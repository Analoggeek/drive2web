'use strict';
/**
 * GUF responder — CloudFront origin-response trigger for cdn-of.*.
 *
 * Caches successful Drive media responses and, on 401/403, bounces the viewer
 * back to the DriveToWeb origin so the token is refreshed and the request
 * retried.
 *
 * ROBUSTNESS (fixed here): the old code dereferenced
 *   response.headers['www-authenticate'][0].value
 *   response.headers['content-type'][0].value
 * whenever those headers were absent. A single 401 from Google without
 * `WWW-Authenticate` (revoked token, IP block, quota) or a 200 without
 * `Content-Type` threw a TypeError, and CloudFront served 503/548 instead of
 * the file — for every cached-miss of that URL.
 *
 * Also: the response JSON (which can include origin headers) is no longer
 * logged verbatim.
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

function isExpiredTokenResponse( response ) {
    const wwwAuth = getHeader( response, 'www-authenticate' ) ;
    if( !wwwAuth )
        return false ;

    return wwwAuth.includes( 'Bearer' )
        && wwwAuth.includes( 'realm="https://accounts.google.com/"' )
        && wwwAuth.includes( 'error=invalid_token' ) ;
}

exports.handler = async( event, context ) => {
    const response = event.Records[ 0 ].cf.response ;
    const request = event.Records[ 0 ].cf.request ;

    if( '200' === response.status ) {
        response.headers[ 'cache-control' ] = [ {
            key: 'Cache-Control', value: 'public, s-maxage=15552000, max-age=15552000'
        } ] ;

        delete response.headers[ 'content-disposition' ] ;
        delete response.headers[ 'expires' ] ;

        // SVGs are usually served as text/xml; upgrade so <img> can render
        // them when the viewer asked for an image
        const accept = request.headers && request.headers[ 'accept' ]
            ? request.headers[ 'accept' ][ 0 ].value : '' ;
        if( accept && accept.includes( 'image/' )
            && 'text/xml' === getHeader( response, 'content-type' ) )
            response.headers[ 'content-type' ] = [ {
                key: 'Content-Type', value: 'image/svg+xml'
            } ] ;

        setCorsHeaders( request, response ) ;
    }
    else if( request.headers[ 'referer' ]
        && ( '403' === response.status || isExpiredTokenResponse( response ) ) ) {

        response.status = '302' ;
        response.statusDescription = 'Found' ;

        response.headers[ 'location' ] = [ {
            key: 'Location',
            value: request.headers[ 'referer' ][ 0 ].value
        } ] ;
        response.headers[ 'cache-control' ] = [ {
            key: 'Cache-Control',
            value: 'no-store, stale-if-error=0' // avoid caching an infinite retry loop
        } ] ;

        delete response.headers[ 'content-type' ] ;
        delete response.headers[ 'content-encoding' ] ;

        setCorsHeaders( request, response ) ;
    }

    return response ;
} ;
