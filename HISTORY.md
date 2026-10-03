# History

DriveToWeb has been in continuous operation since November 2016.

## 2016 — Launch

- Initial service launched, hosting websites from Google Drive via PHP backend
- OAuth 2.0 sign-in with Google Drive
- Custom 403/404 error pages
- Terms of Service and Privacy Policy published

## 2017–2019 — Growth & Stability

- Phishing abuse detection introduced — content scanning of hosted HTML before serving
- Rate limiting and connection throttling added to nginx proxy
- Ongoing blacklist development against PayPal, Microsoft, Facebook, and banking phish
- MongoDB introduced for user and token storage, replacing flat file storage

## 2020 — CDN & Caching Architecture

- Multi-layer caching introduced: PHP → nginx proxy (up to 12 GB) → CDN
- Probabilistic CDN routing for images based on file size thresholds
- Nginx proxy cache tuned with configurable TTLs
- Phishing patterns extended to cover Office 365, Amazon, APK downloads, and obfuscated payloads

## 2021 — CloudFront CDN Integration

- AWS CloudFront (`cdn-of.drv.tw`) introduced for image and media delivery
- CloudFront Lambda@Edge functions (`guf_requestor`, `guf_responder`) handle Google Drive file proxying at the edge with reversed OAuth tokens
- Nginx proxy cache extended and tuned for CDN coexistence
- Ongoing high-volume phishing campaigns blocked (Outlook, SharePoint, LinkedIn, SBI, BDO, Netflix)

## 2022 — OneDrive Support & Custom Site Names

- Microsoft OneDrive / Microsoft Graph API support added alongside Google Drive
- OPF (OneDrive Proxy Files) Lambda@Edge functions added (`opf_requestor`, `opf_responder`)
- Static frontend migrated from `drv.tw` to `www.drv.tw`, hosted on Azure Static Web Apps
- Backend API split to `api.drv.tw` subdomain
- `.on.drv.tw` wildcard subdomain routing introduced for cleaner site URLs (replacing `-on.drv.tw`)
- MongoDB Atlas (AWS + GCP) added as remote replica alongside local MongoDB
- Service worker (`d2w_sw.js`) introduced for progressive web app support
- CDN cache max-age extended to 180 days for static assets
- Custom site name feature published (users can choose their `.on.drv.tw` prefix)
- Abuse reporting API (`/abuseReport`) added

## 2023 — Hardening

- `Sec-Fetch-Dest: document` requests routed through CDN
- Phishing blacklist expanded significantly: Bancolombia, SBI, WeTransfer, MS login pages, CTM360, Crisp chat phish
- CORS handling and `Vary` header tuning for cross-origin media requests

## 2024 — Email Authentication & Service Continuity

- Email-based site access authentication introduced: site owners can require visitors to verify their email before accessing a site (`sendSiteAuth` / `checkSiteAuth`)
- Service suspended briefly due to phishing-related CDN bans; restored after mitigation
- Abuse reporting integrated with external APIs (Netcraft)
- Google Drive flagged as incompatible with restricted scope policy; `@@html` discovery disabled for new Google Drive accounts
- Google Drive effectively disabled for new sign-ups (Sep 2025)
- OneDrive-only mode explored as forward path

## 2025–2026 — OSS Preparation

- Codebase restructured for open source release
- All credentials, personal information, and internal tooling removed
- Internal admin features (backroom log viewer, site diagnostics, media browser) retained for self-hosters
- Experimental site renaming feature removed from public release
- `admin2` experimental admin panel removed
