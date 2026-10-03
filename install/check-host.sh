#!/usr/bin/env bash
#
# Will this machine run DriveToWeb? Run this ON that machine and read the verdict.
#
#   bash check-host.sh          # human report
#   bash check-host.sh --json   # one line per check, for scripting
#
# It only looks around: nothing is installed, nothing is written.
set -u
JSON=0
[ "${1:-}" = --json ] && JSON=1

BLOCK=0 ; SOFT=0
out() { if [ "$JSON" = 1 ]; then printf '%s\t%s\t%s\n' "$1" "$2" "$3"; else printf '  %-4s %-40s %s\n' "$1" "$2" "$3" ; fi ; }
hard() { out "FAIL" "$1" "$2" ; BLOCK=$((BLOCK+1)) ; }
soft() { out "warn" "$1" "$2" ; SOFT=$((SOFT+1)) ; }
good() { out "ok  " "$1" "$2" ; }

[ "$JSON" = 1 ] || printf '\nDriveToWeb - host check\n%s\n' '-------------------------------------'

# ── 0. is this even a Linux machine? (an RDP / Windows box is not) ──────────
UNAME_CMD="${UNAME_CMD:-uname}"
KERN="$("$UNAME_CMD" -s 2>/dev/null || echo unknown)"
case "$KERN" in
    Linux|MINGW*|MSYS*|CYGWIN*) : ;;
    *) hard "not Linux" "kernel says '$KERN' - install.sh needs apt, systemd, nginx, ufw and acme.sh (a bash script); none of those exist here" ;;
esac
if [ "$KERN" != Linux ]; then
    # Git Bash on Windows reports MINGW/MSYS, and the box is still Windows: the app
    # could be made to run there by hand, but nothing in this repo would, and a
    # server that must be rebuilt by a human in a Remote Desktop window is not a
    # server anyone should promise to keep online for other people's files.
    case "$KERN" in MINGW*|MSYS*|CYGWIN*)
        hard "windows" "kernel '$KERN': this is a Windows box reached over RDP. Two real options: (a) in the panel use Reinstall/Change OS and pick Ubuntu 24.04 - then SSH works and ./install.sh does everything; (b) if the image cannot be changed, the product is wrong for this app, do not pay for it or hand it your details."
        ;;
    esac
fi

# ── 1. do you actually control this machine? ────────────────────────────────
if [ "$(id -u)" = 0 ]; then
    good "root" "you are root"
    ROOTOK=1
else
    if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
        good "root" "sudo works without a password"
        ROOTOK=1
    else
        ROOTOK=0
        hard "root" "no root and no passwordless sudo - the installer must write /etc/nginx, /etc/php and restart services"
    fi
fi

for d in /home/*/public_html /usr/local/lsws /usr/local/cPanel /usr/local/cpanel /opt/lampp /etc/httpd/conf/extra; do
    [ -e "$d" ] || continue
    case "$d" in
        */public_html) hard "shared hosting layout" "$d exists: this is a chrooted web account" ;;
        *cpanel*)      hard "control panel" "cPanel detected: apache/litespeed vhosts are generated for you, and the app needs Host-based routing that only a real vhost can give" ;;
        */extra)       soft "apache extras dir" "apache in use - the shipped nginx config cannot be applied as-is" ;;
        *)             soft "stack" "$d present" ;;
    esac
done

# ── 2. OS + package manager ────────────────────────────────────────────────
if [ -r /etc/os-release ]; then
    . /etc/os-release
    case "${ID:-}" in
        ubuntu|debian) good "os" "${PRETTY_NAME:-$ID}" ;;
        *) [ "$ROOTOK" = 1 ] && soft "os" "${PRETTY_NAME:-$ID} - installer is written for apt (Ubuntu/Debian)" || hard "os" "${PRETTY_NAME:-$ID} and not root: installer will not work" ;;
    esac
else
    hard "os" "/etc/os-release missing - this is not a normal Linux server"
fi
command -v apt-get >/dev/null 2>&1 || hard "apt-get" "not found - the installer needs to install php-fpm, nginx and mongodb"

# ── 3. PHP: version and the four extensions the app calls ──────────────────
# many hosts ship PHP for the site but not as "php" on the shell PATH (cPanel
# keeps it under /opt/cpanel/ea-php*/, CloudLinux under /usr/local/php8), and
# checking only `command -v php` would wrongly call those unusable
find_php() {
    local c p
    for c in php /usr/bin/php /usr/local/bin/php /usr/sbin/php; do
        [ -x "$c" ] && command -v "$(basename "$c")" >/dev/null 2>&1 && { printf '%s' "$c" ; return 0 ; }
        [ -x "$c" ] && [ "$c" != php ] && { printf '%s' "$c" ; return 0 ; }
    done
    for p in $(ls -d /opt/cpanel/ea-php8* /usr/local/php8* 2>/dev/null | sort -r); do
        for c in "$p/root/usr/bin/php" "$p/bin/php"; do
            [ -x "$c" ] && { printf '%s' "$c" ; return 0 ; }
        done
    done
    return 1
}
PHPBIN="$(find_php || true)"
if [ -n "$PHPBIN" ]; then
    V="$("$PHPBIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    [ -n "$V" ] || V="$("$PHPBIN" -r 'echo PHP_VERSION;' | cut -d. -f1,2)"
    MAJ="${V%%.*}"
    if [ "${MAJ:-0}" -ge 8 ]; then good "php" "$V" ; else hard "php" "$V - the code needs PHP 8.0+ (match() and str_contains are used)" ; fi
    for e in mongodb mbstring curl dom; do
        if "$PHPBIN" -m 2>/dev/null | grep -qi "^$e$"; then good "php ext $e" "loaded"
        else
            case "$e" in
                mongodb) hard "php ext mongodb" "missing - without it the app cannot talk to the database at all (apt install php${V}-mongodb)" ;;
                *)       soft "php ext $e" "missing (apt install php${V}-$e)" ;;
            esac
        fi
    done
    DIS="$("$PHPBIN" -i 2>/dev/null | sed -n 's/^disable_functions => //p' | tr -d ' ')"
    for f in curl_exec file_get_contents fsockopen stream_socket_client gethostname; do
        printf '%s' "$DIS" | grep -qi "^$f,\|,$f,\|,$f$" && hard "disable_functions" "$f is disabled - the app uses it (Drive/Graph calls go through curl)"
    done
    OB="$("$PHPBIN" -i 2>/dev/null | sed -n 's/^open_basedir => //p' | head -1)"
    case "$OB" in ''|'no value'*|'no value => no value'|'__no_value__'*) : ;;
        *) soft "open_basedir" "set to '$OB' - /var/log/drvtw and the Mongo socket must stay inside it" ;;
    esac
else
    if [ "${ROOTOK:-0}" = 1 ] && command -v apt-get >/dev/null 2>&1; then
        soft "php" "no CLI php yet - the installer apt-installs php${PHPGUESS:-8.1}-fpm and its extensions"
    else
        hard "php" "no php CLI anywhere (PATH, /usr/bin, /opt/cpanel/ea-php*, /usr/local/php8) and no apt to add one: the installer cannot configure this host"
    fi
fi

# ── 4. web server you can reconfigure ──────────────────────────────────────
if command -v lsws_ctl >/dev/null 2>&1 || [ -d /usr/local/lsws ]; then
    soft "web server" "LiteSpeed detected - install.sh writes nginx server blocks, so nginx has to take ports 80/443"
fi
if [ "$ROOTOK" = 1 ]; then
    if command -v nginx >/dev/null 2>&1 || [ -d /etc/nginx/sites-available ]; then
        good "nginx" "present - the installer drops two server blocks in sites-available"
    else
        soft "nginx" "not installed - installer will apt-install it (it needs to own ports 80/443)"
    fi
    if command -v php-fpm >/dev/null 2>&1 || ls /etc/php/*/fpm/pool.d >/dev/null 2>&1; then
        good "php-fpm" "pool directory present - the app gets its own pool with D2W_LOG_FOLDER"
    else
        soft "php-fpm" "no fpm found - the app will not run under a CGI/suPHP handler the way a shared host serves WordPress"
    fi
    for p in 80 443; do
        if ss -ltn 2>/dev/null | grep -q ":$p "; then
            who="$(ss -ltnp 2>/dev/null | awk -v p=":$p" '$4 ~ p {print $NF; exit}')"
            soft "port $p busy" "$who - the installer needs to be the one answering it"
        fi
    done
fi

# ── 5. database: MongoDB, and it has to be a real server ───────────────────
if command -v mongod >/dev/null 2>&1; then good "mongod" "installed locally"
elif ss -ltn 2>/dev/null | grep -q ':27017 '; then good "mongod" "something is listening on 27017"
else soft "mongod" "not running here - use INSTALL_MONGODB=yes to install it, or point MONGO_URI at Atlas/a second box"
fi
command -v mongosh >/dev/null 2>&1 || soft "mongosh" "not installed - needed to create the app user and indexes"

# ── 6. the outside world ────────────────────────────────────────────────────
if command -v curl >/dev/null 2>&1; then
    code="$(curl -s -o /dev/null -m 8 -w '%{http_code}' https://login.microsoftonline.com/common/oauth2/v2.0/.well-known/openid-configuration 2>/dev/null || echo 000)"
    case "$code" in 2*|4*) good "outbound 443" "reached Microsoft's token endpoint (HTTP $code)" ;;
                    *)  hard "outbound 443" "could not reach login.microsoftonline.com (HTTP $code) - sign-in would fail at the first request" ;;
    esac
else
    soft "curl" "missing - install it (the installer uses it for the certificate too)"
fi

# ── 7. size ────────────────────────────────────────────────────────────────
if command -v free >/dev/null 2>&1; then
    MB="$(free -m | awk '/^Mem:/{print $2}')"
    [ "${MB:-0}" -ge 2048 ] && good "memory" "${MB} MB" || hard "memory" "${MB} MB - the app + mongod + nginx want at least 2 GB, 4 GB is comfortable"
fi
DISK="$(df -Pm / 2>/dev/null | awk 'NR==2{print $4}')"
ARCH="$(dpkg --print-architecture 2>/dev/null || uname -m)"
case "$ARCH" in amd64|x86_64) good "cpu arch" "$ARCH (everything has official packages)" ;;
               arm64|aarch64) soft "cpu arch" "$ARCH - works on Ubuntu 24.04; MongoDB arm64 builds are only published for 24.04, not 22.04" ;;
               *) hard "cpu arch" "$ARCH - no MongoDB server build exists for it; use MONGO_URI pointing at another machine" ;;
esac
if [ "${DISK:-0}" -lt 2048 ]; then
    hard "free disk" "${DISK:-?} MB free - the OS alone wants ~2 GB, so nothing can be installed here"
elif [ "${DISK:-0}" -lt 10240 ]; then
    soft "free disk" "${DISK} MB - installs, but the cache and the dumps need room (20 GB is comfortable)"
else
    good "free disk" "$(( DISK / 1024 )) GB"
fi
NPROC="$(nproc 2>/dev/null || echo '?')"
[ "$NPROC" != "?" ] && [ "$NPROC" -lt 2 ] && soft "cpu" "$NPROC core - it will work, slowly"

# ── 8. the two things no script can see from inside ─────────────────────────
soft "DNS control" "you (or your host) must be able to add a WILDCARD record: *.on.<domain> -> this box"
soft "any Host header" "the web server must answer for arbitrary hostnames (the app routes sites by Host); a panel that pins one docroot per domain cannot do this"

printf '\n'
if [ "$BLOCK" -gt 0 ]; then
    out "RESULT" "no-go on this host" "$BLOCK blocking problem(s), $SOFT to note"
    [ "$JSON" = 1 ] || cat <<'EOF'
  What this usually means: it is shared/managed hosting (cPanel, Plesk with
  generated vhosts, managed WordPress). DriveToWeb is not a PHP script you can
  drop into public_html: it needs MongoDB, its own php-fpm pool, a web server
  that answers for any <name>.on.<domain> host, and root to wire all that.

  Your options:
    1. rent a small VPS (Hetzner/DigitalOcean/Vultr/Oracle-free) and run
       install/install.sh there - the hosting you already have can stay as-is;
       point the domain's DNS at the VPS.
    2. if this box really is a VPS you just own, fix the FAIL lines above and
       re-run me:  bash check-host.sh
    3. if you must stay here: only the static front-end (frontend/www.drv.tw)
       can live on shared hosting - the API, sign-in and site serving cannot.
EOF
elif [ "$SOFT" -gt 0 ]; then
    out "RESULT" "usable, with the notes above" "0 blocking, $SOFT to fix during install"
    [ "$JSON" = 1 ] || printf '  Looks fine. sudo ./install.sh will handle the warnings; read the warn lines so you know what it changes.\n'
else
    out "RESULT" "this host is ready" "all checks passed"
fi
[ "$BLOCK" -gt 0 ] && exit 1 ; exit 0
