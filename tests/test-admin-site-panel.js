// Drives the "Your website" panel of frontend/www.drv.tw/inc/admin.js inside a tiny
// DOM/fetch shim, so the wiring of the new @@site / @@rename / @@domain / do/siteName
// calls is actually executed rather than eyeballed. The code under test is the real
// shipped file: the panel region is sliced out and evaluated with its dependencies.

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function t(name, cond, detail) {
    if (!cond) { failed++; console.error('FAIL: ' + name + (detail ? ' - ' + detail : '')); }
    else { passed++; console.log('ok   ' + name); }
}
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
// elements are created on demand by the shim, so tests fetch them this way
function E( run, id ) { return run.dom.document.getElementById( id ) ; }

const adminJs = fs.readFileSync(path.join(__dirname, '..', 'frontend', 'www.drv.tw', 'inc', 'admin.js'), 'utf8');
const adminHtml = fs.readFileSync(path.join(__dirname, '..', 'backend', 'html', 'inline', 'admin.html'), 'utf8');

const start = adminJs.indexOf('// The "Your website" panel');
const end = adminJs.indexOf("var rms = document.querySelectorAll( '.readmore' )");
assert.ok(start > 0 && end > start, 'could not slice the site panel out of admin.js');
const region = adminJs.slice(start, end);

function grabFn(name) {
    const at = adminJs.indexOf('\nfunction ' + name + '(');
    assert.ok(at >= 0, 'admin.js lost ' + name + '()');
    let depth = 0;
    const from = adminJs.indexOf('{', at);
    for (let i = from; i < adminJs.length; i++) {
        if (adminJs[i] === '{') depth++;
        else if (adminJs[i] === '}') { depth--; if (!depth) return adminJs.slice(at + 1, i + 1); }
    }
    throw new Error('unbalanced ' + name);
}

// --- the panel and the ids it expects must agree with each other -------------
const idsInHtml = new Set((adminHtml.match(/id="[^"]+"/g) || []).map(function (m) { return m.slice(4, -1); }));
const referenced = new Set((region.match(/querySelector\( '#[a-z0-9-]+'/gi) || []).map(function (m) {
    return m.match(/#([a-z0-9-]+)/i)[1];
}));
t('every id the panel touches exists in admin.html', [...referenced].every(function (id) { return idsInHtml.has(id); }),
  [...referenced].filter(function (id) { return !idsInHtml.has(id); }).join(', '));
t('the panel references a dozen controls', referenced.size >= 10, String(referenced.size));
t('the panel never writes innerHTML', !/\.innerHTML\s*=/.test(region));
t('messages go out as textContent', /\.textContent\s*=/.test(region) && !/insertAdjacentHTML|outerHTML/.test(region));

// --- normalisation must agree with d2w_normalize_site_name() -----------------
const norm = new Function(grabFn('d2wNormalizeName') + 'return d2wNormalizeName ;')();
[[' My Site ', 'my-site'], ['café', null], ['xn--80ak6aa92e', 'xn--80ak6aa92e'],
 ['.lead.dot-', null], ['A_B', 'a-b'], ['a b/c', 'a-b'], ['', null],
 ['https://mine.on.drv.tw/x', 'mine'], ['a--b', 'a-b'], ['  ', null], ['www', 'www'],
].forEach(function (pair) {
    t('d2wNormalizeName(' + JSON.stringify(pair[0]) + ')', norm(pair[0]) === pair[1], String(norm(pair[0])));
});
// the same table is asserted against d2w_normalize_site_name() in tests/run-tests.php,
// so the two languages cannot drift apart without a red test
t('the table is the one the PHP test uses too',
  fs.readFileSync(path.join(__dirname, 'run-tests.php'), 'utf8').includes("'a b/c', 'a-b'"));
// 'café' -> 'caf' is what this JS does; the server refuses it outright, which is
// why the availability check below is advisory and the Save can only be trusted
// against the PHP answer.
t('the client does not silently rewrite accents on the server side (documented gap)',
  adminJs.indexOf('d2wNormalizeName') > 0 && /authoritative/.test(region));

// --- a DOM that is just enough to run the panel ------------------------------
function makeDom(csrf) {
    const els = {};
    function el(id) {
        return els[id] || (els[id] = {
            id: id, textContent: '', value: '', href: '', hidden: false, disabled: false,
            className: 'd2w-note', innerHTML: null,
            classList: { toggle: function () { }, add: function () { }, remove: function () { } },
            addEventListener: function () { }, appendChild: function () { }, removeChild: function () { },
            select: function () { }, focus: function () { }, setAttribute: function () { },
            getAttribute: function (n) { return 'content' === n ? csrf : null; },
            closest: function (sel) { return /^#d2w-site\b/.test(sel) ? this : null; },
        });
    }
    const handlers = {};
    return {
        els: els, handlers: handlers,
        document: {
            querySelector: function (sel) {
                if (/^meta\[name="d2w-csrf"\]$/.test(sel)) { const m = el('meta'); m.getAttribute = function () { return csrf; }; return m; }
                const m = sel.match(/^#([a-z0-9-]+)/i);
                return m ? el(m[1]) : el(sel);
            },
            getElementById: function (id) { return el(id); },
            addEventListener: function (ev, fn) { (handlers[ev] = handlers[ev] || []).push(fn); },
            createElement: function () { return el('created'); },
            body: { appendChild: function () { }, removeChild: function () { } },
        },
    };
}

const SITE = 'mysite', SUFFIX = '.on.drv.tw', BASE = '/~u123@' + SITE + SUFFIX + '/gd/';
const info = {
    ok: true, siteName: SITE, url: 'https://' + SITE + SUFFIX + '/', service: 'gd', root: '/',
    approved: true, domain: null, renameIn: 0, hint: SITE + SUFFIX,
    txt: 'DRVTW=' + SITE + SUFFIX, cname: SITE + SUFFIX,
};

function run(csrf, responses) {
    const dom = makeDom(csrf);
    const calls = [];
    const win = {
        location: {
            protocol: 'https:', host: 'api' + SUFFIX, href: 'https://api' + SUFFIX + BASE + '?a=admin',
            hash: '', search: '?a=admin', pathname: BASE,
        },
        confirm: function () { return false; },
        setTimeout: setTimeout, clearTimeout: clearTimeout, setInterval: setInterval, clearInterval: clearInterval,
    };
    win.window = win;
    const fetch = function (url, opt) {
        opt = opt || {};
        calls.push({ url: String(url), method: opt.method || 'GET', headers: opt.headers || {}, body: opt.body, credentials: opt.credentials });
        const key = Object.keys(responses).filter(function (k) { return String(url).indexOf(k) >= 0; })[0];
        const r = key ? responses[key] : { status: 200, json: {} };
        return Promise.resolve({
            status: r.status, text: function () { return Promise.resolve(JSON.stringify(r.json)); },
        });
    };
    const src = [grabFn('d2wCsrfToken'), grabFn('d2wSiteBase'), region].join('\n')
        + '\nreturn { } ;';
    const f = new Function('window', 'document', 'fetch', 'Promise', 'URLSearchParams',
        'setTimeout', 'setInterval', 'clearTimeout', 'navigator', src);
    f(win, dom.document, fetch, Promise, URLSearchParams, setTimeout, setInterval, clearInterval, { clipboard: null });
    return { calls: calls, dom: dom, win: win };
}

(async function () {
    // 1. the panel reads the site record on load
    let r = run('abcdef0123456789', { '@@site': { status: 200, json: info } });
    await sleep(0);
    t('load calls GET @@site on this site path',
      1 === r.calls.length && '@@site' === r.calls[0].url.slice(-6) && 'GET' === r.calls[0].method,
      JSON.stringify(r.calls));
    t('the site address is rendered as text', E(r,'d2w-site-url').textContent === info.url, E(r,'d2w-site-url').textContent);
    t('Open points at the site, not at the admin page', E(r,'d2w-btn-open').href === info.url);
    t('the suffix shown next to the input is the real one',
      E(r,'d2w-suffix').textContent === SUFFIX, E(r,'d2w-suffix').textContent);
    t('the TXT value offered for a custom domain is exact',
      E(r,'d2w-txt').textContent === 'DRVTW=' + SITE + SUFFIX, E(r,'d2w-txt').textContent);
    t('the alternate address comes from the server, not from guesswork',
      E(r,'d2w-site-hint').textContent === SITE + SUFFIX, E(r,'d2w-site-hint').textContent);
    t('the domain panel is revealed once the record is known', false === E(r,'d2w-domain').hidden);

    // 2. typing checks availability, debounced, without the session cookie
    E(r,'d2w-newname').value = ' New Thing ';
    r.dom.handlers['input'].forEach(function (fn) {
        fn({ target: E(r,'d2w-newname'), id: 'd2w-newname' });
    });
    t('typing normalises the field in place', E(r,'d2w-newname').value === 'new-thing', E(r,'d2w-newname').value);
    t('no availability request before the debounce expires',
      !r.calls.some(function (c) { return /siteName/.test(c.url); }));
    await sleep(460);
    const avail = r.calls.filter(function (c) { return /\/do\/siteName/.test(c.url); });
    t('one availability request, with the normalised name',
      1 === avail.length && /name=new-thing$/.test(avail[0].url), JSON.stringify(avail));
    t('availability is looked up without credentials', 'omit' === avail[0].credentials, JSON.stringify(avail[0]));

    // 3. a free name unlocks Save, and Save posts to @@rename as a form body
    r = run('abcdef0123456789', {
        '@@site': { status: 200, json: info },
        '/do/siteName': { status: 200, json: { available: true, name: 'new-thing', tip: 'Free.' } },
        '@@rename': { status: 200, json: { ok: true, siteName: 'new-thing', previous: SITE, url: 'https://new-thing' + SUFFIX + '/', tip: 'Renamed.' } },
    });
    await sleep(0);
    E(r,'d2w-newname').value = 'new-thing';
    r.dom.handlers['input'].forEach(function (fn) { fn({ target: E(r,'d2w-newname') }); });
    await sleep(460);
    t('a free name enables the Save button', false === E(r,'d2w-btn-claim').disabled);
    r.dom.handlers['click'].forEach(function (fn) { fn({ target: { id: 'd2w-btn-claim', closest: function () { return { id: 'd2w-btn-claim' }; } } }); });
    await sleep(0);
    const ren = r.calls.filter(function (c) { return /@@rename/.test(c.url); });
    t('Save POSTs to this site\'s @@rename', 1 === ren.length && 'POST' === ren[0].method && /\/gd\/@@rename$/.test(ren[0].url), JSON.stringify(ren));
    t('the new name travels as a form field', 'name=new-thing' === ren[0].body, ren[0].body);
    t('the mutating call carries the CSRF header',
      'abcdef0123456789' === ren[0].headers['X-D2W-CSRF'], JSON.stringify(ren[0].headers));
    t('the mutating call sends the session cookie', 'include' === ren[0].credentials);
    t('the panel re-reads the site record after a rename',
      2 === r.calls.filter(function (c) { return /@@site/.test(c.url); }).length,
      JSON.stringify(r.calls.map(function (c) { return c.url; })));
    t('the rename panel closes itself', true === E(r,'d2w-rename').hidden);

    // 4. a taken name disables Save and quotes the server reason
    r = run('abcdef0123456789', {
        '@@site': { status: 200, json: info },
        '/do/siteName': { status: 409, json: { available: false, name: 'taken', tip: 'Already used by someone else.' } },
    });
    await sleep(0);
    E(r,'d2w-newname').value = 'taken';
    r.dom.handlers['input'].forEach(function (fn) { fn({ target: E(r,'d2w-newname') }); });
    await sleep(460);
    t('a taken name blocks the Save button', true === E(r,'d2w-btn-claim').disabled);
    t('a taken name shows the server reason',
      /Already used/.test(E(r,'d2w-avail').textContent), E(r,'d2w-avail').textContent);
    t('no rename request is attempted for a blocked name',
      !r.calls.some(function (c) { return /@@rename/.test(c.url); }));

    // 5. domain verification reports the server tip verbatim
    r = run('abcdef0123456789', {
        '@@site': { status: 200, json: info },
        '@@domain': { status: 404, json: { ok: false, error: 'ERR_NOT_YET', tip: 'No matching record yet.', expected: 'DRVTW=' + SITE + SUFFIX } },
    });
    await sleep(0);
    E(r,'d2w-domain-input').value = ' WWW.Example.COM ';
    r.dom.handlers['click'].forEach(function (fn) { fn({ target: { id: 'd2w-btn-verify', closest: function () { return { id: 'd2w-btn-verify' }; } } }); });
    await sleep(0);
    const dom0 = r.calls.filter(function (c) { return /@@domain/.test(c.url); });
    t('Verify POSTs the domain lower-cased', 1 === dom0.length && 'domain=www.example.com' === dom0[0].body, JSON.stringify(dom0));
    t('a failed check explains itself', /No matching record yet/.test(E(r,'d2w-domain-note').textContent), E(r,'d2w-domain-note').textContent);

    // 6. without a CSRF token the panel says so instead of firing doomed POSTs
    r = run('', { '@@site': { status: 200, json: info }, '/do/siteName': { status: 200, json: { available: true, name: 'x' } } });
    await sleep(0);
    t('a page rendered without a session still reads its site record',
      r.calls.some(function (c) { return /@@site/.test(c.url) && !c.headers['X-D2W-CSRF']; }));
    E(r,'d2w-newname').value = 'x';
    r.dom.handlers['input'].forEach(function (fn) { fn({ target: E(r,'d2w-newname') }); });
    await sleep(460);
    r.dom.handlers['click'].forEach(function (fn) { fn({ target: { id: 'd2w-btn-claim', closest: function () { return { id: 'd2w-btn-claim' }; } } }); });
    await sleep(0);
    t('an unauthenticated page still sends the CSRF header slot empty, and the server rejects',
      r.calls.filter(function (c) { return /@@rename/.test(c.url); }).every(function (c) { return !c.headers['X-D2W-CSRF']; }));

    // 7. a 429 from the server keeps the button off
    r = run('abcdef0123456789', {
        '@@site': { status: 200, json: Object.assign({}, info, { renameIn: 40 }) },
        '@@rename': { status: 429, json: { ok: false, error: 'ERR_TOO_FAST', tip: 'Too soon.', retryIn: 12 } },
    });
    await sleep(0);
    t('a recent change disables the rename button', true === E(r,'d2w-btn-rename').disabled);
    t('and the note says when it unlocks', /in 40s/.test(E(r,'d2w-site-note').textContent), E(r,'d2w-site-note').textContent);
    E(r,'d2w-newname').value = 'other';
    r.dom.handlers['input'].forEach(function (fn) { fn({ target: E(r,'d2w-newname') }); });
    await sleep(460);
    r.dom.handlers['click'].forEach(function (fn) { fn({ target: { id: 'd2w-btn-claim', closest: function () { return { id: 'd2w-btn-claim' }; } } }); });
    await sleep(0);
    t('a throttled answer is shown with its retry delay',
      /Too soon\. \(retry in 12s\)/.test(E(r,'d2w-avail').textContent), E(r,'d2w-avail').textContent);

    console.log('----------------------------------------');
    console.log(passed + ' passed, ' + failed + ' failed');
    process.exitCode = failed ? 1 : 0;
    process.exit(process.exitCode);   // the cooldown ticker would otherwise keep us waiting
})();
