# Running the server on a laptop you already own

You can. This app is small: PHP + nginx + MongoDB, no build step, no queue workers. A machine
that is switched off at night is the only real difference from a rented server, and a laptop
even has a battery, which is a free UPS.

The two things that actually decide whether it works are not RAM or disk:

1. **The CPU.** MongoDB 5.0+ needs AVX, and the current 7.0/8.0 packages are built for
   Haswell (mid-2013) or later. An older chip runs nginx and PHP happily but `mongod` dies at
   the first start with `Illegal instruction (core dumped)`.
2. **Your internet connection.** Home lines in India (and most places) are behind CGNAT with
   port 80/443 blocked by the ISP, so there is no IP address for DNS to point at. A Cloudflare
   tunnel fixes this without a public IP, without port forwarding, and without a router setting
   you would have to find. It is free, and it also gives you the real HTTPS certificate, so the
   installer does not need to touch DNS at all.

Everything below is the order to do it in. Steps 1-8 take about an hour the first time.

---

## Step 0 — ask the machine

```bash
cd drvtw_oss/install && ./laptop-serve.sh check
```

It prints `ok` / `note` / `FAIL` per item and exits non-zero if something blocks you. The CPU
line is the one that changes the plan:

| it says | do this |
| --- | --- |
| `cpu (AVX2)` | install the database on the laptop too (step 6, `INSTALL_MONGODB=yes`) |
| `cpu (AVX, no AVX2)` | keep the database **off** this machine: MongoDB Atlas M0, free (step 4) |
| `cpu (no AVX at all)` | same as above, and expect it to be slow with several visitors |

## Step 1 — the operating system

Two options; pick one and only one.

**Keep Windows, run Ubuntu inside it (WSL2).** Good for learning, bad as a 24/7 server: Windows
must stay awake and a terminal must stay open, or the VM idles.

```powershell
# PowerShell, as Administrator
wsl --install -d Ubuntu-24.04
```

Then inside Ubuntu, so systemd works (the installer uses `systemctl`):

```bash
sudo tee /etc/wsl.conf > /dev/null <<'EOF'
[boot]
systemd=true
EOF
```

```powershell
wsl --shutdown   # then open Ubuntu again
```

**Or wipe it and install Ubuntu Server 24.04** from a USB stick. Slower to set up, and it is
what you want if this machine is going to be the real one for friends or customers. 500 GB is
plenty; the app itself is ~8 MB, the rest is the OS, the database and upload caches.

## Step 2 — the code

```bash
sudo apt update && sudo apt install -y git curl
git clone https://github.com/jcwtw/drvtw_oss.git
```

## Step 3 — the domain (the only thing you usually pay for)

Sites look like `https://ramesh.on.example.org`, so you need one domain you control. A `.com`
is about ₹1,000 a year (Cloudflare Registrar sells it at cost; Porkbun/Namecheap are similar).
Free options are listed in `FREE-SETUP.md`, but note the limit: with a tunnel you must be able
to add CNAME records yourself, so a subdomain handed to you by someone else does not work.

Put the domain on a free Cloudflare account and change its nameservers at the registrar.
You do **not** add A or AAAA records — step 5 writes the records it needs.

## Step 4 — the database, if step 0 said the CPU is too old

MongoDB Atlas M0 is free, 512 MB, no card required, and it is a normal MongoDB server, so this
app talks to it unchanged. Sign up, create a free cluster in `ap-south-1` (Mumbai), then:

* **Database Access** → add a user (no wildcard password characters — the installer URL-encodes
  what you type, but keep it simple anyway).
* **Network Access** → allow `0.0.0.0/0` (your home IP changes; an allow-list of one IP is a
  support ticket waiting to happen).
* **Connect → Drivers** → copy the `mongodb+srv://...` string.

Put that string in `MONGO_URI` in step 6. Nothing else knows or cares that the database is not
on the laptop.

## Step 5 — the tunnel

```bash
# cloudflared, the tunnel client
cd /tmp && curl -LO https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb
sudo apt install -y ./cloudflared-linux-amd64.deb

# one-time: it opens a browser and asks which Cloudflare account owns your domain
cloudflared tunnel login

cloudflared tunnel create drvtw
```

That prints an id and writes `~/.cloudflared/<id>.json`. Now the config file. `cloudflared` runs
as a service, so it reads `/etc/cloudflared/config.yml`:

```bash
ID=$(ls ~/.cloudflared/*.json | head -1 | xargs basename | sed 's/.json//')
sudo mkdir -p /etc/cloudflared
sudo cp ~/.cloudflared/$ID.json /etc/cloudflared/
sudo tee /etc/cloudflared/config.yml > /dev/null <<EOF
tunnel: $ID
credentials-file: /etc/cloudflared/$ID.json
ingress:
  - hostname: example.org
    service: https://127.0.0.1:443
    originRequest:
      noTLSVerify: true
  - service: http_status:404
EOF

# the three names the app itself needs
for h in example.org www.example.org api.example.org; do cloudflared tunnel route dns $ID $h; done

sudo cloudflared service install
sudo systemctl enable --now cloudflared
```

Two details worth knowing, because getting them wrong looks like a crash:

* The origin is **`https://127.0.0.1:443`**, not port 80. nginx redirects http to https
  permanently, so pointing the tunnel at port 80 loops.
* The last rule (`http_status:404`) must stay last. Every new site is inserted *above* it,
  which is what `laptop-serve.sh host` does for you instead of hand-editing.

Check it: `sudo cloudflared tunnel ingress validate --config /etc/cloudflared/config.yml`
should print `OK`, and `curl -sI https://example.org` from anywhere should reach your laptop
through Cloudflare (a `200`, or a `301` if you asked for `http://`).

## Step 6 — install the app

```bash
cd drvtw_oss/install
./wizard.sh          # answer the four questions
sudo ./install.sh
```

Before the `sudo` line, open `install/config.env` and set these three, which the laptop needs
and a VPS does not:

```bash
DNS_PROVIDER=none          # no DNS writes, no acme.sh: the tunnel serves HTTPS at its edge,
                           # and the installer makes a self-signed cert for nginx to talk to
SERVER_IP=127.0.0.1        # unused on this path; the installer only warns if it disagrees
INSTALL_MONGODB=no         # only if step 0 said the CPU is too old
MONGO_URI=mongodb+srv://user:pass@cluster0.xxxxx.mongodb.net/drvtw
```

`DNS_PROVIDER=none` is not a shortcut here — nginx needs *a* certificate for the tunnel to
reach it, and `install.sh` generates one whose names include `*.on.<domain>`, which is exactly
what the tunnel asks for. Cloudflare presents the real, browser-trusted certificate to your
visitors.

If you left `INSTALL_MONGODB=yes`, the installer puts MongoDB 8.0 in; that is the one that
needs a 2013+ CPU. On an older chip you can go down to `MONGO_VER=6.0` in the same file, but
6.0 is out of support, so Atlas is the better trade.

## Step 7 — every new site is one hostname

```bash
./laptop-serve.sh host ramesh          # -> https://ramesh.on.example.org
./laptop-serve.sh host ramesh --dry-run   # look first, change nothing
```

It adds the ingress rule, writes the CNAME, and restarts `cloudflared`. It does the same for a
visitor's own domain (`./laptop-serve.sh host files.example.in`), and they still only need to
add one CNAME pointing at `<id>.cfargotunnel.com`.

Then in the app itself: open the site, sign in as the owner once, done. The rename flow
(`@@rename`) changes what the app accepts; it cannot add the tunnel record, so on a laptop
this command is part of onboarding a person, until you get a box where DNS writes are automatic.

## Step 8 — keep it awake

```bash
sudo ./laptop-serve.sh wake
```

On bare Linux that masks suspend/hibernate and sets the lid switch to "do nothing". In WSL2 it
will refuse and tell you why: Windows decides, not Ubuntu, so set **Control Panel → Power
Options → Choose what closing the lid does → Do nothing**, and keep one Ubuntu terminal open
(WSL shuts its VM down when nothing is using it).

Battery in, charger in. Then:

```bash
./laptop-serve.sh status      # same as check, for a Sunday sanity look
cd drvtw_oss/install && sudo ./verify.sh --deep
```

---

## What you are signing up for

* **Off means offline.** Power cut, Windows Update reboot, someone closing the lid: your sites
  and everyone's sign-in are down until it is back. `verify.sh` cannot tell you that; a
  monitor you text yourself would, and that is a fair thing to add later.
* **Your upload speed is the limit.** Home lines in India are usually 5-20 Mbps symmetric-ish;
  a ₹400 VPS is 1 Gbps. Pages are fine. Anything that pulls large files through the tunnel is
  slow, and *a lot* of continuous media through Cloudflare's free proxy is the one thing their
  free plan asks you not to do — this app mirrors Drive/OneDrive links, so ordinary use is
  fine, but do not make it a video host.
* **One machine runs everything.** mongod, nginx, PHP. 8 GB is comfortable; 4 GB is fine with
  `FPM_CHILDREN` kept low; 2 GB needs the database elsewhere.
* **Backups are yours now.** The installer writes a nightly dump via `cron`
  (`/etc/cron.d/drvtw-backup` → `BACKUP_DIR`). On a laptop that lands on the same disk, so
  copy it somewhere else weekly — Google Drive/OneDrive folder is enough for a personal site.

## When to move off the laptop

Move when any of these is true: you want a real 24/7 guarantee, more than a handful of
regulars, or you are tired of being the reboot. Nothing in this app is laptop-specific — the
same `install/config.env` runs on a VPS with `DNS_PROVIDER=cloudflare` and `INSTALL_MONGODB=yes`,
and the move is:

```bash
# old box, after the last write has landed
mongodump --uri="$MONGO_URI" --archive=drvtw-dump.gz --gzip

# new box: copy install/config.env across (MONGO_URI now points at the new database),
# run the installer the normal way, then load the dump over the empty collections
sudo ./install.sh
gunzip < drvtw-dump.gz | mongorestore --nsInclude='drvtw.*' --archive
```

DNS and the tunnel both follow you: `cloudflared tunnel create` a new one there, or reuse the
same tunnel by copying `/etc/cloudflared/` to the new machine. The hostnames do not change, so
no one re-adding anything.
