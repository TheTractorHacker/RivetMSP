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
