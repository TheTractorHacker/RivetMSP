# Key management (RivetCore\Crypto, ADR-011)

RivetMSP seals stored secrets (mail and API passwords, webhook secrets, OAuth tokens, RMM signing and Mesh keys, two-factor seeds, the vault key) with one
envelope from RivetCore: `v3:<kid>:base64(nonce || tag || ciphertext)`, AES-256-GCM, the record's context bound in as authenticated data. Older values
(`ENC2:`, `ENC:`, cleartext) stay readable forever and move to v3 lazily and with a tool. Nothing changes on an existing install until an administrator
creates the key file.

## The pieces

| Piece | Where | Notes |
|---|---|---|
| Key file | `/etc/rivetmsp/keys.json` (`$config_keyfile` in `config.php` overrides; `''` disables) | Outside the web root. File `root:www-data` mode `0640`; its **directory `0755`** (see below). Holds every key by id (`kid`). |
| Legacy key | `$config_settings_enc_key` in `config.php` | Stays. It becomes kid `k1`, opens `ENC2:`/`ENC:` values, and still derives other keys (the audit chain, ...). The backup fingerprint is unchanged. |
| Stage flags | `$config_crypto_v3_settings`, `$config_crypto_v3_totp` (optional) | Default: on when a key file exists. `false` switches a stage off again: new writes use the older form, v3 values stay readable. |
| Vault flag | Setting `config_vault_v3_enabled` | Default **off**. Never changed by an update. |
| Scripts | `scripts/keys_cli.php`, `scripts/rewrap_cli.php`, `scripts/vault_v3_cli.php` | Nothing prints key material. |
| Panel | Administration > Security > Encryption keys | Kids, fingerprints, age, values per key, rewrap status, and any permission problem; rewrap, add key, make active, retire (admin only, CSRF, audited). The web server cannot write the key file, so add/activate/retire there need the CLI unless you give it write access to the directory. |

### Permissions: the directory matters as much as the file

The web user (`www-data`) must be able to **traverse** `/etc/rivetmsp` and **read** `keys.json`. A key file the web user cannot reach is not an error PHP
can recover from: the application then behaves as if there were no key file (it falls back to the `config.php` key, writes `ENC2:` values and reads `v3:`
values as empty).

* `sudo php scripts/keys_cli.php generate` run **as root** creates the directory `0755` if it is missing, and the file `root:www-data` `0640`. It prints
  what it set, and a WARNING for anything the web group still cannot reach.
* The installer and the setup wizard do the same when they can (the setup wizard usually cannot create a directory under `/etc`; it says so and prints the command).
* `/etc/rivetmsp` is also where `redis.env` may live. Keep the directory `0755` (or `root:www-data` `0750`); a `root:root` `0750` directory hides both files.
* `php scripts/keys_cli.php status` and the Encryption keys panel report an unreachable file as **KEY FILE NOT REACHABLE** with the fix command, instead of
  showing a plain "config.php key only" install. The same check runs as root (from the mode bits) and as the web user (from the real access).

**Docker.** The container filesystem is not persistent and the app directory is the web root, so do not run `keys_cli.php generate` in a container unless `/etc/rivetmsp` is a volume
(for example a named volume mounted there, writable by the container's `www-data`): a key file that disappears with the container makes every `v3:` secret unreadable. Without a key file the container
keeps working exactly as before on the `$config_settings_enc_key` in the bind-mounted `config.php`.

Contexts are frozen (changing one makes data unreadable): settings family `settings|generic`, vault key `settings|settings.vault_canonical_key`,
TOTP seed `totp|user:<id>`, instance data-key wrap `vault`, credential fields `credential:<id>:<username|password|otp>`. The settings family shares one
context because its call sites do not pass the column; binding each column is a follow-up that needs them to.

Which columns are covered: `src/Crypto/ColumnRegistry.php`, derived from `secStragglerColumns()` (cleartext on old installs, wrapped by a rewrap) plus the
columns that were always sealed, including the RMM module's (`endpoint_agent_settings` signing key and Mesh login key, `rmm_job_extra.secret_params_enc`,
`rmm_custom_field_values.value_enc`). A test fails if `secStragglerColumns()` or `secDeferredColumns()` names a column the registry lacks.

## Upgrade an existing install (the manual step)

1. Deploy the release and run the database update (`php scripts/update_cli.php --update_db`, DB 2.6.87). This widens the encrypted columns and adds the vault columns; it does not change any value. **Dump the database first.**
2. Create the key file (as root): `sudo php scripts/keys_cli.php generate`. The legacy key becomes `k1`; nothing is lost. From now on new writes are v3 and settings are moved lazily as they are read.
3. **Back the key file up offline now**, apart from the database dump and the backup passphrase. Without it, v3 secrets in a restored dump do not open.
4. Move everything at once (optional, it is also lazy): `php scripts/rewrap_cli.php --dry-run`, then `php scripts/rewrap_cli.php` (repeats until a pass changes nothing; resumable; one audit row per column). `php scripts/keys_cli.php status --inventory` shows the result.

Rolling back after step 2: set `$config_crypto_v3_settings = false;` in `config.php` (new writes are `ENC2:` again). v3 values already written are only readable by this release or later, so a downgrade of the code needs them moved back first or a restore from the pre-upgrade dump.

## Rotation

`keys_cli.php add-key` (new active key) -> `rewrap_cli.php --dry-run` -> `rewrap_cli.php` until 0 rewrapped -> take a fresh offline copy -> keep the old key for one backup cycle -> `keys_cli.php retire <kid>` (refused while any value still uses it). The panel warns when the active key is 12 months old. The key admin keeps `keys.json.bak-N` copies for undo; they hold key material, delete them after confirming.

## Credential vault v3 (flag off by default)

Order: key file -> `rewrap_cli.php` -> `vault_v3_cli.php prepare` (data key, wrapped by the key file; needs the canonical vault key) -> `rewrap_cli.php --vault --dry-run` -> `rewrap_cli.php --vault` -> `vault_v3_cli.php enable`.
With the flag on: credential fields are v3; each user's wrap becomes `vw3:` (Argon2id) at their next password login (only when their legacy key equals the canonical key); a password change rewraps the data key; a session holds both the data key and the legacy key; writes are converted to v3 right after they are saved; the mobile token wraps the data key (`api_tokens.token_enc_dek`); share links keep their own per-link key in the URL fragment; restoring a credential from an uploaded backup converts it (and opens a v3 value of the backup with the administrator's session data key). API keys keep their legacy wrap and take the data key from the instance wrap. `users.user_outlook_*` tokens and the UniFi sync still use the legacy key. Retiring the legacy key is a later step. Tokens issued before the flag was switched on have no data key and must sign in again to read v3 fields. RivetMSP has no credential version table.

Client-portal contacts sign in with a password only in RivetMSP and hold no encrypted secret of their own, so there is nothing of theirs to rewrap.

## Backup and recovery

The in-app zip manifest keeps `settings_enc_key_fingerprint` (formula `rivetit-settings-key-fingerprint|v1|`, unchanged) and adds `keyring` (kid => fingerprint) and `keyring_active`; `deploy/backup.sh` writes the same two members into its manifest. The key file is never in an archive. Escrow `keys.json` separately. Restore: `deploy/restore.sh --key-file=<offline copy>` installs it (`root:www-data` 0640, directory 0755) after the import; `--key-file-dest` changes the target. The setup wizard restore cannot write `/etc`; it compares the manifest's key ring with the key file here and tells you which keys are missing and where the file goes. The nightly restore drill checks that the key file here holds every key the backup names and opens a v3 sample secret.
