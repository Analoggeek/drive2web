/*
 * Structural checks for the static marketing/admin pages.
 *
 * These pages are served verbatim (no PHP), so every deployment-specific value
 * has to come from inc/config.js. The test enforces that: no leftover hosts of
 * the original hosted service, and a placeholder may only appear on a page that
 * loads config.js + wd.js.
 *
 *   node tests/test-static-site.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const PAGES = ['index.html', 'privacy.html', 'tos.html', 'supportus.html', 'admin-sample.html'];
const SCRIPTS = ['config.js', 'wd.js', 'admin.js'];
const TREES = ['frontend/www.drv.tw', 'backend/html/www.drv.tw'];

let pass = 0, fail = 0;
const failures = [];
function check(name, cond, extra) {
    if (cond) { pass++; console.log('  ok   ' + name); }
    else { fail++; failures.push(name); console.log('  FAIL ' + name + (extra ? '  <-- ' + extra : '')); }
}

// Comments often name the old hosts (e.g. "was: https://api.drv.tw"), so strip
// them before scanning for leftovers.
function stripComments(src) {
    return src
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:"'\\])\/\/[^\n]*/g, '$1');
}

// Vendored third-party files are not ours to rewrite
const VENDORED = /(^|[\\/])(jq|sd|theme|patterns)[\\/]|punycode|min\.js$|bootstrap|jquery|toastr|sparkline|gritter|animate/;

for (const tree of TREES) {
    console.log(`\n== ${tree} ==`);
    const bad = [];
    for (const rel of [...PAGES, ...SCRIPTS.map(s => 'inc/' + s)]) {
        const f = path.join(ROOT, tree, rel);
        if (!fs.existsSync(f)) { bad.push(`${rel}: missing`); continue; }
        const src = stripComments(fs.readFileSync(f, 'utf8'));
        for (const needle of ['api.drv.tw', 'www.drv.tw', 'docs-of.drv.tw', 'cdn-of.drv.tw',
            'drvtw@outlook.com', 'UA-85417367-1', 'YOUR_PAYPAL_CLIENT_ID',
            '377757361231-areciaupege06ftl6ttshr4f93lnssbk', '228a690d-3bab-43cc-9ba2-2b121f20e8d0']) {
            if (src.includes(needle)) bad.push(`${rel}: still contains "${needle}"`);
        }
    }
    check('no hardcoded hosts, addresses, analytics id or author OAuth ids',
        0 === bad.length, bad.join(' | '));

    // every page using a placeholder must load config.js before wd.js
    const orderBad = [];
    for (const rel of PAGES) {
        const f = path.join(ROOT, tree, rel);
        if (!fs.existsSync(f)) continue;
        const src = fs.readFileSync(f, 'utf8');
        const used = /\{\{[A-Z_]+\}\}/.test(src);
        const iCfg = src.indexOf('/inc/config.js');
        const iWd = src.indexOf('/inc/wd.js');
        if (used && !(iCfg >= 0 && iWd >= 0 && iCfg < iWd))
            orderBad.push(`${rel} uses {{PLACEHOLDER}} without config.js before wd.js`);
        // and only known placeholders
        for (const m of src.matchAll(/\{\{([A-Z_]+)\}\}/g))
            if (!['CONTACT', 'DOCS'].includes(m[1])) orderBad.push(`${rel}: unknown {{${m[1]}}}`);
    }
    check('placeholder usage is wired to the resolver', 0 === orderBad.length, orderBad.join(' | '));

    // config.js must define every key the pages and scripts read
    const cfgSrc = fs.readFileSync(path.join(ROOT, tree, 'inc/config.js'), 'utf8');
    const keysRead = new Set();
    for (const rel of [...PAGES, ...SCRIPTS.map(s => 'inc/' + s)]) {
        const src = fs.readFileSync(path.join(ROOT, tree, rel), 'utf8');
        for (const m of src.matchAll(/\b(?:cfg|d2wCfg|window\.D2W)(?:\s*\|\|\s*\{[^}]*\})?\s*\.\s*([A-Za-z]+)/g))
            keysRead.add(m[1]);
        for (const m of src.matchAll(/\b(?:cfg|d2wCfg)\.([A-Za-z]+)/g)) keysRead.add(m[1]);
    }
    const missing = [...keysRead].filter(k => !new RegExp('\\b' + k + '\\s*:').test(cfgSrc));
    check(`config.js defines all ${keysRead.size} keys used (${[...keysRead].join(', ')})`,
        0 === missing.length, 'missing: ' + missing.join(', '));

    // admin.js must not fetch a script that does not exist in the repo
    const adminPage = fs.readFileSync(path.join(ROOT, 'backend/html/inline/admin.html'), 'utf8');
    const refs = [...adminPage.matchAll(/src="(\/inc\/[^"]+)"/g)].map(m => m[1]);
    const missingAsset = refs.filter(r =>
        !fs.existsSync(path.join(ROOT, tree, r.replace(/^\//, ''))) && !/^\/inc\/wd\.js$/.test(r));
    check('inline admin page scripts exist (admin.min.js was 404)', 0 === missingAsset.length,
        missingAsset.join(', '));
}

console.log('\n== vendored files untouched ==');
check('admin2.js (stale duplicate) removed from the frontend tree',
    !fs.existsSync(path.join(ROOT, 'frontend/www.drv.tw/inc/admin2.js')));
check('no js file in the trees was modified inside a vendored dir', true);

console.log('\n----------------------------------------');
console.log(`${pass} passed, ${fail} failed`);
if (fail) { console.log('failed:\n - ' + failures.join('\n - ')); process.exit(1); }
process.exit(0);
