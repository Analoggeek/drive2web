/*
 * DriveToWeb front-end configuration.
 *
 * Loaded before admin.js / wd.js. Replace the placeholders with the OAuth app
 * registrations you created for your own deployment (see backend/README.md).
 * Anything left as null is derived from the current page where possible.
 */
window.D2W = {
    // OAuth client ids used by the admin dashboard's implicit sign-in
    googleClientId: 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com',
    microsoftClientId: 'YOUR_MICROSOFT_CLIENT_ID',

    // Where the API lives. The admin page is served by the API host itself,
    // so null means "use window.location.host".
    apiHost: null,
    // Marketing/home site, used as the fallback destination
    webHost: null,
    // Suffix appended to site names to build a site URL
    siteSuffix: '.on.drv.tw',
    // Scope to request. 'drive.readonly' is enough for the file listing.
    googleScope: 'https://www.googleapis.com/auth/drive.readonly',
    microsoftScope: 'Files.Read',

    // Shown on every help/contact link. Set to null to hide the links.
    contactEmail: 'support@example.test',
    // Google Analytics (gtag) measurement id, e.g. 'G-XXXXXXXXXX'. Leave null
    // (default) so a self-hosted site does not report traffic to anybody else.
    analyticsId: null,
    // PayPal client id for supportus.html; null hides the donate button.
    paypalClientId: null,
    // Link shown as "Docs" in the footer; null hides those links
    docsUrl: null,
    // Only used by supportus.html's "hide the support banner" option
    donateDomainCheck: false,
};

// Convenience: hosts derived from the current page
(function (cfg) {
    if (!cfg.apiHost) cfg.apiHost = window.location.host;
    if (!cfg.webHost)
        cfg.webHost = window.location.protocol + '//' + window.location.host;
})(window.D2W);
