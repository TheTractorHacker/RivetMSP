# deploy/ — RivetMSP deployment tooling

Scripts to stand up, harden, back up, restore, and update a **standalone** RivetMSP instance
on a fresh (or already-running) Ubuntu/Debian box. This is **not** a multi-tenant installer — every
company gets its own app directory, its own database, its own database user, and its own nginx vhost.
Running these scripts a second time with a different `--domain` adds a second, fully independent
company's instance alongside the first, on the same box.

| Script | What it's for |
|---|---|
| `install.sh` | Stand up a brand-new instance end to end: packages, code, database, TLS/vhost, hardening, firewall, cron, and the app's own first-run setup (or restore an existing backup onto the new box — see `--restore-from`). |
| `harden.sh` | A standalone, idempotent, re-runnable hardening pass — the fuller superset of what `install.sh` applies inline during a fresh install. |
| `backup.sh` (+ systemd timer) | Encrypted, scheduled backups of the database and `uploads/`. |
| `restore_drill.sh` | Root-side restore drill for the encrypted archives: checks the `.sha256`, decrypts to a private directory, restores into a scratch `drill_*` database with the scoped drill account, verifies and drops it. Setup, off-site copies (`etc/offsite.conf.example`) and the full recovery order: [`docs/RECOVERY_RUNBOOK.md`](../docs/RECOVERY_RUNBOOK.md). |
| `restore.sh` | Decrypt and restore a `backup.sh` archive onto an already-installed instance — the disaster-recovery counterpart `backup.sh` never had. |
| `update.sh` | Pull application updates and run any pending database migrations. |
| `lib/common.sh` | Shared helpers (logging, `gen_secret`, OS detection, service checks, `read_app_config`) — sourced by every script above, never run directly. |
| `templates/` | The actual config content applied by `install.sh`/`harden.sh` — nginx vhost + shared locations snippet, PHP-FPM hardening ini, MariaDB hardening cnf, fail2ban jail. Read these if you want to see exactly what gets changed on your box before running anything. |

All scripts must be run as **root** (`sudo`) — they touch `/etc`, install packages, and manage
services. All of them log what they're about to do before doing anything invasive (a service restart,
`ufw enable`, a destructive file write), and none of them will disable SSH access as a side effect.

There's also a Docker Compose path (`../docker-compose.yml`) for local evaluation or a lightweight
self-hosted deployment — see the top-level `README.md`'s Getting Started section. It's a companion to
this tooling, not a replacement: it skips every hardening step below entirely and expects a real
reverse proxy in front of it for TLS.

---

## install.sh

```
sudo deploy/install.sh --domain=<fqdn> [options]
sudo deploy/install.sh --help
```

### What it does, in order

1. **Packages** — installs nginx, MariaDB, PHP 8.4 (added via the `ondrej/php` PPA if Ubuntu's default
   repos don't carry it), certbot, ufw, fail2ban, git, composer, and friends. Anything already installed
   (e.g. because another instance is already running on this box) is left alone.
2. **Application code** — if run from inside an existing checkout of this repo, that checkout is copied
   into the new instance's app directory (so a second company doesn't need its own GitHub network
   access); otherwise it's cloned fresh from GitHub. `composer install --no-dev` runs if `composer.json`
   is present.
3. **Uploads/backups directories + permissions** — creates every `uploads/*` subdirectory with its
   directory-listing-denial placeholder, then sets the whole app directory to `www-data:www-data`,
   `750` directories / `640` files, widening only `uploads/` and `backups/` back to writable.
4. **Database** — generates a random 32-character password (`gen_secret`), creates a MariaDB database
   and a same-named user bound to `localhost` only, with exactly the grants the app needs
   (`SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES, CREATE
   TEMPORARY TABLES` on that one database — nothing global, no `SUPER`/`FILE`/`GRANT OPTION`). Those
   same grants are what let `restore.sh` later `DROP DATABASE`/`CREATE DATABASE` on a restore —
   verified live against a real MariaDB instance with exactly this grant set, not assumed.
5. **Certificate + nginx vhost** — bootstraps a self-signed certificate (needed before `nginx -t` will
   accept a config referencing it), deploys the shared `templates/itflow-locations.conf` snippet once
   per box, renders `templates/nginx-vhost.conf.template` for your domain, and either runs certbot
   (direct-TLS mode) or leaves the self-signed cert in place long-term (`--proxy-mode` or `--skip-tls`).
6. **PHP-FPM + MariaDB hardening** — applies `templates/php-hardening.ini` and
   `templates/mariadb-hardening.cnf` (see `harden.sh` below for what's in them), validating each with a
   config test before restarting the service, and rolling back automatically if the test fails.
7. **Firewall + fail2ban** — allows SSH (auto-detected port) and HTTP/HTTPS through `ufw` *before*
   enabling it, then installs the `sshd` and `itflow-auth` fail2ban jails.
8. **Cron** — installs a system cron entry that invokes `cron/cron.php` every 5 minutes. This is inert
   until an admin turns on **Enable Cron** in the app's own Settings (see "Manual follow-ups" below) —
   the app controls its own effective frequency from there.
9. **Application setup** — runs `scripts/setup_cli.php` as `www-data` to write `config.php`, import
   `db.sql`, and create the first admin user (or, with `--restore-from`, a throwaway placeholder
   instance immediately overwritten by `restore.sh` — see below). Skipped automatically if `config.php`
   already exists (re-running `install.sh` against an already-set-up instance is safe).

   **Success is verified by checking `config.php` for its trailing `$config_enable_setup = 0;` line,
   not `setup_cli.php`'s exit code.** Confirmed live: every `die(...)` in that script is PHP's
   `die()`/`exit()` with a *string* argument, which always exits `0` — a bad email, a DB error, a
   malformed `db.sql` statement all look, at the shell level, identical to success. That trailing line
   is the same flag `includes/redirect_if_setup_enabled.php` itself gates the whole app on, so it's the
   one signal that's actually reliable.

### Key flags

Full reference: `sudo deploy/install.sh --help`. The ones worth knowing up front:

- `--domain=<fqdn>` — **required.** Public hostname for this instance.
- `--proxy-mode` — this box sits behind an *external* reverse proxy that already terminates public TLS.
  Skips certbot, serves a self-signed backend cert on `:8443`, and keeps nginx's own redirects relative
  so the internal hostname/port never leaks to an end user.
- `--skip-tls` — no public DNS yet / TLS will be configured later by hand. Serves self-signed directly.
- `--non-interactive` — fail instead of prompting for anything missing (see `--help` for the full list of
  flags it then requires).
- `--admin-password=...` — **avoid this flag on an interactive terminal.** `install.sh` forwards it to
  `setup_cli.php` via the `ITFLOW_ADMIN_PASSWORD` environment variable, never on `setup_cli.php`'s own
  argv, so it's not visible to other users on the box via `ps`. It IS visible on *this* script's own
  command line and shell history, though — leave it out and answer `setup_cli.php`'s interactive prompt
  instead whenever you have a terminal in front of you, which touches neither. (`scripts/setup_cli.php`
  itself was given a small, additive patch to read `ITFLOW_DB_PASSWORD`/`ITFLOW_ADMIN_PASSWORD` from the
  environment as a fallback when the matching `--option` isn't passed — direct-argv usage is unaffected.)
- `--restore-from=<path>` / `--restore-passphrase-file=<path>` — stand up this new box from an existing
  `backup.sh` archive instead of a fresh company setup. See `restore.sh` below for the full mechanics;
  `install.sh` just runs a throwaway placeholder setup first (so `config.php` and *some* schema exist),
  then hands off to `restore.sh` to overwrite it with the real backup.

### Worked example 1 — fresh dedicated box

```bash
sudo deploy/install.sh \
  --domain=itflow.example.com \
  --email=admin@example.com \
  --company-name="Example Co" --country="United States" \
  --locale=en_US --timezone=America/New_York --currency=USD
# (admin name/email/password prompted interactively)
```

This installs everything from scratch, gets a real Let's Encrypt certificate for
`itflow.example.com`, and finishes with a working instance at `https://itflow.example.com/`.

### Worked example 2 — adding a second company to a box that already runs one instance

```bash
sudo deploy/install.sh \
  --domain=itflow2.example.com \
  --db-name=itflow2 \
  --email=admin@example.com \
  --company-name="Second Co" --country="United States" \
  --locale=en_US --timezone=America/New_York --currency=USD
```

Nginx, PHP-FPM, and MariaDB are already installed and running from the first instance — `install.sh`
detects that and skips reinstalling them. It provisions a brand-new app directory
(`/var/www/itflow2.example.com` by default), a brand-new database/user, and a second nginx vhost,
side by side with the first. The shared `ufw`/`fail2ban` state and the shared nginx rate-limit zone
(`/etc/nginx/conf.d/itflow-rate-limit.conf`) are reused, not duplicated.

When multiple sites share one IP, nginx sends requests addressed to the IP itself to the
default vhost. Pass `--default-vhost` when installing or re-rendering the intended
instance (usually production), and omit it for beta or other sites. Only one vhost
per nginx port can be the default. This also keeps integrations using a private
server IP from silently creating tickets in another instance.

### Worked example 3 — a new box from an existing backup (disaster recovery)

```bash
sudo deploy/install.sh \
  --domain=itflow.example.com \
  --email=admin@example.com \
  --restore-from=/root/backup-itflow-20260101T023000Z.tar.gz.enc \
  --restore-passphrase-file=/etc/itflow/backup-passphrase
```

Every company/localization/admin-user flag is ignored in this mode. Packages, TLS, hardening,
firewall, and cron are provisioned exactly as in a fresh install; the application-setup step then
writes a throwaway placeholder `config.php` and schema (never actually used) and immediately hands off
to `restore.sh`, which overwrites the database and `uploads/` with the real backup's contents. See
`restore.sh` below for exactly what that does.

---

## harden.sh

```
sudo deploy/harden.sh                    # box-wide hardening only
sudo deploy/harden.sh --dry-run          # preview every action, change nothing
sudo deploy/harden.sh --domain itflow.example.com --app-root /var/www/itflow.example.com \
    --ssl-cert /etc/ssl/certs/itflow.example.com.crt \
    --ssl-cert-key /etc/ssl/private/itflow.example.com.key
                                          # also (re)render that vhost hardened
```

### What it applies

1. PHP-FPM hardening (`templates/php-hardening.ini`) — validated with a config test, service reloaded
   only on success, rolled back automatically otherwise.
2. MariaDB hardening (`templates/mariadb-hardening.cnf`) — same validate-then-restart-then-rollback
   pattern.
3. A **mysql_secure_installation-equivalent cleanup** — removes anonymous MySQL users and the `test`
   database via direct, idempotent SQL (skipped, with a warning, if MariaDB's root account isn't using
   the default `unix_socket` auth this expects).
4. fail2ban (`templates/jail-itflow.local` + its `filter.d` companion).
5. `ufw` — auto-detects every port `sshd` actually listens on (main config *and* any
   `sshd_config.d/*.conf` drop-in) and allows all of them, plus 80/443 (and 8443 in `--proxy-mode`),
   *before* enabling the firewall.
6. **unattended-upgrades**, restricted to `-security` origin packages only, with automatic reboot
   explicitly disabled (a live app rebooting itself unattended is its own outage).
7. nginx — the shared rate-limit zone and shared `itflow-locations.conf` snippet unconditionally, plus
   (only if `--domain`/`--app-root`/`--ssl-cert`/`--ssl-cert-key` are given) a re-rendered vhost for that
   domain.

Every step is check-before-act and skips (not re-does) anything already in place, so this is safe to run
repeatedly — including against a box that already has one or more *other* ITFlow instances hardened by
an earlier run.

### Standalone vs. letting install.sh call it

**In this version, `install.sh` does not invoke `harden.sh`.** It applies its own inline copy of the
PHP-FPM/MariaDB hardening (steps 1–2 above, from the exact same template files), plus its own firewall
and fail2ban setup (steps 4–5) as part of a fresh install. What `install.sh` does **not** do on its own
is the mysql_secure_installation-equivalent cleanup (step 3) or unattended-upgrades (step 6).

Practical recommendation: **run `deploy/harden.sh` once, standalone, after `deploy/install.sh`
completes.** Because `harden.sh`'s file-deploy step compares content before writing anything, it will
find the PHP-FPM/MariaDB/fail2ban config `install.sh` already applied unchanged and skip re-writing it —
you'll only actually pick up the two extra steps `install.sh` doesn't do itself:

```bash
sudo deploy/install.sh --domain=itflow.example.com ...
sudo deploy/harden.sh
```

`harden.sh` is also the right tool, entirely on its own, for a company that already has ITFlow running
from a manual or older setup and just wants to retrofit this hardening onto it — it installs the
hardening tools themselves (fail2ban, ufw, unattended-upgrades) if they're missing, since a retrofit
target may well not have them yet. Always preview first with `--dry-run` on a box you didn't just build
with `install.sh`, so you know exactly what's about to change before it does.

Selective skips are available for every section (`--skip-php`, `--skip-mariadb`,
`--skip-mysql-secure`, `--skip-fail2ban`, `--skip-ufw`, `--skip-unattended-upgrades`, `--skip-nginx`) —
see `--help` for the full list.

---

## backup.sh + systemd timer

Scheduled, encrypted backups of the database and `uploads/`, run automatically via a companion systemd
timer rather than depending on someone remembering to run a script by hand.

### Setting up the passphrase file

Backups are encrypted with a passphrase that lives **only** in a root-only file on the server,
`/etc/itflow/backup-passphrase` — never on argv, never in the systemd unit itself:

```bash
sudo mkdir -p /etc/itflow
sudo bash -c 'openssl rand -base64 48 > /etc/itflow/backup-passphrase'
sudo chmod 600 /etc/itflow/backup-passphrase
sudo chown root:root /etc/itflow/backup-passphrase
```

**Immediately copy that passphrase somewhere other than this server** — a password manager, a printed
copy in a safe, a secrets vault, anything off-box. An encrypted backup whose only decryption passphrase
lives on the same disk as the backup provides no real protection if that disk is what's lost, stolen, or
ransomwared — you'd be encrypting a backup against a threat model where the key is guaranteed to be
right next to it.

### Enabling the scheduled timer

```bash
sudo systemctl enable --now itflow-backup.timer
sudo systemctl list-timers itflow-backup.timer     # confirm the next scheduled run
```

Run `deploy/backup.sh` once by hand first (`sudo deploy/backup.sh --app-dir=<path> --passphrase-file=/etc/itflow/backup-passphrase`)
to confirm it succeeds and to see where it writes output, before trusting the timer to run it
unattended.

### What's inside a backup, and how to restore one

Each run produces one file: `<dest>/backup-<dbname>-<timestamp>.tar.gz.enc` — an
`openssl enc -aes-256-cbc -pbkdf2 -iter 600000` encrypted tar archive containing the `mysqldump` output, a small
`backup-manifest.json` (installation ID + a **fingerprint** of `config_settings_enc_key`; the key itself is in a
separate file, see below), and `uploads/`.

### Archive key derivation and the settings-key file

`backup.sh` uses 600000 PBKDF2 iterations for the passphrase (OpenSSL's own default is only 10000). `restore.sh` tries
600000 first and falls back to the old default, so archives made by earlier versions still restore with no flag.

The archive **no longer contains `config_settings_enc_key`**. That key unlocks every stored SMTP/IMAP password, API
key, webhook secret, TOTP seed and the wrapped credential-vault master key in the dump, so it must not sit in the same file as
the data it unlocks. Each run now writes it to a separate file next to the archive:

```
backup-<db>-<timestamp>.tar.gz.enc          the encrypted archive (database + uploads + manifest with a key fingerprint)
backup-<db>-<timestamp>.settings-key        the settings key, mode 0600 root:root, one line plus # comments
```

Copy the `.settings-key` file **off the server**, and keep it apart from both the archive and the passphrase: the dump, the
settings key and the passphrase should never share one file or one disk copy. Old key files are pruned with their archives
by `--retention-days`. To restore, `restore.sh` uses `<archive>.settings-key` automatically when it sits next to `--backup`,
or you name it with `--settings-key-file=<path>`; the key is checked against the manifest fingerprint and the restore stops
if they differ. An archive written before this change still carries the key in its manifest and restores exactly as before.

**Test a restore before you need it for real.** A backup you've never restored is a backup you don't
actually have:

```bash
sudo deploy/restore.sh --app-dir=/var/www/<scratch-instance> \
    --backup=<dest>/backup-<dbname>-<timestamp>.tar.gz.enc \
    --passphrase-file=/etc/itflow/backup-passphrase \
    --confirm-restore
```

against a **scratch** instance stood up with `deploy/install.sh --domain=<scratch>.example.com` —
never straight into a live instance you care about, for a *test* restore. See `restore.sh` below for
exactly what this does.

The application also has its own independent backup feature reachable from inside
the app (Settings → Backup → "Download Backup" / "Save to Server", `admin/backup.php`) that produces
the same kind of database-dump-plus-uploads-zip — useful before a risky change. **It refuses to build a backup until a
backup passphrase of at least 16 characters is saved** (Maintenance → Backup → Backup encryption passphrase); the zip's
`backup-manifest.json.enc` is encrypted with that passphrase and is the only place the settings key is written. The
`db.sql` and `uploads.zip` inside the zip are not themselves encrypted, so it does not replace `deploy/backup.sh` + the systemd
timer for actual disaster-recovery purposes. The browser restore (`/setup` → "Restore from Backup") asks for that
passphrase and applies the recovered key. The two coexist in the same `backups/` directory without either
deleting the other's files (`backup.sh` only ever touches its own `backup-*.enc` naming).

---

## restore.sh

```
sudo deploy/restore.sh --app-dir=<path> --backup=<path> \
    --passphrase-file=<path> --confirm-restore [options]
```

The counterpart `backup.sh` never had until now: decrypts a `backup-*.tar.gz.enc` archive and
overwrites an **already-installed** instance's database and `uploads/` with what's inside. This is the
"new server, only have my offsite encrypted backup" disaster-recovery path — run `deploy/install.sh`
first (or use its `--restore-from` flag directly, which calls this script for you) to get a fresh,
empty instance, then point this at the backup to restore into it.

### What it does, in order

1. **Pre-restore safety backup** — takes a fresh `backup.sh` snapshot of the target's *current* state
   before touching anything, using the idiom `update.sh` already established (mandatory backup, or an
   explicit `--no-pre-restore-backup-confirmed` opt-out). Skip this only if the target has nothing worth
   keeping (e.g. it was just created by `install.sh` and never used).
2. **Decrypt + extract** the backup archive.
3. **Recover the settings key**: from the archive's manifest if it still carries it (older archives), else from
   `--settings-key-file` / the `<archive>.settings-key` sidecar, checked against the manifest fingerprint. Backups that
   predate the manifest print a warning instead — see below for why this matters.
4. **`DROP DATABASE` + `CREATE DATABASE`, then import the SQL dump** — deliberately NOT a plain import
   into whatever's already there. `mysqldump`'s own per-table `DROP TABLE IF EXISTS` only covers tables
   that exist *in the dump*; a table that exists in the target but not in an older backup (schema drift
   between when the backup was taken and the target's current `db.sql`) would otherwise survive
   untouched. Confirmed live: without this step, a leftover table survives a "restore"; with it, the
   target ends up an exact match. The app's own DB user already holds the `CREATE`/`DROP` grants this
   needs (see `install.sh`'s `provision_database`).
5. **Replace `uploads/`** with the backup's (`rsync -a --delete`), then restore
   `www-data:www-data`/`750`/`640` ownership and permissions.
6. **Apply the recovered `config_settings_enc_key`** to the target's `config.php`, if the manifest had
   one and it differs from what's already there. Without this step, any setting
   `encryptSetting()`/`decryptSetting()` protected under the *source* instance's key (SMTP, IMAP, API keys,
   webhook secrets, TOTP seeds, the wrapped vault master key) would decrypt to nothing under the target's
   own freshly generated key (`setup`/`setup_cli.php` now mint one on every install).

### Why the manifest exists

`config_settings_enc_key` lives **only** in `config.php` — never in the database, and therefore never
in the SQL dump `backup.sh` produces. A naive restore (dump + uploads only) onto a fresh instance with
its own freshly-blank `config.php` would silently corrupt every setting that key protected. The
manifest holds a fingerprint of it, and the key itself is supplied from the separate `.settings-key` file (or, for
older archives, read from the manifest inside the encrypted archive); that is what lets `restore.sh` recover and re-apply it.

### Testing a restore safely

Point `--app-dir` at a disposable second instance (`deploy/install.sh --domain=<scratch>.example.com`)
rather than a production one you care about, restore into that, verify, then delete it. `restore.sh`
has no special "dry run" or "scratch database" mode of its own — the isolation comes from which
`--app-dir` you point it at.

### One app-specific wrinkle

Credential vault data in the database is encrypted with each user's own zero-knowledge key, derived
from their password — not a separate recoverable secret bundled into the SQL dump. As long as the admin
account's password is unchanged after a restore, vault data decrypts normally.

---

## update.sh

```bash
sudo deploy/update.sh
```

Wraps `scripts/update_cli.php`: pulls application code updates, then runs any pending database
migrations from `admin/database_updates.php` up to `includes/database_version.php`'s
`LATEST_DATABASE_VERSION`.

**Recommended cadence:** monthly, or immediately after `deploy/harden.sh`'s unattended-upgrades has kept
the OS current but a known application-level fix has shipped. Always take a fresh `deploy/backup.sh` run
(or confirm the scheduled timer ran recently) before updating a production instance — a schema migration
is exactly the kind of change you want a tested rollback path for.

**Deliberately uses plain `--update` (a `git pull`), never `--force_update`**
(`git fetch --all && git reset --hard origin/master`) — a hard reset in an automated wrapper would
silently discard any local edit made directly on the box, which a `git pull` instead merges or fails
loudly on. Unlike ITFlow-Internal-IT's fork of this tooling, this repo's real default branch genuinely
is `master`, so `--force_update`'s hardcoded `origin/master` isn't itself a bug here — the plain
`--update` choice is purely the "never auto-discard local edits" safety principle, not a workaround for
a branch-name mismatch.

---

## Security model

A handful of manual follow-ups matter enough that every company deploying this should do them,
regardless of anything else:

1. **Rotate the generated database password if it was ever displayed on a screen someone else could
   see.** `install.sh` generates it and writes it straight into `config.php` without printing it to the
   terminal or the install log — but if you ever typed `--password=...` on a command line yourself, or
   looked at it over someone's shoulder, treat it as exposed and rotate it.
2. **Enable "Enable Cron" in the app's own Settings once the instance is fully configured.** The system
   cron entry `install.sh` installs fires every 5 minutes unconditionally, but `cron/cron.php` does
   nothing at all until that setting is turned on — it defaults to off.
3. **Set up the backup passphrase file and store a copy of the passphrase somewhere other than the
   server itself**, per the `backup.sh` section above — this is the single most common way an encrypted
   backup ends up providing zero real protection.
4. **Review and enable MFA for the admin account.** ITFlow genuinely supports this — TOTP (via
   `plugins/totp`) and WebAuthn/passkeys are both real, working authentication methods. None of this is
   turned on by default for the account `scripts/setup_cli.php` creates; do it as one of the first
   things after logging in for the first time.
