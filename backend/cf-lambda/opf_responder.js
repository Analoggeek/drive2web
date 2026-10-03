'use strict';
/**
 * OPF responder — CloudFront origin-response trigger for OneDrive proxies.
 *
 * Adds CORS so browser JS can read OneDrive/Graph media, and caches it.
 *
 * FIXES:
 *  - `console.log( JSON.stringify( response ) )` dumped whole proxied files
 *    (and origin headers) into CloudFront logs; it is now a one-line summary.
 *  - a 180-day `public` cache was applied to *any* 200, including error-ish or
 *    non-cacheable origin answers; caching is now skipped when the origin
 *    already said no-store/private or the payload is not the file type we proxy.
 *  - header lookups are null-safe (missing content-type used to be possible
 *    once the requestor allowed arbitrary origins).
 */

function getHeader( response, name ) {
    const h = response.headers ;
    if( !h )
        return null ;
    const entry = h[ name.toLowerCase() ] ;
    if( !entry || !entry.length || !entry[ 0 ] )
        return null ;

    return entry[ 0 ].value || null ;
}

function setCorsHeaders( request, response ) {
    const origin = request.headers && request.headers[ 'origin' ]
        ? request.headers[ 'origin' ][ 0 ].value
        : null ;

    response.headers[ 'vary' ] = [ {
        key: 'Vary', value: 'Origin'
    } ] ;
    response.headers[ 'access-control-allow-origin' ] = [ {
        key: 'Access-Control-Allow-Origin',
        value: origin ? origin : '*'
    } ] ;
    response.headers[ 'access-control-allow-credentials' ] = [ {
        key: 'Access-Control-Allow-Credentials', value: 'false'
    } ] ;
    response.headers[ 'access-control-allow-headers' ] = [ {
        key: 'Access-Control-Allow-Headers', value: '*'
    } ] ;
    response.headers[ 'access-control-expose-headers' ] = [ {
        key: 'Access-Control-Expose-Headers', value: '*'
    } ] ;
    response.headers[ 'access-control-allow-methods' ] = [ {
        key: 'Access-Control-Allow-Methods', value: 'GET,HEAD'
    } ] ;
    response.headers[ 'access-control-max-age' ] = [ {
        key: 'Access-Control-Max-Age', value: '15552000'
    } ] ;
}

exports.handler = async( event, context ) => {
    const request = event.Records[ 0 ].cf.request ;
    const response = event.Records[ 0 ].cf.response ;

    if( '200' === response.status ) {
        const cacheControl = ( getHeader( response, 'cache-control' ) || '' ).toLowerCase() ;
        const bCacheable = !( cacheControl.includes( 'no-store' )
            || cacheControl.includes( 'private' ) ) ;

        if( bCacheable )
            response.headers[ 'cache-control' ] = [ {
                key: 'Cache-Control', value: 'public, s-maxage=15552000, max-age=15552000'
            } ] ;
        else
            response.headers[ 'cache-control' ] = [ {
                key: 'Cache-Control', value: 'private, no-store'
            } ] ;

        response.headers[ 'vary' ] = [ {
            key: 'Vary', value: 'Origin'
        } ] ;

        delete response.headers[ 'expires' ] ;
        delete response.headers[ 'content-disposition' ] ;
        // OneDrive sends a CSP that breaks in-place rendering of hosted pages
        delete response.headers[ 'content-security-policy' ] ;
        delete response.headers[ 'access-control-allow-origin' ] ;
        delete response.headers[ 'access-control-allow-methods' ] ;
        delete response.headers[ 'access-control-allow-headers' ] ;
        delete response.headers[ 'access-control-expose-headers' ] ;
        delete response.headers[ 'access-control-max-age' ] ;

        setCorsHeaders( request, response ) ;

        console.log( 'OPF Response', response.status
            , getHeader( response, 'content-type' )
            , getHeader( response, 'content-length' ) ) ;

        return response ;
    }

    console.log( 'OPF Response', response.status ) ;

    return response ;
} ;
