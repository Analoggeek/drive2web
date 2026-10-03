<?php

/**
 * DriveToWeb deployment self-audit.
 *
 *   php tools/d2w-audit.php [ --root /var/www/drv_tw ] [ --json ] [ --quiet ]
 *
 * --root takes either the deployment folder (the one holding html/ and the
 * nginx_* configs, e.g. /var/www/drv_tw) or the docroot itself
 * (/var/www/drv_tw/html).
 *   php tools/d2w-audit.php --self-test        # used by CI
 *
 * Checks the things that silently break an install (placeholders, permissions,
 * a log folder nobody can enter, an nginx socket that does not exist, a
 * blacklist with a broken regex) without needing a database or a browser.
 *
 * Exit status: 0 = no errors, 1 = self-test failed, 2 = errors found, 3 = usage.
 */

if( 'cli' !== PHP_SAPI ) {
    print "This tool is meant for the command line.\n" ;
    exit( 3 ) ;
}

$ROOT = null ;
$bJson = FALSE ;
$bQuiet = FALSE ;
$bSelfTest = FALSE ;

for( $i = 1; $i < $argc; $i++ ) {
    switch( $argv[ $i ] ) {
    case '--root': $ROOT = $argv[ ++$i ] ?? null ; break ;
    case '--json': $bJson = TRUE ; $bQuiet = TRUE ; break ;
    case '--quiet': $bQuiet = TRUE ; break ;
    case '--self-test': $bSelfTest = TRUE ; break ;
    case '--help': case '-h':
        print "usage: php tools/d2w-audit.php [--root DIR] [--json] [--quiet] [--self-test]\n" ;
        exit( 0 ) ;
    default:
        fwrite( STDERR, "unknown option: {$argv[$i]}\n" ) ;
        exit( 3 ) ;
    }
}

$repoRoot = dirname( __DIR__ ) ;
$base = $ROOT ? rtrim( $ROOT, '/' ) : $repoRoot . '/backend/html' ;

// Accept both the deployment folder and the docroot, and remember where the
// nginx configs are: on a server that is /var/www/drv_tw, in the repo it is
// backend/. Auditing the operator's *edited* copy is the point of --root.
$confDir = $ROOT ? $base : $repoRoot . '/backend' ;
if( $ROOT && !is_file( $base . '/drvtw_private/drvtw_util.php' )
    && is_file( $base . '/html/drvtw_private/drvtw_util.php' ) ) {
    $base .= '/html' ;
}

// ---------------------------------------------------------------- self-test

if( $bSelfTest ) {
    require __DIR__ . '/../backend/html/drvtw_private/drvtw_util.php' ;
    exit( d2w_audit_selftest() ) ;
}

// ------------------------------------------------------------------ loading

$util = $base . '/drvtw_private/drvtw_util.php' ;
if( !is_file( $util ) ) {
    fwrite( STDERR, "not a DriveToWeb tree: $util missing"
        . " (use --root /var/www/drv_tw or .../drv_tw/html)\n" ) ;
    exit( 3 ) ;
}
require $util ;

$credsFile = $base . '/drvtw_private/drvtw_creds.php' ;
$usedExample = FALSE ;
if( is_file( $credsFile ) )
    require $credsFile ;
else {
    $credsFile = $base . '/drvtw_private/drvtw_creds.example.php' ;
    if( !is_file( $credsFile ) ) {
        fwrite( STDERR, "no creds file under $base/drvtw_private\n" ) ;
        exit( 3 ) ;
    }
    require $credsFile ;
    $usedExample = TRUE ;
}

$keys = array( 'd2wBaseDomain', 'd2wApiHost', 'd2wWebHost', 'd2wSiteSuffix', 'd2wCdnHosts',
    'd2wContactEmail', 'd2wWidgetScript', 'd2wLogPrivacy', 'd2wAppCSP', 'd2wContentCSP',
    'sessionSecret', 'opsSecret', 'opsUser', 'opsPass', 'siteNameSecret', 'authCodeMaxFails',
    'gdClientId', 'gdClientSecret', 'gdApiKey', 'odClientId', 'odClientSecret',
    'mongoLocalConnection', 'mongoRemoteConnections', 'mongoTlsLocal', 'mongoTlsCAFile',
    'mongoTlsAllowSelfSigned', 'smtpHost', 'smtpPort', 'sesSmtpUser', 'sesSmtpPassword',
    'mailFrom', 'mailFromName', 'corsAllowedOrigins', 'requireApprovedSites',
    'allowCreatePostDb', 'apiTokens' ) ;

$cfg = array() ;
foreach( $keys as $k )
    $cfg[ $k ] = isset( $$k ) ? $$k : NULL ;

// ----------------------------------------------------------------- findings

$env = array(
    'phpVersion' => PHP_VERSION ,
    'extensions' => array( 'mongodb', 'mbstring', 'curl', 'dom', 'json', 'fileinfo' ) ,
    'loaded' => array_values( array_filter( array(
        'mongodb' => extension_loaded( 'mongodb' ) ? 'mongodb' : '' ,
        'mbstring' => extension_loaded( 'mbstring' ) ? 'mbstring' : '' ,
        'curl' => extension_loaded( 'curl' ) ? 'curl' : '' ,
        'dom' => extension_loaded( 'dom' ) ? 'dom' : '' ,
        'json' => extension_loaded( 'json' ) ? 'json' : '' ,
        'fileinfo' => extension_loaded( 'fileinfo' ) ? 'fileinfo' : '' ,
    ) ) ) ,
    'credsFile' => array( 'exists' => !$usedExample, 'path' => $credsFile ) ,
    'credsPerms' => ( is_file( $credsFile ) && !$usedExample )
        ? ( fileperms( $credsFile ) & 0777 ) : NULL ,
    'logDir' => NULL ,
    'leftoverHosts' => array() ,
) ;

$logDir = getenv( 'D2W_LOG_FOLDER' ) ?: '/var/log/d2w' ;
// Filesystem checks only describe *this* machine, so they are only errors when
// the operator explicitly pointed the audit at a deployment (--root).
$level = $ROOT ? 'error' : 'warn' ;
$env[ 'logDir' ] = array(
    'path' => $logDir ,
    'exists' => is_dir( $logDir ) ,
    'writable' => is_dir( $logDir ) && is_writable( $logDir ) ,
    'searchable' => is_dir( $logDir ) && ( fileperms( $logDir ) & 0111 ) !== 0 ,
) ;

// Anything still naming the old hosted service, outside the documented default
foreach( array( $base . '/inline' ) as $dir ) {
    if( !is_dir( $dir ) )
        continue ;
    foreach( glob( $dir . '/*' ) as $f )
        if( preg_match( '#https?://[a-z0-9.\-]*drv\.tw#i', (string)file_get_contents( $f ) ) )
            $env[ 'leftoverHosts' ][] = basename( $f ) ;
}

$problems = d2w_audit_problems( $cfg, $env ) ;

if( $usedExample )
    $problems[] = array( 'level' => 'warn', 'key' => 'drvtw_creds.php',
        'msg' => 'no drvtw_creds.php under ' . dirname( $credsFile ) . ' - audited the example file' ) ;

// --- runtime extras the pure function cannot see
$sapi = PHP_SAPI ;
if( 'cli' === $sapi && $ROOT ) {
    // FPM socket + real_ip trust, from the configs the operator actually installed
    $nginxFiles = glob( $confDir . '/nginx_*' ) ;
    $fromRepo = FALSE ;
    if( !$nginxFiles ) {
        $nginxFiles = glob( $repoRoot . '/backend/nginx_*' ) ;
        $fromRepo = TRUE ;
    }
    foreach( $nginxFiles as $nginx ) {
        $label = basename( $nginx ) . ( $fromRepo ? ' (repo copy)' : '' ) ;
        $conf = (string)file_get_contents( $nginx ) ;
        if( preg_match_all( '#fastcgi_pass\s+unix:([^;]+);#', $conf, $m ) )
            foreach( $m[ 1 ] as $sock ) {
                $sock = trim( $sock ) ;
                if( false !== strpos( $sock, '$' ) )
                    continue ;                       // template value
                if( !file_exists( $sock ) )
                    $problems[] = array( 'level' => 'error', 'key' => $label,
                        'msg' => "fastcgi_pass points at $sock, which does not exist on this host" ) ;
            }

        if( preg_match( '#set_real_ip_from\s+0\.0\.0\.0/0\s*;#', $conf ) )
            $problems[] = array( 'level' => 'error', 'key' => $label,
                'msg' => 'set_real_ip_from 0.0.0.0/0 lets visitors spoof their source address' ) ;
    }
}

// --- blacklist lint
$bl = $base . '/drvtw_private/drvtw_blacklist.php' ;
if( is_file( $bl ) ) {
    foreach( d2w_audit_blacklist( $bl ) as $p )
        $problems[] = $p ;
} else
    $problems[] = array( 'level' => 'error', 'key' => 'drvtw_blacklist.php', 'msg' => 'missing' ) ;

// --- PHP lint of the tree itself (cheap, catches a half-copied deploy)
$lintFail = array() ;
foreach( array( $base . '/index.php', $base . '/drvtw_private/drvtw_log.php',
    $base . '/drvtw_private/drvtw_util.php' ) as $f ) {
    if( !is_file( $f ) || !function_exists( 'exec' ) )
        continue ;
    $out = array() ;
    @exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $f ) . ' 2>&1', $out, $rc ) ;
    if( 0 !== $rc )
        $lintFail[] = basename( $f ) . ': ' . trim( implode( ' ', $out ) ) ;
}
foreach( $lintFail as $f )
    $problems[] = array( 'level' => 'error', 'key' => 'php -l', 'msg' => $f ) ;

// ------------------------------------------------------------------- output

$nError = count( array_filter( $problems, fn( $p ) => 'error' === $p[ 'level' ] ) ) ;
$nWarn = count( array_filter( $problems, fn( $p ) => 'warn' === $p[ 'level' ] ) ) ;

if( $bJson ) {
    print json_encode( array(
        'ok' => 0 === $nError ,
        'errors' => $nError , 'warnings' => $nWarn ,
        'php' => PHP_VERSION , 'root' => $base ,
        'problems' => $problems ,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ;
    exit( $nError ? 2 : 0 ) ;
}

printf( "DriveToWeb audit (%s)\nPHP %s%s\n\n", $base, PHP_VERSION,
    extension_loaded( 'mongodb' ) ? '' : '  [ext-mongodb NOT loaded]' ) ;

foreach( $problems as $p )
    printf( "  %-6s %-24s %s\n", strtoupper( $p[ 'level' ] ), $p[ 'key' ], $p[ 'msg' ] ) ;

$shown = array_filter( $problems, fn( $p ) => 'info' !== $p[ 'level' ] ) ;
if( !$shown )
    print "  no problems found\n" ;

if( !$bQuiet )
    printf( "\n%d error(s), %d warning(s). Errors block serving; warnings are judgement calls.\n",
        $nError, $nWarn ) ;

exit( $nError ? 2 : 0 ) ;

// ------------------------------------------------------------------ helpers

/**
 * Structural check of the blacklist file.
 *
 * Shape (as consumed by d2w_match_blacklist(), all literal strpos matches -
 * there are no regular expressions in this file, which is why nothing here
 * "compiles" or "breaks" syntactically):
 *   $blacklist_signatures = array(
 *       '<needle>',                                        // simple block
 *       array( '<key>' | array('<key>', ...), array(...) ) // key + sub-patterns
 *   ) ;
 *   $blacklist_copyright = array( array( $site, $path, $needle ), ... ) ;
 */
function d2w_audit_blacklist( $file ) {
    $problems = array() ;

    $blacklist_signatures = $blacklist_copyright = $blacklist_phishing = NULL ;
    include $file ;   // the file only assigns arrays

    if( !is_array( $blacklist_signatures ) ) {
        $problems[] = array( 'level' => 'error', 'key' => 'blacklist',
            'msg' => '$blacklist_signatures is not an array' ) ;
        return $problems ;
    }

    $seen = array() ;
    $nNeedles = 0 ;
    $nGroups = 0 ;
    $broad = 0 ;
    $shortKeys = 0 ;

    foreach( array_values( $blacklist_signatures ) as $i => $sign ) {
        if( is_string( $sign ) ) {
            $nNeedles++ ;
            if( '' === trim( $sign ) )
                $problems[] = array( 'level' => 'error', 'key' => 'blacklist',
                    'msg' => "entry $i is an empty string: it would match every page" ) ;
            else if( isset( $seen[ strtolower( $sign ) ] ) )
                $problems[] = array( 'level' => 'warn', 'key' => 'blacklist',
                    'msg' => sprintf( 'needle "%s" duplicated (entries %s and %d)',
                        $sign, $seen[ strtolower( $sign ) ], $i ) ) ;
            else
                $seen[ strtolower( $sign ) ] = $i ;
            continue ;
        }

        if( !is_array( $sign ) ) {
            $problems[] = array( 'level' => 'error', 'key' => 'blacklist',
                'msg' => "entry $i is neither string nor array (" . gettype( $sign ) . ')' ) ;
            continue ;
        }

        $nGroups++ ;

        if( !isset( $sign[ 0 ] ) || ( !is_string( $sign[ 0 ] ) && !is_array( $sign[ 0 ] ) ) ) {
            $problems[] = array( 'level' => 'error', 'key' => 'blacklist',
                'msg' => "entry $i has no usable key (expected string or array of strings)" ) ;
            continue ;
        }
        if( !isset( $sign[ 1 ] ) || !is_array( $sign[ 1 ] ) || 0 === count( $sign[ 1 ] ) ) {
            $problems[] = array( 'level' => 'warn', 'key' => 'blacklist',
                'msg' => "entry $i has a key but no sub-patterns: it can never match" ) ;
            continue ;
        }

        foreach( (array) $sign[ 0 ] as $key )
            if( is_string( $key ) && strlen( $key ) < 6 )
                $shortKeys++ ;

        foreach( $sign[ 1 ] as $j => $pat ) {
            if( !is_string( $pat ) ) {
                $problems[] = array( 'level' => 'error', 'key' => 'blacklist',
                    'msg' => sprintf( 'entry %d pattern %d is not a string', $i, $j ) ) ;
                continue ;
            }
            $nNeedles++ ;
            if( '' === trim( $pat ) ) {
                $problems[] = array( 'level' => 'error', 'key' => 'blacklist',
                    'msg' => sprintf( 'entry %d pattern %d is empty: the group matches every page',
                        $i, $j ) ) ;
                continue ;
            }
            $k = $i . '|' . strtolower( $pat ) ;
            if( isset( $seen[ $k ] ) )
                $problems[] = array( 'level' => 'warn', 'key' => 'blacklist',
                    'msg' => sprintf( 'duplicate pattern "%s" inside entry %d', $pat, $i ) ) ;
            $seen[ $k ] = TRUE ;

            // A short, generic needle turns the group into a site-wide block
            if( strlen( $pat ) < 6 && preg_match( '/^[a-z ]+$/i', $pat ) )
                $broad++ ;
        }
    }

    if( isset( $blacklist_copyright ) && is_array( $blacklist_copyright ) ) {
        foreach( $blacklist_copyright as $i => $rec ) {
            if( !is_array( $rec ) || 3 !== count( $rec ) )
                $problems[] = array( 'level' => 'error', 'key' => 'blacklist',
                    'msg' => "copyright entry $i must be [ site, path, needle ]" ) ;
            else foreach( $rec as $f => $v )
                if( !is_string( $v ) )
                    $problems[] = array( 'level' => 'error', 'key' => 'blacklist',
                        'msg' => "copyright entry $i field $f is not a string" ) ;
        }
        $problems[] = array( 'level' => 'info', 'key' => 'copyright list',
            'msg' => count( $blacklist_copyright ) . ' entries - they name sites on the old '
                . 'service, so replace them with your own takedown records' ) ;
    }

    if( $broad )
        $problems[] = array( 'level' => 'warn', 'key' => 'blacklist',
            'msg' => "$broad pattern(s) are shorter than 6 plain letters - combined with their "
                . 'group key they block a lot; review for false positives' ) ;
    if( $shortKeys )
        $problems[] = array( 'level' => 'warn', 'key' => 'blacklist',
            'msg' => "$shortKeys group key(s) are shorter than 6 chars" ) ;

    $problems[] = array( 'level' => 'info', 'key' => 'blacklist',
        'msg' => sprintf( '%d entries (%d groups), %d needles',
            count( $blacklist_signatures ), $nGroups, $nNeedles ) ) ;

    return $problems ;
}

/**
 * Self-check of the audit logic itself (also run by php tests/run-tests.php).
 */
function d2w_audit_selftest() {
    $bad = d2w_audit_problems( array(
        'd2wBaseDomain' => 'your.domain', 'd2wApiHost' => 'api.your.domain',
        'd2wWebHost' => 'www.your.domain', 'sessionSecret' => 'REPLACE_WITH_RANDOM_HEX',
        'odClientId' => 'YOUR_MICROSOFT_CLIENT_ID', 'mongoLocalConnection' => 'localhost:27017',
    ), array( 'phpVersion' => '7.4.0', 'extensions' => array( 'mongodb' ),
        'loaded' => array(), 'credsPerms' => 0644,
        'logDir' => array( 'path' => '/var/log/d2w', 'exists' => TRUE, 'writable' => TRUE,
            'searchable' => FALSE ) ) ) ;

    $good = d2w_audit_problems( array(
        'd2wBaseDomain' => 'example.org', 'd2wApiHost' => 'api.example.org',
        'd2wWebHost' => 'www.example.org', 'sessionSecret' => '9f3c1a77be5d40c8a1d6f2e0b7c48a91',
        'opsSecret' => 'e2b7c48a91f3c1a77be5d40c8a1d6f2e', 'odClientId' => '11111111-2222-3333-4444-555555555555',
        'odClientSecret' => 'x', 'gdClientId' => 'x.apps.googleusercontent.com',
        'gdClientSecret' => 'y', 'mongoLocalConnection' => 'mongodb://u:p@%2Ftmp%2Fmongodb-27017.sock/db',
        'mongoRemoteConnections' => array( 'mongodb+srv://u:p@c.example.mongodb.net/db' ),
        'mailFrom' => 'no-reply@example.org', 'requireApprovedSites' => TRUE,
    ) ) ;

    $needles = array( 'd2wBaseDomain', 'sessionSecret', 'odClientId', 'mongoLocalConnection',
        'php', 'ext-mongodb', 'log folder' ) ;
    $missing = array() ;
    foreach( $needles as $n )
        if( !in_array( $n, array_column( $bad, 'key' ), TRUE ) )
            $missing[] = $n ;

    printf( "audit reports %d problem(s) for a placeholder deployment\n", count( $bad ) ) ;
    if( $missing ) {
        printf( "FAIL: audit missed %s\n", implode( ', ', $missing ) ) ;
        return 1 ;
    }
    $badErrors = array_filter( $bad, fn( $p ) => 'error' === $p[ 'level' ] ) ;
    if( count( $badErrors ) < 5 ) {
        print "FAIL: audit should report at least 5 errors for that deployment\n" ;
        return 1 ;
    }
    $goodErrors = array_filter( $good, fn( $p ) => 'error' === $p[ 'level' ] ) ;
    if( $goodErrors ) {
        printf( "FAIL: a complete deployment reported errors: %s\n",
            json_encode( $goodErrors ) ) ;
        return 1 ;
    }
    print "ok: audit flags broken config and stays quiet for a complete one\n" ;
    return 0 ;
}
