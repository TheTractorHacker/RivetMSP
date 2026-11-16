# Host step required before deploying the 2026-11 deep-scan release

The privileged deploy tooling (backup, restore, restore drill, update, Update App) now refuses to run in a layout where the web user could
turn a file write into root. On a host laid out like `itflow.foleyit.com` today (checkout owned by an admin account such as `sysadmin`
with group `www-data` and group-write everywhere, including `.git`), **Update App and `deploy/update.sh` will stop with a loud refusal
until this migration is done.** Do it before (or together with) deploying this release. It needs root; Core (RivetCore) owns host steps.

Why: `www-data` can write `.git` (hooks, `core.sshCommand`, filters) -> git runs as the checkout owner (`sysadmin`, who has sudo) ->
root. The tooling therefore requires `.git`, everything under it and the checkout directory to be owned by the checkout owner (or root)
and not writable by group or others, and never takes the web user from anything the web user can change.

## 1. Install the root-owned tooling and record the web user

    sudo deploy/install-trusted-tooling.sh --web-user=www-data

Writes `/etc/rivetmsp/web-user.conf` (`WEB_USER=www-data`, root:root 0644) and copies `deploy/` to `/usr/local/lib/rivetmsp-deploy`.
The web user must be an unprivileged account: it is refused if it is root, in group sudo/admin/wheel/docker/lxd, or has sudo rights.
Re-run after every update of `deploy/` (the Update App helper installs the same copy).

## 2. Make `.git` and the checkout non-writable for the web user (replace APP and OWNER)

    APP=/var/www/itflow.foleyit.com
    OWNER=sysadmin
    sudo chown -R "$OWNER":www-data "$APP/.git"
    sudo chmod -R g-w,o-w "$APP/.git"
    sudo chmod g-w,o-w "$APP"                  # the directory itself (keep the setgid bit: drwxr-sr-x)
    # code stays readable by the web user, writable only by the owner
    sudo find "$APP" -path "$APP/uploads" -prune -o -path "$APP/backups" -prune -o -path "$APP/config" -prune -o \
         -type d -exec chmod g-w,o-w {} + 
    sudo find "$APP" -path "$APP/uploads" -prune -o -path "$APP/backups" -prune -o -path "$APP/config" -prune -o \
         -type f -exec chmod g-w,o-w {} +
    # directories the application itself writes stay group-writable (and `config.php` stays web-owned if the app edits it)
    sudo chmod -R g+rwX "$APP/uploads" "$APP/backups"
    sudo -u "$OWNER" git -C "$APP" config core.sharedRepository false    # new objects are created 0644/0755

The checkout owner works with `umask 022` from now on (`umask 002` would recreate group-writable files and the tooling would refuse again).

## 3. Update App helper (so the Update page runs as root through the audited helper, not as the web user)

    sudo deploy/install-web-update.sh --app-dir="$APP" --web-user=www-data (--passphrase-file=/root/backup.pass | --no-backup-confirmed)

## 4. Verify (read-only)

    stat -c '%U:%G %a %n' "$APP" "$APP/.git" "$APP/.git/config" "$APP/.git/hooks"     # owner, no group/other write
    cat /etc/rivetmsp/web-user.conf
    sudo /usr/local/lib/rivetmsp-deploy/update.sh --app-dir="$APP" --help             # should not print a refusal about the tree

A refusal always says which path is wrong and how to fix it. `RIVETMSP_ALLOW_UNTRUSTED_GIT=1` and `RIVETMSP_ALLOW_UNTRUSTED_TOOLING=1`
override the gates for an operator who reviewed the tree; the shipped units never set them.

## Other host changes in this release (Core owns these)
- systemd units now run `/usr/local/lib/rivetmsp-deploy/backup.sh` and `restore_drill.sh` (re-render with `deploy/install.sh` or copy the templates) and carry `TimeoutStartSec` (6h backup, 2h drill).
- Live nginx: add the dotfile / `config/` / `deploy|docker|scripts|backups` denies and a barcode allow-list (see `deploy/templates/itflow-locations.conf`); not applied by this repo.
- Cron lock files and the TOTP replay store now live under `uploads/cron_locks` and `uploads/totp_replay` (the web user must be able to write `uploads/`).
- Redis channel names are per-install: deploy every PHP process of the install together.
