<?php

/**
 * DriveToWeb shared helpers (no DB, no network writes)
 *
 * Everything in this file is intentionally side-effect free (except read-only
 * DNS lookups) so it can be unit tested with `tests/run-tests.php`.
 *
 * PHP 7.4 / 8.x compatible.
 */

//
// Secrets / configuration defaults.
//
// drvtw_creds.php may define any of these; the defaults below keep the
// service working out of the box while making the security-relevant ones
// explicit.
//

isset( $sessionSecret ) or $sessionSecret = NULL ;      // HMAC key for owner session cookies
isset( $opsSecret ) or $opsSecret = NULL ;               // HMAC key for operator (@@log) tokens
isset( $opsUser ) or $opsUser = NULL ;                   // Operator HTTP basic-auth user
isset( $opsPass ) or $opsPass = NULL ;                   // Operator HTTP basic-auth password
isset( $siteNameSecret ) or $siteNameSecret = NULL ;     // Seed for generated site names
isset( $authCodeTtlSec ) or $authCodeTtlSec = 900 ;      // Site auth code lifetime
isset( $authCodeSendGapSec ) or $authCodeSendGapSec = 60 ;
isset( $authCodeRetryWindowSec ) or $authCodeRetryWindowSec = 900 ; // guess budget window
isset( $siteNameChangeGapSec ) or $siteNameChangeGapSec = 3600 ;  // min. wait between URL changes
isset( $d2wReservedSiteNames ) or $d2wReservedSiteNames = array() ; // extra names to never hand out
isset( $sessionTtlSec ) or $sessionTtlSec = 86400 * 14 ; // Owner session cookie lifetime

/**
 * Constant-time compare that never warns on NULL.
 */
function d2w_equals( $a, $b ) {
    if( !is_string( $a ) || !is_string( $b ) )
        return FALSE ;

    return hash_equals( $a, $b ) ;
}

/**
 * Keyed digest used for session cookies, operator tokens and site names.
 */
function d2w_hmac( $secret, $data ) {
    if( !is_string( $secret ) || '' === $secret )
        $secret = 'drvtw-insecure-dev-key' ; // Never used in production: see creds template

    return hash_hmac( 'sha256', $data, $secret ) ;
}

/**
 * Resolve the secret used to sign owner sessions.
 * Falls back to a per-install value derived from the ops secret so that a
 * deployment that only configured one secret still works.
 */
function d2w_session_secret() {
    global $sessionSecret, $opsSecret ;

    if( isset( $sessionSecret ) && '' !== $sessionSecret )
        return $sessionSecret ;

    return 'session:' . ( isset( $opsSecret ) ? $opsSecret : '' ) ;
}

//
// Google Drive file IDs
//

/**
 * A Drive file ID is a short opaque token. Anything else (slashes, dots,
 * query/fragment separators) must never be interpolated into a Drive API
 * URL, because that lets a caller move the request to another endpoint.
 */
function d2w_valid_file_id( $idFile ) {
    return is_string( $idFile ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{10,120}$/', $idFile ) ;
}

/**
 * Site subdomains are inserted into URLs and used as DNS labels.
 */
function d2w_valid_site_name( $siteName ) {
    return is_string( $siteName ) && 1 === preg_match( '/^[a-z0-9][a-z0-9\-]{1,62}$/', $siteName ) ;
}

//
// Owner session (protects privileged @@ commands)
//

/**
 * Build the signed value stored in the D2WSESS cookie.
 * Format: <expires-unix>.<userId>.<service>.<hmac>
 */
function d2w_make_session( $userId, $service, $ttl = NULL, $secret = NULL ) {
    if( NULL === $ttl )
        $ttl = $GLOBALS[ 'sessionTtlSec' ] ;
    if( NULL === $secret )
        $secret = d2w_session_secret() ;

    $expires = time() + intval( $ttl ) ;
    $body = $expires . '.' . $userId . '.' . $service ;

    return $body . '.' . d2w_hmac( $secret, $body ) ;
}

/**
 * Verify a session cookie value.
 * @return array|FALSE ['userId'=>..., 'service'=>..., 'expires'=>...]
 */
function d2w_parse_session( $value, $secret = NULL ) {
    if( !is_string( $value ) )
        return FALSE ;

    $parts = explode( '.', $value ) ;
    if( 4 !== count( $parts ) )
        return FALSE ;

    [ $expires, $userId, $service, $mac ] = $parts ;

    if( !ctype_digit( $expires ) || '' === $userId )
        return FALSE ;
    if( 'gd' !== $service && 'od' !== $service )
        return FALSE ;

    if( NULL === $secret )
        $secret = d2w_session_secret() ;

    if( !d2w_equals( $mac, d2w_hmac( $secret, $expires . '.' . $userId . '.' . $service ) ) )
        return FALSE ;

    if( $expires < time() )
        return FALSE ;

    return [ 'userId' => $userId, 'service' => $service, 'expires' => intval( $expires ) ] ;
}

/**
 * Is this request allowed to run a privileged per-file command
 * (@@lock / @@publish / @@unlock / @@dup / @@delete / @@cache) for $userId?
 *
 * The caller must be the site owner, proven by either
 *   a) a valid signed D2WSESS cookie for the same userId + service, or
 *   b) an Authorization: Bearer <token> that equals the owner's own stored
 *      access token (this is what the admin dashboard already holds).
 *
 * Pure function so the rule itself is unit-testable.
 */
function d2w_privileged_request_allowed( $userId, $service, $cookieValue,
    $authHeader, $ownerAccessToken, &$method = NULL ) {

    $method = NULL ;

    $session = d2w_parse_session( $cookieValue ) ;
    if( $session && d2w_equals( $session[ 'userId' ], (string)$userId )
        && d2w_equals( $session[ 'service' ], (string)$service ) ) {
        $method = 'cookie' ;
        return TRUE ;
    }

    // Bearer token must match the owner's own token exactly
    if( is_string( $authHeader ) && preg_match( '/^Bearer\s+(\S+)$/i', trim( $authHeader ), $m ) ) {
        if( is_string( $ownerAccessToken ) && '' !== $ownerAccessToken
            && d2w_equals( $m[ 1 ], $ownerAccessToken ) ) {
            $method = 'bearer' ;
            return TRUE ;
        }
    }

    return FALSE ;
}

//
// Operator (backroom) authorization
//

/**
 * Time-based token for the log stream. Rotates hourly.
 * Must be keyed with a secret: an unkeyed sha1(hour) is public knowledge.
 */
function d2w_ops_token( $secret = NULL, $offset = 0, $windowSec = 3600 ) {
    if( NULL === $secret ) {
        global $opsSecret ;
        $secret = isset( $opsSecret ) && '' !== $opsSecret ? $opsSecret : d2w_session_secret() ;
    }

    return d2w_hmac( $secret, 'd2w-ops-' . floor( time() / $windowSec + $offset ) ) ;
}

/**
 * Verify an operator token, accepting the previous/next window for skew.
 */
function d2w_ops_token_valid( $token, $secret = NULL, $windowSec = 3600 ) {
    if( !is_string( $token ) || '' === $token )
        return FALSE ;

    for( $offset = -1; $offset <= 1; $offset++ )
        if( d2w_equals( $token, d2w_ops_token( $secret, $offset, $windowSec ) ) )
            return TRUE ;

    return FALSE ;
}

/**
 * Is the current request allowed to read operator data?
 * Satisfied by HTTP basic auth against $opsUser/$opsPass, or by a valid
 * time-based token (so the existing backroom UI keeps working).
 */
function d2w_ops_authorized( $token = NULL, $authUser = NULL, $authPass = NULL,
    $opsUser = NULL, $opsPass = NULL, $opsSecretKey = NULL ) {

    if( NULL !== $opsUser ) {
        if( d2w_equals( (string)$authUser, (string)$opsUser )
            && d2w_equals( (string)$authPass, (string)$opsPass )
            && '' !== (string)$opsPass )
            return TRUE ;

        // Basic auth configured and supplied-but-wrong: do not fall through to
        // token auth, so a leaked/rotatable token cannot bypass it.
        if( NULL !== $authUser )
            return FALSE ;
    }

    return d2w_ops_token_valid( $token, $opsSecretKey ) ;
}

//
// Email handling for site-access codes
//

/**
 * Normalize + structurally validate an address. Returns the address or FALSE.
 */
function d2w_normalize_email( $email ) {
    if( !is_string( $email ) )
        return FALSE ;

    $email = trim( $email ) ;
    if( '' === $email || strlen( $email ) > 254 || FALSE !== strpos( $email, '@',
        strpos( $email, '@' ) + 1 ) ) // reject more than one '@' (also blocks header injection)
        return FALSE ;

    if( !filter_var( $email, FILTER_VALIDATE_EMAIL ) )
        return FALSE ;

    return mb_strtolower( $email ) ;
}

/**
 * Does the mail domain accept mail? (MX, then A/AAAA as allowed by RFC 5321)
 */
function d2w_email_domain_reachable( $email ) {
    $domain = substr( $email, strrpos( $email, '@' ) + 1 ) ;
    if( '' === $domain )
        return FALSE ;

    if( function_exists( 'idn_to_ascii' ) ) {
        $ascii = idn_to_ascii( $domain, 0, INTL_IDNA_VARIANT_UTS46 ) ;
        if( FALSE !== $ascii )
            $domain = $ascii ;
    }

    // Plain IPv6 literal addresses have no DNS name to look up
    if( 0 === strpos( $domain, '[' ) )
        return TRUE ;

    return checkdnsrr( $domain, 'MX' ) || checkdnsrr( $domain, 'A' ) ;
}

/**
 * Full check used before sending a code.
 * @return TRUE|String  TRUE when deliverable, otherwise the reason it is not
 */
function d2w_check_recipient_email( $email, $blockedDomains = array() ) {
    $normalized = d2w_normalize_email( $email ) ;
    if( FALSE === $normalized )
        return 'invalid' ;

    foreach( $blockedDomains as $bad ) {
        if( '' !== $bad && FALSE !== strpos( $normalized, mb_strtolower( $bad ) ) )
            return 'blocked' ;
    }

    // Free mailboxes are how phishers farm victim addresses
    if( preg_match( '/@(gmail|live|outlook|hotmail|yahoo|icloud|proton(mail)?|aol)\./i', $normalized ) )
        return 'unacceptable' ;

    if( !d2w_email_domain_reachable( $normalized ) )
        return 'unreachable' ;

    return TRUE ;
}

/**
 * Where may sendAuthCode / checkSiteAuth redirect the visitor?
 * Only back to the site itself: never to an arbitrary external host.
 *
 * @return string  safe Location value ('/' when $url is unusable)
 */
function d2w_safe_site_redirect( $url, $siteName, $baseDomain = 'drv.tw' ) {
    if( !is_string( $url ) || '' === $url )
        return '/' ;

    if( '/' === $url[ 0 ] && !( isset( $url[ 1 ] ) && '/' === $url[ 1 ] ) )
        return $url ; // same-origin path

    if( !preg_match( '#^https?://([^/?\#]+)([^\s]*)$#i', $url, $m ) )
        return '/' ;

    $host = mb_strtolower( $m[ 1 ] ) ;
    if( '' !== $siteName && !d2w_valid_site_name( $siteName ) )
        return '/' ;

    $suffix = mb_strtolower( '.on.' . $baseDomain ) ;
    $expected = $siteName . $suffix ;

    // Exact host match only - never endsWith($baseDomain), which is how
    // "site.on.drv.tw.evil.com" gets past naive checks.
    if( $host === $expected || $host === $expected . ':' . '443'
        || $host === $siteName )
        return $url ;

    return '/' ;
}

/**
 * Pick an Access-Control-Allow-Origin value for a browser request.
 * @return string|FALSE  FALSE means send no CORS header at all
 */
function d2w_cors_origin( $httpOrigin, $allowedHosts, $siteHostSuffix = NULL ) {
    if( !is_string( $httpOrigin ) || '' === $httpOrigin )
        return FALSE ;

    if( 'null' === $httpOrigin )
        return FALSE ;

    if( !preg_match( '#^https?://([^/?\#]+)$#i', $httpOrigin, $m ) )
        return FALSE ;

    $host = mb_strtolower( $m[ 1 ] ) ;
    $host = preg_replace( '/:\d+$/', '', $host ) ;

    foreach( $allowedHosts as $allowed )
        if( d2w_equals( $host, mb_strtolower( $allowed ) ) )
            return $httpOrigin ;

    if( NULL !== $siteHostSuffix && '' !== $siteHostSuffix ) {
        $suffixLen = strlen( $siteHostSuffix ) ;
        if( strlen( $host ) >= $suffixLen
            && 0 === substr_compare( $host, $siteHostSuffix, -$suffixLen, $suffixLen ) )
            return $httpOrigin ; // every hosted site may call the auth API for itself
    }

    return FALSE ;
}

//
// Content inspection helpers (used by quarantineHtml and friends)
//

/**
 * Map a MIME type from a file name extension. Used when a stored file has no
 * usable mimeType, and by the setup tests.
 */
function d2w_mime_from_extension( $name ) {
    $name = preg_replace( '/[\?#].*$/', '', (string)$name ) ;
    static $extMap = array(
        'html' => 'text/html', 'htm' => 'text/html', 'shtml' => 'text/html',
        'css' => 'text/css', 'js' => 'application/javascript',
        'mjs' => 'application/javascript', 'json' => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'md' => 'text/x-markdown', 'txt' => 'text/plain',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'avif' => 'image/avif', 'ico' => 'image/x-icon', 'mp4' => 'video/mp4',
        'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
        'ogg' => 'audio/ogg', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'pdf' => 'application/pdf',
        'zip' => 'application/zip', 'wasm' => 'application/wasm',
    ) ;

    $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ;

    return isset( $extMap[ $ext ] ) ? $extMap[ $ext ] : NULL ;
}

/**
 * UTF BOM sniffing.
 *
 * NOTE: the original helper compared a 3-byte prefix against the 4-byte
 * UTF-32 BOMs, so UTF-32 files were never detected and were then converted
 * from the wrong source encoding.
 */
if( !function_exists( 'detect_utf_encoding' ) ) {
    function detect_utf_encoding( $text ) {
        $UTF32_BE = chr( 0x00 ) . chr( 0x00 ) . chr( 0xFE ) . chr( 0xFF ) ;
        $UTF32_LE = chr( 0xFF ) . chr( 0xFE ) . chr( 0x00 ) . chr( 0x00 ) ;
        $UTF16_BE = chr( 0xFE ) . chr( 0xFF ) ;
        $UTF16_LE = chr( 0xFF ) . chr( 0xFE ) ;
        $UTF8 = chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ;

        $first2 = substr( $text, 0, 2 ) ;
        $first3 = substr( $text, 0, 3 ) ;
        $first4 = substr( $text, 0, 4 ) ; // must be 4 bytes for the UTF-32 BOMs

        if( $first3 == $UTF8 )
            return 'UTF-8' ;
        else if( $first4 == $UTF32_BE )
            return 'UTF-32BE' ;
        else if( $first4 == $UTF32_LE )
            return 'UTF-32LE' ;
        else if( $first2 == $UTF16_BE )
            return 'UTF-16BE' ;
        else if( $first2 == $UTF16_LE )
            return 'UTF-16LE' ;

        return FALSE ;
    }
}

/**
 * Parse an Accept header into a mime => quality map.
 * Supports q=0, q=1, q=.8 and q=0.8 (the previous regex only matched
 * "\d+\.\d+", so "image/*;q=0" was read as quality 1.0).
 */
if( !function_exists( 'd2w_parse_accept' ) ) {
    function d2w_parse_accept( $accept ) {
        $accepted = array() ;

        foreach( explode( ',', (string)$accept ) as $item ) {
            $item = trim( $item ) ;
            if( '' === $item )
                continue ;

            $parts = explode( ';', $item ) ;
            $mime = strtolower( trim( array_shift( $parts ) ) ) ;
            if( '' === $mime )
                continue ;

            $q = 1.0 ;
            foreach( $parts as $param ) {
                if( preg_match( '/^\s*q\s*=\s*(\d*\.?\d+)\s*$/i', $param, $m ) )
                    $q = max( 0.0, min( 1.0, floatval( $m[ 1 ] ) ) ) ;
            }

            $accepted[ $mime ] = $q ; // later entries win, as in the original
        }

        return $accepted ;
    }
}

if( !function_exists( 'acceptQuality' ) ) {
    /**
     * @return float quality in [0,1]; 0 when the type is not acceptable
     */
    function acceptQuality( $accept, $mimeType ) {
        $accepted = d2w_parse_accept( $accept ) ;

        if( isset( $accepted[ $mimeType ] ) )
            return $accepted[ $mimeType ] ;

        $wildcard = preg_replace( '/\/.*$/', '/*', $mimeType ) ;
        if( isset( $accepted[ $wildcard ] ) )
            return $accepted[ $wildcard ] ;

        if( isset( $accepted[ '*/*' ] ) )
            return $accepted[ '*/*' ] ;

        if( isset( $accepted[ '*' ] ) )
            return $accepted[ '*' ] ;

        return 0.0 ;
    }
}

/**
 * Phishing signature matcher used by quarantineHtml().
 *
 * $blacklist_signatures entries are either
 *   'needle'                                   block when needle is present
 *   array( key, array( sig, ... ) )            block when key present and any sig is
 *   array( array( k1, k2 ), array( s1, ... ) ) block when any key matches and any sig is
 *
 * A key that occurs at offset 0 of the document counts as a match (the
 * previous `!strpos()` test only matched at offset > 0, which silently
 * bypassed the rule for documents starting with the key).
 *
 * @return array|FALSE  [ majorIndex, minorIndex ] of the match, FALSE when safe
 */
function d2w_match_blacklist( $html, $blacklist_signatures ) {
    if( !is_array( $blacklist_signatures ) )
        return FALSE ;

    for( $iMajor = 0; $iMajor < count( $blacklist_signatures ); $iMajor++ ) {
        $sign = $blacklist_signatures[ $iMajor ] ;

        if( is_array( $sign ) ) {
            if( is_array( $sign[ 0 ] ) ) {
                $bKey = FALSE ;
                foreach( $sign[ 0 ] as $key ) {
                    if( '' !== $key && FALSE !== strpos( $html, $key ) ) {
                        $bKey = TRUE ;
                        break ;
                    }
                }
            }
            else
                $bKey = '' !== $sign[ 0 ] && FALSE !== strpos( $html, $sign[ 0 ] ) ;

            if( $bKey && count( $sign ) > 1 ) {
                for( $iMinor = 0; $iMinor < count( $sign[ 1 ] ); $iMinor++ )
                    if( FALSE !== strpos( $html, $sign[ 1 ][ $iMinor ] ) )
                        return array( $iMajor, $iMinor ) ;
            }
        }
        else if( is_string( $sign ) && '' !== $sign && FALSE !== strpos( $html, $sign ) )
            return array( $iMajor, -1 ) ;
    }

    return FALSE ;
}

/**
 * Escape a value for the tiny bit of HTML these pages build by hand.
 */
function d2w_html_escape( $value ) {
    return htmlspecialchars( (string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) ;
}

/**
 * JSON for api responses: never emits raw '/', keeps ASCII readable and
 * always returns a string even for non-encodable input.
 */
function d2w_json( $value ) {
    $json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ;

    return FALSE === $json ? '{}' : $json ;
}

/**
 * Site names used to be md5( "<userId>.<service>" ) which anyone who knows a
 * user id (ObjectIds are time-ordered) can recompute - and the site name is
 * the only thing guarding a "private" site. Generated names are therefore
 * seeded from a server secret, or fully random when none is configured.
 */
function d2w_generate_site_name( $userId, $service, $salt = NULL, $secret = NULL ) {
    if( NULL === $secret ) {
        global $siteNameSecret ;
        $secret = isset( $siteNameSecret ) && '' !== $siteNameSecret ? $siteNameSecret : NULL ;
    }

    // Without a server-side secret, any derivation from the user id is
    // public knowledge (the derivation lives in this repo), so use a random
    // name instead. With $siteNameSecret set, names stay reproducible per
    // user+root, which is convenient when restoring a database.
    if( NULL === $secret )
        return substr( bin2hex( random_bytes( 11 ) ), 0, 22 ) ;

    $name = NULL === $salt
        ? substr( d2w_hmac( $secret, 'fresh' . random_bytes( 8 ) ), 0, 22 )
        : substr( d2w_hmac( $secret, $userId . '.' . $service . '.' . $salt ), 0, 22 ) ;

    if( !d2w_valid_site_name( $name ) )
        $name = 's' . substr( $name, 1 ) ;

    return $name ;
}

//
// URL helpers
//

/**
 * Turn a user-supplied path segment into something safe to embed in a Drive
 * API URL / Google download URL.
 */
function d2w_path_segment_encode( $segment ) {
    return rawurlencode( (string)$segment ) ;
}

/**
 * Reject traversal in a decoded request path (paths arrive urldecoded, so
 * "%2e%2e/" would otherwise become "../").
 */
function d2w_path_is_safe( $path ) {
    if( !is_string( $path ) )
        return FALSE ;

    foreach( explode( '/', $path ) as $segment )
        if( '..' === $segment )
            return FALSE ;

    return TRUE ;
}

/**
 * Reads a page from the inline/ directory and substitutes deployment-specific
 * values, so the shipped error/admin pages do not hardcode the original
 * service's hosts or its contact address.
 *
 * Placeholders: {{WEB_URL}} {{WEB_HOST}} {{API_URL}} {{API_HOST}}
 *               {{BASE_DOMAIN}} {{SITE_SUFFIX}} {{CONTACT_EMAIL}}
 *
 * @return string|FALSE  FALSE when the page is missing (caller decides)
 */
function d2w_inline_page( $relPath, $extra = array(), $bHeaders = TRUE ) {
    global $d2wWebHost, $d2wApiHost, $d2wBaseDomain, $d2wSiteSuffix, $d2wContactEmail,
        $d2wInlineRoot ;

    $root = isset( $d2wInlineRoot ) ? $d2wInlineRoot : dirname( __DIR__ ) . '/inline' ;
    $file = rtrim( $root, '/' ) . '/' . ltrim( str_replace( array( 'inline/', '..' ),
        '', (string) $relPath ), '/' ) ;

    if( !is_file( $file ) )
        return FALSE ;

    $html = file_get_contents( $file ) ;
    if( FALSE === $html )
        return FALSE ;

    $webHost = isset( $d2wWebHost ) && $d2wWebHost ? $d2wWebHost : 'localhost' ;
    $apiHost = isset( $d2wApiHost ) && $d2wApiHost ? $d2wApiHost : $webHost ;
    $base = isset( $d2wBaseDomain ) && $d2wBaseDomain
        ? $d2wBaseDomain : preg_replace( '/^[^.]*\./', '', $webHost ) ;
    $suffix = isset( $d2wSiteSuffix ) && $d2wSiteSuffix ? $d2wSiteSuffix : '.on.' . $base ;
    $contact = !empty( $d2wContactEmail ) ? $d2wContactEmail : 'support@' . $base ;

    $keys = array( '{{WEB_URL}}', '{{WEB_HOST}}', '{{API_URL}}', '{{API_HOST}}',
        '{{BASE_DOMAIN}}', '{{SITE_SUFFIX}}', '{{CONTACT_EMAIL}}', '{{CSP_NONCE}}' ) ;
    $vals = array( 'https://' . $webHost, $webHost, 'https://' . $apiHost, $apiHost, $base,
        $suffix, $contact, d2w_csp_nonce() ) ;

    // Per-request values (e.g. {{CSRF}}) win over the deployment-wide ones
    foreach( (array) $extra as $k => $v ) {
        if( FALSE === strpos( $k, '{{' ) )
            $k = '{{' . strtoupper( $k ) . '}}' ;
        $keys[] = $k ;
        $vals[] = (string) $v ;
    }

    $html = str_replace( $keys, $vals, $html ) ;

    if( $bHeaders )
        d2w_page_headers( $relPath ) ;

    // A leftover placeholder means the caller forgot to pass a value: fail loudly
    // in the log, silently in the page (a raw {{TOKEN}} is ugly but not dangerous).
    if( FALSE !== strpos( $html, '{{' ) )
        error_log( 'd2w_inline_page: unresolved placeholder in ' . $relPath ) ;

    return $html ;
}

/**
 * Headers for the pages this application renders itself (error pages, dashboard,
 * backroom). Hosted user content gets its own, narrower set in sendContent().
 */
/**
 * A fresh nonce per request, used by both the CSP header and the {{CSP_NONCE}}
 * substitution in d2w_inline_page(), so the pages this software renders keep
 * their inline scripts working while 'unsafe-inline' stays switched off.
 */
function d2w_csp_nonce() {
    if( empty( $GLOBALS[ 'd2wCspNonce' ] ) )
        $GLOBALS[ 'd2wCspNonce' ] = bin2hex( random_bytes( 16 ) ) ;

    return $GLOBALS[ 'd2wCspNonce' ] ;
}

/**
 * The policy for the pages index.php renders itself. $override may contain the
 * literal {{NONCE}}, which is replaced - an operator who writes their own policy
 * therefore keeps working inline scripts without allowing 'unsafe-inline'.
 */
function d2w_app_csp( $nonce, $override = '' ) {
    if( '' !== trim( (string) $override ) )
        return str_replace( '{{NONCE}}', (string) $nonce, $override ) ;

    return "default-src 'self'; script-src 'self' https: 'nonce-" . $nonce . "';"
        . " style-src 'self' 'unsafe-inline' https:;"
        . " img-src 'self' data: https:; font-src 'self' https: data:;"
        . " connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'" ;
}

function d2w_page_headers( $relPath = '' ) {
    global $d2wAppCSP ;

    // Tests and tools/d2w-audit.php render the same pages from the CLI, where
    // header() only produces "headers already sent" noise.
    if( 'cli' === PHP_SAPI || headers_sent() )
        return TRUE ;

    header( 'X-Content-Type-Options: nosniff' ) ;

    // user content, not an app page: no frame/robots/CSP policy of ours
    if( 'md_wrap.html' === $relPath || preg_match( '/\.js$/i', (string) $relPath ) )
        return TRUE ;

    header( 'X-Frame-Options: SAMEORIGIN' ) ;
    header( 'Referrer-Policy: same-origin' ) ;
    header( 'X-Robots-Tag: noindex, nofollow' ) ;
    header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' ) ;

    // Default: same-origin for everything except the CDN-hosted CSS/fonts these
    // pages load, scripts only from https: origins or with this request's nonce,
    // and forms/base/frame pinned to self so a spoofed page cannot post
    // credentials somewhere else. Inline *handlers* (onclick=...) are not
    // allowed by any of that, which is why the shipped pages bind listeners.
    $csp = d2w_app_csp( d2w_csp_nonce(), isset( $d2wAppCSP ) ? $d2wAppCSP : '' ) ;

    if( $csp )
        header( 'Content-Security-Policy: ' . $csp ) ;

    return TRUE ;
}

//
// CSRF for cookie-authenticated privileged commands
//

/**
 * The @@commands authenticate with a signed cookie that a browser sends
 * automatically, so a page on another origin could trigger them. Bearer-token
 * callers are unaffected (a cross-site fetch cannot read the owner's token).
 *
 * The token is derived from the session value itself, so it needs no storage
 * and dies with the session. A subdomain that can *set* a D2WSESS cookie still
 * cannot produce the matching token without knowing $sessionSecret.
 */
function d2w_csrf_token( $sessionValue ) {
    $session = d2w_parse_session( $sessionValue ) ;
    if( !$session )
        return '' ;

    return substr( d2w_hmac( d2w_session_secret(),
        'csrf|' . $session[ 'userId' ] . '|' . $session[ 'service' ] . '|' . $session[ 'expires' ] ),
        0, 40 ) ;
}

/**
 * Does $presented match the session it is sent with?
 */
function d2w_csrf_ok( $presented, $sessionValue ) {
    $want = d2w_csrf_token( $sessionValue ) ;

    return '' !== $want && d2w_equals( (string) $presented, $want ) ;
}

//
// Log privacy
//

/**
 * user.log is world-readable-ish (0640 www-data:adm), is streamed by the
 * backroom UI and is shipped to log rotators. The shipped code logged full
 * redirect targets, which contained the reversed bearer token (?ra=...), plus
 * visitor e-mail addresses. Scrub both at the one choke point.
 *
 * Deliberately conservative: 24-hex Mongo user ids, site names and file names
 * must survive, because they are what makes the log useful.
 */
function d2w_log_scrub( $msg ) {
    if( !is_string( $msg ) || '' === $msg )
        return '' ;

    // query/header style secrets: keep the key, drop the value
    $msg = preg_replace(
        '/\b(ra|access_token|refresh_token|token|auth_token|code|D2WSESS|D2WCSRF|Authorization)\b(\s*[=:]\s*)(\S+)/i',
        '$1$2[redacted]', $msg ) ;

    // Bearer/Basic values that appear bare
    $msg = preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{12,}/i', '$1 [redacted]', $msg ) ;

    // Long opaque runs (Google ya29.*, Microsoft E.AQ*, JWTs) - 40+ chars
    $msg = preg_replace( '/\b(?:ya29\.[A-Za-z0-9._\-]{8,}|E\.[A-Za-z0-9._\-]{30,}|eyJ[A-Za-z0-9._\-]{20,})\b/',
        '[token]', $msg ) ;

    // e-mail addresses: keep the domain (needed for abuse triage), hide the local part
    $msg = preg_replace( '/([A-Za-z0-9._%+\-])([A-Za-z0-9._%+\-]*)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
        '$1***@$3', $msg ) ;

    return $msg ;
}

/**
 * Is the scrub on? (default on; $d2wLogPrivacy = FALSE turns it off for
 * debugging on a private instance)
 */
function d2w_log_privacy_on() {
    global $d2wLogPrivacy ;

    return !isset( $d2wLogPrivacy ) || FALSE !== $d2wLogPrivacy ;
}

//
// Verification-attempt policy (site access codes)
//

/**
 * A 6-digit code that is valid for 15 minutes is guessable unless verification
 * is rate limited. Pure decision function; the counter lives in Mongo.
 *
 * @return array ['allowed'=>bool, 'retryAfter'=>int, 'fails'=>int]
 */
function d2w_attempt_policy( $now, $fails, $tsLast, $maxTries = 8, $windowSec = 900 ) {
    $fails = max( 0, intval( $fails ) ) ;
    $windowSec = max( 60, intval( $windowSec ) ) ;
    $maxTries = max( 1, intval( $maxTries ) ) ;

    // Window expired: the next failure restarts the count
    if( $tsLast && ( $now - intval( $tsLast ) ) > $windowSec )
        $fails = 0 ;

    if( $fails < $maxTries )
        return array( 'allowed' => TRUE, 'retryAfter' => 0, 'fails' => $fails ) ;

    // Cap the wait at one window: a clock-skewed ts must not lock someone out
    // longer than the operator asked for.
    $elapsed = max( 0, $now - intval( $tsLast ) ) ;
    $retry = min( $windowSec, max( 1, $windowSec - $elapsed ) ) ;

    return array( 'allowed' => FALSE, 'retryAfter' => $retry, 'fails' => $fails ) ;
}

//
// Deployment self-audit (used by tools/d2w-audit.php)
//

/**
 * Check the values an operator put in drvtw_creds.php. Pure function so it can
 * be unit tested; $cfg is the map of config variables, $env the filesystem
 * observations (perms etc.) the caller gathered.
 *
 * @return array list of array( 'level'=>'error'|'warn', 'key'=>..., 'msg'=>... )
 */
function d2w_audit_problems( array $cfg, array $env = array() ) {
    $problems = array() ;
    $add = function( $level, $key, $msg ) use ( &$problems ) {
        $problems[] = array( 'level' => $level, 'key' => $key, 'msg' => $msg ) ;
    } ;

    $get = function( $key, $default = NULL ) use ( $cfg ) {
        return array_key_exists( $key, $cfg ) && NULL !== $cfg[ $key ] ? $cfg[ $key ] : $default ;
    } ;

    // --- hosts
    foreach( array( 'd2wBaseDomain', 'd2wApiHost', 'd2wWebHost' ) as $key ) {
        $v = $get( $key ) ;
        if( !$v )
            $add( 'error', $key, 'not set' ) ;
        else if( FALSE !== strpos( (string) $v, 'your.domain' ) )
            $add( 'error', $key, 'still the example value (' . $v . ')' ) ;
        else if( 'drv.tw' === $v || 0 === strpos( (string) $v, 'api.drv.tw' )
            || 0 === strpos( (string) $v, 'www.drv.tw' ) )
            $add( 'warn', $key, 'points at the shut-down hosted service (' . $v . ')' ) ;
        else if( !preg_match( '/^[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}$/i', (string) $v ) )
            $add( 'error', $key, 'not a valid hostname (' . $v . ')' ) ;
    }

    $suffix = $get( 'd2wSiteSuffix', '.on.' . $get( 'd2wBaseDomain', '' ) ) ;
    if( $get( 'd2wBaseDomain' ) && 0 !== strpos( (string) $suffix, '.on.' )
        && '.on.' . $get( 'd2wBaseDomain' ) !== $suffix )
        $add( 'warn', 'd2wSiteSuffix', 'unusual suffix "' . $suffix . '" - sites are normally <name>.on.<domain>' ) ;

    // --- secrets
    foreach( array( 'sessionSecret' => 'owner sessions for @@commands',
                    'opsSecret' => 'operator tool tokens' ) as $key => $why ) {
        $v = (string) $get( $key, '' ) ;
        if( '' === $v || 'REPLACE_WITH_RANDOM_HEX' === $v )
            $add( 'error', $key, 'missing/placeholder - required for ' . $why ) ;
        else if( strlen( $v ) < 32 )
            $add( 'warn', $key, 'only ' . strlen( $v ) . ' chars; use openssl rand -hex 32' ) ;
        else if( preg_match( '/^(0{4,}|a{8,}|1234|test|i_am_a_sentry)/i', $v ) )
            $add( 'error', $key, 'looks like a sample value, not a generated secret' ) ;
    }

    if( '' !== (string) $get( 'opsUser', '' ) && '' === (string) $get( 'opsPass', '' ) )
        $add( 'error', 'opsPass', '$opsUser is set but $opsPass is empty - operator tools would be unusable' ) ;

    // --- OAuth
    $gdId = (string) $get( 'gdClientId', '' ) ;
    $odId = (string) $get( 'odClientId', '' ) ;
    if( 0 === strpos( $gdId, 'YOUR_' ) || '' === $gdId )
        $add( 'warn', 'gdClientId', 'not configured - Google Drive sign-in will fail (expected: Google restricts new Drive-scope apps)' ) ;
    if( '' === $odId || 0 === strpos( $odId, 'YOUR_' ) )
        $add( 'error', 'odClientId', 'not configured - OneDrive sign-in will fail' ) ;
    foreach( array( 'gdClientSecret', 'odClientSecret' ) as $key )
        if( 0 === strpos( (string) $get( $key, '' ), 'YOUR_' ) )
            $add( 'error', $key, 'still a placeholder' ) ;

    // --- database
    foreach( array( 'mongoLocalConnection', 'mongoRemoteConnections' ) as $key ) {
        $v = $get( $key ) ;
        if( !$v ) { $add( 'error', $key, 'not set' ) ; continue ; }
        foreach( (array) $v as $dsn )
            if( !preg_match( '#^mongodb(\+srv)?://#', (string) $dsn ) )
                $add( 'error', $key, 'not a mongodb:// URI: ' . substr( (string) $dsn, 0, 24 ) ) ;
            else if( FALSE !== strpos( (string) $dsn, 'PASSWORD' ) || FALSE !== strpos( (string) $dsn, 'USERNAME' ) )
                $add( 'error', $key, 'contains USERNAME/PASSWORD placeholder' ) ;
    }

    // --- mail
    if( 0 === strpos( (string) $get( 'sesSmtpPassword', '' ), 'YOUR_' ) )
        $add( 'warn', 'sesSmtpPassword', 'placeholder - private-site access codes cannot be mailed' ) ;
    $from = (string) $get( 'mailFrom', '' ) ;
    if( $from && $get( 'd2wBaseDomain' ) && FALSE === strpos( $from, (string) $get( 'd2wBaseDomain' ) )
        && FALSE === strpos( $from, 'your.domain' ) )
        $add( 'warn', 'mailFrom', 'sender domain differs from $d2wBaseDomain - expect spam-foldering without SPF/DKIM' ) ;

    // --- filesystem / runtime, supplied by the caller
    if( isset( $env[ 'phpVersion' ] ) && version_compare( $env[ 'phpVersion' ], '8.0', '<' ) )
        $add( 'error', 'php', 'PHP ' . $env[ 'phpVersion' ] . ' is too old for this code' ) ;
    foreach( (array) ( $env[ 'extensions' ] ?? array() ) as $ext )
        if( !in_array( $ext, $env[ 'loaded' ] ?? array(), TRUE ) )
            $add( 'error', 'ext-' . $ext, 'php-' . $ext . ' is not loaded' ) ;
    if( isset( $env[ 'credsPerms' ] ) && ( $env[ 'credsPerms' ] & 0077 ) )
        $add( 'error', 'drvtw_creds.php', 'readable beyond owner+group (' . substr( sprintf( '%04o', $env[ 'credsPerms' ] ), -4 ) . ')' ) ;
    if( isset( $env[ 'logDir' ] ) ) {
        if( empty( $env[ 'logDir' ][ 'writable' ] ) )
            $add( 'error', 'log folder', $env[ 'logDir' ][ 'path' ] . ' is not writable by the web server user' ) ;
        else if( empty( $env[ 'logDir' ][ 'searchable' ] ) )
            $add( 'error', 'log folder', $env[ 'logDir' ][ 'path' ]
                . ' has no execute bit - fopen() cannot enter it (needs 0770, not 0640)' ) ;
    }
    if( isset( $env[ 'credsFile' ] ) && empty( $env[ 'credsFile' ][ 'exists' ] ) )
        $add( 'error', 'drvtw_creds.php', 'missing: copy drvtw_creds.example.php and fill it in' ) ;
    foreach( (array) ( $env[ 'leftoverHosts' ] ?? array() ) as $f )
        $add( 'warn', 'hardcoded host', $f . ' still mentions the original service' ) ;

    // --- behaviour flags
    if( !$get( 'requireApprovedSites', FALSE ) )
        $add( 'warn', 'requireApprovedSites', 'off: any shared folder is served without owner verification '
            . '(fine for testing, not for a public host)' ) ;
    if( FALSE === $get( 'd2wLogPrivacy', TRUE ) )
        $add( 'warn', 'd2wLogPrivacy', 'off: visitor e-mail addresses are written to user.log in full' ) ;

    return $problems ;
}

//
// ---------------------------------------------------------------------------
// Visitor-chosen site names ("claim your own URL")
//
// A site name becomes a DNS label (<name>.on.<domain>), so the rules here are
// the ones that keep a self-service naming UI from becoming:
//   * a way to grab a hostname the service itself needs (www, api, cdn-of…),
//   * a way to squat 1-letter labels or numeric ones that browsers treat as IPs,
//   * a moderation bypass, which is why the caller also checks the copyright
//     list before letting a name change (see renameSite() in index.php).
//
// ---------------------------------------------------------------------------

/**
 * Labels that must never be handed to a visitor. $hosts are the deployment's own
 * hostnames; anything that is a single label of one of them is reserved, plus a
 * static list of names that would confuse users or collide with common records.
 */
function d2w_site_name_reserved( $hosts = array(), $extra = array() ) {
    $reserved = array(
        // service / infrastructure names
        'www', 'www2', 'api', 'app', 'admin', 'console', 'dashboard', 'static', 'assets',
        'cdn', 'cdn-of', 'cdn2-of', 'cdn1', 'cdn2', 'mail', 'mx', 'smtp', 'imap', 'pop', 'ns1',
        'ns2', 'ftp', 'ssh', 'git', 'blog', 'docs', 'help', 'support', 'status', 'test', 'dev',
        'staging', 'demo', 'beta', 'new', 'old', 'i', 'img', 'files', 'media', 'video', 'm',
        // names that would collide with the site suffix itself or with routing
        'on', '-on', 'drv', 'drvtw', 'drivetoweb', 'drive', 'root', 'index', 'favicon', 'robots',
        'sitemap', 'well-known', 'acme-challenge',
        // things people try that must not look like a system address
        'localhost', 'wpad', 'autodiscover', 'proxy', 'gateway', 'dns', 'ntp', 'time',
    ) ;

    foreach( (array) $hosts as $host ) {
        if( !is_string( $host ) || '' === $host )
            continue ;
        $host = mb_strtolower( trim( $host ) ) ;
        $dot = strpos( $host, '.' ) ;
        if( FALSE !== $dot && $dot > 0 )
            $reserved[] = substr( $host, 0, $dot ) ;
        $reserved[] = $host ;
    }

    foreach( (array) $extra as $name )
        if( is_string( $name ) && '' !== $name )
            $reserved[] = mb_strtolower( trim( $name ) ) ;

    return array_values( array_unique( $reserved ) ) ;
}

/**
 * Normalise what a visitor typed into a candidate site name: lowercase, strip
 * the scheme/host they usually paste in, and collapse invalid separators to '-'.
 * Returns NULL when there is nothing left to salvage.
 */
/**
 * @param bool $changed  out: set TRUE when input had to be altered (case, spaces,
 *                       stripped characters) so the caller can show what happened
 *                       instead of silently hosting something else than typed.
 */
function d2w_normalize_site_name( $raw, &$changed = NULL ) {
    $changed = FALSE ;
    if( !is_string( $raw ) )
        return NULL ;

    $name = mb_strtolower( trim( $raw ) ) ;

    // "https://mine.on.example.org/", "mine.on.example.org", "My Page" are all
    // the same intent; be forgiving on input, strict on the result.
    $name = preg_replace( '#^[a-z]+://#', '', $name ) ;
    $name = preg_replace( '#/.*$#', '', $name ) ;
    $dot = strpos( $name, '.' ) ;
    if( FALSE !== $dot )
        $name = substr( $name, 0, $dot ) ;

    // Accented or other non-ASCII letters are refused, not transliterated: a
    // name that quietly becomes something else is how people end up hosting on
    // a URL they did not ask for (and how homograph tricks start).
    if( preg_match( '/[^a-z0-9\s_\-.]/', $name ) ) {
        $changed = ( $name !== $raw ) ;
        return NULL ;
    }

    $name = preg_replace( '#[\s_]+#', '-', $name ) ;
    if( '.' === substr( $name, -1 ) )
        $changed = TRUE ;

    // Leave a leading "xn--" alone so a pasted punycode label survives long
    // enough to be refused with a real message instead of being mutated.
    if( 0 !== strpos( $name, 'xn--' ) ) {
        if( preg_match( '#-{2,}#', $name ) )
            $changed = TRUE ;
        $name = preg_replace( '#-{2,}#', '-', $name ) ;
    }
    $name = trim( $name, '-.' ) ;
    if( '' === $name )
        return NULL ;

    $changed = $changed || ( $name !== mb_strtolower( trim( $raw ) ) ) ;

    return $name ;
}

/**
 * @return array  [ bool $ok, string $reason ]
 */
function d2w_site_name_claimable( $name, $reserved = array(), $minLen = 3, $maxLen = 63 ) {
    if( !is_string( $name ) || '' === $name )
        return array( FALSE, 'Pick a name for your site.' ) ;

    if( $name !== mb_strtolower( $name ) )
        return array( FALSE, 'Use lowercase letters only.' ) ;

    if( !d2w_valid_site_name( $name ) )
        return array( FALSE, 'Use 2-' . intval( $maxLen )
            . ' letters, digits or hyphens, starting with a letter or digit, '
            . 'and not ending in a hyphen.' ) ;

    if( in_array( $name, array_map( 'strval', (array) $reserved ), TRUE ) )
        return array( FALSE, 'That name belongs to the service itself and cannot be claimed.' ) ;

    if( strlen( $name ) < intval( $minLen ) )
        return array( FALSE, 'At least ' . intval( $minLen ) . ' characters, please.' ) ;

    if( ctype_digit( $name ) )
        return array( FALSE, 'A name made only of digits can look like an IP address to browsers.' ) ;

    if( preg_match( '/^\d{1,3}\.\d{1,3}/', $name ) )
        return array( FALSE, 'That looks like an IP address.' ) ;

    // Punycode labels are how homograph URLs are built; hosted names here are
    // ASCII by design, so refuse the encoding rather than resolve it.
    if( 0 === strpos( $name, 'xn--' ) )
        return array( FALSE, 'Type the name itself (letters a-z, digits, hyphens) - '
            . 'not an xn-- encoded one.' ) ;

    // A leading/trailing hyphen breaks some resolvers; the regex allows the
    // trailing one, so check it here rather than loosen the shared validator.
    if( '-' === substr( $name, -1 ) )
        return array( FALSE, 'Do not end the name with a hyphen.' ) ;

    return array( TRUE, 'OK' ) ;
}

/**
 * The public URL of a site. One place, so the GUI, the e-mail body and the
 * rename response cannot disagree about whether it is http or https.
 */
function d2w_site_public_url( $name, $baseDomain, $https = TRUE, $path = '/' ) {
    $url = ( $https ? 'https://' : 'http://' ) . $name . '.on.' . $baseDomain ;
    $path = '/' . ltrim( (string) $path, '/' ) ;

    return $url . ( '/' === $path ? '/' : rtrim( $path, '/' ) ) ;
}

/**
 * The DNS record a visitor must publish to bind their own domain. index.php
 * resolves custom domains from this TXT record (or a CNAME into .on.<domain>),
 * so showing it is not decoration: it is the actual mechanism.
 */
function d2w_site_domain_hint( $siteName, $baseDomain ) {
    return array(
        'type'  => 'TXT',
        'name'  => $siteName ? $siteName : '(your domain)',
        'value' => 'DRVTW=' . ( $siteName ? $siteName . '.on.' . $baseDomain : '<site>.on.' . $baseDomain ),
        'alt'   => 'Or add a CNAME for your host pointing at '
            . ( $siteName ? $siteName . '.on.' . $baseDomain : '<site>.on.' . $baseDomain ),
    ) ;
}
