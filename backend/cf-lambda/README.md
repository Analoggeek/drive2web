# cf-lambda — Edge Functions

These Lambda@Edge (AWS CloudFront) functions intercept requests and responses at the CDN edge, enabling the CDN to proxy authenticated requests to Google Drive and OneDrive without exposing OAuth tokens in URLs or requiring a round-trip to the origin server.

## Functions

### Google Drive (GUF — Google User File)

| File | Trigger | Purpose |
|---|---|---|
| `guf_requestor.js` | Viewer request | Rewrites `/_guf/<siteName>/<fileId>` to `googleapis.com/drive/v3/files/<fileId>?alt=media` (both path segments are captured; the shipped version captured only one and asked Drive for `files/<siteName>/<fileId>`, so every CDN-served file 404'd). The file id is validated against `FILE_ID_RE` and re-encoded. Injects the OAuth token (passed reversed in `?ra=`) as an `Authorization: Bearer` header, and keeps it out of the query string and out of the logs. Public files fall back to `GOOGLE_API_KEY`; if that is not set the request is denied rather than sent with `key=undefined`. |
| `guf_responder.js` | Origin response | On 403 from Google (e.g. token expired), redirects the client back to the DriveToWeb origin to refresh the token and retry. |

### OneDrive (OPF — OneDrive Proxy Files)

| File | Trigger | Purpose |
|---|---|---|
| `opf_requestor.js` | Viewer request | Takes the Graph/SharePoint target from `?ur=<host><path>`, and rewrites the request to it. **Only hosts matching `ALLOWED_SUFFIXES`** (`graph.microsoft.com`, `sharepoint.com`, `1drv.ms`, `office.com`, `office365.com`) are accepted; anything else - including `169.254.169.254`, a path-less `ur`, `..`, or a non-GET verb - is refused with a small 403 instead of crashing. A query string inside the target is moved to `request.querystring`, because `request.uri` must stay path-only. |
| `opf_responder.js` | Origin response | Adds CORS headers to OneDrive responses so browser-side JS can consume them cross-origin. |

## Token Security

OAuth access tokens are never stored in URLs or cache keys. For Google Drive:
- The token is passed reversed (`?ra=token_reversed`) so it is not logged by CDN access logs in plaintext
- `guf_requestor.js` reverses it back and places it in the `Authorization` header before forwarding to Google
- `?ra=` is obfuscation, not encryption: restrict the distributions to your own hosts and keep
  the origin's `X-Forwarded-For` trust narrow. Do not put these hostnames on a public site.

## Logging

Both requestors log through `redacted()`, which strips `authorization`, `cookie`, `ra`, `ur`
and `token` values. The shipped versions `console.log`'d whole events, which wrote bearer
tokens into CloudFront logs. `tests/test-lambda.js` asserts a token never appears in the
handler's output.

## Caching and responses

`*_responder.js` no longer stamps 180 days on everything (it cached 401/403 too). Cacheability
now follows the upstream response: `no-store`/`private` are preserved, `max-ageFromUri` only
applies to cacheable 200/206/301/302/304/404, and `Vary`/CORS headers are set together so a
reflected `Origin` cannot be served to a different origin from cache.

## Deployment

Deploy each function as a CloudFront Lambda@Edge function:
- `guf_requestor` / `guf_responder` → attach to the CloudFront distribution for your
  first `$d2wCdnHosts` entry (Google files); `opf_requestor` / `opf_responder` → the second
  (OneDrive). The PHP origin only rewrites to hosts listed in `$d2wCdnHosts`, and the
  functions only accept `graph.microsoft.com`/`sharepoint.com`-family targets.
- Set the `GOOGLE_API_KEY` environment variable to your Google API key (used as fallback for public files)

Each function must be deployed to **us-east-1** (Lambda@Edge requirement), published as a
version, and the *version* ARN is what the distribution references. Set
`minProtocolVersion` to `TLSv1.2` or later on the distribution behaviours (the shipped templates
allowed `SSLv3`/`TLSv1`).

## Verify before deploying

```bash
node --check backend/cf-lambda/*.js
node tests/test-lambda.js                # exercises the real handlers with CloudFront events
python3 tests/check-edge-contract.py     # PHP-built URLs -> shipped handlers, round trip
```
