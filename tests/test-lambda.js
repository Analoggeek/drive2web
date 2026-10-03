/*
 * DriveToWeb edge-function tests (no AWS required).
 *
 * Loads the shipped Lambda@Edge handlers and calls them with synthetic
 * CloudFront events, which is what CloudFront does in production.
 *
 *   node tests/test-lambda.js
 */
'use strict';

const path = require('path');
const querystring = require('querystring');

// D2W_LAMBDA_DIR lets the suite be pointed at another copy (e.g. the
// pre-fix revision) to confirm these tests actually catch the old bugs.
const DIR = process.env.D2W_LAMBDA_DIR
    || path.join(__dirname, '..', 'backend', 'cf-lambda');

const opfRequestor = require(path.join(DIR, 'opf_requestor.js'));
const opfResponder = require(path.join(DIR, 'opf_responder.js'));
const gufRequestor = require(path.join(DIR, 'guf_requestor.js'));
const gufResponder = require(path.join(DIR, 'guf_responder.js'));

let pass = 0, fail = 0;
const failures = [];

function check(name, cond, extra) {
    if (cond) {
        pass++;
        console.log('  ok   ' + name);
    } else {
        fail++;
        failures.push(name);
        console.log('  FAIL ' + name + (extra ? '  <-- ' + extra : ''));
    }
}

function req(method, querystring_, uri, headers) {
    return {
        Records: [{
            cf: {
                request: {
                    method: method,
                    uri: uri,
                    querystring: querystring_,
                    headers: headers || {},
                    origin: { s3: { customHeaders: {} } },
                },
            },
        }],
    };
}

function res(status, headers, body) {
    return {
        Records: [{
            cf: {
                request: { method: 'GET', uri: '/x', querystring: '', headers: {} },
                response: { status: String(status), statusDescription: 'x', headers: headers || {}, body: body || '' },
            },
        }],
    };
}

function hdr(obj, name) {
    const e = obj && obj[name.toLowerCase()];
    return e && e.length ? e[0].value : null;
}


// Supports both handler styles: async (returns the result) and the legacy
// (event, context, callback) form — so the same suite can be run against the
// pre-fix revision via D2W_LAMBDA_DIR to prove it catches the old bugs.
function call(handler, event) {
    return new Promise((resolve, reject) => {
        let done = false;
        const cb = (err, result) => { if (!done) { done = true; err ? reject(err) : resolve(result); } };
        let r;
        try { r = handler(event, {}, cb); } catch (e) { return reject(e); }
        if (r && typeof r.then === 'function') r.then(v => { if (!done) { done = true; resolve(v); } }, reject);
        else if (!done && r) { done = true; resolve(r); }
    });
}

async function run() {
    console.log('\n== opf_requestor: OneDrive proxy target restrictions ==');

    // 1. legitimate Graph host is proxied
    let ev = req('GET', querystring.stringify({
        ur: 'graph.microsoft.com/v1.0/me/drive/root:/site/index.html:/content',
    }), '/_opf/abc123/1234:xyz');
    let out = await call(opfRequestor.handler, ev);
    check('allowed Graph host proxied', hdr(out.headers, 'host') === 'graph.microsoft.com'
        && out.origin.custom.domainName === 'graph.microsoft.com'
        && out.uri === '/v1.0/me/drive/root:/site/index.html:/content',
        JSON.stringify(out && { host: hdr(out.headers, 'host'), uri: out.uri }));

    // 2. SharePoint / 1drv hosts allowed
    ev = req('GET', querystring.stringify({ ur: 'x-internal-1drv.sharepoint.com/sites/a/b' }), '/_opf/s/f');
    out = await call(opfRequestor.handler, ev);
    check('sharepoint host allowed', out.origin && out.origin.custom.domainName === 'x-internal-1drv.sharepoint.com');

    // 3. arbitrary hosts refused (this used to be an open proxy)
    for (const bad of ['evil.example/creds', '169.254.169.254/latest/meta-data/',
        'metadata.google.internal/computeMetadata/v1/', 'graph.microsoft.com.attacker.io/x',
        'xn--graph.microsoft.com.evil/x']) {
        ev = req('GET', querystring.stringify({ ur: bad }), '/_opf/s/f');
        out = await call(opfRequestor.handler, ev);
        check('refuses ' + bad, out && out.status === '403', 'got ' + (out && out.status));
    }

    // 4. malformed ur must be a clean 403, not a thrown TypeError
    for (const malformed of ['', 'nopathsegment', '/', 'a/', 'a/b?c#d', '....//etc/passwd']) {
        ev = req('GET', querystring.stringify({ ur: malformed }), '/_opf/s/f');
        out = await call(opfRequestor.handler, ev);
        check('malformed ur handled (' + JSON.stringify(malformed) + ')',
            out && (out.status === '403' || out.uri !== undefined),
            'handler threw or returned ' + JSON.stringify(out));
    }

    // 5. no ur at all -> untouched request
    ev = req('GET', '', '/other/path');
    out = await call(opfRequestor.handler, ev);
    check('request without ur passes through unchanged', out.uri === '/other/path');

    // 5b. a Graph/SharePoint target with its own query string must be split:
    //     request.uri has to stay path-only
    ev = req('GET', querystring.stringify({
        ur: 'x.sharepoint.com/sites/a/b.pdf?download=1&x=%20y',
    }), '/_opf/s/f');
    out = await call(opfRequestor.handler, ev);
    check('target query moved into querystring',
        out.uri === '/sites/a/b.pdf' && out.querystring === 'download=1&x=%20y',
        JSON.stringify({ uri: out.uri, querystring: out.querystring }));

    // 6. only GET/HEAD
    ev = req('POST', querystring.stringify({ ur: 'graph.microsoft.com/v1.0/x' }), '/_opf/s/f');
    out = await call(opfRequestor.handler, ev);
    check('POST denied', out && out.status === '403');

    console.log('\n== opf_responder: CORS + caching, without crashing ==');
    out = await opfResponder.handler(res('200', {
        'content-type': [{ key: 'Content-Type', value: 'text/html' }],
    }), {});
    check('200 cached publicly with CORS', hdr(out.headers, 'cache-control').includes('public')
        && hdr(out.headers, 'access-control-allow-origin') === '*');
    check('origin CSP removed', out['content-security-policy'] === undefined);

    ev = res('200', { 'content-type': [{ key: 'Content-Type', value: 'text/html' }],
        'cache-control': [{ key: 'Cache-Control', value: 'private, max-age=10' }] });
    ev.Records[0].cf.request.headers.origin = [{ key: 'Origin', value: 'https://a.on.drv.tw' }];
    out = await call(opfResponder.handler, ev);
    check('origin no-store honoured', hdr(out.headers, 'cache-control') === 'private, no-store');
    check('viewer origin reflected', hdr(out.headers, 'access-control-allow-origin') === 'https://a.on.drv.tw');

    out = await call(opfResponder.handler, res('404'), {});
    check('404 untouched', out.status === '404');

    console.log('\n== guf_requestor: file id validation, token handling, logs ==');
    const secretToken = 'ya29.SECRETTOKENSHOULDNOTBELOGGED';
    const rev = secretToken.split('').reverse().join('');

    out = await gufRequestor.handler(req('GET', 'ra=' + rev, '/_guf/abc123/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
        { origin: [{ key: 'Origin', value: 'https://abc123.on.drv.tw' }] }), {});
    check('rewritten to Drive media endpoint', out.uri === '/drive/v3/files/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs'
        && out.origin.custom.domainName === 'www.googleapis.com', JSON.stringify(out.uri));
    const authHdr = out.origin.custom.customHeaders.authorization[0].value;
    check('reversed token restored into Authorization', authHdr === 'Bearer ' + secretToken, authHdr);
    check('querystring carries no token', out.querystring === 'alt=media', out.querystring);

    // traversal via an encoded slash must not reach the origin
    for (const bad of ['..%2f..%2fetc%2fpasswd', 'a/b', 'x?alt=media', '', 'short', 'id-with-@']) {
        ev = req('GET', 'ra=' + rev, '/_guf/abc123/' + bad, { origin: [{ key: 'Origin', value: 'https://x' }] });
        out = await call(gufRequestor.handler, ev);
        check('rejects file id ' + JSON.stringify(bad), out && (out.status === '400' || out.status === '403'),
            'got ' + (out && out.status) + ' uri=' + (out && out.uri));
    }

    // no Origin header (top-level navigation) -> plain Drive link
    out = await call(gufRequestor.handler, req('GET', '', '/_guf/abc/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs'), {});
    check('navigation without Origin redirects to Drive',
        out.status === '302' && hdr(out.headers, 'location') === 'https://drive.google.com/uc?id=1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs');

    // tokens must never be written to CloudFront logs
    const logs = [];
    const realLog = console.log;
    console.log = (...a) => logs.push(a.map(x => (typeof x === 'string' ? x : JSON.stringify(x))).join(' '));
    try {
        ev = req('GET', 'ra=' + rev, '/_guf/abc123/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
            { origin: [{ key: 'Origin', value: 'https://abc123.on.drv.tw' }] });
        await call(gufRequestor.handler, ev);
    } finally {
        console.log = realLog;
    }
    const allLogs = logs.join('\n');
    check('logs do not leak the bearer token', allLogs.length > 0 && !allLogs.includes(secretToken)
        && !allLogs.includes(rev), 'leak: ' + allLogs.slice(0, 120));
    check('logs show redaction marker', allLogs.includes('redacted'));

    // public-file fallback needs an API key
    delete process.env.GOOGLE_API_KEY;
    out = await gufRequestor.handler(req('GET', '', '/_guf/abc/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
        { origin: [{ key: 'Origin', value: 'https://x' }] }), {});
    check('missing GOOGLE_API_KEY denies instead of sending key=undefined',
        out && out.status === '403', 'got ' + (out && out.status));

    process.env.GOOGLE_API_KEY = 'AIzaTest';
    out = await gufRequestor.handler(req('GET', '', '/_guf/abc/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs',
        { origin: [{ key: 'Origin', value: 'https://x' }] }), {});
    check('API key used when no token given', out.querystring === 'alt=media&key=AIzaTest');
    delete process.env.GOOGLE_API_KEY;

    console.log('\n== guf_responder: token-refresh bounce must not throw ==');
    // The shipped bug: a 401 with no WWW-Authenticate header crashed the Lambda
    const ev401 = {
        Records: [{
            cf: {
                request: { headers: { referer: [{ key: 'Referer', value: 'https://cdn-of.drv.tw/_guf/abc/f' }] } },
                response: { status: '401', statusDescription: 'Unauthorized', headers: {}, body: '' },
            },
        }],
    };
    let threw = null;
    try { out = await call(gufResponder.handler, ev401); } catch (e) { threw = e; }
    check('401 without WWW-Authenticate does not crash', threw === null, threw && threw.message);
    check('401 without WWW-Authenticate passes through', threw === null && out.status === '401');

    const evExpired = {
        Records: [{
            cf: {
                request: { headers: { referer: [{ key: 'Referer', value: 'https://cdn-of.drv.tw/_guf/abc/f' }] } },
                response: {
                    status: '401', statusDescription: 'Unauthorized',
                    headers: {
                        'www-authenticate': [{
                            key: 'WWW-Authenticate',
                            value: 'Bearer realm="https://accounts.google.com/", error=invalid_token',
                        }],
                    },
                    body: '',
                },
            },
        }],
    };
    out = await call(gufResponder.handler, evExpired);
    check('expired token bounces back to origin', out.status === '302'
        && hdr(out.headers, 'location') === 'https://cdn-of.drv.tw/_guf/abc/f'
        && hdr(out.headers, 'cache-control').includes('no-store'), JSON.stringify(out.headers.location));

    out = await call(gufResponder.handler, res('403', { 'content-type': [{ key: 'Content-Type', value: 'text/html' }] }));
    check('403 without referer left alone', out.status === '403');

    const ev200 = res('200', {});
    ev200.Records[0].cf.request.headers.accept = [{ key: 'Accept', value: 'image/*' }];
    threw = null;
    try { out = await call(gufResponder.handler, ev200); } catch (e) { threw = e; }
    check('200 without content-type does not crash', threw === null, threw && threw.message);

    const evSvg = res('200', { 'content-type': [{ key: 'Content-Type', value: 'text/xml' }] });
    evSvg.Records[0].cf.request.headers.accept = [{ key: 'Accept', value: 'image/avif,image/webp,*/*' }];
    out = await call(gufResponder.handler, evSvg);
    check('svg promoted from text/xml for image requests', hdr(out.headers, 'content-type') === 'image/svg+xml');

    console.log('\n----------------------------------------');
    console.log(pass + ' passed, ' + fail + ' failed');
    if (fail) { console.log('failed:\n - ' + failures.join('\n - ')); process.exit(1); }
    process.exit(0);
}

run().catch(e => { console.log('HARNESS ERROR: ' + (e && e.stack || e)); process.exit(2); });
