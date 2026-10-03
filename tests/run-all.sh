#!/bin/sh
# Run everything that can be checked offline.
#   ./tests/run-all.sh
# Requires: php (any 8.x), node. nginx is optional (config validation).
set -e
cd "$(dirname "$0")/.."
fail=0

echo "== php -l =="
for f in $(find backend tests -name '*.php' -not -path '*/PHPMailer/*' | sort); do
    php -l "$f" >/dev/null || { echo "LINT FAIL $f"; fail=1; }
done
echo "  ok  all PHP files parse"

echo "== node --check =="
for f in $(find backend frontend tests -name '*.js' \
        -not -path '*/jq/*' -not -path '*/sd/*' -not -path '*/theme/*' \
        -not -path '*/patterns/*' -not -name '*.min.js' -not -name 'punycode*' | sort); do
    node --check "$f" >/dev/null || { echo "SYNTAX FAIL $f"; fail=1; }
done
echo "  ok  all shipped JS parses"

echo "== deployment audit (self-test + this checkout) =="
php tools/d2w-audit.php --self-test
# On a bare checkout the audit must exit 2 (example placeholders); it must never crash.
php tools/d2w-audit.php --quiet > /tmp/d2w-audit.out 2>&1 || true
if grep -q "ERROR  blacklist" /tmp/d2w-audit.out; then
    echo "  FAIL blacklist structural errors reported"; cat /tmp/d2w-audit.out; exit 1
fi
grep -E "INFO   (blacklist|copyright)" /tmp/d2w-audit.out | sed 's/^/  /'
echo "  ok   audit ran (details in /tmp/d2w-audit.out)"

echo "== installer scripts (render + lint, no root needed) =="
for f in install/*.sh; do
    bash -n "$f" || { echo "  SYNTAX FAIL $f"; fail=1; }
done
./tests/laptop-serve-check.sh || { echo "  LAPTOP HELPER CHECKS FAILED"; fail=1; }
inst_rc=0; inst_out="$(bash install/install.sh --selftest 2>/dev/null)" || inst_rc=$?
printf '%s\n' "$inst_out" | tail -2 | sed 's/^/  /'
[ "$inst_rc" -eq 0 ] || { echo "  INSTALLER SELFTEST FAILED"; fail=1; }

echo "== PHP helper + wiring tests =="
php tests/run-tests.php
echo "== edge function tests =="
node tests/test-lambda.js
echo "== admin dashboard matcher =="
node tests/test-admin-regex.js
echo "== static site wiring =="
node tests/test-static-site.js
echo "== CSP nonce, inline handlers, backroom escaping =="
node tests/test-backroom-escaping.js
echo "== self-serve site panel in admin.js =="
node tests/test-admin-site-panel.js
echo "== PHP <-> edge contract =="
python3 tests/check-edge-contract.py

echo "== nginx configs (stubbed environment) =="
./tests/nginx-lint.sh || fail=1

[ "$fail" -eq 0 ] && echo "ALL CHECKS PASSED" || { echo "SOME CHECKS FAILED"; exit 1; }
