#!/usr/bin/env bash
#
# Turn a laptop into the server for this app. Read-only unless you say otherwise.
#
#   ./laptop-serve.sh check                 is this machine able, and what is missing
#   ./laptop-serve.sh wake                  stop it sleeping mid-request (Linux only)
#   ./laptop-serve.sh host <siteName>       add https://<site>.on.<domain> to the tunnel
#   ./laptop-serve.sh host <site> --dry-run  print the two changes instead of making them
#
# The tunnel is what makes a home laptop usable at all: home ISPs in India put you behind
# CGNAT with 80/443 blocked, so no DNS record can point at your machine. A Cloudflare
# tunnel connects OUT to Cloudflare and Cloudflare sends visitors back through it, so
# nothing has to be opened, and Cloudflare's own certificate covers HTTPS - no acme.sh,
# no DNS API token, no wildcard certificate (which is Enterprise-only anyway).
#
# The cost of that trade: wildcards are not free, so each site needs one hostname entry.
# That is what `host` does.
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
CONF="$HERE/config.env"
[ -f "$CONF" ] || CONF=/etc/drvtw/config.env
[ -f "$CONF" ] && { set -a ; . "$CONF" ; set +a ; }
DOMAIN="${DOMAIN:-}"

CMD="${1:-check}"
case "$CMD" in check|wake|host|status) : ;; -h|--help|help) sed -n '2,18p' "$0" ; exit 0 ;; *) printf 'unknown command: %s\n' "$CMD" >&2 ; exit 2 ;; esac
SITE="${2:-}"
DRY=0
for a in "$@" ; do [ "$a" = --dry-run ] && DRY=1 ; done
if [ "$CMD" = host ]; then
    [ -n "$SITE" ] || { printf 'usage: %s host <siteName> [--dry-run]\n' "$0" >&2 ; exit 2 ; }
    # a bare word is one of your own sites; a dotted value is a tenant's own domain and is
    # routed verbatim (they still point it at <uuid>.cfargotunnel.com)
    if printf '%s' "$SITE" | grep -q . ; then : ; fi
    if printf '%s' "$SITE" | grep -Eq '[.]' ; then
        printf '%s' "$SITE" | grep -Eq '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$' \
            || { printf "'%s' is not a hostname I can route\n" "$SITE" >&2 ; exit 2 ; }
    else
        printf '%s' "$SITE" | grep -Eq '^[a-z0-9][a-z0-9-]{1,62}$' \
            || { printf "'%s' is not a claimable site name (a-z, 0-9, hyphen, 3-63)\n" "$SITE" >&2 ; exit 2 ; }
    fi
fi

W="printf '  %-3s %-38s %s\n'"
say() { printf '\n\033[1m%s\033[0m\n' "$*" ; }
ok()   { printf '  \033[32mok  \033[0m %-38s %s\n' "$1" "${2:-}" ; }
warn() { printf '  \033[33mnote\033[0m %-38s %s\n' "$1" "${2:-}" ; }
no()   { printf '  \033[31mFAIL\033[0m %-38s %s\n' "$1" "${2:-}" ; BAD=$((BAD+1)) ; }
BAD=0

# `cloudflared service install` keeps the config in /etc/cloudflared; a hand-run tunnel keeps
# it in ~/.cloudflared. Find whichever this machine uses.
cfg_path() {
    if [ -f /etc/cloudflared/config.yml ]; then printf '/etc/cloudflared/config.yml'
    elif [ -f "$HOME/.cloudflared/config.yml" ]; then printf "$HOME/.cloudflared/config.yml"
    else printf "$HOME/.cloudflared/config.yml" ; fi
}

doit() { if [ "$DRY" = 1 ]; then printf '   would run: %s\n' "$*" ; else "$@" ; fi ; }

# ── check ────────────────────────────────────────────────────────────────────
if [ "$CMD" = check ] || [ "$CMD" = status ]; then
    say "hardware"
    if grep -qw avx2 /proc/cpuinfo 2>/dev/null ; then
        ok "cpu (AVX2)" "current MongoDB builds will run"
    elif grep -qw avx /proc/cpuinfo 2>/dev/null ; then
        warn "cpu (AVX, no AVX2)" "Sandy/Ivy Bridge: MongoDB 7.0+/8.0 builds can die with 'Illegal instruction' - see the note at the end"
    else
        no "cpu (no AVX)" "no current MongoDB build will start here; the database has to live outside this machine"
    fi
    if [ -r /proc/cpuinfo ]; then
        model="$(grep -m1 'model name' /proc/cpuinfo | cut -d: -f2- | sed 's/^ //')"
        n="$(grep -c ^processor /proc/cpuinfo)"
        ok "cores" "$n - $model"
    fi
    mem="$(awk '/MemTotal/{printf "%.1f", $2/1048576}' /proc/meminfo 2>/dev/null)"
    if [ "${mem:-0}" = 0 ] || awk "BEGIN{exit !($mem < 2)}"; then no "memory" "${mem} GB - too little"
    elif awk "BEGIN{exit !($mem < 4)}"; then ok "memory" "${mem} GB (fine; keep the browser closed on this box)"
    else ok "memory" "${mem} GB" ; fi
    free_mb="$(df -Pk / 2>/dev/null | awk 'NR==2{print int($4/1024)}')"
    if [ "${free_mb:-0}" -ge 20480 ]; then ok "free disk" "$(( free_mb / 1024 )) GB"
    elif [ "${free_mb:-0}" -ge 4096 ]; then warn "free disk" "${free_mb} MB - enough to start, watch the cache"
    else no "free disk" "${free_mb} MB" ; fi
    if grep -qiE 'microsoft|wsl' /proc/version 2>/dev/null ; then
        warn "environment" "WSL2 - Windows owns the power settings, see the note below"
    else
        ok "environment" "bare Linux / VM"
    fi

    say "the app's own checks"
    if [ -x "$HERE/check-host.sh" ] || [ -f "$HERE/check-host.sh" ]; then
        bash "$HERE/check-host.sh" 2>/dev/null | grep -E '^  (FAIL|warn)' | head -8
        [ "${PIPESTATUS[0]}" = 0 ] && ok "check-host.sh" "no blocking problems" || warn "check-host.sh" "see the FAIL lines above"
    else
        warn "check-host.sh" "not next to me - run it once you have cloned the repo"
    fi

    say "web server"
    for p in 80 443; do
        code="$(curl -s -k -o /dev/null -m 4 -w '%{http_code}' "https://127.0.0.1:$p/" 2>/dev/null || true)"
        [ -n "$code" ] || code="$(curl -s -o /dev/null -m 4 -w '%{http_code}' "http://127.0.0.1:$p/" 2>/dev/null || true)"
        case "$code" in 000|'') warn "port $p" "nothing answering (sudo ./install.sh --only nginx)" ;;
                        *) ok "port $p" "HTTP $code" ;; esac
    done

    say "the tunnel"
    if command -v cloudflared >/dev/null 2>&1 || [ -x "$HOME/.cache/drvtw/cloudflared" ]; then
        bin="$(command -v cloudflared || echo "$HOME/.cache/drvtw/cloudflared")"
        ok "cloudflared" "$("$bin" --version 2>/dev/null | awk '{print $2 " " $3}')"
    else
        warn "cloudflared" "not installed - ./preview-tunnel.sh fetches it"
    fi
    CFG="$(cfg_path)"
    if [ -f "$CFG" ]; then
        n="$(grep -c 'hostname:' "$CFG")"
        ok "tunnel config" "$CFG - $n hostname entry/entries"
        if [ -n "$DOMAIN" ]; then
            for h in "$DOMAIN" "www.$DOMAIN" "api.$DOMAIN"; do
                grep -q "hostname: $h\$" "$CFG" && ok "ingress $h" "" || warn "ingress $h" "add it: ./laptop-serve.sh host on lists sites"
            done
        fi
    else
        warn "tunnel config" "no /etc/cloudflared/config.yml and none under $HOME/.cloudflared - create the tunnel first (LAPTOP-SERVER.md step 5)"
    fi
    if [ -n "$DOMAIN" ]; then ok "domain" "$DOMAIN (from config.env)" ; else warn "domain" "DOMAIN is not set - install/config.env" ; fi

    if [ "$BAD" = 0 ]; then
        printf '\n  nothing is blocking - the steps in LAPTOP-SERVER.md start at step 1\n\n'
        exit 0
    fi
    say "how to get past the red lines"
    cat <<'EOF'
  Old CPU (no AVX2): the official MongoDB 7/8 packages were built for Haswell and later.
  The easy fix is to keep only PHP + nginx on the laptop and put the database somewhere
  free that is not your CPU's problem: MongoDB Atlas M0. In install/config.env set
  INSTALL_MONGODB=no and paste the Atlas SRV string into MONGO_URI, add your home IP
  range to the Atlas network allow-list, then  sudo ./install.sh --from packages  and
  afterwards  sudo ./install.sh --only creds  so the app picks the new DSN up.
  WSL2: Windows decides when the machine sleeps. Control Panel -> Power Options ->
  "Choose what closing the lid does" -> Do nothing (plugged in), and keep the charger in.
  Ubuntu must have systemd on: /etc/wsl.conf -> [boot] systemd=true, then  wsl --shutdown.
  And one terminal has to stay open in the distro, or Windows stops the WSL VM when idle.
EOF
    printf '\n  %s thing(s) to fix first - they are listed in red above\n\n' "$BAD"
    exit 1
fi

# ── wake ─────────────────────────────────────────────────────────────────────
if [ "$CMD" = wake ]; then
    if grep -qi microsoft /proc/version 2>/dev/null ; then
        printf '\nThis is WSL2: Windows owns the lid and the power button, Ubuntu does not.\n\n'
        printf '  1. In Windows (not here): Control Panel -> Power Options ->\n'
        printf '     "Choose what closing the lid does" -> Plugged in = Do nothing\n'
        printf '  2. Still in Windows: Settings -> System -> Power -> Screen and sleep ->\n'
        printf '     "When plugged in, PC goes to sleep after" = Never\n'
        printf '  3. In Ubuntu, make sure systemd actually starts, or every systemctl call in\n'
        printf '     this repo does nothing at all:\n'
        printf '       sudo tee /etc/wsl.conf >/dev/null <<EOT\n'
        printf '       [boot]\n'
        printf '       systemd=true\n'
        printf '       EOT\n'
        printf '     then "wsl --shutdown" from PowerShell and open Ubuntu again.\n'
        printf '  4. Keep a terminal open inside Ubuntu. Windows stops an idle WSL VM, and a\n'
        printf '     stopped VM means the tunnel and nginx go down with it. A heartbeat job\n'
        printf '     in the distro is the usual fix:\n'
        printf '       crontab -l 2>/dev/null | { cat; echo "* * * * * : > /dev/null"; } | crontab -\n\n'
        printf 'Nothing was changed. On a bare Ubuntu install this same command does the job.\n\n'
        exit 1
    fi
    [ "$(id -u)" = 0 ] || { printf 'I need root to write under /etc - run:  sudo %s wake\n' "$0" >&2 ; exit 2 ; }
    if ! pgrep -x systemd > /dev/null || [ ! -d /run/systemd/system ]; then
        printf 'systemd is not running here (a container, or a chroot). Refusing to write\n/etc/systemd and claim it worked - do it on the real machine instead.\n' >&2
        exit 1
    fi

    printf 'A server must not nap: lid close, sleep and hibernate off.\n'
    mkdir -p /etc/systemd/logind.conf.d
    cat > /etc/systemd/logind.conf.d/99-server.conf <<'EOF'
# Managed by drvtw install/laptop-serve.sh wake - a server does not nap.
[Login]
HandleLidSwitch=ignore
HandleLidSwitchExternalPower=ignore
HandleLidSwitchDocked=ignore
IdleAction=ignore
EOF
    printf '  wrote /etc/systemd/logind.conf.d/99-server.conf\n'
    if systemctl mask sleep.target suspend.target hibernate.target hybrid-sleep.target > /dev/null 2>&1; then
        printf '  masked sleep/suspend/hibernate targets\n'
    else
        printf '  could not mask the sleep targets - the logind lines may still be enough\n' >&2
    fi
    if systemctl restart systemd-logind > /dev/null 2>&1; then
        printf '  logind restarted\n'
    else
        printf '  logind not restarted - reboot when convenient\n'
    fi

    bad=0
    for k in HandleLidSwitch=ignore HandleLidSwitchExternalPower=ignore IdleAction=ignore ; do
        grep -qx "$k" /etc/systemd/logind.conf.d/99-server.conf || { printf '  FAIL %s is not in the file\n' "$k" >&2 ; bad=1 ; }
    done
    if ! systemctl is-enabled suspend.target 2>&1 | grep -q masked ; then
        printf '  FAIL suspend.target is still %s\n' "$(systemctl is-enabled suspend.target 2>&1)" >&2 ; bad=1
    fi
    if [ "$bad" = 0 ]; then
        printf '\n  verified: closing the lid will not stop the server, and the sleep targets are masked.\n'
        printf '  Battery in, charger in - a laptop is the one server that survives a power cut.\n\n'
        exit 0
    fi
    printf '\n  something above did not take. Close this window, reboot, and re-run to confirm.\n\n'
    exit 1
fi

# ── host <siteName> ──────────────────────────────────────────────────────────
if [ "$CMD" = host ]; then
    [ -n "$DOMAIN" ] || { printf 'DOMAIN is not set - fill install/config.env first\n' >&2 ; exit 1 ; }
    CFG="$(cfg_path)"
    [ -f "$CFG" ] || { printf 'no /etc/cloudflared/config.yml and no %s/config.yml. Make the tunnel first: install/LAPTOP-SERVER.md step 5\n' "$HOME/.cloudflared" >&2 ; exit 1 ; }
    # /etc is root-owned, so the edit and the restart go through sudo when needed
    SUDO=""
    [ -w "$CFG" ] || SUDO="sudo"
    HOST="$SITE.on.$DOMAIN"
    case "$SITE" in *.*) HOST="$SITE" ;; esac
    BIN="$(command -v cloudflared || echo "$HOME/.cache/drvtw/cloudflared")"
    TUNNEL="$(grep -m1 '^tunnel:' "$CFG" | awk '{print $2}')"
    [ -n "$TUNNEL" ] || { printf 'no tunnel: line in %s\n' "$CFG" >&2 ; exit 1 ; }

    if grep -q "hostname: $HOST\$" "$CFG" ; then
        printf '  %s is already in %s - nothing to add\n' "$HOST" "$CFG"
    else
        # cloudflared needs the last ingress rule to match everything, so the new entry
        # goes above that catch-all rather than being appended to the file.
        TMP="$(mktemp)"
        awk -v host="$HOST" '
            !done && /^- service:|^[[:space:]]+- service:/ {
                printf "  - hostname: %s\n    service: https://127.0.0.1:443\n    originRequest:\n      noTLSVerify: true\n", host
                done=1
            }
            { print }' "$CFG" > "$TMP"
        if ! grep -q "hostname: $HOST\$" "$TMP" ; then
            printf 'I could not find the catch-all rule to insert above. Add this by hand:\n\n  - hostname: %s\n    service: https://127.0.0.1:443\n    originRequest:\n      noTLSVerify: true\n\n' "$HOST"
            rm -f "$TMP" ; exit 1
        fi
        if [ "$DRY" = 1 ]; then
            printf '  (the file it would write, for checking)\n'
            sed "s/^/      /" "$TMP"
        else
            $SUDO cp "$CFG" "$CFG.bak.$(date +%s)"
            $SUDO tee "$CFG" > /dev/null < "$TMP"
        fi
        rm -f "$TMP"
        printf '  %sadded ingress for %s\n' "$([ "$DRY" = 1 ] && echo 'dry-run: ')" "$HOST"
    fi

    printf '  %s%s tunnel route dns %s %s\n' "$([ "$DRY" = 1 ] && echo 'dry-run: ')" "$(basename "$BIN")" "$TUNNEL" "$HOST"
    if [ "$DRY" = 0 ]; then
        dns_out="$("$BIN" tunnel route dns "$TUNNEL" "$HOST" 2>&1)"
        dns_rc=$?
        if [ "$dns_rc" != 0 ]; then
            case "$dns_out" in
                *"origin cert"*|*origincert*|*"not logged in"*)
                    printf '  not logged in to Cloudflare yet - run this once, it opens a browser:\n      %s tunnel login\n  then re-run: ./laptop-serve.sh host %s\n' "$BIN" "$SITE" ;;
                *"already exists"*)
                    printf '  DNS record for %s was already there - fine\n' "$HOST" ;;
                *) printf '  route dns said: %s\n' "$(printf '%s' "$dns_out" | tail -1)" ;;
            esac
        fi
        "$BIN" tunnel ingress validate --config "$CFG" 2>&1 | sed 's/^/  /'
        if [ -d /run/systemd/system ] && systemctl is-active --quiet cloudflared 2>/dev/null; then
            $SUDO systemctl restart cloudflared && printf '  cloudflared restarted\n'
        else
            printf '  restart the tunnel yourself if it is not a service:  pkill cloudflared ; %s tunnel run &\n' "$BIN"
        fi
        printf '\n  now: https://%s/  - sign in once as the owner and the site is served from this laptop\n' "$HOST"
    fi
    exit 0
fi
