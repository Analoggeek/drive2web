<?php

//
// DriveToWeb credentials & deployment configuration
//
// Copy to drvtw_creds.php in this folder, then fill in the values.
// drvtw_creds.php is git-ignored: never commit it.
// Every setting below that is not in the "Required" sections has a safe
// default, so you can start with just OAuth + MongoDB + SMTP.
//

//
// == Deployment identity (change these when you self-host) ==
//
// The domain that serves hosted sites, e.g. 'drv.tw' gives site URLs of the
// form https://sitename.on.drv.tw/
$d2wBaseDomain = 'your.domain' ;
// Hostname of the sign-in / admin API (this file's index.php)
$d2wApiHost = 'api.your.domain' ;
// Hostname of the marketing + widget site (frontend/)
$d2wWebHost = 'www.your.domain' ;
// Hostnames used as file proxies by the edge functions (cf-lambda/)
$d2wCdnHosts = array( 'cdn-of.your.domain', 'cdn2-of.your.domain' ) ;
// Where the widget script injected into hosted pages is loaded from
$d2wWidgetScript = 'https://www.your.domain/inc/wd.js' ;

//
// == Secrets (generate one each, e.g. `openssl rand -hex 32`) ==
//
// Signs the owner session cookie that guards privileged @@ commands.
// Leave NULL and those commands are only usable with a matching bearer token.
$sessionSecret = 'REPLACE_WITH_RANDOM_HEX' ;
// Signs the hourly token used by the @@backroom / @@log.csv operator tools.
$opsSecret = 'REPLACE_WITH_RANDOM_HEX' ;
// HTTP basic-auth for the operator tools (recommended in addition to $opsSecret)
$opsUser = 'ops' ;
$opsPass = 'REPLACE_WITH_STRONG_PASSWORD' ;
// When set, generated site names are reproducible for a given user+root.
// Leave NULL to get fully random names (recommended).
$siteNameSecret = NULL ;

//
// == Google OAuth 2.0 credentials ==
// Obtain from: https://console.cloud.google.com/ > APIs & Services > Credentials
// Authorized redirect URI must include: https://$d2wApiHost/.a/gd/
$gdClientId = 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com' ;
$gdClientSecret = 'YOUR_GOOGLE_CLIENT_SECRET' ;
$gdApiKey = 'YOUR_GOOGLE_API_KEY' ;

//
// == Microsoft OAuth 2.0 credentials ==
// Obtain from: https://portal.azure.com/ > App registrations
// Redirect URI must include: https://$d2wApiHost/.a/od/
$odClientId = 'YOUR_MICROSOFT_CLIENT_ID' ;
$odClientSecret = 'YOUR_MICROSOFT_CLIENT_SECRET' ;

//
// == MongoDB connection strings ==
// Remote (MongoDB Atlas or self-hosted): used for persistent storage
$mongoRemoteConnections = [
    'mongodb+srv://USERNAME:PASSWORD@cluster0.example.mongodb.net/wwwdrv?retryWrites=true&w=majority',
] ;
// Local (Unix socket): used for primary read/write on the app server
$mongoLocalConnection = 'mongodb://USERNAME:PASSWORD@%2Ftmp%2Fmongodb-27017.sock/wwwdrv' ;

//
// == SMTP for transactional email (site access codes) ==
// Example shown for AWS SES; adapt for any SMTP provider
$smtpHost = 'email-smtp.us-east-1.amazonaws.com' ;
$smtpPort = 587 ;
$sesSmtpUser = 'YOUR_SES_SMTP_USER' ;
$sesSmtpPassword = 'YOUR_SES_SMTP_PASSWORD' ;
// Legacy name kept for compatibility
$smtpPassword = 'YOUR_SMTP_PASSWORD' ;
$mailFrom = 'no-reply@your.domain' ;
$mailFromName = 'DriveToWeb No-Reply' ;

//
// == Optional behaviour ==
//
// Scrub visitor e-mail addresses and any credential from user.log (the log is
// group-readable and is streamed by the @@backroom UI). Set FALSE only while
// debugging on a private instance.
$d2wLogPrivacy = TRUE ;
// Extra CSP for the pages index.php renders itself (error pages, dashboard).
// Empty string uses the built-in default; set to a policy of your own, or to
// 'none' to send no CSP at all.
$d2wAppCSP = '' ;
// CSP applied to hosted user content. Empty (default) sends none, because a
// strict policy breaks arbitrary third-party sites; a hardening-focused host
// could use, e.g., "base-uri 'self'; form-action 'self'".
$d2wContentCSP = '' ;
// How many wrong site-access codes per site are tolerated within
// $authCodeRetryWindowSec before verification is refused with Retry-After.
// The guard cannot be switched off - $authCodeMaxFails is clamped to >= 1, so
// raise it (or raise the window) rather than setting 0.
$authCodeMaxFails = 8 ;

// How often an owner may move their site to a new address, in seconds. The dashboard
// waits this long between attempts and the server refuses anything sooner, so a
// hijacked session cannot rapidly bounce a site between owners. 0 removes the limit -
// fine on a private install, not on a public one.
//$siteNameChangeGapSec = 3600 ;

// Names to refuse on top of the automatic list (this deployment's own hosts, plus
// labels such as www, api, admin, mail, on, drvtw). Anything you run on
// <name>.your.domain belongs here, so a site can never look like it.
//$d2wReservedSiteNames = array( 'shop', 'status' ) ;
$authCodeRetryWindowSec = 900 ;
// Minimum seconds between two code e-mails, counted per site AND per recipient.
$authCodeSendGapSec = 60 ;
// How long a mailed code stays valid.
$authCodeTtlSec = 900 ;
// Lifetime of the owner session cookie that guards the @@commands.
$sessionTtlSec = 1209600 ;
// MongoDB TLS: the driver verifies the server certificate by default. Only set
// these when your server uses a private CA or a self-signed certificate.
$mongoTlsLocal = FALSE ;        // TRUE = require TLS on $mongoLocalConnection too
$mongoTlsCAFile = '' ;          // e.g. '/etc/ssl/mongodb-ca.pem'
$mongoTlsAllowSelfSigned = FALSE ;  // last resort: disables verification
// Origins allowed to call the api.* endpoints cross-origin. Hosted sites
// (*.on.$d2wBaseDomain) are always allowed for themselves.
$corsAllowedOrigins = array( 'www.your.domain', 'api.your.domain', 'your.domain' ) ;
// Address shown on the error/help pages served by index.php (the static site
// under frontend/ reads its own copy from inc/config.js)
$d2wContactEmail = 'support@your.domain' ;
// Require sites to be approved (owners authenticated by email code) before
// their content is served. The hosted service had this ON; the default here
// is OFF so a fresh install works without SMTP. Turn it on for a public host.
$requireApprovedSites = FALSE ;
// Allow the "POST to a Google Sheet as a database" feature. Set to the MongoDB
// user id that may create the sheet; TRUE allows it for every user, FALSE
// (default) disables it. See HOW-TO-USE.md.
$allowCreatePostDb = FALSE ;

//
// == API tokens for trusted callers (e.g. abuse-reporting integrations) ==
// Keys are caller names, values are long random secrets you generate
$apiTokens = array(
    // 'caller-name' => 'YOUR_RANDOM_TOKEN',
) ;

//
// Derived values — do not edit
//
$d2wSiteSuffix = '.on.' . $d2wBaseDomain ;
