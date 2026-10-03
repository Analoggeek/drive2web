#!/usr/bin/env bash
#
# Asks the few questions the installer needs and writes install/config.env.
# Run it as often as you like: current answers are offered as defaults.
#
#   ./wizard.sh              # ask everything
#   ./wizard.sh --minimal    # only the four you cannot avoid
#   ./wizard.sh --check      # show what config.env holds, change nothing
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF="$HERE/config.env"
MODE=ask
[ "${1:-}" = --minimal ] && MODE=minimal
[ "${1:-}" = --check ] && MODE=check

get() { # get KEY  -> current value from config.env (empty if absent)
    [ -f "$CONF" ] || return 0
    sed -n "s/^$1=//p" "$CONF" | tail -1
}

pubip() { curl -fsSM 8 -s https://api.ipify.org 2>/dev/null || true ; }

if [ "$MODE" = check ]; then
    printf 'config file: %s\n' "$CONF"
    [ -f "$CONF" ] || { echo '  (missing - run:  ./wizard.sh)' ; exit 1 ; }
    grep -v '^\s*#' "$CONF" | grep . | sed 's/^/  /'
    exit 0
fi

umask 077
: > "$CONF"
add() { printf '%s=%s\n' "$1" "${2:-}" >> "$CONF" ; }
ask() { # ask KEY "prompt" default
    local key="$1" prompt="$2" def="$3" cur got
    cur="$(get "$key")"
    [ -n "$cur" ] && def="$cur"
    printf '\n%s\n  [%s] > ' "$prompt" "${def:-none}"
    if [ "$MODE" = minimal ] && [ -n "${def:-}" ]; then reply="" ; else read -r reply ; fi
    got="${reply:-$def}"
    add "$key" "$got"
    printf '  saved  %s=%s\n' "$key" "$got"
}

echo "DriveToWeb - the questions. Enter keeps the value in [brackets]."
echo "You can stop any time with Ctrl-C and run me again later."

ask DOMAIN       "1. Your domain (sites become <name>.on.THIS)"  "$(get DOMAIN)"
ask SERVER_IP    "2. This server's public IP"                     "$(pubip)"
ask DNS_PROVIDER "3. DNS provider for the certificate: cloudflare | aws | manual | none (behind a tunnel)" "${DNS_PROVIDER:-cloudflare}"
ask DNS_API_TOKEN "   Its API token (skip if provider=manual)"    "$(get DNS_API_TOKEN)"
ask ONEDRIVE_CLIENT_ID "4. Azure app: Application (client) ID"    "$(get ONEDRIVE_CLIENT_ID)"
[ "$MODE" = minimal ] || ask ONEDRIVE_CLIENT_SECRET "   Azure app: a client secret value" "$(get ONEDRIVE_CLIENT_SECRET)"
[ "$MODE" = minimal ] || ask SMTP_HOST "SMTP host for access-code e-mails (empty = none, and sites are public)" "$(get SMTP_HOST)"
[ "$MODE" = minimal ] || ask SMTP_PORT "   SMTP port" "${SMTP_PORT:-587}"
[ "$MODE" = minimal ] || ask SMTP_USER "   SMTP user" "$(get SMTP_USER)"
[ "$MODE" = minimal ] || ask SMTP_PASSWORD "   SMTP password" "$(get SMTP_PASSWORD)"
[ "$MODE" = minimal ] || ask MAIL_FROM "   sender address" "no-reply@$(get DOMAIN)"
[ "$MODE" = minimal ] || ask GOOGLE_CLIENT_ID "Google Drive client id (empty is normal: Google blocks new Drive apps)" "$(get GOOGLE_CLIENT_ID)"
[ "$MODE" = minimal ] || ask INSTALL_MONGODB "Install MongoDB on this box? yes/no" "${INSTALL_MONGODB:-yes}"
[ "$MODE" = minimal ] || ask APP_DIR "Where the code goes" "${APP_DIR:-/var/www/drvtw}"

# values the installer needs but nobody has to think about
for k in API_HOST WWW_HOST; do : ; done
cat >> "$CONF" <<EOF

# derived, for your reference only (the installer computes these)
#   API_HOST = api.$(get DOMAIN)
#   WWW_HOST = www.$(get DOMAIN)
EOF

echo
echo "wrote $CONF"
echo "next:  sudo ./install.sh        (add --dry-run first if you want to watch)"
