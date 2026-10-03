<?php
// Block malicious pages with these signatures
$blacklist_signatures = array( 
    array( '.000webhostapp.com', array(
        '//pageblanks.', // 180922 ltj9kfphzdzdk1hqpzrfxg FB 1.1
        '//npserver.', // 181216 aphgkhip2yupibhyscekka Yahoo
        '//nothing-new-mail.', // 190202 no27qg2i8y8mdua48lriew Yahoo
        '//triradiate-reenlist.',  // 181019 llj6ljmhm33ogipamuzycq Nepal Army
        '//beac-it-section.',  // 181019 grirjy64gplzghmhgdwqoq Nepal Army 1.5
        '//lorben1717aa.', // 190202 cb5mxhzcooxzbozo1a0uhq eBay
        '//oggomekmflgiojt.', // 190210 rascozv6rsn49cjyi7jf3a eBay
        '//resistant-boxcar.', // 190213 vwzb7d2o6zgtoj3h9ctmba eBay
        '//1234567899876544322.', // 190309 elubacfcrnsmjuvtpgoz8w Nepal Army
        '//semipalmate-cleanse.', // 190310 777stlwkskrv24ff608zuw Yahoo 1.10
        '//timeous-assembly.', // 190313 aphgkhip2yupibhyscekka Live
        '//mailingsestesmofnuclearauthorityofsrilanka.', // 190313 2cdqm9nnxy5mkki2duzkfa Sri Lanka Army
        '//productional-circum.', // 190325 e4b18hoarzd0zcf2aoclng Live
        '//ytgjytytrytyjtttryrtreyrtrfhfhrtrerdcnbvj.', // 190324 wakovlhz7po8tqdtgvaprw
        'https://login.live.com/ppsecure/post.srf', // 1.15
        'PayPal',
        '//safeuseofaccount.', // 190402 jtxhmsguvbkirc5wxxqbvq Sri Lanka Army
        'Sign in', // 211116 cdhhx6ccgzuuplpiihyprw Outlook
        'Mailbox Upgrade Portal', // 211202 gbncywhddjj6gpeoct79za Mailbox upgrade portal
        'Webmail', // 211202 smmoqu3y2js9fplodefimg Webmail
        'Email Address', // 211202 smmoqu3y2js9fplodefimg Webmail
        'bankofamerica', // 220905 secure-bankofamerica-00 BOA
        '//logincdn.msauth.net/', // 221004 outlook-live-account-protection MS
        'Microsoft', // 221004 outlook-live-account-protection MS
        'password', // 221118 bn3muarqndnpzpnzez6ueq Dropbox
        'microsoft', // 230113 outlook-live-account-protection20 MS
    ) ),
    // Google phishing
    array( '//forms.melodysoft.com', array(
        '1ga2018', // 180805 789i5eyzfkd8g9mzimeaow 2.1
        '3030ga1', // 181105 ijzl2nt9zogszimvm9rvya
    ) ),
    // FB phishing
    array( 'Facebook', array(
        'Facebook &copy; ', // 200118 a3zl2blksup5vtuko91ajq
        'action="others.php"', // 220922 aytcfbr2fzua1w2ogycfcq FB
    ) ),
    // Spotify credit card phishing 180314 oimvail1h7p6gc1xqizdda
    array( 
        array(
            '//submit.jotform.me/',
            '//submit.jotform.com/',
        ), array(
            '72602140361443', 
            '80521184061447',
            '230750795328058', // 230329 facebook-log-in-page-6.on.drv.tw FB
    ) ),
    array( '//submit-form.com/', array( // 5.0
        '/LDluckq_Zc7tZp_lCjwg9', // 200917 i1cbfwcx6idqqihaao345q Outlook
    ) ),
    array( 'unescape', array(
        '%2F%2F%74%61%70%69%73%76%65%72%6E%61%2E%6E%65%74%2F', // 191130 qgysgzyuxkui1b5higwoxa
        '%4e%65%74%66%6c%69%78', // 201108 gc1nsygqyfjosdzcybfgma tm1cpeigr5hgnqvfgdlpmg Netflix
        '//eac.by/', // 201123 5oaniqpvwpkjay607sgcow Adobe
        '//maroviana.com.br/', // 201129 hsistgzayfahjahdmehzyq Word
        '//www.manazelspecialists.com/', // 201213 hsistgzayfahjahdmehzyq Word
        '%2F%2F%77%77%77%2E%6C%6F%67%69%6E%2D%75%6E%69%63%72%65%64%69%74%2E%69%74%2F', // 210209 se21pn9datgz4gbm27fpbg Unicredit login
        '%64%65%6D%6F%66%6F%72%63%65%40%62%6B%2E%72%75', // 210220 vsodujgt4l3t6nfov7t0ig MS login (demoforce@bk.ru)
        '//video099s.tk/', // 210220 ozv3ksjybxzyug47bmaryq pnq4o5lyzswhimhoirdzbq grwqapjfzitozpmlzbv0pa FB login
        '//scanner.gs/', // 210222 zgphuuz8gutgjwzsqbeama Adobe log-in
        'PDF%20Online', // 210222 zgphuuz8gutgjwzsqbeama Adobe log-in
        'eval(unescape(', // 210303 2wag32vqfdsv3nzermj9ba MS Word phish
        '%3C%66%6F%72%6D', // 220217 ywqk6ipvgg6b6z0p3gtrlg MS phish
        '.glitch.me/', // 220330 
        '%6C%62%70%69%61%63%63%65%73%73%2E%63%6F%6D', // 220401 redirectlandbankphlogin Landbank lbpiaccess.com
        '%2F%2F%74%2E%6C%79%2F', // 220401 redirectlandbankphlogin Landbank //t.ly/
        '%4C%41%4E%44%42%41%4E%4B', // 220401 redirectlandbankphlogin Landbank LANDBANK
        '%75%6E%65%73%63%61%70%65', // 220401 redirectlandbankphlogin Landbank unescape
        'navyfederal.org', // 230123 40mphbmwbldhpq9pvj1vya Navy Fed Credit Union 
    ) ),
    '//tapisverna.net/', // 191204 aengiriis7gtbi1gk77udw
    '//anixtcr.com/', // 191219 xae0vkk5xybqxv1whk7bvq
    // Amazon
    '//sasme-dame.com/', // 190417 94ydtn7olp8dyjmzujmvyq
    // Instagram
    '//submit.jotformeu.com/', // 190430 ynprjzquonncry6lwyat4a // 10.0
    // Adobe
    '//hindischool.ahma.org.au/', // 190430 zclz5ssulnrmom15gt5iow
    // PayPal phishing
    'PауPаl', // 190411 y723zl1jzzrx6mkgfg7etw 50 d0 b0 d1 83 50 d0 b0 6c
    'РауРаl', // 190430 hthdvnasryg4ohdmrj6bhw qqjcak7fci0ja9dst20sow d0 a0 (P) d0 b0 (a) d1 83 (y) d0 a0 (P) d0 b0 (a) 6c
    'РaуРаl', // 190501 driu09recv5rxbyr7zaylg d0 a0 61 d1 83 d0 a0 d0 b0 6c
    '//schoolproject65.xyz/', // 190224 y2f6zbd2m39jawkncqpufw // 15.0
    '//xspecter.com/', // 190423 hthdvnasryg4ohdmrj6bhw
    '//myxserver.com/', // 190430 driu09recv5rxbyr7zaylg
    // Sakura Internet
    '//www.mcb.ie/', // 190510 87czg9lmzqhjrle0zcjuhw
    // Chase Bank
    '//resources.chase.com/', // 190522 eqmc6gkkij3uezitpxk6hq
    // Encoded forwards
    array( '\x2F\x2F', array( // 20.0
        '\x2F\x2F\x78\x72\x62\x70\x64\x2E\x77\x69\x6E\x2F', // 190524 xlgt7da0ssyry0zwvlxycq //xrbpd.win/
        '\x2F\x2F\x6D\x65\x64\x69\x61\x2E\x74\x69\x74\x73\x2D\x67\x75\x72\x75\x2E\x63\x6F\x6D\x2F', //media.tits-guru.com/
        '\x2F\x2F\x69\x6D\x61\x67\x65\x73\x2E\x73\x65\x78\x2E\x63\x6F\x6D\x2F', //images.sex.com/
    ) ),
    // Obfuscated 'function' word
    'ufcnitno', // 190524 xlgt7da0ssyry0zwvlxycq
    // Malicious forwards
    array( 'http-equiv=\'refresh\' content=', array(
        'Mini 999 :: 1559181438', // 190708 ugokg89euxv5j1rscalk4w mj58xffsnvpuajwu73cxiq
    ) ),
    '//www.fuyoagroup.com', // 190725 3wwcfzdai8zkug20tpqrkg BOA
    // Masqueraded page with password
    array( '<input ', array( 
        '\u006f', // 190730 h6gzqvmlvdbqlvgm2uys9a pjvzxu1itnmafzoniio42w Microsoft login with Unicode 'o'
    ) ), 
    // Jio apk
    array( 'Jio', array( // 25.0
        'Download Now(.apk)', // 190909 or77iitd3rxh1knxdvax1q jbqgyehhdihqgemponyt0w
        'Download Now(.APK)', // 200329 oqtnsz6usaknhb6zygx1xg
        'Download-Now (.APK)', // 200413 j3fb9bzwlxtltmp3szfolg
    ) ),
    array( 'Download Now', array(
        'Offer', // 200415 t85jczmsuvokna40lzz4ag
        'Tiktok', // 210223 qjztngxqfq70mrgtamrksq r6fjl8cbpjtrxpo7jzs8bg Tiktok
        'Airtel', // 210412 zss05ig1is9mox7eci9fva Airtel
    ) ),
    'Banca en Línea', // 191113 6y3zelqlstbiutogfjgf9q
    'becofinance.online', // 191123 mdbrzvk4zyptshylsgq9wq
    '//bizlight.club/', //200423 6d9nmzu2a0kjw0bdrlkweq LinkedIn signin
    'Call Microsoft', // 200423 ovhslrdycjrsp3pn8asena Virus scare // 30.0
    array( array( 
        '<iframe ',
        '<frame', 
        ), 
        array(
        '>.::.<', // Fake iframe logins .::. 
        '//qfreeom.live/', // 200629 s3iklb0meui06lydagw3za
        '//jobopensapp.club/', // 200629 ax8a5gbgufi4wfwkjicmmw
        '//qfreeom.club/', // 200629 ivxuagw4heu1nhzzqvfqzg
        '//itsindev.site/', // 200707 l6uzc3vrjgajedsxxqzkww
        '//recruiterpro.cloud/', // 200711 2dt1oelaykx79gfqdhmqja
        '//supportstrongwebpage.xyz/', // 200711 v6m9omolom4fvughj8zfuq
        '//apexos.top/', // 200711 sadkqfcsy7zqyhcxxrpq4a
        '//itsindev.pro/', // 200711 sadkqfcsy7zqyhcxxrpq4a
        '//searchbrand.live/', // 201011 hlz1fofm0hvzs06sazyeva 
        '//ushiringfinder.work/', // 201019 tzt92zkolq8j1pnjh4cl3w
        '//searchglob.club/', // 201019 gpcujcbtuj6eweltd5yd0g
        '//freedklose.work/', // 201021 zfw0nk5ma9xfgr7rwwatkw
        '//norprom.cam/', // 201124 smjqw3bjiujypzg7sdrrlg MS
        '//greatsparkjobs.work/', // 210222 5ehm30sj4fzfwr4ddhgucg MS login 
        '//customers.d3qpq2o8isw1pg.amplifyapp.com/', // 210614 yszdd9w8tm3nwcg1k5pq5w BT
        '.clarklsons.com/', // 210910 210914 pgvnung4j95yv7o5fgg6nw BT
        '//www.indiancakepoint.in/', // 210921 wxy6k2yv6rsyld4w5vzypg SBB
        '//www.dicsinstitute.in/', // 210921 wxy6k2yv6rsyld4w5vzypg Coupon free
        '//www.kljproperties.in/', // 210921 wxy6k2yv6rsyld4w5vzypg RBB
        '.volatilewriter.com/', // 211213 23piadnzbdonizbvrfewtw nzlue6py7bcnunja63yi3a FB
        '//wzzjcpnq.realsofting.com/', // 211216 vvavipvesldlxdtlbzmz3q FB
        '//scamatoo.com/', // 220119 pvdmve7ncbs9nckm5h3i8a Ncell scam
        '//xmanonline.com/', // 220201 zjc41229zpbv4k9jssglpw FB phish
        '//tewatewatewafdsa333.blogspot.com/', // 220201 f2rkj1gxmjjzaijqkuft7a FB phish
        '//rb.gy/', // 220214 uf0zv7zdknf6qjt6eqfvqg FB phish
        '//is.gd/', // 220217 6qkely2ipwk79q1kxnsvug FB phish
        'automatesolutions.co.in', // 220402 onlinelandbankredirectaccess Landbank
        '//qurdi.com/', // 220519 y95zrlofegmzytab20c6ug FB phish
        '//aqworx.com/', // 220527 ayrl9gxngz40lqudurfrqw FB phish
        '//umissinjir.ml/', // 220529 newmsgrcolors202222222 Messenger phish
        '//umissinjiro.cf/', // 220529 newmsgrcolors202222222 Messenger phish
        '//injiro.club/', // 220529 pprwy1czzdcr8tzzyrdima Messenger phish
        '//2012ta.com/', // 220607 krfp2gbgecucvyjasqmavw FB phish
        '.inmotionhosting.com/', // 220622 cxims7mbahu6kxigno5guw
        '//jopoffer82.online/', // 240930 yjrgemhzopaiirzyjdtxzia FB phish
        '.newxmanonlinegoo.site/', // 241001 yjrgemhzopaiirzyjdtxzia Messenger phish
        '.xmanhub.xyz/', // 241008 aaaxmanhubxyzaaaxmanhubxyz iframe phish
        '.xmanportal.xyz', // 241017 moxmanportalxyzmoxmanportalxyz iframe phish
    ) ),
    'This computer is BLOCKED', // 200806 cnye7r2ptvi5jylmbsocow
    '//sandale.culture-meridienne.online/', // 200913 MS phishing in French
    '//centralamaz.webador.com/', // 200930 Redirect to Amazon phishing site
    array( 'msauth', array( // MS phishing // 35.0
        '//sharedfiles.mywire.org/', // 201011 xmawocdlyto3zzehrerzhg
        '//askdrpeggy.com/', // 201015 xmawocdlyto3zzehrerzhg 6xmapnhzqzwcz81zbdzuqg
        '//andes-ingenieria.com/', // 201012 zw6icjn3q6uykoldkerpzw
        '//abramge.com.br/', // 201015 uz76n2xvdw2h2qd86u1n5a
        '//ob-gyn.com/', // 201020 bzyp43zurwfd7ssyj9goyw
    ) ),
    'staticrypt-password', // 201015 txxf5fb3akdc1qne2a9i1g
    array( '<form', array( // Phishing forms
        '//rb.gy/', // 220730 5royzo2inmsgn1use7ua2w bank/FB phish
        '//www.ban-co.cloud/', // 180826 62uxafckwtlqujrxbfcorq
        '//www.abanca-app-web.eu/', // 180907 y3isdgpo2su5hw9j8afezg fgy8udfs8mhfepseeabowa woowj8jbjazw9nxos1n04w
        '//37.49.224.118:8000/', // 190418 zqpqm5gxwvfm9bfjtedtsw Chinese Postmaster
        '//lili123.eu5.org/', // 201021 zud7axahwnrhf6dhhbwqjg Orange
        '//xtreme-trade.com/', // 201105 ytnzior4j1cvskzphdqzzw Alitalia
        '//cnadnoreste.edu.mx/', // 201109 z1vhkgztxfjxplitzpleqw Researchwonks
        '//submit-form.com/', // 201109 i1cbfwcx6idqqihaao345q Microsoft
        '//conceptworkroomsouth.com/', // 201109 efnvbuwjzlyr9kkldlatdw Microsoft
        '//%63%6e%61%64%6e%6f%72%65%73%74%65.%65%64%75.%6d%78/', // 201112 efnvbuwjzlyr9kkldlatdw Outlook
        '//incruisestakumi.com/', // 201124 klyucuyxlamushdgseoacg Outlook
        //'//hamptonvirginialaws.com/', // 201118 pfpkpqpdum2gzjz5pz6y0a Outlook
        '//payssages.org/', // 201213 4vkjkwex22wbmemxbmimva Webmail
        '//nitto-support.com', // 201213 jgcjqqtrruazy7sdesmola Outlook vqnu6p6jxe7htnsgxxz5kq MS
        '//dshdy2tm7jxy3if6tkyybw0wydu769rglpqvdmyatkm.com/', // 210111 ozmmdmfly0ob6rsgyfcjja Office365
        '//drummerscandystore.com/', // 210122 4ktpqbd4nuvstjhzxmmuig O365
        '//config.wmglobal.org/', // 210122 4ktpqbd4nuvstjhzxmmuig O365
        '//yourtrendymarket.com/', // 210125 9mmthizph34qysmxhz5k7q O365
        '//hawaiiantravelstore.com/', // 210127 qvkyyafc0s5jont0gepe6q O365
        '//www.kloetegroup.com/', // 210209 qsqxvynvfja6dibw2bwrhg Heritage email
        '//bodrummedkitchen.com/', // 210212 lmuorn7lhf2jo8rzeyxqww AU Human Services email
        '//config4rd84ckevpc9o62rb1mciios6.online/', // 210217 8hv4ahtjkjbskuu6smzujg O365
        '//a78anow.co.uk/', // 210222 lmuorn7lhf2jo8rzeyxqww egzjgxfixmox2e9ksrzhpg Webmail
        '>Secured login Session?</span', // 210222 lmuorn7lhf2jo8rzeyxqww egzjgxfixmox2e9ksrzhpg Webmail
        'login.php', // 210222 ps336hhua8qxhajpu1qzma FB phish
        '//shared-documents.serveuser.com/', // 210223 0lvjvcjjxrl0vzflopc8oq LinkedIn
        '//document-view.dns-report.com/', // 210301 rlvktgujbuwj7lfbacp7xg LinkedIn
        '//8kjkl379887897jjj98760898099io08098780908098t9h688h79899989080.c1.biz/', // 210301 wd9y6sx2kuks6dgz9ccagg BOA
        '//authconfigsetup.alterdomuslu.com/', // 210301 zl4fakjrthfzvo0hl7bzra MS
        '//cloud-view.dynssl.com/', // 210308 LinkedIn
        '//authconfig.sharefilemml6oyrt5ogvhlib0gmtbcc4anfozf.com/', // 210308 MS
        '//www.savefeetsavelives.co.uk/', // 210312 rlvktgujbuwj7lfbacp7xg LinkedIn
        '//portal.kibs.ch/', // 210312 te1ebkd6vyewsgk4a2sz4g Google
        '//blackhouseclub.com/', // 210316 sgoqjj2sx5s4sbdiffkldw Optusnet
        '//contandolaciencia.com/', // 210412 sgoqjj2sx5s4sbdiffkldw Webmail
        '//serpentcards.co.uk/', // 210412 sgoqjj2sx5s4sbdiffkldw Webmail
        '\x20Microsoft\x20', // 210419 pfpkpqpdum2gzjz5pz6y0a MS phish
        '//ferretcard.com/', // 210503 ujegycmw5wtbnctljexgwq Webmail
        '//logo.clearbit.com/', // 210524 fdfb2pxkyrjmysijvzvzfw Webmail
        '//www.innerconstellations.com/', // 210525 ujegycmw5wtbnctljexgwq Cyber Drive Illinois
        'globalservicemail.com', // 210614 yszdd9w8tm3nwcg1k5pq5w AOL quoted string
        '//japaskijobs.bar/', // 210622 blwicoq7ozvnvwby0fmvtq MS
        '//unitedhealthgroupmail.work/', // 210622 ealkw79an1vkoeqhjn1h0g MS
        '//japaskijobs.bar/', // 210622 mxbdrbwmm91syzr09oxwmg MS
        '/gmail-email/index.php', // 210622 * MS
        '//bleepkseekers.cyou/', // 210622 ofmbzyei86kk8u3cdkcqlq pbn58kotvvmk1j1eo6zozg MS
        '\x20Voicemail\x20Login', // 210630 4vkjkwex22wbmemxbmimva Voicemail Login
        '//18ff1b98c055.ngrok.io/', // 210702 uotvvppgqvldsa6xvude2a SBI Online
        '//comptronix.net/', // 210722 kcyd74gzpogmb1mp095xnq MS Sharepoint
        '//youmengmob.com/', // 210726 jjmyhsbn9fsivb7igirosg email login
        '//courtbeam.com/', // 210726 jjmyhsbn9fsivb7igirosg email login
        '//accumulationprimary.com/', // 210726 jjmyhsbn9fsivb7igirosg email login
        '//fledgebypudding.org/', // 210801 yflv60691keaxeex1mqo0q email login
        '//smlawyers.co/', // 210801 jjmyhsbn9fsivb7igirosg email login
        '//www.limpermax-srl.com.ar/', // 210827 zn4yh7cwozsta9x8zsrwda email login
        '//anytime27genesh.com/', // 210903 zn4yh7cwozsta9x8zsrwda email login
        '//untangledfilms.ca/', // 210903 zn4yh7cwozsta9x8zsrwda email login
        '//healthtechaz.info/', // 210903 zn4yh7cwozsta9x8zsrwda email login
        'Username is reauired', // 210903 zn4yh7cwozsta9x8zsrwda email login
        '//f1dd-185-252-221-67.ngrok.io/', // 210905 5yryyukuhuulnb7qggqebq SBI phish
        '//cbeconsulting.fr/', // 210906 eylpjhbrigzkp3pum55azw email login
        '//www.ismaelc.com/', // 210907 eylpjhbrigzkp3pum55azw email login
        '//midwestmillworx.com/', // 210907 eylpjhbrigzkp3pum55azw email login
        '//margaretgreanias.com/', // 210907 eylpjhbrigzkp3pum55azw email login
        '//shortyscustoms.com/', // 210907 eylpjhbrigzkp3pum55azw email login
        '//luxebywhit.com/', // 210913 0dvofei2rb7fizvyorak0a account login
        'Valid email is: a@b.c', // 210913 0dvofei2rb7fizvyorak0a account login
        '//lionofflanders.com/', // 210913 0dvofei2rb7fizvyorak0a account login
        '//lapausapodcast.com/', // 210913 0dvofei2rb7fizvyorak0a account login
        'login100-form-title p-b-34 p-t-27', // 210913 0dvofei2rb7fizvyorak0a email signin
        '//indocap.in/', // 210913 0dvofei2rb7fizvyorak0a email signin
        '//goldadvisoryllc.forwardjump.com/', // 210914 oubizk3czan0bgqeyqreoq signin
        '//www.rgbhub.online/', // 210914 mdez6fd5ccqiwq2z7rvbdg Kotak bank
        '//giftswap.co/', // 210915 rnzzoqmz0bwhv38ppiuzow fax message
        '//www.diwanhub.online', // 210915 mdez6fd5ccqiwq2z7rvbdg Kotak bank
        'Kotak_Mahindra_Bank_logo.png', // 210915 mdez6fd5ccqiwq2z7rvbdg Kotak bank
        '//www.incredibleindia.tech/', // 210915 mdez6fd5ccqiwq2z7rvbdg SBI
        '>Fill Your Details<', // 210915 mdez6fd5ccqiwq2z7rvbdg SBI
        '//karanikolas.us/', // 210917 rnzzoqmz0bwhv38ppiuzow sign in
        '//julycollects.com/', // 210917 rnzzoqmz0bwhv38ppiuzow sign in
        'background-icolor', // 210917 rnzzoqmz0bwhv38ppiuzow sign in
        'return eval("(function', // 210921 0dvofei2rb7fizvyorak0a MS
        '//allbollyhub.xyz/', // 210921 eylpjhbrigzkp3pum55azw email
        '//kelijenningsphotography.com/', // 210921 eylpjhbrigzkp3pum55azw email
        '//luckyxdesigns.com/', // 210924 zn4yh7cwozsta9x8zsrwda sign in
        '"fa fa-fw fa-eye field-icon toggle-password"', // 210924 zn4yh7cwozsta9x8zsrwda sign in
        '//claim-defense.com/', // 210930 rllofe0bnj3xmb1ups58vg welcome
        '//laicreazioni.com/', // 210930 rllofe0bnj3xmb1ups58vg welcome
        '<input type="password" name="password" class="form-control" placeholder="Password" required>', // 210930 rllofe0bnj3xmb1ups58vg welcome
        '//raymanrock.com/', // 211001 rllofe0bnj3xmb1ups58vg voicemail
        '<input id="password" placeholder="Enter password" name="password" type="password" class="textbox" autofocus required autocapitalize="off" autocomplete="password" tabindex="2" autocorrect="off" aria-describedby="emailHelp" />', // 211007 ww9ark8q1degdutpiejljg doc access
        '//kingselectrics.co.uk/', // 211011 jfrgzglmpvfo5rj31wzc4a login
        '//enovent.ir/', // 211011 jfrgzglmpvfo5rj31wzc4a login
        '.inxwdez-onffi.online/', // 211019 s6asw9czb8v85anpohzh4w login
        '//www.premierpawn.com/', // 211025 dsb5cxqaiv38vrpymiarga Sharepoint
        '//mail.pusula.yunethosting.rs/', // 211025 n19havucyrwwgyxuz10m8q generic
        '//oscar-williams.com/', // 211025 n19havucyrwwgyxuz10m8q generic
        '//karlhenke.com/', // 211025 n19havucyrwwgyxuz10m8q generic
        '//margaritakaprielyan.net/', // 211028 dxaaf6csq8admoh1qipgnq Outlook
        '//aptekabsmpgenesh.com/', // 211028 dxaaf6csq8admoh1qipgnq Outlook
        'method="POST" name="logonForm', // 211028 dxaaf6csq8admoh1qipgnq cdhhx6ccgzuuplpiihyprw Outlook
        '//acupuncture.guru/', // 211028 dxaaf6csq8admoh1qipgnq Outlook
        '"form-signinvvvvvvm', // 211028 dxaaf6csq8admoh1qipgnq Outlook
        '//knight-build.co.uk/', // 211028 dxaaf6csq8admoh1qipgnq Outlook
        'name="best100"', // 211028 dxaaf6csq8admoh1qipgnq Microsoft
        '//tnhealth-dmv.org/', // 211029 fntvzdjecachjjzizzpnxw MS
        '//artem4x4shiola.com/', // 211029 vepzzwfrrk1zvsrzadyfra Outlook
        '//trees.servpaneleu.online/', // 211030 vepzzwfrrk1zvsrzadyfra Outlook
        '0utlook web App', // 211102 jfrgzglmpvfo5rj31wzc4a Outlook
        '//patimisfersyllenesliopa.com/', // 211102 jfrgzglmpvfo5rj31wzc4a Outlook
        '//apjroxdawrldgenesh.com/', // 211102 jfrgzglmpvfo5rj31wzc4a Outlook
        '//dbyaspznze.com/', // 211102 jfrgzglmpvfo5rj31wzc4a sign in
        '//disaplurntorsyllenesliopa.com/', // 211102 jfrgzglmpvfo5rj31wzc4a Outlook
        '//aprilreyngenesh.com/', // 211103 cdhhx6ccgzuuplpiihyprw Outlook
        '//epavxicair.com/', // 211103 cdhhx6ccgzuuplpiihyprw Outlook
        '"corportate-title"', // 211103 cdhhx6ccgzuuplpiihyprw Outlook
        '//caucecampeche.com/', // 211103 cdhhx6ccgzuuplpiihyprw sign in
        '//cosmicsecret.in/', // 211103 cdhhx6ccgzuuplpiihyprw Outlook
        '//web.cytrack.com/', // 211103 cdhhx6ccgzuuplpiihyprw Outlook
        '//apelsinka1991genesh.com/', // 211113 cdhhx6ccgzuuplpiihyprw MS/Rackspace
        'blacksmithstudio.co.in/', // 211209 g07r3qsyc0uqeslyrujlgq pzxbiwtrof9asghraqbuxq Email Verification Survey
        '//un-titled.co/', // 211209 lh3ruf5dqdkl6j2firs98a SharePoint
        '//laneish-logistics.co/', // 211209 cdejl27wywlpneivx7vfia Blockchain.com
        '//admirallogistics.com/', // 211213 58y3o8weklrrqqmbfupcag MS
        '//passwordreset.microsoft', // 211213 58y3o8weklrrqqmbfupcag MS
        '//geoconsultantservices.com/', // 211216 qthkzwhw23z5gslif4yfwq MS
        'correct Password', // 211216 d70jqy7zd9dgcshpk6kgjg Webmail Admin
        'B&#97;&#110;&#107;&#105;&#110;g', // 220107 elhkt8gwozhev3hi5gxbgq 53 Banking
        '//whatsgrouplink.jewarairportcompensation.com/', // 220109 uxqdz2tnzzvmosoi6kzdxq MS 
        '.okzk.com/', // 220113 gy8tcaevahti2k44oyhrgw Orange
        '//paxful.com/', // 220222 lz64lmznev2oulhukzcomq Paxful
        'facebook-c3643', // 220222 lz64lmznev2oulhukzcomq FB
        'Crédit Mutuel', // 220303 guh8gjdnlh0u1fqtxlbxka Crédit Mutuel
        '//eager-dewdney.194-26-213-194.plesk.page/', // 220303 guh8gjdnlh0u1fqtxlbxka Crédit Mutuel
        '//5bbmfzhoqpt62h124w.', // 190409 hprm5bbmfzhoqpt62h124w (.cf) bmzjwizndh7zz0djnnvbfq (.tk)
        '//liveformhq.com/', // 190403 7q6jhayztunzbhoncvvivg kymjnqjhh0nguh6ncusagg Outlook Web App
        'formbucket.com/', // 190430 190510 7q6jhayztunzbhoncvvivg Outlook Web App
        '//cfodept.weebly.com/', // 220405 1rqwljpme9fjbv0ij3roow Dropbox download
        '//lanecustomhearing.com/', // 220502 fdbakjddnbv3cmhkpjnrzg Excel download
        '.facturaelectronica.sat.gob.mx/', // 220510 verificacion-fdi-factura-electronica-sat-gob-mx DDoS
        '//frejhsh.esy.es/', // 220528 6nmdiukveoklbwxunwcica Alaska Credit Union
        '//oofglokh.esy.es/', // 220528 6nmdiukveoklbwxunwcica Alaska Credit Union
        '//adfsarrayfe1.mohawkcollege.ca/', // 220603 universityoftorontoupdate UofToronto ish
        '//adfs.dawsoncollege.qc.ca/', // 220603 universityoftorontoupdate UofToronto
        '1FAIpQLSdQtwiZRmCGXxItvZ_yDdn5WmDsIm_HWTsocLLbdfk27FUx6A', // 220603 universityoftorontoupdate UofToronto
        'UTORid', // 220603 universityoftorontoupdate UofToronto
        '//www.wellsfargo.com/', // 220607 acctonlineaccessrecovery Wells Fargo
        '//walink.indiax.com/', // 220614 tdxup5ir21b15lazqi5igw DropBox
        '//nguyenthuwann.my.id/', // 220629 instagram-seeingpicture IG
        '&#111;&#102;&#102;&#102;&#105;&#99;&#101;', // 220815 offfice phish
        '.repl.co/', // 220815 Bancolombia/Davivienda phish
        'discord.com/api/webhooks/', // 220905 redirect-lnstagram-logi POST to discord
        '//thegirlsproject.org/', // 220912 drzoar7zxnzaphofmhjxea Dropbox
        '//billpspc.online/', // 220924 rfbrx9zulo6z121xwja2ya SBI
        'Microsoft Support', // 230113 o9h8lldsafvatqm0m9znaw MS Support
        'Bank of America', // 241009 o0wzbr54ezd4zctrvcdnxg BOA phish
        'sc0m.com', // 241229 facebook01login12fha47b FB phish
    ) ),
    array( array( 
            '"refresh"',
            '"Refresh"',
            '%22refresh%22',
        ),
        array( // Redirection
            '//armstrongchile.com/35ty6rfvh/', // 180912 oxqeujzomojsl28ympnzya Wells Fargo
            '//bushupen.web.app', // 201105 pzoyyn2ng29rgt1xr70jba 
            '//securebnl.webador.com', // 201107 p85pbemlshawn1beomgqcq
            '//deefomecall.web.app', // 201109 2uzp6rfe7evotweigvsdoa
            '//mcrabelta.web.app', // 201124 3wcjutdzgzrzqbcdyyxzcq
            '//ncretianpe.firebaseapp.com', // 201124 o1yzlsc87sx6pe2xh03j2w
            '//customdebate.com/', // 210416 ekrgbrlb8trq8zsjiye1ag
            '//tikti123.blogspot.com/', // 210416 bk36gh3qlnf88bwckt3mdg Tiktok
            '/onsemni.com', // 210416 lbf4e3bz9t01ywzfdbpyeq Sharepoint
            '//app-sharepoint.herokuapp.com/', // 220207 txzo5nchn3oep0zvo49vpq Sharepoint
            '//buildpro.bodait.com/', // 220208 fntvzdjecachjjzizzpnxw Office
            'rebrand.ly/', // 220314 unionbankverification1312 Forwarded phish
            'lbpiaccess.com/', // 220331 redirectlandbankphlogin Landbank        
            'rb.gy', // 220331 redirectlandbankphlogin Landbank
            'in 5 sec', // 220331 redirectlandbankphlogin Landbank
            'LAND BANK', // 220331 redirectlandbankphlogin Landbank
            '//jpeg.ly/', // 220401 onlinelandbankredirectaccess redirectlandbankphlogin Landbank
            '//t.ly/', // 220401 onlinelandbankredirectaccess redirectlandbankphlogin Landbank
            '//grabify.link/', // 220401 onlinelandbankredirectaccess redirectlandbankphlogin Landbank
            'myredirectph.info', // 220402 redirectlandbankphlogin Landbank
            '//onlinebdoredirectbdo.github.io/', // 220302 ifc6sewwuveplxw7zmsoqa BDO Banking
            'www.online-bdounibankph.com', // 220302 ifc6sewwuveplxw7zmsoqa BDO Banking
            'firebasestorage.googleapis.com', // 220329 redirectlandbankphlogin Land Bank
            '//t.ly/', // 220329 redirectlandbankphlogin Landbank
            'www.royalweddings.in', // 220330 redirectlandbankphlogin Landbank
            'abre.ai', // 220331 redirectlandbankphlogin Landbank "abre.ai"
            '&#97;&#98;&#114;&#101;&#46;&#97;&#105;', // 220331 redirectlandbankphlogin Landbank "abre.ai"
            'landbank', // 220331 redirectlandbankphlogin Landbank
            'www.google.com/u', // 220331 redirectlandbankphlogin Landbank
            'www.udaipur-icai.org', // 220331 redirectlandbankphlogin Landbank
            '//lnkd.in/', // 220401 onlinelandbankredirectaccess Landbank
            '//englandconsultoffice.org/', // 220413 vzmnukppcjmzckpfzalotq remittance
            '//wordpress-52243183129.devrimsdemo.com/', // 220422 m6ohfn0oiiaawwxxtzwr4a Ruralvia bank
            '//traffic2bitcoin.com/', // 220429 7jyp9jecgrmbjc90p8fgaw Landbank
            '//sites.google.com/view/redirectmybank/', // 220502 7jyp9jecgrmbjc90p8fgaw Landbank
            '.wpengine.com/', // 210614 eda9g9wit1vhvizfb40efg YouSee
            '.amplifyapp.com', // 240504 ojywiwyzki2mgpzneex8wa FB phish
            '.page.link/', // 241223 https-maintenances-new-gcash Gcash phish
            '.exe', // 250619 ndjvmuvkoahnopfqn2jpva ANTI VIRUS UPDATE.ClientSetup.exe
        ) 
    ),
    '(619)924-0036', // 201107 u0iyproombvm22ariizomg iPhone Support
    '866-376-1402', // 201107 u0iyproombvm22ariizomg Windows crash 40.0
    array( 'data:text/html;base64,', array( // Encoded HTML
        '<embed', // 201105 pzoyyn2ng29rgt1xr70jba e70mnsno3prm8kl5zg16bg Outlook
        '<iframe ', // 210614 yszdd9w8tm3nwcg1k5pq5w MS 210728 ovuegb14htmfykhhdhiztw BT
    ) ),
    array( array(
            '.location',
            'location.replace',
            'location[',
        ), 
        array(
            '//sharedfiles.corporateonline-careerview.com/', // 210217 srjhvzytmenioi1t5zowsq Sharepoint
            '//vdsp.ca/', // 220117 uxqdz2tnzzvmosoi6kzdxq Office Netcraft #27400601
            '//electricalpages.com/', // 220126 uxqdz2tnzzvmosoi6kzdxq MS phish
            '//fuqw.com/', // 220126 gy8tcaevahti2k44oyhrgw MS phish
            'rebrand.ly/', // 220210 1buex9ibd1zc6y7vlvwtja BDO Banking
            '//1buex9ibd1zc6y7vlvwtja/', // 220212 ifc6sewwuveplxw7zmsoqa BDO
            '//cbt.hasama.co.id/', // 220218 ifc6sewwuveplxw7zmsoqa BDO
            '//ifc6sewwuveplxw7zmsoqa/', // 220218 ifc6sewwuveplxw7zmsoqa BDO
            '//u.to/', // 220222 elskzy6usvfmqhcr3nf2sw forward
            '//grabify.link/', // 220301 cmc9foxsxz0d6nk4wwsapq BDO
            'Math.random()*urls.length', // 220301 ifc6sewwuveplxw7zmsoqa BDO
            '//firebasestorage.googleapis.com/', // 220401 redirectlandbankphlogin Landbank
            '//ilhsgeafuv.web.app/', // 220116 pa4zhzjz9kq3qfx0k8zn6g Redirect to flagged content
            '//uhgvxnlqch.web.app/', // 220116 pa4zhzjz9kq3qfx0k8zn6g Redirect to flagged content
            'abre.ai', // 220331 redirectlandbankphlogin Landbank
            'newAdditionalURL', // 220331 redirectlandbankphlogin Landbank
            'charactersLength', // 220331 redirectlandbankphlogin Landbank
            'lbpiaccess.com', // 220331 redirectlandbankphlogin Landbank
            '//tr.shadowcord.com/', // 220406 aq7oticuwalytd8lasksvq Amazon from Home
            '//trck.bdtouk.com/', // 220408 6zzvzvisep2pizifkwzxxa Amazon from Home
            '//englandconsultoffice.org/', // 220413 x32x4dygsmjqur6mpx3xdw Office
            'verify/bdo-online', // 220609 qwcczhubswd3cden1xpalw BDO Online
            '//cloud.make-money-online.cf/', // 220712 o6vt0flf6vruvsame98cfa WhatsApp
            '//smrturl.co/', // 230530 giftcards-walmart-2023 ad
            '//alctbilisi.ge/', // 250206 sharesecureddocumentfile
            '.powerappsportals.com', // 250505 e3xnmfhkgzczjzhrut1rkg
            '.glitch.me/', // 250523 zslkzshfu5ebvd89yfzvhg
            '.mittalslabglassware.com', // 251027 b9belphnajw1hfbosyc7ya
            '//krjconstruction.net/', // 260310 694lhiyy6goizx1vnuz24q
        ) 
    ),
    '//servicies1.com/', // 201213 mpgptfo78vdbzxmp1n6jrw FB script
    array( 'PayPal', array ( // PayPal phish
        'Enter your password', // 210220 nwrcdivayezdk6ztputrlw
        '//www.paypalobjects.com/webstatic/', // 200928 wxdxbbgzivsfdkfjzkmg3w
        '.exe"', // 230316 gc2dadbssbekrhchtdwjbg UltraViewer_setup_6.5_en.exe
    ) ),
    array( '//formspree.io/', array ( // 45.0
        'Verification Code', // 210217 zz2p1hgmy83htqsks9imqa BOA
        '(c) Microsoft', // 210714 jexgsba7k0rzwrnifn5qiq Purdue
        'type="password"', // 211120 jve9ezolk03dt08yzilsxg IG
        'Facebook', // 220912 5dbisnt8uqvgqxbms4d2eq FB
    ) ),
    array( 'document.write(', array(
        '%%3Chtml', // 210302 7l3n9xpyraev3lzaz6b9iq MS Word 
        '//next-sharepoint-logo.herokuapp.com/', // 220114 qp6p9bmm5rbxb1ylrzrz4w SharePoint PDF
        '%3c%73%63%72%69%70%74%3e', // 210302 luzuyoatwbketx5lzg9wxq MS Word
        '//document-view.vizvaz.com/', // 210330 g228wzoztrsbmhdlenpzeq j88z4s0gsoimvzcxqn3poq MS
        '//uatimacagro.com/', // 210830 ftklldecc9tzzgxdcjr0cw MS
        '//yoondavid.com/', // 210902 gfgzgmmnbizftftrio4scw MS
        'MIRCBOOT', // 210902 gfgzgmmnbizftftrio4scw MS
        'ProgramasProgramacion.com', // 210905 ebqr8xomhvrpyprnb5puwq FB phish
        '//anypoint.com.au/', // 211116 cdhhx6ccgzuuplpiihyprw Adaptive login
        '//ctntalbk.com/', // 211117 zfubpuhuixsf3cei1lxtuw Adaptive login
        '//reubbilitysyllenesliopa.com/', // 211117 zfubpuhuixsf3cei1lxtuw Adaptive login
        'nHk7jPxa3hr-DRk2opAFo-ib', // 211117 zfubpuhuixsf3cei1lxtuw Adaptive login
        '//belleciarclesyllenesliopa.com/', // 211117 zfubpuhuixsf3cei1lxtuw Adaptive login
        'This User-ID is not found:(Status: Invalid)', // 211123 hdio7a3oz0vvngo8bkzykg Adaptive login
        '//hourlyfinancialadvisors.com/', // 211124 hdio7a3oz0vvngo8bkzykg Adaptive login
        '//catenaliggettsyllenesliopa.com/', // 211124 hdio7a3oz0vvngo8bkzykg Adaptive login
        '//memoryimatic.com/', // 211124 hdio7a3oz0vvngo8bkzykg Adaptive login
        'b-dangerrhide', // 211126 hdio7a3oz0vvngo8bkzykg Adaptive login
        '//barameefilms.com/', // 211217 zzvpkll4r72pm401enyeya Adaptive login
        '<label>REMEMBER ME</label>', // 211217 zzvpkll4r72pm401enyeya Adaptive login
        'traditionallyobjectlessblinked.com/', // 220108 qbvrmyxauarpinkhkyzxpg (movie-hub.ml) Various phishes
        '//habiterie.boite-des-merveilles.com/', // 200322 1galyyybmrszeljk9276jq Orange log-in
        'unescape(atob', // 230908 85mvyegzhaatly8wf4tu4w We Transfer
        'window.atob("aHR0cHM6', // 231016 zlvrbzquvzbdutt0gdiqvk MS phish
        'decodeURI("".concat(', // 231016 yrzfhoejlbm46ecqhfasuw We Transfer phish
        'String.fromCharCode(s.charCodeAt(i)', // 231016 yrzfhoejlbm46ecqhfasuw We Transfer phish
        'Facebook', // 260322 www-facebook-com-profile FB phish
    ) ),
    array( 'data:text/html;base64', array(
        'BT Internet', // 210316 yszdd9w8tm3nwcg1k5pq5w BT Internet
        'LinkedIn', // 210322 gmtnwhlmpw1dqyoyjcf9fa LinkedIn
        '<object data=data:text/html;base64', // 210308 burv4nd6rgxsujjtsehlsa MS login
    ) ),
    array( 'Chase', array( 
        '//expert-adv.com/', // 210326 jcdc5wg0etwz3jdpowesza Chase
    ) ),
    array( 'linkedin', array(
        '<linkedin-logo', // 210330 drfd7u2sm1nt0wyjdoeigg LinkedIn
        'id="linkedin-logo"', // 210330 drfd7u2sm1nt0wyjdoeigg LinkedIn
        'type="linkedin-logo"', // 210516 gqszwp14zjqojbofhrtrkg LinkedIn
    ) ),
    '//fingerprintjs.co.uk.to/', // 210503 efuw68tjdjhojan5d8a06g Redirect Global // 50.0
    array( 'lodscrip', array(
        'paypal', // 210604 wvz3ctzevyptgozvjjz2tg PayPal
        '\'createElement\'', // 210720 wvz3ctzevyptgozvjjz2tg PayPal
    ) ),
    'Incoreect email Address', // 211124 hdio7a3oz0vvngo8bkzykg Outlook
    array( 'websocket', array( 
        '//keys0.openode.io', // 210722 swsrtoxivziee4yveqejxw LCL Bank
    ) ),
    array( 'processor.php', array(
        '//khanomhana.ir/', // 210810 lsdeuyphomwueygnm7xfpa MS
    ) ),
    array( 'McAfee', array( // 55.0
        'client-1da92ad2f44ae6d3f0a00502e0742cf0', // 210910 pfa6rsfaunhivmtmatotow McAfee
    ) ),
    array( 'wallet', array(
        '//sbjdigital.in/', // 211025 lmuorn7lhf2jo8rzeyxqww Import wallet
        'Elon Musk', // 211025 zwtl0tet8eohsi8o8xliow 5a4qamyivrgwzfepigsmuw gn1jhysexqbikigxh4cmsq BTC ETH DOGE
    ) ),
    array( '//script.google.com/macros/', array(
        'Bank', // 221113 ud5hz3u86gvtoev5kcdxza SBI
        'Instagram', // 221124 6cws7ufjdugxgfhcevwl5w IG
        'careerbuilder', // 221113 wrpbkoerzxqcj306umungg careerbuilder
        'Zimbra', // 240820 i6wugnpc9zg0haziagcicg Zimbra
        'blob', // 250722 eclkz7ylt7w2v2g7bnbxbg Blob phish
    ) ),
    array( 'Error: the code has been tampered!', array( 
        'eval("(function', // 211111 oouz5rtzn5j8xw60m6hfow Adaptive login
    ) ),
    array( 'src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAA', array(
        'g_fFcs=', // 211120 zfubpuhuixsf3cei1lxtuw Outlook
        '//uttaranchalboard.org/', // 211203 zzvpkll4r72pm401enyeya Outlook
        '0UTL00K', // 211203 zzvpkll4r72pm401enyeya Outlook
    ) ),
    array( '.cloudfront.net/', array( // 60.0
        'Netflix', // 211121 rbxgs6o2do0zqmvwmowb2w Netflix
    ) ),
    array( 'Private computer</span>(', array(
        '//gradualo.com/', // 211124 hdio7a3oz0vvngo8bkzykg Outlook
    ) ),
    array( 'Congratulate ', array(
        'Amazon', // 211213 qzzhz74k3kquihtgzue2aa Amazon gift card
    ) ),
    array( '//www.kshared.com/', array(
        'number0', // 211213 00lwzkqi2jwdk9wr8gnxka Child porno
    ) ),
    array( 'window.open(', array(
        'SharePoint', // 211221 8ylg9zplrq8sxulbtdbfww SharePoint download
        '//cloud.whatapp.tk/', // 220712 o6vt0flf6vruvsame98cfa Adult Tiktok
    ) ),
    array( '.s3.amazonaws.com', array( // 65.0
        '\'+makeid(0x32)+\'#\'+email', // 220103 qthkzwhw23z5gslif4yfwq SharePoint download
    ) ),    
    array( 'Wallet', array(
        '//usebasin.com/f/', // 220103 www.cryptoconnectpro.live Crypto phish
        '//formcarry.com/', // 220115 0gjvj7a8eydbjz3au8anbg Crypto phish
        '[XR&CO\'2014]', // 220116 0gjvj7a8eydbjz3au8anbg Crypto phish
        'Wallet</h5>', // 220120 lmuorn7lhf2jo8rzeyxqww Crypto phish
    ) ),
    array( 'CPABUILDSETTINGS', array(
        'Free Fire', // 220116 rha0554tptz0b2xoguw9eq Diamond500k fake generator
        'Coinmaster', // 220116 z0h9zcgz8jbaoztua4wgtq Coinmaster fake generator
        'CPABuildLock', // 220116 z0h9zcgz8jbaoztua4wgtq CoinMaster fake generator
        'fortnite', // 220117 www.reward-game.cf Fortnite bucks generator
        'FORTNITE', // 220117 www.reward-game.cf Fortnite bucks generator
        'CPABuildGetFeedURL', // 220117 www.reward-game.cf Clash of Clans
        'Pes ', // 220312 cgmhlca755tlv9ja8ebsvw PES 2021
    ) ),
    array( 'Binance', array(
        'bc1qmcnvumqgjemvg6pmf6x6l2e4tzqpsxteyfqyn6', // 220116 xyhpjqmir3kff7wjxv4dwa Binance BTC phish
        '0x4Adb3281986A00B930e9ab20aC40c8e903e35939', // 220116 xyhpjqmir3kff7wjxv4dwa Binance ETH phish
        'bnb17rughqw4sw7c8uz0sa3nufmnh9mex3twpprqke', // 220116 xyhpjqmir3kff7wjxv4dwa Binance BNB phish
    ) ),
    array( '//formsubmit.co/', array(
        'Messenger', // 220123 scfzpsoixn4vf3s7rkok2g Facebook phish
        ' Instagram</ti', // 220206 652lbi7fqthfgrgegkkvbg Instagram phish
        'newedate@gmail.com', // 220301 bvwiqdyooowklw2ddgstig e-invoice phish
        '//assure.ameli.fr/', // 220504 djccykg7cmvf6f4jqzr0ga Ameli phish
        'instagram', // 220206 instagramir8fazm5v8czwn57tgntq3 Instagram phish
        'Microsoft', // 230219 toantoefjksc1d2zi3htsw MS phish
    ) ),
    array( 'Free Fire', array( // 70.0
        '//locked1.com/', // 220129 n8zhoznprzhn2bgwuuzqbg Free Fire Diamond generator
        '//verifysuper.com/', // 220129 n8zhoznprzhn2bgwuuzqbg Free Fire Diamond generator
        '//verifyspot.net/', // 220129 n8zhoznprzhn2bgwuuzqbg Free Fire Diamond generator
    ) ),
    array( 'cPanel', array(
        '//fxtit.xyz/', // 220129 p8vzznpe5bdvetrvjbngfg cPanel phish
    ) ),
    array( 'Coinbase', array(
        'Buy/Sell Digital Currency', // 220202 9jygdygpiq36cgb6ek0rzq Coinbase
    ) ),
    array( '$("body").load(', array(
        'index.png', // 220301 qqjcak7fci0ja9dst20sow PayPal code in PNG
    ) ),
    '+61 1800 329 044', // 220321 3psly3zyaqzzthvdrrp8tq PayPal phish
    array( 'wetransfer', array( // 75.0
        '.glitch.me', // 220321 mzp3z1vkxufhx7t4v1mb8q Wetransfer trojan
    ) ),
    array( array(
            'Password',     
            'password', 
        ), array(
            '>Messenger', // 220326 yzo7qdtkhz0vhozkgawgcg Facebook login phish
            'IONOS', // 250211 6wxazqxncclzjleyarsh8w IONOS phish
        ) ),
    array( 'lbpiaccess.com', array( // 220401 onlinelandbankredirectaccess Landbank
        '//ofbank.com.ph/',
        '&#x74;&#x2e;&#x6c;&#x79;', // t.ly
        'LAND BANK',
        '//grabify.link/'
    ) ),
    "myredirectph.info", // 220402 onlinelandbankredirectaccess Landbank
    array( array(
            'account<', 
            'ACCOUNT<',
            'LANDBANK', // 220401 redirectlandbankphlogin Landbank
        ),
        array(
            'lnkd.in', // 220402 onlinelandbankredirectaccess Landbank
            'lbpiaccess.com',
            'rebrand.ly',
            '/t.ly',
        ) ),
    array( 'atob', // 80.0
        array(
            'Ly9zZW5kYXJnby5zYnMv', // 220413 allbaba-passport-signn Alibaba
            'passport.alibaba.com', // "
        ) ),
    array( 'https://discordapp.com/api/webhooks/',
        array(
            'instagram', // 220425 copyrightsupportinstagram IG
        ) ),
    array( '//api.telegram.org/bot',
        array(
            'BANCOLOMBIA', // 221202 nz9l7llbqxjy20z6ly7zcq Bancolombia
            'document[_0x', // 240301 cfeahxsssb97pwddbbetga Zajil
            'Instagram', // 240820 mm2eoqsy65lsikuarsjsva Instagram
            'cardnumber', // 250307 netflix3jlapw80yjuicxnuafrwdq Netflix
            'Microsoft.com', // 250512 lvk0xzbgphyam67oqtmeza MS
            'DHL', // 250525 lvxqchraxc1vlhacqkpodw DHL
        ) ),
    array( 'POST',
        array(
            '//rsender.space/', // 220607 acctonlineaccessrecovery Wells Fargo phish
            's4lim09.com', // 240504 home-login-postfinance PostFinance phish
            'jkdluxlimo.com', // 240504 home-login-postfinance PostFinance phish
            'aliiedshipdocks.com', // 240619 gqryzoypabdzarkjot9qxq hannoverblocks phish
            'pheeltechmast.com', // 240627 1dqluiqeaqx9jcntoyzz4q webmail phish
            'xlkq.xyz', // 240707 ggxf1cdbtiirpv41wihqhg Dropbox phish
            'zpzaz.org', // 241123 2g0bxeux2apdsqthl0fjrg Adobe phish
            '@bsdmail.com', // 251029 okxfqzajh0nnjsrzn5g9ew Mail Server phish
            'outletin.pw', // 251106 m9uea9da7jahiitgywwwkq Microsoft phish
            'Adobe', // 251112 st1dhstdw2mwxaqofaxuew Adobe phish
        ) ),
    array( 'Microsoft',
        array(
            '//microfficefiles.github.io/', // 220624 microsoftvoicemailnote Microsoft phish
        ) ),
    array( '体育', // 85.0
        array(
            '亚博', // 220906 dmuhxkjnzoxkhgexzfuj5g CN sports app
        ) ),
    array( 
        array(
            'Continuar',
            'Click', // 251204 constterhomecentrobrou
        ),
        array(
            'Bancolombia', // 221106 bring2tothecomedisihof Bancolombia
            'Banca e-BROU', // 251204 constterhomecentrobrou
        ) ),
    array( '<button',
        array(
            '//yu-mail.net/', // 221109 instagram-pictureimage IG phish
        ) ),
    array( '<script',
        array(
            '//mitmassets.ml/', // 230316 bancolombia phish
            '"\x6C\x6F\x63\x61\x74\x69\x6F\x6E"', // 221016 zlvrbzquvzbdutt0gdiqvk We Transfer phish
            '"\x73\x6C\x69\x63\x65"', // 221016 yrzfhoejlbm46ecqhfasuw MS phish
            'Roblox.BundleDetector', // 240808 loka25-ait-rbx-site-ca Roblox phish
            'entrer votre e-mail', // 240927 j3bwzeqnoarnowedz0grna OneDrive phish
            'votre identité', // 240930 bbmvet5izr847nz0rzuxtq OneDrive phish
            //'\\\\\\\\\\', // 241106 grmheysujmgbs6vf0njmuq Chase phish
        ) ),
    array( 'crisp',
        array(
            '447bbf5d-979b-4eb2-ab7d-47627ac1719c', // 230908 live-chat-support-1052 Revolut
        ) ),
    array( 'charCodeAt(i)', // 90.0
        array(
            // False positives 251218 'unescape', // 210524 7701nit1b0xoxcccdozlrg FB phish
        ) ),
    array( 'Roblox',
        array(
            '.w3spaces.com', // 240420 get-free-xrobux-250k-now0 Roblox phish
            '.cloudfront.net/', // 240809 n1etg4uszdkvhhljpdlhyq Roblox phish
        ) ),
    array( 'processData',
        array(
            '#submitBtn', // 240503 nmzmmthfrztvuzwrzxujia WeTransfer phish
        ) ),
    array( 'Blockchain',
        array(
            ') to the address you sent it from.', // 240504 ay1duru9fat8fsco8gjj3g Eth phish
        ) ),
    array( 'Blockchain',
        array(
            ') to the address you sent it from.', // 240504 ay1duru9fat8fsco8gjj3g Eth phish
        ) ),
    'nocodeform.io/', // 240717 5gzlhshejv0lrqcznvbzvq Webmail phish 95.0
    array( 
        array( 
            'DOWNLOAD', // 241025 4wwvdg8fvo0kiepxaisjba GeekSquad phish
            'Norton', // 251129 gc2dadbssbekrhchtdwjbg Norton Antivirus phish
        ),
        array(
            'UltraViewer', // 241025 4wwvdg8fvo0kiepxaisjba GeekSquad phish
            'iperiusremote', // 241025 4wwvdg8fvo0kiepxaisjba GeekSquad phish
            'rustdesk', // 241025 4wwvdg8fvo0kiepxaisjba GeekSquad phish
            'anydesk', // 241025 4wwvdg8fvo0kiepxaisjba GeekSquad phish
        ) ),
    array( '.firebaseio.com',
        array(
            'instagram', // 241222 w5wz3hjcfquh6okvtmtvcw IG phish
        ) ),
    '//internetbanking.caixa.gov.br/', // 211029 ekjnuacfkj0tsj9xtplopq Caixa Bank
    array( 'emailjs',
        array(
            'password', // 250712 email-verification-tbw2hsczpwmnv0lvrtfpuq-authentification ECS
        ) ),
    array( 'send.php', // 100.0
        array(
            'Password', // 250822 ms-sharepoint-files-download MS phish
        ) ),
    array( 'Credit Card',
        array(
            'Netflix', // 260109 netflix3jlapw80yjuicxnuafrwdq Netflix phish
            'netflix', // 260109 netflix3jlapw80yjuicxnuafrwdq Netflix phish
        ) ),
    '_Izgl5ltm', // 260213 servicionfinancier0sdavi Banco Davivienda phish
) ;

$blacklist_copyright = [
    [ 'lm0fjmdds5ur7ujrwtogma.on.drv.tw', '/live/cricket-live-003.html', 
        'the International Cricket Council (“ICC”)' ],
    [ 'lm0fjmdds5ur7ujrwtogma.on.drv.tw', '/live/match-pc-0002%20-%20Copy.html', 
        'the International Cricket Council (“ICC”)' ],
    [ 'ccftvg4uzfrhxzexqzml9q.on.drv.tw', '/metlife/download.html', 
        'MarkMonitor Anti-Fraud Operations Center on behalf of MetLife' ],
    [ 'o9h3yjkzgg8s4neijtyzxg.on.drv.tw', '/PorucznikKabura/Dok8.htm', 
        'Horizontal Line Ltd. on behalf of CDA S.A.' ],
    [ 'o9h3yjkzgg8s4neijtyzxg.on.drv.tw', '/PorucznikKabura/Dok9.htm', 
        'Horizontal Line Ltd. on behalf of CDA S.A.' ],
    [ 'o9h3yjkzgg8s4neijtyzxg.on.drv.tw', '/PorucznikKabura/Dok10.htm', 
        'Horizontal Line Ltd. on behalf of CDA S.A.' ],
    [ 'o9h3yjkzgg8s4neijtyzxg.on.drv.tw', '/PorucznikKabura/Dok11.htm', 
        'Horizontal Line Ltd. on behalf of CDA S.A.' ],
    [ 'o9h3yjkzgg8s4neijtyzxg.on.drv.tw', '/PorucznikKabura/Dok14.htm', 
        'Horizontal Line Ltd. on behalf of CDA S.A.' ],
    [ 'o9h3yjkzgg8s4neijtyzxg.on.drv.tw', '/PorucznikKabura/Dok15.htm', 
        'Horizontal Line Ltd. on behalf of CDA S.A.' ],
    [ 'oqtnsz6usaknhb6zygx1xg.on.drv.tw', '/Documents/primeapps.html',
        'Reliance Industries Limited' ],
    [ 'oqtnsz6usaknhb6zygx1xg.on.drv.tw', '/Documents/4gapps.html',
        'Reliance Industries Limited' ],
    [ 'oqtnsz6usaknhb6zygx1xg.on.drv.tw', '/Documents/mynet.html',
        'Reliance Industries Limited' ],        
    [ 'cmeueueubqxkldyg68tmea.on.drv.tw', '/starta/i.html',
        'Reliance Industries Limited' ],
    [ 'cmeueueubqxkldyg68tmea.on.drv.tw', '/box/',
        'Reliance Industries Limited' ],
    [ 'zupzjkcol4gtrxfkgnuebg.on.drv.tw', '/www.fltbank.com/',
        'ADCB Bank, Dubai' ],
    [ 'ttvlqyqax7zjawx9ialupg.on.drv.tw', '/btinternet/webmail.html',
        'BT' ],
    [ 'zaugkjwneemtzmwfqneu4w.on.drv.tw', '/www.fltbank.com/',
        'ADCB Bank, Dubai' ],
    [ 'zaugkjwneemtzmwfqneu4w.on.drv.tw', '/www.fltbank.com/',
        'ADCB Bank, Dubai' ],
    [ 'get-free-250k-robux-now1.on.drv.tw', '/free/',
        'Bolster Inc.' ],
    [ 'yl2reyxs0dwbmadjiwqylw.on.drv.tw', '/Moha/sickleave1.html', // 240829 yl2reyxs0dwbmadjiwqylw Seha phish
        'AWS on behalf of Seha Services' ],
    [ 'xiqry0ebmgsfu9i9nrsvgw.on.drv.tw', '/saha%20(1.5).html', // 241120 xiqry0ebmgsfu9i9nrsvgw Seha phish
        'AWS on behalf of Seha Services' ],
] ;

$blacklist_email = [
    'yofab22664@hutov.com',
    '301chinor@bk.ru',
    '@oletters.com',
    '@consultlog.biz',
    '@stephensmith.com.au',
    '@starkitchenequipments.com',
    '@brofoodsupplier.com',
    '@digiboxes.com.mm',
    '@moquetasenferias.com',
    '@lapeds.com', // 240804 pinetwalletp2punlock2024 Pi wallet phish
    '@misstol.com', // 240808 loka25-ait-rbx-site-ca Roblox phish
    '@epsteinatlanta.org', // 240809 n1etg4uszdkvhhljpdlhyq Roblox phish
    '@tcfcincy.com', // 240820 i6wugnpc9zg0haziagcicg Zimbra
    '@witaz.com', // 240820 mm2eoqsy65lsikuarsjsva Instagram
    '@gillesperrault.com', // 240927 j3bwzeqnoarnowedz0grna OneDrive phish
    '@activexpertise.fr', // 240930 bbmvet5izr847nz0rzuxtq OneDrive phish
    '@jopoffer72.online', // 240930 yjrgemhzopaiirzyjdtxzia FB phish
    '.newxmanonlinegoo.site', // 240930 yjrgemhzopaiirzyjdtxzia FB/Messenger phish
    'xmanhub.xyz', // 241008 aaaxmanhubxyzaaaxmanhubxyz iframe phish
    '@staffordprosegur.co.site', // 241009 o0wzbr54ezd4zctrvcdnxg BOA phish
    'xmanportal.xyz', // 241017 moxmanportalxyzmoxmanportalxyz iframe phish
    '@sgatra.com', // 241025 4wwvdg8fvo0kiepxaisjba GeekSquad phish
    '@berlin.com', // 241106 grmheysujmgbs6vf0njmuq Chase phish
    '@echo.tax', // 241120 xiqry0ebmgsfu9i9nrsvgw Seha phish
    '@ak-personalmangement.de', // 241123 2g0bxeux2apdsqthl0fjrg Adobe phish
    '@bulatox.com', // 241222 w5wz3hjcfquh6okvtmtvcw IG phish
    '@rabitex.com', // 241223 https-maintenances-new-gcash Gcash phish
    '@mhrv.co.uk', // 250206 sharesecureddocumentfile
    '@abdoboard.org.uk', // 250211 6wxazqxncclzjleyarsh8w IONOS phish
    '@protonmail.com', // 250307 netflix3jlapw80yjuicxnuafrwdq Netflix
    '@withgod.or.kr', // 250505 e3xnmfhkgzczjzhrut1rkg
    '@safrica.com', // 250512 lvk0xzbgphyam67oqtmeza MS
    '@voxsupplyxchain.com', // 250523 zslkzshfu5ebvd89yfzvhg
    '@arvi.at', // 250525 lvxqchraxc1vlhacqkpodw DHL
    '@greenleafib.com', // 250712 email-verification-tbw2hsczpwmnv0lvrtfpuq-authentification ECS
    '@osxofulk.com', // 250722 eclkz7ylt7w2v2g7bnbxbg Blob phish
    '@wnbaldwy.com', // 250722 vczgdczduwpgiffqlq7wua Blob phish
    '@sports9.win', // 250822 ms-sharepoint-files-download MS phish
    '@mailto.plus', // 251027 b9belphnajw1hfbosyc7ya redirect phish
    '@metec-eg.org', // 251029 okxfqzajh0nnjsrzn5g9ew Mail Server phish
    '@cisamrein-fr.com',// 251029 57mpzyjk6po0ey2zu0l4yg Mail Server phish
    '@jephy-webmail.com', // 251106 m9uea9da7jahiitgywwwkq Microsoft phish
    '@accountant.com', // 251112 st1dhstdw2mwxaqofaxuew Adobe phish
    '@bitcoinssupports.com', // 251129 gc2dadbssbekrhchtdwjbg Norton Antivirus phish
]
?>