#!/bin/sh
# Validates the three shipped nginx configs with `nginx -t` without needing the
# production environment: cache paths, the fastcgi snippet and TLS certificates
# are stubbed out first, so what is checked is the routing/proxy logic.
#
#   ./tests/nginx-lint.sh          (needs nginx installed; run as root or with sudo)
set -e
cd "$(dirname "$0")/.."

NGINX=$(command -v nginx || true)
[ -n "$NGINX" ] || [ -x /usr/sbin/nginx ] || { echo "nginx not installed - skipping"; exit 0; }
[ -n "$NGINX" ] || NGINX=/usr/sbin/nginx

SUDO=""
if [ "$(id -u)" != "0" ]; then
    if command -v sudo >/dev/null 2>&1; then SUDO="sudo -n"; else
        echo "run as root (nginx -t needs to open the listen sockets' config)"; exit 2
    fi
fi

TMP=$(mktemp -d /tmp/d2w-nginxtest.XXXXXX)
trap 'rm -rf "$TMP"' EXIT

# stub certificate
if command -v openssl >/dev/null 2>&1; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
        -keyout "$TMP/k.pem" -out "$TMP/c.pem" -subj "/CN=localhost" >/dev/null 2>&1
fi

for d in client_temp proxy_temp fastcgi_temp uwsgi_temp scgi_temp cache cache2; do mkdir -p "$TMP/$d"; done

rc=0
for f in nginx_drvtw nginx_proxy nginx_drvpx; do
    [ -f "backend/$f" ] || continue

    TMP="$TMP" python3 - "backend/$f" "$TMP/$f.sanitized" <<'PY'
import os, re, sys
src, dst = sys.argv[1], sys.argv[2]
tmp = os.environ['TMP']
s = open(src, encoding='utf-8').read()

# proxy_cache_path needs a real, writable directory owned by the nginx user
s = re.sub(r'proxy_cache_path\s+[^;]*;', '', s, flags=re.S)
# the distro snippet may not exist here
s = s.replace('include snippets/fastcgi-php.conf;',
              'fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n'
              '        fastcgi_param QUERY_STRING    $query_string;\n'
              '        fastcgi_param REQUEST_METHOD  $request_method;')
# acme.sh / certbot paths from the guide do not exist here
s = re.sub(r'ssl_certificate_key\s+[^;]+;', 'ssl_certificate_key %s/k.pem;' % tmp, s)
s = re.sub(r'ssl_certificate\s+[^;]+;', 'ssl_certificate %s/c.pem;' % tmp, s)
# older nginx builds reject "listen ... ssl http2"
s = re.sub(r'(listen\s+[0-9]+)\s+ssl\s+http2\s*;', r'\1 ssl;', s)
s = re.sub(r'^\s*user\s+[^;]+;', '', s, flags=re.M)
open(dst, 'w', encoding='utf-8').write(s)
PY

    cat > "$TMP/$f.conf" <<EOF
worker_processes 1;
error_log $TMP/$f-error.log warn;
pid $TMP/$f.pid;
events { worker_connections 64; }
http {
    include /etc/nginx/mime.types;
    access_log off;
    client_body_temp_path $TMP/client_temp;
    proxy_temp_path $TMP/proxy_temp;
    fastcgi_temp_path $TMP/fastcgi_temp;
    uwsgi_temp_path $TMP/uwsgi_temp;
    scgi_temp_path $TMP/scgi_temp;
    proxy_cache_path $TMP/cache levels=1:2 keys_zone=drvtw:10m max_size=50m inactive=1d use_temp_path=off;
    proxy_cache_path $TMP/cache2 levels=1:2 keys_zone=gdc:10m max_size=50m inactive=1d use_temp_path=off;
    include $TMP/$f.sanitized;
}
EOF

    if out=$($SUDO "$NGINX" -t -p "$TMP" -c "$TMP/$f.conf" 2>&1); then
        echo "  ok   $f"
    else
        echo "  FAIL $f"
        printf '%s\n' "$out" | sed 's/^/         /'
        rc=1
    fi
done

exit $rc
