# Backend

The PHP application server that handles all routing, OAuth, content serving, and caching.

## Directory Structure

```
backend/
├── html/
│   ├── index.php                  # Main router and application logic (~3500 lines)
│   ├── PHPMailer/                 # PHPMailer library (LGPL-2.1)
│   ├── drvtw_private/
│   │   ├── drvtw_creds.example.php  # Credentials template — copy and fill in
│   │   ├── drvtw_blacklist.php      # Phishing/malware content patterns
│   │   ├── drvtw_util.php           # Validation/CORS/redirect/token helpers (unit-tested)
│   │   └── drvtw_log.php            # CSV logging (0770 dir, degrades instead of fataling)
│   ├── inline/                    # HTML templates served inline by index.php
│   │   ├── admin.html             # User dashboard
│   │   ├── backroom.html          # Operator log viewer
│   │   ├── 403*.html              # Access denied pages
│   │   ├── 404.html               # Not found page
│   │   └── ...
│   └── www.drv.tw/                # Static files for the landing site (served locally)
├── cf-lambda/                     # Cloudflare/CloudFront Lambda@Edge functions
├── nginx_drvtw                    # Nginx config for the application server
├── nginx_proxy                    # Nginx config for the caching proxy
├── nginx_drvpx                    # Nginx config for the CDN proxy
└── setup/
    ├── DrvTWServerInstallation.txt  # Step-by-step server setup guide
    ├── ProxyServerInstallation.txt  # Proxy server setup guide
    ├── logrotate.d/d2w              # Log rotation config
    └── mongod/                      # MongoDB setup and backup scripts

../tests/                            # offline test suite: ./tests/run-all.sh
```

The repo layout differs from the paths in `nginx_*`: the configs expect the **contents** of
`backend/` to live at `/var/www/drv_tw` (so `/var/www/drv_tw/html`,
`/var/www/drv_tw/nginx_drvtw`, `/var/www/drv_tw/setup/...`).

## Server Setup

### Prerequisites

- Ubuntu 20.04 / 22.04
- Nginx + PHP-FPM 8.1 or newer (`fastcgi_pass` in `nginx_drvtw` must match your pool socket)
- PHP extensions: `php-mongodb`, `php-mbstring`, `php-curl`, `php-dom`
- MongoDB Community Edition 4.4+
- acme.sh (for SSL) — you need a **wildcard** certificate: every hosted site is a subdomain
- `nginx -t` + `php -l` + `node` if you want to run `tests/run-all.sh` before deploying

See `setup/DrvTWServerInstallation.txt` for step-by-step instructions.

### Credentials

Copy the credentials template and fill in your values:

```bash
cp html/drvtw_private/drvtw_creds.example.php \
   html/drvtw_private/drvtw_creds.php
```

You will need:
- **Microsoft OAuth** credentials from [Azure App Registrations](https://portal.azure.com/)
  (redirect URIs `https://$d2wApiHost/.a/od/` and `/.b/od/`)
- **Google OAuth** credentials from [Google Cloud Console](https://console.cloud.google.com/)
  — note that Google currently refuses new apps requesting Drive scopes
- **MongoDB** connection strings (local socket + optional Atlas)
- **SMTP** credentials for email-based site authentication
- generated secrets for `$sessionSecret` (owner sessions) and `$opsSecret`/`$opsUser`/`$opsPass`
  (operator tools); `openssl rand -hex 32` each

Every key the application reads is listed and commented in `drvtw_creds.example.php`,
including `$d2wBaseDomain`/`$d2wApiHost`/`$d2wWebHost`/`$d2wSiteSuffix`/`$d2wCdnHosts`,
`$d2wContactEmail`, `$d2wWidgetScript`, `$corsAllowedOrigins`, `$requireApprovedSites`,
`$allowCreatePostDb`, `$siteNameSecret`, `$apiTokens`, `$d2wLogPrivacy`, `$d2wAppCSP`,
`$d2wContentCSP`, `$authCodeMaxFails`, `$authCodeSendGapSec` and the `$mongoTls*` trio. Set the
host keys first: they replace the `drv.tw` values that used to be hardcoded in the router, in
`inline/*.html` and in the error pages.

Then let the tool tell you what you missed:

```bash
php tools/d2w-audit.php --root /var/www/drv_tw      # exit 2 = something blocks serving
```

It checks config placeholders, permissions (including the 0770-vs-0640 log-folder trap),
extensions, the `fastcgi_pass` socket named in the nginx configs, `set_real_ip_from
0.0.0.0/0`, and the structure of `drvtw_blacklist.php` (groups that can never match,
duplicated needles, patterns so short they will false-positive).

The log folder comes from the environment: `env[D2W_LOG_FOLDER] = /var/log/d2w` in the FPM
pool (default `/var/log/d2w`), which must be group-writable **and** searchable — `0770`, not
`0640`.

### Nginx

Symlink the appropriate config into `/etc/nginx/sites-enabled/`:

```bash
# Application server
sudo ln -s /var/www/drv_tw/nginx_drvtw /etc/nginx/sites-enabled/

# Caching proxy (separate machine or same)
sudo ln -s /var/www/drv_tw/nginx_proxy /etc/nginx/sites-enabled/
```

Update hostnames, SSL certificate paths, and upstream IPs in the config files to match your
deployment, and narrow `set_real_ip_from` to your own proxies. Validate any change with:

```bash
./tests/nginx-lint.sh      # nginx -t against stubbed cache dirs / certs / fastcgi snippet
```

## URL Routing

`index.php` routes based on hostname:

Hosts are compared against the configured values, not literal names:

| Host | Purpose |
|---|---|
| `$d2wWebHost` (`www.your.domain`) | Landing page, `/inc/*` assets, widget |
| `$d2wApiHost` (`api.your.domain`) | OAuth callbacks (`/.a/`, `/.b/`), owner admin API, `~user/…` paths, operator routes, `/do/…`, `/abuseReport` |
| your.domain | 301s to the web host, plus `/site/<name>/…` aliases |
| `<site>$d2wSiteSuffix` (`<site>.on.your.domain`) | Serve the user's hosted site |
| `$d2wCdnHosts` (`cdn-of…`, `cdn2-of…`) | Edge file proxies (`/_guf/`, `/_opf/`) |
| Custom domain | Resolved via DNS TXT/CNAME to a site name |

## Operator Tools

Two internal endpoints are available on the API host (HTTPS only):

- `@@backroom` — real-time log viewer dashboard (`inline/backroom.html`)
- `@@log.csv/<token>` — raw log stream (what backroom polls)
- `@@tok` — mints the current token for the tools above

Both require HTTP basic auth (`$opsUser`/`$opsPass`) **and** a token
(`d2w_ops_token()`) computed as `hash_hmac('sha256', hourWindow|purpose, $opsSecret)`,
rotating hourly with a one-window grace; `X-D2W-Token:` is accepted instead of a URL token.
`getLog()` re-checks on every poll.

The upstream version guarded these with `sha1( floor(time()/3600) + offset + 919 )` — no
secret, so anyone reading the public repository could stream the operator log. If you deploy
this code, treat any log that predates the fix as containing readable URIs and e-mail
addresses.

Owner-only site commands (`@@publish`, `@@lock`, `@@unlock`, `@@dup`, `@@delete`, `@@download`,
`@@cache`) are likewise gated: they need the `D2WSESS` cookie issued at the end of the OAuth
code exchange, or `Authorization: Bearer <stored access token>`, and mutators are POST/PUT only.
A cookie-authenticated mutation must additionally carry `X-D2W-CSRF`, whose value is derived
from the session itself (`d2w_csrf_token()` — no storage, expires with the session) and injected
into the dashboard page as `<meta name="d2w-csrf">`; the dashboard's "Site tools" panel uses it.
Bearer callers are exempt because a cross-origin page cannot read that header's input.

## Phishing Blacklist

`html/drvtw_private/drvtw_blacklist.php` contains content patterns used to detect and block phishing pages before they are served. It has been maintained continuously since 2017 and covers hundreds of known phishing patterns (PayPal, Microsoft, Facebook, banking institutions, etc.).

To add a new rule, add a pattern entry and commit. The format is documented in the file itself.

## cf-lambda

See [cf-lambda/README.md](cf-lambda/README.md).
