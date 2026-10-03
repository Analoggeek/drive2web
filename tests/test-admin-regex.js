/*
 * Exercises the request matcher that ships in frontend/www.drv.tw/inc/admin.js.
 * The helper and the RegExp are pulled out of the real file, so this fails if
 * the pattern in admin.js stops matching the dashboard's own URLs.
 *
 *   node tests/test-admin-regex.js
 */
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '../frontend/www.drv.tw/inc/admin.js'), 'utf8');

const helper = src.match(/function d2wEscapeRe\([\s\S]*?\n}/);
if (!helper) { console.log('FAIL: d2wEscapeRe() not found in admin.js'); process.exit(1); }

const stmt = src.match(/new RegExp\(([\s\S]*?)\) \) ;/);
if (!stmt) { console.log('FAIL: could not find the api matcher in admin.js'); process.exit(1); }

const build = new Function('apiHost', helper[0] + '; return new RegExp(' + stmt[1] + ') ;');

let bad = 0;
function check(name, cond) {
    console.log((cond ? 'ok   ' : 'FAIL ') + name);
    if (!cond) bad++;
}

const re = build('api.drv.tw');
check('matches /~user@x.com/gd/?a=admin', re.test('https://api.drv.tw/~bob@x.com/gd/?a=admin'));
check('matches /~user@x.com/od/ with no query string',
    re.test('https://api.drv.tw/~bob@x.com/od/'));
check('matches a deeper path', re.test('https://api.drv.tw/~bob@x.com/gd/folder/?a=admin'));
check('does not match another host', !re.test('https://evil.example/~bob@x.com/gd/?a=admin'));

const groups = 'https://api.drv.tw/~bob@x.com/gd/?a=admin'.match(re);
check('captures username', groups && groups[1] === 'bob@x.com');
check('captures service code', groups && groups[2] === 'gd');
check('captures query string', groups && groups[4] === 'a=admin');

// Hostnames with dots must not act as wildcards
const reEscaped = build('api.drv.tw');
check('escaped host does not match apixdrvxtw',
    !reEscaped.test('https://apiXdrvXtw/~bob@x.com/gd/?a=admin'));

// Old shipped pattern, for the record
const oldRe = /^https?:\/\/api\.drv\.tw\/~([^@]*@[^\/]*)\/([go]d)\/([^\?]*)\?(.*)$/;
console.log('(for the record) old pattern on a no-query URL: '
    + oldRe.test('https://api.drv.tw/~bob@x.com/gd/'));

process.exit(bad ? 1 : 0);
