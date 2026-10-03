#!/usr/bin/env bash
#
# DriveToWeb installer - turns the answers in config.env into a running server.
#
#   sudo ./install.sh              # do everything
#   sudo ./install.sh --only nginx # re-run one step
#   ./install.sh --selftest        # check this script + its generated files, no root
#   ./install.sh --stage /tmp/x    # render every file it would write, touch nothing else
#   ./install.sh --dry-run         # print the plan
#
# What it does, in order:
#   preflight  packages  fpm  logdir  mongodb  code  creds  nginx  tls  static
#   firewall  backup  verify
#
# It is safe to run twice: files it manages are backed up before being replaced,
# generated secrets are kept in /etc/drvtw/secrets.env, and your credentials file
# is never overwritten unless you pass --force-creds.

set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
CONF="$HERE/config.env"
[ -f "$CONF" ] || CONF="$HERE/config.env.example"

MODE=apply
STAGE=""
FORCE_CREDS=0
ONLY=""
FROM=""
DRY=0
APP_DIR=/var/www/drvtw
MANAGED='# managed by drvtw install.sh - edits will be overwritten on the next run'

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run)   DRY=1 ; MODE=dry ;;
        --stage)     MODE=stage ; STAGE="$2" ; shift ;;
        --selftest)  MODE=selftest ;;
        --only)      ONLY="$2" ; shift ;;
        --from)      FROM="$2" ; shift ;;
        --force-creds) FORCE_CREDS=1 ;;
        -y|--yes)    ASSUME_YES=1 ;;
        -h|--help)   sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//' ; exit 0 ;;
        *) printf 'unknown option: %s\n' "$1" >&2 ; exit 2 ;;
    esac
    shift
done

PHASES="preflight packages fpm logdir mongodb code creds nginx tls static firewall backup verify"

# ---------------------------------------------------------------- utilities
say()  { printf '\033[1m==>\033[0m %s\n' "$*" ; }
info() { printf '    %s\n' "$*" ; }
warn() { printf '    \033[33mWARN\033[0m %s\n' "$*" >&2 ; }
die()  { printf '\n\033[31mSTOP\033[0m %s\n' "$*" >&2 ; exit 1 ; }
trap 'printf "\n\033[31minstall.sh failed at line %s\033[0m\n" "$LINENO" >&2' ERR

# run <cmd...> : execute unless we are only planning
run() {
    if [ "$DRY" = 1 ]; then printf '    [dry] %s\n' "$*" ; return 0 ; fi
    "$@"
}

# render <path> <content-file> : install a generated file, backing up a foreign one
render() {
    local dest="$1" src="$2" out
    if [ "$DRY" = 1 ] && [ -z "$STAGE" ]; then
        printf '    [dry] would write %s\n' "$dest"
        return 0
    fi
    out="$STAGE$dest"
    mkdir -p "$(dirname "$out")"
    if [ -e "$out" ] && ! grep -q 'managed by drvtw install.sh' "$out" 2>/dev/null; then
        local bak="$out.old.$(date +%Y%m%d%H%M%S)"
        info "keeping the file you had there: $bak"
        cp -a "$out" "$bak"
    fi
    install -m 0644 "$src" "$out"
    [ "$MODE" = apply ] && [ -n "$MANAGED_MARK" ] || true
}

secret() {   # stable secret: generated once, then reused
    local key="$1" len="${2:-32}" cur
    cur="$(grep -m1 "^${key}=" /etc/drvtw/secrets.env 2>/dev/null | cut -d= -f2- || true)"
    if [ -z "$cur" ]; then
        cur="$(openssl rand -hex "$len")"
        [ "$MODE" = apply ] && printf '%s=%s\n' "$key" "$cur" >> /etc/drvtw/secrets.env
    fi
    printf '%s' "$cur"
}

need() { command -v "$1" >/dev/null 2>&1 ; }
want() { need "$1" || die "$1 is required (phase: $2)" ; }

# ---------------------------------------------------------------- config
load_config() {
    # shellcheck disable=SC1090
    set -a; . "$CONF" ; set +a
    : "${DOMAIN:?DOMAIN is not set in $CONF}"
    : "${SERVER_IP:?SERVER_IP is not set in $CONF}"
    APP_DIR="${APP_DIR:-/var/www/drvtw}"
    BACKUP_DIR="${BACKUP_DIR:-/var/backups/drvtw}"
    DB_DIR="${DB_DIR:-/var/lib/mongodb}"
    DNS_PROVIDER="${DNS_PROVIDER:-manual}"
    INSTALL_MONGODB="${INSTALL_MONGODB:-yes}"
    REQUIRE_APPROVED="${REQUIRE_APPROVED:-auto}"
    SMTP_HOST="${SMTP_HOST:-}"
    MAIL_FROM="${MAIL_FROM:-no-reply@$DOMAIN}"
    [ -n "$SMTP_HOST" ] || { [ "$REQUIRE_APPROVED" = auto ] && REQUIRE_APPROVED=false ; }
    [ "$REQUIRE_APPROVED" = auto ] && REQUIRE_APPROVED=true
    API_HOST="api.$DOMAIN"
    WWW_HOST="www.$DOMAIN"
}

check_config() {
    local bad=0 k
    for k in DOMAIN SERVER_IP ONEDRIVE_CLIENT_ID ; do
        eval "local v=\${$k:-}"
        case "$v" in ''|CHANGEME*|example.org|203.0.113.*) bad=1 ; warn "$k is still a placeholder ('$v')" ;; esac
    done
    # none = the certificate is somebody else's problem (a Cloudflare tunnel, a load
    # balancer): there is no TXT record to write, so a token is not wanted either.
    if [ "$DNS_PROVIDER" != manual ] && [ "$DNS_PROVIDER" != none ] \
       && [ "${DNS_API_TOKEN:-CHANGEME}" = CHANGEME ]; then
        bad=1 ; warn "DNS_PROVIDER=$DNS_PROVIDER needs DNS_API_TOKEN (or use DNS_PROVIDER=manual, or none)"
    fi
    printf '%s' "$DOMAIN" | grep -Eq '^[a-z0-9][a-z0-9-]*\.[a-z]{2,}$' \
        || { bad=1 ; warn "DOMAIN '$DOMAIN' is not a plain domain" ; }
    if [ "$MODE" = apply ] && [ $bad = 1 ]; then
        die "fill in $CONF first (that file is the only thing you have to edit)"
    fi
    return 0
}

TMP="$(mktemp -d)"
# --dry-run must not touch the filesystem either: redirect every rendered file into
# a temp tree (render() writes "$STAGE$dest", so an empty STAGE would mean /).
[ "$MODE" = dry ] && STAGE="$TMP/dryroot"
[ "$MODE" = stage ] && STAGE="${STAGE:?--stage needs a directory}"
[ "$MODE" = selftest ] && STAGE="$TMP/stage"
[ "$MODE" = selftest ] || [ "$MODE" = stage ] || [ "$MODE" = dry ] || DRY=0
if [ "$MODE" = stage ] || [ "$MODE" = selftest ]; then DRY=1 ; fi
if [ "$MODE" = apply ] && [ "$(id -u)" != 0 ]; then
    die "run me as root:  sudo $0"
fi
[ "$MODE" = apply ] && { mkdir -p /etc/drvtw ; touch /etc/drvtw/secrets.env ; chmod 0600 /etc/drvtw/secrets.env ; }
MANAGED_MARK=1
cleanup() { rm -rf "$TMP" ; }
trap cleanup EXIT

detect_php() {
    if need php; then
        PHPVER="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    fi
    : "${PHPVER:=}"
    if [ -z "$PHPVER" ]; then
        for v in 8.3 8.2 8.1 8.4 8.0 ; do
            if [ "$MODE" = apply ] && ! apt-cache policy "php$v-fpm" 2>/dev/null | grep -q Candidate; then continue; fi
            PHPVER="$v" ; break
        done
    fi
    [ -n "$PHPVER" ] || die "no supported PHP 8.x found (phase: packages)"
    FPM_SOCK="/run/php/drvtw-$PHPVER.sock"
    POOL="/etc/php/$PHPVER/fpm/pool.d/drvtw.conf"
    export PHPVER FPM_SOCK POOL
}

# ---------------------------------------------------------------- phases
p_preflight() {
    say "preflight"
    [ -f "$REPO/backend/html/index.php" ] || die "$REPO does not look like the DriveToWeb repo"
    [ -f "$REPO/backend/nginx_drvtw" ]     || die "backend/nginx_drvtw missing"
    check_config
    info "domain   : $DOMAIN  (sites: <name>.on.$DOMAIN)"
    info "hosts    : $API_HOST  $WWW_HOST"
    info "install  : $APP_DIR   (php$PHPVER, pool $FPM_SOCK)"
    info "mode     : $MODE${STAGE:+  staging under $STAGE}"
    if [ "${INSTALL_MONGODB:-yes}" != yes ] && [ -z "${MONGO_URI:-}" ]; then
        die "INSTALL_MONGODB=no but MONGO_URI is empty - where should the app connect?"
    fi
    if [ "$MODE" = apply ]; then
        . /etc/os-release 2>/dev/null || true
        info "os       : ${PRETTY_NAME:-unknown}"
        [ "${ID:-}" = ubuntu ] || warn "tested on Ubuntu 22.04/24.04; ${ID:-?} is on you"
        if ! need curl; then warn "curl missing - installing it with the packages phase" ; fi
        # Ubuntu itself is ~2 GB and never shrinks, mongodb-org + php-fpm + nginx add a few
        # hundred MB, and the dumps/cache grow into whatever is left. Failing here beats an
        # apt that dies at 97% and a machine that will not boot the next day.
        local free_mb ; free_mb="$(free_space_mb "$APP_DIR" || echo 0)"
        info "disk     : $(( free_mb / 1024 )) GB free under $APP_DIR"
        [ "$free_mb" -ge 2048 ] || die "only $free_mb MB free on $APP_DIR - the OS, the database and room to grow need at least 2 GB; plan on 20 GB. A plan advertising a few hundred MB of disk is not a server for this app."
        [ "$free_mb" -ge 20480 ] || warn "only $(( free_mb / 1024 )) GB free - it installs, but keep an eye on the cache and the dumps"
        if [ "${DNS_PROVIDER:-manual}" = none ]; then
            # a tunnel box has no public IP to compare against and no records of its own:
            # cloudflared writes the CNAMEs, so probing either one here only creates noise.
            info "edge     : DNS_PROVIDER=none - a Cloudflare tunnel owns the DNS and the real certificate"
            info "dns      : not checked (and SERVER_IP is not used on this path)"
        else
            local ip
            ip="$(curl -s -m 8 https://api.ipify.org || true)"
            [ -n "$ip" ] && [ "$ip" != "$SERVER_IP" ] && warn "this box is $ip but SERVER_IP=$SERVER_IP"
            for h in "$API_HOST" "$WWW_HOST" "anything.on.$DOMAIN"; do
                local got; got="$(getent hosts "$h" | awk 'NR==1{print $1}' || true)"
                [ -n "$got" ] && info "dns      : $h -> $got" || warn "dns not answering for $h (ok if you have not pointed it yet)"
            done
        fi
    fi
    ask_go
}

ask_go() {
    [ "$MODE" != apply ] && return 0
    [ "${ASSUME_YES:-0}" = 1 ] && return 0
    printf '\nReady. Type yes to continue: ' ; read -r a
    [ "$a" = yes ] || die "aborted by you (nothing was changed)"
}

p_packages() {
    say "packages"
    run env DEBIAN_FRONTEND=noninteractive apt-get update -qq
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        nginx "php$PHPVER-fpm" "php$PHPVER-mongodb" "php$PHPVER-mbstring" "php$PHPVER-curl" \
        "php$PHPVER-dom" "php$PHPVER-zip" openssl curl ca-certificates git rsync cron \
        unzip
    [ "$MODE" = apply ] || return 0
    for e in mongodb mbstring curl dom; do
        php -m | grep -qi "^$e$" || die "php-$e is not loaded (apt install php$PHPVER-$e)"
    done
    info "php $PHPVER with mongodb, mbstring, curl, dom"
}

p_fpm() {
    say "php-fpm pool"
    cat > "$TMP/pool" <<EOF
$MANAGED
; A pool of its own, so this app never shares a socket with the distro www pool.
[drvtw]
user = www-data
group = www-data
listen = $FPM_SOCK
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = static
pm.max_children = ${FPM_CHILDREN:-64}
pm.max_requests = 500
catch_workers_output = yes
decorate_workers_output = no
clear_env = no
env[D2W_LOG_FOLDER] = /var/log/drvtw
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size] = 32M
php_admin_value[expose_php] = Off
; Notices must never reach the response: a deprecation printed inside a JSON body
; breaks the caller's JSON.parse, and the app is one str_repeat() away from that.
php_admin_value[display_errors] = Off
php_admin_value[log_errors] = On
php_admin_value[session.cookie_httponly] = 1
EOF
    render "$POOL" "$TMP/pool"
    run install -d -m 0755 -o www-data -g www-data /run/php
    run systemctl reload "php$PHPVER-fpm"
    info "pool $POOL -> $FPM_SOCK"
}

p_logdir() {
    say "log folder"
    run install -d -m 0770 -o www-data -g adm /var/log/drvtw
    info "/var/log/drvtw 0770 www-data:adm (0640 here would break every log write silently)"
}

p_mongodb() {
    say "mongodb"
    if [ "${INSTALL_MONGODB:-yes}" != yes ]; then
        info "skipped (INSTALL_MONGODB=no) - using $MONGO_URI"
        MONGO_PASS="" ; return 0
    fi
    . /etc/os-release 2>/dev/null || true
    local codename="${VERSION_CODENAME:-jammy}" ver="${MONGO_VER:-8.0}"
    case "$codename" in jammy|noble) : ;; *) warn "untested Ubuntu release '$codename'; trying the $ver repo anyway" ;; esac
    # arm64 (Ampere Altra / Graviton) boxes are sold as "free tier" all the time now, and
    # a hardcoded arch=amd64 line makes apt answer "Unable to locate package mongodb-org"
    # with no hint that the arch string was the problem.
    local arch ; arch="$(dpkg --print-architecture 2>/dev/null || echo amd64)"
    case "$arch" in
        amd64) : ;;
        arm64) [ "$codename" = noble ] || warn "MongoDB publishes arm64 Ubuntu builds for 24.04 - on '$codename' apt may find none; use the 24.04 image or INSTALL_MONGODB=no with MONGO_URI" ;;
        *) warn "CPU architecture '$arch' has no MongoDB server packages - the database has to come from outside (INSTALL_MONGODB=no + MONGO_URI)" ; arch="amd64" ;;
    esac
    info "installing mongodb-org $ver for $arch/$codename"
    run curl -fsSL "https://www.mongodb.org/static/pgp/server-$ver.asc" | run gpg --dearmor -o /usr/share/keyrings/mongodb.gpg
    run bash -c "echo 'deb [ arch=$arch signed-by=/usr/share/keyrings/mongodb.gpg ] https://repo.mongodb.org/apt/ubuntu $codename/mongodb-org/$ver multiverse' > /etc/apt/sources.list.d/mongodb-org-$ver.list"
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mongodb-org

    # TLS off + loopback only: a single box does not need requireTLS, and the
    # shipped mongod.conf pairs requireTLS with a self-signed cert, which the
    # app then refuses to connect to. Both ends must agree - so pick plain-local.
    cat > "$TMP/mongod.conf" <<EOF
$MANAGED
storage:
  dbPath: $DB_DIR
  journal:
    enabled: true
systemLog:
  destination: file
  logAppend: true
  path: /var/log/mongodb/mongod.log
net:
  port: 27017
  bindIp: 127.0.0.1
  unixDomainSocket:
    enabled: true
    pathPrefix: /tmp
processManagement:
  timeZoneInfo: /usr/share/zoneinfo
security:
  authorization: enabled
EOF
    render /etc/mongod.conf "$TMP/mongod.conf"
    run install -d -m 0750 -o mongodb -g mongodb "$DB_DIR"
    run systemctl enable --now mongod
    run systemctl restart mongod

    MONGO_PASS="$(secret MONGO_PASS 24)"
    export MONGO_PASS
    [ "$MODE" = apply ] || { info "(staged: the app user is created in the verify run)"; return 0 ; }
    # localhost exception lets us create the first user without authenticating
    mongosh --quiet --eval "
      db.getSiblingDB('admin').getUser('drvtw') || db.getSiblingDB('admin').createUser({
        user: 'drvtw', pwd: '$MONGO_PASS', roles: [{role:'readWrite', db:'wwwdrv'}] }) ;
      var d = db.getSiblingDB('wwwdrv');
      d.cache_gd.createIndex({userId:1,version:1,urlHash:1})
      d.accessTokens.createIndex({userId:1,service:1})
      d.authfail.createIndex({ts:1},{expireAfterSeconds:3600})
      print('indexes: ' + d.cache_gd.getIndexes().length + '/' + d.accessTokens.getIndexes().length
            + '/' + d.authfail.getIndexes().length) ;
    " | sed 's/^/    /'
    info "db user drvtw on wwwdrv, 3 indexes (same ones as setup/mongod/init.js)"
}

p_code() {
    say "code"
    run install -d -m 0755 "$APP_DIR"
    if [ -f "$APP_DIR/html/drvtw_private/drvtw_creds.php" ] && [ "$MODE" = apply ]; then
        run cp -a "$APP_DIR/html/drvtw_private/drvtw_creds.php" "$BACKUP_DIR/creds.php.$(date +%F-%H%M)"
        info "credentials backed up to $BACKUP_DIR"
    fi
    run rsync -a --delete --exclude 'html/drvtw_private/drvtw_creds.php' "$REPO/backend/" "$APP_DIR/"
    run chown -R "root:www-data" "$APP_DIR"
    run find "$APP_DIR" -type d -exec chmod 0750 {} +
    [ "$MODE" = apply ] && [ -d "$REPO/.git" ] && ( cd "$REPO" && git rev-parse --short HEAD ) | sed 's/^/    commit /'
    info "$APP_DIR/html = docroot (index.php, inline/, drvtw_private/)"
}

p_creds() {
    say "credentials"
    local dest="$APP_DIR/html/drvtw_private/drvtw_creds.php"
    if [ -e "$STAGE$dest" ] && [ "$FORCE_CREDS" != 1 ] && [ "$MODE" = apply ]; then
        info "kept the existing $dest (pass --force-creds to rewrite it)"
        return 0
    fi
    # the mongodb phase may or may not have run (--only creds), so fall back to
    # the stored secret rather than an unbound variable
    : "${MONGO_PASS:=${MONGO_URI:-}$( [ -z "${MONGO_URI:-}" ] && secret MONGO_PASS 24 )}"
    [ -n "${MONGO_PASS+x}" ] || MONGO_PASS=""
    SESSION_SECRET="$(secret SESSION_SECRET)"
    OPS_SECRET="$(secret OPS_SECRET)"
    OPS_PASS="$(secret OPS_PASS 16)"
    OPS_USER="${OPS_USER:-drvtw-admin}"
    local mongo_uri="${MONGO_URI:-mongodb://drvtw:${MONGO_PASS}@127.0.0.1:27017/wwwdrv}"

    cat > "$TMP/creds.php" <<'EOF'
<?php
// managed by drvtw install.sh - generated from install/config.env
//
// Values you can change by hand any time: every request re-reads this file, so
// no service restart is needed. The installer will not overwrite it unless you
// delete it or pass --force-creds.
//
// Never commit this file. It is git-ignored and nginx denies the directory.

// ── hosts
$d2wBaseDomain = '__DOMAIN__' ;
$d2wApiHost    = '__API_HOST__' ;
$d2wWebHost    = '__WWW_HOST__' ;
$d2wSiteSuffix = '.on.__DOMAIN__' ;
$d2wContactEmail = '__MAIL_FROM__' ;
// no file cache proxy / CDN unless you added the second nginx vhost
$d2wCdnHosts   = array() ;
$d2wWidgetScript = 'https://__WWW_HOST__/inc/wd.js' ;

// ── secrets (generated; stored again in /etc/drvtw/secrets.env)
$sessionSecret = '__SESSION_SECRET__' ;   // owner sessions + the dashboard's CSRF token
$opsSecret    = '__OPS_SECRET__' ;        // operator tool tokens (backroom, log export)
$opsUser      = '__OPS_USER__' ;
$opsPass      = '__OPS_PASS__' ;
$siteNameSecret = NULL ;                  // NULL = random site names per owner

// ── sign-in: OneDrive is the one that works for a new app
$odClientId     = '__OD_ID__' ;
$odClientSecret = '__OD_SECRET__' ;
$gdClientId     = '__GD_ID__' ;
$gdClientSecret = '__GD_SECRET__' ;
$gdApiKey       = '__GD_KEY__' ;

// ── database
$mongoLocalConnection  = '__MONGO__' ;
$mongoRemoteConnections = array() ;
$mongoTlsLocal         = FALSE ;   // must match mongod.conf: this install runs plain loopback
$mongoTlsCAFile         = NULL ;
$mongoTlsAllowSelfSigned = FALSE ;

// ── mail (only used for private-site access codes)
$smtpHost = '__SMTP_HOST__' ;
$smtpPort = '__SMTP_PORT__' ;
// the keys this code actually reads are the sesSmtp* pair ($smtpUser does not exist)
$sesSmtpUser = '__SMTP_USER__' ;
$sesSmtpPassword = '__SMTP_PASS__' ;
$smtpPassword = '__SMTP_PASS__' ;
$mailFrom = '__MAIL_FROM__' ;
$mailFromName = 'DriveToWeb' ;

// ── behaviour
$requireApprovedSites = __REQUIRE_APPROVED__ ;   // TRUE = owners must verify by e-mail
$sessionTtlSec   = 1209600 ;   // 14 days
$siteNameChangeGapSec = 3600 ; // how often an owner may move their site's address
$d2wReservedSiteNames = array( __RESERVED__ ) ;
$d2wLogPrivacy   = TRUE ;      // hide visitor e-mails in the log
// $d2wAppCSP / $d2wContentCSP stay default: the app pages need their per-request
// script nonce, which the shipped policy already carries.
EOF
    sed -e "s|__DOMAIN__|$DOMAIN|g" \
        -e "s|__API_HOST__|$API_HOST|g" \
        -e "s|__WWW_HOST__|$WWW_HOST|g" \
        -e "s|__MAIL_FROM__|$MAIL_FROM|g" \
        -e "s|__SESSION_SECRET__|$SESSION_SECRET|g" \
        -e "s|__OPS_SECRET__|$OPS_SECRET|g" \
        -e "s|__OPS_USER__|$OPS_USER|g" \
        -e "s|__OPS_PASS__|$OPS_PASS|g" \
        -e "s|__OD_ID__|${ONEDRIVE_CLIENT_ID:-}|g" \
        -e "s|__OD_SECRET__|${ONEDRIVE_CLIENT_SECRET:-}|g" \
        -e "s|__GD_ID__|${GOOGLE_CLIENT_ID:-}|g" \
        -e "s|__GD_SECRET__|${GOOGLE_CLIENT_SECRET:-}|g" \
        -e "s|__GD_KEY__|${GOOGLE_API_KEY:-}|g" \
        -e "s|__MONGO__|$mongo_uri|g" \
        -e "s|__SMTP_HOST__|${SMTP_HOST:-}|g" \
        -e "s|__SMTP_PORT__|${SMTP_PORT:-587}|g" \
        -e "s|__SMTP_USER__|${SMTP_USER:-}|g" \
        -e "s|__SMTP_PASS__|${SMTP_PASSWORD:-}|g" \
        -e "s|__REQUIRE_APPROVED__|$([ "$REQUIRE_APPROVED" = true ] && echo TRUE || echo FALSE )|g" \
        -e "s|__RESERVED__|$(printf '%s' "${RESERVED_NAMES:-}" | tr ',' '\n' | sed 's/^ *//; s/ *$//; /^$/d' | sed "s/.*/'&'/" | paste -sd, - || true)|g" \
        "$TMP/creds.php" > "$TMP/creds.final.php"
    want php creds 2>/dev/null || true
    if need php; then php -l "$TMP/creds.final.php" > /dev/null || die "generated creds.php does not parse" ; fi
    local out="$STAGE$dest"
    mkdir -p "$(dirname "$out")"
    install -m 0640 -o root -g www-data "$TMP/creds.final.php" "$out" 2>/dev/null || install -m 0640 "$TMP/creds.final.php" "$out"
    info "$dest (0640 root:www-data)"
    info "operator login for /@@backroom: $OPS_USER / $OPS_PASS"
    [ "$MODE" = apply ] || info "(staged copy under $STAGE)"
}

# Free megabytes under a path. DF_CMD exists so --selftest can feed this a fake `df`:
# the guard that uses it only fires in apply mode, and a stage run must not touch the
# real filesystem - without the override that arithmetic would never be executed.
free_space_mb() {
    local cmd="${DF_CMD:-df}" kb
    command -v "$cmd" >/dev/null 2>&1 || { printf '0' ; return 1 ; }
    kb="$("$cmd" -Pk "$1" 2>/dev/null | awk 'NR==2{print $4}')"
    printf '%s' "$(( ${kb:-0} / 1024 ))"
}

# Does this box have a routable IPv6 address? Kept in one place, and `IP_CMD` lets
# --selftest feed it a fake `ip` so both branches are actually exercised.
edge_v6_probe() {
    local cmd="${IP_CMD:-ip}"
    command -v "$cmd" >/dev/null 2>&1 || return 1
    "$cmd" -6 addr show scope global 2>/dev/null | grep -q "inet6 "
}

# Sets V680/V6443 to the extra listen lines when v6 exists (empty otherwise). nginx
# fails to *start* if told to bind [::] on an IPv4-only host, and a failed start here
# takes the whole site down rather than degrading it - so we only add what the box
# can bind. Indian mobile networks are largely IPv6-capable, which is the other half
# of the reason: v4-only listening quietly drops a slice of your visitors.
edge_v6_lines() {
    V680="" ; V6443=""
    edge_v6_probe || return 1
    V680="
    listen [::]:80 default_server;"
    V6443="
    listen [::]:443 ssl;"
    return 0
}

p_nginx() {
    say "nginx"
    V680="" ; V6443=""        # under set -u the heredoc must expand even when no v6 exists
    # edge: TLS here, proxy to the app on loopback; app: the shipped config with
    # our socket, loopback-only listen
    if [ "$MODE" = apply ] && edge_v6_lines ; then
        info "global IPv6 present -> edge listens on [::] as well; add AAAA records for the same names"
    fi
    cat > "$TMP/edge" <<EOF
$MANAGED
server {
    listen 80 default_server;$V680
    server_name _;
    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://\$host\$request_uri; }
}

server {
    listen 443 ssl;$V6443
    http2 on;
    server_name $DOMAIN www.$DOMAIN $API_HOST *.$DOMAIN *.on.$DOMAIN;

    ssl_certificate     /etc/ssl/drvtw/fullchain.cer;
    ssl_certificate_key /etc/ssl/drvtw/privkey.pem;
    ssl_session_cache shared:SSL:20m;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    client_max_body_size 32m;

    # the site content is served from the app server; keep the Host header, that
    # is how the app finds out which site was asked for
    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Connection "";
        proxy_read_timeout 120s;
    }
}
EOF
    sed -e "s|listen 8000 default_server;|listen 127.0.0.1:8000 default_server;|" \
        -e "s|root /var/www/drv_tw/html;|root $APP_DIR/html;|" \
        -e "s|fastcgi_pass unix:/run/php/php8.1-fpm.sock;|fastcgi_pass unix:$FPM_SOCK;|" \
        "$REPO/backend/nginx_drvtw" > "$TMP/app"
    printf '\n%s\n' "$MANAGED" >> "$TMP/app"

    render /etc/nginx/sites-available/drvtw-edge "$TMP/edge"
    render /etc/nginx/sites-available/drvtw-app "$TMP/app"
    run install -d -m 0755 /var/www/letsencrypt
    if [ "$MODE" = apply ]; then
        run ln -sf /etc/nginx/sites-available/drvtw-edge /etc/nginx/sites-enabled/drvtw-edge
        run ln -sf /etc/nginx/sites-available/drvtw-app /etc/nginx/sites-enabled/drvtw-app
        run rm -f /etc/nginx/sites-enabled/default
        nginx -t
        run systemctl reload nginx
        info "nginx tested and reloaded"
    else
        info "(staged: symlinks and nginx -t are skipped)"
    fi
}

p_tls() {
    say "certificate"
    if [ "${DNS_PROVIDER:-manual}" = none ]; then
        info "DNS_PROVIDER=none - a self-signed certificate (the browser will warn)"
        run install -d -m 0755 /etc/ssl/drvtw
        run openssl req -x509 -newkey rsa:2048 -nodes -days 365 \
            -keyout /etc/ssl/drvtw/privkey.pem -out /etc/ssl/drvtw/fullchain.cer \
            -subj "/CN=$DOMAIN" \
            -addext "subjectAltName=DNS:$DOMAIN,DNS:*.$DOMAIN,DNS:*.on.$DOMAIN,DNS:$API_HOST,DNS:$WWW_HOST"
        return 0
    fi
    info "acme.sh will answer the DNS-01 challenge - a wildcard cannot be issued any other way"
    run env DEBIAN_FRONTEND=noninteractive bash -c "curl https://get.acme.sh | sh -s email=admin@$DOMAIN"
    run install -d -m 0700 /etc/ssl/drvtw

    # friendly name -> the dnsapi plugin acme.sh actually ships
    local provider="" envline=""
    case "${DNS_PROVIDER}" in
        cloudflare) provider=dns_cf ; envline="CF_Token=${DNS_API_TOKEN:-}" ;;
        aws|route53) provider=dns_aws
            envline="AWS_Access_Key_ID=${DNS_API_KEY:-} AWS_Secret_Access_Key=${DNS_API_SECRET:-}" ;;
        gcp|google) provider=dns_gc ; envline="GC_JSON=${DNS_API_TOKEN:-}" ;;
        hetzner) provider=dns_hetzner ; envline="HETZNER_Token=${DNS_API_TOKEN:-}" ;;
        dnsmadeeasy) provider=dns_dnsmadeeasy
            envline="DMApiKey=${DNS_API_KEY:-} DMSecretKey=${DNS_API_SECRET:-}" ;;
        manual) provider="" ;;
        *) die "DNS_PROVIDER '$DNS_PROVIDER' is unknown - use cloudflare, aws, gcp, hetzner, dnsmadeeasy or manual" ;;
    esac
    # an empty token makes acme.sh fail in a way that looks like a DNS problem, so
    # name the missing value instead - and offer the way out
    if [ -n "$provider" ] && [ "$MODE" = apply ]; then
        case "$provider" in
            dns_aws|dns_dnsmadeeasy)
                if [ -z "${DNS_API_KEY:-}" ] || [ "${DNS_API_KEY:-}" = CHANGEME ] \
                    || [ -z "${DNS_API_SECRET:-}" ] || [ "${DNS_API_SECRET:-}" = CHANGEME ]; then
                    die "$DNS_PROVIDER needs DNS_API_KEY and DNS_API_SECRET in $CONF (or set DNS_PROVIDER=manual)"
                fi ;;
            *)
                if [ -z "${DNS_API_TOKEN:-}" ] || [ "${DNS_API_TOKEN:-}" = CHANGEME ]; then
                    die "$DNS_PROVIDER needs DNS_API_TOKEN in $CONF (or set DNS_PROVIDER=manual)"
                fi ;;
        esac
    fi

    local names="-d '$DOMAIN' -d '*.$DOMAIN' -d '*.on.$DOMAIN'"
    if [ -z "$provider" ]; then
        run bash -c "$envline ~/.acme.sh/acme.sh --server letsencrypt --issue $names --dns \
            --yes-I-know-dns-manual-mode-enough-go-ahead-please --log"
        if [ "$MODE" = apply ]; then
            printf '\n  Add the _acme-challenge TXT records acme.sh printed above, then press Enter.\n'
            printf '  (check with:  dig -t txt _acme-challenge.%s)\n  ' "$DOMAIN"
            read -r _
        fi
        run bash -c "~/.acme.sh/acme.sh --renew -d '$DOMAIN' --force"
    else
        run bash -c "$envline ~/.acme.sh/acme.sh --server letsencrypt --issue $names --dns $provider --log"
    fi
    run bash -c "~/.acme.sh/acme.sh --install-cert -d '$DOMAIN' --ecc \
        --fullchain-file /etc/ssl/drvtw/fullchain.cer --key-file /etc/ssl/drvtw/privkey.pem \
        --reloadcmd 'systemctl reload nginx'"
    run chmod 0600 /etc/ssl/drvtw/privkey.pem
    info "renewal is installed as a cron entry: grep acme /etc/crontab"
}

p_static() {
    say "static front-end"
    local src="$REPO/frontend/www.drv.tw" out="$STAGE$APP_DIR/html/$WWW_HOST"
    mkdir -p "$out"
    if [ "$MODE" = apply ]; then
        run rsync -a --delete "$src/" "$APP_DIR/html/$WWW_HOST/"
        run chown -R "root:www-data" "$APP_DIR/html/$WWW_HOST"
    else
        # staging writes for real, into $STAGE only: that is what lets --selftest
        # lint the rendered config.js instead of trusting the sed patterns
        cp -a "$src/." "$out/"
    fi
    local cfg="$APP_DIR/html/$WWW_HOST/inc/config.js"
    [ "$MODE" = apply ] || cfg="$out/inc/config.js"
    sed -i \
        -e "s|microsoftClientId: '.*'|microsoftClientId: '${ONEDRIVE_CLIENT_ID:-null}'|" \
        -e "s|googleClientId: '.*'|googleClientId: '${GOOGLE_CLIENT_ID:-null}'|" \
        -e "s|siteSuffix: '.*'|siteSuffix: '.on.$DOMAIN'|" \
        -e "s|contactEmail: '.*'|contactEmail: '$MAIL_FROM'|" \
        -e "s|apiHost: null|apiHost: '$API_HOST'|" \
        "$cfg"
    if need node; then node --check "$cfg" || die "rendered config.js does not parse" ; fi
    info "$cfg  (siteSuffix '.on.$DOMAIN' - every site link is built from it)"
}

p_firewall() {
    say "firewall"
    run ufw default deny incoming
    run ufw allow OpenSSH
    run ufw allow 80/tcp
    run ufw allow 443/tcp
    run ufw --force enable
    info "80/443 open; 8000 and 27017 must stay loopback-only"
    [ "$MODE" = apply ] && ss -ltnp | grep -E ':(8000|27017)' | sed 's/^/    /'
    return 0
}

p_backup() {
    say "rotation + backups"
    cat > "$TMP/logrotate" <<EOF
$MANAGED
/var/log/drvtw/*.log {
    daily
    missingok
    rotate 52
    compress
    delaycompress
    notifempty
    create 0640 www-data adm
}
EOF
    render /etc/logrotate.d/drvtw "$TMP/logrotate"
    cat > "$TMP/cron" <<EOF
$MANAGED
2 0 * * * root mongodump --uri "mongodb://drvtw:__MONGO_PASS__@127.0.0.1:27017/wwwdrv" \\
    --excludeCollection=cache_gd --excludeCollection=accessTokens \\
    --archive="$BACKUP_DIR/wwwdrv-\$(date +\%F).gz" --gzip >/dev/null 2>&1
20 3 * * 0 root find $BACKUP_DIR -name 'wwwdrv-*.gz' -mtime +21 -delete
EOF
    sed -i "s|__MONGO_PASS__|${MONGO_PASS:-}|;s|__MONGO_PASS__|replaced-at-install|" "$TMP/cron"
    render /etc/cron.d/drvtw-backup "$TMP/cron"
    run install -d -m 0700 -o root -g root "$BACKUP_DIR"
    [ "$MODE" = apply ] && [ -n "${MONGO_PASS:-}" ] && \
        sed -i "s|replaced-at-install|$MONGO_PASS|" /etc/cron.d/drvtw-backup && \
        chmod 0600 /etc/cron.d/drvtw-backup
    info "nightly dump of wwwdrv minus the rebuildable collections, 21 days kept"
}

p_verify() {
    say "verification"
    [ "$MODE" = apply ] || { info "nothing to poke in $MODE mode" ; return 0 ; }
    local fail=0 code
    if [ -f "$REPO/tools/d2w-audit.php" ]; then
        php "$REPO/tools/d2w-audit.php" --root "$APP_DIR" && info "audit: exit 0" || { warn "audit reported errors (see above)" ; fail=1 ; }
    fi
    for u in "http://$API_HOST/" "https://$API_HOST/" "https://$API_HOST/drvtw_private/drvtw_creds.php" "https://nothing.on.$DOMAIN/"; do
        code="$(curl -s -o /dev/null -m 15 -w '%{http_code}' -I "$u" || echo 000)"
        case "$u" in
            *drvtw_private*)  [ "$code" = 404 ] || { warn "creds not denied ($code)" ; fail=1 ; } ;;
            *://$API_HOST/*)  [ "$code" = 301 ] || [ "$code" = 302 ] || [ "$code" = 200 ] || { warn "$u -> $code" ; fail=1 ; } ;;
            *)                [ "$code" = 404 ] || [ "$code" = 301 ] || [ "$code" = 302 ] || warn "$u -> $code" ;;
        esac
        info "$code  $u"
    done
    curl -s "https://$API_HOST/do/siteName?name=test-site" | sed 's/^/    /'
    [ -f /var/log/drvtw/user.log ] && tail -3 /var/log/drvtw/user.log | sed 's/^/    log: /'
    [ $fail = 0 ] && say "looks live" || warn "see DEPLOY-RUNBOOK.md section 12 for the full list"
    printf '\nOpen  https://%s  and connect a OneDrive account.\n' "$WWW_HOST"
    printf 'Then the dashboard shows the "Your website" card: that is your URL,\nand Change address is how you claim the one you want.\n'
    printf 'Logs:  sudo tail -f /var/log/drvtw/user.log\n'
}

# ---------------------------------------------------------------- run
load_config
detect_php

if [ "$MODE" = selftest ]; then
    say "selftest (render every file this script writes, then lint them)"
    bad=0
    for f in "$0" "$HERE/deploy.sh" "$HERE/verify.sh" "$HERE/wizard.sh"; do
        [ -f "$f" ] && bash -n "$f" && info "bash -n  ok  $(basename "$f")" || { warn "syntax: $f" ; bad=1 ; }
    done
    MODE=stage ; DRY=1 ; STAGE="$STAGE"
    load_config
    check_config || bad=1
    for p in fpm code creds nginx static backup logdir; do
        "p_$p" > "$TMP/out.$p" 2>&1 || { warn "phase $p failed:" ; sed 's/^/      /' "$TMP/out.$p" ; bad=1 ; continue ; }
        info "rendered $p"
    done
    if need php; then
        find "$STAGE" -name 'drvtw_creds.php' -print0 | xargs -r0 -n1 php -l >/dev/null \
            && info "php -l    ok  generated credentials" || { warn "generated creds.php does not parse" ; bad=1 ; }
    fi
    grep -q "server_name $DOMAIN" "$STAGE/etc/nginx/sites-available/drvtw-edge" \
        && info "nginx edge carries your server_name" || { warn "edge vhost missing server_name" ; bad=1 ; }
    grep -q "php_admin_value\[display_errors\] = Off" "$STAGE/etc/php/$PHPVER/fpm/pool.d/drvtw.conf" 2>/dev/null \
        && info "pool keeps display_errors off (notices would corrupt JSON)" \
        || { warn "php-fpm pool does not pin display_errors=Off" ; bad=1 ; }
    grep -q "listen 127.0.0.1:8000" "$STAGE/etc/nginx/sites-available/drvtw-app" \
        && info "app vhost is loopback-only" || { warn "app vhost still listening on all interfaces" ; bad=1 ; }
    printf '#!/bin/sh\necho "    inet6 2001:db8::42/64 scope global dynamic"$("\\n")\n' > "$TMP/ip6"
    printf '#!/bin/sh\nexit 0\n' > "$TMP/ip4"
    chmod +x "$TMP/ip6" "$TMP/ip4"
    if IP_CMD="$TMP/ip6" edge_v6_lines && printf '%s' "$V680" | grep -q "listen \[::\]:80" \
       && printf '%s' "$V6443" | grep -q "listen \[::\]:443" ; then
        info "edge gains IPv6 listens when the box has a v6 address"
    else
        warn "v6 probe produced no listen lines for a v6-capable box" ; bad=1
    fi
    V680="" ; V6443=""
    printf '#!/bin/sh\nprintf "Filesystem 1024-blocks Used Available Capacity Mounted on\\n"\nprintf "/dev/fake 1000000 900000 50000 95%% /\\n"\n' > "$TMP/df_small"
    printf '#!/bin/sh\nexit 1\n' > "$TMP/df_none"
    chmod +x "$TMP/df_small" "$TMP/df_none"
    if [ "$(DF_CMD="$TMP/df_small" free_space_mb /)" = 48 ] ; then
        info "disk guard reads 'Available' from df (50000 KB -> 48 MB)"
    else
        warn "df parsing is wrong, so the 2 GB preflight guard is meaningless" ; bad=1
    fi
    [ "$(DF_CMD="$TMP/df_none" free_space_mb / 2>/dev/null)" = 0 ] \
        && info "a df that fails reports 0 free, which the guard treats as no-go" \
        || { warn "unusable df must report 0" ; bad=1 ; }

    if IP_CMD="$TMP/ip4" edge_v6_lines ; then
        warn "v6 probe answered yes on an IPv4-only box (nginx would fail to bind)" ; bad=1
    else
        [ -z "$V680$V6443" ] && info "IPv4-only box keeps the v4-only edge" \
            || { warn "v6 lines left set after a negative probe" ; bad=1 ; }
    fi
    if [ $bad = 0 ]; then say "SELFTEST PASSED" ; else say "SELFTEST FAILED" ; exit 1 ; fi
    exit 0
fi

run_phase() {
    local p="$1"
    "p_$p"
}

started=0
for p in $PHASES; do
    [ -n "$FROM" ] && [ "$p" != "$FROM" ] && [ $started = 0 ] && continue
    started=1
    [ -n "$ONLY" ] && [ "$p" != "$ONLY" ] && continue
    run_phase "$p"
done
say "done"
