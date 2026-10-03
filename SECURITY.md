# Security notes

DriveToWeb serves other people's websites. That is the whole threat model: every request handler
that takes a path, an id or a host from the URL is a potential SSRF, and every page it renders is
a page someone else controls. This file is the summary; `ANALYSIS-REPORT.md` has the findings the
code was changed for, and `HOW-TO-USE.md` has the operator-facing walkthrough.

Nothing here is a substitute for keeping PHP, nginx, MongoDB and your OS patched, or for putting
the box on a network where only nginx can reach php-fpm and MongoDB.

## Who can do what

Read from `backend/html/index.php`; the "host" column is which `$httpHost` branch handles it.

| Host | Route | Requires | Behaviour |
|---|---|---|---|
| `api.<domain>` | `~<user>@<host>/<gd\|od>` | visitor | no site yet → redirect into the OAuth flow; with a site → `301` to `<site>.on.<domain>`, or the dashboard on `?a=admin`, or JSON `{siteName}` on `?a=siteName` |
| `api.<domain>` | `.a/<gd\|od>`, `.b/<gd\|od>` | OAuth `code` | completes sign-in, stores tokens, sets `D2WSESS`, `.b` renders the dashboard |
| `api.<domain>` | `…/@@cache`, `@@download` | **owner** | allowed on GET (idempotent), but still need the owner session or bearer token |
| `api.<domain>` | `…/@@publish`, `@@lock`, `@@unlock`, `@@dup`, `@@delete` | **owner** | POST/PUT **and** `X-D2W-CSRF` (cookie auth) **and** `^[A-Za-z0-9_\-]{10,120}$` file id. `@@publish`/`@@unlock`/`@@lock` edit the file's Drive *permissions*, `@@dup` copies it, `@@delete` deletes it in the owner's Drive |
| `api.<domain>` | `…/@@site` | **owner** | read-only: the site's address, root, approval state, bound domain and how long until it may be renamed again |
| `api.<domain>` | `…/@@rename` | **owner** | `POST`/`PUT` **and** `X-D2W-CSRF`; takes `name=`; refused (`ERR_LOCKED`) while the site is on the copyright blacklist, throttled by `$siteNameChangeGapSec` (`429` + `Retry-After`) |
| `api.<domain>` | `…/@@domain` | **owner** | `POST`/`PUT` **and** `X-D2W-CSRF`; verifies a `TXT`/`CNAME` for the submitted host by DNS lookup only - it never writes to DNS and never accepts a host that does not point back |
| `api.<domain>` | `do/siteName?name=` | anonymous | availability lookup for the naming field; answers free/taken plus the normalised candidate, nothing else |
| `api.<domain>` | `do/sendSiteAuth`, `do/checkSiteAuth` | visitor | see "Site access codes" below |
| `api.<domain>` | `@@tok`, `@@log.csv/<tok>`, `@@backroom` | operator | `401 + WWW-Authenticate` unless HTTP Basic (`$opsUser`/`$opsPass`) or a valid `X-D2W-Token` / `?tok=` from `@@tok`; tokens are `hash_hmac( $opsSecret, hour )`, valid ±1 h |
| `api.<domain>` | `POST /abuseReport` | `$apiTokens` caller | `Authorization: Bearer <token>`; unknown/missing → `401 ERR_AUTH_FAILED`; the report is inserted into `wwwdrv.abuses` with `status: 'new'` and the caller's name |
| `<site>.on.<domain>` | any path | nothing | content serving; blacklist + copyright checks first; `$requireApprovedSites` gates unapproved sites; the `@@` commands are refused without owner auth here too |
| `<domain>` (apex) | `/site/<name>/…` | nothing | `301` to the `.on.` host; `~…` → `301` to `api.`; everything else → `301` to `www.` |
| `<cdn host>` in `$d2wCdnHosts` | `/_guf/<site>/<fileId>` | validated | site name and file id both checked before anything is fetched; owner's Google token is attached server-side |

There is no `/do/register`, `/do/create`, `/do/setAccessCode`, `/do/cors_proxy` or debug query
switch in this code base — site creation happens as a side effect of sign-in
(`setSiteInfo()`), and the "Google Sheet as a database" feature is off unless
`$allowCreatePostDb` names the user (or is `TRUE`).

Privilege decisions never trust the client's hostname: `owner_auth_method( $userId, $service )`
asks for the token *stored for that user and service*, or a `D2WSESS` cookie whose HMAC, expiry
and `userId`/`service` binding all check out. `$service` is threaded into `gdNavigate()` from the
site record precisely so it cannot be guessed — an earlier revision read an undefined variable
there and answered 403 to every authenticated owner.

### Site access codes

`sendAuthCode()` and `checkAuthCode()` are the whole flow; what bounds it:

| Control | Knob | Effect |
|---|---|---|
| Send gap | `$authCodeSendGapSec` (60) | counted per site **and** per recipient (`email:<addr>` slot) |
| Code lifetime | `$authCodeTtlSec` (900) | stored hash-free in `wwwdrv.authcode` |
| Guess budget | `$authCodeMaxFails` (8) per `$authCodeRetryWindowSec` (900 s) | on trip, the code is *not compared*; `?auth=slow` + `Retry-After` |
| Recipient policy | `$blacklist_email` (undefined by default) | free-mail rejected with "provide an organizational e-mail"; `dns_get_mx` reachability check |

## Secrets and files

| What | Where | Mode | Notes |
|---|---|---|---|
| `drvtw_creds.php` | `backend/html/drvtw_private/` | `0640`, root:www-data | OAuth, session, ops and site-name secrets, DSNs, SMTP password — **never** committed |
| `/var/log/d2w` | outside the docroot | `0770`, `www-data:www-data` | the web user must be able to *enter* the directory; both wrong combinations produce an empty `user.log` |
| `cache_*/` | docroot | `0770`, `www-data:www-data` | not required, but keeps the first writes from failing |
| `nginx_*/` | `/etc/nginx/sites-enabled/` | `0644` | contains no secrets |
| `php.ini` / `phpinfo()` | — | — | `nginx_drvtw` executes only `location = /index.php`, so a stray `info.php` cannot be served; the audit tool flags regressions |

Minimum-strength rule for `sessionSecret`, `opsSecret`, `siteNameSecret` and each `$apiTokens`
entry: 32+ hex characters that are not an example value. `php tools/d2w-audit.php` checks all of
it, including whether the file is world-readable.

Rotating `$sessionSecret` invalidates every owner session and every CSRF token at once (codes
already mailed stay valid until `$authCodeTtlSec` passes); rotating `$opsSecret` logs operators out
of the backroom; both are safe to do at any time.

## What the log deliberately does not contain

`user.log` is group-readable and can be tailed over HTTP by an operator, so `d2w_putlog()` runs
`d2w_log_scrub()` on every message before it reaches disk: `ra=`/`token=`/`Authorization`/
`D2WSESS` values, `Bearer`/`Basic` material, `ya29.*` / `E…` / JWT-shaped strings, and the local
part of e-mail addresses (domains are preserved; they are what abuse triage needs). Set
`$d2wLogPrivacy = FALSE` only if you want the raw text while debugging. Visitor IPs are not
logged at all; `$_SERVER` is never dumped.

## Cookies

`D2WSESS` is the only cookie this application sets (no `d2wsites`, no third-party cookies from
the backend).

| Attribute | Value | Why |
|---|---|---|
| Value | `expires.userId.service.hmac` | `d2w_parse_session()` rejects a cookie whose HMAC, expiry or `userId.service` binding does not match, so it is unforgeable without `$sessionSecret` |
| `Domain` | `.<baseDomain>` | the dashboard and the `.on.` hosts both need to send it |
| `Path` | `/` | as above |
| `Secure`, `HttpOnly`, `SameSite=Lax` | all set | scripted pages cannot read it; a cross-site *POST from a same-site page* is still possible, which is why mutations additionally require `X-D2W-CSRF` |
| lifetime | `$sessionTtlSec` (default 14 days) | rotate `$sessionSecret` to invalidate every session at once |

The parent-domain scope is a deliberate trade-off: any hosted site can *send* the cookie, so
cookie-authenticated mutations are useless without the CSRF token, and read-only `@@cache` can be
triggered by a hostile hosted page as the owner (a wasted cache refresh, nothing more). A
deployment that wants to close even that can change `set_owner_session()` to omit the `domain`
key (host-only on `api.<domain>`); the cost is that `@@` commands issued from a
`<site>.on.<domain>` page stop working. Nothing in the shipped front-end relies on them —
`wd.js` never calls `@@cache`, only `admin.js` on the API host does.

The CSRF value is *not* a cookie: it is derived from the session by `d2w_csrf_token()` and travels
as `X-D2W-CSRF`, so it cannot be set by an attacker and dies with the session. A plain
double-submit cookie was rejected here precisely because `*.on.<domain>` makes same-site cookie
tossing trivial.

## Script execution on pages this software renders

`d2w_page_headers()` sends `Content-Security-Policy` with `script-src 'self' https:
'nonce-<random>'`. The nonce is generated per request (`d2w_csp_nonce()`) and the same value is
substituted into the page as `{{CSP_NONCE}}` on each inline `<script nonce="…">` tag, so the
rendered pages - including the visitor's access-code form in `403-authenticate.html` and the
operator `backroom.html` - keep working without `'unsafe-inline'`. Operators who set
`$d2wAppCSP` may put `{{NONCE}}` in their own policy where they want it.

A nonce authorises a `<script>` *element*, never an `onclick=` / `onchange=` *attribute*: those are
not allowed by any part of this policy, and the shipped pages bind listeners instead. Two guards in
the test suite keep it that way - every inline script in `backend/html/inline/*.html` (except
`md_wrap.html`, which is deliberately outside the policy because it renders inside a frame of the
user's own content) must carry `nonce="{{CSP_NONCE}}"`, and none may use an inline event handler.

Because `script-src` still allows `https:` origins (the pages load a chart library and Font Awesome
from CDNs), an injected attribute that breaks out of a quoted value could otherwise pull in
`<script src="https://attacker/…">`. That is why `backroom.html` - which renders *visitor-controlled*
log fields (Referer, User-Agent, request path) into a table - escapes every interpolation (`esc()`)
and filters link targets (`safeHref()`, which turns `javascript:`/`data:` into `#`).

## Response headers

Application-rendered pages: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
`Referrer-Policy: same-origin`, `X-Robots-Tag: noindex, nofollow`,
`Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()`, and a Content-Security
Policy (`$d2wAppCSP` overrides; the default allows `'self'` scripts plus `https:` script/style/font
origins, because the landing page pulls Bootstrap and font-awesome from CDNs, and keeps
`form-action`/`base-uri`/`frame-ancestors` at same-origin). Hosted content does **not** get these:
`d2w_page_headers()` skips `*.js` and `md_wrap.html` so user files keep their own semantics.

Hosted user content: `X-Content-Type-Options: nosniff` and `Cross-Origin-Resource-Policy:
cross-origin`, no CSP by default — a strict policy would break arbitrary third-party sites.
`$d2wContentCSP` opts in (e.g. `base-uri 'self'; form-action 'self'`).

MongoDB connections verify server certificates by default; `'ssl' => TRUE` is only applied to a
plain local socket when `$mongoTlsLocal = TRUE`, and `$mongoTlsCAFile` supplies a private CA.
Set `$mongoTlsAllowSelfSigned = TRUE` to go back to the old permissive behaviour — do not.

## Edge functions (`cf-lambda/`)

Fail closed: no configured allowlist, no upstream, a non-allowlisted host, a disallowed scheme, a
malformed id, an over-long path or a missing header all answer 403/400 rather than fetching. The
redirect target is rebuilt from validated pieces, so the URL from the client is never used as a
host. Responses carry no `Set-Cookie`, and no `Authorization`/`Cookie`/`X-D2W-*` header survives
a hop to a different host. `fetch` pins TLS 1.2 and does not log host, path, file id or tokens.

## If you are reporting a vulnerability

Open a [security advisory](https://github.com/jcwtw/drvtw_oss/security/advisories/new) on the
private tracker rather than a public issue. Include the version (`git rev-parse HEAD`) and the
route involved; expect an acknowledgement within a week. Do not include real access tokens,
session cookies or visitor e-mail addresses in the report.
