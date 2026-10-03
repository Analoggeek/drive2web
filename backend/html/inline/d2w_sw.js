self.addEventListener( 'activate', event => {
    event.waitUntil( clients.claim() ) ;
});

self.addEventListener( 'fetch', function( event ){
    const req = ( ( new URL( event.request.url ).origin.endsWith('{{BASE_DOMAIN}}')
        && 'GET' === event.request.method && 'no-cors' === event.request.mode 
        && [ 'image', 'script', 'font', 'style' ].includes( event.request.destination ) )
        ? new Request( event.request, { mode: 'cors', credentials: 'same-origin' } )
        : event.request ) ;

    event.respondWith( fetch( req ) ) ;
});