#!/usr/bin/env bash
# Re-review RR4 (MSP-DS-34): restore.sh, as root, followed web-user symlinks: rsync --delete through APP_DIR/uploads and
# file_put_contents/chmod/chown on APP_DIR/config.php. Now: symlinked/foreign destinations are refused, the copy and the config edit run as
# the web user (rename over config.php, never a write through it). Real restore_uploads / apply_settings_enc_key from restore.sh, real
# common.sh helpers; only `runuser` is replaced (PATH shim) and root mode comes from the RIVETMSP_TRUST_TEST_NONROOT hooks.
# Run: bash tests/msp_ds_review_RR4_restore_symlinks.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
fail=0; ok(){ echo "ok   $1"; }; bad(){ echo "FAIL $1"; fail=1; }
ME="$(id -u)"; MEN="$(id -un)"; export RIVETMSP_TEST_PRIV_SKIP=1   # the test account may be sudo-capable: the privileged-account refusal has its own test (RR1)
mkdir -p "$T/bin" "$T/etc"
printf '#!/usr/bin/env bash\n[[ "$1" == -u && "$3" == -- ]] || exit 9\necho "RUNUSER $2" >> "%s/runuser.log"; shift 3; exec "$@"\n' "$T" > "$T/bin/runuser"; chmod +x "$T/bin/runuser"
export RIVETMSP_TRUST_TEST_NONROOT=1 RIVETMSP_TEST_AS_ROOT=1 RIVETMSP_TRUST_UID="$ME" RIVETMSP_ETC_DIR="$T/etc" PATH="$T/bin:$PATH"
echo "WEB_USER=$MEN" > "$T/etc/web-user.conf"; chmod 644 "$T/etc/web-user.conf"
sed -n '/^restore_uploads() {/,/^}/p;/^apply_settings_enc_key() {/,/^}/p' "$ROOT/deploy/restore.sh" > "$T/funcs.sh"
[[ -s "$T/funcs.sh" ]] || { bad "could not extract the functions"; exit 1; }
# drive FN in a fresh shell: the real common.sh (die/announce/run_app_exec...), the real function bodies
drive() { bash -c 'source "$1/deploy/lib/common.sh"; source "$2"; APP_DIR="$3"; EXTRACT_DIR="$4"; BACKUP_FILE=x; SETTINGS_ENC_KEY=old; MANIFEST_SETTINGS_ENC_KEY="$5"; if ( "$6" ); then echo RC=0; else echo RC=$?; fi' _ "$ROOT" "$T/funcs.sh" "$@" 2>&1; }
newcase() { C="$T/$1"; rm -rf "$C"; mkdir -p "$C/app" "$C/ex/uploads/sub" "$C/victim"; echo new > "$C/ex/uploads/sub/new.txt"; echo keep > "$C/victim/important"; echo '<?php' > "$C/app/config.php"; }

# --- uploads ---
newcase u1; ln -s "$C/victim" "$C/app/uploads"
r="$(drive "$C/app" "$C/ex" k restore_uploads)"
[[ "$r" == *RC=1* && "$r" == *"symbolic link"* && "$(cat "$C/victim/important")" == keep && ! -e "$C/victim/sub" && -L "$C/app/uploads" ]] && ok "uploads -> symlink is refused; the target keeps its files and receives none" || bad "uploads symlink: $r $(ls "$C/victim")"
newcase u2; mkdir -p "$C/app/uploads/old"; echo stale > "$C/app/uploads/old/stale.txt"; ln -s "$C/victim" "$C/app/uploads/sub"; ln -s /etc/hostname "$C/ex/uploads/archived-link"
r="$(drive "$C/app" "$C/ex" k restore_uploads)"
[[ "$r" == *RC=0* && "$(cat "$C/app/uploads/sub/new.txt")" == new && ! -e "$C/app/uploads/old" && "$(cat "$C/victim/important")" == keep && ! -e "$C/victim/new.txt" && ! -L "$C/app/uploads/sub" ]] && ok "normal restore works; a symlink planted INSIDE uploads/ is replaced, not followed" || bad "uploads normal: $r"
[[ ! -e "$C/app/uploads/archived-link" ]] && ok "a symlink inside the archive's uploads/ is not recreated (--no-links)" || bad "archive symlink was copied"
[[ "$(grep -c 'RUNUSER' "$T/runuser.log")" -ge 2 && "$(stat -c %a "$C/app/uploads/sub")" == 770 && "$(stat -c %a "$C/app/uploads/sub/new.txt")" == 660 ]] && ok "mkdir and rsync ran through runuser as the web user; modes match install.sh (770/660)" || bad "runuser/mode: $(cat "$T/runuser.log") $(stat -c %a "$C/app/uploads/sub")"
newcase u3; mkdir "$C/app/uploads"; echo "WEB_USER=nobody" > "$T/etc/web-user.conf"
r="$(drive "$C/app" "$C/ex" k restore_uploads)"; echo "WEB_USER=$MEN" > "$T/etc/web-user.conf"; chmod 644 "$T/etc/web-user.conf"
[[ "$r" == *RC=1* && "$r" == *"not the web user"* ]] && ok "an uploads/ directory not owned by the web user is refused" || bad "uploads owner: $r"
newcase u4; : > "$C/app/uploads"
r="$(drive "$C/app" "$C/ex" k restore_uploads)"; [[ "$r" == *RC=1* && "$r" == *"not a directory"* ]] && ok "uploads that is a file is refused" || bad "uploads file: $r"
newcase u5; rm -f "$T/etc/web-user.conf"; ln -s "$C/victim" "$C/app/uploads"
grep -q -- '--no-same-owner' "$ROOT/deploy/restore.sh" && ok "tar extraction is --no-same-owner" || bad "tar keeps archived owners"
echo "WEB_USER=$MEN" > "$T/etc/web-user.conf"; chmod 644 "$T/etc/web-user.conf"

# --- config.php ---
newcase c1; echo SHADOW > "$C/victim/shadow"; chmod 600 "$C/victim/shadow"; rm "$C/app/config.php"; ln -s "$C/victim/shadow" "$C/app/config.php"
r="$(drive "$C/app" "$C/ex" newkey apply_settings_enc_key)"
[[ "$r" == *RC=1* && "$r" == *"symbolic link"* && "$(cat "$C/victim/shadow")" == SHADOW && "$(stat -c %a "$C/victim/shadow")" == 600 ]] && ok "config.php -> symlink: refused; target not rewritten, chmod'd or chown'd" || bad "config symlink: $r"
# the race: a symlink swapped in AFTER the check. Take the function with the -L refusal deleted and show the write still cannot reach the target.
newcase c2; echo SHADOW > "$C/victim/shadow"; chmod 600 "$C/victim/shadow"; rm "$C/app/config.php"; ln -s "$C/victim/shadow" "$C/app/config.php"
grep -v 'is a symbolic link; refusing to rewrite' "$T/funcs.sh" > "$T/funcs-nocheck.sh"
r="$(bash -c 'source "$1/deploy/lib/common.sh"; source "$2"; APP_DIR="$3"; BACKUP_FILE=x; SETTINGS_ENC_KEY=old; MANIFEST_SETTINGS_ENC_KEY=newkey; apply_settings_enc_key; echo RC=$?' _ "$ROOT" "$T/funcs-nocheck.sh" "$C/app" 2>&1)"
[[ "$(cat "$C/victim/shadow")" == SHADOW && "$(stat -c %a "$C/victim/shadow")" == 600 && ! -L "$C/app/config.php" && "$(cat "$C/app/config.php")" == *newkey* ]] && ok "even with the check bypassed (swap after the check) the edit replaces the NAME via rename and never writes through the link" || bad "race: $r"
newcase c3; printf '<?php\n$dbhost = "x";\n$config_settings_enc_key = "old";\n$other = 1;\n' > "$C/app/config.php"; chmod 600 "$C/app/config.php"
r="$(drive "$C/app" "$C/ex" 'n3w$key' apply_settings_enc_key)"
[[ "$r" == *RC=0* && "$(grep -c config_settings_enc_key "$C/app/config.php")" == 1 && "$(grep -c 'other = 1' "$C/app/config.php")" == 1 && "$(grep config_settings_enc_key "$C/app/config.php")" == *'n3w$key'* && "$(stat -c %a "$C/app/config.php")" == 640 && ! -L "$C/app/config.php" && -z "$(ls -A "$C/app" | grep '^\.config\.php\.')" ]] && ok "a regular config.php is edited in place (key replaced, other lines kept, 0640, no temp file left)" || bad "config normal: $r $(cat "$C/app/config.php")"
exit $fail
