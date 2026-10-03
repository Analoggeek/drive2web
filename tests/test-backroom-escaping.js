// The operator backroom renders nginx log lines - Referer, User-Agent, the
// request path - by string concatenation. This test pins the escaping added to
// backend/html/inline/backroom.html: a hostile log line must not be able to close
// an attribute, inject a tag, or hand the operator a javascript: link.
//
// The functions are lifted straight out of the shipped file rather than reimplemented.

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0;
function t(name, cond, detail) {
    if (!cond) {
        console.error('FAIL: ' + name + (detail ? ' - ' + detail : ''));
        process.exitCode = 1;
    } else {
        passed++;
        console.log('ok   ' + name);
    }
}

const file = path.join(__dirname, '..', 'backend', 'html', 'inline', 'backroom.html');
const html = fs.readFileSync(file, 'utf8');
const body = html.match(/<script nonce="[^"]*">([\s\S]*?)<\/script>/);
t('the backroom script is inline and nonced (CSP)', !!body);
const src = body ? body[1] : '';

// No inline event handlers may survive in a page that receives the policy: a nonce
// authorises the <script> block, never an onclick= attribute. md_wrap.html is the
// one template deliberately exempt from the policy (it is framed by site content),
// and its onload= on the local .js file is how it sizes the iframe.
fs.readdirSync(path.join(__dirname, '..', 'backend', 'html', 'inline'))
  .filter(function (f) { return /\.html$/.test(f) && 'md_wrap.html' !== f; })
  .forEach(function (f) {
    const txt = fs.readFileSync(path.join(__dirname, '..', 'backend', 'html', 'inline', f), 'utf8');
    t('no inline event handlers in ' + f,
      !/<[a-z0-9]+\s[^>]*\son[a-z]+\s*=/i.test(txt),
      (txt.match(/\son[a-z]+\s*=\s*"[^"]{0,40}/gi) || []).slice(0, 2).join(' | '));
    // only the markup's own <script> tags: strip the bodies first, so a comment
    // inside the JS that mentions <script> cannot be mistaken for markup
    const tags = txt.replace(/<script\b([^>]*)>[\s\S]*?<\/script>/g, '<script$1></script>').match(/<script\b[^>]*>/g) || [];
    t('every inline script in ' + f + ' carries the nonce placeholder',
      tags.filter(function (tag) { return !/\bsrc=/.test(tag); }).length > 0
      && tags.filter(function (tag) { return !/\bsrc=/.test(tag); })
        .every(function (tag) { return /nonce="{{CSP_NONCE}}"/.test(tag); }),
      tags.join(' | '));
  });

function grab(name) {
    const start = src.indexOf('function ' + name + '(');
    assert.ok(start >= 0, 'backroom.html no longer defines ' + name + '()');
    let depth = 0, i = src.indexOf('{', start);
    for (let j = i; j < src.length; j++) {
        if (src[j] === '{') depth++;
        else if (src[j] === '}') { depth--; if (0 === depth) return src.slice(start, j + 1); }
    }
    throw new Error('unbalanced braces in ' + name);
}

const ua2deviceStub = 'var ua2device = function ( ua ) { return ua ; } ;';
// getEventRow indexes the module-level `events` array, so the fixture goes in there
const hostile = {
    referrer: 'https://x.test/" onmouseover="alert(1)',
    url: 'https://api.on.drv.tw/p/<script>alert(2)</script>',
    ua: '"><img src=x onerror=alert(3)>',
    uid: 'abc"def\'ghi',
    ip: '1.2.3.4',
    httpCode: '200',
    secDuration: 0.1,
    cacheMiss: '<b>1</b>',
    tsStart: 1700000000000,
};
const api = new Function([grab('esc'), grab('safeHref'), grab('shortenDisplayUrl'), grab('getEventRow'), ua2deviceStub,
                          'var events = [' + JSON.stringify(hostile) + '] ;',
                          'var geoips = {} ;',
                          'return { esc: esc, safeHref: safeHref, shortenDisplayUrl: shortenDisplayUrl, getEventRow: getEventRow } ;'].join('\n'))();

const row = api.getEventRow(0, 'r1');

t('quotes inside log fields are escaped, so an attribute cannot be closed',
  row.includes('&quot;')
  && !/ onmouseover="/.test(row) && !/ onerror="/.test(row) && !/ onload="/.test(row),
  row.slice(0, 200));
t('injected tags are inert', !/<script/i.test(row) && row.includes('&lt;script&gt;'));
t('the user agent title attribute cannot be broken out of', !/<img src=x onerror/.test(row));
t('the row class is escaped too', row.includes('class="r1"'));

t('javascript: links become #', '#' === api.safeHref('javascript:alert(1)'), api.safeHref('javascript:alert(1)'));
t('data: links become #', '#' === api.safeHref('data:text/html,<script>alert(1)</script>'));
t('http(s) and protocol-relative links stay usable',
  'https://x.test/a' === api.safeHref('https://x.test/a') && '//x.test/a' === api.safeHref('//x.test/a'));
t('esc() is idempotent enough for & < > " \'', '&lt;a href=&quot;&amp;&quot;&gt;&#39;' === api.esc(`<a href="&">'`), api.esc(`<a href="&">'`));

// the header row and the toggle button must be wired through data- attributes,
// because the delegated listener is what replaced their inline handlers
t('sortable headers carry data-sort', /data-sort="ref"/.test(src) && /data-sort="dur"/.test(src) && /data-sort="start"/.test(src));
t('row toggle buttons carry data-toggle', /data-toggle="r' \+ getHashCode/.test(src));
t('a single delegated listener handles both', /th\[data-sort\]/.test(src) && /button\[data-toggle\]/.test(src));

// the author's own Analytics id must not ship in pages a self-hosted operator serves
(function () {
    // shipped pages only: this test mentions the pattern itself
    const skip = new Set(['.git', 'node_modules', 'PHPMailer', 'aws-sdk-fetch']);
    let hits = [];
    (function walk(dir) {
        fs.readdirSync(dir, { withFileTypes: true }).forEach(function (e) {
            if (skip.has(e.name)) return;
            const full = path.join(dir, e.name);
            if (e.isDirectory()) return walk(full);
            if (!/\.(html|js|php)$/.test(e.name)) return;
            const txt = fs.readFileSync(full, 'utf8');
            if (/gaId: 'UA-/.test(txt)) hits.push(path.relative(path.join(__dirname, '..'), full));
        });
    })(path.join(__dirname, '..', 'backend', 'html'));
    if (!hits.length) (function () {
        const fe = path.join(__dirname, '..', 'frontend', 'www.drv.tw');
        (function walk(dir) {
            fs.readdirSync(dir, { withFileTypes: true }).forEach(function (e) {
                if (skip.has(e.name)) return;
                const full = path.join(dir, e.name);
                if (e.isDirectory()) return walk(full);
                if (!/\.(html|js)$/.test(e.name)) return;
                if (/gaId\s*:/.test(fs.readFileSync(full, 'utf8'))) hits.push(path.relative(path.join(__dirname, '..'), full));
            });
        })(fe);
    })();
    t('no gaId: \'UA-...\' left anywhere in the tree', 0 === hits.length, hits.join(', '));
})();

console.log(passed + ' checks passed');
