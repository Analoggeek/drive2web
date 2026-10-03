/*
 * DriveToWeb site behaviour (loaded by every static page).
 *
 * 1. Fills the {{CONTACT}} placeholders in the static HTML with the address
 *    configured in inc/config.js — the pages are served as-is, so the
 *    substitution that index.php does for inline/ pages happens here instead.
 * 2. Loads Google Analytics only when the deployment opts in with its own
 *    measurement id (the shipped build pointed at the original author's
 *    property, which would report your visitors to someone else).
 * 3. Registers the viewer service worker, as before.
 */
(function (window, document) {
    'use strict' ;

    var cfg = window.D2W = window.D2W || { } ;

    // Static pages cannot be templated server-side, so they carry {{PLACEHOLDER}}
    // markers that are resolved here from inc/config.js. An unconfigured value
    // hides the link instead of pointing at the original hosted service.
    var VALUES = {
        '{{CONTACT}}': function() { return cfg.contactEmail ; },
        '{{DOCS}}': function() { return cfg.docsUrl ; },
    } ;
    var KEYS = Object.keys( VALUES ) ;

    function hasPlaceholder( text ) {
        for( var i = 0 ; i < KEYS.length ; i++ )
            if( -1 !== text.indexOf( KEYS[ i ] ) )
                return KEYS[ i ] ;
        return null ;
    }

    function valueOf( key ) {
        var v = VALUES[ key ] ? VALUES[ key ]() : null ;
        return ( v && 'null' !== v && !/^YOUR_/.test( v ) ) ? v : null ;
    }

    function fillPlaceholders() {
        function fix( node ) {
            if( 1 === node.nodeType ) {
                if( 'A' === node.tagName ) {
                    var href = node.getAttribute( 'href' ) || '' ;
                    var key = hasPlaceholder( href ) ;
                    if( key ) {
                        var v = valueOf( key ) ;
                        if( v ) {
                            node.setAttribute( 'href',
                                '{{CONTACT}}' === key
                                    ? 'mailto:' + v
                                    : href.replace( key, v ) ) ;
                        } else {
                            // not configured: drop the anchor, keep its label
                            var span = document.createElement( 'span' ) ;
                            span.className = 'd2w-unconfigured' ;
                            while( node.firstChild )
                                span.appendChild( node.firstChild ) ;
                            node.parentNode.replaceChild( span, node ) ;
                            return ;
                        }
                    }
                }
                for( var i = 0, kids = node.children ; i < kids.length ; i++ )
                    fix( kids[ i ] ) ;
            } else if( 3 === node.nodeType ) {
                var key2 = hasPlaceholder( node.data ) ;
                if( key2 )
                    node.data = node.data.split( key2 )
                        .join( valueOf( key2 ) || '(not configured on this site)' ) ;
            }
        }

        if( document.body )
            fix( document.body ) ;
    }

    function loadAnalytics() {
        // Use your own property. The shipped build hardcoded the original
        // author's, which would send your visitors' page views to them.
        var id = cfg.analyticsId ;
        if( !id || 0 === String( id ).indexOf( 'YOUR_' ) )
            return ;

        window.dataLayer = window.dataLayer || [ ] ;
        window.gtag = window.gtag || function() { window.dataLayer.push( arguments ) ; } ;
        window.gtag( 'js', new Date() ) ;
        window.gtag( 'config', id ) ;

        // keep the optional session id used by the viewer pages
        var qs = window.location.search ;
        if( qs ) {
            var sid = new URLSearchParams( qs ).get( 's' ) ;
            if( sid )
                window.gtag( 'set', 'user_properties', { 'd2w_sid': sid } ) ;
        }

        var s = document.createElement( 'script' ) ;
        s.async = 1 ;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( id ) ;
        ( document.head || document.documentElement ).appendChild( s ) ;
    }

    function registerServiceWorker() {
        // Viewer pages (3D/PDF/image viewers) need the worker; other pages do not
        if( !( window.TDV || window.PDFJS || window.acute3d || window.pannellum
            || window.THREE || window.PIXI ) || !navigator.serviceWorker )
            return ;
        navigator.serviceWorker.register( 'd2w_sw.js' ).then( function( r ) { r.update() ; } ) ;
    }

    function start() {
        try { fillPlaceholders() ; } catch( e ) { }
        try { loadAnalytics() ; } catch( e ) { }
        try { registerServiceWorker() ; } catch( e ) { }
    }

    if( 'loading' !== document.readyState )
        start() ;
    else
        document.addEventListener( 'DOMContentLoaded', start ) ;
} )( window, document ) ;
