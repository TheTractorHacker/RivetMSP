#!/usr/bin/env bash
# Review R12 (MSP-DS-34): every git that update.sh / web-update.sh (and update_cli.php, which they start) run as the checkout owner must
# have hooks and fsmonitor switched off, not only the one `git checkout`. Behavioural: a repo whose .git is "web-writable" carries a
# post-merge hook, a post-checkout hook and core.fsmonitor; with the safe environment none runs; without it they do (so the test can fail).
# Run: bash tests/msp_ds_review_R12_git_hooks_off.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null
SAFE=(); eval "$(sed -n '/^RIVETMSP_GIT_SAFE_ENV=(/p' "$ROOT/deploy/lib/common.sh")"; SAFE=("${RIVETMSP_GIT_SAFE_ENV[@]}")
[[ "${#SAFE[@]}" -ge 5 ]] && ok "RIVETMSP_GIT_SAFE_ENV is defined in common.sh" || { bad "no RIVETMSP_GIT_SAFE_ENV"; exit 1; }

mk() { # mk NAME : an upstream with 2 commits and a clone carrying planted hooks + fsmonitor
    git init -q "$T/$1-up"; ( cd "$T/$1-up" && echo 1 > f && git add f && git commit -qm one )
    git clone -q "$T/$1-up" "$T/$1"
    ( cd "$T/$1-up" && echo 2 > f && git commit -qam two )
    for h in post-merge post-checkout reference-transaction; do printf '#!/bin/sh\ntouch "%s/pwn-%s-$(basename "$0")"\n' "$T" "$1" > "$T/$1/.git/hooks/$h"; chmod +x "$T/$1/.git/hooks/$h"; done
    printf '#!/bin/sh\ntouch "%s/pwn-%s-fsmonitor"\n' "$T" "$1" > "$T/$1/fsm.sh"; chmod +x "$T/$1/fsm.sh"
    git -C "$T/$1" config core.fsmonitor "$T/$1/fsm.sh"
}
drive() { ( cd "$T/$1" && "${@:2}" git pull -q origin HEAD >/dev/null 2>&1; "${@:2}" git checkout -q -B x >/dev/null 2>&1; "${@:2}" git status >/dev/null 2>&1 ); }
mk bare; drive bare env
n_bare="$(ls "$T" | grep -c '^pwn-bare')"
[[ "$n_bare" -ge 1 ]] && ok "control: without the safe env the planted hooks/fsmonitor DO run ($n_bare markers)" || bad "control failed: the scenario does not fire, test proves nothing"
mk safe; drive safe env "${SAFE[@]}"
n_safe="$(ls "$T" | grep -c '^pwn-safe' || true)"
[[ "$n_safe" == 0 ]] && ok "with the safe env no hook or fsmonitor command runs" || bad "planted code ran under the safe env: $(ls "$T" | grep '^pwn-safe')"
[[ "$(cat "$T/safe/f")" == 2 ]] && ok "the pull itself still worked" || bad "pull did not fast-forward"

# every git/update_cli/composer/vendor-fixup run as the owner carries the env
unsafe="$(grep -nE 'sudo -u "\$\{(OWNER|owner)\}"' "$ROOT"/deploy/update.sh "$ROOT"/deploy/web-update.sh "$ROOT"/deploy/lib/common.sh | grep -vE '^\S+:[0-9]+:\s*#' | grep -E ' (git|php|bash)\b' | grep -v 'RIVETMSP_GIT_SAFE_ENV' || true)"
[[ -z "$unsafe" ]] && ok "every sudo -u OWNER git/php/bash/composer call in update.sh, web-update.sh and common.sh carries the safe env" || { bad "owner commands without the env:"; echo "$unsafe"; }

# update_cli.php sets the same variables before its first exec(); run its preamble and ask git
pre="$(sed -n "/^putenv('GIT_CONFIG_COUNT/,/^putenv('GIT_CONFIG_VALUE_1/p" "$ROOT/scripts/update_cli.php")"
[[ -n "$pre" ]] || bad "update_cli.php has no GIT_CONFIG putenv block"
mk phpc
out="$(cd "$T/phpc" && php -r "$pre"' echo trim((string) shell_exec("git config --get core.hooksPath")), "|", trim((string) shell_exec("git config --get core.fsmonitor")); shell_exec("git pull -q origin HEAD 2>&1");')"
[[ "$out" == "/dev/null|false" && ! -e "$T/pwn-phpc-fsmonitor" && "$(ls "$T" | grep -c '^pwn-phpc' || true)" == 0 ]] && ok "update_cli.php's own git calls run with hooks and fsmonitor off" || bad "update_cli.php preamble: '$out' $(ls "$T" | grep '^pwn-phpc')"
first_exec="$(grep -n 'exec(' "$ROOT/scripts/update_cli.php" | head -1 | cut -d: -f1)"; put="$(grep -n "putenv('GIT_CONFIG_COUNT" "$ROOT/scripts/update_cli.php" | cut -d: -f1)"
[[ -n "$put" && "$put" -lt "$first_exec" ]] && ok "the putenv block precedes the first exec() in update_cli.php" || bad "putenv after exec"
exit $fail
