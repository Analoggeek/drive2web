(function(window,document,$){

// Escapes a hostname for use inside a RegExp built from a string
function d2wEscapeRe( s ) {
    return String( s ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) ;
}

function handleError( err, cTries, doRetry ) {
    console.log( err ) ;
    if( err ) {
        if( 403 == err.status && cTries < 5 ) {
            // Forbidden, most likely userRateLimitExceeded
            setTimeout( doRetry, 
                1000 * Math.pow( 2, cTries ) + Math.random() * 1000 ) ;
        }
        else if( 401 == err.status ) {
            // Unauthorized
            window.location.href = window.location.href.split( '#' )[ 0 ] ; 
        }
        else if( 404 == err.status ) {
            // OneDrive returns 404 if no html file is found
            var looking = document.querySelector( '#looking' ) ;
            looking.classList.add( 'hidden' ) ;

            var done = document.querySelector( '#nofile' ) ;
            done.classList.remove( 'hidden' ) ;
        }
    }
}

function gdResolveFileRecord( id, fileRecs, accessToken, tokenType, cTries ) {
    cTries = cTries || 0 ;

    var fileRec = fileRecs[ id ] ;
    if( !( fileRec && fileRec.parent 
        && !fileRecs.hasOwnProperty( fileRec.parent ) ) )
        return ;

    fileRec.wip = true ;
    fileRecs[ fileRec.parent ] = {
        'wip': true
    } ;

    urlFileRec = 'https://www.googleapis.com/drive/v3/files/'
        + fileRec.parent + '?fields=name%2Cparents' ;
    $.ajax({
        'url': urlFileRec,
        'beforeSend': function( xhr ){
            xhr.setRequestHeader( 'Authorization', tokenType + ' ' + accessToken ) ;
        },
        'success': (function( id ){
            return function( data ){
                fileRec = fileRecs[ id ] ;
                fileRec.wip = false ; 

                fileRecs[ fileRec.parent ] = { 
                    'name': data.name,
                    'uri': encodeURIComponent( data.name ),
                    'wip': false
                } ;
                if( data.parents )
                    // rec.parent is not root, and not resolved yet
                    fileRecs[ fileRec.parent ].parent = data.parents[ 0 ] ;
                else {
                    // Parent is root
                    fileRecs[ fileRec.parent ].isRoot = true ;
                }
            }
        })( id ),
        'error': (function( id ){
            return function( err ) {
                fileRec = fileRecs[ id ] ;
                fileRec.wip = false ;
                delete fileRecs[ fileRec.parent ] ;

                handleError( err, cTries, function(){
                    gdResolveFileRecord( id, fileRecs, accessToken, tokenType, cTries++ ) ;
                } ) ;
            } ;
        })( id )
    }) ;
}

var idRoot ;

function gdResolveFiles( fileRecs, accessToken, tokenType ) {
    var cEntries = 0, cResolved = 0 ;
    for( var id in fileRecs )
        if( fileRecs.hasOwnProperty( id ) ) {
            cEntries++ ;
            var fileRec = fileRecs[ id ] ;
            if( fileRec.wip )
                continue ;
            else if( !fileRec.parent )
                cResolved++ ;
            else if( fileRecs.hasOwnProperty( fileRec.parent ) 
                && !fileRecs[ fileRec.parent ].wip && idRoot ) {
                    
                var parentRec = fileRecs[ fileRec.parent ] ;
                if( parentRec.isRoot ) {
                    fileRec.name = '/' + fileRec.name ;
                    fileRec.uri = '/' + fileRec.uri ;
                    if( idRoot != fileRec.parent )
                        fileRec[ 'unreachable' ] = true ;
                    delete fileRec.parent ;
                    cResolved++ ;
                }
                else {
                    fileRec.name = parentRec.name + '/' + fileRec.name ;
                    fileRec.uri = parentRec.uri + '/' + fileRec.uri ;
                    fileRec.parent = parentRec.parent ; 
                }
            }
            else if( !fileRec.isRoot ) 
                setTimeout( (function( id ) {
                    return function() {
                        gdResolveFileRecord( id, fileRecs, accessToken, tokenType, 0 ) ;
                    } ;
                })( id ), ( cEntries + 1 ) * 300 ) ; // Spread out the calls
        }

    return cResolved / cEntries ;
}

function gdUpdateContent( serviceBase, target, accessToken, tokenType, cTries ) {
    cTries = cTries || 0 ;

    var urlListHtml = 'https://www.googleapis.com/drive/v3/files'
        + '?corpus=user&pageSize=1000&q=mimeType%3D\'text%2Fhtml\'+and+not+trashed'
        + '&fields=files(id%2Cname%2Cparents%2Cpermissions%2Cshared%2CwebContentLink)'
        + '&orderBy=modifiedTime%20desc' ;
    $.ajax({
        'url': urlListHtml,
        'beforeSend': function( xhr ){
            xhr.setRequestHeader( 'Authorization', tokenType + ' ' + accessToken ) ;
        },
        'success': function( data ) {
            if( data && data.files && data.files.length > 0 ) {

                var fileRecs = {} ;
                for( var iFile = 0; iFile < data.files.length; iFile++ ) {
                    var fileRec = data.files[ iFile ] ;
                    var pub = false ;
                    if( fileRec.permissions && fileRec.permissions.length > 0 ) {
                        for( var p = 0; !pub && p < fileRec.permissions.length; p++ )
                            if( 'anyone' == fileRec.permissions[ p ].type )
                                pub = true ;
                    }
                    else
                        pub = fileRec.shared ;

                    if( pub && fileRec.id && fileRec.name && fileRec.parents 
                        && fileRec.webContentLink )
                        fileRecs[ fileRec.id ] = {
                            'name': ( 'index.html' == fileRec.name ? '' : fileRec.name ),
                            'uri': ( 'index.html' == fileRec.name 
                                ? '' : encodeURIComponent( fileRec.name ) ),
                            'parent': fileRec.parents[ 0 ], // Follow first parent
                            'mimeType': 'text/html'
                        } ;
                }

                var hTimer = setInterval( function() {
                    var pctComplete = gdResolveFiles( fileRecs, accessToken, tokenType ) ;
                    if( 1 == pctComplete || isNaN( pctComplete ) ) {
                        clearInterval( hTimer ) ;
                        var htmls = [] ;
                        for( var id in fileRecs )
                            if( fileRecs.hasOwnProperty( id ) 
                                && 'text/html' == fileRecs[ id ][ 'mimeType' ]
                                && !fileRecs[ id ][ 'unreachable' ] )

                                htmls.push( fileRecs[ id ] ) ;

                        htmls.sort( function( a, b ){
                            var pathA = a.name.split( '/' ), depthA = pathA.length,
                                pathB = b.name.split( '/' ), depthB = pathB.length ;
                            var iDepth = 0, order = 0 ;

                            while( 0 == order && iDepth < depthA && iDepth < depthB ) {
                                order = pathA[ iDepth ].localeCompare( pathB[ iDepth ] ) ;
                                iDepth++ ;
                            }
                            if( 0 == order )
                                order = depthA - depthB ;

                            return order ;
                        } ) ;
                        
                        for( var i = 0; i < htmls.length; i++ ) {
                            var fileRec = htmls[ i ] ;
                            var li = document.createElement( 'li' ) ;
                            var a = document.createElement( 'a' ) ;
                            var txt = document.createTextNode( 'https://' + punycode.toUnicode( serviceBase ) + fileRec.name ) ;
                            a.setAttribute( 'href', 'https://' + serviceBase + fileRec.uri ) ;
                            a.setAttribute( 'target', '_blank' ) ;
                            a.appendChild( txt ) ;
                            li.appendChild( a ) ;

                            var htmlLinks = document.querySelector( 'ul#htmlLinks' ) ;
                            htmlLinks.appendChild( li ) ;
                        }

                        var looking = document.querySelector( '#looking' ) ;
                        looking.classList.add( 'hidden' ) ;

                        var result = document.querySelector( 0 == htmls.length ? '#nofile' : '#found' ) ;
                        result.classList.remove( 'hidden' ) ;
                    }
                }, 500 ) ;
            }
            else {
                var looking = document.querySelector( '#looking' ) ;
                looking.classList.add( 'hidden' ) ;
                var nofile = document.querySelector( '#nofile' ) ;
                nofile.classList.remove( 'hidden' ) ;
            }
        },
        'error': function( err ) {
            handleError( err, cTries, function(){
                gdUpdateContent( serviceBase, target, accessToken, tokenType, cTries++ ) ;
            } ) ;
        }
    }) ;

    var urlRootId = 'https://www.googleapis.com/drive/v3/files/root?fields=id' ;
    setTimeout( function(){
        $.ajax({
            'url': urlRootId,
            'beforeSend': function( xhr ){
                xhr.setRequestHeader( 'Authorization', tokenType + ' ' + accessToken ) ;
            },
            'success': function( data ){
                idRoot = data.id ;
            }
        }) ;
    }, 250 ) ;
}

function odUpdateContent( serviceBase, target, accessToken, tokenType, cTries ) {
    cTries = cTries || 0 ;

    var search = 'html' ;
    var urlListHtml = 'https://graph.microsoft.com/v1.0/me/drive/root/search(q=\''
        + encodeURIComponent( search ) + '\')?$select=name,file,parentReference,shared' ;
    $.ajax({
        'url': urlListHtml,
        'beforeSend': function( xhr ){
            xhr.setRequestHeader( 'Authorization', tokenType + ' ' + accessToken ) ;
        },
        'success': function( data ) {
            //console.log( data ) ;
            if( data.value ) {
                var cFilesShown = 0 ;

                for( var i = 0; i < data.value.length; i++ ) {
                    var fileRec = data.value[ i ] ;
                    var mimeType ;
                    if( fileRec.file && fileRec.file.mimeType )
                        mimeType = fileRec.file.mimeType ;

                    if( fileRec.parentReference && fileRec.parentReference.path 
                        && fileRec.name
                        && 'text/html' == mimeType ) 
                    { 
                        var fullPath = fileRec.parentReference.path
                            .replace( '/drive/root:', '' ) + '/' 
                            + ( 'index.html' == fileRec.name 
                                ? '' : encodeURIComponent( fileRec.name ) ) ;
                        var li = document.createElement( 'li' ) ;
                        var a = document.createElement( 'a' ) ;
                        var txt = document.createTextNode( 'https://' + punycode.toUnicode( serviceBase ) + fullPath ) ;
                        a.setAttribute( 'href', 'https://' + serviceBase + fullPath ) ;
                        a.setAttribute( 'target', '_blank' ) ;
                        a.appendChild( txt ) ;
                        li.appendChild( a ) ;

                        var htmlLinks = document.querySelector( 'ul#htmlLinks' ) ;
                        htmlLinks.appendChild( li ) ;

                        cFilesShown++ ;
                    }
                }

                var looking = document.querySelector( '#looking' ) ;
                looking.classList.add( 'hidden' ) ;

                var done = document.querySelector( 
                    ( cFilesShown > 0 ) ? '#found' : '#nofile' ) ;
                done.classList.remove( 'hidden' ) ;
            }
        },
        'error': function( err ) {
            handleError( err, cTries, function(){
                odUpdateContent( serviceBase, target, accessToken, tokenType, cTries++ ) ;
            } ) ;
        }
    }) ;
}


window.addEventListener( 'load', function(){
    // Configuration comes from /inc/config.js (window.D2W); every value has a
    // fallback so the dashboard still works when config.js is absent.
    var cfg = window.D2W || { } ;
    var apiHost = cfg.apiHost || window.location.host ;
    var webHost = cfg.webHost || ( window.location.protocol + '//' + window.location.host ) ;
    var siteSuffix = cfg.siteSuffix || '.on.drv.tw' ;

    // The host is no longer hardcoded, and the query string is optional:
    // /~user@g.com/gd/ used to fall through to a redirect to the marketing
    // site because the old regex demanded a '?'.
    var request = window.location.href.match(
        new RegExp( '^https?://' + d2wEscapeRe( apiHost )
            + '/~([^@]*@[^/?]*)/([go]d)/([^?]*)\\??(.*)$' ) ) ;
    // Parse hash into params
    var state = '', rest = '#', params = {} ;
    if( '#' == window.location.hash[ 0 ] ) {
        var args = window.location.hash.substring( 1 ).split( '&' ) ;
        for( var i = 0; i < args.length; i++ ) {
            var arg = args[ i ].split( '=' ) ;

            if( 2 == arg.length ) {
                params[ arg[ 0 ] ] = decodeURIComponent( arg[ 1 ] ) ;

                if( 'state' !== arg[ 0 ] )
                    rest += ( 1 == rest.length ? '' : '&' ) + args[ i ] ;
            }
        }
    }

    if( request ) {
        var username = request[ 1 ],
            serviceCode = request[ 2 ],
            uri = request[ 3 ],
            option = request[ 4 ] ;

        if( !params[ 'access_token' ] ) {
            if( 'gd' == serviceCode ) {
                var urlNext = window.location.href.replace( window.location.hash, '' ) ;
                var urlOauth = 'https://accounts.google.com/o/oauth2/v2/auth'
                    + '?scope=' + encodeURIComponent( cfg.googleScope
                        || 'https://www.googleapis.com/auth/drive.readonly' )
                    + '&redirect_uri=' + encodeURIComponent( 'https://' + apiHost + '/.b/gd/' )
                    + '&state=' + encodeURIComponent( urlNext )
                    + '&response_type=token'
                    + '&client_id=' + encodeURIComponent( cfg.googleClientId )
                    + '&login_hint=' + encodeURIComponent( username ) ;
                window.location.href = urlOauth ;
            }
            else if( 'od' == serviceCode ) {
                var urlNext = window.location.href.replace( window.location.hash, '' ) ;
                var urlOauth = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'
                    + '?scope=' + encodeURIComponent( cfg.microsoftScope || 'Files.Read' )
                    + '&redirect_uri=' + encodeURIComponent( 'https://' + apiHost + '/.b/od/' )
                    + '&state=' + encodeURIComponent( urlNext )
                    + '&response_type=token'
                    + '&client_id=' + encodeURIComponent( cfg.microsoftClientId )
                    + '&login_hint=' + encodeURIComponent( username ) ;
                window.location.href = urlOauth ;
            }
        }
        else {
            // Authentication done. URL should contain ?a=admin. Grab access_token from hash
            window.location.hash = 'authed=1' ; // Hide all info. This shall not re-load browser
            var target = window.location.protocol 
                + '//' + window.location.host + window.location.pathname ;

            window.fetch( target + '?a=siteName' )
                .then( function( response ){
                    return response.json() ;
                })
                .then( function( result ){
                    if( result && result.siteName ) {
                        var serviceBase = result.siteName + siteSuffix ;

                        if( 'gd' == serviceCode )
                            gdUpdateContent( serviceBase, target,
                                params[ 'access_token' ], params[ 'token_type' ], 0 ) ;
                        else if( 'od' == serviceCode )
                            odUpdateContent( serviceBase, target,
                                params[ 'access_token' ], params[ 'token_type' ], 0 ) ;

                        document.body.classList.remove( 'hidden' ) ;
                    }
                }) ;
        }
    }
    else if( window.location.href.match( /\/\.b\/[go]d\// ) && params[ 'state' ] )
        // Finishing up authentication as /.b/gd/#...
        window.location.href = params[ 'state' ] + rest ;
    else
        window.location.href = webHost ;
}) ;

function hasClass( e, cls ) {
    return ( ' ' + e.className + ' ' ).replace( /[\n\t]/g, ' ' ).indexOf( ' ' + cls + ' ' ) > -1 ;
}

function onReadmore( ev ) {
    var t = ev.target ;
    var m = document.querySelector( '#' + t.getAttribute( 'data-target' ) ) ;
    var bOpen = hasClass( m, 'open' ) ;
    if( bOpen ) {
        t.classList.remove( 'open' ) ;
        m.classList.remove( 'open' ) ;
    }
    else {
        t.classList.add( 'open' ) ;
        m.classList.add( 'open' ) ;
    }
}

// ---------------------------------------------------------------------------
// Site tools: the @@commands, called as the signed-in owner.
//
// The server requires, for a mutating command, either a matching bearer token
// or (when authenticating with the D2WSESS cookie) the X-D2W-CSRF header that
// inline/admin.html embeds as a <meta> value derived from that session.
// ---------------------------------------------------------------------------
function d2wCsrfToken() {
    var m = document.querySelector( 'meta[name="d2w-csrf"]' ) ;
    var t = m ? ( m.getAttribute( 'content' ) || '' ) : '' ;
    return /^[0-9a-f]{16,64}$/.test( t ) ? t : '' ;
}

// The API path prefix for this site: /~user@domain/gd/  (or /od/)
function d2wSiteBase() {
    var m = window.location.href.match( /\/~[^\/?]+\/[go]d\// ) ;
    if( m )
        return m[ 0 ] ;

    // Finishing sign-in through /.b/<svc>/ — the state hash carries the real URL
    var hash = window.location.hash || '' ;
    m = hash.match( /state=([^&]+)/ ) ;
    if( m ) {
        var state = decodeURIComponent( m[ 1 ] ) ;
        var m2 = state.match( /\/~[^\/?]+\/[go]d\// ) ;
        if( m2 )
            return m2[ 0 ] ;
    }
    return '' ;
}

function d2wToolResult( text ) {
    var pre = document.querySelector( '#d2w-tool-result' ) ;
    if( pre )
        pre.textContent = text ;
}

function d2wRunTool( btn ) {
    var base = d2wSiteBase() ;
    if( !base ) {
        d2wToolResult( 'Sign in again from the landing page: this page has no site path to act on.' ) ;
        return ;
    }

    var cmd = btn.getAttribute( 'data-cmd' ) ;
    var method = btn.getAttribute( 'data-method' ) || 'POST' ;
    var fileId = ( document.querySelector( '#d2w-fileid' ).value || '' ).trim() ;

    if( '@@cache' !== cmd && '@@download' !== cmd ) {
        if( !/^[A-Za-z0-9_\-]{10,120}$/.test( fileId ) ) {
            d2wToolResult( cmd + ' needs a valid Drive file id (10-120 chars of [A-Za-z0-9_-]).' ) ;
            return ;
        }
        if( btn.getAttribute( 'data-confirm' )
            && !window.confirm( 'Really ' + cmd + ' ' + fileId + '?' ) )
            return ;
    }

    var url = base + cmd + ( fileId ? '/' + encodeURIComponent( fileId ) : '' ) ;
    var headers = { } ;
    var csrf = d2wCsrfToken() ;
    if( csrf && 'GET' !== method )
        headers[ 'X-D2W-CSRF' ] = csrf ;

    d2wToolResult( method + ' ' + url + ' ...' ) ;

    fetch( url, {
        method: method ,
        credentials: 'include' ,       // sends D2WSESS
        headers: headers ,
    } )
    .then( function( r ) {
        return r.text().then( function( t ) {
            var out = 'HTTP ' + r.status + '\n' ;
            try { out += JSON.stringify( JSON.parse( t ), null, 2 ) ; }
            catch( e ) { out += t.slice( 0, 4000 ) ; }
            d2wToolResult( out ) ;
        } ) ;
    }, function( e ) {
        d2wToolResult( 'Request failed: ' + e ) ;
    } ) ;
}

( function initTools() {
    var panel = document.querySelector( '#d2w-tools' ) ;
    if( !panel )
        return ;

    if( !d2wCsrfToken() ) {
        panel.querySelector( '#d2w-tool-buttons' ).innerHTML =
            '<em>Sign in from the landing page (not the popup flow) to enable the '
            + 'mutating tools: this page carries no CSRF token.</em>' ;
    }

    panel.addEventListener( 'click', function( ev ) {
        var btn = ev.target.closest ? ev.target.closest( 'button[data-cmd]' ) : null ;
        if( btn )
            d2wRunTool( btn ) ;
    } ) ;
} )() ;

// ---------------------------------------------------------------------------
// The "Your website" panel: what the site is called, and how to change it.
//
// Nothing here is a new authority - each action is the same @@command the tools
// below use, called as the signed-in owner with X-D2W-CSRF. The server is the one
// that decides whether a name is claimable (see d2w_site_name_claimable()), so
// this code only mirrors those rules to make the field feel responsive.
// ---------------------------------------------------------------------------
function d2wText( sel, text, cls ) {
    var el = document.querySelector( sel ) ;
    if( !el )
        return null ;
    // textContent, never innerHTML: the server's tip strings quote a name the
    // visitor typed, and this page is rendered inside the owner's session.
    el.textContent = text ;
    if( undefined !== cls )
        el.className = el.className.replace( /\bd2w-(ok|err)\b/g, '' ).trim()
            + ( cls ? ' ' + cls : '' ) ;
    return el ;
}

function d2wApiOrigin() {
    // admin.html is served by the API host itself, so a relative URL would do;
    // an explicit origin keeps /do/siteName working when it is not.
    return window.location.protocol + '//' + window.location.host ;
}

// Mirrors d2w_normalize_site_name() step for step - same cuts, same tidy-up - so
// what the owner sees in the field is what the server will store. Where the PHP
// *refuses* an input, this returns null and the panel says so; silently rewriting
// "cafe\u0301" into some other word is how people end up hosting on a URL they
// never asked for. The server stays authoritative: this is only feedback.
function d2wNormalizeName( raw ) {
    var name = String( null === raw || undefined === raw ? '' : raw ).trim().toLowerCase() ;
    name = name.replace( /^[a-z]+:\/\//, '' ) ;

    var cut = name.indexOf( '/' ) ;
    if( cut >= 0 )
        name = name.slice( 0, cut ) ;
    cut = name.indexOf( '.' ) ;
    if( cut >= 0 )
        name = name.slice( 0, cut ) ;

    if( /[^a-z0-9_\- ]/.test( name ) )
        return null ;

    name = name.replace( /[\s_]+/g, '-' ) ;
    if( 0 !== name.indexOf( 'xn--' ) )
        name = name.replace( /-{2,}/g, '-' ) ;
    name = name.replace( /^[.-]+/, '' ).replace( /[.-]+$/, '' ) ;

    return name.length ? name : null ;
}

function d2wCsrfHeaders() {
    var headers = { } ;
    var csrf = d2wCsrfToken() ;
    if( csrf )
        headers[ 'X-D2W-CSRF' ] = csrf ;
    return headers ;
}

function d2wSiteCall( cmd, method, fields ) {
    var base = d2wSiteBase() ;
    if( !base )
        return Promise.reject( new Error( 'no-site-path' ) ) ;

    var opt = { method: method, credentials: 'include', headers: d2wCsrfHeaders() } ;
    if( fields ) {
        var body = new URLSearchParams( ) ;
        for( var k in fields )
            body.append( k, fields[ k ] ) ;
        opt.body = body.toString() ;
        opt.headers[ 'Content-Type' ] = 'application/x-www-form-urlencoded' ;
    }

    return fetch( base + cmd, opt ).then( function( r ) {
        return r.text().then( function( t ) {
            var json = null ;
            try { json = JSON.parse( t ) ; } catch( e ) { }
            return { status: r.status, json: json } ;
        } ) ;
    } ) ;
}

function d2wCopy( text, btn ) {
    var label = btn ? btn.textContent : '' ;
    function done( ok ) {
        if( !btn )
            return ;
        btn.textContent = ok ? 'Copied' : 'Copy failed' ;
        setTimeout( function(){ btn.textContent = label ; }, 2000 ) ;
    }

    if( navigator.clipboard && navigator.clipboard.writeText ) {
        navigator.clipboard.writeText( text ).then( function(){ done( true ) ; },
            function(){ done( false ) ; } ) ;
        return ;
    }

    var ta = document.createElement( 'textarea' ) ;
    ta.value = text ;
    ta.setAttribute( 'readonly', '' ) ;
    ta.style.position = 'fixed' ;
    ta.style.left = '-9999px' ;
    document.body.appendChild( ta ) ;
    ta.select() ;
    var ok = false ;
    try { ok = document.execCommand( 'copy' ) ; } catch( e ) { }
    document.body.removeChild( ta ) ;
    done( ok ) ;
}

( function initSitePanel() {
    if( !document.querySelector( '#d2w-site' ) )
        return ;

    var state = { siteName: '', suffix: '', url: '', renameTimer: 0 } ;

    function setBusy( busy ) {
        [ '#d2w-btn-claim', '#d2w-btn-verify' ].forEach( function( sel ) {
            var el = document.querySelector( sel ) ;
            if( el )
                el.disabled = busy ;
        } ) ;
    }

    function renderInfo( info ) {
        state.siteName = info.siteName || '' ;
        state.url = info.url || '' ;
        state.suffix = '' ;

        if( state.url ) {
            var host = state.url.replace( /^https:\/\//, '' ).replace( /\/.*$/, '' ) ;
            var at = host.indexOf( state.siteName ) ;
            if( at >= 0 )
                state.suffix = host.substr( at + state.siteName.length ) ;
        }

        d2wText( '#d2w-site-url', state.url || '(unknown)' ) ;
        var open = document.querySelector( '#d2w-btn-open' ) ;
        if( open ) {
            open.href = state.url || '#' ;
            open.classList.toggle( 'hidden', !state.url ) ;
        }

        d2wText( '#d2w-site-hint', info.hint || '~<user>@' + state.siteName + state.suffix ) ;
        d2wText( '#d2w-site-domain', info.domain ? info.domain + ' - verified' : 'not set' ) ;

        var suf = document.querySelector( '#d2w-suffix' ) ;
        if( suf && state.suffix )
            suf.textContent = state.suffix ;

        // the server states the record itself, so the panel never has to guess
        // what a working deployment expects; the local construction is a fallback
        var txt = document.querySelector( '#d2w-txt' ) ;
        if( txt )
            txt.textContent = info.txt || ( 'DRVTW=' + state.siteName + state.suffix ) ;
        var dom = document.querySelector( '#d2w-domain' ) ;
        if( dom )
            dom.hidden = false ;

        var note = 'Your site is live at ' + ( state.url || 'an unknown address' ) ;
        if( false === info.approved )
            note += ' (this deployment only serves approved sites - yours is not approved yet)' ;
        if( info.renameIn > 0 )
            note += ' - you can change the address again in ' + info.renameIn + 's' ;
        d2wText( '#d2w-site-note', note, false === info.approved ? 'd2w-err' : 'd2w-ok' ) ;

        var btnRename = document.querySelector( '#d2w-btn-rename' ) ;
        if( btnRename ) {
            btnRename.disabled = info.renameIn > 0 ;
            if( info.renameIn > 0 )
                state.renameTimer = setInterval( function() {
                    info.renameIn -= 1 ;
                    if( info.renameIn <= 0 ) {
                        clearInterval( state.renameTimer ) ;
                        btnRename.disabled = false ;
                        btnRename.textContent = 'Change address' ;
                    }
                    else
                        btnRename.textContent = 'Change address (' + info.renameIn + 's)' ;
                }, 1000 ) ;
        }
    }

    function loadSite() {
        return d2wSiteCall( '@@site', 'GET' ).then( function( r ) {
            if( 200 === r.status && r.json && r.json.ok )
                renderInfo( r.json ) ;
            else if( r.json && 'ERR_NO_SITE' === r.json.error )
                d2wText( '#d2w-site-note', 'Sign in from the landing page first: this page has '
                    + 'no site attached to it yet.', 'd2w-err' ) ;
            else
                d2wText( '#d2w-site-note', 'Could not read the site record (HTTP ' + r.status + ').', 'd2w-err' ) ;
        }, function( e ) {
            d2wText( '#d2w-site-note', 'Could not reach the server: ' + e, 'd2w-err' ) ;
        } ) ;
    }

    // Availability check, debounced: the endpoint is a public lookup on the API
    // host (no session needed) so it can answer while the panel is still loading.
    var availTimer = 0 ;
    function checkAvail( name ) {
        var el = d2wText( '#d2w-avail', name ? 'Checking...' : '' ) ;
        var claim = document.querySelector( '#d2w-btn-claim' ) ;
        if( claim )
            claim.disabled = true ;
        if( !name )
            return ;

        fetch( d2wApiOrigin() + '/do/siteName?name=' + encodeURIComponent( name ),
                { credentials: 'omit' } )
        .then( function( r ) {
            return r.text().then( function( t ) {
                var json = null ;
                try { json = JSON.parse( t ) ; } catch( e ) { }
                return { status: r.status, json: json } ;
            } ) ;
        } )
        .then( function( r ) {
            var j = r.json || { } ;
            if( !el )
                return ;
            if( j.available ) {
                el.textContent = 'Free: ' + name + state.suffix ;
                el.className = 'd2w-note d2w-ok' ;
                if( claim )
                    claim.disabled = false ;
            }
            else {
                el.textContent = ( j.tip || 'Not available.' ) + ( j.name ? ' (' + j.name + ')' : '' ) ;
                el.className = 'd2w-note d2w-err' ;
            }
        }, function( e ) {
            if( el ) {
                el.textContent = 'Could not check availability: ' + e ;
                el.className = 'd2w-note d2w-err' ;
            }
        } ) ;
    }

    document.addEventListener( 'click', function( ev ) {
        var btn = ev.target.closest ? ev.target.closest( '#d2w-site button, #d2w-site .d2w-btn' ) : null ;
        if( !btn )
            return ;

        if( 'd2w-btn-refresh' === btn.id ) {
            loadSite() ;
            return ;
        }
        if( 'd2w-btn-copy' === btn.id ) {
            d2wCopy( state.url, btn ) ;
            return ;
        }
        if( 'd2w-btn-copytxt' === btn.id ) {
            var code = document.querySelector( '#d2w-txt' ) ;
            d2wCopy( code ? code.textContent : '', btn ) ;
            return ;
        }
        if( 'd2w-btn-rename' === btn.id ) {
            var panel = document.querySelector( '#d2w-rename' ) ;
            panel.hidden = false ;
            var input = document.querySelector( '#d2w-newname' ) ;
            input.value = state.siteName ;
            input.focus() ;
            input.select() ;
            checkAvail( '' ) ;
            return ;
        }
        if( 'd2w-btn-cancel' === btn.id ) {
            document.querySelector( '#d2w-rename' ).hidden = true ;
            return ;
        }
        if( 'd2w-btn-claim' === btn.id ) {
            var name = d2wNormalizeName( document.querySelector( '#d2w-newname' ).value ) ;
            if( !name ) {
                d2wText( '#d2w-avail', 'That name cannot be used.', 'd2w-err' ) ;
                return ;
            }
            setBusy( true ) ;
            d2wSiteCall( '@@rename', 'POST', { name: name } ).then( function( r ) {
                setBusy( false ) ;
                var j = r.json || { } ;
                if( 200 === r.status && j.ok ) {
                    d2wText( '#d2w-site-note', j.unchanged
                        ? 'That is already your address.'
                        : ( j.tip || 'Address changed.' ), 'd2w-ok' ) ;
                    document.querySelector( '#d2w-rename' ).hidden = true ;
                    if( !j.unchanged ) {
                        window.setTimeout( function() {
                            if( window.confirm( 'Reload this page at its new address now?\n' + j.url ) )
                                window.location.href = j.url + window.location.search + window.location.hash ;
                        }, 250 ) ;
                    }
                    loadSite() ;
                    return ;
                }
                var tip = j.tip || ( 'HTTP ' + r.status ) ;
                if( 429 === r.status && j.retryIn )
                    tip += ' (retry in ' + j.retryIn + 's)' ;
                d2wText( '#d2w-avail', tip, 'd2w-err' ) ;
                var claim2 = document.querySelector( '#d2w-btn-claim' ) ;
                if( claim2 )
                    claim2.disabled = true ;
            }, function( e ) {
                setBusy( false ) ;
                d2wText( '#d2w-avail', 'Request failed: ' + e, 'd2w-err' ) ;
            } ) ;
            return ;
        }
        if( 'd2w-btn-verify' === btn.id ) {
            var domain = ( document.querySelector( '#d2w-domain-input' ).value || '' ).trim().toLowerCase() ;
            setBusy( true ) ;
            d2wSiteCall( '@@domain', 'POST', { domain: domain } ).then( function( r ) {
                setBusy( false ) ;
                var j = r.json || { } ;
                var el = d2wText( '#d2w-domain-note', j.tip || ( 'HTTP ' + r.status ),
                    j.ok ? 'd2w-ok' : 'd2w-err' ) ;
                if( j.ok )
                    loadSite() ;
            }, function( e ) {
                setBusy( false ) ;
                d2wText( '#d2w-domain-note', 'Request failed: ' + e, 'd2w-err' ) ;
            } ) ;
            return ;
        }
    } ) ;

    document.addEventListener( 'input', function( ev ) {
        if( 'd2w-newname' !== ev.target.id )
            return ;
        var name = d2wNormalizeName( ev.target.value ) ;
        if( null === name ) {
            // Show the reason instead of quietly rewriting: a name with anything
            // beyond a-z 0-9 - _ in it (accents, spaces around punctuation, an
            // emoji) would be refused by the server anyway.
            if( '' === String( ev.target.value ).trim() )
                checkAvail( '' ) ;
            else
                d2wText( '#d2w-avail', 'Only a-z, 0-9, hyphens and underscores can be used.',
                    'd2w-err' ) ;
            var blocked = document.querySelector( '#d2w-btn-claim' ) ;
            if( blocked )
                blocked.disabled = true ;
            return ;
        }
        if( name !== ev.target.value )
            ev.target.value = name ;
        if( availTimer )
            clearTimeout( availTimer ) ;
        availTimer = window.setTimeout( function(){ checkAvail( name ) ; }, 400 ) ;
    } ) ;

    if( d2wSiteBase() )
        loadSite() ;
    else
        d2wText( '#d2w-site-note', 'Sign in from the landing page (or connect a drive account) '
            + 'and this panel will show your site address.', 'd2w-err' ) ;
} )() ;

var rms = document.querySelectorAll( '.readmore' ) ;
for( var i = 0; i < rms.length; i++ )
    rms[ i ].addEventListener( 'click', onReadmore ) ;

})(window,document,jQuery) ;