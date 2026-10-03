#!/usr/bin/env bash
#
# Does the server work? Run this any time, by itself.
#
#   ./verify.sh            # the go/no-go list, PASS/FAIL per line
#   ./verify.sh --deep     # also: sign in as a real owner and prove the rename
#                          #       guards (needs mongosh, and it writes nothing)
#
# Nothing here changes anything on disk except a temp file.
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
CONF="$HERE/config.env"
[ -f "$CONF" ] || CONF=/etc/drvtw/config.env
[ -f "$CONF" ] && { set -a ; . "$CONF" ; set +a ; }
APP_DIR="${APP_DIR:-/var/www/drvtw}"
DOMAIN="${DOMAIN:?DOMAIN is not set (install/config.env or /etc/drvtw/config.env)}"
API_HOST="api.$DOMAIN"
WWW_HOST="www.$DOMAIN"
DEEP=0
[ "${1:-}" = --deep ] && DEEP=1
CREDS="$APP_DIR/html/drvtw_private/drvtw_creds.php"

pass=0 ; fail=0 ; skip=0
ok()   { printf '  \033[32mPASS\033[0m  %-46s %s\n' "$1" "${2:-}" ; pass=$((pass+1)) ; }
bad()  { printf '  \033[31mFAIL\033[0m  %-46s %s\n' "$1" "${2:-}" ; fail=$((fail+1)) ; }
sk()   { printf '  \033[33mskip\033[0m  %-46s %s\n' "$1" "${2:-}" ; skip=$((skip+1)) ; }
head1() { curl -s -o /dev/null -m 20 -w '%{http_code}' -I "$1" 2>/dev/null || echo 000 ; }
sec() { printf '\n\033[1m%s\033[0m\n' "$*" ; }

code() { curl -s -o /dev/null -m 20 -w '%{http_code}' "${1:-}" ; }

sec "runtime output"
# Cheap and it has bitten for real: with display_errors on, a single deprecation
# lands inside the JSON and the caller's parse fails, so the app looks "randomly
# broken" while the log says nothing.
# Cheap, and it has bitten for real: with display_errors on, one deprecation lands
# inside the JSON body and the caller's parse fails, so the app looks randomly broken
# while the log says nothing. Only PHP's own banners count here - matching a bare
# <br /> looked fine until a 401 page (which legitimately contains two of them) would
# have failed this check for the wrong reason.
BODY="$(curl -s -m 20 -k "https://$API_HOST/do/siteName?name=procheck$$" 2>/dev/null || true)"
if [ -z "$BODY" ]; then
    sk "no php notices in responses" "no answer from https://$API_HOST (is the site up?)"
elif printf '%s' "$BODY" | grep -qiE "<b>(Deprecated|Warning|Notice|Parse error|Fatal error)</b>|Warning:|Deprecated:|Notice:|Fatal error:|Undefined (array key|variable)" ; then
    bad "no php notices in responses" "PHP error banner in the body - set php_admin_value[display_errors]=Off"
else
    ok "no php notices in responses" "clean body"
fi

sec "configuration"
if [ -f "$CREDS" ]; then
    perm=$(stat -c '%a' "$CREDS" 2>/dev/null || echo unknown)
    case "$perm" in 600|640|400|440) ok "credentials file mode" "$perm" ;; *) bad "credentials file mode" "$perm (should be 0640)" ;; esac
    php -l "$CREDS" >/dev/null 2>&1 && ok "credentials file parses" || bad "credentials file parses" "php -l failed"
    for k in d2wBaseDomain sessionSecret odClientId mongoLocalConnection ; do
        grep -q "\$$k" "$CREDS" && ok "key set: \$$k" || bad "key set: \$$k" "missing"
    done
    grep -Eq "your\.domain|CHANGEME|REPLACE_WITH|YOUR_" "$CREDS" && bad "no placeholders left" || ok "no placeholders left"
else
    bad "credentials file" "$CREDS missing - run sudo ./install.sh --only creds"
fi

sec "the deployment audit tool"
if [ -f "$REPO/tools/d2w-audit.php" ] && need_it=$(command -v php || true) ; then
    out="$("$REPO/tools/d2w-audit.php" --root "$APP_DIR" 2>&1)" ; rc=$?
    case "$rc" in
        0) if printf '%s' "$out" | grep -q WARN ; then
               ok "audit" "warnings only" ; printf '%s\n' "$out" | grep WARN | sed 's/^/         /'
           else ok "audit" "clean" ; fi ;;
        2) bad "audit" "blocking problems:" ; printf '%s\n' "$out" | grep -E 'ERROR' | sed 's/^/         /' ;;
        *) bad "audit" "exit $rc (wrong --root?)" ;;
    esac
else
    sk "audit" "tools/d2w-audit.php or php not found"
fi

sec "log folder (the number-one install bug)"
if [ -d /var/log/drvtw ]; then
    m=$(stat -c '%a' /var/log/drvtw)
    [ "$(( 8#$m & 8#0111 ))" -ne 0 ] && ok "/var/log/drvtw searchable" "$m" || bad "/var/log/drvtw searchable" "$m - needs 0770, not 0640"
    [ -f /var/log/drvtw/user.log ] && ok "log lines are being written" "$(wc -l < /var/log/drvtw/user.log) lines" \
        || sk "log lines are being written" "no user.log yet (visit the site once)"
else
    bad "/var/log/drvtw" "missing - run sudo ./install.sh --only logdir"
fi

sec "nginx + PHP"
[ "$(code "http://$API_HOST/")" = 301 ] || [ "$(code "http://$API_HOST/")" = 302 ] \
    && ok "http redirects to https" || bad "http redirects to https" "$(code http://$API_HOST/)"
[ "$(code "https://$API_HOST/drvtw_private/drvtw_creds.php")" = 404 ] \
    && ok "credentials unreachable over http" || bad "credentials unreachable over http" "$(code https://$API_HOST/drvtw_private/drvtw_creds.php)"
h="$(curl -sI -m 20 "https://nothing.on.$DOMAIN/" 2>/dev/null | tr -d '\r')"
printf '%s' "$h" | grep -qi '^content-security-policy:' && ok "app pages send the CSP header" || bad "app pages send the CSP header" "no header"
printf '%s' "$h" | grep -qi "^x-content-type-options: nosniff" && ok "app pages send nosniff" || bad "app pages send nosniff" "no header"
n="$(printf '%s' "$h" | grep -ci "nonce-" || true)"
[ "$n" -ge 1 ] && ok "CSP carries a per-request nonce" || bad "CSP carries a per-request nonce" "script-src has no 'nonce-' - inline JS on the site pages will be blocked"
b="$(curl -s -m 20 "https://nothing.on.$DOMAIN/" 2>/dev/null)"
printf '%s' "$b" | grep -Eq '<script[^>]*nonce="[0-9a-f]{32}"' && ok "the rendered page's inline script is nonced" \
    || bad "the rendered page's inline script is nonced" "mismatch between the header and the page"

sec "TLS"
if command -v openssl >/dev/null; then
    enddate="$(echo | openssl s_client -servername "$API_HOST" -connect "$API_HOST:443" 2>/dev/null \
        | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)"
    [ -n "$enddate" ] && ok "certificate valid until" "$enddate" || bad "certificate" "could not read it"
    echo | openssl s_client -servername "$API_HOST" -connect "$API_HOST:443" 2>/dev/null \
        | grep -q "Verification return code: 00" && ok "trusted by the system CA" || sk "trusted by the system CA" "check with a browser"
else
    sk "TLS" "openssl missing"
fi

sec "database"
if command -v mongosh >/dev/null; then
    n=$(mongosh --quiet --eval 'try{print(db.getSiblingDB("wwwdrv").sites.countDocuments({}))}catch(e){print("ERR")}}' 2>/dev/null || echo ERR)
    [ "$n" = ERR ] && bad "wwwdrv reachable" "is auth right? see /etc/drvtw/secrets.env" || ok "wwwdrv reachable" "$n site(s)"
    idx=$(mongosh --quiet --eval 'try{print(db.getSiblingDB("wwwdrv").authfail.getIndexes().length)}catch(e){print(0)}' 2>/dev/null || echo 0)
    [ "${idx:-0}" -ge 2 ] && ok "authfail indexes (TTL for the lockout)" "$idx" || bad "authfail indexes (TTL for the lockout)" "run install/mongod/init.js"
else
    sk "database" "mongosh not installed here"
fi

sec "front-end + sign-in"
[ "$(code "https://$WWW_HOST/")" = 200 ] && ok "landing page serves" || bad "landing page serves" "$(code https://$WWW_HOST/)"
sfx="$(curl -s -m 20 "https://$WWW_HOST/inc/config.js" 2>/dev/null | sed -n "s/.*siteSuffix: '\([^']*\)'.*/\1/p")"
[ "$sfx" = ".on.$DOMAIN" ] && ok "config.js siteSuffix" "$sfx" || bad "config.js siteSuffix" "'$sfx' - owners would be sent to the wrong host"
cid="$(curl -s -m 20 "https://$WWW_HOST/inc/config.js" 2>/dev/null | sed -n "s/.*microsoftClientId: '\([^']*\)'.*/\1/p")"
[ -n "$cid" ] && [ "$cid" != YOUR_MICROSOFT_CLIENT_ID ] && [ "$cid" != null ] && ok "OneDrive client id in config.js" || bad "OneDrive client id in config.js" "'$cid'"

sec "the naming API (your site address)"
j="$(curl -s -m 20 "https://$API_HOST/do/siteName?name=test-site" 2>/dev/null)"
printf '%s' "$j" | grep -q '"available"' && ok "do/siteName answers" "$j" || bad "do/siteName answers" "$j"
printf '%s' "$(curl -s -m 20 "https://$API_HOST/do/siteName?name=www")" | grep -q '"available":false' \
    && ok "reserved names refused" || bad "reserved names refused" "$(curl -s -m 20 "https://$API_HOST/do/siteName?name=www")"
printf '%s' "$(curl -s -m 20 "https://$API_HOST/do/siteName?name=caf%C3%A9")" | grep -q 'letters a-z' \
    && ok "accented names refused, not transliterated" || bad "accented names refused, not transliterated" "$(curl -s "https://$API_HOST/do/siteName?name=caf%C3%A9")"
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d 'name=x' "https://$API_HOST/@@rename")" != 200 ] \
    && ok "@@rename needs an owner session" || bad "@@rename needs an owner session" "it answered 200 to an anonymous POST"

if [ "$DEEP" = 1 ]; then
    sec "as a real owner (writes nothing, mints a throwaway session)"
    # The owner path is /~<username>/od/... where <username> is the account the user
    # signed in with (an email address) - NOT the ObjectId, and not "<id>@<site>", which
    # is what this used to build: no such username exists, so the app answered its
    # "go home" 302 and every guard below looked broken. mongosh is only a convenience
    # for finding such a user - pass DEEP_USER + DEEP_UID and this runs without it.
    if [ -n "${DEEP_USER:-}" ] && [ -n "${DEEP_UID:-}" ] && [ -n "${DEEP_SITE:-}" ]; then
        OWNER="$DEEP_USER" ; UID24="$DEEP_UID" ; SITE="$DEEP_SITE"
    else
        command -v mongosh >/dev/null 2>&1 \
            || sk "mongosh" "not installed - DEEP_USER + DEEP_UID let these checks run anyway"
        if command -v mongosh >/dev/null 2>&1 ; then
            SITE="$(mongosh --quiet --eval 'var d=db.getSiblingDB("wwwdrv");var s=d.sites.findOne({service:"od"})||d.sites.findOne();print(s?s.name:"")' 2>/dev/null || true)"
            UID24="$(mongosh --quiet --eval 'var d=db.getSiblingDB("wwwdrv");var s=d.sites.findOne({service:"od"})||d.sites.findOne();print(s?s.userId:"")' 2>/dev/null || true)"
            OWNER="$(mongosh --quiet --eval 'var d=db.getSiblingDB("wwwdrv");var s=d.sites.findOne({service:"od"})||d.sites.findOne();var u=s?d.users.findOne({_id:s.userId}):null;print(u?u.username:"")' 2>/dev/null || true)"
        fi
    fi
    if [ -z "$OWNER" ] || [ -z "$UID24" ] || [ -z "$SITE" ]; then
        sk "owner checks" "no signed-in site in the DB yet - sign in from the landing page first (or pass DEEP_USER + DEEP_UID + DEEP_SITE)"
    else
        # A 900-second session minted from the same secret the cookie uses, so the checks
        # below run as the owner without a browser. NB: drvtw_util.php sits next to the
        # credentials file, not one directory above it - the old require pointed too high
        # and the whole block degraded into a silent "skip", which is exactly the check
        # you want after a permissions change. Nothing is swallowed any more.
        mint() { env U="$UID24" D2W_CREDS="$CREDS" php -r '
            $c = getenv("D2W_CREDS");
            require dirname($c) . "/drvtw_util.php";
            require $c;
            $s = d2w_make_session(getenv("U"), "od", 900);
            echo "csrf:" . d2w_csrf_token($s) . "\nsess:" . $s . "\n";' 2>&1 || true ; }
        M="$(mint)"
        SESS="$(printf '%s' "$M" | sed -n 's/^sess://p')"
        CSRF="$(printf '%s' "$M" | sed -n 's/^csrf://p')"
        if [ -z "$SESS" ]; then
            bad "owner session can be minted" "$(printf '%s' "$M" | grep -iE "error|warning|missing" | head -1 | cut -c1-90)"
        else
            ok "owner session can be minted" "900 s, throwaway"
            # The api host answers /~<user>/od/@@x with a 301 to the canonical site
            # host; the guards live behind that, so ask the site host directly.
            BASE="${DEEP_BASE:-https://$SITE.on.$DOMAIN}"
            r="$(curl -s -m 20 -k -H "Cookie: D2WSESS=$SESS" "$BASE/@@site")"
            LINKED=1        # the two guards below only mean something once the site resolves
            if printf '%s' "$r" | grep -q '"ok":true' ; then
                ok "@@site as the owner" "$(printf '%s' "$r" | head -c 150)"
            elif printf '%s' "$r" | grep -q "File not found" ; then
                # routing, session and CSRF plumbing all worked: the site record exists
                # but its Drive root is not live (a hand-inserted row, or a user whose
                # token expired), so resolve() has nothing to walk.
                LINKED=0
                sk "@@site as the owner" "site row has no live Drive link - sign in once from the landing page"
            else
                bad "@@site as the owner" "$(printf '%s' "$r" | head -c 150)"
            fi
            if [ "$LINKED" = 0 ]; then
                sk "@@rename guards" "unreachable until a site resolves (the request 404s before the mutator)"
            else
                r2="$(curl -s -o /dev/null -w '%{http_code}' -m 20 -k -X POST -H "Cookie: D2WSESS=$SESS" -d "name=zzz-test" "$BASE/@@rename")"
                [ "$r2" = 403 ] && ok "@@rename without the CSRF header is refused" "$r2" || bad "@@rename without the CSRF header is refused" "${r2} (expected 403)"
                r3="$(curl -s -o /dev/null -w '%{http_code}' -m 20 -k -X GET -H "Cookie: D2WSESS=$SESS" -H "X-D2W-CSRF: $CSRF" "$BASE/@@rename")"
                [ "$r3" = 405 ] && ok "a mutator cannot be triggered by GET" "$r3" || bad "a mutator cannot be triggered by GET" "${r3} (expected 405)"
            fi
        fi
    fi
fi
printf '\n  %d passed, %d failed, %d skipped\n' "$pass" "$fail" "$skip"
[ "$fail" = 0 ] || printf '  see DEPLOY-RUNBOOK.md section 12 for what each check means\n'
[ "$fail" = 0 ]
