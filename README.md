# DriveToWeb

Host websites directly from Google Drive or Microsoft OneDrive — no traditional web server required. Users share a folder of HTML/CSS/JS files with DriveToWeb, and the service makes it accessible at a public subdomain.

**Live service:** [drv.tw](https://www.drv.tw) *(shutting down May 31, 2026)*

## How It Works

1. A user uploads their website files to a Google Drive or OneDrive folder and shares it
2. They sign in to DriveToWeb via OAuth and receive a site URL (e.g. `sitename.on.drv.tw`)
3. Visitors access the URL — DriveToWeb fetches files from the cloud storage and serves them, with multi-layer caching

## Repository Structure

```
drvtw_oss/
├── backend/          # PHP application server, nginx configs, setup scripts
├── frontend/         # Static landing site (www.drv.tw), hosted on Azure Static Web Apps
├── HISTORY.md        # Major milestones
└── README.md         # This file
```

See the README in each subfolder for setup details.

## Read these first

- **[HOW-TO-USE.md](HOW-TO-USE.md)** — install, configure and operate your own instance
  (also the user-facing guide: URLs, `@@` commands, claiming or renaming a site address and
  binding your own domain from the dashboard, private sites, troubleshooting).
- **[DEPLOY-RUNBOOK.md](DEPLOY-RUNBOOK.md)** — the ordered procedure to take a checkout to a live
  public host: DNS, wildcard TLS, php-fpm, MongoDB (including the TLS choice), credentials,
  nginx, the static site, optional CloudFront, the go/no-go checks, day-2 operations, rollback.
- **[install/README.md](install/README.md)** - the automated path: `./wizard.sh`, then
  `sudo ./install.sh`. It performs the whole deployment described below and verifies itself;
  read the runbook to know what it changes, or when something stops.
- **[SECURITY.md](SECURITY.md)** — what each route requires, where the secrets live, what the log
  deliberately does not contain, and the residual risks that were accepted rather than fixed.
- **[ANALYSIS-REPORT.md](ANALYSIS-REPORT.md)** — the problems found in this review and the
  fixes applied: unauthenticated privileged commands, an open proxy in the edge functions,
  an open redirect in the e-mail flow, a dead moderation check, hardcoded `drv.tw`
  credentials/hosts/Analytics everywhere, and the config/deploy defects. §11 covers the second
  pass (log privacy, CSRF on owner commands, the access-code guess budget, response headers,
  the deployment audit tool) and corrects two claims the first pass made too optimistically.
- **[tests/README.md](tests/README.md)** — `./tests/run-all.sh` runs lint, the PHP helper
  tests, the edge-function tests, the static-site checks and `nginx -t` offline.

This working tree is a **hardened fork**: the application logic is unchanged in intent
(same routes, same caching model, same blacklist) but the security and portability defects
above are fixed, and the front end is config-driven instead of pinned to `drv.tw`.

## Architecture

| Layer | Technology |
|---|---|
| Application | PHP 8.x, nginx, PHP-FPM |
| Database | MongoDB (local socket + MongoDB Atlas replica) |
| CDN / Edge | Cloudflare, AWS CloudFront with Lambda@Edge |
| OAuth | Google OAuth 2.0, Microsoft OAuth 2.0 |
| Cloud APIs | Google Drive API v3, Microsoft Graph API |
| Email | PHPMailer via SMTP (AWS SES) |
| SSL | Let's Encrypt via acme.sh |
| Static frontend | Azure Static Web Apps |

## Features

- Serve static websites from Google Drive or Microsoft OneDrive
- Custom subdomain per user (`sitename.on.drv.tw`)
- Custom domain support via DNS CNAME or TXT record
- Email-based visitor authentication for private sites
- Phishing and malware content scanning before serving
- Abuse reporting API
- Multi-layer cache: PHP → nginx proxy → CDN (up to 180-day TTL)
- Service worker support for progressive web apps
- Custom 404 pages
- Bilingual (English/Chinese) documentation at [docs-of.drv.tw](https://docs-of.drv.tw)
  (the original operator's site; set `$d2wContactEmail` and the `docsUrl` in
  `frontend/www.drv.tw/inc/config.js` to point your deployment's pages at your own docs)

## Known Limitations

- **Google Drive:** Google's restricted OAuth scope policy blocks new app registrations requesting broad Drive access. Existing users continue to work; new Google Drive sign-ups are disabled.
- **Phishing abuse:** The service is a recurring target for phishing campaigns. The blacklist (`drvtw_blacklist.php`) requires active maintenance.
- **Self-hosted deployments** need their own Google and Microsoft OAuth app registrations.

## Quick Start

```bash
./tests/run-all.sh          # everything checkable offline (php -l, node --check, tests, nginx -t)
cp backend/html/drvtw_private/drvtw_creds.example.php \
   backend/html/drvtw_private/drvtw_creds.php    # then fill it in
php tools/d2w-audit.php                          # what is still wrong with this deployment
```

Then follow [HOW-TO-USE.md](HOW-TO-USE.md) ("Install it as the operator") or
[backend/README.md](backend/README.md) for the per-component details. A fresh instance is
OneDrive-only in practice: Google's restricted OAuth scope policy blocks new apps that request
Drive access.

## License

MIT — see [LICENSE](LICENSE).

PHPMailer (in `backend/html/PHPMailer/`) is LGPL-2.1.
