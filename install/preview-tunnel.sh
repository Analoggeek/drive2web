#!/usr/bin/env bash
#
# A temporary public HTTPS address for a local DriveToWeb install. No account, no card,
# nothing to sign up for - Cloudflare's "quick tunnel".
#
#   ./preview-tunnel.sh              # tunnel the web server on port 80
#   ./preview-tunnel.sh 8099         # tunnel another local port
#   ./preview-tunnel.sh --check      # only report whether this is possible here
#
# WHAT THIS IS NOT: a deployment. The URL is random and changes on every run, it is a
# single hostname so the per-site <name>.on.<domain> addresses cannot resolve through it,
# and the tunnel dies when you press Ctrl-C. Use it to see the app on your phone, to show
# someone a screen, or to test the sign-in page from outside your own network. Serving
# other people's files still means install.sh, a domain and a server that stays on.
set -u

PORT=80
CHECK=0
for arg in "$@" ; do
    case "$arg" in
        --check) CHECK=1 ;;
        -h|--help) sed -n '2,17p' "$0" ; exit 0 ;;
        --*) printf 'unknown flag: %s\n' "$arg" >&2 ; exit 2 ;;
        *) PORT="$arg" ;;
    esac
done
case "$PORT" in
    ''|*[!0-9]*) printf 'the port must be a number, got: %s\n' "$PORT" >&2 ; exit 2 ;;
esac

need() { command -v "$1" >/dev/null 2>&1 ; }
need curl || { printf 'curl is required (apt-get install -y curl)\n' >&2 ; exit 1 ; }

# Before anything else: is something actually serving on that port? Half the time people
# reach for a tunnel, the real problem is that nginx is stopped or listening on 80 only.
# Note the `|| true`: curl already prints 000 when it cannot connect, and `|| echo 000`
# beside it yields the string "000000", which is not equal to "000" - so the guard below
# would happily announce a dead port as a working one and tunnel into nothing.
code="$(curl -s -o /dev/null -m 6 -w '%{http_code}' "http://127.0.0.1:$PORT/" 2>/dev/null || true)"
[ -n "$code" ] || code=000
case "$code" in 000|000000)
    printf '\nnothing answering on http://127.0.0.1:%s - fix that first:\n' "$PORT"
    printf '   systemctl status nginx --no-pager | head -5\n'
    printf '   sudo ./install.sh --from nginx      # or: --only nginx\n'
    printf '   and remember the tunnel needs the local port, not a firewall-closed one\n\n'
    exit 1
    ;;
esac
printf 'local server: http://127.0.0.1:%s answers HTTP %s\n' "$PORT" "$code"
[ "$CHECK" = 1 ] && { printf 'check-only: ok, a tunnel would start from here\n' ; exit 0 ; }

BIN="${CLOUDFLARED_BIN:-$HOME/.cache/drvtw/cloudflared}"
if need cloudflared ; then
    BIN="$(command -v cloudflared)"
elif [ ! -x "$BIN" ]; then
    arch="$(uname -m)"
    case "$arch" in
        x86_64|amd64)  a=amd64 ;;
        aarch64|arm64) a=arm64 ;;
        *) printf 'no cloudflared build for %s; install it yourself or use the ssh fallback below\n' "$arch" >&2
           printf '   ssh -R 80:localhost:%s nokey@localhost.run\n' "$PORT" >&2 ; exit 1 ;;
    esac
    mkdir -p "$(dirname "$BIN")"
    printf 'downloading cloudflared (%s, ~20 MB) -> %s\n' "$a" "$BIN"
    curl -fsSL -o "$BIN.part" "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-$a" \
        || { printf 'download failed (is outbound 443 open?)\n' >&2 ; rm -f "$BIN.part" ; exit 1 ; }
    mv "$BIN.part" "$BIN" ; chmod +x "$BIN"
fi

LOG="$(mktemp)"
cleanup() { [ -n "${TUN_PID:-}" ] && kill "$TUN_PID" 2>/dev/null ; rm -f "$LOG" ; }
trap cleanup EXIT INT TERM

"$BIN" tunnel --url "http://127.0.0.1:$PORT" --no-autoupdate >"$LOG" 2>&1 &
TUN_PID=$!

URL=""
for _ in $(seq 1 60) ; do
    URL="$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG" | head -1)"
    [ -n "$URL" ] && break
    kill -0 "$TUN_PID" 2>/dev/null || { printf 'the tunnel stopped, its log:\n' >&2 ; tail -12 "$LOG" >&2 ; exit 1 ; }
    sleep 0.5
done
[ -n "$URL" ] || { printf 'no URL after 30 s; last lines of the log:\n' >&2 ; tail -12 "$LOG" >&2 ; exit 1 ; }

cat <<EOF

  ----------------------------------------------------------------
   Temporary public URL:   $URL
   local port:             $PORT
  ----------------------------------------------------------------
   Open it on your phone if you like. It works while this window runs;
   Ctrl-C ends it and the address is gone (the next run gets a new one).

   Two limits worth knowing before you show it to anyone:
     - one hostname only, so file links shaped like <name>.on.<domain>
       will not resolve - use the dashboard and the API for testing;
     - Cloudflare serves you HTTPS, but your side is plain HTTP on
       localhost. Fine for a demo, not a place to sign in for real.

   Same trick on a machine with ssh and nothing to download:
     ssh -R 80:localhost:$PORT nokey@localhost.run
  ----------------------------------------------------------------

EOF
wait "$TUN_PID"
