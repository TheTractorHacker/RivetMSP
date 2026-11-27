#!/usr/bin/env bash
# Re-review RR2 (MSP-DS-34): the GIT_CONFIG safe env only turns off hooks and fsmonitor; a repository the web user can write also runs code
# as OWNER through core.sshCommand, remote.*.uploadpack and filter smudge. The fix refuses to run git as OWNER unless the whole .git (and
# APP_DIR) is owned by OWNER/root and not group/other-writable. Real require_git_tree_trusted; git itself is the real git.
# Run: bash tests/msp_ds_review_RR2_git_tree_trusted.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'chmod -R u+rwx "$T" 2>/dev/null; rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null
ME="$(id -un)"
eval "$(sed -n '/^RIVETMSP_GIT_SAFE_ENV=(/p' "$ROOT/deploy/lib/common.sh")"
chk() { bash -c 'source "$1/deploy/lib/common.sh"; require_git_tree_trusted "$2" "$3"; echo "RC=$?"' _ "$ROOT" "$@" 2>&1; }
mk() { # mk NAME : upstream + clone with 2 commits, ssh "fork" remote, exploit scripts ready; tree is clean (0755/0644, owned by me)
    git init -q "$T/$1-up"; ( cd "$T/$1-up" && echo 1 > f && git add f && git commit -qm one )
    git clone -q "$T/$1-up" "$T/$1"; ( cd "$T/$1-up" && echo 2 > f && git commit -qam two )
    git -C "$T/$1" remote add fork ssh://nohost.invalid/repo
    printf '#!/bin/sh\ntouch "%s/pwn-%s-ssh"\nexit 1\n' "$T" "$1" > "$T/$1-ssh.sh"; chmod +x "$T/$1-ssh.sh"
    chmod -R go-w "$T/$1"
}
fetch_safe() { ( cd "$T/$1" && env "${RIVETMSP_GIT_SAFE_ENV[@]}" timeout 20 git fetch fork >/dev/null 2>&1 ); }

# control: the planted keys really run under the OLD safe env (so this test can fail)
mk ctl; git -C "$T/ctl" config core.sshCommand "$T/ctl-ssh.sh"; fetch_safe ctl
[[ -e "$T/pwn-ctl-ssh" ]] && ok "control: core.sshCommand fires although the safe env is set (the gap RR2 reports)" || bad "control did not fire; the scenario proves nothing"
git init -q "$T/ctl2-up"; ( cd "$T/ctl2-up" && echo 1 > f && git add f && git commit -qm one ); git clone -q "$T/ctl2-up" "$T/ctl2"
git -C "$T/ctl2" config remote.origin.uploadpack "sh -c 'touch $T/pwn-up; exec git-upload-pack \"\$@\"' --"
( cd "$T/ctl2" && env "${RIVETMSP_GIT_SAFE_ENV[@]}" git fetch origin >/dev/null 2>&1 )
[[ -e "$T/pwn-up" ]] && ok "control: remote.origin.uploadpack fires under the safe env" || bad "uploadpack control did not fire"

# the fix: a clean tree passes
mk good; r="$(chk "$T/good" "$ME")"
[[ "$r" == "RC=0" ]] && ok "a normally owned, not group/other-writable repository passes" || bad "clean tree refused: $r"
# the same tree is refused once the web user could have written any part of it, and git is then never started
refused() { # NAME DESC : expects refusal naming the path, then runs the update gate (check && git) and asserts no marker
    local r; r="$(chk "$T/$1" "${3:-$ME}")"
    if [[ "$r" == *"Refusing to run git/composer"* && "$r" != *RC=0* ]]; then ok "refused: $2"; else bad "$2 NOT refused: $r"; fi
    rm -f "$T/pwn-$1-ssh"
    ( bash -c 'source "$1/deploy/lib/common.sh"; require_git_tree_trusted "$2" "$3" && cd "$2" && env "${RIVETMSP_GIT_SAFE_ENV[@]}" timeout 20 git fetch fork' _ "$ROOT" "$T/$1" "${3:-$ME}" >/dev/null 2>&1 )
    [[ ! -e "$T/pwn-$1-ssh" ]] && ok "  ... and the gated git fetch ran no planted command ($2)" || bad "planted core.sshCommand ran despite the gate ($2)"
}
mk a; git -C "$T/a" config core.sshCommand "$T/a-ssh.sh"; chmod 666 "$T/a/.git/config"; refused a ".git/config world-writable carrying core.sshCommand"
mk b; git -C "$T/b" config core.sshCommand "$T/b-ssh.sh"; chmod 775 "$T/b/.git/hooks"; refused b ".git/hooks group-writable"
mk c; git -C "$T/c" config core.sshCommand "$T/c-ssh.sh"; mkdir -p "$T/c/.git/info"; echo '* filter=x' > "$T/c/.git/info/attributes"; chmod 666 "$T/c/.git/info/attributes"; refused c ".git/info/attributes world-writable (filter smudge route)"
mk d; git -C "$T/d" config core.sshCommand "$T/d-ssh.sh"; refused d ".git/config owned by another account" nobody
mk e; git -C "$T/e" config core.sshCommand "$T/e-ssh.sh"; chmod 770 "$T/e"; refused e "APP_DIR itself group-writable (the web user could swap .git)"
mk f; git -C "$T/f" config core.sshCommand "$T/f-ssh.sh"; mv "$T/f/.git" "$T/f-realgit"; ln -s "$T/f-realgit" "$T/f/.git"; refused f ".git is a symlink"
mk g; mv "$T/g/.git" "$T/g-realgit"; echo "gitdir: $T/g-realgit" > "$T/g/.git"; refused g ".git is a gitdir: file redirecting elsewhere"
mk h; chmod 664 "$T/h/.git/refs/heads/$(git -C "$T/h" symbolic-ref --short HEAD)"; refused h "a ref file group-writable (checkout of attacker content)"
# the override is explicit and loud
r="$(RIVETMSP_ALLOW_UNTRUSTED_GIT=1 chk "$T/a" "$ME")"; [[ "$r" == *RC=0* && "$r" == *Refusing* ]] && ok "RIVETMSP_ALLOW_UNTRUSTED_GIT=1 proceeds but still prints the warning" || bad "override: $r"

# wiring: every path that runs git/composer/update_cli as OWNER calls the check first
grep -q 'require_git_tree_trusted "${APP_DIR}" "${OWNER}"' "$ROOT/deploy/update.sh" && ok "update.sh checks after determine_owner" || bad "update.sh not wired"
l1="$(grep -n 'require_git_tree_trusted' "$ROOT/deploy/web-update.sh" | head -1 | cut -d: -f1)"; l2="$(grep -n 'sudo -u "${OWNER}"' "$ROOT/deploy/web-update.sh" | head -1 | cut -d: -f1)"
[[ -n "$l1" && "$l1" -lt "$l2" ]] && ok "web-update.sh checks before its first git as OWNER" || bad "web-update.sh ordering"
grep -c 'require_git_tree_trusted "${app}" "${owner}"' "$ROOT/deploy/lib/common.sh" | grep -q '^2$' && ok "ensure_updater_remote and ignore_git_filemode check too" || bad "common.sh helpers not wired"
exit $fail
