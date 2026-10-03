# Deploy it live — step by step

Everything below is the sequence to take this repository (with the two hardening commits:
`64b2d3b` first pass, `54aa06b` + `dbf2515` second pass) from a checkout to a public
DriveToWeb host, plus the day-2 operations. Commands are written for Ubuntu 22.04 with nginx and
php8.1-fpm; adjust the PHP version in one place (`$PHPVER`) and it follows.

Read `HOW-TO-USE.md` for *what* each knob means and `SECURITY.md` for the auth model; this file is
only the order of operations and the checks that say you may continue.

Two rules that make the whole thing safer than it looks:

1. **Never edit `/var/www/drv_tw` by hand.** Edit the checkout, run `./tests/run-all.sh`, then
   copy. The checkout is also where `tools/d2w-audit.php` lives.
2. **`drvtw_creds.php` is not in the copy** — it is git-ignored, and `nginx_drvtw` denies the
   directory anyway. Create it on the server, mode `0640`, owner `root:www-data`.

---

> **Do not want to run all of this by hand?** `install/install.sh` automates sections 3-12
> (packages, php-fpm, MongoDB and its indexes, the code, credentials, nginx, the wildcard
> certificate, the static site, firewall, logrotate, backups) and then re-runs the checks in
> section 12. Three commands, and the only file you edit is `install/config.env`: see
> [`install/README.md`](install/README.md). This runbook stays the reference for what that
> script does, why, and what to do when it stops.

## 0. Decide three things first

| Decision | Choices | Consequence |
|---|---|---|
| Which cloud | **OneDrive only** (recommended) or OneDrive + Google Drive | Google restricts new OAuth apps requesting Drive scopes (since Sep 2025): if your app is unverified, visitors get a "app not verified" wall. Everything in this repo works with `'od'` alone. |
| Where content is streamed from | origin only, **nginx proxy** (`nginx_proxy` + `nginx_drvpx`), or CloudFront + `cf-lambda/` | Without a file proxy, `/_guf`/`/_od` streaming (audio/video, big files, Range requests) goes through PHP and will be slow. The proxy configs are shipped and lint-tested; CloudFront additionally needs the four Lambda@Edge functions. |
| Public or private | `$requireApprovedSites = FALSE` (serve everything) or `TRUE` (owners must verify by e-mail before their site serves) | `TRUE` needs working SMTP *and* the moderation blacklist to matter. Use `TRUE` for anything on the open internet. |

Fill in these values once and use them everywhere below:

```bash
DOMAIN=example.org                 # base domain; sites become <name>.on.$DOMAIN
API_HOST=api.$DOMAIN
WWW_HOST=www.$DOMAIN
CDN1=cdn-of.$DOMAIN                # skip both CDN_* if you chose "origin only"
CDN2=cdn2-of.$DOMAIN
APP=/var/www/drv_tw                # deployment root (docroot is $APP/html)
REPO=$HOME/drvtw_oss               # the checkout you test from
PHPVER=php8.1
SOCK=/run/php/$PHPVER-fpm.sock
```

## 1. Pre-flight inventory (do not start without these)

- [ ] A VM: 2 vCPU / 4 GB, ≥40 GB, Ubuntu 22.04, ports 80/443 reachable, everything else not.
      Not sure an existing host qualifies? `bash install/check-host.sh` on it answers in 20 seconds
      (shared/cPanel hosting will say no-go, and it is right: MongoDB + your own php-fpm pool + a
      vhost that answers for any `<name>.on.<domain>` are not optional).
- [ ] A DNS zone you can update **via API** (for the wildcard DNS-01 certificate).
- [ ] Wildcard-capable hostnames: `$DOMAIN`, `*.$DOMAIN`, `*.on.$DOMAIN` (the last one is what
      makes hosting sites work; it is not optional).
- [ ] An Azure "App registration" for OneDrive with redirect URIs
      `https://$API_HOST/.a/od/` **and** `https://$API_HOST/.b/od/` (both are used: `.a` is the
      popup flow, `.b` the dashboard flow).
- [ ] Optional Google Cloud OAuth client with redirect URIs `https://$API_HOST/.a/gd/` and
      `/.b/gd/`, plus an API key restricted to the Drive API.
- [ ] SMTP credentials whose host you can reach from the VM (port 587 egress), with the ability to
      send as `$DOMAIN`.
- [ ] A MongoDB (same box, or a second box you can reach over a private network / Atlas).
- [ ] The patch, if you cloned upstream yourself: `drvtw_oss-all.patch` carries every review
      commit on top of the `0bca345` import (`git apply --check` it before you trust the copy).

## 2. DNS

```
A      @                <VM_IP>
A      api              <VM_IP>
A      www              <VM_IP>
A      *                <VM_IP>        # only if you use <site>.on for custom domains
A      *.on             <VM_IP>
A      cdn-of           <VM_IP>        # or the CloudFront alias target
A      cdn2-of          <VM_IP>
```

Set the TTL of `*.on` low (60 s) for the first week — it is the record you may have to move fast.
Check before continuing: `dig +short api.$DOMAIN A` and `dig +short anything.on.$DOMAIN A`.

## 3. TLS certificate (wildcard, DNS-01)

```bash
curl https://get.acme.sh | sh -s email=admin@$DOMAIN
export CF_Token='…'                    # or your provider's vars: see acme.sh wiki dnsapi
~/.acme.sh/acme.sh --server letsencrypt --issue \
    -d "$DOMAIN" -d "*.$DOMAIN" -d "*.on.$DOMAIN" --dns dns_cf --log
sudo mkdir -p /etc/ssl
~/.acme.sh/acme.sh --install-cert -d "$DOMAIN" --ecc \
    --fullchain-file /etc/ssl/d2w-fullchain.cer --key-file /etc/ssl/d2w.key \
    --reloadcmd "service nginx reload"
sudo chmod 0600 /etc/ssl/d2w.key
```

acme.sh installs a cron entry that renews and re-installs — `grep acme /etc/crontab` to confirm.
Do **not** use HTTP-01 here: `*.on.$DOMAIN` cannot answer it.

## 4. nginx, PHP-FPM, the log folder

```bash
sudo apt update
sudo apt install -y nginx $PHPVER-fpm $PHPVER-mongodb $PHPVER-mbstring $PHPVER-curl $PHPVER-dom git
php -m | grep -E 'mongodb|mbstring|curl|dom'      # all four must print
```

Pool file `/etc/php/8.1/fpm/pool.d/www.conf`:

```ini
pm = static
pm.max_children = 64          ; 4 GB box; 256 on a bigger one
pm.max_requests = 500
slowlog = /var/log/php/php-$pool.log.slow
request_slowlog_timeout = 60s
pm.status_path = /php-fpm-status
env[D2W_LOG_FOLDER] = /var/log/d2w
```

```bash
sudo mkdir -p /var/log/php /var/log/d2w
sudo chown www-data:www-data /var/log/php /var/log/d2w
sudo chmod 0770 /var/log/d2w
sudo systemctl restart $PHPVER-fpm
ls -l /run/php/                       # $SOCK must exist
```

`0770` on the log folder is the number-one install bug: the folder must be *searchable* by
`www-data`, and `0640` (a file mode someone copied onto a directory) makes every log write fail
silently. `tools/d2w-audit.php` checks exactly this and will tell you.

## 5. MongoDB

```bash
wget -qO - https://www.mongodb.org/static/pgp/server-7.0.asc | sudo gpg --dearmor -o /usr/share/keyrings/mongodb.gpg
echo "deb [ arch=amd64 signed-by=/usr/share/keyrings/mongodb.gpg ] https://repo.mongodb.org/apt/ubuntu jammy/mongodb-org/7.0 multiverse" \
  | sudo tee /etc/apt/sources.list.d/mongodb-org-7.0.list
sudo apt update && sudo apt install -y mongodb-org
sudo systemctl enable --now mongod
```

`setup/mongod/init.js` creates the admin user, the limited app user, **and the indexes** —
including the TTL index on `authfail` that backs the access-code lockout. Run it from the checkout
(it asks for passwords with `passwordPrompt()`), and re-run the same file after step 6 when the
copy under `$APP/setup/` exists; it is idempotent:

```bash
mongosh < $REPO/backend/setup/mongod/init.js
```

Then verify:

```bash
sudo mongosh --quiet --eval 'db.adminCommand({listDatabases:1}).databases.forEach(d=>print(d.name))'
sudo mongosh --quiet --authenticationDatabase admin -u drvuser -p --eval \
  'use wwwdrv; db.getCollectionNames().join(", "); db.authfail.getIndexes().map(i=>i.name).join(", ")'
```

Expected: collections `sites`, `authcode`, `authfail`, `cache_gd`, `accessTokens`, `abuses`, and an
index on `authfail` named `ts_1` with `expireAfterSeconds: 3600`.

### TLS for MongoDB — pick one, and match the app to it

| Layout | mongod.conf `net.tls.mode` | creds |
|---|---|---|
| Mongo on the same box, app connects over `/tmp/mongodb-27017.sock` | `disabled` | nothing (defaults: `$mongoTlsLocal = FALSE`) |
| Same box but you want TLS anyway | `requireTLS` | `$mongoTlsLocal = TRUE ; $mongoTlsCAFile = '/etc/ssl/mongodb-ca.pem' ;` **and** the server cert must be signed by that CA and name the host in the DSN (`verify_peer_name` is on) |
| Atlas / another box | server-managed | `$mongoTlsCAFile` only for a private CA; never set `$mongoTlsAllowSelfSigned` unless you mean it |

The old shipped `mongod.conf` says `requireTLS` with a self-signed certificate. If you keep that,
the app **must** get `$mongoTlsLocal = TRUE` plus a CA file — with the defaults the socket
connection is refused. Generating a usable pair:

```bash
sudo openssl req -new -x509 -days 3650 -nodes -out /etc/ssl/mongodb-ca.pem \
    -keyout /etc/ssl/mongodb-ca.key -subj "/CN=DriveToWeb Mongo CA"
sudo openssl req -new -nodes -newkey rsa:2048 -keyout /etc/ssl/mongodb.pem \
    -out /tmp/mongo.csr -subj "/CN=localhost"
printf "subjectAltName=DNS:localhost,IP:127.0.0.1\n" > /tmp/san.cnf
sudo openssl x509 -req -in /tmp/mongo.csr -CA /etc/ssl/mongodb-ca.pem -CAcreateserial \
    -days 1500 -extfile /tmp/san.cnf | sudo tee -A /etc/ssl/mongodb.pem   > /dev/null
sudo chown mongod:mongod /etc/ssl/mongodb.pem && sudo chmod 0600 /etc/ssl/mongodb.pem
# mongod.conf: tls.certificateKeyFile: /etc/ssl/mongodb.pem, mode: requireTLS
sudo systemctl restart mongod
```

`/tmp/mongodb-27017.sock` is created `0777` by the shipped conf. With `authorization: enabled`
that is tolerable; if you want it tight, `filePermissions: 0770` and `sudo usermod -aG mongod
www-data`.

## 6. Code onto the box

```bash
cd $HOME && git clone https://github.com/jcwtw/drvtw_oss.git    # already contains the fixes
#   … or apply them to your own clone of the import commit:
git checkout 0bca345 && git apply $HOME/drvtw_oss-all.patch && git switch -c hardened

cd drvtw_oss && ./tests/run-all.sh        # ALL CHECKS PASSED before anything is copied
sudo mkdir -p $APP
sudo rsync -a --delete $HOME/drvtw_oss/backend/ $APP/
sudo chown -R $USER:www-data $APP
sudo find $APP -type d -exec chmod 0750 {} \;
```

`--delete` is fine here because `$APP/html/drvtw_private/drvtw_creds.php` is the only file that is
not in git and the only one that matters — copy it aside first if you are re-deploying:

```bash
sudo cp -a $APP/html/drvtw_private/drvtw_creds.php $HOME/creds.backup.$(date +%F)
```

## 7. Credentials

```bash
sudo install -o root -g www-data -m 0640 \
    $APP/html/drvtw_private/drvtw_creds.example.php $APP/html/drvtw_private/drvtw_creds.php
# (owner $USER:www-data is equally fine - what matters is 0640 and that "other" cannot read it)
sudo $EDITOR $APP/html/drvtw_private/drvtw_creds.php
```

What to change, in the order the audit tool complains about it:

| Key | Value |
|---|---|
| `$d2wBaseDomain` / `$d2wApiHost` / `$d2wWebHost` | `$DOMAIN`, `$API_HOST`, `$WWW_HOST` |
| `$d2wCdnHosts` | `array( CDN1, CDN2 )`, or `array()` if you skipped the proxy |
| `$d2wWidgetScript` | `https://$WWW_HOST/inc/wd.js` |
| `$sessionSecret` | `php -r 'echo bin2hex(random_bytes(32)),"\n";'` — **NULL disables cookie auth**, and then the dashboard's Site tools and every `@@` command need a bearer token |
| `$opsSecret`, `$opsUser`, `$opsPass` | same generator; a strong password for `@@backroom` |
| `$siteNameSecret` | leave `NULL` (random site names) |
| `$odClientId` / `$odClientSecret` | the Azure app |
| `$gdClientId` / `$gdClientSecret` / `$gdApiKey` | only if Google approved the app |
| `$mongoLocalConnection` | `mongodb://drvuser:<pw>@%2Ftmp%2Fmongodb-27017.sock/wwwdrv` (note the URL-encoded socket path) |
| `$mongoRemoteConnections` | Atlas / second box, or `array()` for a single-node install |
| `$smtpHost` / `$smtpPort` / `$sesSmtpUser` / `$sesSmtpPassword` / `$mailFrom` | your SMTP |
| `$requireApprovedSites` | `TRUE` for a public host |
| `$siteNameChangeGapSec`, `$d2wReservedSiteNames` | how often an owner may move their site (default 3600 s; `0` = no limit - keep it non-zero on a public install) and any extra names that must never be handed out; the deployment's own hostnames are reserved automatically |
| `$authCodeMaxFails`, `$authCodeSendGapSec`, `$authCodeTtlSec`, `$sessionTtlSec` | defaults are sane; the guess guard cannot be turned off (values are clamped to ≥1 attempt, ≥60 s window) |
| `$mongoTlsLocal`, `$mongoTlsCAFile`, `$mongoTlsAllowSelfSigned` | only per the table in step 5 |
| `$d2wLogPrivacy`, `$d2wAppCSP`, `$d2wContentCSP` | leave as shipped unless you have a reason |

Every request re-reads this file, so you do **not** need to restart PHP for a creds change — but
opcache may hold `index.php`, so after a *code* deploy run `sudo systemctl reload $PHPVER-fpm`.

Now let the tool read your work:

```bash
php $REPO/tools/d2w-audit.php --root $APP ; echo "exit=$?"
```

`exit=0` (or `0` with warnings) is the gate. `exit=2` prints the exact key that is still a
placeholder. `exit=3` means the path is wrong (`--root` takes `$APP` or `$APP/html`).

## 8. nginx

```bash
sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -sf $APP/nginx_drvtw /etc/nginx/sites-enabled/
sudo ln -sf $APP/nginx_proxy /etc/nginx/sites-enabled/     # if you run the cache proxy
sudo $EDITOR $APP/nginx_drvtw        # 1) fastcgi_pass $SOCK  2) set_real_ip_from <your proxies> only
sudo $EDITOR $APP/nginx_proxy        # 3) ssl_certificate /etc/ssl/d2w-fullchain.cer + key
                                     # 4) upstream d2w_backend -> app box:8000
sudo nginx -t && sudo systemctl reload nginx
```

Three points of emphasis:

* `set_real_ip_from 0.0.0.0/0` is the one line that silently breaks the rate limits, the abuse
  reports and your logs — every visitor picks their apparent IP. The audit tool errors on it.
* Only `location = /index.php` may execute PHP. Keep it that way; `phpinfo()` in a docroot is a
  configuration dump for anyone who asks.
* `$APP/nginx_drvpx` (the CDN-facing proxy) still contains rewrites for the old service
  (`www.drv.tw/_gdc/`, `doc-..-docs.googleusercontent.com`). If you use it, replace `www.drv.tw`
  with `$WWW_HOST` inside it; if you use CloudFront instead, do not enable it.

## 9. Static front-end

```bash
sudo $EDITOR $REPO/frontend/www.drv.tw/inc/config.js
#   googleClientId / microsoftClientId : the SAME apps as in creds
#   siteSuffix: '.on.' . $DOMAIN        <- it ships as '.on.drv.tw'; every site link is built
#                                          from this, so forgetting it sends visitors to the old host
#   contactEmail: you@…, analyticsId: null, docsUrl: your docs
sudo rsync -a $REPO/frontend/www.drv.tw/ $APP/html/$WWW_HOST/
sudo chown -R $USER:www-data $APP/html/$WWW_HOST
```

`nginx_drvtw` resolves requests with `try_files /$host$uri …`, so the static site must live in a
directory named exactly like the host you serve it on (`$APP/html/www.example.org`).

Those static files never reach PHP, so they get **none** of the security headers described in
`SECURITY.md` (the default CSP, `nosniff`, `X-Frame-Options`). If you want them, add to
`nginx_proxy`'s `server` block:

```nginx
add_header X-Content-Type-Options nosniff always;
add_header Referrer-Policy "same-origin" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; connect-src 'self' https:; form-action 'self'; frame-ancestors 'self'" always;
```

(`'unsafe-inline'` for scripts/styles is required: `landing.js` and the widget set inline styles.)

Two copies of the dashboard script exist on purpose: `$REPO/frontend/www.drv.tw/inc/admin.js`
(the source, also used by the Azure Static Web Apps workflow) and
`$APP/html/$WWW_HOST/inc/admin.js` (what browsers get). Re-run the rsync after editing, or make
`backend/html/www.drv.tw` a symlink target of your choice — just don't let them drift.

The pages served by `index.php` itself (dashboard, error pages) come from `$APP/html/inline/*.html`
and need no build step; `{{CSRF}}`, `{{API_URL}}` and `{{CONTACT_EMAIL}}` are substituted at
request time.

## 10. Optional: CloudFront + Lambda@Edge

Required only if you chose CDN streaming. Each handler is `exports.handler` in its own file, so
the Lambda handler is `<file-basename>.handler` — no renaming to `index.js`.

```bash
cd $REPO/backend/cf-lambda && node --check *.js && node ../../tests/test-lambda.js   # 41 passed
for f in guf_requestor guf_responder opf_requestor opf_responder; do
  zip -q -r /tmp/$f.zip $f.js
  aws lambda create-function --region us-east-1 --function-name d2w-$f \
     --runtime nodejs18.x --handler $f.handler --zip-file fileb:///tmp/$f.zip \
     --role arn:aws:iam::<ACCOUNT>:role/lambda-edge-role --timeout 10 --memory-size 128 \
     --environment "Variables={GOOGLE_API_KEY=<key for guf_requestor only>}"
done
```

Then, per distribution: publish a **version** of each function (Lambda@Edge rejects `$LATEST`),
attach `d2w-guf_requestor:<ver>` to *viewer-request* and `d2w-guf_responder:<ver>` to
*origin-response* on the `$CDN1` distribution, the `opf_*` pair on `$CDN2`, set
`MinProtocolVersion: TLSv1.2_2019` on every behaviour, and forward *all* query strings (the reversed
token rides in `ra` for Google, the OneDrive target in `ur`; cache-key behaviour is decided by the
edge code, not by CloudFront's per-behaviour query-string list).

The edge role needs `lambda:InvokeFunction@edgelambda.amazonaws.com` + `AWSLambdaBasicExecutionRole`
in us-east-1, and `aws cloudfront wait distribution-deployed --id <E123…>` between changes.

If any of this fails, the failure mode is 403 from the edge, and `user.log` on the origin will show
`RESP 302 forward CDN1 …` lines with the scrubbed token — i.e. the origin did its part.

## 11. Firewall, rotation, backups

```bash
sudo ufw default deny incoming
sudo ufw allow OpenSSH && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw enable
sudo ss -ltnp | grep -E ':8000|:27017'   # both must be bound to 127.0.0.1 / the socket only
sudo cp $APP/setup/logrotate.d/d2w /etc/logrotate.d/d2w && sudo logrotate -d /etc/logrotate.d/d2w
sudo mkdir -p /home/$USER/mongojobs && sudo cp $APP/setup/mongod/jobs/*.sh /home/$USER/mongojobs/
sudo vi /home/$USER/mongojobs/backup-d2w.sh         # set the MONGO_* env lines
(crontab -l 2>/dev/null; echo '2 0 * * * /home/'$USER'/mongojobs/backup-d2w.sh 2> /tmp/backup-d2w.log') | crontab -
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
```

`backup-d2w.sh` dumps `wwwdrv` (minus `cache_gd`/`accessTokens`, which are rebuildable) and pushes
to Atlas. If you have no Atlas, delete the `mongorestore` lines — but then add a `gzip` to a
second disk. Note its `--tlsInsecure`: that flag is about *its* connection, not the app's; switch
it to `--sslCAFile /etc/ssl/mongodb-ca.pem` when your Mongo uses a CA.

## 12. Go/no-go checks (run all of these; expected results in comments)

```bash
php $REPO/tools/d2w-audit.php --root $APP ; echo "exit=$?"          # exit=0
curl -sI http://$API_HOST/  | head -1                                # 302 (upgrade to https)
curl -sI https://$API_HOST/ | head -1                                # 301 -> https://www.$DOMAIN/
curl -sI https://$API_HOST/ | grep -i '^location'                    # ...carries the Location above
curl -sI https://$API_HOST/drvtw_private/drvtw_creds.php | head -1   # HTTP/2 404
curl -sI https://nothing.on.$DOMAIN/ | grep -ic 'content-security-policy\|x-content-type-options'
                                                                     # 2: the PHP-rendered 404 carries them
curl -s  https://nothing.on.$DOMAIN/ | head -3                       # a 404 page on YOUR domain
curl -sI https://$WWW_HOST/ | grep -i content-security || true       # EMPTY, and that is expected:
curl -s  "https://$API_HOST/~nobody@x.com/od/@@delete/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs" -X POST
                                                                     # {"error":"ERR_AUTH_REQUIRED",…}
sudo tail -3 /var/log/d2w/user.log                                   # CSV lines, no e-mails, no tokens
```

Confirm the hardened code — not a stale copy — is what nginx executes:

```bash
sudo -u www-data php -r 'require "'$APP'/html/drvtw_private/drvtw_util.php";
  var_dump(function_exists("d2w_csrf_token"), function_exists("d2w_log_scrub"),
           function_exists("d2w_attempt_policy"));'                 # bool(true) x3
grep -c "verify_peer" $APP/html/index.php                            # 2 (the opt-out paths), and
grep -c "allow_self_signed => TRUE" $APP/html/index.php               # 0 — verification is on
```

The sign-in round trip (do this from a normal browser window, not a private one, so you keep the
cookie):

1. `https://$WWW_HOST/` → sign in with OneDrive → allow access.
2. You land on `https://$API_HOST/~you@outlook.com/od/?a=admin` — the dashboard, listing your sites.
3. Note the site name shown, then open `https://<site>.on.$DOMAIN/` → your root folder's
   `index.html` renders.
4. In the dashboard, **Site tools → Publish** a file id, then **Refresh cache**: both must report
   `HTTP 200`. If the panel says *"this page carries no CSRF token"*, `$sessionSecret` is NULL or
   the cookie was not accepted for this host.
5. Kill-switch test: a *valid-looking* owner session but no CSRF header must be refused. Use the
   real site owner's `userId` (24 hex) from `db.sites`, and any non-destructive mutator:

```bash
UID=$(sudo mongosh --quiet --eval 'printjson(db.getSiblingDB("wwwdrv").sites.findOne({name:"<site>"}).userId)' | tr -d '"')
curl -s -X POST --cookie "D2WSESS=$SESS" \
     "https://$API_HOST/~you@outlook.com/od/@@lock/<a-file-id-you-own>"
                                                                     # {"error":"ERR_CSRF_REQUIRED",…}
curl -s -X POST --cookie "D2WSESS=$SESS" -H "X-D2W-CSRF: $CSRF" \
     "https://$API_HOST/~you@outlook.com/od/@@lock/<same file id>"   # HTTP 200-ish JSON, no error
```

To mint `$SESS` for a test without a browser (the value is a real session — treat it like one):

```bash
SESS=$(sudo php -r 'require "'$APP'/html/drvtw_private/drvtw_creds.php";
  require "'$APP'/html/drvtw_private/drvtw_util.php";
  echo d2w_make_session( "<24-hex userId from db.sites>", "od", 900 );')
CSRF=$(php -r 'require "'$APP'/html/drvtw_private/drvtw_creds.php";
  require "'$APP'/html/drvtw_private/drvtw_util.php";
  echo d2w_csrf_token( getenv("SESS") );' )   # export SESS first
```

Access-code path (needed when `$requireApprovedSites = TRUE`):

```bash
curl -s "https://$API_HOST/do/sendSiteAuth?site=<site>&email=you@$DOMAIN"   # {"tip":…} or {"error":"Wait"…}
for i in 1 2 3 4 5 6 7 8 9; do curl -s "https://$API_HOST/do/checkSiteAuth?site=<site>&code=00000$i&url=https://<site>.on.$DOMAIN/" -o /dev/null -w '%{http_code} '; done
# nine 302s; the 9th carries Location: …?auth=slow and Retry-After: <n>
sudo mongosh --quiet --eval 'db.getSiblingDB("wwwdrv").authfail.findOne()'    # { _id: "<site>", fails: 9, ts: … }
sudo mongosh --quiet --eval 'db.getSiblingDB("wwwdrv").authfail.deleteMany({})'  # un-lock for real users
grep "SITEAUTH" /var/log/d2w/user.log | tail -4    # "throttled site=… fails=9", e-mails scrubbed
```

Naming and custom domains (owner-facing; uses `$SESS` + `$CSRF` as minted above):

```bash
curl -s "https://$API_HOST/do/siteName?name=caf%C3%A9"      # {"available":false,"tip":"Use only the letters a-z, digits and hyphens.",…} - accents refused, not transliterated
curl -s "https://$API_HOST/do/siteName?name=my-site"        # {"available":true,"name":"my-site","tip":"Free."}
curl -s "https://$API_HOST/do/siteName?name=www"            # 400 - reserved names are refused before any lookup
curl -s -H "Cookie: D2WSESS=$SESS" "https://$API_HOST/~$UID@$SITE.on.$DOMAIN/gd/@@site"   # {ok:true,siteName,url,approved,domain,renameIn,txt:…}
curl -s -X POST -H "Cookie: D2WSESS=$SESS" -H "X-D2W-CSRF: $CSRF" -d 'name=my-site' \
     "https://$API_HOST/~$UID@$SITE.on.$DOMAIN/gd/@@rename"                              # {ok:true,siteName:"my-site",url:…}; the old address 404s from now on
curl -s -X POST -H "Cookie: D2WSESS=$SESS" -H "X-D2W-CSRF: $CSRF" -d 'domain=www.example.com' \
     "https://$API_HOST/~$UID@my-site.on.$DOMAIN/gd/@@domain"                            # 404 ERR_NOT_YET until the TXT record answers, then {ok:true,matched:true}
grep "SITE renamed\|SITE domain verified" /var/log/d2w/user.log | tail -4   # both are logged, no secrets in the lines
```

A `403 ERR_AUTH_REQUIRED` there means the session cookie did not identify the site owner, and
`403 ERR_CSRF_REQUIRED` means the header was missing - neither touched the database. If the site is
on the copyright blacklist, `@@rename` answers `403 ERR_LOCKED`: renaming must not become a way to
escape an abuse block (the block is keyed by host+path of the *old* name).

Only after all of that: open the firewall to the world / publish DNS, and watch
`sudo tail -f /var/log/d2w/user.log` for ten minutes.

## 13. If you are migrating from an existing install

```bash
sudo mongodump --db wwwdrv --archive=/tmp/d2w-$(date +%F).gz --gzip   # on the old box
sudo mongorestore --db wwwdrv --drop --gzip --archive=/tmp/d2w-…      # on the new one
```

The documents carry `userId` values that reference the **old** OAuth apps' tokens; every user must
sign in once again to mint tokens for your client id (nothing else in `wwwdrv` needs touching —
`cache_gd` and `accessTokens` will refill, and `authfail` simply starts empty). Then:

- re-issue `$sessionSecret` (old `D2WSESS` cookies stop working — that is the point),
- if the old site names were generated with a `$siteNameSecret`, keep that secret or names change,
- re-check `$d2wCdnHosts` + the CloudFront aliases before you cut DNS.

## 14. Day-2 operations

| Task | How |
|---|---|
| Deploy a change | edit in `$REPO` → `./tests/run-all.sh` → `rsync -a $REPO/backend/html/ $APP/html/` (never `--delete` on the docroot) → `php $REPO/tools/d2w-audit.php --root $APP` → `sudo systemctl reload $PHPVER-fpm` |
| Rotate a secret | `$opsSecret` logs operators out of the backroom; `$sessionSecret` logs every owner out and invalidates all CSRF tokens; `$siteNameSecret` changes future generated names only |
| Unblock a site's access codes | `db.authfail.deleteOne({_id:'<site>'})` |
| Revoke a user | `db.accessTokens.deleteMany({userId:'<id>'})` + `db.sites.updateOne({name:'<site>'},{ $set:{ bAuthenticated:false } })` (with `$requireApprovedSites` on, that quarantines the site) |
| Read logs from outside | `T=$(curl -su "$opsUser:$opsPass" https://$API_HOST/@@tok \| sed 's/.*"token":"\([0-9a-f]*\)".*/\1/')`; `curl -su … "https://$API_HOST/@@log.csv/$T"`; the human version is `https://$API_HOST/@@backroom` |
| Watch it | `tail -f /var/log/d2w/user.log`, `curl -s http://127.0.0.1/nginx_status`, `df -h /var/cache/nginx`, `mongod` resident memory |
| Every month | `apt list --upgradable`, `~/.acme.sh/acme.sh --list`, re-run the audit (a stale `$d2wCdnHosts` or a moved socket shows up there) |

## 15. Rollback

Keep the previous copy and swap it back — nothing else has to be undone:

```bash
sudo rsync -a $APP/html/ /var/www/drv_tw.prev-$(date +%F)/html/    # BEFORE deploying
sudo rm -rf $APP/html && sudo mv /var/www/drv_tw.prev-<date>/html $APP/html
sudo systemctl reload $PHPVER-fpm
```

The database needs no rollback: the two phase-2 changes are additive (the `authfail` collection
and the extra indexes), and every config key added in this review has a default in
`drvtw_util.php`/`index.php`, so old code reading new creds — or new creds read by old code — is
fine. If you must revert the *whole* review on your own clone: `git apply -R drvtw_oss-all.patch`.

Reversing that also reverses the CSRF gate, the log scrubbing and the access-code lockout: if you
roll back because of a bug, tell the users, and prefer fixing forward.

## 16. Sign-off

```
[ ] run-all.sh on the checkout: ALL CHECKS PASSED
[ ] audit --root on the server: exit=0
[ ] creds: no placeholder strings, 0640 root:www-data
[ ] /var/log/d2w 0770 www-data:www-data, lines appearing, no e-mails/tokens in them
[ ] nginx: real_ip_from = own proxies only; only /index.php executes; creds 404
[ ] db.authfail has ts_1 TTL index
[ ] config.js: real client ids + siteSuffix
[ ] dashboard Site tools: publish/lock/refresh return HTTP 200
[ ] dashboard "Your website" card: address shown; first rename works, the second answers 429
    until `$siteNameChangeGapSec` has passed; `@@domain` says ERR_NOT_YET before the TXT record
    exists and `matched:true` after (and `mongosh … sites.findOne({name:'my-site'}).domain` agrees)
[ ] ERR_AUTH_REQUIRED, ERR_CSRF_REQUIRED and ?auth=slow each demonstrated
[ ] backup cron installed and a restore actually performed once
[ ] SECURITY.md residual risks read and accepted
```
