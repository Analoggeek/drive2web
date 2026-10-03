'use strict' ;
/**
 * GUF requestor (Google User File) — CloudFront origin-request trigger.
 *
 * Rewrites /_guf/<siteName>/<fileId> into a Drive API media request and
 * injects the owner's OAuth token (passed reversed in ?ra=) as an
 * Authorization header.
 *
 * SECURITY / ROBUSTNESS (fixed here):
 *  - request.uri arrives URL-DECODED at the edge, and the fileId was spliced
 *    into request.uri without re-encoding: `/_guf/site/..%2f..%2fanything`
 *    became `/drive/v3/files/../../anything` (path surgery on the googleapis
 *    origin). File IDs are now validated and re-encoded.
 *  - the full request — including the freshly injected `Authorization` header
 *    — was console.log()ed, writing owner OAuth bearer tokens into
 *    CloudFront/Lambda logs. They are redacted now.
 *  - only GET/HEAD are proxied.
 */
const querystring = require( 'querystring' ) ;

const FILE_ID_RE = /^[A-Za-z0-9_\-]{10,120}$/ ;

function deny( status, message ) {
    console.log( 'GUF denied:', message ) ;

    return {
        status: String( status ) ,
        statusDescription: status === 403 ? 'Forbidden' : 'Bad Request' ,
        headers: {
            'content-type': [ { key: 'Content-Type', value: 'text/plain' } ] ,
            'cache-control': [ { key: 'Cache-Control', value: 'no-store' } ] ,
        } ,
        body: message ,
    } ;
}

function redacted( request ) {
    try {
        const copy = JSON.parse( JSON.stringify( request ) ) ;
        for( const name of [ 'authorization', 'cookie', 'x-goog-api-key' ] )
            if( copy.headers && copy.headers[ name ] )
                copy.headers[ name ] = [ { key: name, value: '[redacted]' } ] ;
        if( copy.origin && copy.origin.custom && copy.origin.custom.customHeaders )
            copy.origin.custom.customHeaders = '[redacted]' ;

        return JSON.stringify( copy ) ;
    }
    catch( e ) {
        return '[unserializable]' ;
    }
}

exports.handler = async( event, context ) => {
    const request = event.Records[ 0 ].cf.request ;

    if( request.method !== 'GET' && request.method !== 'HEAD' )
        return deny( 403, 'Method not allowed' ) ;

    const uri = request.uri ;
    const params = querystring.parse( request.querystring ) ;
    const match = uri.match( /^\/_guf\/([^/]+)\/(.+)$/ ) ;

    if( !match )
        return deny( 400, 'Malformed request' ) ;

    const idFile = match[ 2 ] ;

    if( !FILE_ID_RE.test( idFile ) )
        return deny( 400, 'Malformed file id' ) ;

    // No Origin header means this is a top-level navigation, not a fetch/XHR:
    // hand the viewer the plain Drive download link instead of proxying.
    if( !request.headers[ 'origin' ] ) {
        return {
            status: '302' ,
            statusDescription: 'Found' ,
            headers: {
                'vary': [ { key: 'Vary', value: 'Origin' } ] ,
                'location': [ { key: 'Location', value: 'https://drive.google.com/uc?id=' + idFile } ] ,
                'cache-control': [ { key: 'Cache-Control', value: 'public, s-maxage=604800, max-age=0' } ] ,
            } ,
        } ;
    }

    // Referer tracks the original request URI (without querystring) so the
    // responder can bounce back to the origin in case of 401/403
    // Use the domain the viewer actually called, so a deployment that renames
    // its CDN host (or serves cdn2-of.*) still bounces back correctly
    const cdnHost = request.headers[ 'host' ] && request.headers[ 'host' ].length
        ? request.headers[ 'host' ][ 0 ].value
        : 'cdn-of.drv.tw' ;

    request.headers[ 'referer' ] = [ {
        key: 'Referer' ,
        value: params.ra
            ? 'https://' + cdnHost + request.uri
            : 'https://drive.google.com/uc?id=' + idFile ,
    } ] ;

    request.uri = '/drive/v3/files/' + encodeURIComponent( idFile ) ;
    request.querystring = 'alt=media' ;
    request.headers[ 'host' ] = [ {
        key: 'Host' ,
        value: 'www.googleapis.com' ,
    } ] ;

    request.origin = {
        custom: {
            protocol: 'https' ,
            port: 443 ,
            domainName: 'www.googleapis.com' ,
            path: '' ,
            sslProtocols: [ 'TLSv1.2' ] ,
            keepaliveTimeout: 5 ,
            readTimeout: 60 ,
        } ,
    } ;

    if( params.ra ) {
        // Token is passed reversed so it does not land in access logs in
        // plaintext; undo that here and keep it out of the cache key.
        const token = String( params.ra ).split( '' ).reverse().join( '' ) ;

        request.origin.custom.customHeaders = {
            authorization: [ {
                key: 'Authorization' ,
                value: 'Bearer ' + token ,
            } ] ,
        } ;
    }
    else {
        if( !process.env.GOOGLE_API_KEY )
            return deny( 403, 'No credential for public file' ) ;

        request.querystring = 'alt=media&key=' + process.env.GOOGLE_API_KEY ;
    }

    console.log( 'Request', redacted( request ) ) ;

    return request ;
} ;
