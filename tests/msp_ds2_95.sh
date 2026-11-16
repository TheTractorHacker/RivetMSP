#!/usr/bin/env bash
# MSP-DS-95: the settings-encryption key and the DB/admin passwords never appear on a process command line (/proc/PID/cmdline is world-readable).
# Runs the real write_settings_key_file() from deploy/backup.sh with php wrapped to record every argv, plus static checks of the other scripts.
# Run: bash tests/msp_ds2_95.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail=0
ok() { if [[ "$2" == 1 ]]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi; }
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
KEY="$(head -c32 /dev/urandom | od -An -tx1 | tr -d ' \n')"

# --- behavioural: backup.sh's key-file writer ---
REAL_PHP="$(command -v php)"
cat > "$T/php" <<EOF
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$T/argv.log"
exec "$REAL_PHP" "\$@"
EOF
chmod +x "$T/php"
out="$(PATH="$T:$PATH" SETTINGS_ENC_KEY="$KEY" bash -c "source '$ROOT/deploy/backup.sh'; SETTINGS_ENC_KEY='$KEY'; write_settings_key_file '$T/x.settings-key'" 2>&1)"
ok "key file was written with the key" "$([[ -f "$T/x.settings-key" ]] && grep -q "^$KEY\$" "$T/x.settings-key" && echo 1 || echo 0)"
ok "key file carries the right fingerprint line" "$(grep -q '^# fingerprint: [0-9a-f]\{16\}$' "$T/x.settings-key" && echo 1 || echo 0)"
ok "php was actually invoked (the recorder saw it)" "$([[ -s "$T/argv.log" ]] && echo 1 || echo 0)"
ok "the key is NOT in any recorded php argv" "$(grep -q "$KEY" "$T/argv.log" && echo 0 || echo 1)"

# --- static: no script passes the key or the passwords as an argument ---
b="$ROOT/deploy/backup.sh"; r="$ROOT/deploy/restore.sh"; i="$ROOT/deploy/install.sh"; e="$ROOT/docker/entrypoint.sh"
ok "backup.sh: no php/run_app_php call takes SETTINGS_ENC_KEY after --" "$(grep -nE "^\s*' -- .*SETTINGS_ENC_KEY" "$b" >/dev/null && echo 0 || echo 1)"
ok "backup.sh: manifest builder reads the key from the environment" "$(grep -q 'RIVETMSP_SK="${SETTINGS_ENC_KEY}" run_app_php' "$b" && echo 1 || echo 0)"
ok "restore.sh: fingerprint check does not pass the key as an argument" "$(grep -nE "'\s*-- \"\\\$\{key\}\"" "$r" >/dev/null && echo 0 || echo 1)"
ok "restore.sh: config.php rewrite does not pass the key as an argument" "$(grep -nE "' -- .*MANIFEST_SETTINGS_ENC_KEY" "$r" >/dev/null && echo 0 || echo 1)"
for f in "$i" "$e"; do
  ok "$(basename "$f"): no 'env ITFLOW_*_PASSWORD=value' on a command line" "$(grep -nE 'env [^|]*ITFLOW_(DB|ADMIN)_PASSWORD=' "$f" >/dev/null && echo 0 || echo 1)"
  ok "$(basename "$f"): sudo preserves the exported variables" "$(grep -q 'sudo --preserve-env=ITFLOW_DB_PASSWORD,ITFLOW_ADMIN_PASSWORD' "$f" && echo 1 || echo 0)"
done
bash -n "$b" "$r" "$i" "$e" && ok "all four scripts still parse" 1 || ok "all four scripts still parse" 0
exit $fail
