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
- Live nginx (`/etc/nginx/snippets/itflow-locations.conf`, shared by the ITFlow vhosts; not applied by this repo, a human applies it, then `nginx -t && systemctl reload nginx`).
  The authoritative text is `deploy/templates/itflow-locations.conf` (tested against a real nginx by `tests/msp_ds2_182.php` and `tests/msp_mrr_22|23|24.php`); `plugins/barcode/barcode.php` stays hardened and
  included in-process by `includes/qr_data_uri.php`, a direct HTTP request to it returns 404. The corrected rule set, replacing the earlier snippet of this round, is these seven edits:
  1. Dotfiles and tooling trees (near the top): `location ~ /\.(?!well-known/) { return 404; }` and `location ~ ^/(config|deploy|docker|docs|scripts|backups)(/|$) { return 404; }`.
  2. Any-depth extension deny (after the `tests|vendor|src|...` deny): `location ~* \.(md|lock|sql|yml|yaml|sh|bak|dist)$ { deny all; }`.
  3. NEW (MRR-22), directly after edit 2: the app links the OpenAPI spec for download and an exact match beats every regex location, so add
     `location = /api/v1/openapi.yaml { }`
  4. `location ^~ /uploads/`: the nested script deny now ends in `(/|$)` (MRR-23): `location ~ ^/uploads/.*\.(php|phtml|phar|phps|cgi|pl|sh)(/|$) { deny all; }` (keep its two add_header lines).
  5. Replace the whole `location ^~ /plugins/ { ... }` block with:
     ```
     location ^~ /plugins/ {
         location ~* \.(md|lock|sql|yml|yaml|sh|bak|dist)$ {   # MRR-24: ^~ skips the any-depth deny above
             deny all;
         }
         location ~ \.(php|phtml|phar|phps)(/|$) {            # MRR-23: also refuses a.php/x (PATH_INFO)
             deny all;
         }
         add_header Cache-Control "public, max-age=31536000, immutable";
     }
     ```
  6. Add the same nested `location ~* \.(md|lock|sql|yml|yaml|sh|bak|dist)$ { deny all; }` as the first lines inside `location ^~ /fonts/`, `location ^~ /css/` and `location ^~ /js/` (MRR-24; nothing there uses those extensions today, defence in depth).
  7. (M3-21) The live snippet's `location ^~ /.well-known/ { try_files $uri =404; }` is a ^~ prefix, so it skips every regex deny above and would serve `/.well-known/x.sh`, `/.well-known/.env` and `/.well-known/x.php` as plain files. Replace it with:
     ```
     location ^~ /.well-known/ {
         location ~* \.(md|lock|sql|yml|yaml|sh|bak|dist|php|phtml|phar|phps)$ {
             deny all;
         }
         location ~ /\.(?!well-known/) {
             deny all;
         }
         try_files $uri =404;
     }
     ```
     `/.well-known/acme-challenge/<token>` (extensionless) is still served. Tested by `tests/msp_m3_21.php`.
  Apache installs: the root `.htaccess` rule is now `RedirectMatch 403 ^/plugins/.*\.(php|phtml|phar|phps)(/|$)` (ships with the code, nothing to apply by hand).
- Cron lock files and the TOTP replay store now live under `uploads/cron_locks` and `uploads/totp_replay` (the web user must be able to write `uploads/`).
- Redis channel names are per-install: every PHP process of the install must run the new code at the same time, or live ticket updates are lost
  until the deploy finishes. On the live host that is exactly: `php8.5.12-fpm.service` (web requests, the SSE streams and the MCP endpoint all run
  inside FPM; `deploy/update.sh` reloads it) and the cron-started PHP CLI processes of `/etc/cron.d/itflow` (they start fresh each run, so nothing to
  restart, but do not deploy while a cron run is in flight: check `pgrep -af cron/cron.php` first). `redis-server.service` itself is NOT restarted.
  Other units found on this host (`rivetit-agent`, `rivetit-redis`, `rivetit-demo`, `rivetmsp-demo`, the `mw-itflow-*` cron files) belong to other installs and are not touched.
- The sudoers rule written by `install-web-update.sh` is now `Defaults!<helper> env_reset, !setenv` plus a single `NOPASSWD: <helper>` line (no argument wildcard:
  this host's sudo-rs rejects the old `web-update.sh --branch=*` form, which would have made the installer refuse to write the rule; the helper validates `--branch=` itself).
