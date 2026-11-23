#!/usr/bin/env bash
# Review R34 (MSP-DS-34): the remediation printed by require_trusted_tooling is `sudo deploy/install-trusted-tooling.sh`,
# which only works when git records the file as executable. Run: bash tests/msp_ds_review_R34_tooling_exec_bit.sh
set -u
cd "$(dirname "$0")/.."
fail=0
for f in deploy/install-trusted-tooling.sh deploy/backup.sh deploy/restore.sh deploy/restore_drill.sh deploy/update.sh deploy/web-update.sh deploy/install-web-update.sh; do
    mode="$(git ls-files -s -- "$f" | awk '{print $1}')"
    if [[ "$mode" == 100755 ]]; then echo "ok   $f is 100755 in git"; else echo "FAIL $f has git mode '${mode:-untracked}' (want 100755)"; fail=1; fi
done
exit $fail
