<?php
/**
 * DriveToWeb helper tests — runs the shipped code in
 * backend/html/drvtw_private/drvtw_util.php (no DB, no network writes).
 *
 *   php tests/run-tests.php
 */

error_reporting( E_ALL ) ;
ini_set( 'display_errors', '1' ) ;

// Any PHP diagnostic the code emits while the suite runs is a finding: it is
// how an undefined variable (which php -l cannot see) hides behind a test that
// passes vacuously. Recorded here and asserted at the end.
$GLOBALS[ 'diagnostics' ] = array() ;
set_error_handler( function( $no, $str, $file, $line ) {
    if( 0 === error_reporting() )   // an intentional @ (probing a port, a missing file)
        return FALSE ;
    $GLOBALS[ 'diagnostics' ][] = $str . ' @' . basename( $file ) . ':' . $line ;
    return TRUE ;
} ) ;

$ROOT = dirname( __DIR__ ) ;
$CREDS_FILE = $ROOT . '/backend/html/drvtw_private/drvtw_creds.php' ;
$CREDS_TEMPLATE = $ROOT . '/backend/html/drvtw_private/drvtw_creds.example.php' ;

// index.php requires creds before util; mirror that so the tests exercise the
// same variable set a real request has.
require( file_exists( $CREDS_FILE ) ? $CREDS_FILE : $CREDS_TEMPLATE ) ;
require( $ROOT . '/backend/html/drvtw_private/drvtw_util.php' ) ;

// The shipped logger creates its folder and opens user.log at include time, so
// point it at a fresh temp directory BEFORE requiring it — that is the same
// "first run on a new machine" path that used to fail with a 0640 folder.
$GLOBALS[ 'd2wTestLogDir' ] = sys_get_temp_dir() . '/d2w-log-test-' . getmypid() ;
@exec( 'rm -rf ' . escapeshellarg( $GLOBALS[ 'd2wTestLogDir' ] ) ) ;
putenv( 'D2W_LOG_FOLDER=' . $GLOBALS[ 'd2wTestLogDir' ] ) ;
require( $ROOT . '/backend/html/drvtw_private/drvtw_log.php' ) ;

$pass = 0 ;
$fail = 0 ;
$failures = array() ;

function t( $name, $cond, $detail = '' ) {
    global $pass, $fail, $failures ;
    if( $cond ) {
        $pass++ ;
        echo "  ok   $name\n" ;
    }
    else {
        $fail++ ;
        $failures[] = $name . ( '' !== $detail ? '  [' . $detail . ']' : '' ) ;
        echo "  FAIL $name" . ( '' !== $detail ? "  ($detail)" : '' ) . "\n" ;
    }
}

function section( $title ) {
    echo "\n== $title ==\n" ;
}

// ------------------------------------------------------------------ ids

section( 'Drive file id / site name validation' ) ;
t( 'accepts a real Drive id', TRUE === d2w_valid_file_id( '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms' ) ) ;
t( 'rejects path traversal', FALSE === d2w_valid_file_id( '../../../../etc/passwd' ) ) ;
t( 'rejects slash injection', FALSE === d2w_valid_file_id( 'abc1234567890/permissions' ) ) ;
t( 'rejects query injection', FALSE === d2w_valid_file_id( 'abc1234567890?alt=media' ) ) ;
t( 'rejects fragment injection', FALSE === d2w_valid_file_id( 'abc1234567890#x' ) ) ;
t( 'rejects too-short', FALSE === d2w_valid_file_id( 'abc' ) ) ;
t( 'rejects empty', FALSE === d2w_valid_file_id( '' ) ) ;
t( 'rejects non-string', FALSE === d2w_valid_file_id( NULL ) ) ;
t( 'site name ok', TRUE === d2w_valid_site_name( 'get-free-250k-robux-now1' ) ) ;
t( 'site name rejects host suffix', FALSE === d2w_valid_site_name( 'evil.com/on.drv.tw' ) ) ;
t( 'site name rejects dotdot', FALSE === d2w_valid_site_name( '..' ) ) ;

// ------------------------------------------------------------------ paths

section( 'Decoded path safety' ) ;
t( 'plain path ok', TRUE === d2w_path_is_safe( 'site/index.html' ) ) ;
t( 'encoded traversal caught', FALSE === d2w_path_is_safe( urldecode( '..%2F..%2Fetc' ) ) ) ;
t( 'dotfile allowed', TRUE === d2w_path_is_safe( '.well-known/x' ) ) ;

// ------------------------------------------------------------------ session

section( 'Owner session cookie' ) ;
$userId = '584574ea7674fca77585b481' ;
$other  = '111111111111111111111111' ;
$good = d2w_make_session( $userId, 'gd' ) ;
$Parsed = d2w_parse_session( $good ) ;
t( 'round trip', is_array( $Parsed ) && $Parsed[ 'userId' ] === $userId && $Parsed[ 'service' ] === 'gd' ) ;
t( 'tampered userId rejected', FALSE === d2w_parse_session(
    str_replace( $userId, $other, $good ) ) ) ;
t( 'expired rejected', FALSE === d2w_parse_session( d2w_make_session( $userId, 'gd', -10 ) ) ) ;
t( 'garbage rejected', FALSE === d2w_parse_session( 'nonsense' ) ) ;
t( 'empty rejected', FALSE === d2w_parse_session( '' ) ) ;
t( 'foreign key rejected', FALSE === d2w_parse_session( $good, 'another-secret' ) ) ;

$ownerToken = 'ya29.a0AVvZS5f-OWNER-SECRET-TOKEN' ;
$anon = d2w_privileged_request_allowed( $userId, 'gd', NULL, NULL, $ownerToken ) ;
t( 'anonymous cannot run privileged command', FALSE === $anon ) ;
$withCookie = d2w_privileged_request_allowed( $userId, 'gd', $good, NULL, $ownerToken ) ;
t( 'owner cookie grants access', TRUE === $withCookie ) ;
$otherUsersCookie = d2w_privileged_request_allowed( $userId, 'gd',
    d2w_make_session( $other, 'gd' ), NULL, $ownerToken ) ;
t( "someone else's cookie denied", FALSE === $otherUsersCookie ) ;
$wrongService = d2w_privileged_request_allowed( $userId, 'od', $good, NULL, $ownerToken ) ;
t( 'cookie for other service denied', FALSE === $wrongService ) ;
$bearer = d2w_privileged_request_allowed( $userId, 'gd', NULL, 'Bearer ' . $ownerToken, $ownerToken ) ;
t( 'matching bearer token grants access', TRUE === $bearer ) ;
$bearerWrong = d2w_privileged_request_allowed( $userId, 'gd', NULL, 'Bearer attacker-token', $ownerToken ) ;
t( 'wrong bearer token denied', FALSE === $bearerWrong ) ;
$bearerNoServerToken = d2w_privileged_request_allowed( $userId, 'gd', NULL, 'Bearer whatever', NULL ) ;
t( 'bearer denied when owner has no token', FALSE === $bearerNoServerToken ) ;

// ------------------------------------------------------------------ ops token

section( 'Operator log token' ) ;
$secret = 'unit-test-ops-secret' ;
$tok = d2w_ops_token( $secret ) ;
t( 'token verifies', TRUE === d2w_ops_token_valid( $tok, $secret ) ) ;
t( 'token is 64 hex chars', 1 === preg_match( '/^[0-9a-f]{64}$/', $tok ) ) ;
// The shipped bug: sha1(hour + 919) - anyone can compute it.
$predictable = sha1( floor( time() / 60 / 60 ) + 919 ) ;
t( 'old predictable token rejected', FALSE === d2w_ops_token_valid( $predictable, $secret ) ) ;
t( 'forged token rejected', FALSE === d2w_ops_token_valid( 'deadbeef', $secret ) ) ;
t( 'token from other secret rejected', FALSE === d2w_ops_token_valid( $tok, 'other-secret' ) ) ;
t( 'basic auth accepted', TRUE === d2w_ops_authorized( NULL, 'ops', 'pw123', 'ops', 'pw123', $secret ) ) ;
t( 'basic auth wrong pw rejected', FALSE === d2w_ops_authorized( NULL, 'ops', 'nope', 'ops', 'pw123', $secret ) ) ;
t( 'wrong basic auth does not fall through to token',
    FALSE === d2w_ops_authorized( d2w_ops_token( $secret ), 'ops', 'nope', 'ops', 'pw123', $secret ) ) ;
t( 'token accepted when no basic auth sent',
    TRUE === d2w_ops_authorized( d2w_ops_token( $secret ), NULL, NULL, 'ops', 'pw123', $secret ) ) ;
t( 'no creds configured and no token -> denied',
    FALSE === d2w_ops_authorized( NULL, NULL, NULL, 'ops', 'pw123', $secret ) ) ;

// ------------------------------------------------------------------ email

section( 'Recipient email validation (sendAuthCode)' ) ;
$blocked = array( '@bulatox.com', 'yofab22664@hutov.com' ) ;
t( 'plain address deliverable', TRUE === d2w_check_recipient_email( 'john@example.org', $blocked ) ) ;
t( 'normalized before use', 'john@example.org' === d2w_normalize_email( ' JOHN@Example.ORG ' ) ) ;
t( 'no @ rejected', 'invalid' === d2w_check_recipient_email( 'nobody-at-all', $blocked ) ) ;
t( 'empty rejected', 'invalid' === d2w_check_recipient_email( '', $blocked ) ) ;
t( 'two @ rejected', 'invalid' === d2w_check_recipient_email( 'a@b.com@evil.com', $blocked ) ) ;
t( 'CRLF in address rejected', 'invalid' === d2w_check_recipient_email( "a@b.com\r\nBcc: x@y.com", $blocked ) ) ;
t( 'blocked domain rejected', 'blocked' === d2w_check_recipient_email( 'phish@bulatox.com', $blocked ) ) ;
t( 'blocked full address rejected', 'blocked' === d2w_check_recipient_email( 'yofab22664@hutov.com', $blocked ) ) ;
t( 'gmail rejected as unacceptable', 'unacceptable' === d2w_check_recipient_email( 'x@gmail.com', $blocked ) ) ;
t( 'outlook rejected as unacceptable', 'unacceptable' === d2w_check_recipient_email( 'x@outlook.com', $blocked ) ) ;
t( 'dead domain unreachable', 'unreachable' === d2w_check_recipient_email( 'x@no-such-domain-zz9q7.invalid', $blocked ) ) ;
// Regression: the original expression never ran dns_get_mx() at all.
$orig = 'a@b' ;
$atpos = strpos( $orig, '@' ) || !strpos( $orig, 'zz' ) ;
t( 'documents original short-circuit bug ($atpos became a bool)', TRUE === $atpos ) ;

// ------------------------------------------------------------------ redirects

section( 'Auth-code redirect target (open redirect)' ) ;
t( 'relative path kept', '/some/page.html' === d2w_safe_site_redirect( '/some/page.html', 'abc', 'drv.tw' ) ) ;
t( 'site subdomain kept',
    'https://abc.on.drv.tw/x?a=1' === d2w_safe_site_redirect( 'https://abc.on.drv.tw/x?a=1', 'abc', 'drv.tw' ) ) ;
t( 'external host rejected', '/' === d2w_safe_site_redirect( 'https://evil.example/x', 'abc', 'drv.tw' ) ) ;
t( 'suffix-confusion rejected', '/' === d2w_safe_site_redirect( 'https://abc.on.drv.tw.evil.com/', 'abc', 'drv.tw' ) ) ;
t( 'lookalike prefix rejected', '/' === d2w_safe_site_redirect( 'https://abc.on.drv.tw.attacker.io/', 'abc', 'drv.tw' ) ) ;
t( 'protocol-relative rejected', '/' === d2w_safe_site_redirect( '//evil.com/x', 'abc', 'drv.tw' ) ) ;
t( 'javascript url rejected', '/' === d2w_safe_site_redirect( 'javascript:alert(1)', 'abc', 'drv.tw' ) ) ;
t( 'junk rejected', '/' === d2w_safe_site_redirect( 'not a url', 'abc', 'drv.tw' ) ) ;
t( 'missing url -> root', '/' === d2w_safe_site_redirect( NULL, 'abc', 'drv.tw' ) ) ;

section( 'CORS origin reflection' ) ;
$allowed = array( 'www.drv.tw', 'api.drv.tw', 'drv.tw' ) ;
t( 'allowlisted origin reflected', 'https://www.drv.tw' === d2w_cors_origin( 'https://www.drv.tw', $allowed, '.on.drv.tw' ) ) ;
t( 'site origin reflected', 'https://abc.on.drv.tw' === d2w_cors_origin( 'https://abc.on.drv.tw', $allowed, '.on.drv.tw' ) ) ;
t( 'arbitrary origin dropped', FALSE === d2w_cors_origin( 'https://evil.example', $allowed, '.on.drv.tw' ) ) ;
t( 'null origin dropped', FALSE === d2w_cors_origin( 'null', $allowed, '.on.drv.tw' ) ) ;
t( 'origin with path dropped', FALSE === d2w_cors_origin( 'https://abc.on.drv.tw/x', $allowed, '.on.drv.tw' ) ) ;
t( 'suffix-confusion origin dropped', FALSE === d2w_cors_origin( 'https://abc.on.drv.tw.evil.com', $allowed, '.on.drv.tw' ) ) ;

// ------------------------------------------------------------------ encoding / accept

section( 'BOM sniffing + Accept parsing' ) ;
t( 'utf8 bom', 'UTF-8' === detect_utf_encoding( "\xEF\xBB\xBF<p>hi</p>" ) ) ;
t( 'utf32be bom (was a 3-byte compare bug)', 'UTF-32BE' === detect_utf_encoding( "\x00\x00\xFE\xFF<p>" ) ) ;
t( 'utf32le bom (was a 3-byte compare bug)', 'UTF-32LE' === detect_utf_encoding( "\xFF\xFE\x00\x00<p>" ) ) ;
t( 'utf16le bom', 'UTF-16LE' === detect_utf_encoding( "\xFF\xFE<p>" ) ) ;
t( 'no bom', FALSE === detect_utf_encoding( '<p>hi</p>' ) ) ;
t( 'repeated calls do not re-define constants (no warning)',
    'UTF-8' === detect_utf_encoding( "\xEF\xBB\xBFx" ) ) ;
$accepted = d2w_parse_accept( 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8' ) ;
t( 'accept parse: q=0.9 kept', abs( $accepted[ 'application/xhtml+xml' ] - 0.9 ) < 0.0001 ) ;
t( 'accept parse: bare type defaults to 1', 1.0 === $accepted[ 'text/html' ] ) ;
t( 'integer q accepted (old regex needed a dot)', 0.0 === d2w_parse_accept( 'image/*;q=0' )[ 'image/*' ] ) ;
t( 'leading-dot q accepted', 0.8 === d2w_parse_accept( '*/*;q=.8' )[ '*/*' ] ) ;
t( 'acceptQuality honours q=0', 0.0 === acceptQuality( 'text/html;q=0,*/*;q=0', 'image/png' ) ) ;
t( 'acceptQuality exact match', 1.0 === acceptQuality( 'text/html', 'text/html' ) ) ;
t( 'acceptQuality wildcard subtype', 0.5 === acceptQuality( 'image/*;q=0.5', 'image/png' ) ) ;
t( 'acceptQuality unmatched -> 0', 0.0 === acceptQuality( 'video/mp4', 'text/html' ) ) ;

section( 'MIME from extension' ) ;
t( 'html', 'text/html' === d2w_mime_from_extension( 'Index.HTML' ) ) ;
t( 'svg', 'image/svg+xml' === d2w_mime_from_extension( 'a/b/logo.svg?v=2' ) ) ;
t( 'unknown -> NULL', NULL === d2w_mime_from_extension( 'noext' ) ) ;

// ------------------------------------------------------------------ blacklist

section( 'Phishing signature matcher' ) ;
$sigs = array(
    array( 'Click', array( 'Bancolombia' ) ) ,
    array( array( '<iframe ', '<frame' ), array( '>.::.<' ) ) ,
    'needle-only',
) ;
t( 'key + signature matched', array( 0, 0 ) === d2w_match_blacklist( 'a Click b Bancolombia c', $sigs ) ) ;
t( 'signature missing -> safe', FALSE === d2w_match_blacklist( 'just a Click here', $sigs ) ) ;
t( 'key at offset 0 matches (was !strpos bug)',
    array( 0, 0 ) === d2w_match_blacklist( 'Click Bancolombia', $sigs ) ) ;
t( 'alt key matched', array( 1, 0 ) === d2w_match_blacklist( "<frame x>.::.<", $sigs ) ) ;
t( 'iframe key matched', array( 1, 0 ) === d2w_match_blacklist( 'x<iframe y>.::.<', $sigs ) ) ;
t( 'plain string entry matched', array( 2, -1 ) === d2w_match_blacklist( 'abc needle-only def', $sigs ) ) ;
t( 'clean page safe', FALSE === d2w_match_blacklist( '<p>my portfolio</p>', $sigs ) ) ;
// The shipped behaviour for a key that only appears at index 0 while the
// signature list is long must still be a match:
$one = array( array( array( 'Click' ), array( 'Bancolombia' ) ) ) ;
t( 'grouped-key page starting with key matched',
    array( 0, 0 ) === d2w_match_blacklist( 'Click Bancolombia', $one ) ) ;

// ------------------------------------------------------------------ site names

section( 'Generated site names' ) ;
$name = d2w_generate_site_name( $userId, 'gd' ) ;
t( 'valid label', TRUE === d2w_valid_site_name( $name ) ) ;
t( '22 chars', 22 === strlen( $name ) ) ;
$n2 = d2w_generate_site_name( $userId, 'gd' ) ;
t( 'random when unseeded (names are unguessable secrets)', $name !== $n2 ) ;
$d1 = d2w_generate_site_name( $userId, 'gd', 0, 'shared-secret' ) ;
$d2 = d2w_generate_site_name( $userId, 'gd', 0, 'shared-secret' ) ;
t( 'seeded generation is reproducible', $d1 === $d2 ) ;
t( 'seeded generation differs per user', $d1 !== d2w_generate_site_name( $other, 'gd', 0, 'shared-secret' ) ) ;
t( 'not the old md5(userId.service) form',
    $d1 !== strtolower( preg_replace( '/\+|\//', 'z', str_replace( '=', '', base64_encode( md5( $userId . '.gd', TRUE ) ) ) ) ) ) ;


section( 'Site naming: what the owner may claim' ) ;

// d2w_normalize_site_name() is forgiving on input and strict on the result; the
// out-param tells the UI whether it had to tidy something up, so the owner is
// never surprised by an address they did not type.
$GLOBALS[ 'd2wBaseDomain' ] = 'drv.tw' ;
$GLOBALS[ 'd2wSiteSuffix' ] = '.on.drv.tw' ;
foreach( array(
        array( ' My Site ', 'my-site', TRUE ) ,
        array( 'https://Mine.On.Drv.tw/x', 'mine', TRUE ) ,
        array( 'a--b', 'a-b', TRUE ) ,
        array( 'A_B', 'a-b', TRUE ) ,
        array( 'abc.', 'abc', TRUE ) ,
        // shared with tests/test-admin-site-panel.js, which asserts the browser
        // mirror of this function agrees on exactly these inputs
        array( 'a b/c', 'a-b', TRUE ) ,
        array( 'www', 'www', FALSE ) ,
        array( 'my-site', 'my-site', FALSE ) ,
        // punycode is kept intact long enough to be refused with a real message
        array( 'xn--80ak6aa92e', 'xn--80ak6aa92e', FALSE ) ,
        // accents are refused, never transliterated
        array( "caf\u{00e9}", NULL, NULL ) ,
        array( '.lead.dot-', NULL, NULL ) ,
        array( '', NULL, NULL ) ,
        array( '   ', NULL, NULL ) ,
    ) as $case ) {
    $changed = NULL ;
    $got = d2w_normalize_site_name( $case[ 0 ], $changed ) ;
    t( 'normalize ' . var_export( $case[ 0 ], TRUE ) . ' -> ' . var_export( $case[ 1 ], TRUE ),
        $got === $case[ 1 ] && ( NULL === $case[ 2 ] || $changed === $case[ 2 ] ),
        var_export( $got, TRUE ) . ' changed=' . var_export( $changed, TRUE ) ) ;
}

$reserved = d2w_site_name_reserved() ;
foreach( array( 'www', 'api', 'mail' ) as $r )
    t( 'reserved name ' . $r . ' is on the list', in_array( $r, $reserved ) ) ;

foreach( array(
        array( '', FALSE ) ,
        array( 'ab', FALSE ) ,                        // below $siteNameMinLength
        array( 'My-Site', FALSE ) ,                   // not lower-cased yet
        array( 'my-site', TRUE ) ,
        array( '3d-print', TRUE ) ,
        array( '123456', FALSE ) ,                    // digits only: IP-lookalike
        array( '1.2.3.4', FALSE ) ,                   // never survives normalization
        array( 'www', FALSE ) ,
        array( 'xn--fiqs8s', FALSE ) ,
        array( 'trailing-', FALSE ) ,
        array( str_repeat( 'a', 64 ), FALSE ) ,
    ) as $case ) {
    list( $ok, $why ) = d2w_site_name_claimable( $case[ 0 ], $reserved ) ;
    t( 'claimable ' . var_export( $case[ 0 ], TRUE ) . ' = ' . ( $case[ 1 ] ? 'yes' : 'no' ),
        $ok === $case[ 1 ], (string) $why ) ;
    if( !$case[ 1 ] )
        t( '  ...with a message a person can act on', '' !== trim( (string) $why ) && $why !== 'OK', (string) $why ) ;
}

// 'www' clears the length floor, so the reserved rule is what has to speak up
list( $ok, $why ) = d2w_site_name_claimable( 'www', $reserved ) ;
t( 'a reserved name is refused as reserved, not as too short',
    FALSE === $ok && FALSE !== stripos( $why, 'service itself' ), (string) $why ) ;

t( 'public URL is built from the site name and suffix',
    'https://my-site.on.drv.tw/' === d2w_site_public_url( 'my-site', 'drv.tw', TRUE )
        && 'http://my-site.on.drv.tw/' === d2w_site_public_url( 'my-site', 'drv.tw', FALSE ) ) ;
t( 'a path is joined without doubling or dropping the slash',
    'https://x.on.drv.tw/docs' === d2w_site_public_url( 'x', 'drv.tw', TRUE, '/docs/' )
        && 'https://x.on.drv.tw/a.html' === d2w_site_public_url( 'x', 'drv.tw', TRUE, 'a.html' ) ) ;
$hint = d2w_site_domain_hint( 'my-site', 'drv.tw' ) ;
t( 'the hint spells the DNS record out, TXT name and value',
    'TXT' === $hint[ 'type' ] && 'DRVTW=my-site.on.drv.tw' === $hint[ 'value' ],
    json_encode( $hint ) ) ;
t( 'the same helper works before a name is chosen',
    'DRVTW=<site>.on.drv.tw' === d2w_site_domain_hint( NULL, 'drv.tw' )[ 'value' ] ) ;

// ------------------------------------------------------------------ escaping

section( 'Output escaping' ) ;
t( 'html escaped', '&lt;script&gt;' === d2w_html_escape( '<script>' ) ) ;
t( 'quotes escaped', '&quot;x&quot;' === d2w_html_escape( '"x"' ) ) ;
t( 'json of site name is parseable', '{"siteName":"abc"}' === d2w_json( array( 'siteName' => 'abc' ) ) ) ;
t( 'json survives invalid utf8', 1 === preg_match( '/^\{.*\}$/', d2w_json( array( 'siteName' => "\xB3\x27\x28" ) ) ) ) ;


// ------------------------------------------------------------------ logging

section( 'Log writer (drvtw_private/drvtw_log.php)' ) ;
$logDir = $GLOBALS[ 'd2wTestLogDir' ] ;

$bFatal = FALSE ;
set_error_handler( function( $no, $str ) use ( &$bFatal ) {
    if( FALSE !== stripos( $str, 'fputcsv' ) || FALSE !== stripos( $str, 'TypeError' )
        || FALSE !== stripos( $str, 'Deprecat' ) )
        $bFatal = TRUE ;
    return TRUE ;
} ) ;
$ok = d2w_putlog( LOG_INFO, 'hello from the test suite' ) ;
restore_error_handler() ;

t( 'd2w_putlog succeeds in a fresh temp dir', TRUE === $ok ) ;
t( 'logger created its folder on first use', is_dir( $logDir ) ) ;
t( 'created directory is searchable (0640 was the bug)',
    is_dir( $logDir ) && ( fileperms( $logDir ) & 0111 ) !== 0 ) ;
$line = (string)@file_get_contents( $logDir . '/user.log' ) ;
t( 'log line written with level and ident',
    FALSE !== strpos( $line, 'hello from the test suite' ) && FALSE !== strpos( $line, 'INFO' ) ) ;
t( 'no fputcsv/TypeError/deprecation diagnostics', FALSE === $bFatal ) ;

// an unwritable location must degrade to error_log(), not fatal
d2w_closelog() ;
$GLOBALS[ 'd2w_logFolder' ] = '/proc/definitely-not-writable' ;
$bFatal2 = FALSE ;
set_error_handler( function( $no, $str ) use ( &$bFatal2 ) {
    if( FALSE !== stripos( $str, 'TypeError' ) ) $bFatal2 = TRUE ;
    return TRUE ;
} ) ;
$ok2 = d2w_putlog( LOG_INFO, 'this must not be fatal' ) ;
restore_error_handler() ;
t( 'unwritable log target returns FALSE instead of fatal',
    FALSE === $ok2 && FALSE === $bFatal2 ) ;
$GLOBALS[ 'd2w_logFolder' ] = $logDir ;
d2w_closelog() ;
@exec( 'rm -rf ' . escapeshellarg( $logDir ) ) ;

section( 'Templated inline pages' ) ;

$GLOBALS[ 'd2wBaseDomain' ] = 'example.test' ;
$GLOBALS[ 'd2wWebHost' ] = 'www.example.test' ;
$GLOBALS[ 'd2wApiHost' ] = 'api.example.test' ;
$GLOBALS[ 'd2wSiteSuffix' ] = '.on.example.test' ;
$GLOBALS[ 'd2wContactEmail' ] = 'help@example.test' ;
$csrfFixture = str_repeat( 'a', 40 ) ;      // the value admin.html is given per request

$page = d2w_inline_page( '404.html', array( '{{CSRF}}' => str_repeat( 'a', 40 ) ) ) ;
t( 'inline page loads', is_string( $page ) && strlen( $page ) > 40 ) ;
t( 'hosts substituted', FALSE !== strpos( $page, 'www.example.test' ) ) ;
t( 'contact email substituted', FALSE !== strpos( $page, 'help@example.test' ) ) ;
t( 'no un-substituted placeholders', FALSE === strpos( $page, '{{' ) ) ;
t( 'no leftover drv.tw in rendered page', FALSE === stripos( $page, 'drv.tw' ) ) ;

$adminRendered = (string)d2w_inline_page( 'admin.html', array( '{{CSRF}}' => $csrfFixture ) ) ;
t( 'admin page carries the session-derived CSRF token',
    FALSE !== strpos( $adminRendered, '<meta name="d2w-csrf" content="' . $csrfFixture . '"' )
        && FALSE === strpos( $adminRendered, 'name="d2w-csrf" content=""' ) ) ;

t( 'missing page returns FALSE instead of warning',
    FALSE === d2w_inline_page( 'definitely-not-here.html' ) ) ;
t( 'path traversal out of inline/ is refused',
    FALSE === d2w_inline_page( '../drvtw_private/drvtw_creds.example.php' ) ) ;

// every page index.php renders must be placeholder-clean
$bad = array() ;
foreach( glob( $ROOT . '/backend/html/inline/*' ) as $f ) {
    $page = d2w_inline_page( basename( $f ), array( '{{CSRF}}' => $csrfFixture ) ) ;
    if( !is_string( $page ) ) { $bad[] = basename( $f ) . '(unreadable)' ; continue ; }
    if( FALSE !== strpos( $page, '{{' ) ) $bad[] = basename( $f ) . '(placeholder left)' ;
    // the shipped pages may only mention the old domain as an example inside a
    // comment; anything in a URL/attribute position is a bug
    if( preg_match( '#(?:href|src|action)="[^"]*drv\.tw#i', $page ) )
        $bad[] = basename( $f ) . '(hardcoded drv.tw in a URL)' ;
}
t( 'all ' . count( glob( $ROOT . '/backend/html/inline/*' ) ) . ' inline pages render clean',
    0 === count( $bad ), implode( ', ', $bad ) ) ;


// ------------------------------------------------------------------ CSP + inline
//
// The default policy allows inline scripts only with a per-request nonce. Every
// page this software renders itself has an inline <script>, so if the plumbing
// broke, the visitor access-code form and the operator backroom would both be
// dead - and a suite that only asserts "the header was sent" would not notice.

$tNonce = d2w_csp_nonce() ;
t( 'a nonce is 32 hex chars and stable within a request',
    1 === preg_match( '/^[0-9a-f]{32}$/', $tNonce ) && $tNonce === d2w_csp_nonce() ) ;

$csp = d2w_app_csp( $tNonce ) ;
$scriptDir = '' ;
foreach( explode( ';', $csp ) as $d )
    if( FALSE !== strpos( $d, 'script-src' ) )
        $scriptDir = trim( $d ) ;
t( 'script-src allows https: CDNs plus this request nonce, and nothing unsafe',
    FALSE !== strpos( $scriptDir, "'nonce-" . $tNonce . "'" )
        && FALSE !== strpos( $scriptDir, "'self'" ) && FALSE !== strpos( $scriptDir, 'https:' ),
    $scriptDir ) ;
t( 'script-src carries no unsafe-inline / unsafe-hashes',
    FALSE === strpos( $scriptDir, "'unsafe-inline'" ) && FALSE === strpos( $scriptDir, "'unsafe-hashes'" ),
    $scriptDir ) ;
t( 'an operator override may embed {{NONCE}} where it wants the inline scripts',
    "script-src 'nonce-" . $tNonce . "'" === d2w_app_csp( $tNonce, "script-src 'nonce-{{NONCE}}'" ),
    d2w_app_csp( $tNonce, "script-src 'nonce-{{NONCE}}'" ) ) ;
t( 'an override without the placeholder is passed through untouched',
    'default-src none' === d2w_app_csp( $tNonce, 'default-src none' ) ) ;

$adminNonce = NULL ;
if( preg_match( '#<script[^>]*nonce="([0-9a-f]{32})"#', $adminRendered, $m ) )
    $adminNonce = $m[ 1 ] ;
t( 'the rendered admin page carries this request nonce on its inline script',
    $adminNonce === d2w_csp_nonce(), var_export( $adminNonce, TRUE ) ) ;

$noHandler = array() ;
foreach( glob( $ROOT . '/backend/html/inline/*.html' ) as $f ) {
    // md_wrap.html is deliberately outside the policy (it renders inside a frame
    // of the user's own content) and sizes itself with one onload= attribute.
    if( 'md_wrap.html' === basename( $f ) )
        continue ;
    $html = (string)file_get_contents( $f ) ;
    if( preg_match( '/<[a-z0-9]+\s[^>]*\son[a-z]+\s*=/i', $html ) )
        $noHandler[] = basename( $f ) . '(inline handler)' ;
    // keep each tag's attributes, drop its body: a comment inside the JS that
    // mentions <script> must not be mistaken for markup
    $tags = preg_replace( '/(<script\b[^>]*>)[\s\S]*?<\/script>/', '$1</script>', $html ) ;
    preg_match_all( '/<script\b[^>]*>/', $tags, $mm ) ;
    foreach( $mm[ 0 ] as $tag )
        if( FALSE === stripos( $tag, 'src=' ) && FALSE === stripos( $tag, 'nonce=' ) )
            $noHandler[] = basename( $f ) . '(unnonced <script>)' ;
}
t( 'no policy-covered page uses an inline event handler or an unnonced script',
    0 === count( $noHandler ), implode( ', ', $noHandler ) ) ;

section( 'CSRF for cookie-authenticated commands' ) ;

$GLOBALS[ 'sessionSecret' ] = 'a' ;
$session = d2w_make_session( '584574ea7674fca77585b481', 'gd', 3600 ) ;
$csrf = d2w_csrf_token( $session ) ;

t( 'token derived from a valid session', 40 === strlen( $csrf ) && ctype_xdigit( $csrf ) ) ;
t( 'token verifies against the same session', TRUE === d2w_csrf_ok( $csrf, $session ) ) ;
t( 'token from another session is refused',
    FALSE === d2w_csrf_ok( d2w_csrf_token( d2w_make_session( 'otheruser00000000000000', 'od', 3600 ) ), $session ) ) ;
t( 'forged/expired cookie yields no token', '' === d2w_csrf_token( 'garbage' ) ) ;
t( 'no cookie -> no token -> CSRF check fails closed',
    '' === d2w_csrf_token( NULL ) && FALSE === d2w_csrf_ok( '', NULL ) ) ;
t( 'token is not the raw session value', FALSE === strpos( $session, $csrf ) ) ;
t( 'same session always yields the same token (stateless by design)',
    $csrf === d2w_csrf_token( $session ) ) ;

$m = NULL ;
t( 'privileged_request_allowed reports the method (cookie)',
    TRUE === d2w_privileged_request_allowed( '584574ea7674fca77585b481', 'gd', $session, NULL, NULL, $m )
        && 'cookie' === $m ) ;
$m = NULL ;
t( 'privileged_request_allowed reports the method (bearer)',
    TRUE === d2w_privileged_request_allowed( 'u1', 'od', NULL, 'Bearer tok123', 'tok123', $m )
        && 'bearer' === $m ) ;
$m = NULL ;
t( 'unauthorised request reports no method',
    FALSE === d2w_privileged_request_allowed( 'u1', 'od', NULL, 'Bearer nope', 'tok123', $m )
        && NULL === $m ) ;

section( 'Log privacy scrubbing' ) ;

$scrubbed = d2w_log_scrub( 'RESP 302 forward CDN1 https://cdn-of.example.test/_guf/abc123/'
    . '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs?ra=' . urlencode( strrev( 'ya29.a0AbCdEfGhIjKlMnOpQrSt' ) ) ) ;
t( 'ra= token removed', FALSE === strpos( $scrubbed, 'a0AbCdEfGhIjKlMnOpQrSt' )
    && FALSE === strpos( $scrubbed, 'trSq' ) ) ;
t( 'proxied file id and site name survive (needed for debugging)',
    FALSE !== strpos( $scrubbed, '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs' )
        && FALSE !== strpos( $scrubbed, 'abc123' ) ) ;

$scrubbed = d2w_log_scrub( 'AUTHENTICATE quota.limit=16106127360 for u=584574ea7674fca77585b481 site=abcd1234' ) ;
t( 'mongo user id untouched', FALSE !== strpos( $scrubbed, '584574ea7674fca77585b481' ) ) ;
t( 'site name untouched', FALSE !== strpos( $scrubbed, 'site=abcd1234' ) ) ;

$scrubbed = d2w_log_scrub( 'MAILER sent code 424878 to bob.jones@Example.COM' ) ;
t( 'recipient local part hidden', FALSE === strpos( $scrubbed, 'bob.jones' )
    && FALSE !== strpos( $scrubbed, '@Example.COM' ) ) ;
t( 'the one-time code itself is not invented/lost by the scrub',
    FALSE !== strpos( $scrubbed, 'code 424878' ) ) ;

$scrubbed = d2w_log_scrub( 'auth Header: Bearer ya29.a0AfB_byC0defGHIjklMNOpqrSTUvwxYZ123456789 x' ) ;
t( 'bearer value hidden', FALSE === strpos( $scrubbed, 'a0AfB_byC' ) ) ;

$scrubbed = d2w_log_scrub( 'cookie D2WSESS=1700000000.584574ea7674fca77585b481.gd.abcdef0123456789' ) ;
t( 'session cookie hidden', FALSE === strpos( $scrubbed, '584574ea7674fca77585b481.gd' ) ) ;

// through the real logger: nothing sensitive must reach user.log
$GLOBALS[ 'd2w_logFolder' ] = $GLOBALS[ 'd2wTestLogDir' ] ;
@exec( 'rm -rf ' . escapeshellarg( $GLOBALS[ 'd2wTestLogDir' ] ) ) ;
d2w_closelog() ;
d2w_putlog( LOG_WARNING, 'MAILER sent code 123456 to victim@phished.example' ) ;
d2w_closelog() ;
$raw = (string)@file_get_contents( $GLOBALS[ 'd2wTestLogDir' ] . '/user.log' ) ;
t( 'log file on disk carries no e-mail local part', FALSE === strpos( $raw, 'victim@' ) ) ;
t( 'log file still says something useful', FALSE !== strpos( $raw, 'MAILER sent code' ) ) ;

section( 'Auth-code attempt policy' ) ;

$p = d2w_attempt_policy( 1000, 0, 0, 8, 900 ) ;
t( 'first attempt allowed', TRUE === $p[ 'allowed' ] && 0 === $p[ 'retryAfter' ] ) ;
$p = d2w_attempt_policy( 1000, 7, 900, 8, 900 ) ;
t( 'under the cap still allowed', TRUE === $p[ 'allowed' ] ) ;
$p = d2w_attempt_policy( 1000, 8, 900, 8, 900 ) ;
t( 'at the cap: refused with a Retry-After',
    FALSE === $p[ 'allowed' ] && $p[ 'retryAfter' ] > 0 && $p[ 'retryAfter' ] <= 900 ) ;
$p = d2w_attempt_policy( 1000000, 40, 1000000 - 5000, 8, 900 ) ;
t( 'window expired resets the count', TRUE === $p[ 'allowed' ] ) ;
$p = d2w_attempt_policy( 1000, 999, 1000 + 1000000000, 8, 900 ) ;
t( 'Retry-After is capped at one window even with a future timestamp',
    $p[ 'retryAfter' ] <= 900 && $p[ 'retryAfter' ] >= 1 ) ;
$p = d2w_attempt_policy( time(), 999, time(), 0, 0 ) ;
t( 'garbage limits normalise instead of disabling the guard',
    is_array( $p ) && $p[ 'allowed' ] === FALSE ) ;

section( 'App-page headers, over a real HTTP server' ) ;

// d2w_page_headers() calls header(), which only does something in a web SAPI -
// so start the built-in server, render one inline page through it and read the
// response. This is the only place the header set is asserted as behaviour
// rather than as source text.
$hdrDir = sys_get_temp_dir() . '/d2w-hdrs-' . getmypid() ;
@mkdir( $hdrDir ) ;
file_put_contents( $hdrDir . '/router.php', "<?php\n"
    . 'require ' . var_export( $ROOT . '/backend/html/drvtw_private/drvtw_util.php', TRUE ) . " ;\n"
    . "\$GLOBALS['d2wAppCSP'] = '' ;\n"
    . 'header( "Content-Type: text/html" ) ;' . " d2w_page_headers( \$_GET['p'] ) ;\n"
    . "http_response_code( 200 ) ; echo 'ok ;\n' ;" ) ;

$srvLog = $hdrDir . '/server.log' ;
$port = 0 ;
$d2wSrv = NULL ;

// A port derived from the pid collided with a previous run whose server was still
// going away; the requests then reached that stale process and reported 500s from
// code this checkout no longer has. So: pick a random port in a wide range, keep
// the real child handle, and retry while the bind is refused.
for( $try = 0; $try < 8 && 0 === $port; $try++ ) {
    $cand = 20000 + random_int( 0, 20000 ) ;
    if( file_exists( $srvLog ) )
        unlink( $srvLog ) ;   // never @unlink(): a suppressed warning is still a diagnostic here
    $cmd = escapeshellarg( PHP_BINARY ) . ' -S 127.0.0.1:' . $cand . ' -t '
        . escapeshellarg( $hdrDir ) . ' ' . escapeshellarg( $hdrDir . '/router.php' )
        . ' > ' . escapeshellarg( $srvLog ) . ' 2>&1' ;
    $pipes = array() ;
    $kid = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'file', $srvLog, 'a' ) ),
        $pipes, $hdrDir ) ;
    if( !is_resource( $kid ) )
        continue ;

    // Wait for the server's own startup line rather than probing the socket: a
    // connection attempt that fails would itself be a diagnostic, and this suite
    // asserts there are none.
    for( $i = 0; $i < 80; $i++ ) {
        if( FALSE !== strpos( (string)@file_get_contents( $srvLog ), 'started' ) ) {
            $port = $cand ;
            $d2wSrv = $kid ;
            break ;
        }
        if( !( (array)proc_get_status( $kid ) )[ 'running' ] )
            break ;           // bind refused, try another port
        usleep( 50000 ) ;
    }

    if( 0 === $port && is_resource( $kid ) ) {
        @proc_terminate( $kid ) ;
        @proc_close( $kid ) ;
    }
    foreach( $pipes as $pipe )
        if( is_resource( $pipe ) )
            @fclose( $pipe ) ;
}

/** fetch a URL from the throwaway server, returning its response headers */
function d2w_fetch_headers( $url, &$body = NULL ) {
    $ctx = stream_context_create( array( 'http' => array( 'ignore_errors' => TRUE, 'timeout' => 2 ) ) ) ;
    $body = @file_get_contents( $url, FALSE, $ctx ) ;
    return ( FALSE !== $body && isset( $http_response_header ) )
        ? implode( "\n", $http_response_header ) : NULL ;
}

$hdrs = d2w_fetch_headers( 'http://127.0.0.1:' . $port . '/?p=404.html' ) ;

t( 'the built-in server answered (PHP CLI server usable here)', is_string( $hdrs ), 'no response' ) ;
if( is_string( $hdrs ) ) {
    foreach( array( 'X-Content-Type-Options: nosniff', 'X-Frame-Options: SAMEORIGIN',
        'Referrer-Policy: same-origin', 'X-Robots-Tag: noindex, nofollow',
        'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()',
        'Content-Security-Policy:' ) as $h )
        t( "app page sends $h", FALSE !== stripos( $hdrs, $h ) ) ;

    // md_wrap.html and *.js are hosted-content renders: nosniff yes, our frame/
    // robots/CSP policy no (it would apply to the *user's* document).
    $jsHdrs = d2w_fetch_headers( 'http://127.0.0.1:' . $port . '/?p=script.js' ) ;
    t( 'a .js render keeps nosniff but drops the page policy',
        FALSE !== stripos( (string)$jsHdrs, 'X-Content-Type-Options: nosniff' )
            && FALSE === stripos( (string)$jsHdrs, 'X-Frame-Options' )
            && FALSE === stripos( (string)$jsHdrs, 'X-Robots-Tag' ),
        str_replace( "\n", ' ~ ', (string)$jsHdrs ) ) ;

    $cspLine = preg_grep( '/^Content-Security-Policy:/i', explode( "\n", $hdrs ) ) ;
    $csp = trim( substr( (string)reset( $cspLine ), strlen( 'Content-Security-Policy:' ) ) ) ;
    t( 'default CSP pins form-action and base-uri to self',
        FALSE !== strpos( $csp, "form-action 'self'" ) && FALSE !== strpos( $csp, "base-uri 'self'" ),
        $csp ) ;

    // The header and the page must agree, or every inline script on the page is
    // blocked. Check the nonce as it went over the wire, and that it is not fixed.
    $sentNonce = NULL ;
    if( 1 === preg_match( "/'nonce-([0-9a-f]{32})'/", $csp, $mm ) )
        $sentNonce = $mm[ 1 ] ;
    t( 'the delivered CSP carries a 32-hex nonce', is_string( $sentNonce ), $csp ) ;

    $hdrs2 = d2w_fetch_headers( 'http://127.0.0.1:' . $port . '/?p=404.html' ) ;
    $csp2 = '' ;
    foreach( explode( "\n", (string)$hdrs2 ) as $line )
        if( 0 === stripos( $line, 'Content-Security-Policy:' ) )
            $csp2 = trim( substr( $line, strlen( 'Content-Security-Policy:' ) ) ) ;
    $nonce2 = NULL ;
    if( 1 === preg_match( "/'nonce-([0-9a-f]{32})'/", $csp2, $mm2 ) )
        $nonce2 = $mm2[ 1 ] ;
    t( 'a second request gets a different nonce', is_string( $nonce2 ) && $nonce2 !== $sentNonce,
        var_export( $nonce2, TRUE ) . ' vs ' . var_export( $sentNonce, TRUE ) ) ;
}
if( is_resource( $d2wSrv ) ) {
    proc_terminate( $d2wSrv ) ;
    proc_close( $d2wSrv ) ;
}
if( file_exists( $srvLog ) )
    unlink( $srvLog ) ;
@unlink( $hdrDir . '/router.php' ) ; @rmdir( $hdrDir ) ;

section( 'No unguarded superglobal reads on the response path' ) ;
// php-fpm always defines QUERY_STRING; the built-in server (and a rewrite that
// drops the query part) does not. One "Undefined array key" banner printed into
// a JSON body breaks the caller's parse, so the reads must be guarded - and one
// of them was not until the app was actually booted against a database.
$idxSrc = (array)explode( "\n", (string)file_get_contents( $ROOT . '/backend/html/index.php' ) ) ;
$unguarded = 0 ;
foreach ( $idxSrc as $idxLine ) {
    if ( FALSE !== strpos( $idxLine, "\$_SERVER[ 'QUERY_STRING' ]" )
        && FALSE === strpos( $idxLine, 'isset(' ) ) $unguarded++ ;
}
t( 'every QUERY_STRING read checks isset() first', 0 === $unguarded,
    $unguarded ? $unguarded . ' unguarded read(s) in index.php' : 'no bare reads' ) ;

section( 'The installer script writes keys that exist' ) ;

// install/install.sh generates drvtw_creds.php from a template. A key it invents
// is not an error in PHP - it just does nothing - so this is the only thing that
// will tell us when a config key is renamed on one side.
$installer = (string)@file_get_contents( $ROOT . '/install/install.sh' ) ;
if( FALSE !== $installer && '' !== $installer ) {
    preg_match_all( '/^\$([A-Za-z0-9_]+)\s*=/m', $installer, $im ) ;
    $written = array_unique( $im[ 1 ] ) ;
    $real = '' ;
    foreach( array( 'backend/html/index.php', 'backend/html/drvtw_private/drvtw_util.php',
        'backend/html/drvtw_private/drvtw_creds.example.php' ) as $f )
        $real .= (string)file_get_contents( $ROOT . '/' . $f ) ;
    $ghosts = array() ;
    foreach( $written as $k )
        if( FALSE === strpos( $real, '$' . $k ) )
            $ghosts[] = '$' . $k ;
    t( 'the ' . count( $written ) . ' keys the installer sets are all real config keys',
        0 === count( $ghosts ), implode( ', ', $ghosts ) ) ;
    // and the generated file has to survive the deployment audit's placeholder scan
    t( 'the installer leaves no placeholder behind',
        FALSE === strpos( $installer, 'REPLACE_WITH_RANDOM_HEX' )
            && FALSE === strpos( $installer, 'YOUR_MICROSOFT_CLIENT_ID' ) ) ;
} else {
    t( 'install/install.sh present', FALSE, 'the installer is part of the repo now' ) ;
}

section( 'Deployment audit (tools/d2w-audit.php logic)' ) ;

$bad = d2w_audit_problems( array(
    'd2wBaseDomain' => 'your.domain', 'd2wApiHost' => 'api.your.domain',
    'd2wWebHost' => 'www.your.domain', 'sessionSecret' => 'REPLACE_WITH_RANDOM_HEX',
    'odClientId' => 'YOUR_MICROSOFT_CLIENT_ID', 'mongoLocalConnection' => 'nope',
), array( 'phpVersion' => '7.4.33', 'extensions' => array( 'mongodb' ), 'loaded' => array(),
    'credsPerms' => 0644, 'logDir' => array( 'path' => '/var/log/d2w', 'exists' => TRUE,
        'writable' => TRUE, 'searchable' => FALSE ),
    'leftoverHosts' => array( '404.html' ) ) ) ;
$keys = array_column( $bad, 'key' ) ;
foreach( array( 'd2wBaseDomain', 'sessionSecret', 'odClientId', 'mongoLocalConnection',
    'php', 'ext-mongodb', 'drvtw_creds.php', 'log folder', 'hardcoded host' ) as $k )
    t( "audit reports $k", in_array( $k, $keys, TRUE ) ) ;
t( 'audit calls a non-searchable log folder an error',
    in_array( 'error', array_column( $bad, 'level' ), TRUE ) && 0 === count(
        array_filter( $bad, fn( $p ) => 'warn' === $p[ 'level' ] && 'log folder' === $p[ 'key' ] ) ) ) ;

$good = d2w_audit_problems( array(
    'd2wBaseDomain' => 'example.org', 'd2wApiHost' => 'api.example.org',
    'd2wWebHost' => 'www.example.org', 'sessionSecret' => '9f3c1a77be5d40c8a1d6f2e0b7c48a91',
    'opsSecret' => 'e2b7c48a91f3c1a77be5d40c8a1d6f2e', 'odClientId' => 'x',
    'odClientSecret' => 'y', 'gdClientId' => 'z.apps.googleusercontent.com',
    'gdClientSecret' => 'w', 'mongoLocalConnection' => 'mongodb://u:p@%2Ftmp%2Fmongodb-27017.sock/db',
    'mongoRemoteConnections' => array( 'mongodb+srv://u:p@c.example.mongodb.net/db' ),
    'mailFrom' => 'no-reply@example.org', 'requireApprovedSites' => TRUE,
) ) ;
$errs = array_filter( $good, fn( $p ) => 'error' === $p[ 'level' ] ) ;
t( 'a complete deployment reports no errors', 0 === count( $errs ), json_encode( $errs ) ) ;

// the shipped blacklist must lint clean of hard errors
$blProblems = array() ;
if( function_exists( 'd2w_audit_blacklist' ) ) {
    // defined in the tool, not the lib: include it in a child process instead
    $out = array() ;
    $rc = 1 ;
    if( function_exists( 'exec' ) ) {
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $ROOT . '/tools/d2w-audit.php' )
            . ' --quiet 2>&1', $out, $rc ) ;
        $txt = implode( "\n", $out ) ;
        t( 'audit tool runs on this checkout (exit 0 or 2 = config placeholders only)',
            0 === $rc || 2 === $rc, 'rc=' . $rc ) ;
        t( 'blacklist has no structural errors',
            FALSE === strpos( $txt, 'ERROR  blacklist' ), $txt ) ;
        t( 'audit reports the blacklist size',
            FALSE !== strpos( $txt, 'needles' ) ) ;
    }
}

// ------------------------------------------------------------------ wiring

section( 'Security gates are wired into index.php' ) ;
// Tokenise first and drop comments/whitespace noise, so a mention inside a
// comment can never make a gate test pass by accident.
$index = d2w_code_only( $ROOT . '/backend/html/index.php' ) ;

/**
 * Returns the PHP source of $file with comments and whitespace runs removed,
 * using the real tokenizer (not a regex).
 */
function d2w_code_only( $file ) {
    $src = file_get_contents( $file ) ;
    $out = '' ;
    foreach( token_get_all( $src ) as $tok ) {
        if( is_array( $tok ) ) {
            if( T_COMMENT === $tok[ 0 ] || T_DOC_COMMENT === $tok[ 0 ] )
                continue ;
            if( T_WHITESPACE === $tok[ 0 ] ) { $out .= ' ' ; continue ; }
            $out .= $tok[ 1 ] ;
        } else {
            $out .= $tok ;
        }
    }
    return $out ;
}

function src_between( $src, $from, $to ) {
    $a = strpos( $src, $from ) ;
    if( FALSE === $a ) return NULL ;
    $b = strpos( $src, $to, $a ) ;
    return FALSE === $b ? substr( $src, $a ) : substr( $src, $a, $b - $a ) ;
}

$navGate = src_between( $index, 'function gdNavigate(', 'gdMakeCache( $userId, $token, $idParent )' ) ;
t( 'gdNavigate authorises privileged commands up front',
    NULL !== $navGate && ( FALSE !== strpos( $navGate, 'owner_auth_method( $userId, $service )' )
        || FALSE !== strpos( $navGate, 'owner_is_authorized( $userId, $service )' ) ) ) ;
$tDel = strpos( $index, "case '@@delete':" ) ;
$tGate = strpos( $index, 'NULL === $authMethod' ) ;
if( FALSE === $tGate )
    $tGate = strpos( $index, '! owner_is_authorized( $userId, $service )' ) ;
if( FALSE === $tGate )
    $tGate = strpos( $index, '!owner_is_authorized( $userId, $service )' ) ;
t( 'the gate runs before the @@delete case', FALSE !== $tDel && FALSE !== $tGate && $tGate < $tDel ) ;
$tCsrf = strpos( $index, 'ERR_CSRF_REQUIRED' ) ;
t( 'cookie-authenticated mutators need a CSRF token, checked before @@delete',
    FALSE !== $tCsrf && FALSE !== strpos( $index, 'd2w_csrf_ok( $presented,' ) && $tCsrf < $tDel ) ;
$tId = strpos( $index, 'd2w_valid_file_id( $path[ $depth + 1 ] )' ) ;
t( 'file id from the URL is validated before use', FALSE !== $tId ) ;

$logRoute = src_between( $index, 'else if( \'@@tok\' == $cmd[ 1 ]', '} // $d2wApiHost' ) ;
t( 'operator routes (@@tok/@@log.csv/@@backroom) are gated',
    NULL !== $logRoute && FALSE !== strpos( $logRoute, 'ops_is_authorized(' ) ) ;
$getLog = src_between( $index, 'function getLog(', 'function addAbuseReport' ) ;
t( 'getLog() also re-checks authorization itself',
    NULL !== $getLog && FALSE !== strpos( $getLog, 'ops_is_authorized(' ) ) ;
t( 'the ops token is keyed (hash_hmac) and not sha1(hour)',
    FALSE !== strpos( $index, 'd2w_ops_token(' )
        && FALSE === strpos( $index, "sha1( floor( time() / 60 / 60 )" ) ) ;
$auth = src_between( $index, 'function sendAuthCode(', 'function checkAuthCode(' ) ;
t( 'sendAuthCode no longer calls dns_get_mx() with a missing argument',
    NULL !== $auth && FALSE === strpos( $auth, 'dns_get_mx(' ) ) ;
t( 'sendAuthCode validates the recipient', NULL !== $auth && FALSE !== strpos( $auth, 'd2w_check_recipient_email(' ) ) ;
t( 'sendAuthCode uses a CSPRNG for the code', NULL !== $auth && FALSE !== strpos( $auth, 'random_int(' ) ) ;
$code = src_between( $index, 'function checkAuthCode(', 'function reportAbuse(' ) ;
t( 'checkAuthCode sanitizes its redirect target',
    NULL !== $code && FALSE !== strpos( $code, 'd2w_safe_site_redirect(' ) ) ;
t( 'abuse report no longer uses the `isset() ?? $x = ...` no-op',
    FALSE === strpos( $index, 'isset($params[\'attack_type\'])' ) ) ;


section( 'Self-serve site commands are wired' ) ;

// the three commands must be privileged (owner session) AND exempt from the
// "needs a drive bearer token" mutator rule, or the panel 403s for a cookie-only
// owner. Assert both halves from the tokenised source.
$priv = src_between( $index, '$bPrivileged = in_array(', ') ;' ) ;
foreach( array( '@@site', '@@rename', '@@domain' ) as $cmd )
    t( $cmd . ' is a privileged command', NULL !== $priv && FALSE !== strpos( $priv, $cmd ) ) ;

$mut = src_between( $index, '$bMutator = !in_array(', ') ;' ) ;
t( 'the @@site/@@cache/@@download reads are exempt from the bearer-token mutator rule',
    NULL !== $mut && FALSE !== strpos( $mut, "'@@cache'" ) && FALSE !== strpos( $mut, "'@@download'" )
        && FALSE !== strpos( $mut, "'@@site'" ), var_export( $mut, TRUE ) ) ;

t( 'the three commands are dispatched from the site switch',
    FALSE !== strpos( $index, "case '@@site':" ) && FALSE !== strpos( $index, "case '@@rename':" )
        && FALSE !== strpos( $index, "case '@@domain':" ) ) ;
$posSite = strpos( $index, "case '@@site':" ) ;
$posFileCmd = strpos( $index, "case '@@download':" ) ;
t( 'they are matched by the depth-0/1 switch, before the switch that needs a file id',
    FALSE !== $posSite && FALSE !== $posFileCmd && $posSite < $posFileCmd ) ;
$tCsrfGate = strpos( $index, 'ERR_CSRF_REQUIRED' ) ;
t( 'the rename and domain cases come after the CSRF gate, so they cannot bypass it',
    FALSE !== $tCsrfGate && FALSE !== $posSite && $tCsrfGate < $posSite ) ;

$renameCase = src_between( $index, "case '@@rename':", "case '@@domain':" ) ;
t( '@@rename is called with the service, not the drive token (regression)',
    NULL !== $renameCase && FALSE !== strpos( $renameCase, 'site_rename( $userId, $service, $siteName,' )
        && FALSE === strpos( $renameCase, 'site_rename( $userId, $token' ) ) ;
$domainCase = src_between( $index, "case '@@domain':", "}" ) ;
t( '@@domain reads its argument from POST or GET',
    NULL !== $domainCase && FALSE !== strpos( $domainCase, "\$_POST[ 'domain' ]" )
        && FALSE !== strpos( $domainCase, "site_bind_domain( \$siteName," ) ) ;

$ren = src_between( $index, 'function site_rename(', 'function move_site_record(' ) ;
t( 'rename refuses to move a blacklisted site',
    NULL !== $ren && FALSE !== strpos( $ren, 'site_name_copyright_blocked(' )
        && FALSE !== strpos( $ren, 'ERR_LOCKED' ) ) ;
t( 'rename is rate limited by the configurable gap',
    NULL !== $ren && FALSE !== strpos( $ren, '$siteNameChangeGapSec' ) && FALSE !== strpos( $ren, '429' ) ) ;
t( 'rename routes the name through the same claim rules the check uses',
    NULL !== $ren && FALSE !== strpos( $ren, 'site_name_status( $rawNew )' ) ) ;
t( 'a taken name answers 409, a malformed one 400',
    NULL !== $ren && FALSE !== strpos( $ren, 'getSiteInfo( $newName ) ) ? 409 : 400' ) ) ;

$mv = src_between( $index, 'function move_site_record(', 'function site_bind_domain(' ) ;
$aUp = FALSE === $mv ? FALSE : strpos( $mv, "upsert' => TRUE" ) ;
$aDel = FALSE === $mv ? FALSE : strpos( $mv, '$bulk->delete(' ) ;
t( 'the record is upserted at the new name before the old one is deleted',
    FALSE !== $aUp && FALSE !== $aDel && $aUp < $aDel,
    'upsert=' . var_export( $aUp, TRUE ) . ' delete=' . var_export( $aDel, TRUE ) ) ;
t( 'every store is written, so a rename cannot half-apply',
    NULL !== $mv && FALSE !== strpos( $mv, "'remoteStores'" ) && FALSE !== strpos( $mv, "'localStore'" ) ) ;

$bind = src_between( $index, 'function site_bind_domain(', 'function site_check_available(' ) ;
t( 'domain verification accepts either the TXT token or the CNAME',
    NULL !== $bind && FALSE !== strpos( $bind, 'DNS_TXT' ) && FALSE !== strpos( $bind, 'DNS_CNAME' ) ) ;
t( 'the TXT token is the site name, so it cannot be replayed for another site',
    NULL !== $bind && FALSE !== strpos( $bind, '\'DRVTW=\' . $siteName' ) ) ;
t( 'a domain that does not verify is not stored',
    NULL !== $bind && FALSE !== strpos( $bind, "'ERR_NOT_YET'" ) ) ;

$avail = src_between( $index, 'function site_check_available(', 'function send_site_json(' ) ;
t( 'the availability lookup answers without a session',
    NULL !== $avail && FALSE === strpos( $avail, 'd2w_csrf_ok' )
        && FALSE !== strpos( $avail, 'site_name_status( $rawName )' ) ) ;

$tRoute = strpos( $index, '\'siteName\' == $cmd[ 2 ]' ) ;
$tAvail = strpos( $index, 'site_check_available(' ) ;
t( 'the public lookup is routed at /do/siteName into site_check_available()',
    FALSE !== $tRoute && FALSE !== $tAvail && $tRoute < $tAvail
        && $tAvail - $tRoute < 400, 'route=' . var_export( $tRoute, TRUE ) . ' call=' . var_export( $tAvail, TRUE ) ) ;

section( 'Variable scope and doc accuracy (regressions)' ) ;

// gdNavigate() reads $service for the owner check but did not receive it, which
// made every cookie-authenticated @@ command answer 403.
t( 'gdNavigate takes $service as a parameter',
    1 === preg_match( '/function\s+gdNavigate\s*\((.*?)\)\s*\{/s', $index, $mm )
        && FALSE !== strpos( $mm[ 1 ], '$service' ) ) ;
preg_match_all( '/gdNavigate\s*\(((?:[^()]|\([^()]*\))*)\)/', $index, $calls ) ;
$calls = array_values( array_filter( $calls[ 1 ], fn( $c ) => FALSE === strpos( $c, '&$userId' ) ) ) ;
t( 'every gdNavigate() call site passes $service', 4 === count( $calls ), 'found ' . count( $calls ) ) ;
foreach( $calls as $i => $c )
    t( "call site $i forwards the service", FALSE !== strpos( $c, '$service' ),
        trim( preg_replace( '/\s+/', ' ', $c ) ) ) ;
t( 'the privileged gate refuses instead of guessing when the service is unknown',
    FALSE !== strpos( $index, '$authMethod = $service ? owner_auth_method(' ) ) ;
$code2 = src_between( $index, 'function checkAuthCode(', 'function getAuthFailures(' ) ;
if( NULL === $code2 )
    $code2 = src_between( $index, 'function checkAuthCode(', 'function reportAbuse(' ) ;
t( 'the guess window is configurable, not hardcoded',
    NULL !== $code2 && FALSE !== strpos( $code2, '$authCodeRetryWindowSec' ) ) ;

// reportAbuse() consulted a function-local $authHeader, so every Bearer caller got 401.
$abuse = src_between( $index, 'function reportAbuse(', 'function sendAuthCode(' ) ;
if( NULL === $abuse )
    $abuse = src_between( $index, 'function reportAbuse(', 'function getUserIdFromName(' ) ;
t( 'reportAbuse globalises $authHeader',
    NULL !== $abuse && FALSE !== strpos( $abuse, 'global $apiTokens, $authHeader' ),
    NULL === $abuse ? 'function body not found' : '' ) ;

// Docs that name a config key nobody implemented are worse than no docs.
$phpVars = array( 'this', 'GLOBALS' ) ;
foreach( array( '/backend/html/index.php', '/backend/html/drvtw_private/drvtw_util.php',
    '/backend/html/drvtw_private/drvtw_log.php',
    '/backend/html/drvtw_private/drvtw_creds.example.php' ) as $rel ) {
    foreach( token_get_all( (string)file_get_contents( $ROOT . $rel ) ) as $tok )
        if( is_array( $tok ) && T_VARIABLE === $tok[ 0 ] )
            $phpVars[ ltrim( $tok[ 1 ], '$' ) ] = 1 ;
}
// names that belong to nginx / shell / a wildcard, not to PHP
$allowDocs = array( 'D2W_LOG_FOLDER', 'CSRF', 'pool', 'USER', 'uri', 'needle', 'escape',
    'request_uri', 'uid_got', 'binary_remote_addr', 'args', 'root', 'host' ) ;
foreach( array( '/SECURITY.md', '/HOW-TO-USE.md', '/backend/README.md' ) as $rel ) {
    $txt = (string)file_get_contents( $ROOT . $rel ) ;
    preg_match_all( '/\$([a-zA-Z][a-zA-Z0-9_]*)/', $txt, $m ) ;
    $ghost = array() ;
    foreach( array_unique( $m[ 1 ] ) as $name ) {
        if( in_array( $name, $allowDocs, TRUE ) || isset( $phpVars[ $name ] ) )
            continue ;
        // "$mongoTls*" style wildcards are fine if some real key starts with them
        foreach( array_keys( $phpVars ) as $real )
            if( strlen( $name ) >= 6 && 0 === strpos( $real, $name ) ) continue 2 ;
        $ghost[] = '$' . $name ;
    }
    t( $rel . ' names only config keys that exist', 0 === count( $ghost ),
        implode( ', ', $ghost ) ) ;
}

// --root may be the deployment folder or the docroot, and must audit the
// operator's own nginx configs (not the repo's).
$js = array() ; $rcAudit = 1 ;
if( function_exists( 'exec' ) ) {
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $ROOT . '/tools/d2w-audit.php' )
        . ' --root ' . escapeshellarg( $ROOT . '/backend' ) . ' --json 2>/dev/null', $js, $rcAudit ) ;
    $audit = json_decode( implode( "\n", $js ), TRUE ) ;
    t( 'audit --root resolves the deployment folder to its docroot',
        is_array( $audit )
            && ( $ROOT . '/backend/html' ) === rtrim( (string)$audit[ 'root' ], '/' ),
        is_array( $audit ) ? (string)$audit[ 'root' ] : 'no json' ) ;
    $keys = is_array( $audit ) ? array_column( $audit[ 'problems' ], 'key' ) : array() ;
    $nginxSeen = (bool)array_filter( $keys, fn( $k ) => 0 === strpos( (string)$k, 'nginx_drvtw' ) ) ;
    $sockExists = file_exists( '/run/php/php8.1-fpm.sock' ) ;
    t( 'audit reports on the nginx config it found (or the socket is fine)',
        $nginxSeen || $sockExists, 'keys=' . implode( ',', array_slice( $keys, 0, 4 ) ) ) ;
}


restore_error_handler() ;

$diag = array_unique( $GLOBALS[ 'diagnostics' ] ) ;
sort( $diag ) ;
t( 'the suite produced no PHP diagnostics', 0 === count( $diag ),
    implode( ' | ', array_slice( $diag, 0, 6 ) ) ) ;

echo "\n----------------------------------------\n" ;
printf( "%d passed, %d failed\n", $pass, $fail ) ;
if( $fail ) {
    echo "failed: \n - " . implode( "\n - ", $failures ) . "\n" ;
    exit( 1 ) ;
}
exit( 0 ) ;
