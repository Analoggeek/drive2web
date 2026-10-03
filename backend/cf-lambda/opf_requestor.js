'use strict' ;
/**
 * OPF requestor (OneDrive Proxy Files) — CloudFront origin-request trigger.
 *
 * Rewrites a proxied file request into the Microsoft storage URL that the
 * DriveToWeb origin passed in ?ur=<host>/<path>.
 *
 * SECURITY (fixed here):
 *  - `ur` was copied straight into request.uri + the custom origin host, so
 *    this function turned the CDN into an OPEN PROXY for any host the caller
 *    named (?ur=example.com/anything, ?ur=169.254.169.254/...). Requests are
 *    now only forwarded to Microsoft storage/Graph endpoints.
 *  - `match[...]` was dereferenced without checking, so any ?ur without a
 *    '/' threw (TypeError) and CloudFront answered 503/548 for the request.
 *  - CDN responses are cached per URL; without an allowlist anyone could get
 *    arbitrary content stored under a trusted cache key.
 */
const querystring = require( 'querystring' ) ;

const ALLOWED_SUFFIXES = [
    '.graph.microsoft.com',
    '.graph.microsoft.us',
    '.sharepoint.com',
    '.sharepoint.cn',
    '.onedrive.com',
    '.-files.1drv.com',
    '.files.1drv.com',
] ;

function hostAllowed( host ) {
    if( !host )
        return false ;

    // strip any port and lowercase
    const h = String( host ).toLowerCase().replace( /:\d+$/, '' ) ;

    if( h === 'graph.microsoft.com' || h === 'api.onedrive.com' )
        return true ;

    for( let i = 0; i < ALLOWED_SUFFIXES.length; i++ ) {
        const suffix = ALLOWED_SUFFIXES[ i ] ;
        if( h.length > suffix.length && h.slice( -suffix.length ) === suffix )
            return true ;
    }

    return false ;
}

function deny( message ) {
    console.log( 'OPF denied:', message ) ;

    return {
        status: '403',
        statusDescription: 'Forbidden',
        headers: {
            'content-type': [ { key: 'Content-Type', value: 'text/plain' } ] ,
            'cache-control': [ { key: 'Cache-Control', value: 'no-store' } ] ,
        } ,
        body: message ,
    } ;
}

exports.handler = async( event, context ) => {
    const request = event.Records[ 0 ].cf.request ;

    // This function only exists to fetch files; never relay other verbs
    if( request.method !== 'GET' && request.method !== 'HEAD' )
        return deny( 'Method not allowed' ) ;

    const params = querystring.parse( request.querystring ) ;
    const ur = params[ 'ur' ] ;

    if( !ur )
        return request ;

    // Reject anything that could smuggle a scheme, credentials or a newline
    if( typeof ur !== 'string' || ur.length > 4096 || !/^[A-Za-z0-9.\-_]+\/[^\s]*$/.test( ur ) )
        return deny( 'Malformed proxy target' ) ;

    if( ur.indexOf( '..' ) >= 0 )
        return deny( 'Malformed proxy target' ) ;

    const slash = ur.indexOf( '/' ) ;
    if( slash <= 0 || slash === ur.length - 1 )
        return deny( 'Malformed proxy target' ) ;

    const opfHost = ur.slice( 0, slash ) ;
    let opfUri = ur.slice( slash ) ;

    if( !hostAllowed( opfHost ) )
        return deny( 'Host not allowed' ) ;

    // request.uri must be path-only: a Graph/SharePoint download URL carries
    // its own query string, which belongs in request.querystring.
    const qmark = opfUri.indexOf( '?' ) ;
    let opfQuery = '' ;
    if( qmark >= 0 ) {
        opfQuery = opfUri.slice( qmark + 1 ) ;
        opfUri = opfUri.slice( 0, qmark ) ;
    }

    request.uri = opfUri ;
    request.querystring = opfQuery ;
    request.headers[ 'host' ] = [ {
        key: 'Host' ,
        value: opfHost ,
    } ] ;
    request.origin = {
        custom: {
            protocol: 'https' ,
            port: 443 ,
            domainName: opfHost ,
            path: '' ,
            // SSLv3 / TLSv1.0 / 1.1 are not accepted by Microsoft or by the
            // current Lambda@Edge API; listing them only invites failures.
            sslProtocols: [ 'TLSv1.2' ] ,
            keepaliveTimeout: 5 ,
            readTimeout: 60 ,
        } ,
    } ;

    return request ;
} ;
