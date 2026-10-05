# Retiring `Syncro-Beta`: one deploy branch (`master`)

Written 2026-10-04. Nothing in this document has been run; the steps are for the maintainer.

## Where things stand

| Branch | Tip | Used by |
|---|---|---|
| `master` | `b62ed4f27` | GitHub default branch; `deploy/install.sh` clones it for new installs |
| `Syncro-Beta` | `b62ed4f27` (**identical to `master`**) | the live checkout `/var/www/itflow.foleyit.com` is on it |
| `beta` | `73261d5ca` (= `b62ed4f27` + 2 RivetCore commits) | development; nothing deploys from it |
| `mobile-api-parity` | `f4c2d08ce` (2 ahead, 8 behind) | separate Android API work; unrelated to this cutover |

`Syncro-Beta` is therefore just a second name for `master`. Retiring it loses no history. The one thing that needs care is that the **live
checkout is on `Syncro-Beta` and has no upstream configured** (`git pull` there currently fails with "no upstream configured"), so it must be re-pointed
before the branch is deleted.

## Rules

- **Do not create git tags on commits the live site runs.** Admin > Update shows `git describe --tags --abbrev=0 HEAD` as the version, so a tag on (or nearer than the real release tags to) the live commit renames the product's version in the UI. Use a **branch** for rollback pointers (`backup/pre-rivetcore` already exists at `b62ed4f27`).
- Never run `scripts/update_cli.php --force_update` (or the matching Admin button) on this repository: it does `git reset --hard origin/master`, which discards anything not on `master`.
- Nothing below happens on the live box until the rollback point exists and the test copy has passed.

## Steps

### 1. Rollback point (live box) - done 2026-10-04: branch `backup/pre-rivetcore`, database dump `rivetmsp-itflow-pre-rivetcore-20261004T204711.sql.gz`
```
cd /var/www/itflow.foleyit.com
git status --short                      # expect nothing but untracked backups/
git push fork b62ed4f27:refs/heads/backup/pre-rivetcore      # a branch, not a tag (see Rules); already done
mysqldump --single-transaction <live database> | gzip > ~/db_backups/rivetmsp-pre-rivetcore-$(date +%Y%m%dT%H%M%S).sql.gz
```

### 2. Fix the live checkout's branch (no file changes)
`master` and `Syncro-Beta` are the same commit, so switching changes no files, and it gives `git pull` an upstream.
```
git fetch fork
git checkout -B master fork/master
git branch --set-upstream-to=fork/master master
git status -sb                          # "## master...fork/master", clean
git pull                                # "Already up to date."
```
Check how updates actually run on that box (`deploy/update.sh` as root, or the Admin Update button) and that it still works after this.

### 3. Test copy
Deploy `beta` somewhere that is not the live site, with a schema-only copy of the database (no client data). Run the update (migrations 2.6.55 and 2.6.56),
sign in, create and reply to a ticket, and confirm nothing changed visibly (all RivetCore switches are off).

### 4. Merge `beta` into `master`
`beta` already contains `master`, so this is a fast-forward:
```
git push fork beta:master               # fast-forward only; never --force
```
(or open a pull request `beta` -> `master` and merge it.)

### 5. Update the live site
Run the normal update on the live box (`deploy/update.sh`). It pulls `master`, installs dependencies, runs migrations 2.6.55 and 2.6.56, and reloads PHP-FPM.
Smoke-test sign-in, tickets, invoices and the RMM pages. Switch on RivetCore modules one at a time afterwards (`config_core_<module>_enabled`).

### 6. Retire `Syncro-Beta`
Only after step 5 has run clean for a few days:
```
git push fork b62ed4f27:refs/heads/backup/syncro-beta-final   # a branch, not a tag; keeps the name findable
git push fork --delete Syncro-Beta
git branch -d Syncro-Beta                                                 # on each machine that has it locally
```
`Syncro-Beta` appears in some code comments ("(Syncro-Beta)") as a feature label for the RMM work. Those are harmless and need no change.

## Rolling back
Before step 4: nothing to undo. After step 5: `git checkout backup/pre-rivetcore` on the live box (or `git reset --hard backup/pre-rivetcore` on `master` if it must be undone for everyone), restore the database dump if migrations must be reversed, and reload PHP-FPM.
