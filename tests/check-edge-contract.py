#!/usr/bin/env python3
"""
Cross-check between the PHP origin and the CloudFront edge functions.

The two sides speak only URLs, so a change on one side silently breaks the
other (that is how the shipped /_guf handler ended up requesting
files/<siteName>/<fileId> and 404-ing every user file). This script:

  1. asserts, textually, how index.php composes the proxied URLs,
  2. builds the same URLs with PHP (same encoding calls), and
  3. feeds them to the *actual* shipped handlers through node.

    python3 tests/check-edge-contract.py
"""
import json
import os
import re
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HTML = os.path.join(ROOT, 'backend', 'html')
LAMBDA = os.path.join(ROOT, 'backend', 'cf-lambda')

failures = []


def read(*p):
    with open(os.path.join(*p), encoding='utf-8') as fh:
        return fh.read()


def check(name, cond, extra=''):
    ok = bool(cond)
    print(('  ok   ' if ok else '  FAIL ') + name + (('  <-- ' + str(extra)) if (extra and not ok) else ''))
    if not ok:
        failures.append(name)


def run(cmd, timeout=30):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    except Exception as e:                                     # noqa: BLE001
        class R:
            stdout, stderr = '', str(e)
        return R()


def drive(handler_file, url, extra_js=''):
    """Load the shipped handler and run it against a CloudFront event built
    from `url` exactly as CloudFront would (raw path, raw query string)."""
    js = """
process.env.NODE_NO_WARNINGS = '1';
const { handler } = require(%s);
const u = new URL(%s);
const base = {
    method: 'GET',
    uri: u.pathname,
    querystring: u.search.slice(1),
    headers: {
        origin: [{ key: 'Origin', value: u.protocol + '//' + u.host }],
        referer: [{ key: 'Referer', value: u.protocol + '//' + u.host + u.pathname }],
    },
};
const event = { Records: [{ cf: { request: base } }] };
%s
handler(event, {}).then((r) => {
    const out = {
        status: r.status || null,
        uri: r.uri || null,
        querystring: r.querystring || null,
        host: r.headers && r.headers.host ? r.headers.host[0].value : null,
        origin: r.origin && r.origin.custom ? r.origin.custom.domainName : null,
        auth: r.origin && r.origin.custom && r.origin.custom.customHeaders
            && r.origin.custom.customHeaders.authorization
            ? r.origin.custom.customHeaders.authorization[0].value : null,
    };
    console.log('RESULT' + JSON.stringify(out));
}, (e) => { console.log('THREW' + String(e && e.message)); });
""" % (json.dumps(os.path.join(LAMBDA, handler_file)), json.dumps(url), extra_js)
    r = run(['node', '-e', js])
    for line in (r.stdout or '').split('\n'):
        if line.startswith('RESULT'):
            return json.loads(line[6:])
    return {'threw': (r.stdout + r.stderr).strip()[:300]}


def php(code):
    return run(['php', '-r', code])


def main():
    index = read(HTML, 'index.php')
    guf = read(LAMBDA, 'guf_requestor.js')
    opf = read(LAMBDA, 'opf_requestor.js')

    print('\n== how index.php composes the proxied URLs ==')

    guf_line = next((l.strip() for l in index.split('\n') if "'/_guf/'" in l), '')
    check('Google target is /_guf/<siteName>/<fileId> (site and id in separate segments)',
          re.search(r"_guf/' \. \$siteName \. '/' \. urlencode\( \$idFile \)", guf_line), guf_line)

    opf_line = next((l.strip() for l in index.split('\n') if "'/_opf/'" in l), '')
    ur_line = next((l.strip() for l in index.split('\n') if '?ur=' in l), '')
    check('OneDrive target is /_opf/<siteName>/<itemId> + ?ur=',
          '_opf/' in opf_line and '$siteName' in opf_line and 'rawurlencode(' in ur_line,
          opf_line + ' | ' + ur_line)
    check('the ?ur= value is encoded exactly once (no urldecode() applied to it)',
          'urldecode(' not in ur_line and "rawurlencode( substr( $urlContent, strlen( 'https://' ) ) )" in ur_line,
          ur_line)
    ra_line = next((l.strip() for l in index.split('\n') if '?ra=' in l), '')
    check('the access token travels as ?ra=<urlencoded strrev(token)>',
          "?ra=' . urlencode( strrev( $token[ 'token' ] )" in ra_line, ra_line)

    print('\n== Google edge function vs a real PHP-built URL ==')
    p = php("""
$siteName = 'abc123def456ghi789jkl0' ;
$idFile = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs' ;
$token = 'ya29.a0AWEXAMPLETOKEN' ;
$cdnHost = 'cdn-of.example.test' ;
echo 'https://' . $cdnHost . '/_guf/' . $siteName . '/' . urlencode( $idFile )
    . '?ra=' . urlencode( strrev( $token ) ) ;
""")
    url = (p.stdout or '').strip()
    check('php produced the URL', bool(url), p.stderr.strip())
    got = drive('guf_requestor.js', url, "process.env.GOOGLE_API_KEY = 'unused-because-token';")
    check('handler did not throw', 'threw' not in got, got.get('threw'))
    check('rewritten to the Drive media endpoint',
          got.get('uri') == '/drive/v3/files/' + '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs', got.get('uri'))
    check('sent to www.googleapis.com', got.get('origin') == 'www.googleapis.com', got.get('origin'))
    check('the reversed token is restored to the original',
          got.get('auth') == 'Bearer ya29.a0AWEXAMPLETOKEN', got.get('auth'))
    check('no token left in the query string', got.get('querystring') == 'alt=media', got.get('querystring'))

    print('\n== OneDrive edge function vs a real PHP-built URL ==')
    p2 = php("""
$siteName = 'abc123def456ghi789jkl0' ;
$idFile = 'item!e3JTVzEXAMPLE' ;
$urlContent = 'https://x-internal-1drv.sharepoint.com/sites/d2w/mysite/i/docs/a%20b.pdf?download=1&wid=%7B1%7D' ;
$cdnHost = 'cdn-of.example.test' ;
echo 'https://' . $cdnHost . '/_opf/' . $siteName . '/' . urlencode( $idFile )
    . '?ur=' . rawurlencode( substr( $urlContent, strlen( 'https://' ) ) ) ;
""")
    url2 = (p2.stdout or '').strip()
    check('php produced the URL', bool(url2), p2.stderr.strip())
    got2 = drive('opf_requestor.js', url2)
    check('handler did not throw', 'threw' not in got2, got2.get('threw'))
    check('host taken from ?ur=', got2.get('host') == 'x-internal-1drv.sharepoint.com', got2.get('host'))
    check('origin follows the host',
          got2.get('origin') == 'x-internal-1drv.sharepoint.com', got2.get('origin'))
    check('path-only uri, percent-escapes preserved',
          got2.get('uri') == '/sites/d2w/mysite/i/docs/a%20b.pdf', got2.get('uri'))
    check('target query kept separately',
          got2.get('querystring') == 'download=1&wid=%7B1%7D', got2.get('querystring'))

    print('\n== the allowlist still refuses what PHP would happily be tricked into ==')
    for bad_host in ['169.254.169.254', 'evil.example', 'graph.microsoft.com.attacker.io']:
        p3 = php("""
echo 'https://cdn-of.example.test/_opf/s/i?ur=' . rawurlencode( '%s/sites/x/y' ) ;
""" % bad_host)
        got3 = drive('opf_requestor.js', (p3.stdout or '').strip())
        check('refuses %s' % bad_host, got3.get('status') == '403', json.dumps(got3)[:120])

    print('\n== shared invariants ==')
    check('PHP validates file ids before using them (d2w_valid_file_id)', 'd2w_valid_file_id' in index)
    check('PHP validates generated/reported site names (d2w_valid_site_name)', 'd2w_valid_site_name' in index)
    check('the JS side has its own file id shape check', 'FILE_ID_RE' in guf)
    check('the edge rejects a ur= that is not host/path', "Malformed proxy target" in opf)
    check('edge logs redact credentials', 'redact' in (guf + opf).lower())

    print('\n----------------------------------------')
    if failures:
        print('%d contract check(s) failed:\n - %s' % (len(failures), '\n - '.join(failures)))
        return 1
    print('edge-function URL contract holds')
    return 0


if __name__ == '__main__':
    missing = [t for t in ('node', 'php') if not shutil.which(t)]
    if missing:
        print('SKIP: %s not installed' % ', '.join(missing))
        sys.exit(0)
    sys.exit(main())
