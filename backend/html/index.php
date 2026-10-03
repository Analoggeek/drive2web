<?php

ini_set( 'default_charset', NULL ) ; // Remove charset=UTF-8 added since PHP 5.6
$usStart = microtime( TRUE ) ;

//
// Main
//

// Absolute paths: relative requires depend on the current working directory,
// which only happens to be right under php-fpm.
$d2wCredsFile = __DIR__ . '/drvtw_private/drvtw_creds.php' ;
if( !is_file( $d2wCredsFile ) ) {
    // A fresh checkout has no credentials file; say so clearly instead of
    // throwing "Failed opening required" into the response body.
    http_response_code( 500 ) ;
    header( 'Content-Type: text/plain; charset=UTF-8' ) ;
    error_log( 'DriveToWeb: missing ' . $d2wCredsFile
        . ' - copy drvtw_creds.example.php to drvtw_creds.php and fill it in.' ) ;
    print "DriveToWeb is not configured yet: drvtw_private/drvtw_creds.php is missing.\n" ;
    exit ;
}
require $d2wCredsFile ;
require __DIR__ . '/drvtw_private/drvtw_blacklist.php' ;
require __DIR__ . '/drvtw_private/drvtw_log.php' ;
require __DIR__ . '/drvtw_private/drvtw_util.php' ;

//
// Deployment identity. Set these in drvtw_creds.php when self-hosting; the
// defaults reproduce the hosted drv.tw service.
//
isset( $d2wBaseDomain ) or $d2wBaseDomain = 'drv.tw' ;
isset( $d2wApiHost ) or $d2wApiHost = 'api.' . $d2wBaseDomain ;
isset( $d2wWebHost ) or $d2wWebHost = 'www.' . $d2wBaseDomain ;
isset( $d2wCdnHosts ) or $d2wCdnHosts = array( 'cdn-of.' . $d2wBaseDomain,
    'cdn2-of.' . $d2wBaseDomain ) ;
isset( $d2wSiteSuffix ) or $d2wSiteSuffix = '.on.' . $d2wBaseDomain ;
isset( $d2wWidgetScript ) or $d2wWidgetScript = 'https://' . $d2wWebHost . '/inc/wd.js' ;
isset( $d2wContactEmail ) or $d2wContactEmail = 'support@' . $d2wBaseDomain ;
isset( $d2wLogPrivacy ) or $d2wLogPrivacy = TRUE ;      // scrub e-mails/tokens from user.log
isset( $d2wAppCSP ) or $d2wAppCSP = '' ;                // '' => default policy in d2w_page_headers()
isset( $d2wContentCSP ) or $d2wContentCSP = '' ;        // optional CSP for hosted pages
isset( $authCodeMaxFails ) or $authCodeMaxFails = 8 ;   // code guesses per site per 15 min
isset( $mongoTlsLocal ) or $mongoTlsLocal = FALSE ;    // TLS for the *local* Mongo connection
isset( $mongoTlsCAFile ) or $mongoTlsCAFile = '' ;
isset( $mongoTlsAllowSelfSigned ) or $mongoTlsAllowSelfSigned = FALSE ;
isset( $corsAllowedOrigins ) or $corsAllowedOrigins = array( $d2wWebHost,
    $d2wApiHost, $d2wBaseDomain ) ;
isset( $smtpHost ) or $smtpHost = 'email-smtp.us-east-1.amazonaws.com' ;
isset( $smtpPort ) or $smtpPort = 587 ;
isset( $mailFrom ) or $mailFrom = 'no-reply@' . $d2wBaseDomain ;
isset( $mailFromName ) or $mailFromName = 'DriveToWeb No-Reply' ;
isset( $allowCreatePostDb ) or $allowCreatePostDb = FALSE ;
isset( $requireApprovedSites ) or $requireApprovedSites = FALSE ;

use PHPMailer\PHPMailer\PHPMailer ;
use PHPMailer\PHPMailer\SMTP ;
use PHPMailer\PHPMailer\Exception ;

require __DIR__ . '/PHPMailer/PHPMailer.php' ;
require __DIR__ . '/PHPMailer/SMTP.php' ;
require __DIR__ . '/PHPMailer/Exception.php' ;

//
// Begin parse URL
//
$https = ( !empty( $_SERVER[ 'HTTPS' ] ) && 'off' != strtolower( $_SERVER[ 'HTTPS' ] ) 
    || ( isset( $_SERVER[ 'HTTP_X_FORWARDED_PROTO' ] ) 
        && 'https' == $_SERVER[ 'HTTP_X_FORWARDED_PROTO' ] ) 
    || ( isset( $_SERVER[ 'HTTP_CLOUDFRONT_FORWARDED_PROTO' ] ) 
        && 'https' == $_SERVER[ 'HTTP_CLOUDFRONT_FORWARDED_PROTO' ] ) ) ;

$httpHost = isset( $_SERVER[ 'HTTP_HOST' ] ) 
    ? mb_strtolower( $_SERVER[ 'HTTP_HOST' ] ) : NULL ;
$httpOrigin = isset( $_SERVER[ 'HTTP_ORIGIN' ] ) 
    ? mb_strtolower( $_SERVER[ 'HTTP_ORIGIN' ] ) : NULL ;

$requestUri = isset( $_SERVER[ 'REQUEST_URI' ] ) ? $_SERVER[ 'REQUEST_URI' ] : '/' ;
$requestMethod = isset( $_SERVER[ 'REQUEST_METHOD' ] ) ? $_SERVER[ 'REQUEST_METHOD' ] : 'GET' ;
$authHeader = isset( $_SERVER[ 'HTTP_AUTHORIZATION' ] ) ? $_SERVER[ 'HTTP_AUTHORIZATION' ] : NULL ;
$referrer = ( isset( $_SERVER[ 'HTTP_REFERER' ] ) 
    ? $_SERVER[ 'HTTP_REFERER' ] : NULL ) ;
$httpNoCache = ( ( isset( $_SERVER[ 'HTTP_CACHE_CONTROL' ] ) 
        && FALSE !== strpos( $_SERVER[ 'HTTP_CACHE_CONTROL' ], 'no-cache' ) ) || 
    ( isset( $_SERVER[ 'HTTP_PRAGMA' ] ) 
        && FALSE !== strpos( $_SERVER[ 'HTTP_PRAGMA' ], 'no-cache' ) ) ) ;

$get_as = isset( $_GET[ 'as' ] ) ? $_GET[ 'as' ] : NULL ;

$logTopic = substr( $requestUri, -12 ) ;
d2w_initlog( substr( (string)$httpHost, 0, 14 - strlen( $logTopic ) ) . $logTopic
    . sprintf( '%02d', mt_rand( 0, 99 ) ), 0, LOG_USER ) ;

d2w_putlog( LOG_INFO, 'HTTP ' . $requestMethod . ' http' . ( $https ? 's' : '' )
    . '://' . $httpHost . $requestUri ) ;
d2w_putlog( LOG_INFO, 'USER ip:' 
    . ( isset( $_SERVER[ 'REMOTE_ADDR' ] ) ? $_SERVER[ 'REMOTE_ADDR' ] : '?.?.?.?' )
    . ( isset( $_COOKIE[ 'uid' ] ) ? ', uid:' . $_COOKIE[ 'uid' ] : '' )
    . ( $referrer ? ', referrer:' . $referrer : '' ) 
    . ( isset( $_SERVER[ 'HTTP_USER_AGENT' ] ) 
        ? ', ua:' . $_SERVER[ 'HTTP_USER_AGENT' ] : '' ) ) ;

// Remove my php file name if invoked explicitly
$phpPrefix = '/' . basename( __FILE__  ) . '/' ;
if( FALSE !== strpos( $requestUri, $phpPrefix ) )
    $requestUri = '/' . substr( $requestUri, strlen( $phpPrefix ) ) ;

$ok = FALSE ;

$cmd = explode( '/', strtok( $requestUri, '?' ) ) ; // $cmd should not incl query string
foreach( $cmd as &$cmdtoken )
    $cmdtoken = urldecode( $cmdtoken ) ;

// Allow only GET, HEAD, POST, and OPTION
if( !in_array( $requestMethod, array( 'GET', 'HEAD', 'POST', 'OPTIONS' ), TRUE ) ) {
    d2w_putlog( LOG_INFO, 'RESP 405' ) ;
    header( $_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed', TRUE, 405 ) ;
    $html = d2w_inline_page( '405.html' ) ;
    $cb = strlen( $html ) ;
    header( 'Content-Length: ' . $cb ) ;
    print $html ;
    $ok = TRUE ;
}

if( 'OPTIONS' == $requestMethod && $httpOrigin ) {
    d2w_putlog( LOG_INFO, 'RESP 204 OPTIONS' ) ;
    header( $_SERVER['SERVER_PROTOCOL'] . ' 204 No Content', TRUE, 204 ) ;
    header( 'Connection: Keep-alive' ) ;
    header( 'Access-Control-Allow-Origin: ' . $httpOrigin ) ;
    header( 'Access-Control-Allow-Methods: GET, HEAD, POST, OPTIONS' ) ;
    header( 'Access-Control-Max-Age: 604800' ) ;
    $ok = TRUE ;
}

/**
 * requestUri States
 *
 * 1. Host not set => www.drv.tw
 * 2. http(s)://drv.tw
 *   a. /~username/drive/path (redirect to api.)
 *   b. /site/siteId/path
 *   c. => www.drv.tw
 * 3. http(s)://www.drv.tw
 *   a. => www.drv.tw
 * 4. http(s)://api.drv.tw
 *   a. /.a/drive/?state=/~username/drive/path&code=
 *     i. Store code as refreshToken, then proceed to URL in state
 *   b. /.b/drive/#state=/~username/drive/path?a=admin&access_token=...
 *     i. Send admin.html: leave token in hash, then proceed to URL in state
 *   c. /~username/drive/path (https only)
 *     i. ~username already authenticated: 
 *       1. If ?a=admin, send admin.html: if visitor is owner of URL, present navigation and service options
 *       2. Redirect to site URL
 *     ii. Not yet authenticated: log-in user and send to .a on success
 *   d. [TODO] POST /~username/drive/path?domain=name (https only)
 *     i. Set {name}.on.drv.tw/ as alias for URL, removing previous assignments
 *   e. @@tok
 *   f. /@@log
 *   g. /do/
 *     i. sendSiteAuth?site&email
 *   h. /abuseRerpot
 * 5. .../index.html
 * 6. http(s)://siteId.on.drv.tw/path
 * 7. cdn-of.drv.tw
 *   a. /_guf/siteName/fileId
 * 8. xxx.yyy. ... .drv.tw
 * 9. Custom domain name
 */

else if( !$httpHost ) {
    header( 'Location: https://' . $d2wWebHost . '/', TRUE ) ;
    d2w_putlog( LOG_INFO, 'RESP 302 home (' . $d2wWebHost . ')' ) ;
    $ok = TRUE ;
}

else if( $d2wBaseDomain == $httpHost ) {
    if( strlen( $cmd[ 1 ] ) > 0 && '~' == $cmd[ 1 ][ 0 ] && count( $cmd ) > 1 ) {
        header( 'Location: https://' . $d2wApiHost . $requestUri, TRUE, 301 ) ;
        d2w_putlog( LOG_INFO, 'RESP 301 to ' . $d2wApiHost ) ;
        $ok = TRUE ;
    }
    else if( 'site' == $cmd[ 1 ] && count( $cmd ) > 2 ) {
        $siteName = $cmd[ 2 ] ;
        $siteUrl = 'http' . ( $https ? 's' : '' ) . '://' . $siteName . $d2wSiteSuffix ;
    
        for( $i = 3; $i < count( $cmd ); $i++ )
            $siteUrl .= '/' . $cmd[ $i ] ;
    
        $q = isset( $_SERVER[ 'QUERY_STRING' ] ) ? (string)$_SERVER[ 'QUERY_STRING' ] : '' ;
        if( $q )
            $siteUrl .= '?' . $q ;
    
        header( "Location: $siteUrl", TRUE, 301 ) ;
        d2w_putlog( LOG_INFO, 'RESP 301 site to -on subdomain' ) ;
        $ok = TRUE ;
    }
    
    if( !$ok ) {
        header( 'Location: https://' . $d2wWebHost . $requestUri, TRUE, 301 ) ;
        d2w_putlog( LOG_INFO, 'RESP 301 ' . $d2wBaseDomain . ' ?' ) ;
        $ok = TRUE ;
    }
}

else if( $d2wApiHost == $httpHost ) {
    if( !$https ) {
        // Must use https
        header( 'Location: https://' . $httpHost . $requestUri, TRUE ) ;
        $ok = TRUE ;
    }
    else {
        if( '.a' == $cmd[ 1 ] && count( $cmd ) > 1 ) {
            // Just finished sign-in
            if( count( $cmd ) > 2 ) {
                $service = $cmd[ 2 ] ;
        
                $q = isset( $_SERVER[ 'QUERY_STRING' ] ) ? (string)$_SERVER[ 'QUERY_STRING' ] : '' ;
                parse_str( $q, $args ) ;
        
                //d d2w_putlog( LOG_INFO, 'NEWU parsed as ' . var_export( $args, TRUE ) ) ;
                $nextUrl = NULL ;
                if( 'gd' == $service && isset( $args['code'] ) 
                    && isset( $args['state'] ) ) {
                    d2w_putlog( LOG_INFO, 'NEWU gd user' ) ;
                    $nextUrl = gdSetupFromAuthCode( $args['code'], $args['state'] ) ;
                }
                else if( 'od' == $service && isset( $args['code'] ) 
                    && isset( $args['state'] ) ) {
                    d2w_putlog( LOG_INFO, 'NEWU od user' ) ;
                    $nextUrl = odSetupFromAuthCode( $args['code'], $args['state'] ) ;
                }
        
                if( $nextUrl ) {
                    header( 'Location: ' . $nextUrl ) ;
                    d2w_putlog( LOG_INFO, 'RESP 302 oauth to state' ) ;
                }
                else {
                    // Failed authorization. Bring user home
                    header( 'Location: https://' . $d2wWebHost . '/#errorAuth' ) ;
                    d2w_putlog( LOG_INFO, 'ERR oauth failed' ) ;
                }
        
                $ok = TRUE ;
            }
        }
        else if( '.b' == $cmd[ 1 ] && count( $cmd ) > 1 ) {
            // Just finished sign-in via a=admin
            if( count( $cmd ) > 2 ) {
                $service = $cmd[ 2 ] ;
                if( 'gd' == $service || 'od' == $service ) {
                    $adminPage = d2w_inline_page( 'admin.html', array( '{{CSRF}}'
                        => d2w_csrf_token( isset( $_COOKIE[ 'D2WSESS' ] ) ? $_COOKIE[ 'D2WSESS' ] : NULL ) ) ) ;
                    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
                    header( 'Content-Type: text/html' ) ;
                    header( 'Content-Length: ' . strlen( $adminPage ) ) ;
                    print( $adminPage ) ;
                    d2w_putlog( LOG_INFO, 'RESP 200 .b show admin page' ) ;
                    $ok = TRUE ;
                }
            }
        }
        else if( strlen( $cmd[ 1 ] ) > 0 && '~' == $cmd[ 1 ][ 0 ] && count( $cmd ) > 2 ) {
            // Accessing with user name
            $username = substr( $cmd[ 1 ], 1 ) ;
            $service = $cmd[ 2 ] ;

            $userId = getUserIdFromName( $username ) ;
            $token = getAccessToken( $userId, $service ) ;

            $siteName = NULL ;
            if( $token ) {
                $siteName = getSiteName( $userId, $service ) ;
                if( !$siteName ) {
                    setSiteInfo( NULL, $userId, $service, '/' ) ;
                    $siteName = getSiteName( $userId, $service ) ;
                }    
            }

            if( $siteName ) {
                // See if user is requesting a=admin action
                $q = isset( $_SERVER[ 'QUERY_STRING' ] ) ? (string)$_SERVER[ 'QUERY_STRING' ] : '' ;
                parse_str( $q, $args ) ;

                if( isset( $args[ 'a' ] ) ) {
                    if( 'admin' === $args[ 'a' ] ) {
                        // {{CSRF}} lets the dashboard issue the @@commands; it is
                        // empty unless this request already carries a valid session
                        $adminPage = d2w_inline_page( 'admin.html', array( '{{CSRF}}'
                            => d2w_csrf_token( isset( $_COOKIE[ 'D2WSESS' ] ) ? $_COOKIE[ 'D2WSESS' ] : NULL ) ) ) ;
                        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
                        header( 'Content-Type: text/html' ) ;
                        header( 'Content-Length: ' . strlen( $adminPage ) ) ;
                        print( $adminPage ) ;
                        d2w_putlog( LOG_INFO, 'RESP 200 ?a=admin show admin page' ) ;
                        $ok = TRUE ;    
                    }
                    else if( 'siteName' === $args[ 'a' ] ) {
                        $resp = d2w_json( array( 'siteName' => $siteName ) ) ;
                        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
                        header( 'Content-Type: application/json' ) ;
                        header( 'Content-Length: ' . strlen( $resp ) ) ;
                        print( $resp ) ;
                        d2w_putlog( LOG_INFO, 'RESP 200 ?a=siteName' ) ;
                        $ok = TRUE ;
                    }
                }
                else {
                    $siteUrl = 'http' . ( $https ? 's' : '' ) 
                        . '://' . $siteName . $d2wSiteSuffix ;
                    for( $i = 3; $i < count( $cmd ); $i++ )
                        $siteUrl .= '/' . $cmd[ $i ] ;
                    
                    if( $q )
                        $siteUrl .= '?' . $q ;

                    header( "Location: $siteUrl", TRUE, 301 ) ;
                    d2w_putlog( LOG_INFO, 'RESP 301 resolved' ) ;
                    $ok = TRUE ;
                }
            }
            else {
                if( 'gd' === $service )
                    $url = gdGetOauthUrl( $username, $requestUri ) ;
                else if( 'od' === $service )
                    $url = odGetOauthUrl( $username, $requestUri ) ;
                else
                    $url = 'https://' . $d2wWebHost ;

                header( "Location: $url", TRUE ) ;
                d2w_putlog( LOG_INFO, 'RESP 302 authenticate' ) ;
                $ok = TRUE ;
            }
        }
        else if( 'do' == $cmd[ 1 ] ) {
            $args = array() ;
            parse_str( isset( $_SERVER[ 'QUERY_STRING' ] ) ? (string)$_SERVER[ 'QUERY_STRING' ] : '' , $args ) ;

            if( 'sendSiteAuth' == $cmd[ 2 ] ) {
                if( isset( $args[ 'site' ] ) && isset( $args[ 'email' ] ) )
                    $ok = sendAuthCode( $args[ 'site' ], $args[ 'email' ] ) ;
                else {
                    header( 'Content-Type: application/json' ) ;
                    header( 'Cache-Control: no-store' ) ;
                    print d2w_json( array( 'error' => 'ERR_INCOMPLETE_REQUEST' ) ) ;
                    $ok = TRUE ;
                }
            }
            else if( 'siteName' == $cmd[ 2 ] ) {
                // The naming field in the dashboard polls this while you type
                $ok = site_check_available( isset( $args[ 'name' ] ) ? $args[ 'name' ] : NULL ) ;
            }
            else if( 'checkSiteAuth' == $cmd[ 2 ] ) {
                if( isset( $args[ 'site' ] ) && isset( $args[ 'code' ] ) )
                    $ok = checkAuthCode( $args[ 'site' ], $args[ 'code' ],
                        isset( $args[ 'url' ] ) ? $args[ 'url' ] : '/' ) ;
                else {
                    header( 'Content-Type: application/json' ) ;
                    header( 'Cache-Control: no-store' ) ;
                    print d2w_json( array( 'error' => 'ERR_INCOMPLETE_REQUEST' ) ) ;
                    $ok = TRUE ;
                }
            }
        }
        else if( 'abuseReport' == $cmd[ 1 ] ) {
            if( 2 == count( $cmd ) && 'POST' === $requestMethod ) {
                $params = json_decode( file_get_contents('php://input'), TRUE ) ;
                d2w_putlog( LOG_INFO, 'API POST abuseReport' ) ;
                $ok = reportAbuse($params) ;
            }
        }
        else if( '@@tok' == $cmd[ 1 ] || '@@log.csv' == $cmd[ 1 ] || '@@backroom' == $cmd[ 1 ] ) {
            // Operator tooling: requires HTTP basic auth and/or the hourly
            // keyed token. See d2w_ops_authorized().
            // The third segment only exists on /@@log.csv/<tok> - `2 == count( $cmd )`
            // is off by one and read $cmd[ 2 ] on the plain /@@backroom hit, which
            // printed "Undefined array key 2" ahead of the 401 body (and ahead of the
            // JSON of @@tok, breaking the dashboard's token fetch on any box where
            // display_errors is on).
            $presented = count( $cmd ) > 2 ? $cmd[ 2 ] : NULL ;
            if( '@@tok' == $cmd[ 1 ] )
                $presented = isset( $_GET[ 'tok' ] ) ? $_GET[ 'tok' ] : $presented ;

            if( !ops_is_authorized( $presented ) ) {
                d2w_putlog( LOG_WARNING, 'SECURE:ops unauth ' . $cmd[ 1 ] ) ;
                header( 'WWW-Authenticate: Basic realm="DriveToWeb operator"' ) ;
                header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 401 Unauthorized', TRUE, 401 ) ;
                header( 'Cache-Control: no-store' ) ;
                print 'Authentication required' ;
                $ok = TRUE ;
            }
            else if( '@@tok' == $cmd[ 1 ] ) {
                header( 'Content-Type: application/json' ) ;
                header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
                header( 'Pragma: no-cache' ) ;
                print d2w_json( array( 'token' => time_hash() ) ) ;
                d2w_putlog( LOG_INFO, 'HASH sent' ) ;
                $ok = TRUE ;
            }
            else if( '@@log.csv' == $cmd[ 1 ] ) {
                $ok = getLog() ;
            }
            else {
                d2w_putlog( LOG_INFO, 'RESP 200' ) ;
                $htmlBackroom = d2w_inline_page( 'backroom.html' ) ;
                header( 'Content-Type: text/html' ) ;
                header( 'Content-Length: ' . strlen( $htmlBackroom ) ) ;
                print $htmlBackroom ;
                $ok = TRUE ;
            }
        }
    } // $https

    if( !$ok ) {
        header( 'Location: https://' . $d2wWebHost . $requestUri, TRUE, 301 ) ;
        $ok = TRUE ;
    }
} // if( $d2wApiHost == $httpHost )

else if( '/index.html' == substr( $requestUri, -strlen( '/index.html' ) ) ) {
    d2w_putlog( LOG_INFO, 'RESP 301 drop trailing index.html' ) ;
    header( 'Location: ' . substr( $requestUri, 0, 
        strlen( $requestUri ) - strlen( 'index.html' ) ), TRUE ) ;
    header( 'Cache-Control: public, s-maxage=604800, max-age=604800' ) ;
    $ok = TRUE ;
}

else if( '-on.' . $d2wBaseDomain == substr( $httpHost, -strlen( '-on.' . $d2wBaseDomain ) ) ) {
    header( 'Location: https://' . str_replace( '-on.' . $d2wBaseDomain, $d2wSiteSuffix, $httpHost ) 
        . $requestUri, TRUE, 301 ) ;
    header( 'Cache-Control: public, s-maxage=604800, max-age=604800' ) ;
    d2w_putlog( LOG_INFO, 'RESP 301 moved to ' . $d2wSiteSuffix ) ;

    $ok = TRUE ;
}

else if( $d2wSiteSuffix == substr( $httpHost, -strlen( $d2wSiteSuffix ) ) ) {

    $siteName = substr( $httpHost, 0, strlen( $httpHost ) - strlen( $d2wSiteSuffix ) ) ;
    
    if( isset( $_SERVER[ 'HTTP_UPGRADE_INSECURE_REQUESTS' ] )
        && '1' == $_SERVER[ 'HTTP_UPGRADE_INSECURE_REQUESTS' ]
        && ( 'GET' == $requestMethod || 'HEAD' == $requestMethod )
        && !$https ) {

        header( 'Location: https://' . $httpHost . $requestUri, TRUE, 307 ) ;
        header( 'Vary: Upgrade-Insecure-Requests' ) ;
        header( 'Cache-Control: public, s-maxage=604800, max-age=604800' ) ;
        d2w_putlog( LOG_INFO, 'RESP 307 upgraded' ) ;

        $ok = TRUE ;
    }
    else
        $ok = resolve( $siteName, $cmd ) ;
}

else if( in_array( $httpHost, $d2wCdnHosts, TRUE ) ) {
    if( '_guf' == $cmd[ 1 ] && 4 == count( $cmd ) ) {
        $siteName = $cmd[ 2 ] ;
        $idFile = $cmd[ 3 ] ;

        // Both values end up in Google API URLs and cache keys
        if( !d2w_valid_site_name( $siteName ) || !d2w_valid_file_id( $idFile ) ) {
            header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 400 Bad Request', TRUE, 400 ) ;
            header( 'Content-Length: 0' ) ;
            d2w_putlog( LOG_WARNING, 'SECU:bad _guf args' ) ;
            $ok = TRUE ;
        }

        $siteInfo = $ok ? FALSE : getSiteInfo( $siteName ) ;
        if( $siteInfo && isset( $siteInfo->userId ) ) {
            $token = gdGetAccessToken( $siteInfo->userId ) ;
    
            header( 'Cache-Control: public, s-maxage=604800, max-age=604800' ) ;
            $ok = gdSendFile( $siteInfo->userId, $token, $idFile ) ;
            d2w_putlog( LOG_INFO, 'FILE _GUF sent ' . ( $ok ? 'OK': 'FAILED' ) ) ;    
        }
    }
}

else if( substr_count( $httpHost, '.' ) > 2 
    && '.' . $d2wBaseDomain == substr( $httpHost, -strlen( '.' . $d2wBaseDomain ) ) ) {
    header( 'Location: https://' . $d2wWebHost . '/', TRUE ) ;
    d2w_putlog( LOG_INFO, 'RESP 302 home (subdomain depth)' ) ;
    $ok = TRUE ;
}

else {
    // Handle custom domain name DNS records here
    $siteName = NULL ;
    $names = dns_get_record( $httpHost, DNS_TXT ) ;
    //d2w_putlog( LOG_INFO, 'names ' . print_r( $names, TRUE ) ) ;
    if( FALSE !== $names && count( $names ) > 0 ) {
        for( $i = 0; $i < count( $names ); $i++ ) {
            $rec = $names[ $i ] ;
            if( 'TXT' == $rec[ 'type' ] 
                && 'DRVTW=' == substr( $rec[ 'txt' ], 0, strlen( 'DRVTW=' ) ) ) {

                // 'DRVTW=<name>.on.drv.tw' or legacy 'DRVTW=@<name>'
                $target = mb_strtolower( $rec[ 'txt' ] ) ;
                $value = substr( $target, strlen( 'DRVTW=' ) ) ;

                if( '.on.' . $d2wBaseDomain == substr( $value, -strlen( '.on.' . $d2wBaseDomain ) )
                    || '-on.' . $d2wBaseDomain == substr( $value, -strlen( '-on.' . $d2wBaseDomain ) ) )

                    // Strip the suffix so the site name keeps no stray '/'
                    $siteName = substr( $value, 0, strlen( $value )
                        - strlen( '.on.' . $d2wBaseDomain ) ) ;
                else if( '@' == substr( $value, 0, 1 ) )
                    $siteName = substr( $value, 1 ) ;

                // Never trust a DNS record that yields a malformed name
                if( NULL !== $siteName && !d2w_valid_site_name( $siteName ) )
                    $siteName = NULL ;
            }
        }
    }
    else {
        $names = dns_get_record( $httpHost, DNS_CNAME ) ;
        for( $i = 0; $i < count( $names ); $i++ ) {
            $rec = $names[ $i ] ;
            if( 'CNAME' == $rec[ 'type' ] ) {
                $target = mb_strtolower( $rec[ 'target' ] ) ;
                if( $d2wSiteSuffix == substr( $target, -strlen( $d2wSiteSuffix ) )
                    || '-on.' . $d2wBaseDomain == substr( $target, -strlen( '-on.' . $d2wBaseDomain ) ) ) {

                    $siteName = substr( $target, 0, strlen( $target ) - strlen( $d2wSiteSuffix ) ) ;
                    if( !d2w_valid_site_name( $siteName ) )
                        $siteName = NULL ;
                }
            }
        }
    }

    if( $siteName ) {
        array_splice( $cmd, 1, 0, $httpHost ) ;
        d2w_putlog( LOG_INFO, 'VIRT prefixing ' . $httpHost . ' with ' . $siteName ) ;
        $ok = resolve( $siteName, $cmd ) ;
    }
    else {
        $maxAge = 300 + mt_rand( 0, 600 ) ;
        header( 'Cache-Control: public, s-maxage=' . $maxAge . ', max-age=' . $maxAge ) ;
        header( 'Location: https://' . $d2wWebHost . '/', TRUE ) ;
        d2w_putlog( LOG_INFO, 'RESP 302 home (unknown host "' . $httpHost . '")' ) ;
        $ok = TRUE ;
    }
}

if( !$ok ) {
    d2w_putlog( LOG_INFO, 'RESP 404' ) ;
    header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 404 Not Found', TRUE, 404 ) ;

    $page404 = d2w_inline_page( '404.html' ) ;
    if( FALSE === strpos( $requestUri, '/404.html' ) &&
        !( isset( $_SERVER[ 'HTTP_ACCEPT' ] ) && 
            FALSE === strpos( $_SERVER[ 'HTTP_ACCEPT' ], 'text/html' ) ) ) {

        $url404 = 'http' . ( $https ? 's' : '' ) . "://$httpHost/404.html" ;
        $opts = array( 
            'http' => array(
                'protocol_version' => 1.1,
                'header' => "Host: $httpHost\r\nConnection: close\r\n"
            )) ;
        $ctx = stream_context_create( $opts ) ;
        $custom404 = @file_get_contents( $url404, FALSE, $ctx ) ;

        if( FALSE !== $custom404 ) {
            d2w_putlog( LOG_INFO, 'CONF custom 404' ) ;
            $page404 = $custom404 ;
        }
        else
            d2w_putlog( LOG_INFO, 'CONF FAILED custom 404 @ ' . $url404 ) ;
    }
    header( 'Cache-Control: public, s-maxage=604800, max-age=604800' ) ;
    header( 'Content-Type: text/html' ) ;
    header( 'Content-Length: ' . strlen( $page404 ) ) ;
    print $page404 ;
}

$usElapsed = microtime( TRUE ) - $usStart ;
// One X per 20 ms, capped at 60. The (int) is not cosmetic: str_repeat() wants an int
// length, and handing it $usElapsed * 5 made PHP 8.1+ emit "Implicit conversion from
// float to int loses precision" on *every* request - an HTML banner spliced into JSON
// responses the moment display_errors is on. The scale was wrong too: five times a
// seconds-count leaves an empty bar for anything under 200 ms.
d2w_putlog( LOG_INFO, 'TIME ' . str_repeat( 'X', min( 60, (int)( $usElapsed * 50 ) ) ) . ' '
    . number_format( $usElapsed, 3 ) ) ;
exit ;

/**
 * Hourly operator token. It must NOT be computable from public information:
 * the previous implementation was sha1( hour + 919 ), which anyone can
 * reproduce, so the @@tok "protection" guarded nothing at all.
 */
function time_hash( $offset = 0 ) {
    return d2w_ops_token( NULL, $offset ) ;
}

/**
 * Is this request allowed to use the operator tools (@@backroom, @@log.csv)?
 */
function ops_is_authorized( $presentedToken = NULL ) {
    global $opsUser, $opsPass, $authHeader ;

    $authUser = isset( $_SERVER[ 'PHP_AUTH_USER' ] ) ? $_SERVER[ 'PHP_AUTH_USER' ] : NULL ;
    $authPass = isset( $_SERVER[ 'PHP_AUTH_PW' ] ) ? $_SERVER[ 'PHP_AUTH_PW' ] : NULL ;

    if( NULL === $presentedToken && isset( $_SERVER[ 'HTTP_X_D2W_TOKEN' ] ) )
        $presentedToken = $_SERVER[ 'HTTP_X_D2W_TOKEN' ] ;

    return d2w_ops_authorized( $presentedToken, $authUser, $authPass,
        ( isset( $opsUser ) && '' !== $opsUser ) ? $opsUser : NULL,
        ( isset( $opsPass ) && '' !== $opsPass ) ? $opsPass : NULL, NULL ) ;
}

/**
 * Owner session that guards privileged per-file commands (@@lock, @@delete,
 * @@publish, ...). Established at the end of the server-side sign-in and
 * scoped to the parent domain so <site>.on.<domain> sees it too.
 */
function set_owner_session( $userId, $service ) {
    global $d2wBaseDomain ;

    if( headers_sent() )
        return ;

    setcookie( 'D2WSESS', d2w_make_session( $userId, $service ), array(
        'expires' => time() + intval( $GLOBALS[ 'sessionTtlSec' ] ) ,
        'path' => '/',
        'domain' => '.' . $d2wBaseDomain,
        'secure' => TRUE ,
        'httponly' => TRUE ,
        'samesite' => 'Lax',
    ) ) ;
}

/**
 * How was this request authenticated for a privileged command?
 * @return string|NULL  'cookie' | 'bearer' | NULL (not authorised)
 */
function owner_auth_method( $userId, $service ) {
    global $authHeader ;

    $cookie = isset( $_COOKIE[ 'D2WSESS' ] ) ? $_COOKIE[ 'D2WSESS' ] : NULL ;
    $token = getAccessToken( $userId, $service ) ;
    $method = NULL ;

    d2w_privileged_request_allowed( $userId, $service, $cookie, $authHeader,
        isset( $token[ 'token' ] ) ? $token[ 'token' ] : NULL, $method ) ;

    return $method ;
}

/**
 * May this request run a privileged command against this site owner's files?
 */
function owner_is_authorized( $userId, $service ) {
    return NULL !== owner_auth_method( $userId, $service ) ;
}

function sendAuthCode( $site, $email ) {

    global $httpOrigin, $blacklist_email, $sesSmtpUser, $sesSmtpPassword ;
    global $smtpHost, $smtpPort, $mailFrom, $mailFromName ;
    global $corsAllowedOrigins, $d2wSiteSuffix ;

    $email = d2w_normalize_email( $email ) ;
    $bBadEmail = ( FALSE === $email ) ;

    $res = array(
        'site' => $site,
        'email' => $email
    ) ;

    $sWaitTime = 0 ;

    if( !$bBadEmail ) {
        // Throttle per site AND per recipient: one site could otherwise be
        // used to mail-bomb an address, or many sites the same address.
        foreach( array( $site, 'email:' . $email ) as $slot ) {
            $tsLastSent = getLastAuthCodeTimestamp( $slot ) ;
            if( NULL !== $tsLastSent ) {
                $wait = intval( $GLOBALS[ 'authCodeSendGapSec' ] ) - ( time() - $tsLastSent ) ;
                if( $wait > $sWaitTime )
                    $sWaitTime = $wait ;
            }
        }
    }

    // The shipped check was
    //   else if( !($atpos = strpos($email,'@') || !dns_get_mx(substr(...))) )
    // which (a) assigned a bool to $atpos, (b) short-circuited the DNS check
    // away for every address containing '@', and (c) threw ArgumentCountError
    // for addresses without '@' because dns_get_mx() needs a second argument.
    $emailCheck = $bBadEmail ? 'invalid'
        : d2w_check_recipient_email( $email, $blacklist_email ) ;

    if( $sWaitTime > 0 ) {
        $res['tip'] = "Wait $sWaitTime seconds before sending another code." ;
        $res['error'] = 'Wait' ;
        d2w_putlog( LOG_WARNING, 'SITEAUTH warn too fast' ) ;
    }
    else if( 'invalid' === $emailCheck ) {
        $res['tip'] = 'The email address is not valid.' ;
        $res['error'] = 'Email invalid' ;
        d2w_putlog( LOG_WARNING, 'SITEAUTH warn invalid' ) ;
    }
    else if( 'blocked' === $emailCheck ) {
        $res['tip'] = "Please provide an organizational (school, company, etc.) email." ;
        $res['error'] = 'Email blocked' ;
        d2w_putlog( LOG_WARNING, 'SITEAUTH warn blocked' ) ;
    }
    else if( 'unacceptable' === $emailCheck ) {
        $res['tip'] = "Please provide an organizational (school, company, etc.) email." ;
        $res['error'] = 'Email not accepted' ;
        d2w_putlog( LOG_WARNING, 'SITEAUTH warn unacceptable' ) ;
    }
    else if( 'unreachable' === $emailCheck ) {
        $res['tip'] = 'Unable to send to the email address. Please check your spelling.' ;
        $res['error'] = 'Email not reachable' ;
        d2w_putlog( LOG_WARNING, 'SITEAUTH warn unreachable' ) ;
    }
    else
    {
        $code = sprintf( '%06d', random_int( 0, 999999 ) ) ; // CSPRNG: it is a credential
        setSiteAuthCode( $site, $code, $email ) ;
        setSiteAuthCode( 'email:' . $email, $code, $email ) ; // recipient throttle
    
        $mailer = new PHPMailer() ;

        $mailer->isSMTP() ;
        // $mailer->SMTPDebug = SMTP::DEBUG_SERVER;
        $mailer->Host = $smtpHost ;
        $mailer->SMTPAuth = true ;
        $mailer->Username = $sesSmtpUser ;
        $mailer->Password = $sesSmtpPassword ;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS ;
        $mailer->Port = $smtpPort ;
        $mailer->Timeout = 20 ;

        $mailer->setFrom( $mailFrom, $mailFromName ) ;
        $mailer->addAddress( $email ) ;
        $mailer->addCustomHeader( 'Auto-Submitted: auto-generated' ) ;
        $mailer->addCustomHeader( 'Precedence: bulk' ) ;
                
        $mailer->isHTML( true ) ;
        $mailer->Subject = 'DriveToWeb authentication code';
        $mailer->Body    = "<b>$code</b> is your site authentication code. Valid for 15 minutes only.<br/>"
            . 'If you did not request it, you may ignore this safely.' ;
        $mailer->AltBody = "\"$code\" is your site authentication code. Valid for 15 minutes only.\n"
            . 'If you did not request it, you may ignore this safely.' ;
        
                $mins = max( 1, intval( round( $GLOBALS[ 'authCodeTtlSec' ] / 60 ) ) ) ;
        $mailer->Body = str_replace( '15 minutes', $mins . ' minutes', $mailer->Body ) ;
        $mailer->AltBody = str_replace( '15 minutes', $mins . ' minutes', $mailer->AltBody ) ;

        try {
            $mailer->send() ;
            // success was previously reported even when send() threw
            $res['success'] = TRUE ;
        } 
        catch( Exception $e ) {
            $res['error'] = 'Internal error: the code could not be sent.' ;
            $res['success'] = FALSE ;
            d2w_putlog( LOG_WARNING, 'SITEAUTH err ' . $mailer->ErrorInfo ) ;
        }    
    }

    $body = json_encode( $res ) ;
    // Never reflect an arbitrary Origin (that lets any web page use this
    // endpoint); and always vary on Origin so caches do not mix responses.
    $corsOrigin = d2w_cors_origin( $httpOrigin, $corsAllowedOrigins, $d2wSiteSuffix ) ;
    if( $corsOrigin )
        header( 'Access-Control-Allow-Origin: ' . $corsOrigin ) ;
    header( 'Vary: Origin' ) ;
    header( 'Content-Length: ' . strlen( $body ) ) ;
    header( 'Content-Type: application/json' ) ;
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
    header( 'Pragma: no-cache' ) ;
    print $body ;
    d2w_putlog( LOG_INFO, "SITEAUTH sent for $site" . ( isset($res['error']) ? ' E ' . $res['error'] : '' ) ) ;

    return true ;
}

/**
 * Redeem a site access code.
 *
 * The redirect target is restricted to the site itself. Previously any
 * attacker could pass url=https://evil.example and get a trusted
 * api.<domain> 302 to it, and the redirect happened even when the code was
 * wrong (so a rejected code still looked like a successful visit).
 */
function checkAuthCode( $site, $code, $url ) {
    global $d2wBaseDomain, $authCodeMaxFails, $authCodeRetryWindowSec ;

    // A 6-digit code valid for 15 minutes is only safe if guessing is bounded:
    // the shipped code throttled *sending* but never *verifying*.
    $fails = getAuthFailures( $site ) ;
    $policy = d2w_attempt_policy( time(), $fails[ 'fails' ], $fails[ 'ts' ],
        intval( $authCodeMaxFails ),
        intval( isset( $authCodeRetryWindowSec ) ? $authCodeRetryWindowSec : 900 ) ) ;

    if( !$policy[ 'allowed' ] ) {
        d2w_putlog( LOG_WARNING, 'SITEAUTH throttled site=' . $site
            . ' fails=' . $policy[ 'fails' ] ) ;

        $url = d2w_safe_site_redirect( $url, $site, $d2wBaseDomain ) ;
        $url .= ( FALSE === strpos( $url, '?' ) ? '?' : '&' ) . 'auth=slow' ;
        header( 'Location: ' . $url, TRUE, 302 ) ;
        header( 'Retry-After: ' . $policy[ 'retryAfter' ] ) ;
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;

        return true ;
    }

    $bValid = checkSiteAuthCode( $site, $code ) ;

    if( $bValid ) {
        setSiteAuthenticate( $site, true ) ;
        clearAuthFailures( $site ) ;
        d2w_putlog( LOG_INFO, 'SITEAUTH validated ' . $site ) ;
    }
    else {
        recordAuthFailure( $site ) ;
        d2w_putlog( LOG_WARNING, 'SITEAUTH rejected code for ' . $site
            . ' attempt=' . ( $policy[ 'fails' ] + 1 ) ) ;
    }

    $url = d2w_safe_site_redirect( $url, $site, $d2wBaseDomain ) ;
    if( !$bValid )
        $url .= ( FALSE === strpos( $url, '?' ) ? '?' : '&' ) . 'auth=bad' ;

    header( 'Location: ' . $url, TRUE, 302 ) ;
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
    header( 'Pragma: no-cache' ) ;

    return true ;
}

/***
 * reportAbuse
 * 
 * $params['url'] required, "$URL" OR "$DOMAIN"
 * $params['attack_type'] required, "$ATTACK_TYPE"
 * $params['description'] "This attack targets $BRAND, more information can be found at $INCIDENT_REPORT_LINK"
 * $params['date'] "$DATE"
 * $params['target_brand'] "$BRAND"
 * $params['screenshot_url'] "$SCREENSHOT_URL"
 * 
 * Returns TRUE if handled
 */
function reportAbuse( $params ) {

    // $authHeader is the file-scope value parsed from HTTP_AUTHORIZATION; without
    // globalising it every `Authorization: Bearer <$apiTokens[…]>` caller was
    // refused, because isset() on a function-local was always FALSE.
    global $apiTokens, $authHeader ;

    $bHandled = TRUE ;
    $error = NULL ;
    $res = array() ;

    $auth = NULL ;
    if( isset( $authHeader ) ) {
        $authHeader = trim( $authHeader ) ;
        if( preg_match( '/^(?:Bearer|Token)\s+(\S+)$/i', $authHeader, $matches ) )
            $auth = $matches[ 1 ] ;
    }

    $apiUser = NULL ;
    if( is_array( $apiTokens ) )
        foreach( $apiTokens as $user => $token ) {
            // Constant-time compare, and never echo the caller's token back
            if( '' !== $token && d2w_equals( $auth, $token ) ) {
                $apiUser = $user ;
                break ;
            }
        }

    $status = 200 ;
    if( $apiUser ) {
        if( !( isset( $params[ 'url' ] ) && isset( $params[ 'attack_type' ] ) ) ) {
            $res['error'] = "ERR_INCOMPLETE_REPORT" ; // url AND attack_type are required
            $status = 400 ;
        }
        else {
            $abuseId = addAbuseReport( $apiUser, $params ) ;
            if( !empty( $abuseId ) )
                $res['reportId'] = $abuseId ;
            else {
                $res['error'] = "ERR_FAILED" ;
                $status = 500 ;
            }
        }
    }
    else {
        $res['error'] = "ERR_AUTH_FAILED" ;
        $status = 401 ;
    }

    $body = json_encode( $res ) ;
    if( 200 != $status )
        header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' ' . $status, TRUE, $status ) ;
    header( 'Content-Length: ' . strlen( $body ) ) ;
    header( 'Content-Type: application/json' ) ;
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
    header( 'Pragma: no-cache' ) ;
    print $body ;
    d2w_putlog( LOG_INFO, "API reportAbuse done" ) ;

    return $bHandled ;
}

function resolve( $siteName, $cmd ) {

    global $httpNoCache ;

    $ok = FALSE ;

    // The site name comes from the Host header and path segments are
    // urldecoded before we get here, so both must be validated before they
    // are used in Drive/Graph queries, cache keys and generated URLs.
    if( !d2w_valid_site_name( $siteName ) )
        return FALSE ;

    for( $i = 1; $i < count( $cmd ); $i++ )
        if( !d2w_path_is_safe( $cmd[ $i ] ) )
            return FALSE ;

    $siteInfo = getSiteInfo( $siteName ) ;

    if( $siteInfo && isset( $siteInfo->userId ) && isset( $siteInfo->service ) 
        && isset( $siteInfo->root ) ) {

        if( 'd2w_sw.js' === $cmd[ count( $cmd ) - 1 ] ) {
            // Make d2w_sw.js appear wherever it is needed (folder of requesting HTML)
            $fn = 'inline/d2w_sw.js' ;
            $stat = stat( $fn ) ;
            $dtLastModified = new DateTime() ;
            $dtLastModified->setTimestamp( $stat[ 'mtime' ] ) ;

            $ok = sendContent( 'file', $fn, 'application/javascript', $stat[ 'size' ], 
                NULL, $dtLastModified, $siteInfo->userId ) ;
        }
        else {
            $userId = $siteInfo->userId ;
            $service = $siteInfo->service ;
            $root = trim( $siteInfo->root, '/' ) ;
            $rootSegments = explode( '/', $root ) ;
            // The shipped code hardcoded TRUE here, which silently disabled the
            // quota-approval gate *and* the "visitors must authenticate by
            // email" feature: db.sites.bAuthenticated was written but never read.
            // Default keeps today's behaviour (serve everything); set
            // $requireApprovedSites = TRUE to let the stored flag govern again.
            global $requireApprovedSites ;
            $bAuthenticated = $requireApprovedSites
                ? ( isset( $siteInfo->bAuthenticated ) && $siteInfo->bAuthenticated )
                : TRUE ;
    
            if( 'gd' == $service ) {
                $token = gdGetAccessToken( $userId ) ;
    
                if( $token ) {
                    $path = array() ;
                    for( $i = 0; $i < count( $rootSegments ); $i++ )
                        if( $rootSegments[ $i ] )
                            array_push( $path, $rootSegments[ $i ] ) ;
                    for( $i = 1; $i < count( $cmd ); $i++ )
                        if( $i == count( $cmd ) - 1 && '' == $cmd[ $i ] )
                            array_push( $path, 'index.html' ) ;
                        else
                            array_push( $path, $cmd[ $i ] ) ;
        
                    // Invalidate cache if requested so
                    if( $httpNoCache )
                        gdInvalidateCache( $userId ) ;
        
                    $ok = gdNavigate( $userId, $token, $path, $siteName, $bAuthenticated,
                        0, 'root', 0, NULL, $service ) ;
                }
            }
            else if( 'od' == $service ) {
                $token = odGetAccessToken( $userId ) ;
    
                if( $token ) {
                    $path = '' ;
                    for( $i = 0; $i < count( $rootSegments ); $i++ )
                        if( $rootSegments[ $i ] )
                            $path .= '/' . $rootSegments[ $i ] ;
                    for( $i = 1; $i < count( $cmd ); $i++ )
                        if( $i == count( $cmd ) - 1 && '' == $cmd[ $i ] )
                            $path .= '/index.html' ;
                        else
                            $path .= '/' . $cmd[ $i ] ;
        
                    $ok = odNavigate( $userId, $token, $path, $siteName, $bAuthenticated ) ;
                }
            }    
        }
    }
    return $ok ;
}

function gdGetOauthUrl( $username, $state ) {
    global $gdClientId, $d2wApiHost ;

    $res = 'https://accounts.google.com/o/oauth2/v2/auth'
        . '?scope=' . urlencode( 'https://www.googleapis.com/auth/drive' ) 
        . '&response_type=code'
        . '&client_id=' . urlencode( $gdClientId )
        . '&redirect_uri=' . urlencode( 'https://' . $d2wApiHost . '/.a/gd/' )
        . '&access_type=offline'
        . '&prompt=consent'
        . '&state=' . urlencode( $state ) ;

    if( $username )
        $res .= '&login_hint=' . urlencode( $username ) ;

    return $res ;
}

function odGetOauthUrl( $username, $state ) {
    global $odClientId, $d2wApiHost ;

    $res = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'
        . '?scope=' . urlencode( 'User.Read Files.ReadWrite.All offline_access' ) 
        . '&client_id=' . urlencode( $odClientId )
        . '&response_type=code'
        . '&redirect_uri=' . urlencode( 'https://' . $d2wApiHost . '/.a/od/' )
        . '&prompt=consent'
        . '&state=' . urlencode( $state ) ;

    if( $username )
        $res .= '&login_hint=' . urlencode( $username ) ;

    return $res ;
}

function gdGetUserInfo( $accessToken ) {
    $user = FALSE ;

    $url = 'https://www.googleapis.com/drive/v3/about?'
        . 'fields=user(displayName%2CemailAddress)' ;

    $opts = array( 'http' => array (
        'method' => 'GET',
        'header' => 'Authorization: Bearer ' . $accessToken
    ) ) ;
    $context = stream_context_create( $opts ) ;
    $result = @file_get_contents( $url, FALSE, $context ) ;

    if( $result ) {
        $userInfo = json_decode( $result ) ;
        $user = array(
            'displayName' => $userInfo->user->displayName,
            'userName'    => $userInfo->user->emailAddress
        ) ;
    }

    return $user ;
}

function odGetUserInfo( $accessToken ) {
    $user = FALSE ;

    $url = 'https://graph.microsoft.com/v1.0/me' ;

    $opts = array( 'http' => array (
        'method' => 'GET',
        'header' => 'Authorization: Bearer ' . $accessToken
    ) ) ;
    $context = stream_context_create( $opts ) ;
    $result = @file_get_contents( $url, FALSE, $context ) ;

    if( $result ) {
        $userInfo = json_decode( $result ) ;
        $user = array(
            'displayName' => $userInfo->displayName,
            'userName'    => $userInfo->userPrincipalName
        ) ;
    }

    return $user ;
}

function gdSetupFromAuthCode( $code, $state ) {
    global $gdClientId, $gdClientSecret, $d2wApiHost ;

    $url = 'https://www.googleapis.com/oauth2/v4/token' ;
    $data = array( 
        'code'          => $code,
        'client_id'     => $gdClientId,
        'client_secret' => $gdClientSecret,
        'redirect_uri'  => 'https://' . $d2wApiHost . '/.a/gd/',
        'grant_type'    => 'authorization_code'
    ) ;

    $options = array( 'http' => array (
        'header'  => "Content-type: application/x-www-form-urlencoded; charset=utf-8\r\n",
        'method'  => 'POST',
        'content' => http_build_query( $data )
    ) ) ;
    $context = stream_context_create( $options ) ;
    $jsonTokens = @file_get_contents( $url, FALSE, $context ) ;

    $result = FALSE ;
    if( $jsonTokens ) {
        $tokens = json_decode( $jsonTokens ) ;
        $user = gdGetUserInfo( $tokens->access_token ) ;

        if( $user ) {
            setUserInfo( $user ) ;
            d2w_putlog( LOG_INFO, 'NEWU user ' . $user[ 'userName' ] ) ;
            $userId = getUserIdFromName( $user[ 'userName' ] ) ;

            if( !getSiteName( $userId, 'gd' ) )
                setSiteInfo( NULL, $userId, 'gd', '/' ) ;

            if( isset( $tokens->refresh_token ) )
                setRefreshToken( $userId, 'gd', $tokens->refresh_token ) ;
            
            setAccessToken( $userId, 'gd', $tokens->access_token, 
                $tokens->token_type, time() + $tokens->expires_in ) ;

            // Establish the owner session that guards privileged @@ commands
            set_owner_session( $userId, 'gd' ) ;

            $urlTail = '' ;
            $iTail = strpos( $state, '/', 1 ) ; // Find the 2nd '/'
            if( $iTail )
                $urlTail = substr( $state, $iTail ) ;

            $result = 'https://' . $d2wApiHost . '/~' . rawurlencode( $user[ 'userName' ] ) . $urlTail ;
        }
        else
            d2w_putlog( LOG_ERR, 'NEWU ERR get user info failed' ) ;
    }
    else {
        // TODO: handle error in case request failed (oauth hacked?)
        d2w_putlog( LOG_ERR, 'NEWU ERR no auth tokens' ) ;
    }

    return $result ;
}

function odSetupFromAuthCode( $code, $state ) {
    global $odClientId, $odClientSecret, $d2wApiHost ;

    $url = 'https://login.microsoftonline.com/common/oauth2/v2.0/token' ;
    $data = array( 
        'code'          => $code,
        'client_id'     => $odClientId,
        'client_secret' => $odClientSecret,
        'redirect_uri'  => 'https://' . $d2wApiHost . '/.a/od/',
        'grant_type'    => 'authorization_code'
    ) ;

    $options = array( 'http' => array (
        'header'  => "Content-type: application/x-www-form-urlencoded; charset=utf-8\r\n",
        'method'  => 'POST',
        'content' => http_build_query( $data )
    ) ) ;
    $context = stream_context_create( $options ) ;
    $jsonTokens = file_get_contents( $url, FALSE, $context ) ;
    //d print_r( $jsonTokens ) ;

    $result = FALSE ;
    if( $jsonTokens ) {
        $tokens = json_decode( $jsonTokens ) ;
        $user = odGetUserInfo( $tokens->access_token ) ;

        if( $user ) {
            setUserInfo( $user ) ;
            $userId = getUserIdFromName( $user[ 'userName' ] ) ;

            if( !getSiteName( $userId, 'od' ) )
                setSiteInfo( NULL, $userId, 'od', '/' ) ;

            if( isset( $tokens->refresh_token ) )
                setRefreshToken( $userId, 'od', $tokens->refresh_token ) ;
            
            setAccessToken( $userId, 'od', $tokens->access_token, 
                $tokens->token_type, time() + $tokens->expires_in ) ;

            set_owner_session( $userId, 'od' ) ;

            $urlTail = '' ;
            $iTail = strpos( $state, '/', 1 ) ; // Find the 2nd '/'
            if( $iTail )
                $urlTail = substr( $state, $iTail ) ;

            $result = 'https://' . $d2wApiHost . '/~' . rawurlencode( $user[ 'userName' ] ) . $urlTail ;
        }
    }
    else {
        // TODO: handle error in case request failed (oauth hacked?)
    }

    return $result ;
}

function gdGetAccessToken( $userId ) {
    global $gdClientId, $gdClientSecret, $d2wApiHost ;

    $result = getAccessToken( $userId, 'gd' ) ;

    if( !$result ) {
        $refreshToken = getRefreshToken( $userId, 'gd' ) ;

        if( $refreshToken ) {
            $url = 'https://www.googleapis.com/oauth2/v4/token' ;
            $data = array( 
                'refresh_token' => $refreshToken,
                'client_id'     => $gdClientId,
                'client_secret' => $gdClientSecret,
                'grant_type'    => 'refresh_token'
            ) ;

            $options = array( 'http' => array (
                'header'  => "Content-type: application/x-www-form-urlencoded; charset=utf-8\r\n",
                'method'  => 'POST',
                'content' => http_build_query( $data )
            ) ) ;
            $context = stream_context_create( $options ) ;
            $jsonTokens = @file_get_contents( $url, FALSE, $context ) ;

            if( $jsonTokens ) {
                $tokens = json_decode( $jsonTokens ) ;

                setAccessToken( $userId, 'gd', $tokens->access_token, 
                    $tokens->token_type, time() + $tokens->expires_in ) ;

                $result = getAccessToken( $userId, 'gd' ) ;
            }
        }
        else {
            d2w_putlog( LOG_ERR, 'AUTH:err cannot get refresh token' ) ;
            $username = getUserNameFromId( $userId, 'gd' ) ;
            if( $username )
                header( 'Location: https://' . $d2wApiHost . '/~' . rawurlencode( $username )
                    . '/gd/?a=admin', TRUE, 301 ) ;
            else
                d2w_putlog( LOG_ERR, 'AUTH:err unknown user ID' ) ;
        }
    }

    return $result ;
}

function odGetAccessToken( $userId ) {
    global $odClientId, $odClientSecret, $d2wApiHost ;

    $result = getAccessToken( $userId, 'od' ) ;

    if( !$result ) {
        $refreshToken = getRefreshToken( $userId, 'od' ) ;

        if( $refreshToken ) {
            $url = 'https://login.microsoftonline.com/common/oauth2/v2.0/token' ;
            $data = array( 
                'refresh_token' => $refreshToken,
                'client_id'     => $odClientId,
                'client_secret' => $odClientSecret,
                'redirect_uri'  => 'https://' . $d2wApiHost . '/.a/od/',
                'grant_type'    => 'refresh_token'
            ) ;

            $options = array( 'http' => array (
                'header'  => "Content-type: application/x-www-form-urlencoded; charset=utf-8\r\n",
                'method'  => 'POST',
                'content' => http_build_query( $data )
            ) ) ;
            $context = stream_context_create( $options ) ;
            $jsonTokens = @file_get_contents( $url, FALSE, $context ) ;

            if( !$jsonTokens ) {
                d2w_putlog( LOG_INFO, 'CPAT fallback to Live API for token' ) ;
                $url = 'https://login.live.com/oauth20_token.srf' ;
                $jsonTokens = @file_get_contents( $url, FALSE, $context ) ;
            }

            if( $jsonTokens ) {
                $tokens = json_decode( $jsonTokens ) ;

                setAccessToken( $userId, 'od', $tokens->access_token, 
                    $tokens->token_type, time() + $tokens->expires_in ) ;

                $result = getAccessToken( $userId, 'od' ) ;
            }
        }
        else
            d2w_putlog( LOG_ERR, 'AUTH:err cannot get refresh token' ) ;
    }

    return $result ;
}





function gdGetFile( $userId, $token, $idFile, $cAttempts = 0, $cacheVersion = NULL ) {
    $result = FALSE ;

    if( !d2w_valid_file_id( $idFile ) )
        return FALSE ;

    $idFile = rawurlencode( $idFile ) ;

    if( $cAttempts > 5 ) {
        d2w_putlog( LOG_ERR, 'BACKOUT u=' . $userId . ', f=' . $idFile ) ;
        return FALSE ;
    }

    $url = 'https://www.googleapis.com/drive/v3/files/'
        . $idFile . '?fields=size%2CmimeType%2CmodifiedTime%2CheadRevisionId' ;

    [ $response_header, $response_body ]
        = gdCallWithCache( $userId, $token, $url, $cAttempts, $cacheVersion ) ;

    $matches = array() ;
    if( $response_header && preg_match( '#HTTP/\d+.\d+ (\d+)#', $response_header[ 0 ], $matches ) ) {
        $http_response_code = $matches[ 1 ] ;

        if( '200' != $http_response_code ) {
            // Error handling
            if( '5' == $http_response_code[ 0 ] ) {
                // Server error. Re-try
                d2w_putlog( LOG_WARNING, 'RETRY on gdGetFile ' . $http_response_code ) ;
                $result = gdGetFile( $userId, $token, $idFile, ++$cAttempts, 
                    $cacheVersion ) ;
            }
        }
        else {
            $file = json_decode( $response_body ) ;
    
            $url = 'https://www.googleapis.com/drive/v3/files/'
                . $idFile . '?alt=media' ;
            $opts = array( 'http' => array (
                'method' => 'GET',
                'header' => 'Authorization: Bearer ' . $token[ 'token' ]
            ) ) ;
            $context = stream_context_create( $opts ) ;
            $result = array(
                'mimeType' => $file->mimeType,
                'size' => $file->size,
                'content' => @file_get_contents( $url, FALSE, $context )
            ) ;
    
            if( FALSE === $result[ 'content' ] ) {
                $http_response_code = NULL ;
                $responseHeader = $http_response_header ;
    
                if( $responseHeader && isset( $responseHeader[ 0 ] ) ) {
                    $matches = array() ;
                    preg_match( '#HTTP/\d+.\d+ (\d+)#', $http_response_header[ 0 ], $matches ) ;
                    $http_response_code = $matches[1] ;                    
                }
        
                if( !$http_response_code || '5' == $http_response_code[ 0 ] ) {
                    d2w_putlog( LOG_WARNING, 'RETRY on webContentLink failed to load: ' . $http_response_code ) ;
    
                    $result = gdGetFile( $userId, $token, $idFile, ++$cAttempts, 
                        $cacheVersion ) ;    
                }
                else if( '2' != $http_response_code[ 0 ] )
                    d2w_putlog( LOG_WARNING, 'ERROR gdGetFile: ' . $http_response_code ) ;
            }
        }    
    }

    return $result ;
}

function gdSendFile( $userId, $token, $idFile ) {
    $ok = FALSE ;

    $result = gdGetFile( $userId, $token, $idFile ) ;
    if( FALSE !== $result ) {
        header( 'Content-Type: ' . $result[ 'mimeType' ] ) ;
        header( 'Content-Length: ' . $result[ 'size' ] ) ;
        d2w_putlog( LOG_INFO, 'FILE to send: type ' . $result[ 'mimeType' ] 
            . ', size ' . $result[ 'size' ] ) ;

        print( $result[ 'content' ] ) ;
        $ok = TRUE ;
    }

    return $ok ;
}

function gdUnlock( $userId, $token, $idFile, $bPublic = FALSE ) {
    if( !d2w_valid_file_id( $idFile ) )
        return FALSE ;

    $idFile = rawurlencode( $idFile ) ;

    $ok = FALSE ;

    $url = 'https://www.googleapis.com/drive/v3/files/'
        . $idFile . '/permissions' ;

    $headers = array( 
        'Authorization: Bearer ' . $token[ 'token' ],
        'Content-Type: application/json'
    ) ;
    $postdata = '{"role":"reader","type":"anyone","allowFileDiscovery":' 
        . ( $bPublic ? 'true' : 'false' ) . '}' ;

    $ch = curl_init( $url ) ;
    curl_setopt( $ch, CURLOPT_POST, TRUE ) ;
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ) ;
    curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata ) ;
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, TRUE ) ;
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE ) ;

    $resPerm = curl_exec( $ch ) ;
    curl_close( $ch ) ;

    if( $resPerm ) {
        $url = 'https://www.googleapis.com/drive/v3/files/' . $idFile ;

        $headers = array( 
            'Authorization: Bearer ' . $token[ 'token' ],
            'Content-Type: application/json'
        ) ;
        $postdata = '{"copyRequiresWriterPermission":"false"}' ;
    
        $ch = curl_init( $url ) ;
        curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PATCH' ) ;
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ) ;
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata ) ;
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, TRUE ) ;
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE ) ;
    
        $resCopy = curl_exec( $ch ) ;
        curl_close( $ch ) ;
    
        if( $resCopy ) {
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
            header( 'Pragma: no-cache' ) ;
            print( $resPerm . ' ' . $resCopy ) ;
            $ok = TRUE ;
        }
    }

    return $ok ;
}

function gdLock( $userId, $token, $idFile ) {
    if( !d2w_valid_file_id( $idFile ) )
        return FALSE ;

    $idFile = rawurlencode( $idFile ) ;

    $ok = FALSE ;

    $url = 'https://www.googleapis.com/drive/v3/files/'
        . $idFile . '/permissions/anyoneWithLink' ;
    
    $headers = array( 'Authorization: Bearer ' . $token[ 'token' ] ) ;

    $ch = curl_init( $url ) ;
    curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' ) ;
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ) ;
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 ) ;

    $result = curl_exec( $ch ) ;
    $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE ) ;
    curl_close( $ch ) ;

    if( $httpCode ) {
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
        header( 'Pragma: no-cache' ) ;
        print( $httpCode ) ;
        $ok = TRUE ;
    }

    return $ok ;
}

function gdDuplicate( $userId, $token, $idFile ) {
    if( !d2w_valid_file_id( $idFile ) )
        return FALSE ;

    $idFile = rawurlencode( $idFile ) ;

    $ok = FALSE ;

    $url = 'https://www.googleapis.com/drive/v3/files/'
        . $idFile . '/copy' ;

    $headers = array( 
        'Authorization: Bearer ' . $token[ 'token' ],
        'Content-Type: application/json'
    ) ;
    $postdata = '{}';

    $ch = curl_init( $url ) ;
    curl_setopt( $ch, CURLOPT_POST, TRUE ) ;
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ) ;
    curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata ) ;
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, TRUE ) ;
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE ) ;

    $result = curl_exec( $ch ) ;
    curl_close( $ch ) ;

    if( $result ) {
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
        header( 'Pragma: no-cache' ) ;
        print( $result ) ;
        $ok = TRUE ;
    }

    return $ok ;
}

function gdDelete( $userId, $token, $idFile ) {
    if( !d2w_valid_file_id( $idFile ) )
        return FALSE ;

    $idFile = rawurlencode( $idFile ) ;

    $ok = FALSE ;

    $url = 'https://www.googleapis.com/drive/v3/files/' . $idFile ;

    $headers = array( 
        'Authorization: Bearer ' . $token[ 'token' ],
        'Content-Type: application/json'
    ) ;

    $ch = curl_init( $url ) ;
    curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "DELETE" ) ;
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ) ;
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 ) ;

    $result = curl_exec( $ch ) ;
    $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE ) ;
    curl_close( $ch ) ;

    if( $httpCode ) {
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
        header( 'Pragma: no-cache' ) ;
        print( $httpCode ) ;
        $ok = TRUE ;
    }

    return $ok ;
}

function gdMakeCache( $userId, $token, $idParent ) {
    $ok = FALSE ;

    $url = 'https://www.googleapis.com/drive/v3/files?ignoreDefaultVisibility=true' ;

    $headers = array( 
        'Authorization: Bearer ' . $token[ 'token' ],
        'Content-Type: application/json'
    ) ;
    $postdata = '{"name":".cache","description":"Temporary cache may be safely removed","parents":["' 
        . $idParent . '"],"mimeType":"application/vnd.google-apps.folder"}' ;

    $ch = curl_init( $url ) ;
    curl_setopt( $ch, CURLOPT_POST, TRUE ) ;
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ) ;
    curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata ) ;
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, TRUE ) ;
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE ) ;

    $result = curl_exec( $ch ) ;
    curl_close( $ch ) ;

    if( $result ) {
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
        header( 'Pragma: no-cache' ) ;
        print( $result ) ;

        $res = json_decode( $result ) ;
        
        if( $res && $res->id ) {
            $idFile = $res->id ;

            $url = 'https://www.googleapis.com/drive/v3/files/' . $idFile . '/permissions' ;
        
            $headers = array( 
                'Authorization: Bearer ' . $token[ 'token' ],
                'Content-Type: application/json'
            ) ;
            $postdata = '{"role":"writer","type":"anyone","allowFileDiscovery":false}' ;
        
            $ch = curl_init( $url ) ;
            curl_setopt( $ch, CURLOPT_POST, TRUE ) ;
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ) ;
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata ) ;
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, TRUE ) ;
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE ) ;
        
            $result = curl_exec( $ch ) ;
            curl_close( $ch ) ;

            print( $result ) ;

            $ok = TRUE ;
        }
    }

    return $ok ;
}





function gdInvalidateCache( $userId ) {
    $db = openDb() ;
    $bulk = new MongoDB\Driver\BulkWrite() ;
    $bulk->delete( [
        'userId'  => new MongoDB\BSON\ObjectID( $userId )
    ] ) ;
    $db['cache']->executeBulkWrite( 'wwwdrv.cache_gd', $bulk ) ;

    d2w_putlog( LOG_INFO, 'CACH:invd user ' . $userId ) ;
}

function gdUpdateCacheVersion( $userId, $token, $cAttempts = 0 ) {

    $cacheVersion = NULL ;
    $startPageToken = NULL ;

    $url = 'https://www.googleapis.com/drive/v3/changes/startPageToken?'
        . 'fields=startPageToken' ;

    // Try to get the most recently cached startPageToken, and determine if
    // it needs updating
    $urlHash = sha1( $url ) ;
    $db = openDb() ;

    // When 'version' is null, 'result' is used to store the cached startPageToken
    $filter = [ 
        'userId'  => new MongoDB\BSON\ObjectID( $userId ),
        'version' => NULL,
        'urlHash' => $urlHash 
    ] ;
    $options = [ 'projection' => [ 'result' => 1 ] ] ;
    $query = new MongoDB\Driver\Query( $filter, $options ) ;
    $recs = $db['cache']->executeQuery( 'wwwdrv.cache_gd', $query )->toArray() ;

    $cached = NULL ; 
    if( count( $recs ) > 0 )
        $cached = $recs[ 0 ] ;
    
    if( $cached && isset( $cached->result ) ) {
        $startPageToken = $cached->result ;
        $tsCached = $cached->_id->getTimestamp() ;
        $sAge = time() - $tsCached ;

        // Use cached version if it's only a few seconds old
        // Or some of the time if it's slightly older
        if( $startPageToken
            && ( ( $sAge < 6 ) || ( $sAge < 120 && mt_rand( 1, 4 ) != 1 ) ) )
            $cacheVersion = $startPageToken ;
        else
            d2w_putlog( LOG_INFO, 'CACH:chkv @ age ' . $sAge ) ;
    }

    // If not, ask drive for the latest cacheVersion
    if( !$cacheVersion ) {

        // Exponential back-off
        // pow() returns a float, so usleep() took a float: same deprecation as above.
        // Capped at 30 s as well - unbounded, this slept for minutes inside the request
        // while nginx had already given up, so the visitor got a 502 and the fpm worker
        // was still asleep.
        $usDelay = min( 30000000, (int)( ( pow( 2, $cAttempts ) - 1 ) * mt_rand( 0, 2000000 ) ) ) ;
        usleep( $usDelay ) ;

        $opts = array( 'http' => array (
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . $token[ 'token' ]
        ) ) ;
        $context = stream_context_create( $opts ) ;
        $result = @file_get_contents( $url, FALSE, $context ) ;

        if( $result ) {
            $res = json_decode( $result ) ;

            // And clear all cache entries out of date
            if( $res && isset( $res->startPageToken ) ) {
                $cacheVersion = $res->startPageToken ;
                $bulk = new MongoDB\Driver\BulkWrite() ;

                // FIRST Remove all cache entries not current
                $bulk->delete( [
                    'userId'  => new MongoDB\BSON\ObjectID( $userId ),
                    'version' => [ '$ne' => $cacheVersion ]
                ] ) ;
                // THEN record current cache version (startPageToken)
                $bulk->insert( [
                    'userId'  => new MongoDB\BSON\ObjectID( $userId ),
                    'version' => NULL,
                    'urlHash' => $urlHash,
                    'result'  => $cacheVersion
                ] ) ;
                $db['cache']->executeBulkWrite( 'wwwdrv.cache_gd', $bulk ) ;
            }
        }
        else {
            // Return NULL and let caller handle re-try
            d2w_putlog( LOG_WARNING, 'CACH:fail startPageToken failed to update #' 
                . ( $cAttempts + 1 ) . ', prev=' . $startPageToken ) ;

            if( $cAttempts < 5 )
                $cacheVersion = gdUpdateCacheVersion( $userId, $token, $cAttempts + 1 ) ;
        }
    }

    return $cacheVersion ;
}

function gdCallWithCache( $userId, $token, $url, $cAttempts = 0, $cacheVersion = NULL ) {
    $responseHeader = NULL ;
    $responseBody = NULL ;
    $urlHash = sha1( $url ) ;

    $cached = NULL ;

    if( !$cacheVersion )
        $cacheVersion = gdUpdateCacheVersion( $userId, $token ) ;

    if( $cacheVersion ) {
        $db = openDb() ;
        $filter = [ 
            'userId'  => new MongoDB\BSON\ObjectID( $userId ),
            'version' => $cacheVersion,
            'urlHash' => $urlHash 
        ] ;
        $options = [ 'projection' => 
            [ 'responseHeader' => 1, 'responseBody' => 1 ] ] ;
        $query = new MongoDB\Driver\Query( $filter, $options ) ;
        $recs = $db['cache']->executeQuery( 'wwwdrv.cache_gd', $query )->toArray() ;

        if( count( $recs ) > 0 )
            $cached = $recs[ 0 ] ;
    }

    if( $cached && isset( $cached->responseHeader ) && isset( $cached->responseBody ) ) {
        // Cache hit
        //d2w_putlog( LOG_INFO, 'CACH:hit' ) ;
        $responseHeader = $cached->responseHeader ;
        $responseBody = $cached->responseBody ;
    }
    else {
        // Cache miss
        d2w_putlog( LOG_INFO, 'CACH:miss' ) ;

        // Exponential back-off
        if( $cAttempts > 0 )
            d2w_putlog( LOG_INFO, 'CALL:rtry #' . $cAttempts . ', ' . $url ) ;
        // pow() returns a float, so usleep() took a float: same deprecation as above.
        // Capped at 30 s as well - unbounded, this slept for minutes inside the request
        // while nginx had already given up, so the visitor got a 502 and the fpm worker
        // was still asleep.
        $usDelay = min( 30000000, (int)( ( pow( 2, $cAttempts ) - 1 ) * mt_rand( 0, 2000000 ) ) ) ;
        usleep( $usDelay ) ;
    
        $opts = array( 'http' => array (
            'method'        => 'GET',
            'header'        => 'Authorization: Bearer ' . $token[ 'token' ],
            'ignore_errors' => true
        ) ) ;
        $context = stream_context_create( $opts ) ;
        $result = @file_get_contents( $url, FALSE, $context ) ;

        if( FALSE === $result )
            d2w_putlog( LOG_WARNING, 'CALL:fail ' . $url ) ;
        else {
            $responseHeader = $http_response_header ;
            $responseBody = $result ; 
    
            $matches = array() ;
            preg_match( '#HTTP/\d+.\d+ (\d+)#', $http_response_header[0], $matches ) ;
            $http_response_code = $matches[1] ;

            if( in_array( $http_response_code[0], array( '2', '3' ) ) ) {
                if( 'NOCACHE' === $cacheVersion )
                    $cacheVersion = gdUpdateCacheVersion( $userId, $token ) ;

                if( $cacheVersion ) {
                    // Update cache if success
                    $bulk = new MongoDB\Driver\BulkWrite() ;
                    $bulk->insert( [
                        'userId'  => new MongoDB\BSON\ObjectID( $userId ),
                        'version' => $cacheVersion,
                        'urlHash' => $urlHash,
                        'responseHeader' => $responseHeader,
                        'responseBody'  => $responseBody
                    ] ) ;
                    $db['cache']->executeBulkWrite( 'wwwdrv.cache_gd', $bulk ) ;
                }
            }
            else {
                d2w_putlog( LOG_WARNING, 'CALL:err HTTP ' . $http_response_code ) ;

                if( '404' !== $http_response_code ) {
                    $res = json_decode( $responseBody ) ;
                    if( isset( $res->error ) ) {
                        if( isset( $res->error->errors ) && count( $res->error->errors ) > 0 ) {
            
                            $errorDomain = $res->error->errors[ 0 ]->domain ;
                            $errorReason = $res->error->errors[ 0 ]->reason ;
            
                            if( 'usageLimits' == $errorDomain )
                                return gdCallWithCache( $userId, $token, $url, ++$cAttempts, $cacheVersion ) ;
            
                            else if( 'authError' == $errorReason ) {
                                invalidateUserTokens( $userId, 'gd' ) ;
                                d2w_putlog( LOG_WARNING, 'CALL:err invalidated u=' . $userId ) ;
                            }
                            else {
                                d2w_putlog( LOG_WARNING, 'CALL:err unhandled ' 
                                    . $errorDomain . ': ' . $errorReason ) ;
                            }
                        }
                    }
                    else {
                        // 401 if user cancelled from https://security.google.com/settings/security/permissions 
                        d2w_putlog( LOG_WARNING, 'CALL:err ' . $http_response_code ) ;
                    }
                }
            } 
        }
    }

    return array( $responseHeader, $responseBody ) ;
}

function gdNavigate( &$userId, &$token, &$path, &$siteName, &$bAuthenticated, $depth = 0, 
    $idParent = 'root', $cAttempts = 0, $cacheVersion = NULL, $service = NULL ) {

    global $requestMethod, $get_as, $httpHost, $httpOrigin, $https, $allowCreatePostDb ;
    
    $ok = FALSE ;

    /**
     * Perform exponential backoff if needed
     */
    if( $cAttempts > 5 ) {
        d2w_putlog( LOG_ERR, 'BACKOUT u=' . $userId . ', ' . implode( '/', $path ) ) ;
        return FALSE ;
    }

    /**
     * Handle special commands
     *
     * SECURITY: these run with the *site owner's* Drive token, which the
     * server holds for every hosted site. They must never be reachable
     * anonymously: previously `GET https://<site>.on.<domain>/@@delete/<fileId>`
     * deleted (or @@published / @@locked / @@duplicated) any file the owner can
     * touch, with no authentication of any kind.
     */
    $bPrivileged = in_array( $path[ $depth ], array( '@@cache', '@@download', '@@publish',
        '@@unlock', '@@lock', '@@dup', '@@delete', '@@site', '@@rename', '@@domain' ), TRUE ) ;

    if( $bPrivileged ) {
        // $service arrives as gdNavigate()'s last argument (from resolve()'s
        // $siteInfo->service): the session and the stored token are both keyed
        // by user AND service, so guessing it is not an option.
        $authMethod = $service ? owner_auth_method( $userId, $service ) : NULL ;

        if( NULL === $authMethod ) {
            d2w_putlog( LOG_WARNING, 'SECURE:priv unauth u=' . $userId
                . ' cmd=' . $path[ $depth ] ) ;
            header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 403 Forbidden', TRUE, 403 ) ;
            header( 'Cache-Control: no-store' ) ;
            header( 'Content-Type: application/json' ) ;
            print d2w_json( array( 'error' => 'ERR_AUTH_REQUIRED',
                'tip' => 'Sign in as the site owner first (owner session cookie or bearer token).' ) ) ;
            return TRUE ;
        }

        // Mutators must not be triggerable by a GET (image tags, prefetchers,
        // crawlers, <link rel=preload>, ...)
        $bMutator = !in_array( $path[ $depth ],
            array( '@@cache', '@@download', '@@site' ), TRUE ) ;

        if( $bMutator && 'POST' !== $requestMethod && 'PUT' !== $requestMethod ) {
            d2w_putlog( LOG_WARNING, 'SECURE:priv wrong method ' . $requestMethod ) ;
            header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 405 Method Not Allowed', TRUE, 405 ) ;
            header( 'Allow: POST' ) ;
            header( 'Content-Length: 0' ) ;
            return TRUE ;
        }

        // Cookie-authenticated mutations need a session-derived token: the
        // browser sends D2WSESS on its own, and a page on any *.on.<domain>
        // (i.e. any hosted site - including a hostile one) can post to the API
        // host. A bearer caller cannot be driven cross-site, so it is exempt.
        if( $bMutator && 'cookie' === $authMethod ) {
            $presented = isset( $_SERVER[ 'HTTP_X_D2W_CSRF' ] ) ? $_SERVER[ 'HTTP_X_D2W_CSRF' ]
                : ( isset( $_POST[ 'd2w_csrf' ] ) ? $_POST[ 'd2w_csrf' ] : NULL ) ;

            if( !d2w_csrf_ok( $presented,
                    isset( $_COOKIE[ 'D2WSESS' ] ) ? $_COOKIE[ 'D2WSESS' ] : NULL ) ) {
                d2w_putlog( LOG_WARNING, 'SECURE:priv missing csrf u=' . $userId
                    . ' cmd=' . $path[ $depth ] ) ;
                header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 403 Forbidden', TRUE, 403 ) ;
                header( 'Cache-Control: no-store' ) ;
                header( 'Content-Type: application/json' ) ;
                print d2w_json( array( 'error' => 'ERR_CSRF_REQUIRED',
                    'tip' => 'Send the X-D2W-CSRF header (window.D2W.csrf on the admin page).' ) ) ;
                return TRUE ;
            }
        }
    }

    if( 0 == $depth || 1 == $depth ) { // Custom domains will start at depth 1
        switch( $path[ $depth ] ) {
        case '@@cache':
            return gdMakeCache( $userId, $token, $idParent ) ;

        // Self-service site settings: the GUI reads @@site and posts @@rename /
        // @@domain. They act on the site, not on a file, so no file id follows.
        case '@@site':
            return site_send_info( $userId, $service, $siteName ) ;
        case '@@rename':
            return site_rename( $userId, $service, $siteName,
                isset( $_POST[ 'name' ] ) ? $_POST[ 'name' ]
                    : ( isset( $_GET[ 'name' ] ) ? $_GET[ 'name' ] : NULL ) ) ;
        case '@@domain':
            return site_bind_domain( $siteName,
                isset( $_POST[ 'domain' ] ) ? $_POST[ 'domain' ]
                    : ( isset( $_GET[ 'domain' ] ) ? $_GET[ 'domain' ] : NULL ) ) ;
        }
    }
    
    if( isset( $path[ $depth + 1 ] ) ) {
        if( !d2w_valid_file_id( $path[ $depth + 1 ] ) ) {
            d2w_putlog( LOG_WARNING, 'SECU:bad file id ' . substr( $path[ $depth + 1 ], 0, 64 ) ) ;
            header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 400 Bad Request', TRUE, 400 ) ;
            header( 'Content-Length: 0' ) ;
            return TRUE ;
        }

        switch( $path[ $depth ] ) {
        case '@@download':
            return gdSendFile( $userId, $token, $path[ $depth + 1 ] ) ;
        case '@@publish':
            return gdUnlock( $userId, $token, $path[ $depth + 1 ], TRUE ) ;
        case '@@unlock':
            return gdUnlock( $userId, $token, $path[ $depth + 1 ] ) ;
        case '@@lock':
            return gdLock( $userId, $token, $path[ $depth + 1 ] ) ;
        case '@@dup':
            return gdDuplicate( $userId, $token, $path[ $depth + 1 ] ) ;
        case '@@delete':
            return gdDelete( $userId, $token, $path[ $depth + 1 ] ) ;
        }
    }

    if( ( 0 == $depth || 1 == $depth ) && !$bAuthenticated ) {
        $bEnabled = false ;
        $url = 'https://www.googleapis.com/drive/v3/about?fields=*' ;
    
        [ $response_header, $response_body ]
            = gdCallWithCache( $userId, $token, $url, $cAttempts, $cacheVersion ) ;
    
        $matches = array() ;
        preg_match( '#HTTP/\d+.\d+ (\d+)#', $response_header[ 0 ], $matches ) ;
        $http_response_code = $matches[ 1 ] ;
    
        if( '200' == $http_response_code ) {
            $res = json_decode( $response_body ) ;

            $quota = $res->storageQuota ;
            if( !isset( $quota->limit ) || (int)($quota->limit) > 16106127360 ) {
                d2w_putlog( LOG_INFO, 'AUTHENTICATE quota.limit=' 
                    . ( isset( $quota->limit ) ? $quota->limit : 'NOT_SET' ) ) ;

                setSiteAuthenticate( $siteName, true ) ;
                $bEnabled = true ;
            }
        }
        else if( '5' == $http_response_code[ 0 ] ) {
            // Server error. Re-try
            d2w_putlog( LOG_WARNING, 'RETRY on ' . $http_response_code ) ;
            return gdNavigate( $userId, $token, $path, $siteName, $bAuthenticated, 
                $depth, $idParent, ++$cAttempts, $cacheVersion, $service ) ;
        }

        if( !$bEnabled )
            return sendNeedAuthenticate( $siteName ) ;
    }

    // Get the file for this path level
    $pathEsc = str_replace( '\'', '\\\'', $path[ $depth ] ) ;
    $q = "'$idParent' in parents and name='$pathEsc' and not trashed" ;
    $final = ( $depth == count( $path ) - 1 ) ;
    if( !$final )
        $q .= " and mimeType='application/vnd.google-apps.folder'" ;

    $url = 'https://www.googleapis.com/drive/v3/files?'
        . 'corpus=user&q=' . urlencode( $q )
        . '&fields=files(id%2CmimeType%2Cmd5Checksum%2CmodifiedTime%2C'
        . 'webContentLink%2Cpermissions%2CpermissionIds%2Cshared%2Csize%2C'
        . 'headRevisionId%2CisAppAuthorized)' ;

    [ $response_header, $response_body ]
        = gdCallWithCache( $userId, $token, $url, $cAttempts, $cacheVersion ) ;
    $res = json_decode( $response_body ) ;

    $matches = array() ;
    preg_match( '#HTTP/\d+.\d+ (\d+)#', $response_header[ 0 ], $matches ) ;
    $http_response_code = $matches[ 1 ] ;

    if( '200' != $http_response_code ) {
        // Error handling
        if( '5' == $http_response_code[ 0 ] ) {
            // Server error. Re-try
            d2w_putlog( LOG_WARNING, 'RETRY on ' . $http_response_code ) ;
            return gdNavigate( $userId, $token, $path, $siteName, $bAuthenticated, 
                $depth, $idParent, ++$cAttempts, $cacheVersion, $service ) ;
        }
    }
    else {
        if( 'POST' == $requestMethod && $final ) {
            $idPostDb = NULL ;

            // Find target DB to POST
            for( $i = 0; !$idPostDb && $i < count( $res->files ); $i++ ){
                $file = $res->files[ $i ] ;
            
                if( 'application/vnd.google-apps.spreadsheet' == $file->mimeType 
                    && isset( $file->isAppAuthorized ) && $file->isAppAuthorized ) {

                    $idPostDb = $file->id ;
                }
            }

            // If none found, create it (opt-in via $allowCreatePostDb; this used
            // to be gated on a hardcoded personal MongoDB user id)
            if( !$idPostDb && owner_is_authorized( $userId, $service )
                && ( TRUE === $allowCreatePostDb
                    || ( is_string( $allowCreatePostDb )
                        && d2w_equals( $allowCreatePostDb, (string)$userId ) ) ) ) {

                if( 'http' . ( $https ? 's' : '' ) . '://' . $httpHost == $httpOrigin ) {
                    d2w_putlog( LOG_INFO, 'POST creating DB' ) ;
                    $idPostDb = gdCreatePostDb( $token, $idParent, $path[ $depth ] ) ;
                }
                else
                    d2w_putlog( LOG_INFO, 'POST err CORS' ) ;
            }

            // DB is found earlier when processing POST
            if( $idPostDb ) {
                d2w_putlog( LOG_INFO, 'POST appending to DB' ) ;

                if( gdPostContent( $token, $idPostDb ) ) {
                    gdInvalidateCache( $userId ) ;
                    $ok = TRUE ;
                }
                else
                    d2w_putlog( LOG_INFO, 'POST failed' ) ;
            }
        }

        if( count( $res->files ) > 0 ) {
            $bServed = FALSE ;
            // Google Drive allows multiple files with the same name
    
            if( !$final ) {
                for( $i = 0; $i < count( $res->files ) && !$bServed; $i++ ) {
                    $file = $res->files[ $i ] ;
    
                    if( 'application/vnd.google-apps.folder' == $file->mimeType ) {
                        $ok = gdNavigate( $userId, $token, $path, $siteName, $bAuthenticated, 
                            $depth + 1, $file->id, 0, $cacheVersion, $service ) ;
    
                        $bServed = TRUE ;
                    }
                }
            }
            else {
                // Serve file
                for( $i = 0; $i < count( $res->files ) && !$bServed; $i++ ){
                    $file = $res->files[ $i ] ;
    
                    if( 'application/vnd.google-apps.folder' == $file->mimeType ) {
                        $bServed = forwardDirectorySlash() ;
                    }
                    else if( 'db' == $get_as ) {
                        if( 'application/vnd.google-apps.spreadsheet' == $file->mimeType ) {
                            d2w_putlog( LOG_INFO, 'GET as js' ) ;
    
                            $bServed = sendContent( 'gd',
                                NULL, 
                                'application/vnd.google-apps.spreadsheet',
                                NULL, 
                                isset( $file->headRevisionId ) ? $file->headRevisionId : NULL,
                                isset( $file->modifiedTime ) 
                                    ? DateTime::createFromFormat( 'Y-m-d\TH:i:s.u\Z', 
                                        $file->modifiedTime ) : NULL,
                                $userId, $file->id ) ;
                        }
                    }
                    else if( isset( $file->size ) // Not set for Google Docs files
                        && isset( $file->webContentLink ) ) {
    
                        $bPublic = FALSE ;
                        if( isset( $file->permissionIds ) ) {
                            if( in_array( 'anyone', $file->permissionIds ) 
                                || in_array( 'anyoneWithLink', $file->permissionIds ) )
                                $bPublic = TRUE ;
                        }
                        else if( isset( $file->permissions ) ) {
                            for( $iPerm = 0; !$bPublic && $iPerm < count( $file->permissions ); $iPerm++ )
                                if( 'anyone' == $file->permissions[ $iPerm ]->type )
                                    $bPublic = TRUE ;
                        }
                        else
                            $bPublic = ( isset( $file->shared ) && $file->shared ) ;
    
                        if( $bPublic ) {
                            $urlContent = preg_replace( '/&export=download$/', '', $file->webContentLink ) ;
    
                            $bServed = sendContent( 'gd',
                                $urlContent,
                                isset( $file->mimeType ) ? $file->mimeType : NULL,
                                isset( $file->size ) ? $file->size : NULL,
                                isset( $file->headRevisionId ) ? $file->headRevisionId : NULL,
                                isset( $file->modifiedTime ) 
                                    ? DateTime::createFromFormat( 'Y-m-d\TH:i:s.u\Z', 
                                        $file->modifiedTime ) : NULL,
                                $userId, $file->id ) ;
                        }
                    }
                }

                if( !$bServed && $ok ) {
                    header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 200 OK' ) ;
                    header( 'Content-Type: application/json' ) ;
                    header( 'Cache-Control: no-cache' ) ;
                    $result = '{"success":true}' ;
                    header( 'Content-Length: ' . strlen( $result ) ) ;
                    print $result ;

                    $bServed = TRUE ;
                }

                if( !$bServed ) {
                    d2w_putlog( LOG_WARNING, 'RESP 403 not accessible' ) ;
    
                    header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 403 Forbidden' ) ;
                    print d2w_inline_page( '403.html' ) ;
                }
    
                $ok = TRUE ;
            }
        }
    } 

    return $ok ;
}

function odNavigate( &$userId, &$token, &$path, &$siteName, &$bAuthenticated ) {

    global $requestMethod ;

    $bServed = FALSE ;



    if( !$bAuthenticated )
        return sendNeedAuthenticate( $siteName ) ;

    $fields = 'id,eTag,cTag,lastModifiedDateTime,file,folder,size,shared,' 
        . '@microsoft.graph.downloadUrl' ;
    $path = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) ) ;
    $url = 'https://graph.microsoft.com/v1.0/me/drive/root' . ( $path ? ':' . $path : '' ) 
        . '?select=' . $fields ; // Somehow '$'select does not return @microsoft.graph.downloadUrl

    $opts = array( 'http' => array (
        'method' => 'GET',
        'header' => 'Authorization: bearer ' . $token[ 'token' ]
    ) ) ;
    $context = stream_context_create( $opts ) ;
    $result = @file_get_contents( $url, FALSE, $context ) ;

    if( !$result ) {
        d2w_putlog( LOG_INFO, 'CPAT fallback to Live API for content' ) ;
        $fields = 'id,eTag,cTag,lastModifiedDateTime,file,folder,size,shared,' 
            . '@content.downloadUrl' ;
        $url = 'https://api.onedrive.com/v1.0/drive/root' . ( $path ? ':' . $path : '' ) 
            . '?select=' . $fields ;
        $result = @file_get_contents( $url, FALSE, $context ) ;
    }

    if( !$result ) {
        // $http_response_header only exists if the request got a response; a
        // DNS or connect failure leaves it undefined, which used to throw here.
        $http_response_code = NULL ;
        if( isset( $http_response_header[ 0 ] ) ) {
            $matches = array() ;
            if( preg_match( '#HTTP/\d+.\d+ (\d+)#', $http_response_header[ 0 ], $matches ) )
                $http_response_code = $matches[ 1 ] ;
        }

        d2w_putlog( LOG_WARNING, 'WARN odNavigate '
            . ( NULL === $http_response_code ? 'no-response' : $http_response_code ) ) ;
    }
    else {
        $res = json_decode( $result ) ;

        if( isset( $res->{ '@content.downloadUrl' } ) )
            $res->{ '@microsoft.graph.downloadUrl' } 
                = $res->{ '@content.downloadUrl' } ;

        else {
            if( isset( $res->{ '@microsoft.graph.downloadUrl' } ) 
                && FALSE !== strpos( $res->{ '@microsoft.graph.downloadUrl' }, 
                    '.sharepoint.com' ) )
                d2w_putlog( LOG_INFO, 'CPAT on ODB' ) ;
            else
                d2w_putlog( LOG_INFO, 'CPAT on MSGraph' ) ;
        }
    
        if( !isset( $res->shared ) ) {
            d2w_putlog( LOG_WARNING, 'RESP 403 not shared' ) ;
            header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 403 Forbidden' ) ;
            print d2w_inline_page( '403.html' ) ;
            $bServed = TRUE ;
        }
        else {
            if( isset( $res->folder ) ) {
                forwardDirectorySlash() ;
                $bServed = TRUE ;
            }
            else if( isset( $res->{ '@microsoft.graph.downloadUrl' } ) ) {

                $bServed = sendContent( 'od',
                    $res->{ '@microsoft.graph.downloadUrl' },
                    ( isset( $res->file ) && isset( $res->file->mimeType ) )
                        ? $res->file->mimeType : NULL,
                    isset( $res->size ) ? $res->size : NULL,
                    isset( $res->cTag ) ? $res->cTag : NULL,
                    isset( $res->lastModifiedDateTime )  
                        ? DateTime::createFromFormat( 'Y-m-d\TH:i:s.u\Z', 
                            $res->lastModifiedDateTime ) : NULL,
                    $userId, $res->id ) ;
            }
            else
                d2w_putlog( LOG_DEBUG, 'WARN odNavigate no @microsoft.graph.downloadUrl' ) ;
        }
    }

    return $bServed ;
}

function sendNeedAuthenticate( $site ){
    header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 403 Forbidden', TRUE, 403 ) ;

    $page403auth = d2w_inline_page( '403-authenticate.html' ) ;
    $page403auth = str_replace( '%%DRV_SITE%%', $site, $page403auth ) ;

    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
    header( 'Pragma: no-cache' ) ;
    header( 'Content-Length: ' . strlen( $page403auth ) ) ;
    print $page403auth ;
    
    d2w_putlog( LOG_WARNING, 'RESP 403 needs authentication' ) ;

    return TRUE ;
}

$_mongo = FALSE ; // Driver instances
function openDb() {
    global $_mongo ;
    if( $_mongo )
        return $_mongo ;

    global $mongoRemoteConnections, $mongoLocalConnection ;

    // PHP driver does not close connections:
    //   https://github.com/mongodb/mongo-php-driver/issues/393
    try {
        // The shipped version disabled certificate verification for every
        // connection - including the one that carries OAuth refresh tokens -
        // and forced 'ssl' => TRUE even for the local Unix socket, where TLS
        // is meaningless. Verification is now on unless the operator opts out.
        global $mongoTlsLocal, $mongoTlsCAFile, $mongoTlsAllowSelfSigned ;

        $ctx = array( 'ssl' => array(
            'allow_self_signed' => !empty( $mongoTlsAllowSelfSigned ) ,
            'verify_peer'       => empty( $mongoTlsAllowSelfSigned ) ,
            'verify_peer_name'  => empty( $mongoTlsAllowSelfSigned ) ,
            'verify_expiry'     => FALSE,
        ) ) ;
        if( !empty( $mongoTlsCAFile ) )
            $ctx[ 'ssl' ][ 'cafile' ] = $mongoTlsCAFile ;

        $bLocalTls = !empty( $mongoTlsLocal ) ;
        $mongoLocal = new MongoDB\Driver\Manager(
            $mongoLocalConnection,
            $bLocalTls ? array( 'tls' => TRUE ) : array(),
            $bLocalTls ? array( 'context' => stream_context_create( $ctx ) ) : array() ) ;

        // Remote (Atlas / replica) URIs: the driver verifies certificates on its
        // own, so only build a context when the operator asked for a CA file or
        // explicitly allowed self-signed.
        $bRelax = !empty( $mongoTlsAllowSelfSigned ) || !empty( $mongoTlsCAFile ) ;
        $remoteOpts = $bRelax ? array( 'tls' => TRUE ) : array() ;
        $remoteCtx = $bRelax ? array( 'context' => stream_context_create( $ctx ) ) : array() ;

        $mongoRemotes = [] ;
        for( $i = 0; $i < count( $mongoRemoteConnections ); $i++ )
            $mongoRemotes[] = new MongoDB\Driver\Manager( $mongoRemoteConnections[ $i ],
                $remoteOpts, $remoteCtx ) ;

        $mongo = [
            'remoteStores' => $mongoRemotes, 'localStore' => $mongoLocal, // Store data at multiple locations
            'main' => $mongoLocal, // Main data source. Can be either $mongoLocal or $mongoRemote
            'cache' => $mongoLocal, // Cache for quick and temporary data. Should be $mongoLocal
        ] ;
    }
    catch( \Throwable $err ) {
        // The message can contain the DSN (with its password): redact before
        // logging, and answer 503 instead of dying with a blank page.
        d2w_putlog( LOG_CRIT, 'DB Mongo Manager failed: '
            . preg_replace( '#://[^@/]*@#', '://***@', $err->getMessage() ) ) ;

        if( !headers_sent() ) {
            http_response_code( 503 ) ;
            header( 'Retry-After: 30' ) ;
            header( 'Content-Type: text/plain; charset=UTF-8' ) ;
            print "Storage backend unavailable\n" ;
        }
        exit ;
    }

    $_mongo = $mongo ;

    return $mongo ;
}

function setUserInfo( $userInfo ) {
    $ok = FALSE ;

    $db = openDb() ;
    $bulk = new MongoDB\Driver\BulkWrite() ;
    $bulk->update( [ 'username' => strtolower( $userInfo[ 'userName' ] ) ], 
        [ '$set' => [
            'displayName' => $userInfo[ 'displayName' ]
        ] ], 
        [ 'upsert' => TRUE ] ) ;

    try {
        $writeResult = $db['localStore']->executeBulkWrite( 'wwwdrv.users', $bulk ) ;
        $ok = ( NULL !== $writeResult ) ;

        for( $i = 0; $i < count( $db['remoteStores'] ); $i++ ) {
            $bulk = new MongoDB\Driver\BulkWrite() ;
            $bulk->update( [ 'username' => strtolower( $userInfo[ 'userName' ] ) ], 
                [ '$set' => [
                    'displayName' => $userInfo[ 'displayName' ]
                ] ], 
                [ 'upsert' => TRUE ] ) ;

            $db['remoteStores'][ $i ]->executeBulkWrite( 'wwwdrv.users', $bulk ) ;
        }
    }
    catch( MongoDB\Driver\Exception\Exception $e ) {
        d2w_putlog( LOG_CRIT, 'DB exception: ' . $e->getMessage() ) ;
    }
    if( !$ok )
        d2w_putlog( LOG_CRIT, 'DB set user info failed for ' . $userInfo[ 'userName' ] ) ;

    return $ok ;
}

function getUserIdFromName( $username ) {
    $userId = FALSE ;

    $db = openDb() ;
    $filter = [ 'username' => strtolower( $username ) ] ;
    $options = [
        'projection' => [ '_id'=> 1 ],
        'limit'      => 1
    ] ;
    $query = new MongoDB\Driver\Query( $filter, $options ) ;
    $recs = $db['main']->executeQuery( 'wwwdrv.users', $query )->toArray() ;

    if( count( $recs ) > 0 ) {
        $rec = $recs[ 0 ] ;
        $userId = $rec->_id->__toString() ;
    }

    return $userId ;
}

function getUserNameFromId( $userId, $service ) {
    $username = FALSE ;

    if( $userId && $service ) {
        $db = openDb() ;
        $filter = [ 
            '_id'  => new MongoDB\BSON\ObjectID( $userId )
        ] ;
        $options = [
            'projection' => [ 'username' => 1 ],
            'limit'      => 1
        ] ;
        $query = new MongoDB\Driver\Query( $filter, $options ) ;
        $recs = $db['main']->executeQuery( 'wwwdrv.users', $query )->toArray() ;
        if( count( $recs ) > 0 ) {
            $rec = $recs[ 0 ] ;
            $username = $rec->username ;
        }
    }

    return $username ;
}

function setRefreshToken( $userId, $service, $refreshToken ) {
    $ok = FALSE ;

    $db = openDb() ;
    $bulk = new MongoDB\Driver\BulkWrite() ;
    $bulk->update( [
        'userId'    => new MongoDB\BSON\ObjectID( $userId ),
        'service'   => $service
    ], [ '$set' => [
        'token' => $refreshToken
    ] ], [
        'upsert'    => TRUE
    ] ) ;

    try {
        $writeResult = $db['localStore']->executeBulkWrite( 'wwwdrv.refreshTokens', $bulk ) ;
        $ok = ( NULL !== $writeResult ) ;

        for( $i = 0; $i < count( $db['remoteStores'] ); $i++ ) {
            $bulk = new MongoDB\Driver\BulkWrite() ;
            $bulk->update( [
                'userId'    => new MongoDB\BSON\ObjectID( $userId ),
                'service'   => $service
            ], [ '$set' => [
                'token' => $refreshToken
            ] ], [
                'upsert'    => TRUE
            ] ) ;
            $db['remoteStores'][ $i ]->executeBulkWrite( 'wwwdrv.refreshTokens', $bulk ) ;
        }
    }
    catch( MongoDB\Driver\Exception\Exception $e ) {
        d2w_putlog( LOG_CRIT, 'DB exception: ' . $e->getMessage() ) ;
    }
    if( !$ok )
        d2w_putlog( LOG_CRIT, 'DB set refresh token failed for ' . $userId ) ;

    return $ok ;
}

function setAccessToken( $userId, $service, $token, $type, $secExpiresBy ) {
    $ok = FALSE ;

    $db = openDb() ;
    $bulk = new MongoDB\Driver\BulkWrite() ;
    $bulk->update( [
        'userId'      => new MongoDB\BSON\ObjectID( $userId ),
        'service'     => $service
    ], [ '$set' => [
            'token'   => $token,
            'type'    => $type,
            'expires' => new MongoDB\BSON\UTCDateTime( $secExpiresBy * 1000 )
    ] ], [
        'upsert'      => TRUE
    ] ) ;

    try {
        $db['cache']->executeBulkWrite( 'wwwdrv.accessTokens', $bulk ) ;
        $ok = TRUE ;
    }
    catch( MongoDB\Driver\Exception\Exception $e ) {
        d2w_putlog( LOG_CRIT, 'DB set access token failed for ' . $userId ) ;
    }

    return $ok ;
}

function invalidateUserTokens( $userId, $service ) {
    $ok = FALSE ;

    $db = openDb() ;
    $bulk = new MongoDB\Driver\BulkWrite() ;
    $bulk->delete( [
        'userId'  => new MongoDB\BSON\ObjectID( $userId ),
        'service' => $service
    ] ) ;

    try {
        $db['cache']->executeBulkWrite( 'wwwdrv.accessTokens', $bulk ) ;
        d2w_putlog( LOG_INFO, 'AUTH:inva u=' . $userId ) ;
        $ok = TRUE ;
    }
    catch( MongoDB\Driver\Exception\Exception $e ) {
        d2w_putlog( LOG_CRIT, 'DB invalidate access token failed for ' . $userId ) ;
    }

    $bulk = new MongoDB\Driver\BulkWrite() ;
    $bulk->delete( [
        'userId'  => new MongoDB\BSON\ObjectID( $userId ),
        'service' => $service
    ] ) ;

    try {
        d2w_putlog( LOG_INFO, 'AUTH:invr u=' . $userId ) ;
        $db['localStore']->executeBulkWrite( 'wwwdrv.refreshTokens', $bulk ) ;
        $ok = TRUE ;

        for( $i = 0; $i < count( $db['remoteStores'] ); $i++ ) {
            $bulk = new MongoDB\Driver\BulkWrite() ;
            $bulk->delete( [
                'userId'  => new MongoDB\BSON\ObjectID( $userId ),
                'service' => $service
            ] ) ;
            $db['remoteStores'][ $i ]->executeBulkWrite( 'wwwdrv.refreshTokens', $bulk ) ;
        }
    }
    catch( MongoDB\Driver\Exception\Exception $e ) {
        d2w_putlog( LOG_CRIT, 'DB ' . ( $ok ? 'backup' : 'set' ) 
            . ' invalidate refresh token failed for ' . $userId . ': ' . $e->getMessage() ) ;
    }

    return $ok ;
}

function getRefreshToken( $userId, $service ) {
    $result = FALSE ;

   if( $userId ) {
        $db = openDb() ;
        $filter = [ 
            'userId'  => new MongoDB\BSON\ObjectID( $userId ),
            'service' => $service 
        ] ;
        $options = [
            'projection' => [ 'token' => 1 ],
            'limit'      => 1
        ] ;
        $query = new MongoDB\Driver\Query( $filter, $options ) ;
        $recs = $db['main']->executeQuery( 'wwwdrv.refreshTokens', $query )->toArray() ;
        if( count( $recs ) > 0 ) {
            $rec = $recs[ 0 ] ;
            $result = $rec->token ;
        }
    }

    return $result ;
}

/**
* getAccessToken 
* Returns access token with expiration (as DateTime)
 */
function getAccessToken( $userId, $service ) {
    $result = FALSE ;

    if( $userId ) {
        // Reuse tokens valid for more than 5 minutes from now
        $dtLastsUntil = new MongoDB\BSON\UTCDateTime( ( time() + 300 ) * 1000 ) ;

        $db = openDb() ;
        $filter = [ 
            'userId'  => new MongoDB\BSON\ObjectID( $userId ),
            'service' => $service,
            'expires' => [ '$gte' => $dtLastsUntil ]
        ] ;
        $options = [
            'projection' => [
                'token'   => 1,
                'expires' => 1
            ],
            'limit' => 1
        ] ;
        $query = new MongoDB\Driver\Query( $filter, $options ) ;
        $recs = $db['cache']->executeQuery( 'wwwdrv.accessTokens', $query )->toArray() ;
        if( $recs && isset( $recs[ 0 ] ) )
            $result = [
                'token' => $recs[ 0 ]->token,
                'expires' => $recs[ 0 ]->expires->toDateTime()
            ] ;
    }
    return $result ;
}

function sendHeaderArray( $header, $values ) {
    foreach( $values as $value )
        header( $header . ': ' . $value, FALSE ) ; // Append instead of replace

    if( count( $values ) > 0 )
        d2w_putlog( LOG_NOTICE, 'HEADER ' . $header . ': ' . implode( ', ', $values ) ) ;
}

function gdGetPostDb( $token, $fileId ) {
    $ok = FALSE ;

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . $fileId . '/values/A%3AZ' ;

    $opts = array( 'http' => array (
        'method' => 'GET',
        'header' => 'Authorization: Bearer ' . $token[ 'token' ]
    ) ) ;
    $context = stream_context_create( $opts ) ;
    $result = @file_get_contents( $url, FALSE, $context ) ;

    if( $result ) {
        $res = json_decode( $result ) ;
        if( $res && isset( $res->values ) 
            && is_array( $res->values ) 
            && count( $res->values ) >= 2 ) {

            $dbSpec = [ 'db' => [] ] ;
            $dbSpec[ 'db' ][ 'schema' ] = $res->values[ 0 ] ;
            array_shift( $res->values ) ; // Remove 1st entry
            $dbSpec[ 'db' ][ 'rows' ] = $res->values ;

            $out = json_encode( $dbSpec ) ;
            header( 'Cache-Control: no-cache' ) ;
            header( 'Content-Type: application/json' ) ;
            header( 'Content-Length: ' . strlen( $out ) ) ;
            print $out ;

            $ok = TRUE ;
        }
    }

    return $ok ;
}
$url = 'https://www.googleapis.com/drive/v3/files/'
. $idFile . '/permissions/anyoneWithLink' ;

$headers = array( 'Authorization: Bearer ' . $token[ 'token' ] ) ;

$ch = curl_init( $url ) ;
curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' ) ;
curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ) ;
curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 ) ;

$result = curl_exec( $ch ) ;
$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE ) ;
curl_close( $ch ) ;

function gdCreatePostDb( $token, $idParent, $fileName ) {
    $idSpreadsheet = NULL ;

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' ;
    $data = array(
        'properties' => array(
            'title' => $fileName 
        ) ) ;
    $options = array( 'http' => array (
        'header'  => "Content-type: application/json\r\n"
            . 'Authorization: Bearer ' . $token[ 'token' ],
        'method'  => 'POST',
        'content' => json_encode( $data ),
        'ignore_errors' => TRUE
    ) ) ;
    
    $context = stream_context_create( $options ) ;
    $result = @file_get_contents( $url, FALSE, $context ) ;

    if( $result ) {
        $res = json_decode( $result ) ;
        if( isset( $res->spreadsheetId ) ) {

            $url = 'https://www.googleapis.com/drive/v3/files/' 
                . $res->spreadsheetId . '?addParents=' . $idParent 
                . '&removeParents=root';
            $options = array( 'http' => array (
                'header'  => 'Authorization: Bearer ' . $token[ 'token' ],
                'method'  => 'PATCH'
            ) ) ;
            
            $context = stream_context_create( $options ) ;
            $ok = @file_get_contents( $url, FALSE, $context ) ;

            // The following are ok to fail (permission may not exist)
            $url = 'https://www.googleapis.com/drive/v3/files/' 
                . $res->spreadsheetId . '/permissions/anyoneWithLink' ;
            $options = array( 'http' => array (
                'header'  => 'Authorization: Bearer ' . $token[ 'token' ],
                'method'  => 'DELETE'
            ) ) ;
            $context = stream_context_create( $options ) ;
            @file_get_contents( $url, FALSE, $context ) ;

            $url = 'https://www.googleapis.com/drive/v3/files/' 
                . $res->spreadsheetId . '/permissions/anyone' ;
            @file_get_contents( $url, FALSE, $context ) ;

            if( $ok )
                $idSpreadsheet = $res->spreadsheetId ;
            else
                d2w_putlog( LOG_INFO, 'POST FAILED initialize DB' ) ;
        }
        else
            d2w_putlog( LOG_INFO, 'POST FAILED create DB' ) ;
    }

    return $idSpreadsheet ;
}

function gdPostContent( $token, $fileId ) {
    $ok = FALSE ;

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . $fileId 
        . '/values/A1?valueInputOption=RAW' ;

    d2w_putlog( LOG_INFO, 'POST received Content-Type: ' 
        . $_SERVER[ 'HTTP_CONTENT_TYPE' ] ) ;

    $data = array( 'values' => array( array_merge( 
        array( '_id__00_00_' ), array_keys( $_POST ) ) ) ) ;

    $options = array( 'http' => array(
        'header'  => "Content-Type: application/json\r\n"
            . 'Authorization: Bearer ' . $token[ 'token' ],
        'method'  => 'PUT',
        'content' => json_encode( $data ),
        'ignore_errors' => TRUE
    ) ) ;
    $context = stream_context_create( $options ) ;
    $result = @file_get_contents( $url, FALSE, $context ) ;

    if( $result ) {
        $res = json_decode( $result ) ;
        if( !isset( $res->error ) ) {
            $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . $fileId 
                . '/values/A2:append?valueInputOption=RAW' ;
            $data = array( 'values' => array( array_merge( 
                array( time() . sprintf( '-%06d', mt_rand( 0, 999999 ) ) ), 
                array_values( $_POST ) ) ) ) ;
        
            $options = array( 'http' => array(
                'header'  => "Content-Type: application/json\r\n"
                    . 'Authorization: Bearer ' . $token[ 'token' ],
                'method'  => 'POST',
                'content' => json_encode( $data ),
                'ignore_errors' => TRUE
            ) ) ;
            $context = stream_context_create( $options ) ;
            $result = @file_get_contents( $url, FALSE, $context ) ;
        
            if( $result ) {
                $res = json_decode( $result ) ;
                $ok = !isset( $res->error ) ;
                if( $ok && isset( $res->updates ) && isset( $res->updates->updatedRange ) )
                    d2w_putlog( LOG_INFO, 'POST updated ' . $res->updates->updatedRange ) ;
                else if( isset( $res->error->message ) )
                    d2w_putlog( LOG_INFO, 'POST err ' . $res->error->message ) ;
            }
            else
                d2w_putlog( LOG_INFO, 'POST append failed' ) ;
        }
        else
            d2w_putlog( LOG_INFO, 'POST set schema failed' ) ;
    }

    return $ok ;
}

/**
 * Takes an URL, which may possible point to an HTML page that prompts for human intervention,
 * and resolve until its final link to actual content
 */
function gdUnscreenContent( $urlContent, $fileId ) {

    global $gdApiKey ;
    
    $urlScreened = FALSE ;
    $cookieNID = FALSE ;

    global $usStart ;

    $resolveConfirms = TRUE ;
    $urlNext = $urlContent ;
    $requestHeaders = array() ;
    $ckfile = tempnam( sys_get_temp_dir(), 'gucc' ) ;
    if( FALSE === $ckfile )
        return FALSE ;

    $maxTries = 5 ;
    $trial = 0 ;

    while( $resolveConfirms && $trial <= $maxTries ) {

        d2w_putlog( LOG_INFO, 'SCAN unscreening ' . $urlNext 
            . ' @ ' . number_format( microtime( TRUE ) - $usStart, 3 )
            . ' headers: ' . str_replace( array("\n", "\r"), '', 
            print_r( $requestHeaders, TRUE ) ) ) ;

        $trial++ ;
        $cx = curl_init( $urlNext ) ;

        $urlBase = NULL ;
        if( preg_match( '/(^[^:]+:\/\/[^:\/\s]+)\//', $urlNext, $urlparts ) )
            $urlBase = $urlparts[ 1 ] ;

        /*
         * Get HEAD on resource to see if it's a 302 or a 200
         */
        curl_setopt( $cx, CURLOPT_HEADER, TRUE ) ;
        curl_setopt( $cx, CURLOPT_HTTPHEADER, $requestHeaders ) ;
        curl_setopt( $cx, CURLOPT_NOBODY, TRUE ) ;
        curl_setopt( $cx, CURLOPT_COOKIEFILE, $ckfile ) ;
        curl_setopt( $cx, CURLOPT_COOKIEJAR, $ckfile ) ;
        curl_setopt( $cx, CURLOPT_RETURNTRANSFER, TRUE ) ;
        curl_setopt( $cx, CURLOPT_TIMEOUT, 60 ) ;
        curl_setopt( $cx, CURLOPT_FOLLOWLOCATION, FALSE ) ;

        /*$fLog = d2w_openlog( LOG_INFO ) ;
        curl_setopt( $cx, CURLOPT_STDERR, $fLog ) ;
        curl_setopt( $cx, CURLOPT_VERBOSE, TRUE ) ;
        fclose( $fLog ) ;*/

        $responseHeader = curl_exec( $cx ) ;
        if( FALSE === $responseHeader ) {
            d2w_putlog( LOG_WARNING, 'SCAN unscreening FAILED' ) ;
        }
        else {
            $httpCode = curl_getinfo( $cx, CURLINFO_HTTP_CODE ) ;
            $urlEffective = curl_getinfo( $cx, CURLINFO_EFFECTIVE_URL ) ;
            $httpContentType = curl_getinfo( $cx, CURLINFO_CONTENT_TYPE ) ;
            $httpLocation = curl_getinfo( $cx, CURLINFO_REDIRECT_URL ) ;
    
            d2w_putlog( LOG_DEBUG, 'SCAN ' . $httpCode 
                . ' @ ' . number_format( microtime( TRUE ) - $usStart, 3 )
                . ' effectiveURL: ' . $urlEffective
                . ' type: ' . $httpContentType
                . ' to: ' . $httpLocation ) ;

            if( 200 == $httpCode ) {

                if( FALSE === strpos( $httpContentType, 'text/html' ) ) {
                    // Not a forward nor content
                    $urlScreened = str_replace( '?e=download', '', $urlNext ) ;
                    d2w_putlog( LOG_INFO, 'SCAN result ' . $urlScreened
                        . ' @ ' . number_format( microtime( TRUE ) - $usStart, 3 ) ) ;
    
                    $resolveConfirms = FALSE ;
                }
                else {
                    $urlScreened = 'https://www.googleapis.com/drive/v3/files/' . $fileId 
                        . '?alt=media&key=' . $gdApiKey ;

                    $resolveConfirms = FALSE ;
                }
            }
            else {
                // Not error page: forward to user
                $urlScreened = $urlEffective ;

                d2w_putlog( LOG_INFO, 'SCAN result ' . $httpCode . ' ' . $urlScreened
                . ' @ ' . number_format( microtime( TRUE ) - $usStart, 3 ) ) ;

                $resolveConfirms = FALSE ;
            }
        }

        curl_close( $cx ) ;
    }
    if( $ckfile && file_exists( $ckfile ) )
        unlink( $ckfile ) ;

    return $urlScreened ;
}

// http://php.net/manual/en/function.mb-detect-encoding.php
//
// detect_utf_encoding(), acceptQuality() and d2w_parse_accept() now live in
// drvtw_private/drvtw_util.php so they can be unit tested (tests/run-tests.php).
// The versions removed here had two bugs:
//   * $first4 = substr( $text, 0, 3 ) made the UTF-32 BOMs unreachable, so
//     UTF-32 content was converted from the wrong source encoding
//   * the Accept parser required "q=0.9", so "q=0" read as quality 1.0
//

function handleCopyrightBlacklist( $httpHost, $requestUri ) {

    global $blacklist_copyright ;

    $bHandled = FALSE ;

    for( $iRule = 0; $iRule < count( $blacklist_copyright ); $iRule++ ) {
        if( $httpHost === $blacklist_copyright[ $iRule ][ 0 ] 
            && explode( '?', $requestUri, 2 )[ 0 ]
                === $blacklist_copyright[ $iRule ][ 1 ] ) {

            $holder = $blacklist_copyright[ $iRule ][ 2 ] ;

            $page = d2w_inline_page( '403-complaint.html' ) ;
            $page = str_replace( '%%DRV_HOLDER%%', $holder, $page ) ;

            d2w_putlog( LOG_WARNING, 'RESP 403 copyright withhold' ) ;
            header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 403 Forbidden' ) ;
            header( 'Cache-Control: public, s-maxage=604800, max-age=604800' ) ;
            header( 'Content-type: text/html' ) ;
            header( 'Content-length: ' . strlen( $page ) ) ;
            print( $page ) ;

            $bHandled = TRUE ;
            break ;
        }
    }

    return $bHandled ;
}

function handleNotModified( $dtLastModified, $eTag ) {
    $bHandled = FALSE ;

    // Adapted from https://stackoverflow.com/questions/2021882
    $ims = isset( $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] ) ? $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] : NULL ;
    $inm = isset( $_SERVER[ 'HTTP_IF_NONE_MATCH' ] ) ? $_SERVER[ 'HTTP_IF_NONE_MATCH' ] : NULL ;
    $dtIms = $ims ? DateTime::createFromFormat( 'D, d M Y H:i:s \G\M\T', $ims ) : NULL ;
    if( $dtLastModified )
        $dtLastModified->setTimestamp( $dtLastModified->getTimestamp() ) ; // Truncate seconds

    $isModified = !( ( $ims && $dtIms && $dtLastModified && $dtIms >= $dtLastModified ) 
        || ( $inm && $eTag && $inm == $eTag ) ) ;

    if( !$isModified ) {
        d2w_putlog( LOG_INFO, 'RESP 304 not modified' ) ;
        header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 304 Not Modified' ) ;
        $bHandled = TRUE ;
    }

    return $bHandled ;
}

function handleAccept( &$mimeType ) {
    $bHandled = FALSE ;

    // Not fatal: curl and API clients often send no Accept header at all
    $httpAccept = isset( $_SERVER[ 'HTTP_ACCEPT' ] ) ? $_SERVER[ 'HTTP_ACCEPT' ] : '' ;

    $mimeConversions = [
        [ 'text/javascript', 'application/javascript', 'application/javascript' ],
        [ 'text/xml', 'image/', 'image/svg+xml' ],
        [ 'text/plain', TRUE, 'text/plain; charset=UTF-8' ],
        [ 'application/octet-stream', 'application/manifest', 'application/manifest+json' ],        
    ] ;
    foreach( $mimeConversions as $mimeConversion ) {
        if( $mimeType === $mimeConversion[ 0 ] 
            && ( TRUE === $mimeConversion[ 1 ] 
                || FALSE !== strpos( $httpAccept, $mimeConversion[ 1 ] ) ) ) {

            d2w_putlog( LOG_INFO, 'EDIT replaced MIME ' . $mimeType . ' AS ' . $mimeConversion[ 2 ] ) ; 
            $mimeType = $mimeConversion[ 2 ] ;
        }
    }

    /* This was designed to avoid loading .jpg which contains code
    $quality = acceptQuality( $httpAccept, $mimeType ) ;

    if( $quality < 0.5 )
        d2w_putlog( LOG_WARNING, 'SECU:' . ( $quality <= 0.0 ? 'acp0' : 'acpl' ) 
            . ' q=' . $quality . ' for ' . $mimeType . ' REQ: ' . $httpAccept ) ;

    if( $quality <= 0.0 ) {
        d2w_putlog( LOG_INFO, 'RESP 406 Not Acceptable' ) ;
        header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 406 Not Acceptable' ) ;
        header( 'Content-Length: 0' ) ;
        $bHandled = TRUE ; 
    }
    */

    return $bHandled ;
}

function quarantineHtml( &$html ){
    global $blacklist_signatures ;

    $bSafe = TRUE ;

    $encoding = detect_utf_encoding( $html ) ;

    if( FALSE === $encoding )
        $encoding = mb_detect_encoding( $html ) ;

    if( $encoding 
        && !( 'UTF-8' === $encoding || 'ASCII' === $encoding ) 
        && FALSE === strpos( $html, 'charset="utf-8"' ) ) {

        d2w_putlog( LOG_INFO, 'EDIT decode as ' . $encoding ) ;

        $html = mb_convert_encoding( $html, 'UTF-8', $encoding ) ;
    }

    // The inline matcher used `!strpos( $html, $key )` for grouped keys, which
    // is TRUE both when the key is missing *and* when it is found at offset 0:
    // a page beginning with the key (e.g. '<iframe ...') skipped the rule.
    $match = d2w_match_blacklist( $html, $blacklist_signatures ) ;
    $bSafe = ( FALSE === $match ) ;

    if( !$bSafe ) {
        d2w_putlog( LOG_WARNING, 'SECU:sign ' . $match[ 0 ] . ':' . $match[ 1 ] ) ;
    }
    else if( FALSE !== stripos( $html, '<form ' ) || FALSE !== stripos( $html, '<input ' ) ) {
        // Suspicious form

        $suspSign = 'F' ;

        if( FALSE !== stripos( $html, 'password' ) ) {
            $suspSign .= 'P' ;
            if( preg_match( '/type\s*=\s*[\'"]?password[\'"]?/i', $html ) 
                || FALSE !== strpos( $html, 'text-security:' ) )
                $suspSign .= 'T' ;
            else
                $suspSign .= 't' ;
        }

        if( FALSE !== strpos( $html, 'Facebook' ) 
            || FALSE !== strpos( $html, 'Gmail' ) 
            || FALSE !== strpos( $html, 'Outlook' )
            || FALSE !== stripos( $html, 'Yahoo' ) 
            || FALSE !== stripos( $html, 'PayPal' ) 
            || FALSE !== stripos( $html, 'eBay' ) 
            || FALSE !== stripos( $html, ' bank' ) 
            || FALSE !== stripos( $html, 'Trend Micro' )
            || FALSE !== stripos( $html, 'Norton' )
            || FALSE !== stripos( $html, '&#37038;&#25919;&#38598;&#22242;' ) // 邮政集团
            || FALSE !== stripos( $html, 'DHL ' )
            || FALSE !== stripos( $html, 'DocuSign' )
            || FALSE !== stripos( $html, 'WeTransfer' ) // 200308 4tp7czunngjdzhvvjy3trg
            || FALSE !== stripos( $html, 'Avast' ) // 200806 h725zthxhislxvezjrpfpa
            || FALSE !== stripos( $html, 'staticrypt' ) // 201009 txxf5fb3akdc1qne2a9i1g
            || FALSE !== strpos( $html, 'Microsoft Exchange Systems' ) // 201118 pfpkpqpdum2gzjz5pz6y0a glwyjxclsqtwzvmay4btva qft3ews7dzhj16fxupaana
            )

            $suspSign .= 'B' ;

        //d2w_putlog( LOG_WARNING, 'SECU:susp ' . $suspSign ) ;

        if( 'FPTB' == $suspSign ) {
            $html = preg_replace( 
                //'/(<form)((?:(?!password|<\/form).)*password(?:(?!<\/form).)*<\/form)/si', 
                '/<(form|input|button|img)/si', 
                '<!-- Blocked by DriveToWeb --><WITHHELD-$1', $html, -1, $count ) ;
            d2w_putlog( LOG_WARNING, 'SECU:edit FPTB violation removed #' . $count ) ;

            if( FALSE !== stripos( $html, '//aadcdn.msauth.net/' ) )
                $bSafe = FALSE ;
        }
    }
    else if( FALSE !== strpos( $html, 'unescape' ) ) {
        $cScanned = min( 1024, strlen( $html ) ) ;
        $count = substr_count( $html, '%', 0, $cScanned ) ;
        $len = min( 1024, $cScanned ) * 0.1 ;
        if( $count > $len ) {
            $bSafe = FALSE ;
            d2w_putlog( LOG_WARNING, 'SECU:count % ' . $count . ' > ' . $len ) ;
        }
    }
    else if( strlen( $html ) < 1024 ) {
        $count = substr_count( $html, '&#' ) ;
        $len = strlen( $html ) * 0.05 ;
        if( $count > $len ) {
            $bSafe = FALSE ;
            d2w_putlog( LOG_WARNING, 'SECU:count &# ' . $count . ' > ' . $len ) ;
        }
    }

    return $bSafe ;
}

function sendContent( $service, $urlContent, $mimeType, $fileSize, $eTag, $dtLastModified, 
    $idUser = NULL, $idFile = NULL ) {

    global $https, $requestMethod, $httpHost, $requestUri, $referrer, $httpOrigin ;

    $bHandled = FALSE ;

    $bHandled = handleCopyrightBlacklist( $httpHost, $requestUri ) ;

    if( !$bHandled )
        $bHandled = handleNotModified( $dtLastModified, $eTag ) ;

    // 'X-Requested-Wtih' typo meant caches never varied on the real header
    header( 'Vary: Origin, Sec-Fetch-Mode, X-Requested-With' ) ;

    // Hosted content: never let a browser guess a different type, and say
    // explicitly that cross-origin embedding is intended (it is the product).
    header( 'X-Content-Type-Options: nosniff' ) ;
    header( 'Cross-Origin-Resource-Policy: cross-origin' ) ;

    global $d2wContentCSP ;
    if( !empty( $d2wContentCSP ) )
        header( 'Content-Security-Policy: ' . $d2wContentCSP ) ;

    global $corsAllowedOrigins, $d2wSiteSuffix ;
    $corsOrigin = d2w_cors_origin( $httpOrigin, $corsAllowedOrigins, $d2wSiteSuffix ) ;
    if( $corsOrigin ) {
        d2w_putlog( LOG_INFO, 'ORIG allow ' . $corsOrigin ) ;
        header( 'Access-Control-Allow-Origin: ' . $corsOrigin ) ;
        header( 'Access-Control-Allow-Credentials: false' ) ;
    }

    if( !$bHandled && isset( $_SERVER[ 'HTTP_ACCEPT' ] ) )
        $bHandled = handleAccept( $mimeType ) ;

    if( !$bHandled ){
        if( $eTag )
            header( 'ETag: ' . $eTag ) ;
        if( $dtLastModified )
            header( 'Last-Modified: ' . $dtLastModified->format( 
                'D, d M Y H:i:s \G\M\T' ) ) ; 
    
        $bIsHtml = ( ( 'text/html' == $mimeType 
                || 'application/javascript' == $mimeType      // Needed if loaded via XHR
                || 'application/x-javascript' == $mimeType
                || 'text/javascript' == $mimeType
                || 'text/xml' == $mimeType 
                || 'application/xslt+xml' == $mimeType )
            && $fileSize < 32 * 1024 * 1024 ) ;

        $bIsCss = ( 'text/css' == $mimeType     // CSS needs valid relative links
            && $fileSize < 4 * 1024 * 1024 ) ;

        $bIsCors = ( $httpOrigin 
            || ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) 
                && 'XMLHttpRequest' == $_SERVER['HTTP_X_REQUESTED_WITH'] )
            || ( isset( $_SERVER[ 'HTTP_SEC_FETCH_MODE' ] ) 
                && 'cors' == $_SERVER[ 'HTTP_SEC_FETCH_MODE' ] ) ) ;

        $bIsImgAsDoc = ( 0 === strpos( $mimeType, 'image/' ) 
            && isset( $_SERVER[ 'HTTP_SEC_FETCH_DEST' ] ) 
            && 'document' == $_SERVER[ 'HTTP_SEC_FETCH_DEST' ] ) ;
    
        $userAgent = ( isset( $_SERVER[ 'HTTP_USER_AGENT' ] )
            ? $_SERVER[ 'HTTP_USER_AGENT' ] : '' ) ;
        $bIsBotImg = ( FALSE !== strpos( $userAgent, 'bot' )  // Bot requests, serve images
            && 0 === strpos( $mimeType, 'image/' )
            && $fileSize < 8 * 1024 * 1024 ) ;

        $bIsBlob = ( ( 'application/x-gzip' == $mimeType
                && '.unityweb' == substr( $requestUri, -strlen( '.unityweb' ) ) )
            || ( 'application/octet-stream' == $mimeType
                && '.appcache' == substr( $requestUri, -strlen( '.appcache' ) ) )
            || ( 'application/octet-stream' == $mimeType
                && '.webmanifest' == substr( $requestUri, -strlen( '.webmanifest' ) ) )
            || ( 'application/x-123' == $mimeType ) )
            && $fileSize < 32 * 1024 * 1024 ;

        $bIsXmlImg = ( isset( $_SERVER[ 'HTTP_ACCEPT' ] )
            && FALSE !== strpos( $_SERVER[ 'HTTP_ACCEPT' ], 'image/' )  
            && ( 'text/xml' == $mimeType || 'image/svg+xml' == $mimeType )
            && $fileSize < 8 * 1024 * 1024 ) ; // XML <img> requests, likely SVG, need COPY
        
        $bIsPostDb = ( 'application/vnd.google-apps.spreadsheet' == $mimeType ) ;

        $bIsText = ( 0 === strpos( $mimeType, 'text/plain' ) 
            && $fileSize < 4 * 1024 * 1024 ) ;

        $bIsReplacedContent = FALSE ;
        // Font Awesome 4.7 is 404 blocked by GD
        if( FALSE !== strpos( $requestUri, 'fontawesome-webfont' ) ) {
            if( 77160 == $fileSize ) {
                $bIsReplacedContent = TRUE ;
                $urlContent = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/fonts/fontawesome-webfont.woff2' ;
            }
            else if( 'application/font-woff' === $mimeType && 98024 == $fileSize ) {
                $bIsReplacedContent = TRUE ;
                $urlContent = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/fonts/fontawesome-webfont.woff' ;
            }
            else if( 'application/x-font-ttf' === $mimeType && 165548 == $fileSize ) {
                $bIsReplacedContent = TRUE ;
                $urlContent = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/fonts/fontawesome-webfont.ttf' ;
            }
        }

        if( $bIsReplacedContent ) {
            d2w_putlog( LOG_INFO, 'RESP 302 replaced' ) ;

            header( "Location: $urlContent" ) ;
            header( 'Cache-Control: public, s-maxage=604800, max-age=604800' ) ;
            $bHandled = TRUE ;
        }
        else if( ( ( $bIsCors && !$bIsCss ) // CSS resolves relative to effective URL, so it cannot be forwarded to CDN
                || $bIsImgAsDoc )
            && ( ( 'gd' === $service && $idUser && $idFile ) || 'od' == $service )
            && $fileSize < 2 * 1024 * 1024 // Only cache files smaller than 2MB to avoid cost 
            ) {

            // Capped at monthly 1TB/10Mtx = per 5-min 115MB/1.15Ktx and per hour 1.4GB/13Ktx
            $bCdn1 = $fileSize > 8 * 1024 && $fileSize < 1024 * 7 / 16 * 1024 ; // Send this range to CDN 1
            $bCdn2 = !$bCdn1 ; // Send the rest to CDN 2

            if( 'gd' === $service && $idUser )
                $token = gdGetAccessToken( $idUser ) ;

            if( $bCdn1 || $bCdn2 ) {
                $siteName = getSiteName( $idUser, $service ) ;

                global $d2wCdnHosts ;
                $cdnHost = isset( $d2wCdnHosts[ $bCdn1 ? 0 : 1 ] )
                    ? $d2wCdnHosts[ $bCdn1 ? 0 : 1 ] : $d2wCdnHosts[ 0 ] ;

                $urlProxiedCopy = ( ( 'gd' == $service )

                    ? 'https://' . $cdnHost . '/_guf/' . $siteName . '/' . urlencode( $idFile )
                        . '?ra=' . urlencode( strrev( $token[ 'token' ] ) )

                    // urldecode() decoded a second time here (the edge function
                    // decodes it once more), mangling Graph URLs whose own query
                    // string contains %xx escapes
                    : 'https://' . $cdnHost . '/_opf/' . $siteName . '/' . urlencode( $idFile )
                        . '?ur=' . rawurlencode( substr( $urlContent, strlen( 'https://' ) ) ) ) ;
            }

            if( $urlProxiedCopy ) {
                d2w_putlog( LOG_INFO, 'RESP 302 forward CDN' . ( $bCdn1 ? '' : '2' ) . ' size=' . $fileSize . ' ' 
                    . $urlProxiedCopy ) ;
                $maxAge = ( ( 'gd' == $service && isset( $token[ 'expires' ] ) )
                    ? max( 60, $token[ 'expires' ]->getTimestamp() - time() ) // GD links live as long as the token
                    : 3600 * 24 - 10 ) ; // OD links are valid for 24 hours - 10 min
                d2w_putlog( LOG_INFO, 'MXAG ' . $maxAge ) ;

                header( "Location: $urlProxiedCopy" ) ;
                header( 'Cache-Control: public, s-maxage=' . $maxAge . ', max-age=' . $maxAge ) ;
                header( 'X-D2W-Target-Length: ' . $fileSize ) ;
                $bHandled = TRUE ;
            }
        }
        // Local copy
        else if( $bIsHtml || $bIsCss || $bIsBlob || $bIsXmlImg || $bIsText ) {

            header( 'Content-Type: ' . $mimeType ) ;
            if( 'gd' == $service && $idUser && $idFile ) {
                // For GD, use get file alt=media API which permits higher access count
                $token = gdGetAccessToken( $idUser ) ;
                $filerec = gdGetFile( $idUser, $token, $idFile ) ;
                $html = ( FALSE !== $filerec ? $html = $filerec[ 'content' ] : FALSE ) ;
            }
            else
                // For other services, use direct download API which may impose access limit
                $html = @file_get_contents( $urlContent ) ;

            // Check for content GD screener
            if( FALSE !== $html && 'gd' == $service 
                && FALSE !== strpos( $html, 'id="uc-download-link"' ) ) {

                // Too much removed, likely blocked warning 
                $urlNetContent = gdUnscreenContent( $urlContent ) ;

                if( FALSE !== $urlNetContent )
                    $html = file_get_contents( $urlNetContent ) ; 
            }

            if( FALSE !== $html ) {
                $bSafe = TRUE ;

                if( 'text/html' === $mimeType || FALSE !== strpos( $mimeType, 'javascript' ) )
                    $bSafe = quarantineHtml( $html ) ;
                
                if( $bSafe ) {
                    if( 'text/html' == $mimeType ) {
                        // Insert wd.js before the last </body>, </html>, or any tag. Script detects other libraries
                        $pos = strripos( $html, '</body>' ) ;
                        if( FALSE === $pos )
                            // For HTML w/o </body>
                            $pos = strripos( $html, '</html>' ) ;
                        if( FALSE === $pos )
                            // For HTML w/o </body> and </html>
                            $pos = strlen( $html ) ;

                        if( FALSE !== $pos ) {
                            $siteName = getSiteName( $idUser, $service ) ;

                            if( FALSE !== $siteName ) {
                                global $d2wWidgetScript ;
                                $html = substr_replace( $html, 
                                    '<script src="' . $d2wWidgetScript . '?s='
                                        . rawurlencode( $siteName ) . '"></script>', $pos, 0 ) ;
                            }
                        }
                    }

                    // Patch known libraries for crossOrigin
                    if( FALSE !== strpos( $requestUri, 'tdvplayersw.js') && 1317 == $fileSize ) {
                        $html = str_replace( 'fetch(request)', 
                            'fetch((new URL(event.request.url).origin===self.location.origin&&\'no-cors\'==request.mode)'
                            . '?new Request(request.url):request)', $html ) ;
                        d2w_putlog( LOG_INFO, 'PATCH forcing CORS in tdvplayersw.js' ) ; 
                    }

                    $cb = strlen( $html ) ;
                    header( 'Content-Length: ' . $cb ) ;
                    $maxAge = 43200 ;
                    header( 'Cache-Control: public, s-maxage=' . $maxAge . ', max-age=' . $maxAge ) ;
                
                    // TODO: Compress result if Accept-Encoding allows
                    header( 'Vary: Accept-Encoding', FALSE ) ; // So caches don't mix comp/uncomp data

                    if( $requestMethod != 'HEAD' ) {
                        $type = ( $bIsHtml ? 'html' : 
                                ( $bIsCss ? 'csss' :
                                ( $bIsBlob ? 'blob' : 
                                ( $bIsXmlImg ? 'xmli' : 
                                ( $bIsText ? 'text' : 'unkn' ) ) ) ) ) ;

                        d2w_putlog( LOG_INFO, 'RESP 200 COPY:' . $type . '; size=' . $fileSize 
                            . ', added=' . ( $cb - $fileSize ) ) ;
                        print $html ;
                    }

                    $bHandled = TRUE ;
                }
                else {
                    if( FALSE !== strpos( $mimeType, '/javascript' ) ) {
                        d2w_putlog( LOG_WARNING, 'RESP 403 SECU script' ) ;

                        global $d2wWebHost ;
                        $html = 'window.location.replace("https://' . $d2wWebHost . '");' ;
                        $cb = strlen( $html ) ;
                        header( 'Cache-Control: public, s-maxage=604800, max-age=604800' ) ;
                        header( 'Content-Length: ' . $cb ) ;
                        print $html ;
                        $bHandled = TRUE ;
                    }
                    else {
                        d2w_putlog( LOG_WARNING, 'RESP 403 SECU page' ) ;
                        header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 403 Forbidden' ) ;
                        header( 'Cache-Control: public, s-maxage=604800, max-age=604800' ) ;
                        $html = d2w_inline_page( '403-content.html' ) ;
                        $cb = strlen( $html ) ;
                        header( 'Content-Length: ' . $cb ) ;
                        print $html ;
                        $bHandled = TRUE ;
                    }
                }
            }
        }
        // Markdown support
        else if( 'text/x-markdown' == $mimeType ) {
            $html = file_get_contents( $urlContent ) ;
            $wrapped = d2w_inline_page( 'md_wrap.html' ) ;
            $wrapped = str_replace( '%%DRV_CONTENT%%', $html, $wrapped ) ;
            $cb = strlen( $wrapped ) ;
            header( 'Content-Type: text/html' ) ;
            header( 'Content-Length: ' . $cb ) ;
            print $wrapped ;
            $bHandled = TRUE ;
        }
        // Special handling for large files on GD that may trigger excess size confirmation 
        else if( 'gd' == $service 
            && ( 'video/mp4' == $mimeType 
                || 'video/x-m4v' == $mimeType
                || 'video/x-matroska' == $mimeType
                || 'video/x-msvideo' == $mimeType
                || 'video/avi' == $mimeType
                || 'application/x-shockwave-flash' == $mimeType
                || 'application/x-iso9660-image' == $mimeType
                || 'application/x-msdownload' == $mimeType
                || 'application/x-dosexec' == $mimeType
                || 'application/x-msi' == $mimeType
                || 'application/zip' == $mimeType
                || 'application/x-apple-diskimage' == $mimeType
                ) ) {

            $urlNetContent = gdUnscreenContent( $urlContent, $idFile ) ;

            if( FALSE !== $urlNetContent ) {
                // Not error page: forward to user
                d2w_putlog( LOG_INFO, 'RESP 302 screened forward; size=' . $fileSize ) ;
                header( 'Location: ' . $urlNetContent ) ;
                header( 'Content-Type: ' . $mimeType ) ;
                $maxAge = 300 + mt_rand( 0, 600 ) ;
                header( 'Cache-Control: public, s-maxage=' . $maxAge . ', max-age=' . $maxAge ) ;
                header( 'X-D2W-Target-Length: ' . $fileSize ) ;

                $bHandled = TRUE ;
            }
            else {
                // Most likely "too many downloads". Respond either 429 503
                d2w_putlog( LOG_INFO, 'RESP 429 busy' ) ;
                header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 429 Too Many Requests' ) ;
                print d2w_inline_page( '429.html' ) ;
                $bHandled = TRUE ;
            }
        }
        else if( 'gd' == $service && $bIsPostDb ) {
            $token = gdGetAccessToken( $idUser ) ;
            $bHandled = gdGetPostDb( $token, $idFile ) ;
        }
        else {
            // No need to copy, just forward (cacheable)
            d2w_putlog( LOG_INFO, 'RESP 302 forward; size=' . $fileSize ) ;
            $maxAge = ( 0 === strpos( $urlContent, "https://drive.google.com/uc?id=" ) ? 604800 : 600 + mt_rand( 0, 1200 ) ) ;
            d2w_putlog( LOG_INFO, 'MXAG ' . $maxAge ) ;

            // Google blocks cross-site requests to /uc. Get /thumbnail instead
            if( 'gd' == $service && 0 === strpos( $mimeType, 'image/' ) )
                $urlContent = str_replace( 'https://drive.google.com/uc?', 
                    'https://drive.google.com/thumbnail?sz=w1000&', $urlContent ) ;

            header( "Location: $urlContent" ) ;
            header( 'Cache-Control: public, s-maxage=' . $maxAge . ', max-age=' . $maxAge ) ;
            header( 'X-D2W-Target-Length: ' . $fileSize ) ;

            $bHandled = TRUE ;
        }

        // Something failed: just send the link without caching
        if( !$bHandled ) {
            d2w_putlog( LOG_INFO, 'RESP 302 exception forward; size=' . $fileSize ) ;
            header( "Location: $urlContent" ) ;
            header( 'Cache-Control: no-cache' ) ;
            header( 'X-D2W-Target-Length: ' . $fileSize ) ;
            $bHandled = TRUE ;
        }
    }

    return $bHandled ;
}

function setSiteInfo( $siteName, $userId, $service, $root, $removePrevious = FALSE ) {
    global $siteNameSecret ;

    $ok = FALSE ;

    $db = openDb() ;
    if( $removePrevious ) {
        $bulk = new MongoDB\Driver\BulkWrite() ;
        $bulk->delete( [
            'userId'  => new MongoDB\BSON\ObjectID( $userId ),
            'service' => $service,
            'root'    => $root
        ] ) ;
        $db['localStore']->executeBulkWrite( 'wwwdrv.sites', $bulk ) ;

        for( $i = 0; $i < count( $db['remoteStores'] ); $i++ ) {
            $bulk = new MongoDB\Driver\BulkWrite() ;
            $bulk->delete( [
                'userId'  => new MongoDB\BSON\ObjectID( $userId ),
                'service' => $service,
                'root'    => $root
            ] ) ;
            $db['remoteStores'][ $i ]->executeBulkWrite( 'wwwdrv.sites', $bulk ) ;
        }
    }

    if( !$siteName ) {
        //
        // Generate a site name. Two problems here are fixed:
        //  1. the loop tested `count( $rec )` - a variable that was never
        //     updated - and queried `site => $siteName` captured *before* the
        //     candidate name existed, so collisions were never detected and a
        //     new site could silently take over an existing URL;
        //  2. names were md5( "<userId>.<service>" ), which anyone who knows a
        //     (time-ordered, semi-public) ObjectId can recompute - and the site
        //     name is the only thing guarding a "private" site.
        //
        $salt = 0 ;
        $candidate = NULL ;
        do {
            $candidate = d2w_generate_site_name( $userId, $service, NULL, $siteNameSecret ) ;
            $query = new MongoDB\Driver\Query( [ 'site' => $candidate ], [ 'limit' => 1 ] ) ;
            $recs = $db['main']->executeQuery( 'wwwdrv.sites', $query )->toArray() ;
            $salt++ ;
        }
        while( count( $recs ) > 0 && $salt < 20 ) ;

        if( count( $recs ) > 0 ) {
            d2w_putlog( LOG_CRIT, 'DB site name collision for user ' . $userId ) ;
            return FALSE ;
        }

        $siteName = $candidate ;
    }
    else if( !d2w_valid_site_name( $siteName ) ) {
        d2w_putlog( LOG_WARNING, 'DB rejected site name ' . substr( (string)$siteName, 0, 80 ) ) ;
        return FALSE ;
    }

    $bulk = new MongoDB\Driver\BulkWrite() ;
    $bulk->update( [
        'site'    => $siteName
    ], [ '$set' => [
        'userId'         => new MongoDB\BSON\ObjectID( $userId ),
        'service'        => $service,
        'root'           => $root
    ] ], [
        'upsert'         => TRUE
    ] ) ;

    try {
        $writeResult = $db['localStore']->executeBulkWrite( 'wwwdrv.sites', $bulk ) ;
        $ok = ( NULL !== $writeResult ) ;

        for( $i = 0; $i < count( $db['remoteStores'] ); $i++ ) {
            $bulk = new MongoDB\Driver\BulkWrite() ;
            $bulk->update( [
                'site'    => $siteName
            ], [ '$set' => [
                'userId'         => new MongoDB\BSON\ObjectID( $userId ),
                'service'        => $service,
                'root'           => $root
            ] ], [
                'upsert'         => TRUE
            ] ) ;
            $db['remoteStores'][ $i ]->executeBulkWrite( 'wwwdrv.sites', $bulk ) ;
        }
    }
    catch( MongoDB\Driver\Exception\Exception $e ) {
        d2w_putlog( LOG_CRIT, 'DB exception: ' . $e->getMessage() ) ;
    }
    if( !$ok )
        d2w_putlog( LOG_CRIT, 'DB set site info failed for ' . $siteName ) ;

    return $ok ;
}

function getSiteInfo( $siteName ) {
    $result = FALSE ;

    if( $siteName ) {
        $db = openDb() ;
        $filter = [ 'site' => $siteName ] ;
        $options = [
            'projection' => [
                'userId'          => 1,
                'service'         => 1,
                'root'            => 1,
                'bAuthenticated'  => 1
            ],
            'limit' => 1
        ] ;
        $query = new MongoDB\Driver\Query( $filter, $options ) ;
        $recs = [] ;
        try {
            $recs = $db['main']->executeQuery( 'wwwdrv.sites', $query )->toArray() ;
        }
        catch( MongoDB\Driver\Exception\Exception $e ) {
            d2w_putlog( LOG_CRIT, 'DB get site info failed for ' . $siteName ) ;
        }
        if( count( $recs ) > 0 )
            $result = $recs[ 0 ] ;
    }
    return $result ;
}

function setSiteAuthenticate( $siteName, $bAuthenticated ) {
    $ok = FALSE ;

    if( $siteName ) {
        $db = openDb() ;
        $bulk = new MongoDB\Driver\BulkWrite() ;
        $bulk->update( [
            'site'    => $siteName
        ], [ '$set' => [
            'bAuthenticated' => $bAuthenticated,
        ] ] ) ;
    
        try {
            $writeResult = $db['localStore']->executeBulkWrite( 'wwwdrv.sites', $bulk ) ;
            $ok = ( NULL !== $writeResult ) ;
    
            for( $i = 0; $i < count( $db['remoteStores'] ); $i++ ) {
                $bulk = new MongoDB\Driver\BulkWrite() ;
                $bulk->update( [
                    'site'    => $siteName
                ], [ '$set' => [
                    'bAuthenticated' => $bAuthenticated,
                ] ] ) ;
                $db['remoteStores'][ $i ]->executeBulkWrite( 'wwwdrv.sites', $bulk ) ;
            }
        }
        catch( MongoDB\Driver\Exception\Exception $e ) {
            d2w_putlog( LOG_CRIT, 'DB exception: ' . $e->getMessage() ) ;
        }
        if( !$ok )
            d2w_putlog( LOG_CRIT, 'DB set site bAuthenticate failed for ' . $siteName ) ;    
    }

    return $ok ;
}

/**
 * Names the deployment needs for itself: every single-label host it answers on,
 * plus the operator's own list. A visitor must not be able to claim "api" or
 * "cdn-of" and then receive traffic meant for the service.
 */
function site_reserved_names() {
    global $d2wBaseDomain, $d2wApiHost, $d2wWebHost, $d2wCdnHosts, $d2wReservedSiteNames ;

    $hosts = array( $d2wBaseDomain, $d2wApiHost, $d2wWebHost ) ;
    foreach( (array) $d2wCdnHosts as $h )
        $hosts[] = $h ;

    return d2w_site_name_reserved( $hosts, (array) $d2wReservedSiteNames ) ;
}

/**
 * @return array [ $ok, $reason, $normalisedName ]
 */
function site_name_status( $rawName, $bCheckTaken = TRUE ) {
    $changed = FALSE ;
    $name = d2w_normalize_site_name( $rawName, $changed ) ;

    if( NULL === $name )
        return array( FALSE, 'Use only the letters a-z, digits and hyphens.', NULL ) ;

    list( $ok, $reason ) = d2w_site_name_claimable( $name, site_reserved_names() ) ;
    if( !$ok )
        return array( FALSE, $reason, $name ) ;

    if( $bCheckTaken && getSiteInfo( $name ) )
        return array( FALSE, 'That address is already taken.', $name ) ;

    if( site_name_copyright_blocked( $name ) )
        return array( FALSE, 'That address is reserved by the service.', $name ) ;

    if( $changed )
        $reason = 'OK - we will use "' . $name . '"' ;

    return array( TRUE, $reason, $name ) ;
}

/**
 * A name on the copyright list is a takedown target: refuse it both when
 * claiming and when renaming, so a rename cannot be used to escape a block.
 */
function site_name_copyright_blocked( $siteName ) {
    global $blacklist_copyright, $d2wSiteSuffix ;

    if( !is_array( $blacklist_copyright ) || '' === $siteName )
        return FALSE ;

    $host = $siteName . $d2wSiteSuffix ;

    foreach( $blacklist_copyright as $rule )
        if( isset( $rule[ 0 ] ) && 0 === strcasecmp( (string) $rule[ 0 ], $host ) )
            return TRUE ;

    return FALSE ;
}

function send_site_json( $data, $status = 200, $extraHeaders = array() ) {
    if( !headers_sent() ) {
        header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' ' . $status
            . ( 429 === $status ? ' Too Many Requests' : ( 409 === $status ? ' Conflict'
                : ( 400 === $status ? ' Bad Request' : ( 403 === $status ? ' Forbidden' : ' OK' ) ) ) ),
            TRUE, $status ) ;
        header( 'Content-Type: application/json' ) ;
        header( 'Cache-Control: no-store' ) ;
        foreach( (array) $extraHeaders as $h )
            header( $h ) ;
    }

    print d2w_json( $data ) ;

    return TRUE ;
}

/**
 * GET …/@@site - what the dashboard needs to render the URL card.
 */
function site_send_info( $userId, $service, $siteName ) {
    global $d2wBaseDomain, $d2wSiteSuffix, $siteNameChangeGapSec ;

    $site = getSiteInfo( $siteName ) ;
    if( !$site )
        return send_site_json( array( 'ok' => FALSE, 'error' => 'ERR_NO_SITE',
            'tip' => 'No site is attached to this session yet.' ), 404 ) ;

    $renameIn = 0 ;
    if( isset( $site->tsNameChange ) ) {
        $gap = intval( $siteNameChangeGapSec ) ;
        $left = $gap - ( time() - intval( $site->tsNameChange ) ) ;
        if( $left > 0 )
            $renameIn = $left ;
    }

    return send_site_json( array(
        'ok'         => TRUE,
        'siteName'   => $siteName,
        'url'        => d2w_site_public_url( $siteName, $d2wBaseDomain, TRUE ),
        'service'    => $service,
        'root'       => isset( $site->root ) ? $site->root : '/',
        'approved'   => !empty( $site->bAuthenticated ) ,
        'domain'     => isset( $site->domain ) ? $site->domain : NULL,
        'renameIn'   => $renameIn,
        // Plain strings, because the admin panel writes these straight into the
        // page: the alternate address, and the one record the owner must publish.
        'hint'       => $siteName . $d2wSiteSuffix,
        'txt'        => 'DRVTW=' . $siteName . $d2wSiteSuffix,
        'cname'      => $siteName . $d2wSiteSuffix,
        'records'    => d2w_site_domain_hint( $siteName, $d2wBaseDomain ),
    ) ) ;
}

/**
 * POST …/@@rename?name=<label> - claim the address you want.
 *
 * Owner-authenticated and CSRF-protected by the caller (it is in the privileged
 * command list), rate limited, and refused when either name is on the copyright
 * list, so it cannot be used to dodge a takedown.
 */
function site_rename( $userId, $service, $siteName, $rawNew ) {
    global $d2wBaseDomain, $d2wSiteSuffix, $siteNameChangeGapSec, $requestMethod ;

    $site = getSiteInfo( $siteName ) ;
    if( !$site )
        return send_site_json( array( 'ok' => FALSE, 'error' => 'ERR_NO_SITE' ), 404 ) ;

    $gap = intval( $siteNameChangeGapSec ) ;
    if( $gap > 0 && isset( $site->tsNameChange ) ) {
        $left = $gap - ( time() - intval( $site->tsNameChange ) ) ;
        if( $left > 0 )
            return send_site_json( array( 'ok' => FALSE, 'error' => 'ERR_TOO_FAST',
                'tip' => 'You changed this address less than ' . $gap
                    . ' seconds ago. Wait ' . $left . 's and try again.',
                'retryIn' => $left ), 429, array( 'Retry-After: ' . $left ) ) ;
    }

    // Escaping a block by renaming: refuse before anything is written.
    if( site_name_copyright_blocked( $siteName ) ) {
        d2w_putlog( LOG_WARNING, 'SECURE:rename refused (copyright) site=' . $siteName ) ;
        return send_site_json( array( 'ok' => FALSE, 'error' => 'ERR_LOCKED',
            'tip' => 'This site is under review and its address cannot be changed.' ), 403 ) ;
    }

    list( $ok, $reason, $newName ) = site_name_status( $rawNew ) ;
    if( !$ok )
        return send_site_json( array( 'ok' => FALSE, 'error' => 'ERR_BAD_NAME',
            'tip' => $reason, 'name' => $newName ),
            ( $newName && getSiteInfo( $newName ) ) ? 409 : 400 ) ;

    if( $newName === $siteName )
        return send_site_json( array( 'ok' => TRUE, 'siteName' => $siteName, 'unchanged' => TRUE,
            'url' => d2w_site_public_url( $siteName, $d2wBaseDomain, TRUE ) ) ) ;

    if( !move_site_record( $siteName, $newName, $site ) )
        return send_site_json( array( 'ok' => FALSE, 'error' => 'ERR_STORAGE',
            'tip' => 'The change could not be saved. Try again in a moment.' ), 503 ) ;

    d2w_putlog( LOG_INFO, 'SITE renamed ' . $siteName . ' -> ' . $newName
        . ' u=' . $userId . ' via=' . $requestMethod ) ;

    return send_site_json( array(
        'ok'         => TRUE,
        'siteName'   => $newName,
        'previous'   => $siteName,
        'url'        => d2w_site_public_url( $newName, $d2wBaseDomain, TRUE ),
        'previousUrl' => d2w_site_public_url( $siteName, $d2wBaseDomain, TRUE ),
        'tip'        => 'Your site now lives at the new address. Update any links you '
            . 'published, and refresh your site cache if it looked stale before.',
    ) ) ;
}

/**
 * Move the record. The old name is *not* tombstoned: it simply becomes free
 * again, like every other hosting service with renames - but the rename is
 * rate limited and logged, and a blocked site never gets here.
 */
function move_site_record( $fromName, $toName, $site ) {
    $db = openDb() ;

    $fields = array(
        'userId'        => $site->userId,
        'service'       => $site->service,
        'root'          => isset( $site->root ) ? $site->root : '/',
        'bAuthenticated'=> !empty( $site->bAuthenticated ) ,
        'tsNameChange'  => time() ,
        'prevSite'      => $fromName ,
    ) ;

    foreach( array( 'localStore', 'remoteStores' ) as $which ) {
        $stores = ( 'localStore' === $which ) ? array( $db[ 'localStore' ] ) : $db[ 'remoteStores' ] ;

        foreach( $stores as $store ) {
            try {
                $bulk = new MongoDB\Driver\BulkWrite() ;
                $bulk->update( [ 'site' => $toName ], [ '$set' => $fields ], [ 'upsert' => TRUE ] ) ;
                $bulk->delete( [ 'site' => $fromName ] ) ;
                $store->executeBulkWrite( 'wwwdrv.sites', $bulk ) ;
            }
            catch( \Throwable $e ) {
                d2w_putlog( LOG_CRIT, 'DB site rename failed: ' . $e->getMessage() ) ;
                return FALSE ;
            }
        }
    }

    return TRUE ;
}

/**
 * POST …/@@domain?domain=example.com - show the record they must publish, and
 * confirm it resolves here. DNS only: nothing is fetched, so this cannot be
 * turned into a port scanner.
 */
function site_bind_domain( $siteName, $domain ) {
    global $d2wBaseDomain, $d2wSiteSuffix ;

    $hint = d2w_site_domain_hint( $siteName, $d2wBaseDomain ) ;

    $domain = is_string( $domain ) ? mb_strtolower( trim( $domain ) ) : '' ;
    if( !preg_match( '/^(?!-)[a-z0-9\-.]{3,253}(?<!-)$/', $domain ) || FALSE === strpos( $domain, '.' ) )
        return send_site_json( array( 'ok' => FALSE, 'error' => 'ERR_BAD_DOMAIN',
            'tip' => 'Enter a plain hostname, e.g. www.example.com', 'hint' => $hint ), 400 ) ;

    $expected = 'DRVTW=' . $siteName . $d2wSiteSuffix ;
    $matched = FALSE ;
    $records = @dns_get_record( $domain, DNS_TXT ) ;

    if( is_array( $records ) )
        foreach( $records as $rec )
            if( isset( $rec[ 'txt' ] ) && 0 === strcasecmp( trim( $rec[ 'txt' ], '"' ), $expected ) )
                $matched = TRUE ;

    if( !$matched ) {
        $cname = @dns_get_record( $domain, DNS_CNAME ) ;
        if( is_array( $cname ) )
            foreach( $cname as $rec )
                if( isset( $rec[ 'target' ] )
                    && 0 === strcasecmp( $siteName . $d2wSiteSuffix, $rec[ 'target' ] ) )
                    $matched = TRUE ;
    }

    if( !$matched )
        return send_site_json( array( 'ok' => FALSE, 'error' => 'ERR_NOT_YET',
            'tip' => 'No matching record yet. Publish it, then verify again - DNS can '
                . 'take a few minutes.',
            'expected' => $expected, 'hint' => $hint ), 404 ) ;

    d2w_putlog( LOG_INFO, 'SITE domain verified ' . $domain . ' for ' . $siteName ) ;

    $db = openDb() ;
    try {
        $bulk = new MongoDB\Driver\BulkWrite() ;
        $bulk->update( [ 'site' => $siteName ], [ '$set' => [ 'domain' => $domain,
            'tsDomainCheck' => time() ] ] ) ;
        $db[ 'localStore' ]->executeBulkWrite( 'wwwdrv.sites', $bulk ) ;
    }
    catch( \Throwable $e ) {
        // Verification succeeded; remembering it is a nicety, so do not fail the call
        d2w_putlog( LOG_WARNING, 'DB domain note failed: ' . $e->getMessage() ) ;
    }

    return send_site_json( array( 'ok' => TRUE, 'matched' => TRUE, 'domain' => $domain,
        'url' => 'https://' . $domain . '/',
        'tip' => 'Verified. Point the host at this service as well (A record to the server, '
            . 'or a CNAME to ' . $siteName . $d2wSiteSuffix . ').' ) ) ;
}

/**
 * GET /do/siteName?name=… - the pre-check the naming field polls.
 * Public, one indexed query, and it reports only whether an address is free.
 */
function site_check_available( $rawName ) {
    list( $ok, $reason, $name ) = site_name_status( $rawName ) ;

    return send_site_json( array(
        'available' => $ok,
        'name'      => $name,
        'tip'       => $ok ? 'Free.' : $reason,
    ), $ok ? 200 : ( $name && getSiteInfo( $name ) ? 409 : 400 ) ) ;
}


/**
 * Get site name from user ID and service
 * @param string $userId
 * @param string $service ("gd" or "od")
 * @param string $root
 * @return string
 */
function getSiteName( $userId, $service, $root = '/' ) {
    $result = FALSE ;

    if( $userId && $service ) {
        $db = openDb() ;
        $filter = [ 
            'userId'  => new MongoDB\BSON\ObjectID( $userId ),
            'service' => $service,
            'root'    => $root
        ] ;
        $options = [
            'projection' => [ 'site' => 1 ],
            'sort' => [ '_id' => -1 ],
            'limit' => 1
        ] ;
        $query = new MongoDB\Driver\Query( $filter, $options ) ;
        $recs = $db['main']->executeQuery( 'wwwdrv.sites', $query )->toArray() ;
        if( count( $recs ) > 0 )
            $result = $recs[ 0 ]->site ;
    }
    return $result ;
}



function setSiteAuthCode( $siteName, $authCode, $email ) {
    $ok = FALSE ;

    $mongoNow = new MongoDB\BSON\UTCDateTime ;

    if( $siteName ) {
        try {
            $db = openDb() ;
            $bulk = new MongoDB\Driver\BulkWrite() ;
            $bulk->update( [
                'site'    => $siteName
            ], [ '$set' => [
                'code' => $authCode,
                'email' => $email,
                'ts' => $mongoNow
            ] ], [
                'upsert' => TRUE
            ] ) ;
        
            $writeResult = $db['localStore']->executeBulkWrite( 'wwwdrv.authcode', $bulk ) ;
            $ok = ( NULL !== $writeResult ) ;
    
            for( $i = 0; $i < count( $db['remoteStores'] ); $i++ ) {
                $bulk = new MongoDB\Driver\BulkWrite() ;
                $bulk->update( [
                    'site'    => $siteName
                ], [ '$set' => [
                    'code' => $authCode,
                    'email' => $email,
                    'ts' => $mongoNow
                ] ], [
                    'upsert' => TRUE
                ] ) ;
                $db['remoteStores'][ $i ]->executeBulkWrite( 'wwwdrv.authcode', $bulk ) ;
            }
        }
        catch( MongoDB\Driver\Exception\Exception $e ) {
            d2w_putlog( LOG_CRIT, 'DB exception: ' . $e->getMessage() ) ;
        }
        if( !$ok )
            d2w_putlog( LOG_CRIT, 'DB set site auth code failed for ' . $siteName ) ;    
    }

    return $ok ;
}

function getLastAuthCodeTimestamp( $siteName ) {
    $tsLastSent = NULL ;

    if( $siteName ) {
        $db = openDb() ;
        $filter = [ 
            'site' => $siteName,
        ] ;
        $options = [
            'projection' => [ 'ts' => 1 ],
            'sort' => [ '_id' => -1 ],
            'limit' => 1
        ] ;
        $query = new MongoDB\Driver\Query( $filter, $options ) ;
        $recs = $db['main']->executeQuery( 'wwwdrv.authcode', $query )->toArray() ;
        if( count( $recs ) > 0 )
            $tsLastSent = $recs[ 0 ]->ts->toDateTime()->getTimestamp() ;
    }
    return $tsLastSent ;
}

/**
 * Per-site verification-failure counter (wwwdrv.authfail, _id = site name).
 * A DB problem must not lock a visitor out of their own site, so the counter
 * degrades to "no data" (the send-side throttle still applies).
 */
function getAuthFailures( $siteName ) {
    $out = array( 'fails' => 0, 'ts' => 0 ) ;

    if( !$siteName )
        return $out ;

    try {
        $db = openDb() ;
        $query = new MongoDB\Driver\Query( [ '_id' => $siteName ],
            [ 'projection' => [ 'fails' => 1, 'ts' => 1 ], 'limit' => 1 ] ) ;
        $recs = $db[ 'localStore' ]->executeQuery( 'wwwdrv.authfail', $query )->toArray() ;

        if( count( $recs ) > 0 ) {
            $rec = $recs[ 0 ] ;
            $out[ 'fails' ] = isset( $rec->fails ) ? intval( $rec->fails ) : 0 ;
            $out[ 'ts' ] = isset( $rec->ts ) ? intval( $rec->ts ) : 0 ;
        }
    }
    catch( \Throwable $e ) {
        d2w_putlog( LOG_WARNING, 'SITEAUTH counter unavailable: ' . $e->getMessage() ) ;
    }

    return $out ;
}

function bumpAuthFailures( $siteName, $bReset ) {
    if( !$siteName )
        return ;

    try {
        $db = openDb() ;
        $bulk = new MongoDB\Driver\BulkWrite() ;

        if( $bReset )
            $bulk->delete( [ '_id' => $siteName ] ) ;
        else
            $bulk->update(
                [ '_id' => $siteName ],
                array( '$inc' => [ 'fails' => 1 ],
                    '$set' => [ 'ts' => time() ] ),
                [ 'upsert' => TRUE ] ) ;

        $db[ 'localStore' ]->executeBulkWrite( 'wwwdrv.authfail', $bulk ) ;
    }
    catch( \Throwable $e ) {
        d2w_putlog( LOG_WARNING, 'SITEAUTH counter write failed: ' . $e->getMessage() ) ;
    }
}

function recordAuthFailure( $siteName ) { bumpAuthFailures( $siteName, FALSE ) ; }
function clearAuthFailures( $siteName ) { bumpAuthFailures( $siteName, TRUE ) ; }

function checkSiteAuthCode( $siteName, $authCode ) {
    $checked = FALSE ;

    if( $siteName && $authCode ) {
        $db = openDb() ;
        $filter = [ 
            'site' => $siteName,
            'code' => $authCode
        ] ;
        $options = [
            'projection' => [ 'ts' => 1 ],
            'sort' => [ '_id' => -1 ],
            'limit' => 1
        ] ;
        $query = new MongoDB\Driver\Query( $filter, $options ) ;
        $recs = $db['main']->executeQuery( 'wwwdrv.authcode', $query )->toArray() ;
        if( count( $recs ) > 0 ) {
            $tsSent = $recs[ 0 ]->ts->toDateTime()->getTimestamp() ;
            $checked = ( ( time() - $tsSent ) < 15 * 60 ) ;
        }
    }
    return $checked ;
}

function forwardDirectorySlash() {
    global $requestUri ;

    header( 'Location: ' 
        . preg_replace( '/^([^\\?]*)(\\?.*)?/', '$1/$2', $requestUri ), TRUE, 301 ) ;
    d2w_putlog( LOG_INFO, 'RESP 301 DirectorySlash' ) ;

    return TRUE ;
}

function getLog() {
    $ok = FALSE ;

    // The route gate is the primary check; re-check here so this stays safe if
    // it is wired to another path later. Token may arrive as /@@log.csv/<tok>.
    global $cmd ;
    $presented = isset( $cmd[ 2 ] ) ? $cmd[ 2 ] : NULL ;
    if( !ops_is_authorized( $presented ) ) {
        d2w_putlog( LOG_WARNING, 'SECURE:ops getLog denied' ) ;
        header( 'WWW-Authenticate: Basic realm="DriveToWeb operator"' ) ;
        header( $_SERVER[ 'SERVER_PROTOCOL' ] . ' 401 Unauthorized', TRUE, 401 ) ;
        header( 'Content-Length: 0' ) ;
        return TRUE ;
    }

    $cLines = isset( $_GET[ 'l' ] ) ? intval( $_GET[ 'l' ] ) : 80 ;
    $cLines = max( 1, min( 20000, $cLines ) ) ;
    $cursor = isset( $_GET[ 'r' ] ) ? max( -1, intval( $_GET[ 'r' ] ) ) : -1 ;
    $filter = isset( $_GET[ 'f' ] ) ? preg_replace( '/[\r\n]/', '', $_GET[ 'f' ] ) : FALSE ;
    
    $logFilePath = '/var/log/d2w/user.log' ;
    
    $fpLog = fopen( $logFilePath, 'r' ) ;
    
    if( $fpLog ) {
        $stat = fstat( $fpLog ) ;
        $cbLogFile = $stat[ 'size' ] ;
    
        $start = $cursor ;
        if( $start < 0 || $start > $cbLogFile ) {
            $start = max( 0, $cbLogFile - $cLines * 128 ) ; // Roughly 128 bytes/line 
        }
    
        fseek( $fpLog, $start ) ;
    
        $range = fread( $fpLog, min( $cbLogFile - $start, 8 * 1024 * 1024 ) ) ;
    
        if( $cursor < 0 ) {
            // No cursor given, so skip first line fragment
            $posLine = strpos( $range, "\n" ) ;
            if( FALSE !== $posLine ) {
                $range = substr( $range, $posLine + 1 ) ;
                $start += $posLine + 1 ;
            }
        }
    
        $posLastLine = -1 ;
        for( $iLine = 0; $iLine < $cLines; $iLine++ ) {
            $posNextLine = strpos( $range, "\n", $posLastLine + 1 ) ;
            if( FALSE !== $posNextLine )
                $posLastLine = $posNextLine ;
            else
                break ;
        }
    
        $range = substr( $range, 0, $posLastLine + 1 ) ;
        $nextCursor = $start + strlen( $range ) ;

        if( $filter ) {
            $lines = explode( "\n", $range ) ;
            $matches = preg_grep( '/' . preg_quote( $filter, '/' ) . '/', $lines ) ;
            $range = implode( "\n", $matches ) ;
        }
    
        $responseData = "$start,$nextCursor,$cbLogFile\n" . $range ;
    
        $cbResponse = strlen( $responseData ) ;
    
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ) ;
        header( 'Pragma: no-cache' ) ;
        header( 'Content-Type: text/plain' ) ;
        header( 'Content-Length: ' . $cbResponse ) ;
        echo $responseData ;

        $ok = TRUE ;
    }
    
    return $ok ;
}

/**
 * addAbuseReport
 * 
 * @param string $apiUser required
 * @param string $params['url'] required, "$URL" OR "$DOMAIN"
 * @param string $params['attack_type'] required, "$ATTACK_TYPE"
 * @param string $params['description'] "This attack targets $BRAND, more information can be found at $INCIDENT_REPORT_LINK"
 * @param string $params['date'] "$DATE"
 * @param string $params['target_brand'] "$BRAND"
 * @param string $params['screenshot_url'] "$SCREENSHOT_URL"
 * 
 * @return string reportId if added
 */
function addAbuseReport( $apiUser, $params ) {

    $reportId = NULL ;

    $db = openDb() ;

    $bulk = new MongoDB\Driver\BulkWrite ;
    //
    // The shipped code used `isset($p['k']) ?? $e['x'] = $p['k']`. isset()
    // never returns NULL, so the right-hand side of ?? was never evaluated
    // and every field below was silently dropped from the stored report.
    //
    $entry = array(
        '_id' => new MongoDB\BSON\ObjectId(),
        'url' => isset( $params[ 'url' ] ) ? $params[ 'url' ] : NULL,
        'attackType' => isset( $params[ 'attack_type' ] ) ? $params[ 'attack_type' ] : NULL,
        'description' => isset( $params[ 'description' ] ) ? $params[ 'description' ] : NULL,
        'date' => isset( $params[ 'date' ] ) ? $params[ 'date' ] : NULL,
        'targetBrand' => isset( $params[ 'target_brand' ] ) ? $params[ 'target_brand' ] : NULL,
        'screenshotUrl' => isset( $params[ 'screenshot_url' ] ) ? $params[ 'screenshot_url' ] : NULL,
    ) ;
    $entry['apiUser'] = $apiUser ;
    $entry['status'] = 'new' ;
    $bulk->insert( $entry ) ;

    try {
        $writeResult = $db['localStore']->executeBulkWrite( 'wwwdrv.abuses', $bulk ) ;
        d2w_putlog( LOG_INFO, 'DB upserted #: ' . $writeResult->getUpsertedCount() ) ;
        if( NULL !== $writeResult )
            $reportId = (string)$entry['_id'] ;

        for( $i = 0; $i < count( $db['remoteStores'] ); $i++ ) {
            $bulk = new MongoDB\Driver\BulkWrite() ;
            $bulk->insert( $entry ) ;
            $db['remoteStores'][ $i ]->executeBulkWrite( 'wwwdrv.abuses', $bulk ) ;
        }
    }
    catch( MongoDB\Driver\Exception\Exception $e ) {
        d2w_putlog( LOG_CRIT, 'DB exception: ' . $e->getMessage() ) ;
    }
    if( !$reportId )
        d2w_putlog( LOG_CRIT, 'DB addAbuseReport failed' ) ;

    return $reportId ;
}

?>
