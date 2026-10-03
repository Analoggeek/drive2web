# Frontend

The static landing site served at `www.drv.tw`, hosted on Azure Static Web Apps.

## Directory Structure

```
frontend/
└── www.drv.tw/
    ├── index.html           # Landing page
    ├── admin-sample.html    # Sample admin page reference
    ├── inc/config.js        # DEPLOYMENT CONFIG - edit this one file (see below)
    ├── supportus.html       # Donation page (PayPal — configure YOUR_PAYPAL_CLIENT_ID)
    ├── tos.html             # Terms of Service
    ├── privacy.html         # Privacy Policy
    ├── skin-config.html     # Theme configuration
    ├── robots.txt
    ├── favicon.ico
    ├── staticwebapp.config.json  # Azure Static Web Apps routing rules
    ├── img/                 # Logo and service icons
    └── inc/
        ├── admin.js         # Admin dashboard JavaScript (reads window.D2W from config.js)
        ├── wd.js / wd.css   # DriveToWeb client-side widget
        ├── wd-s.css         # Mobile styles
        ├── style.css        # Main stylesheet
        ├── bootstrap.min.css
        ├── font-awesome.css
        ├── animate.css
        ├── punycode-2.1.0.min.js
        ├── sd/              # Showdown Markdown renderer
        ├── jq/              # jQuery UI and chart libraries
        └── theme/           # Inspinia admin theme JS
```

## Deployment

This site is deployed automatically via GitHub Actions to Azure Static Web Apps on push to the main branch. See `.github/workflows/` for the workflow.

To deploy to your own Azure Static Web App:
1. Create a Static Web App resource in the Azure portal
2. Connect it to your fork of this repository
3. Azure will generate a GitHub Actions workflow with a deployment token

## Configuration

`inc/config.js` defines `window.D2W`, read by `admin.js`, `wd.js` and the pages above. It
replaces the values that used to be hardcoded to the original hosted service:

| Key | Used for |
|---|---|
| `googleClientId` / `microsoftClientId` | the dashboard's OAuth sign-in (`redirect_uri` is built from `apiHost`, so register `https://<apiHost>/.b/gd/` and `/.b/od/` in your app) |
| `apiHost` / `webHost` / `siteSuffix` | where sign-in goes and how site URLs are shown; `apiHost` defaults to the current host |
| `contactEmail` / `docsUrl` | the `{{CONTACT}}` / `{{DOCS}}` markers in the static pages, resolved by `wd.js`. `null` hides the link instead of pointing at someone else's address |
| `analyticsId` | Google Analytics property to load; **null (default)** loads nothing, so your visitors' page views are not reported to the original author's property |
| `paypalClientId` | `supportus.html` loads the PayPal SDK only when this is set; otherwise the page shows "donations are not configured" instead of throwing `paypal is not defined` |
| `donateDomainCheck` | enables the (disabled by default) "hide the support banner" option |

**Branding**: Update `index.html` with your own service name, domain, and description before
deploying. The pages no longer need `<base href>` pinning — it is `/`, so assets resolve
against whatever host serves the site.

## Admin Dashboard (`admin.js`)

The admin dashboard (`admin.html`, served dynamically by the backend at `api.your.domain/.b/gd/` or `/.b/od/`) uses `admin.js` to:

1. Complete the OAuth implicit flow (token in URL hash)
2. Fetch the user's site name from the API (`?a=siteName`)
3. Display file listing from Google Drive or OneDrive via the backend

The admin page is served from the backend (`inline/admin.html`), not from the static frontend,
but shares the same `admin.js` and CSS assets — which is why `inc/config.js` is loaded by both
trees (`backend/html/www.drv.tw/` is a synced copy of this directory).

Note on privileges: `admin.js` completes the *implicit* flow, so the token stays in the URL
hash and no owner session cookie is issued. The privileged `@@` commands (`@@publish`,
`@@lock`, `@@dup`, `@@delete`, …) therefore are not callable from this dashboard: they need
the `D2WSESS` cookie issued by the server-side `/.a/<svc>/` code exchange, or an
`Authorization: Bearer <access token>` header. Add `fetch(..., { headers: { Authorization: … } })`
to `admin.js` if you want them in the browser.
