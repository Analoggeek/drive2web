# How to use DriveToWeb

Two audiences, two halves of this file:

* **[Run it as a user](#run-it-as-a-user)** — what the service does once it is installed, and
  how a site owner publishes content.
* **[Install it as the operator](#install-it-as-the-operator)** — the server, database, OAuth
  apps, DNS and configuration you need to run your own instance.

If you want the background on *why* files are where they are, or what was changed relative to
the upstream repo, see [ANALYSIS-REPORT.md](ANALYSIS-REPORT.md).

```
┌───────────────┐   /~user@x.com/gd/…    ┌──────────────┐  Drive/Graph API  ┌──────────────┐
│  browser      │ ─────────────────────► │  index.php    │ ────────────────► │ Google /     │
│  (visitor)    │ ◀───────────────────── │  PHP + nginx  │ ◀──────────────── │ Microsoft    │
└───────────────┘    302 → site URL,     │  + MongoDB    │                   └──────────────┘
                    content, admin UI    └──────┬───────┘
                                                │ optional
                                        ┌───────▼────────┐
                                        │ CloudFront +   │  cdn-of/cdn2-of hosts,
                                        │ Lambda@Edge    │  token injection at the edge
                                        └────────────────┘
```

| Piece | Path | Notes |
|---|---|---|
| Application (router, OAuth, content serving, moderation) | `backend/html/index.php` | one file, ~3.4k lines, routes by `Host` |
| Shared helpers (unit-tested) | `backend/html/drvtw_private/drvtw_util.php` | validation, CORS, redirects, tokens, encodings |
| Logging | `backend/html/drvtw_private/drvtw_log.php` | CSV log in `/var/log/d2w` (or `$D2W_LOG_FOLDER`) |
| Content blacklist | `backend/html/drvtw_private/drvtw_blacklist.php` | phishing/copyright patterns, needs maintenance |
| Error / admin / backroom pages | `backend/html/inline/` | templated at request time — no hardcoded hosts |
| nginx configs | `backend/nginx_drvtw`, `nginx_proxy`, `nginx_drvpx` | app, cache proxy, CDN proxy |
| Edge functions | `backend/cf-lambda/` | optional CloudFront Lambda@Edge |
| Landing site + dashboard JS | `frontend/www.drv.tw/` | static; deploy to any static host |
| Install guides, mongod setup | `backend/setup/` | text guides + index/user scripts |
| Tests | `tests/` | run `./tests/run-all.sh` |
| Deployment audit | `tools/d2w-audit.php` | `php tools/d2w-audit.php --root /var/www/drv_tw` — config, permissions, extensions, nginx socket, blacklist structure |

---

## Run it as a user

A "site" is a folder in your Google Drive or OneDrive. DriveToWeb never stores your files; it
caches mappings and fetches content from the cloud drive on demand.

### 1. Sign in and get a site name

Open (substituting your deployment's API host):

```
https://api.your.domain/~you@outlook.com/od/?a=admin      # OneDrive
https://api.your.domain/~you@gmail.com/gd/?a=admin        # Google Drive
```

You are sent through OAuth (`/.b/<svc>/` on return). On success the app:

* creates a mapping `you@outlook.com / od → <22 random chars>` and
* shows the dashboard (`inline/admin.html`), which lists your published paths.

Your site is then live at

```
https://<siteName>.on.your.domain/
```

The dashboard's **Your website** card repeats that address and is where you change it:

| Control | What it calls |
|---|---|
| Open site / Copy address | nothing - the first is a link, the second writes the clipboard |
| typing in the field | `GET /do/siteName?name=<candidate>` (public, no session), debounced 400 ms |
| Save address | `POST /~<user>@<site>.on.<domain>/<svc>/@@rename` with `name=<candidate>` |
| Verify (domain panel) | `POST …/@@domain` with `domain=<host>` |

A name is a single DNS label: `a-z`, `0-9`, `-`, must start with a letter or digit, 3-63
characters, not all digits, not ending in `-`, not one of the deployment's own hostnames
(`www`, `api`, the base domain, ...) and not an `xn--` punycode label. The panel mirrors those
rules as you type, but **the server decides**: a name it will not hand out is refused with the
reason in the reply's `tip`, and `@@rename` is rate limited by `$siteNameChangeGapSec` (1 h by
default) so a site cannot be bounced between owners rapidly. Renaming moves the `sites` record -
the drive mapping, the cache and your files are untouched, and any link you published under the
old address stops resolving (it 404s; there is no redirect, because nothing records the old name
as yours after the move).

Nothing about signing in changes: connect a drive, and the site plus its address are created for
you (`gdSetupFromAuthCode()` / `odSetupFromAuthCode()` call `setSiteInfo( NULL, … )` and land you
on `?a=admin`); the card is only the UI for the command that has always existed.

### 2. Publish content

Put `index.html` (plus assets) in the root of the *shared* folder. Two conventions matter:

* **Folders are namespaced by the file id of their parent.** Anything you want addressable has
  to be inside the folder you shared; subfolders work as paths.
* **`@@` is reserved.** A path segment starting with `@@` is an API command, not a file.

Typical visitor behaviours:

| URL | Effect |
|---|---|
| `https://<site>.on.<domain>/` | serves `index.html`, then falls back to your custom 404, then `inline/404.html` |
| `…/some/file.md` | rendered inside `inline/md_wrap.html` (and quarantined like HTML) |
| `…/some/file.svg` | served as `image/svg+xml` |
| `…/?ur=…`, `…/@@…` | API commands — see below |
| `https://<domain>/site/<siteName>/…` | host-agnostic alias that 301s to the `*.on.` URL (useful behind a CDN) |

### 3. Owner commands (all require the owner session)

`@@cache` is idempotent and safe with `GET`; the rest are `POST`/`PUT` only. Authentication is
either the `D2WSESS` cookie you get by completing OAuth in this browser, or a bearer token equal
to your stored access token:

```bash
# warm the URL cache for the whole site
curl -X GET  --cookie "D2WSESS=<from the browser>" \
  https://api.your.domain/~you@outlook.com/od/@@cache

# share / unshare one file (id comes from the drive)
curl -X POST --cookie "D2WSESS=<…>" https://api.your.domain/~you@…/od/@@publish/<fileId>
curl -X POST --cookie "D2WSESS=<…>" https://api.your.domain/~you@…/od/@@lock/<fileId>

# copy or delete a file in your drive
curl -X POST --cookie "D2WSESS=<…>" https://api.your.domain/~you@…/gd/@@dup/<fileId>
curl -X POST --cookie "D2WSESS=<…>" https://api.your.domain/~you@…/gd/@@delete/<fileId>

# stream a file bypassing the cache
curl --cookie "D2WSESS=<…>" https://api.your.domain/~you@…/gd/@@download/<fileId>
```

Unauthenticated callers get `403 {"error":"ERR_AUTH_REQUIRED"}`; a `GET` on a mutator gets
`405 Allow: POST`; a cookie-authenticated mutator without the CSRF token gets
`403 {"error":"ERR_CSRF_REQUIRED"}`.

That token is derived from your session (`d2w_csrf_token( D2WSESS )`), needs no storage, and is
embedded in the dashboard page as `<meta name="d2w-csrf" content="…">` — so **the dashboard's
"Site tools" panel runs all of these commands in the browser** (refresh cache, publish, make
public/private, duplicate, delete; delete confirms first). From `curl` you pass it yourself, or
use a bearer token, which is exempt (a cross-origin page cannot read your token, so there is
nothing to forge):

```bash
CSRF=$(curl -s --cookie "D2WSESS=<…>" 'https://api.your.domain/~you@x.com/od/?a=admin' \
      | sed -n 's/.*name="d2w-csrf" content="\([0-9a-f]*\)".*/\1/p')
curl -X POST --cookie "D2WSESS=<…>" -H "X-D2W-CSRF: $CSRF" \
     https://api.your.domain/~you@x.com/od/@@lock/<fileId>
```

### 4. Private sites (visitor authentication)

If the site is marked "authenticated" (see `$requireApprovedSites`), visitors hitting a blocked
path get `inline/403-authenticate.html`: they enter their e-mail, `POST /do/sendSiteAuth`
mails a 6-digit code (`/do/checkSiteAuth` verifies it) and a per-site allowance is stored.
Delivery needs working SMTP; with `$requireApprovedSites = FALSE` the flow is not triggered for
normal content.

Two limits guard that exchange:

| Limit | Knob | When it trips |
|---|---|---|
| Sending | `$authCodeSendGapSec` | `{"error":"Wait","tip":"Wait N seconds…"}` — counted per site **and** per recipient |
| Guessing | `$authCodeMaxFails` (8) per `$authCodeRetryWindowSec` (900 s) | after 8 wrong codes for a site within 15 minutes, verification is skipped and the visitor is bounced with `?auth=slow` + `Retry-After`; counters live in `wwwdrv.authfail` (TTL index created by `setup/mongod/init.js`) |

The shipped code had only the first, so a 6-digit code valid for 15 minutes could be brute-forced
at leisure.

### 5. Custom domains

Resolution is by DNS, not by configuration: for a `Host` that is not `<site>.on.<domain>`,
`index.php` looks up the **TXT record of the requested host** and accepts a value of
`DRVTW=<site>.on.<domain>` (the legacy `DRVTW=@<site>` still works), then validates the extracted
name with `d2w_valid_site_name()` before it is used. So binding a domain means publishing that
one record; there is no per-domain config to edit and no restart.

The **Bring your own domain** panel in the dashboard does the same lookup from the other side, so
you can see whether DNS has propagated before you point anything at the server: it shows the exact
TXT value for your site, `POST`s it to `@@domain`, and reports `ERR_NOT_YET` until the record
answers. A successful check also stores `domain` on the site record (bookkeeping for the panel and
for abuse triage - serving does not depend on it). A `CNAME` to `<site>.on.<domain>` is accepted as
an alternative, and `www`-style hosts can then simply CNAME to the `*.on.` name.

Two things the panel cannot do for you, and operators get wrong:

* the host must reach this server (an `A`/`AAAA` record, or a CNAME through a CDN);
* **TLS is per host.** the wildcard `*.on.your.domain` does not cover `www.example.com`, so either
  terminate ACME for each bound host (HTTP-01 works now that the host resolves to you) or put
  CloudFront in front with an ACM certificate listing the names - see `DEPLOY-RUNBOOK.md` §3, §10.

Redirects from the API host use `d2w_safe_site_redirect()`, so only your own site paths and
`*.<$d2wSiteSuffix>` hosts are accepted.

### 5b. Response headers

Pages the application renders itself (error pages, dashboard, backroom) carry
`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin`,
`X-Robots-Tag: noindex, nofollow`, a `Permissions-Policy`, and a sane default CSP
(`$d2wAppCSP` overrides).

Hosted user content gets `X-Content-Type-Options: nosniff` and
`Cross-Origin-Resource-Policy: cross-origin` — cross-origin embedding is the product — and no
CSP by default, because a strict policy breaks arbitrary third-party sites; set
`$d2wContentCSP` (e.g. `base-uri 'self'; form-action 'self'`) to trade compatibility for
phishing resistance. `user.log` is scrubbed of e-mail local parts and of any credential that
reaches it (`$d2wLogPrivacy`, on by default).

### 6. Widgets

Hosted pages can pull in `?d2w` helpers (3D viewer, PDF.js, image viewer) — `inline/d2w_sw.js`
is served from the folder of the requesting page and only registers for those viewers. The
service worker's same-origin check uses `{{BASE_DOMAIN}}`, i.e. your domain.

---

## Install it as the operator

> The same steps as an ordered checklist — with the exact commands, the expected output of each
> check, and the rollback path — are in **[DEPLOY-RUNBOOK.md](DEPLOY-RUNBOOK.md)**. This section
> explains what each knob means; the runbook tells you what to type and in which order.

`backend/setup/DrvTWServerInstallation.txt` is the step-by-step version of this section; the
commands below are the same ones, with the corrections this review found (PHP 8.x socket,
permissions, `D2W_LOG_FOLDER`).

### 0. What you need

* A **domain with wildcard DNS** — the app needs `*.<your.domain>` to resolve, because every
  site is a subdomain (`<site>.on.your.domain`). Wildcard certificates (DNS-01) are strongly
  recommended: `your.domain`, `*.your.domain`, `*.on.your.domain`.
* 1 Ubuntu 20.04+ server for PHP/nginx/MongoDB (a second one for the caching proxy is optional).
* An **OAuth app for Microsoft** (Azure AD, `Files.Read` delegated) — required.
  A Google OAuth app is optional; *Google restricts new apps requesting Drive scopes, so a fresh
  install usually cannot offer Google Drive sign-in.*
* SMTP credentials (SES/SendGrid/anything) if you want private sites and abuse mail.
* MongoDB 4.4+ (local socket + optional Atlas replica).

### 1. Packages

```bash
sudo apt install -y nginx php8.1-fpm php8.1-mongodb php8.1-mbstring php8.1-curl php8.1-dom git
# php7.4 in the old guide does not match the configs: the pool socket must be
# /run/php/php8.1-fpm.sock (or whichever 8.x you install — keep both in sync)
```

`/etc/php/8.1/fpm/pool.d/www.conf`:

```ini
pm = static
pm.max_children = 256
pm.max_requests = 500
slowlog = /var/log/php/php-$pool.log.slow
request_slowlog_timeout = 60s
pm.status_path = /php-fpm-status
env[D2W_LOG_FOLDER] = /var/log/d2w
```

```bash
sudo mkdir -p /var/log/php /var/log/d2w
sudo chown www-data:www-data /var/log/php /var/log/d2w
sudo chmod 0770 /var/log/d2w            # 0640 here was a bug: a directory needs the x bit
sudo service php8.1-fpm restart
```

### 2. Code and permissions

```bash
git clone <your fork> drvtw_oss
# The shipped nginx configs assume html/, nginx_drvtw and setup/ sit directly under
# /var/www/drv_tw, i.e. the contents of backend/ - not the repo root:
sudo mkdir -p /var/www/drv_tw
sudo cp -a drvtw_oss/backend/. /var/www/drv_tw/
sudo chown -R $USER:www-data /var/www/drv_tw
sudo find /var/www/drv_tw -type d -exec chmod 0750 {} \;
sudo cp /var/www/drv_tw/html/drvtw_private/drvtw_creds.example.php \
        /var/www/drv_tw/html/drvtw_private/drvtw_creds.php
sudo chmod 0640 /var/www/drv_tw/html/drvtw_private/drvtw_creds.php
```

`drvtw_creds.php` stays inside the docroot because nginx denies `/drvtw_private/` (see
`backend/nginx_drvtw`); if you change the nginx layout, move the file out and adjust the
`require` at the top of `index.php`.

### 3. Configuration reference (`drvtw_creds.php`)

| Key | Required | Meaning |
|---|---|---|
| `$d2wBaseDomain` | yes | e.g. `your.domain`; drives every derived host |
| `$d2wApiHost`, `$d2wWebHost` | yes | `api.your.domain`, `www.your.domain` |
| `$d2wSiteSuffix` | derived | `.on.$d2wBaseDomain` — the per-site suffix |
| `$siteNameChangeGapSec` | `3600` | minimum seconds between two owner-initiated `@@rename`s of one site; `0` disables the throttle |
| `$d2wReservedSiteNames` | `array()` | extra site names never to hand out; the deployment's own hostnames (`www`, `api`, the base domain and its first labels, `on`, `drvtw`, …) are already on the list |
| `$d2wCdnHosts` | only with cf-lambda | hosts whose requests are proxied at the edge |
| `$d2wWidgetScript` | no | widget loader injected into hosted pages |
| `$d2wContactEmail` | no | shown on error/help pages (replaces the author's address) |
| `$gdClientId`, `$gdClientSecret`, `$gdApiKey` | for Drive | redirect URI: `https://$d2wApiHost/.a/gd/` |
| `$odClientId`, `$odClientSecret` | for OneDrive | redirect URI: `https://$d2wApiHost/.a/od/` |
| `$mongoLocalConnection`, `$mongoRemoteConnections` | yes | `%2Ftmp%2Fmongodb-27017.sock` form for the socket |
| `$smtpHost`, `$smtpPort`, `$sesSmtpUser`, `$sesSmtpPassword`, `$mailFrom`, `$mailFromName` | for private sites | |
| `$sessionSecret` | **yes** | signs `D2WSESS` (owner sessions). `openssl rand -hex 32`. NULL ⇒ `@@` commands need a bearer token |
| `$opsSecret`, `$opsUser`, `$opsPass` | for operator tools | hourly HMAC token + HTTP basic for `@@backroom`, `@@log.csv` |
| `$siteNameSecret` | no | set ⇒ reproducible site names; NULL (recommended) ⇒ random |
| `$requireApprovedSites` | no | `TRUE` = serve only sites whose owner is verified (what the hosted service used) |
| `$allowCreatePostDb` | no | `TRUE`/user id enables "POST to a Google Sheet as a database" |
| `$corsAllowedOrigins` | no | extra origins allowed on the API host |
| `$apiTokens` | no | static tokens for trusted server-to-server callers (abuse API) |

### 4. Database

```bash
sudo service mongod start
mongosh /var/www/drv_tw/setup/mongod/init.js          # creates admin + drvuser
# indexes (init.js covers accessTokens; cache needs this one)
mongosh "mongodb://drvuser@%2Ftmp%2Fmongodb-27017.sock/wwwdrv" --eval \
  'db.cache_gd.createIndex({userId:1,version:1,urlHash:1}); db.accessTokens.createIndex({userId:1,service:1})'
```

The app connects with TLS verification **on** by default. Either give it a
`/etc/ssl/mongodb.pem` your CA list trusts, or set the documented opt-out — do not
disable verification for a database that stores OAuth tokens (the shipped code did).

### 5. nginx

```bash
sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -s /var/www/drv_tw/nginx_drvtw /etc/nginx/sites-enabled/
# optionally the caching proxy + CDN proxy:
sudo ln -s /var/www/drv_tw/nginx_proxy /etc/nginx/sites-enabled/
sudo vi /etc/nginx/sites-enabled/nginx_drvtw    # real_ip_from, php socket, cert paths
( cd drvtw_oss && ./tests/nginx-lint.sh )       # validates all three, stubbed env
sudo service nginx restart
```

Two things to edit for sure: `set_real_ip_from` (list only your own proxies — `0.0.0.0/0`
lets visitors spoof their source address) and the `upstream` host IPs in `nginx_proxy`.

### 6. Sanity checks

```bash
( cd drvtw_oss && ./tests/run-all.sh )  # lint + all tests, from a checkout
php drvtw_oss/tools/d2w-audit.php --root /var/www/drv_tw   # config/permissions/blacklist audit
curl -sI https://api.your.domain/ | head -1        # expect 301 → https://www.your.domain/
curl -sI http://api.your.domain/  | head -1        # expect 302 (same host, https upgrade)
curl -s  https://api.your.domain/~me@x.com/od/@@delete/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs \
   -X POST                                          # expect 403 ERR_AUTH_REQUIRED
curl -s  https://random.on.your.domain/ | head -3   # expect 403/404 page of YOUR domain
sudo tail -5 /var/log/d2w/user.log                  # expect CSV lines, dir 0770
```

`/var/log/d2w/user.log` is read by `@@backroom`; `backend/setup/logrotate.d/d2w` rotates it
daily and keeps 52 versions (`create 0640 www-data adm`).

### 7. Front end

```bash
cp frontend/www.drv.tw/inc/config.js /tmp/config.js     # edit hosts, client ids, contact e-mail
#   `siteSuffix` must become '.on.<your domain>' - it ships as '.on.drv.tw', and
#   the landing page and dashboard build every site link from it
```

* Deploy `frontend/www.drv.tw/` to any static host (Azure Static Web Apps works out of the
  box: `.github/workflows/deploy-frontend.yml` runs the tests and deploys; add the
  `D2W_AZURE_DEPLOY_TOKEN` repository secret), **or** serve `backend/html/www.drv.tw/` — it is
  a synced copy, which is what `nginx_drvtw`'s `try_files /$host$uri` looks for.
* `inc/config.js` is the only file you must edit: Microsoft/Google client ids (the implicit
  flow redirect must be registered as `https://$d2wApiHost/.b/<svc>/`), hosts, contact e-mail.
  Leave `analyticsId` and `paypalClientId` null unless you want those features — the shipped
  build pointed at the original author's Analytics property and a placeholder PayPal client id.
* Google Translate and the CDN jQuery/font-awesome references remain third-party loads; drop
  them if you want no third-party requests at all.

### 8. Optional: CloudFront edge functions

Needed only to keep Drive/Graph tokens out of browser-visible redirects and to serve files
from the edge:

1. Deploy `backend/cf-lambda/*.js` as Lambda@Edge in `us-east-1`.
2. `guf_requestor`: set the `GOOGLE_API_KEY` environment variable (public-file fallback;
   without it those requests are denied instead of sending `key=undefined`).
3. Origin for the `cdn-of`/`cdn2-of` distributions → your nginx proxy (`nginx_drvpx`), and
   add those hostnames to `$d2wCdnHosts` in `drvtw_creds.php`.
4. The requestors now enforce a host allowlist (`ALLOWED_SUFFIXES` in `opf_requestor.js`) and
   reject anything else with 403; if you proxy other storage hosts, extend that list.
5. `node tests/test-lambda.js` and `python3 tests/check-edge-contract.py` must pass after any
   change here — they exercise the real handlers.

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `502` from nginx, `connect() to unix:/run/php/php8.1-fpm.sock failed` | you installed a different PHP: point `fastcgi_pass` at the pool socket you actually have |
| Every page is blank, log has `fopen(...): Failed to open stream` | `/var/log/d2w` missing or not group-writable; `chown www-data:www-data`, `chmod 0770` |
| `403 ERR_CSRF_REQUIRED` on a mutating `@@` command | cookie auth without `X-D2W-CSRF`; the value comes from `<meta name="d2w-csrf">` on the admin page (empty when that page carried no session) |
| Visitor bounced with `?auth=slow` | `$authCodeMaxFails` reached for that site; wait out `Retry-After`, or delete the row in `db.authfail` |
| Site URLs 404 through the CDN but work direct | `guf_requestor.js` not deployed/updated (the old single-group pattern 404'd every file), or `?ur=` host not in `ALLOWED_SUFFIXES` |
| `403 ERR_AUTH_REQUIRED` on `@@…` | you are not the owner session: sign in at `api.…/~user@x/od/?a=admin` first, or send `Authorization: Bearer <stored access token>` |
| `401` then nothing on `@@backroom` | HTTP Basic credentials missing (`$opsUser`/`$opsPass`), or the hourly token rotated — re-fetch `GET /@@tok` (with basic auth) and retry; `X-D2W-Token:` is accepted instead of putting the token in the URL |
| Visitors get the marketing site after sign-in | `inc/config.js` still has placeholder client ids / `apiHost` — the implicit flow bailed out |
| "site is not approved" / 403 on a fresh site | `$requireApprovedSites = TRUE` and the owner never completed e-mail verification (check SMTP) |
| Google sign-in fails with `access_denied` / `unverified app` | expected for new apps: Google restricted Drive scopes; use OneDrive or get your app verified |
| Hosted pages are slow and `user.log` shows `MISS` constantly | nginx `proxy_cache` zone is tiny or the cache dir is unwritable (`/var/cache/nginx`), or `$cacheVersion` was never bumped after a change — `POST …/@@cache` re-warms it |

## Security checklist for a public deployment

- [ ] `$sessionSecret`, `$opsSecret`, `$opsPass` generated (`openssl rand -hex 32`), not placeholders
- [ ] `$requireApprovedSites = TRUE`
- [ ] MongoDB TLS on, verification on, `drvuser` limited to `readWrite` on `wwwdrv`
- [ ] `set_real_ip_from` limited to your own proxies; `limit_req` zones sized for your traffic
- [ ] `/drvtw_private/` unreachable (test: `curl -s -o /dev/null -w '%{http_code}\n' https://api.your.domain/drvtw_private/drvtw_creds.php` → `404`)
- [ ] `drvtw_creds.php` is `0640` and never committed (`.gitignore` covers it)
- [ ] `drvtw_blacklist.php` reviewed on a schedule; abuse mailboxes monitored (`/do/reportAbuse`)
- [ ] CloudFront distributions restricted to `$d2wCdnHosts`; no generic `?ur=` proxying
- [ ] `inc/config.js` has your client ids, your contact address, your `siteSuffix`, and no analytics id you don't own
