# Audit trail hash chain

Code: `src/Audit/AuditChain.php` (chain), `src/Audit/ChainedAudit.php` (seals after each event), `src/Platform/Nightly.php` (seal and verify),
`admin/audit_trail.php` + `admin/post/audit_trail.php` (Administration > Audit trail). Findings behind this design: MSP-DS-173 and its re-reviews
(MRR-18..21, M3-10..M3-20).

## What is trusted

| Thing | Who can write it | Trusted? |
|---|---|---|
| `audit_events` rows (`prev_hash`, `row_hash`) | anyone with database write access | evidence only, checked by the walk |
| `platform_settings` `audit_chain_*` (mode, key id, hmac_from, head, last_verify), `settings.config_audit_retention_days` | anyone with database write access | **never** trusted on a keyed install; they are hints that must agree with the signed anchor |
| `config/audit_chain_head.json` (the anchor file, 0600) | the web server user / root | trusted when its MAC verifies under the install's key |
| the settings key (`config_settings_enc_key`) | whoever can read `config.php` | the root of trust |

An attacker with database access only (no key, no file access) must not be able to edit, delete, reorder, truncate or wipe audit rows without
`verify()` reporting it. An attacker with database AND key AND file access can rebuild everything; that limit is accepted.

## Modes

* **hmac** (an install with a settings key): every row hash is `HMAC-SHA256(key_derived, prev_hash + fields)`.
* **plain** (an install without a key): plain SHA-256. It detects accidents and casual edits, not an attacker who can rehash; the install
  is told so on the page.
* A chain can have a **plain prefix followed by a keyed segment**: an install that had no key when the chain started and got one later.
  Rows with `audit_id < from` are plain, rows with `audit_id >= from` are keyed. `from` (`hmac_from`) is **bound into the signed anchor**;
  the copy in `platform_settings` is only a mirror and must equal it, otherwise the chain is broken. A keyed row after a plain one is fine,
  a plain row after a keyed one is never fine.

## The anchor

The head of the last verified chain is stored twice: in `platform_settings.audit_chain_head` and in the anchor file. Both are
`{id, hash, at, kid, from, mode, mac}` where `mac` is an HMAC (keyed install) over `id|hash|at|kid|from|mode`. `kid` is a fingerprint of the
key, `from` is the start of the keyed segment, `mode` is `hmac` or `plain`, `at` is when it was recorded.

* A **genesis anchor** (`id 0`, hash `0000...`) is written when the first row of an install with a key is sealed, so a fresh install has an
  anchor from its first event.
* `record()` after every successful `verify()` moves both anchors to the verified head (file first).
* A keyed install with **no anchor file** is `broken` (cause `anchor_missing`) whenever sealed rows exist or a database head exists,
  whatever the database says. Only an install with no sealed rows at all (brand new) is fine.

## verify(): the state machine (keyed install)

1. Key mismatch against `audit_chain_key_id` -> `key_changed`, unless the current key still verifies a signed head (then the id was edited:
   `broken`). No key but a chain recorded as hmac -> `key_unavailable`.
2. Read the anchor file. Absent and rows or a DB head exist -> `broken` / `anchor_missing`. Unreadable, junk, wrong signature, `mode` not
   `hmac` -> `broken`. The DB mode must not be `plain` (downgrade) and the DB `hmac_from` must equal the anchor's `from`.
3. Walk every sealed row: link (`prev_hash`) and hash, plain below `from`, keyed from `from`.
4. Compare the **anchor file** with the rows first (row gone, different hash, rows up to it gone), then check the database head (its MAC, then
   the same comparison). The file is compared first so that stripping the DB head's MAC cannot turn "rows were deleted" into a harmless
   "predates signing".
5. An anchor older than the oldest surviving row is accepted only when the **retention window explains it**: retention is on and the anchor
   was recorded longer ago than the window. "The surviving row follows the anchor's hash" is not enough (`DELETE audit_id <= N` makes that true).

A keyless install keeps the old behaviour: anchors are plain SHA-256 (a database-only writer still cannot move the file), `hmac_from` is
read from the database because there is nothing to sign it with.

## Retention

Retention may delete the oldest rows. One function, `AuditChain::effectiveRetentionDays()`, computes the window for the cron job that deletes
and for the verifier that has to explain the gap: the compliance profile floor (`RetentionPolicy`), then the `SecurityBaseline` floor (90
days) for any value of 1 or more; `0` keeps forever, and then nothing explains a gap. It reads the settings row itself, so web requests and
cron agree (cron used to see 0 and a web request an unfloored value). Residual risk: someone who can write `settings` can still set the
window to the floor; a gap older than the floor is then explained.

## Re-anchor (Administration > Audit trail), admin password required, logged with the previous head

`reanchor()` accepts the rows as they are now as a new baseline after the rows themselves verify. It never launders an edit.

1. If a **valid signed anchor file** exists it decides the layout (`mode`, `from`); the database mode and `hmac_from` are ignored. Rows
   must verify against that layout. (Edit + plain rehash + `mode=plain` is refused.) The new `from` is `min(anchor.from, head_id + 1)` so
   a restore of an older database keeps working.
2. With no valid anchor file the segments are **detected by walking**: plain rows first, keyed rows after; the boundary becomes `from`
   (all plain on a keyed install: `from = head_id + 1`, "upgrade"; all keyed: `from = 0`). A plain row after a keyed one is refused.
   Without an anchor there is no evidence of what the chain was: this path trusts the administrator, which is why it is a deliberate,
   password-protected click.
3. The anchor file is written **first**. If it cannot be written the re-anchor stops with the file path and a permission hint and changes
   nothing in the database (no "press Re-anchor" loop).
4. Then the database settings (mode, key id, `hmac_from`, head) are set, the chain is verified and recorded, and the open alerts cleared.

An empty chain re-anchors to a genesis anchor on a keyed install.

## One-time legacy step (installs upgraded from 26.10.12)

Those installs have a keyed chain, a database head without a MAC (or none) and **no anchor file**. The first nightly run reports
`broken` with cause `anchor_missing`; the alert is titled "Audit trail anchor file missing" and says what to do instead of "tampering
suspected". The admin clicks **Administration > Audit trail > "Set up the anchor (one time)"** and enters their password. The step is never
automatic and never derived from database state: it requires that there is no anchor file, requires the **whole** chain to verify as keyed
(no plain rows are accepted here), then writes the signed anchor file and the MAC'd database head. After that a missing anchor is a failure
whatever the database says.

Honest limit: rows that an attacker removed from the end before the legacy step cannot be known. The step trusts the chain as it is that day.

## Restore procedure

* **Database backup restored** (rows older than the anchor): verify reports `broken` (anchor ahead of the rows). If you are sure it is the
  restore, use Re-anchor. The old head is written to the audit log.
* **Anchor file lost** (new host, config not restored): `anchor_missing`; use Re-anchor (or the legacy button, which is the strict variant).
  Back up `config/audit_chain_head.json` together with `config.php`.
* **Settings key changed or lost**: `key_changed` / `key_unavailable`, not tampering. Restore the old key; nothing else can verify the chain.
* **Anchor file not writable**: re-anchor reports the path; fix the owner (the web server user) and mode of `config/` and try again.

## Tests

`tests/msp_ds2_173.php`, `tests/msp_mrr_18_21.php`, `tests/msp_m3_10.php` .. `tests/msp_m3_20.php` with `tests/lib/audit_chain_m3.php`
(all on a scratch database, real `AuditChain`; each has its `// Run:` line).
