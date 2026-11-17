# Updating from the Admin page

Administration > Update runs `git fetch`/`git pull` as the web user (`www-data`) inside the app folder. When the folder was cloned by another user (the person who administers the server) the two must share it. The **Checks** panel on that page tests each requirement and prints the fix; it never contacts the remote.

| Check | What it looks at | Typical fix |
| --- | --- | --- |
| Git is installed / PHP can run git | `git --version` through `exec()` | install git; remove `exec` from `disable_functions` |
| This folder is a git checkout | `.git` exists and git accepts the owner | `git config --system --add safe.directory <app>` (git's "dubious ownership") |
| The web user can write to .git | `.git`, `objects`, `refs`, `index`, `HEAD`, `FETCH_HEAD` | shared group (below) |
| The web user can write to the app files | app folder, `includes`, `admin`, `vendor/composer`, the DB-version files | `chmod -R g+rwX <app>` with the shared group |
| The update source is set | `remote.fork.url` (config only); for SSH, a readable key for the web user | add the remote; create a read-only deploy key |
| Updates have been downloaded before | `refs/remotes/fork/<branch>` exists, `FETCH_HEAD` time | fix the items above, reload |
| Composer files are clean | `git status` of `vendor/composer` | nothing: Update App resets them itself |
| No hand-edited files | other modified tracked files | commit/stash, or FORCE Update App |
| Database matches the code | `LATEST_DATABASE_VERSION` vs the stored one | press Update Database; never run code older than the database |
| PHP libraries are present | `vendor/autoload.php`, Composer (informational) | `composer install --no-dev` |

## Shared group arrangement

```
chgrp -R www-data <app>            # or a dedicated group that holds both users
chmod -R g+rwX <app>
find <app> -type d -exec chmod g+s {} +
git -C <app> config core.sharedRepository group
git config --system --add safe.directory <app>
```

Update App refuses to start (with the check's explanation) when `exec`, git, the repository, `.git` writability or the app files fail, instead of running half a pull.

## Client IP behind a proxy (config.php)

`CONST_GET_IP_METHOD` selects where the client address comes from. A proxy header is believed only when the immediate peer (`REMOTE_ADDR`) is a proxy you trust, otherwise any client could forge its address and dodge the login lockouts and rate limits.

- `HTTP_X_FORWARDED_FOR`: honoured only with `CONST_TRUSTED_PROXIES` (array or comma list of addresses/CIDRs); read from the right, skipping trusted hops.
- `HTTP_CF_CONNECTING_IP`: honoured only when the peer is in `CONST_TRUSTED_PROXIES` or in the Cloudflare ranges. The Cloudflare edge ranges (Cloudflare's published v4 and v6 lists, `includes/cloudflare_ranges.php`, copied 2026-11-16) are built in, so a Cloudflare-direct install needs no setting; define `CONST_CLOUDFLARE_RANGES` (array or comma list) in config.php only to replace that list. There is NO private/loopback fallback: a proxy on the same host or LAN (Zoraxy, nginx, a load balancer) that sits between Cloudflare and PHP must be listed in `CONST_TRUSTED_PROXIES` (for example `'127.0.0.1'`), and that proxy MUST overwrite `CF-Connecting-IP` (set it from the connection it received, or pass Cloudflare's value only when the connection came from Cloudflare's ranges) and must never forward a client-supplied value. A listed proxy that passes the header through untouched, or a PHP port reachable directly by anyone, lets any visitor choose their own address. List entries must be a plain address or `address/prefix` (digits, in range); malformed entries are ignored. When the mode is on and the connection you are using is in neither list, Administration > Security shows a warning.
