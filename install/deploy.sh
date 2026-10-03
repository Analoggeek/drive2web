#!/usr/bin/env bash
#
# Push a new version of the code to the server. Run this ON THE SERVER, in the
# checkout (usually /root/drvtw_oss or $HOME/drvtw_oss).
#
#   sudo ./deploy.sh                 # git pull, test, copy, reload
#   sudo ./deploy.sh --patch FILE    # apply a .patch instead of pulling
#   sudo ./deploy.sh --rev abc123    # checkout a specific commit
#   sudo ./deploy.sh --skip-tests    # when you are in a hurry (not recommended)
#
# It always backs up drvtw_creds.php before rsync --delete, and it never touches
# /var/lib/mongodb.
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
CONF="$HERE/config.env"
APP_DIR=/var/www/drvtw
PULL=git ; REV="" ; TESTS=1 ; PATCH=""

while [ $# -gt 0 ]; do
    case "$1" in
        --patch) PATCH="$2" ; PULL=patch ; shift ;;
        --rev)   REV="$2" ; shift ;;
        --skip-tests) TESTS=0 ;;
        -h|--help) sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//' ; exit 0 ;;
        *) echo "unknown option: $1" >&2 ; exit 2 ;;
    esac
    shift
done

[ "$(id -u)" = 0 ] || { echo "run as root: sudo $0" >&2 ; exit 1 ; }
[ -f "$CONF" ] && { set -a ; . "$CONF" ; set +a ; } || echo "no $CONF - using /etc/drvtw/config.env"
[ -f "$CONF" ] || CONF=/etc/drvtw/config.env
[ -f "$CONF" ] && { set -a ; . "$CONF" ; set +a ; }
APP_DIR="${APP_DIR:-/var/www/drvtw}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*" ; }

say "update the checkout"
cd "$REPO"
case "$PULL" in
    git)
        [ -n "$REV" ] && git checkout -q "$REV" || { git fetch --all -q && git checkout -q origin/HEAD 2>/dev/null || git pull -q --ff-only ; }
        ;;
    patch)
        [ -f "$PATCH" ] || { echo "patch not found: $PATCH" >&2 ; exit 1 ; }
        git apply --check "$PATCH" || { echo "the patch does not apply cleanly; nothing changed" >&2 ; exit 1 ; }
        git apply "$PATCH"
        ;;
esac
echo "    $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo working tree) @ $(git rev-parse --short HEAD 2>/dev/null || echo uncommitted)"

if [ "$TESTS" = 1 ] && [ -x tests/run-all.sh ]; then
    say "tests"
    ./tests/run-all.sh 2>&1 | tail -5
fi

say "back up credentials"
CREDS="$APP_DIR/html/drvtw_private/drvtw_creds.php"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/drvtw}"
mkdir -p "$BACKUP_DIR"
[ -f "$CREDS" ] && cp -a "$CREDS" "$BACKUP_DIR/creds.php.$(date +%F-%H%M%S)" && echo "    saved to $BACKUP_DIR"

say "copy the code"
rsync -a --delete --exclude 'html/drvtw_private/drvtw_creds.php' "$REPO/backend/" "$APP_DIR/"
chown -R root:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0750 {} +

# the dashboard script is served from the static host directory, so it must be
# re-synced with the copy the tests compare against
if [ -d "$REPO/frontend/www.drv.tw" ]; then
    rsync -a --delete "$REPO/frontend/www.drv.tw/inc/" "$APP_DIR/html/${WWW_HOST:-www.$DOMAIN}/inc/" 2>/dev/null || true
    chown -R root:www-data "$APP_DIR/html/${WWW_HOST:-www.$DOMAIN}/inc" 2>/dev/null || true
fi

say "reload php-fpm (opcache holds index.php)"
PHPVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
[ -n "$PHPVER" ] && systemctl reload "php$PHPVER-fpm" && echo "    php$PHPVER-fpm reloaded"
systemctl is-active --quiet nginx && systemctl reload nginx && echo "    nginx reloaded"

say "permissions the app needs"
install -d -m 0770 -o www-data -g adm /var/log/drvtw
echo "    /var/log/drvtw 0770 www-data:adm"

say "check"
"$HERE/verify.sh" || {
    echo
    echo "Deployment is copied but something did not check out."
    echo "Roll back with:  sudo cp -a $BACKUP_DIR/creds.php.<time> $CREDS"
    echo "                 then re-run me with --rev <previous commit>"
    exit 1
}
