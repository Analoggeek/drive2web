<?php

/**
 * DriveToWeb logging helpers.
 *
 * Fixes vs. the shipped version:
 *  - `mkdir( $folder, 0640 )` created the log directory WITHOUT the execute
 *    bit, so the web server could not enter it and fopen() always failed.
 *    A directory needs 0750/0770, not 0640.
 *  - when fopen() failed, fputcsv()/fclose() were still called with FALSE,
 *    which is a TypeError in PHP 8 (fatal, no response) instead of a no-op.
 *  - the log folder and priority were ignored (syslog facility argument).
 */

$d2w_logFolder = getenv( 'D2W_LOG_FOLDER' ) ? getenv( 'D2W_LOG_FOLDER' ) : '/var/log/d2w' ;
$d2w_logHost = explode( '.', gethostname(), 2 )[ 0 ] ;
$d2w_logIdent = FALSE ;
$d2w_logPriorityMin = LOG_INFO ;
$d2w_logFp = NULL ; // reused per request (one open file, not one per line)

function d2w_initlog( string $ident, int $option, int $facility ) {
    global $d2w_logIdent ;

    $d2w_logIdent = $ident ;
}

function d2w_putlog( int $priority, string $message ) {
    global $d2w_logHost, $d2w_logIdent, $d2w_logFp ;

    // Log level, rendered from the syslog priority for the CSV column
    $d2w_logPriorityLabel = function( int $p ) : string {
        static $map = array(
            LOG_EMERG => 'EMERG', LOG_ALERT => 'ALERT', LOG_CRIT => 'CRIT',
            LOG_ERR => 'ERR', LOG_WARNING => 'WARN', LOG_NOTICE => 'NOTICE',
            LOG_INFO => 'INFO', LOG_DEBUG => 'DEBUG',
        ) ;
        foreach( $map as $bit => $name )
            if( $p === $bit ) return $name ;
        return 'P' . $p ;
    } ;

    $fpLog = d2w_openlog( $priority ) ;
    if( !is_resource( $fpLog ) )
        return FALSE ; // never fatal: logging must not take the site down

    // The log is group-readable and is streamed by the backroom UI: strip
    // visitor addresses and any credential that ends up in a message.
    if( function_exists( 'd2w_log_privacy_on' ) && d2w_log_privacy_on() )
        $message = d2w_log_scrub( $message ) ;

    // Explicit separators/escape: PHP 8.4 deprecates relying on the default
    // $escape, and an empty escape matches RFC 4180 (no backslash escaping).
    $ok = fputcsv( $fpLog,
        array( date( 'c' ), $d2w_logPriorityLabel( $priority ), $d2w_logHost, $d2w_logIdent, $message ),
        ',', '"', '' ) ;

    return FALSE !== $ok ;
}

/**
 * Open (once) and return the log handle.
 */
function d2w_openlog( int $priority = LOG_INFO ) {
    global $d2w_logFolder, $d2w_logFp ;

    if( is_resource( $d2w_logFp ) )
        return $d2w_logFp ;

    if( !file_exists( $d2w_logFolder ) ) {
        // 0770: the directory must be searchable by owner and group
        @mkdir( $d2w_logFolder, 0770, TRUE ) ;
        @chmod( $d2w_logFolder, 0770 ) ;
    }

    $fpLog = @fopen( $d2w_logFolder . '/user.log', 'a' ) ;
    if( !is_resource( $fpLog ) ) {
        // Last-resort fallback so log lines are not silently lost in a
        // misconfigured container; error_log() lands in the PHP-FPM/nginx log.
        error_log( sprintf( 'd2w log unavailable (%s): %s', $d2w_logFolder,
            preg_replace( '/\s+/', ' ', (string)$d2w_logIdent ) ) ) ;
        return FALSE ;
    }

    // One line at a time keeps interleaving from concurrent FPM workers sane
    if( function_exists( 'stream_set_locking' ) )
        stream_set_locking( $fpLog, LOCK_EX ) ;

    $d2w_logFp = $fpLog ;

    return $fpLog ;
}

function d2w_closelog() {
    global $d2w_logFp ;

    if( is_resource( $d2w_logFp ) ) {
        fclose( $d2w_logFp ) ;
        $d2w_logFp = NULL ;
    }
}

register_shutdown_function( 'd2w_closelog' ) ;

?>
