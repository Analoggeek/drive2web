# Tests

Everything here runs offline — no MongoDB, no network, no AWS.

| Command | What it exercises |
|---|---|
| `./tests/run-all.sh` | everything below, plus `php -l` on all PHP and `node --check` on all shipped JS |
| `php tests/run-tests.php` | the helpers in `backend/html/drvtw_private/drvtw_util.php` (redirect safety, CORS origin, e-mail/MX check, file-id and site-name validation, blacklist matching, encoding/accept headers, ops token), the logger in `drvtw_log.php`, and tokenizer-based wiring checks against `index.php` |
| `node tests/test-lambda.js` | the four `backend/cf-lambda` handlers, called exactly as CloudFront calls them. `D2W_LAMBDA_DIR=<dir> node tests/test-lambda.js` runs the same suite against another copy (used to confirm the pre-fix code fails it) |
| `node tests/test-admin-regex.js` | the URL matcher in `frontend/www.drv.tw/inc/admin.js`, extracted from the shipped file |
| `node tests/test-static-site.js` | the static pages carry no leftover hosts/e-mails/analytics ids, `{{PLACEHOLDER}}`s are wired to `config.js`/`wd.js`, and every script the admin page loads exists in the repo |
| `python3 tests/check-edge-contract.py` | builds proxied URLs with **PHP** using the same composition as `index.php`, then feeds them to the **real** edge handlers through node — catches one-sided changes |
| `./tests/laptop-serve-check.sh` | `install/laptop-serve.sh` against a stubbed `cloudflared`: which names it accepts, the ingress rule landing above the catch-all, `--dry-run` really changing nothing, and the "I cannot do this here" refusals |\n| `./tests/nginx-lint.sh` | `nginx -t` on `backend/nginx_drvtw`, `nginx_proxy`, `nginx_drvpx` with cache dirs, the fastcgi snippet and TLS certificates stubbed |

`tests/run-tests.php` exits 1 on failure, so CI (` .github/workflows/deploy-frontend.yml`)
can gate on it. If `drvtw_private/drvtw_creds.php` exists it is loaded first, so tests run
against your real configuration; otherwise `drvtw_creds.example.php` is used.

Not covered here (needs a live deployment): real OAuth round trips, MongoDB read/write
behaviour, and end-to-end content serving. `HOW-TO-USE.md` §"Sanity checks" has curl commands
for those.
