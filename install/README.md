# Installer — do this instead of reading the runbook

Ye 3 commands poora setup kar deti hain. Neeche wala English doc detail ke liye hai;
`DEPLOY-RUNBOOK.md` sirf tab padhna jab kuch ajeeb ho.

```bash
git clone https://github.com/jcwtw/drvtw_oss.git && cd drvtw_oss/install
./wizard.sh                    # 4 sawaal, bas
sudo ./install.sh              # sab kuch; end mein ap-aap check karta hai
```

That is the whole thing. `install.sh` takes maybe 10 minutes and prints
`looks live` at the end; if anything failed it says what and where to look.

## Brand-new VPS: the first ten minutes

From your own computer, replacing `YOUR_VPS_IP` with the address the panel shows:

```bash
ssh root@YOUR_VPS_IP                      # free/entry VPSes usually hand you root + a password
passwd                                    # change that password right now, before anything else
apt update && apt-get install -y git curl ca-certificates
git clone https://github.com/jcwtw/drvtw_oss.git
cd drvtw_oss/install
./wizard.sh
./install.sh                              # you are already root, so no sudo needed
./verify.sh
```

`apt-get install -y git` is there because a minimal image has no git, and nothing else in
this repo can fetch it for you. Everything after that is the same quickstart as above; the
installer works as root without `sudo` (`sudo ./install.sh` is only the wording for boxes
where you log in as a normal user).

**Image to choose in the panel:** Ubuntu 24.04 LTS (or 22.04). Debian 12 also works - the
installer prints a one-line warning and continues. Windows Server, CentOS Stream, Rocky,
Alma, Kali, Arch: not supported, rebuild the machine.

**x86_64 or ARM64?** If the panel asks, take x86_64. ARM64 (Ampere Altra, Graviton) works,
but MongoDB publishes arm64 Ubuntu builds only for 24.04 - on 22.04 the database install
will find no package. The installer reads `dpkg --print-architecture` and writes the right
`arch=` line into the apt source, and tells you which one it is installing for.

**Disk:** the guard in preflight stops a machine with under 2 GB free before apt starts. A
"free VPS" that advertises a few hundred MB of disk (some do say 500 MB) cannot hold the OS
plus a database plus a cache - that is not a small server, it is a toy, and no amount of
configuring changes that.

### Got an RDP (Windows Server) box instead?

`install.sh` cannot run on Windows, and that is not a packaging accident: it calls apt,
`systemctl` (php-fpm, nginx, mongod), `ufw`, logrotate, rsync and acme.sh, writes
`/etc/nginx/sites-available` and a php-fpm pool, and sets POSIX ownership on the credentials
file (`0640 root:www-data`) - which the app's own audit refuses to accept any other way. PHP
and MongoDB both ship Windows builds, so the app *could* be made to run there by hand, but
nothing in this repo would do it for you, and every later update would be an afternoon in a
Remote Desktop window.

In order of usefulness:

1. **Change the image.** The panel calls it Reinstall / Rebuild / Change OS. Pick Ubuntu
   24.04, then connect over SSH (port 22) with the same root password. That is the whole fix -
   RDP was only ever the way in, not the requirement.
2. **If the image cannot be changed**, the product is wrong, not the setup. Don't pay for it
   and don't fill the form. "Windows Server + RDP + free + full root" is the shape of a
   trial-account resale or a data-collection page, not a host.
3. **Your own Windows 10/11 PC** is fine for learning, not for going live: install WSL2 and
   Ubuntu from the Microsoft Store, then inside it `git clone` this repo and run
   `./tests/run-all.sh` (the whole test suite needs only `php` and `node`, no database).
   Do not point users at it: inbound 80/443 are blocked by home ISPs, and Windows Update
   reboots the box. WSL is not available on Windows Server, so it does not rescue case 1.

`bash install/check-host.sh` says all of this on the box itself, in one line.

### Small box (1-2 GB RAM)? add swap first

MongoDB plus php-fpm on 1 GB without swap will get the OOM killer called, and the symptom
will look like "the site is random 502". Two minutes:

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

### Free or time-limited VPS: read this before you build anything

| Risk | What to do |
|---|---|
| The box **ends on a date** (a 90/120-day free trial dies silently, sometimes with the disk) | The nightly dump in `/var/backups/drvtw` is on the same disk, so it dies with the box. Copy it off weekly: `scp -r root@YOUR_VPS_IP:/var/backups/drvtw ./backups/` on your laptop, then test one restore once. Keep the domain's DNS where you can move it in 30 seconds (Cloudflare): a new VPS then means editing 5 A records and re-running `install.sh`, nothing else. |
| Region you did not pick | Streaming and file downloads are latency-sensitive. Choose the node closest to your users; a distant one is fixable only by rebuilding. |
| Port 25 blocked (most free/cheap hosts block outbound mail to stop spam) | Keep `SMTP_HOST` empty (approval stays off), or use an API-based mail service. Do not debug it for an hour: `nc -w5 -vz smtp.<provider> 25` timing out while 587 works is the host's block, not your config. |
| IPv6 | The installer adds `listen [::]` to the edge only when the box actually has a global v6 address, and prints a line telling you so. If it did, add an AAAA record for the same names; if your panel is IPv6-only, tell me before you build the DNS plan. |
| Oversubscribed shared nodes | Reboots and slow disks happen. `verify.sh` is cheap - run it after every provider maintenance window, and it will tell you what broke. |

## A laptop at home can be the server

`LAPTOP-SERVER.md` is the whole procedure for that, including the part most guides miss: a
home connection is behind CGNAT with 80/443 blocked, so DNS cannot point at your machine at
all - a Cloudflare tunnel fixes that without opening anything, and one command per site adds
the hostname (`./laptop-serve.sh host <name>`). `./laptop-serve.sh check` first, because a
2011-era CPU cannot run current MongoDB builds.

## No server and nothing to spend?

`FREE-SETUP.md` ranks what is actually free, and `./preview-tunnel.sh 8099` will give a
running install a temporary public HTTPS address with no account and no card - enough to
open the app on your phone or show someone a screen, not enough to host anyone's files.

## Already have a server? Ask it first

```bash
bash install/check-host.sh          # 20 seconds, changes nothing
```

It answers the one question that decides everything: does this machine give you
root, PHP 8 with the `mongodb` extension, a web server you can reconfigure, and
outbound HTTPS? Shared/cPanel/Plesk/"managed WordPress" hosting prints
`RESULT no-go on this host` and tells you why - this app is not a PHP script you can
upload into `public_html`, it needs a database server and its own php-fpm pool. A real
VPS prints `ready` (or a short list of things the installer will fix itself).

## What you must have ready (the installer cannot do these)

| Thing | Why only you can |
|---|---|
| A Ubuntu 22.04 or 24.04 VM, root access, ports 22/80/443 open | it's your server |
| A domain, with **DNS API access** (a Cloudflare token is easiest) | the wildcard certificate `*.on.<domain>` can only be issued through DNS |
| An Azure "App registration" for OneDrive, with redirect URIs `https://api.<domain>/.a/od/` **and** `/.b/od/ | sign-in has to be created at the identity provider |
| SMTP login (only for private sites) | mail accounts are not something we can mint |
| `git` on the box | the installer copies the app out of that checkout |

If you do not have SMTP yet: leave `SMTP_HOST` empty. The installer then sets
`$requireApprovedSites = FALSE`, so every shared folder is served without e-mail verification.
Turn it on later by editing `/var/www/drvtw/html/drvtw_private/drvtw_creds.php` — no restart needed.

## What each script does

| Script | Command | Needs root |
|---|---|---|
| `wizard.sh` | asks the questions, writes `install/config.env` | no |
| `install.sh` | installs PHP-FPM + pool, the log folder, MongoDB, the code, `drvtw_creds.php`, nginx (TLS edge + app on loopback), the certificate via acme.sh, the static site, ufw, logrotate, nightly dump; then verifies | yes |
| `deploy.sh` | later updates: `git pull` → tests → `rsync` → reload → `verify.sh` | yes |
| `verify.sh` | the go/no-go list only, PASS/FAIL per check, changes nothing | no |

Useful flags:

```bash
sudo ./install.sh --dry-run            # print the plan, touch nothing
sudo ./install.sh --only nginx         # redo one phase (fpm, creds, tls, static, …)
sudo ./install.sh --from static        # redo everything from that phase on
sudo ./install.sh --force-creds        # regenerate drvtw_creds.php (backs the old one up first)
sudo ./install.sh --only mongodb       # DB + indexes only
./install.sh --selftest                # render every file it would write and lint them (no root)
./verify.sh --deep                     # also sign in as a real owner and prove the rename guards
```

Re-running `install.sh` is normal. It keeps the secrets it generated in
`/etc/drvtw/secrets.env` (so sessions stay valid), backs up any file it replaces, and never
overwrites your credentials unless you say `--force-creds`.

## Files it owns

```
/etc/drvtw/config.env                 your answers
/etc/drvtw/secrets.env                generated passwords, session secret (0600)
/etc/php<X>/fpm/pool.d/drvtw.conf     own pool, socket /run/php/drvtw-<X>.sock, D2W_LOG_FOLDER
/etc/nginx/sites-available/drvtw-edge TLS on 80/443, proxies to 127.0.0.1:8000
/etc/nginx/sites-available/drvtw-app  the shipped backend/nginx_drvtw, patched: loopback listen,
                                      our socket, root = <APP_DIR>/html
/etc/mongod.conf                      loopback only, no TLS, auth on  (matches $mongoTlsLocal=FALSE)
/etc/logrotate.d/drvtw
/etc/cron.d/drvtw-backup              nightly mongodump, 21 days kept
/var/www/drvtw/                       the app (backend/ copied here; docroot is html/)
/var/www/drvtw/html/drvtw_private/drvtw_creds.php     0640 root:www-data
/var/www/drvtw/html/www.<domain>/     the static landing site, config.js rewritten for you
```

Everything it writes is marked `managed by drvtw install.sh`, and a file it finds there that it did
not create is copied to `<name>.old.<timestamp>` first.

## After it finishes

1. Open `https://www.<your domain>` → connect OneDrive → you land on the dashboard.
2. The **Your website** card shows your new address; *Change address* is how you claim the name you
   want, and *Bring your own domain* prints the one TXT record to publish. No operator action needed.
3. `sudo tail -f /var/log/drvtw/user.log` while you click around.
4. `./verify.sh` any time. It re-runs the audit tool (`tools/d2w-audit.php`), the header checks, the
   TLS date, the DB indexes and the naming API.

## When something is wrong

| Symptom | Look here |
|---|---|
| `install.sh` stops with `STOP ...` | the message says which value or file; nothing half-applied is dangerous — fix it and re-run |
| browser says "not secure" | certificate phase failed: `sudo grep acme /var/log/acme.sh/.acme.sh.log`, and DNS for `api.<domain>` |
| sign-in page loads but OneDrive errors | the redirect URIs in Azure must be `https://api.<domain>/.a/od/` **and** `/.b/od/` exactly |
| sites return 502 | `sudo systemctl status php<X>-fpm` and `sudo ls -l /run/php/` (socket must exist), then `sudo ./install.sh --only nginx` |
| no log lines | `/var/log/drvtw` must be `0770` and owned `www-data:adm`, and the pool must carry `env[D2W_LOG_FOLDER]` |
| "Access Denied" from Microsoft after sign-in | `$odClientId` in creds must be the same app as `microsoftClientId` in `config.js` (both are written from your answers; check `./verify.sh`) |
| MongoDB refused | `$mongoLocalConnection` and `/etc/mongod.conf` must agree: this install uses plain loopback, no TLS |
| you want the CDN / file cache too | `backend/nginx_proxy` + `backend/nginx_drvpx`, or CloudFront — see `DEPLOY-RUNBOOK.md` §10 |

## Taking it back down

```bash
sudo rm -f /etc/nginx/sites-enabled/drvtw-edge /etc/nginx/sites-enabled/drvtw-app
sudo systemctl reload nginx ; sudo rm -f /etc/cron.d/drvtw-backup /etc/logrotate.d/drvtw
sudo rm -rf /var/www/drvtw            # data is in /var/lib/mongodb - dump it first
```
Your credentials and the database are not deleted by any script here.
