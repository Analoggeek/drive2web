#!/usr/bin/env bash
# Offline check for install/laptop-serve.sh - the helper that turns a laptop at home into
# this app's server. Nothing here needs root, a network, or a Cloudflare account: the
# cloudflared binary is a stub that records what it was asked to do.
#   tests/laptop-serve-check.sh
set -u
cd "$(dirname "$0")/.."
SCRIPT=install/laptop-serve.sh
pass=0 ; fail=0
ok()  { printf '  ok   %s\n' "$1" ; pass=$((pass+1)) ; }
bad() { printf '  FAIL %s%s\n' "$1" "${2:+  ($2)}" ; fail=$((fail+1)) ; }
has() { case "$2" in *"$1"*) return 0 ;; esac ; return 1 ; }

[ -f "$SCRIPT" ] || { echo "no $SCRIPT"; exit 1; }
bash -n "$SCRIPT" || { echo "  FAIL $SCRIPT does not parse"; exit 1; }

HOME0="$(mktemp -d)"
trap 'rm -rf "$HOME0"' EXIT
mkdir -p "$HOME0/.cloudflared" "$HOME0/bin" "$HOME0/repo/install"
cp install/*.sh "$HOME0/repo/install/"   # check-host.sh is called from the same folder

# a cloudflared that only remembers its arguments
cat > "$HOME0/bin/cloudflared" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_CF_LOG"
[ "$1" = tunnel ] && [ "$2" = ingress ] && { echo "Validating rules"; echo "OK"; exit 0; }
exit 0
EOF
chmod +x "$HOME0/bin/cloudflared"
cat > "$HOME0/.cloudflared/config.yml" <<'EOF'
tunnel: 11111111-2222-3333-4444-555555555555
credentials-file: /x.json
ingress:
  - hostname: example.org
    service: https://127.0.0.1:443
    originRequest:
      noTLSVerify: true
  - service: http_status:404
EOF
printf 'DOMAIN=example.org\n' > "$HOME0/repo/install/config.env"
run() { ( cd "$HOME0/repo/install" && HOME="$HOME0" PATH="$HOME0/bin:$PATH" FAKE_CF_LOG="$HOME0/log" \
          ./laptop-serve.sh "$@" ) ; }
cfgyml() { cat "$HOME0/.cloudflared/config.yml" ; }

# 1. names it must refuse, before it touches anything
out="$(run host 'BAD_NAME' 2>&1)" ; rc=$?
{ [ "$rc" = 2 ] && has 'not a claimable site name' "$out" ; } \
    && ok 'refuses a name the app itself would refuse' || bad 'bad-site-name guard' "rc=$rc $out"
out="$(run host 'ramesh.something else' 2>&1)" ; rc=$?
{ [ "$rc" = 2 ] && has 'not a hostname I can route' "$out" ; } \
    && ok 'refuses a malformed custom domain' || bad 'bad-host guard' "rc=$rc"

# 2. a bare word gets the .on.<domain> suffix; a dotted value is routed verbatim
run host ramesh > /dev/null 2>&1
y="$(cfgyml)"
if has 'hostname: ramesh.on.example.org' "$y"; then ok 'site name becomes <name>.on.<domain>' ; else bad 'suffix' "$y" ; fi
if [ "$(printf '%s' "$y" | grep -c 'service: http_status:404')" = 1 ]; then
    # the last thing in the file has to be the catch-all, or cloudflared rejects the config
    if has 'http_status:404' "$(cfgyml | grep -v '^[[:space:]]*$' | tail -1)"; then
        ok 'the catch-all stayed the last rule'
    else
        bad 'catch-all moved off the end' "$(cfgyml | tail -1)"
    fi
else
    bad 'catch-all lost'
fi
run host files.example.in > /dev/null 2>&1
if has 'hostname: files.example.in' "$(cfgyml)" && ! has 'files.example.in.on.' "$(cfgyml)"; then
    ok "a tenant's own domain is routed as-is"
else
    bad 'custom domain handling'
fi

# 3. every new rule uses https + noTLSVerify, because nginx 301s http to https
body="$(cfgyml | grep -A3 'hostname: ramesh')"
if has 'service: https://127.0.0.1:443' "$body" && has 'noTLSVerify: true' "$body"; then
    ok 'origin is https://127.0.0.1:443 with noTLSVerify'
else
    bad 'origin block' "$body"
fi

# 4. idempotent: a second run says so and does not duplicate the entry
before="$(cfgyml | grep -c 'hostname:')"
out="$(run host ramesh 2>&1)"
after="$(cfgyml | grep -c 'hostname:')"
{ has 'already in' "$out" && [ "$before" = "$after" ] ; } \
    && ok 'a repeat run changes nothing' || bad 'not idempotent' "before=$before after=$after"

# 5. --dry-run promises and does not deliver
cp "$HOME0/.cloudflared/config.yml" "$HOME0/before.yml"
run host ghost --dry-run > /dev/null 2>&1
if cmp -s "$HOME0/before.yml" "$HOME0/.cloudflared/config.yml"; then
    ok '--dry-run left the config alone'
else
    bad '--dry-run wrote the file'
fi
if ! has 'ghost' "$(cfgyml)"; then ok 'and added no hostname either' ; else bad 'dry-run added a rule' ; fi

# 6. what it asked cloudflared to do (only from the real run, not the dry one)
log="$(cat "$HOME0/log" 2>/dev/null)"
if has 'tunnel route dns 11111111-2222-3333-4444-555555555555 ramesh.on.example.org' "$log"; then
    ok 'it created the DNS record for the hostname'
else
    bad 'route dns never ran' "$log"
fi
if has 'tunnel ingress validate' "$log"; then
    ok 'it validated the config with cloudflared itself'
else
    bad 'no ingress validate'
fi
if ! has 'ghost' "$log"; then ok 'nothing was sent to Cloudflare for the dry run' ; else bad 'dry run called cloudflared' ; fi

# 7. a config whose catch-all it cannot find must refuse, not corrupt
printf 'tunnel: abc\ningress:\n  - hostname: example.org\n    service: http://127.0.0.1:8080\n' > "$HOME0/.cloudflared/config.yml"
out="$(run host deep --dry-run 2>&1)" ; rc=$?
{ [ "$rc" = 1 ] && has 'catch-all' "$out" ; } \
    && ok 'refuses instead of appending an invalid rule' || bad 'missing catch-all handling' "rc=$rc $out"

# 8. wake must never claim success when it cannot write
if [ "$(id -u)" != 0 ]; then
    out="$(run wake 2>&1)" ; rc=$?
    { [ "$rc" != 0 ] && has 'root' "$out" ; } \
        && ok 'wake refuses to pretend without root' || bad 'wake as a normal user' "rc=$rc $out"
else
    printf '  sk   wake (running as root, the refusal path is not reachable)\n'
fi

# 9. check must work with no network and no server, and must not crash
out="$(run check 2>&1)" ; rc=$?
case "$rc" in 0|1) : ;; *) bad "check exited $rc" ;; esac
if [ "$rc" = 0 ] || [ "$rc" = 1 ]; then ok "check ran offline (rc=$rc)" ; else bad 'check exit code' "rc=$rc" ; fi
if has 'cpu' "$out" && has 'hardware' "$out"; then ok 'check reports the CPU verdict' ; else bad 'check output' ; fi
if has 'tunnel' "$out"; then ok 'check reports the tunnel' ; else bad 'check missed the tunnel section' ; fi

printf '\n%d passed, %d failed\n' "$pass" "$fail"
[ "$fail" = 0 ] || exit 1
