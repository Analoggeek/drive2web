# Running this with no money and no server

The honest starting point: this app needs a computer that stays on, and nobody gives
away a real one for nothing. What *is* free is smaller than it sounds, so this page ranks
the options by "what you can do today with only a browser".

Nothing here is a shortcut around `install.sh` - every route below ends at the same three
commands, on a machine that happens to be free.

## Today, zero signups: your own computer + a quick tunnel

Use the machine you already have as the server, and Cloudflare's "quick tunnel" to make it
reachable from outside for as long as the window stays open.

```
cd drvtw_oss/install
./preview-tunnel.sh 8099          # or 80, whatever your local server listens on
```

- Needs: no account, no card, outbound HTTPS only.
- Gives: `https://<random>.trycloudflare.com` for as long as you run it.
- Good for: seeing the app on your phone, showing someone the landing page or the sign-in
  screen, testing `/do/siteName` and the backroom login from outside your network.
- Not good for: real use. One hostname means the `<name>.on.<domain>` addresses cannot
  resolve, the URL changes every run, and it dies when you close the window or sleep the
  laptop.

On Linux this can be your actual box for as long as it stays on: run `install.sh` normally,
then `./preview-tunnel.sh`. On Windows, use WSL2 (Ubuntu from the Microsoft Store) and run
it inside that - the app itself does not run on Windows.

## Free forever, needs one card for identity: Oracle Cloud Always Free

The only "real server, genuinely ₹0, indefinitely" offer from a company you can point a
domain at. Four ARM cores and 24 GB on the Ampere shape are free with no expiry; a card is
used to prove you are a person and is not charged on the free tier.

1. Sign up, then Compute → Instances → create one, image **Ubuntu 24.04**, shape
   `VM.Standard.A1.Flex` (choose 4 OCPU / 24 GB if 1 GB Ampere is unavailable, otherwise take
   what fits).
2. In the VCN security list, allow ingress on 80/tcp and 443/tcp - Oracle blocks these by
   default and this is the single most common "the site is unreachable" cause there.
3. If the region says out of capacity: it clears and fills hourly, and a different region or
   the smaller `E2.4` shape usually goes through.
4. SSH in and run the normal setup: `apt update && apt-get install -y git`, clone the repo,
   `bash install/check-host.sh`, `./wizard.sh`, `sudo ./install.sh`, `./verify.sh`.

Watch-outs: free accounts get reclaimed if idle, the account can be closed if the payment
check fails, and ARM means the Ubuntu 24.04 image specifically (MongoDB's arm64 packages are
published for 24.04 only). Treat it as a real server that could vanish - keep the weekly copy
of `/var/backups/drvtw` off the box, as `install/README.md` says.

## Free database only: MongoDB Atlas M0

Atlas gives a 512 MB free cluster forever. That covers the hardest part of the requirements
(the database), but it does not run PHP, so on its own it solves nothing. It pairs with a
small VPS: in `install/config.env` set `INSTALL_MONGODB=no`, paste the Atlas SRV URI into
`MONGO_URI`, add your network's IP to the Atlas allow-list, and run
`sudo ./install.sh --from packages`.

## Free domain names, and whether they work here

The app needs three things from a domain: records for `@`, `www`, `api`, `*` and `*.on`, and
a way to answer a DNS-01 challenge so a wildcard certificate can be issued.

- A **free subdomain from a service that delegates to your own Cloudflare account** is the
  only free shape that works fully: you keep the zone, so `DNS_PROVIDER=cloudflare` and an
  API token behave exactly as they do on a paid domain. These services come and go, get
  delisted for other people's abuse, and can delete your subdomain on a whim - fine for a
  test, not something to build a product on.
- `eu.org` and similar free zones are real and long-lived but slow to approve and have no
  DNS API, so you would use `DNS_PROVIDER=manual` and renew certificates by hand every
  60 days. Workable, tedious.
- A free subdomain on a **shared-hosting or page-builder domain has no DNS control at all**:
  no wildcard, no certificate, no api host. It cannot run this app.
- If you are a student, GitHub Student Developer Pack includes a free year of a real domain
  plus cloud credits - that is the cheapest path to something you are not going to lose.

## What none of the free "shared hosting" plans can do

No PHP MongoDB extension you can install, no php-fpm pool of your own, no vhost answering
arbitrary hostnames, no wildcard certificate. `bash install/check-host.sh` on any such box
will say so in about 20 seconds, which is faster than arguing with a control panel.

## The short version

| What you have | Do this |
|---|---|
| Only a browser, today | `./preview-tunnel.sh` against a local run - demo only |
| A card, no money to spend | Oracle Always Free + Ubuntu 24.04 + your own domain later |
| A student account | Student Pack: free domain year, then the same install |
| A friend with a VPS | install.sh inside their box is fine - it writes nothing outside the paths you set |
| Nothing at all | the demo in the sandbox preview is still the app, running |
