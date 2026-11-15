<?php
// Privacy policy: what HB Hub stores about you, why, who can see it, the cookies it sets and how to get it deleted.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too.
// Open to everyone, like the frontpage, and so in Danish like every public page (the section ids stay English, as
// other pages link to them). Keep it in step with the code: when a new table, cookie, localStorage key or outside
// service is added, it belongs here, and HBHUB_PRIVACY_UPDATED gets the date of the change. It's set in
// assets/php/legalUpdated.php, since the footer needs it too: changing it there shows everyone who agreed to the old
// policy the "privacy policy updated" box.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/legalUpdated.php';

// The cookies the site sets: name => [what it's for, how long it's kept]. All of them are needed for the site to work
// (logging in, the login lock, bans), so the cookie notice (footer.php) only tells you about them, it doesn't ask.
const HBHUB_PRIVACY_COOKIES = [
    'hbHubSession' => ['Holder dig logget ind. Indeholder et tilfældigt id, der peger på dit login.', '30 dage, eller til du logger ud'],
    'hbHubPendingLogin' => ['Husker, at du har skrevet den rigtige adgangskode (eller er logget ind med Discord), mens du skriver din totrinskode.', '5 minutter'],
    'hbHubDiscordSignup' => ['Sættes, når du kommer tilbage fra Discord efter at have klikket på Opret dig med Discord. Indeholder, krypteret så kun HB Hub kan læse det, dit Discord-id, brugernavn, visningsnavn, dit billedes id og din bio, så oprettelsesformularen kan starte med dem, og din nye konto bliver koblet til den Discord-konto. Fjernes, når din konto er oprettet, eller du vælger ikke at koble den til.', '30 minutter'],
    'hbHubDiscordState' => ['Sættes, når du klikker på Log ind med Discord, Opret dig med Discord eller kobler din Discord-konto til. Indeholder en tilfældig kode, hvad du startede, og den side, du skal tilbage til, så kun din browser kan gøre det færdigt, når Discord sender dig tilbage. Fjernes, så snart du er tilbage.', '10 minutter'],
    'hbHubDevice' => ['Genkender en browser, du har logget ind fra eller er logget ind i, så nogen, der gætter din adgangskode, ikke kan låse dig ude af den. Det er også sådan, en spøgelsesudelukkelse bliver givet videre til en ny konto, der bruges i den samme browser, og sådan side-admins ser, hvilke konti der bruges i den samme browser. De gratis spil på /play tjekker også, om den er der, og sender en browser, der har været logget ind før, til login og videre til det normale spil.', '180 dage'],
    'hbHubBanned' => ['Sættes, når en udelukket konto bruges i browseren, så den ikke bare kan oprette en ny konto.', '400 dage, eller til kontoen ikke længere er udelukket og logger ind igen'],
    'hbHubToast' => ['En kort besked til den næste side, som "Your password was changed".', '60 sekunder'],
];

// What the site keeps in your browser's local storage (and IndexedDB). It never leaves your browser, except where the
// text says so.
const HBHUB_PRIVACY_LOCAL_STORAGE = [
    'hbhub-chat-emoji-recent' => 'De emoji, du senest har brugt i chatten, i beskeder og reaktioner, så de kommer først i emojivælgeren, og når du reagerer.',
    'hbHubWatchdog' => 'Hvor mange gange denne fane har fået en "server optaget"-fejl (508), og fra hvilke adresser på HB Hub, indtil rapporten om det er nået frem til serveren (se Rapporter om optaget server ovenfor). Gemmes i session storage, så det er væk, når du lukker fanen, og slettes, så snart rapporten er gået igennem.',
    'hbHubChatListScroll' => 'Hvor langt ned i chatlisten du havde scrollet, så den stadig står der, når du genindlæser eller kommer tilbage til chatten. Gemmes i session storage, så det er væk, når du lukker fanen.',
    'hbHubNotifiedMessageId' => 'Den seneste besked, du fik en browsernotifikation eller lyd for, så du ikke får den to gange.',
    'hbHubNavPolledAt' => 'Hvornår en af dine HB Hub-faner sidst tjekkede for nye beskeder til browsernotifikationer eller lyden (bare et tidspunkt), så de faner, du ikke kigger på, ikke alle tjekker på én gang.',
    'hbHubChessSound, hbHubMatadorSound, hbHubUnoSound, hbHubMinerSound, hbHubSlotsSound' => 'Om du har slået spillenes lyde (eller spilleautomatens) til eller fra.',
    'hbHubMinerLateKeys' => 'Om du har skiftet Ore Mining Inc.s genvejstaster 1-9 og 0 til dine sidste ti bygninger (med opgraderingen Long-Range Hot Keys).',
    'hbHubMinerAutoBuy' => 'Om du har slået Ore Mining Inc.s Auto Buy til eller fra (med opgraderingen Auto Buy).',
    'hbHubMinerKeysHelp' => 'Om Ore Mining Inc. allerede har vist dig popuppen om genvejstasterne (efter du købte opgraderingen Hot Key Panel).',
    'hbHubMinerAutoWarp, hbHubMinerAutoCrunch' => 'Om du har slået Ore Mining Inc.s Autopilot og Auto Crunch til eller fra (med færdighederne af samme navn).',
    'hbHubMinerAutoWarpTimes, hbHubMinerAutoCrunchTimes' => 'Det tal, du har skrevet ved Ore Mining Inc.s Autopilot- og Auto Crunch-knapper: hvor mange gange så meget Dark Matter eller Antimatter et Warp Jump eller Big Crunch skal give, før de laver det.',
    'hbHubMinerRun:<tab id>' => 'Mens Ore Mining Inc. er åbent: det, du har gjort i spillet, som ikke er gemt endnu, og hvad en nedbrudsrapport ville sige (se Casino og spil ovenfor), opdateret hvert sekund. Går spillet ned eller bliver lukket, før det er gemt, bliver det, du gjorde, sendt med den første gemning, næste gang du åbner det, ligesom en normal gemning, og så slettet. Det slettes, når spillet lukkes uden noget tilbage at gemme. Efter et nedbrud bliver nedbrudsrapport-delen, til du har svaret på, om den må sendes; den sendes kun, hvis du siger ja.',
    'hbHubMiner (IndexedDB)' => 'En nedbrudsrapport, som spillet skriver, hvis det fryser, mens du spiller (skrevet af en del af spillet, der bliver ved med at køre, når resten er frosset). Den sendes kun, hvis du siger ja, næste gang du åbner spillet, og slettes uanset hvad. Spiller du uden en konto, bliver den aldrig sendt, bare slettet, næste gang du åbner spillet.',
    'hbHubSudokuGuest' => 'Kun i den gratis Sudoku på /play/sudoku, spillet uden en konto: dit spil (opgaven, dens løsning, de tal og noter du har skrevet, tiden og hints indtil nu), og din bedste tid og dit antal sejre på hvert niveau, så du kan lukke siden og fortsætte senere. Det spilles kun i din browser, så intet af det bliver sendt til HB Hub, og det kommer aldrig med over på en konto.',
    'hbHubSudoku' => 'Det spil Sudoku, du er i gang med (med en konto): dets nummer, opgaven, de tal og noter du har skrevet, og tiden og hints indtil nu, så du kan lukke siden og fortsætte senere. Selve spillet, dets løsning og dets tid er på HB Hubs server (se Casino og spil ovenfor); de tal, du skriver, sendes kun, når brættet er fyldt, for at tjekke det, eller med et hint.',
    'hbHubGuestWordle' => 'Hvis du spiller Wordle uden en konto (på /play/wordle): dine spil fra i dag, krypteret af HB Hub, så de ikke kan læses eller ændres i din browser. Det sendes med hvert gæt, så serveren kan tjekke det. Logger du så ind eller opretter dig, bliver det sendt én gang for at tilføje dagens spil til din konto (kun på et sprog, du ikke har spillet i dag), og så slettet. Spil fra en tidligere dag bliver ignoreret og overskrevet.',
    'hbHubGuestWordleRandom' => 'Hvis du spiller Wordles tilfældige ord uden en konto: dit tilfældige ord på hvert sprog med dine gæt, krypteret af HB Hub på samme måde, sendt med hvert gæt, så serveren kan tjekke det. Det kommer aldrig med over på en konto og slettes, når du åbner Wordle logget ind.',
    'hbHubGuestMiner' => 'Hvis du spiller Ore Mining Inc. uden en konto (på /play/ore-mining-inc): dit spil, krypteret af HB Hub på samme måde, og et tilfældigt id for den fane, du spiller i (så spillet kun kører i én fane ad gangen). Det sendes med hver gemning, så serveren kan tjekke, hvad du har gjort. Logger du så ind eller opretter dig, bliver det sendt én gang for at blive din kontos spil (kun hvis kontoen ikke har et endnu), og så slettet.',
    'hbHubGuestMinerRun:<tab id>' => 'Det samme som hbHubMinerRun ovenfor, når du spiller Ore Mining Inc. uden en konto. Det, du har gjort, som ikke er gemt endnu, bliver kun nogensinde sendt med den næste gemning af det spil, du spiller uden en konto, aldrig til en kontos spil.',
    'hbHubGuestPrompt' => 'Hvor længe du har spillet de gratis spil uden en konto (bare et antal sekunder, talt mens siden er på skærmen), så popuppen, der beder dig oprette dig, kun dukker op en gang imellem.',
    'hbHubAppPrompt' => 'At popuppen om at lægge HB Hub på din telefons hjemmeskærm er blevet vist (eller at du allerede har åbnet HB Hub fra hjemmeskærmen), så den kun dukker op én gang.',
    'hbHubCookieNotice' => 'At du har klikket cookie-beskeden væk, så den ikke bliver vist igen.',
    'hbHubTermsAgreed, hbHubPrivacyAgreed' => 'Datoerne for de vilkår og den privatlivspolitik, du sidst har accepteret (ved at klikke cookie-beskeden eller "er blevet opdateret"-boksen væk), så den boks kun dukker op, når en af dem ændrer sig igen.',
];
?>
<!DOCTYPE html>
<html lang="da" class="home">
<head>
    <?php $headerHomeNav = true; $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Privatlivspolitik - HB Hub</title>
    <meta name="description" content="Hvad HB Hub gemmer om dig, hvorfor, hvem der kan se det, de cookies den bruger, og hvordan du får dine data slettet.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <article class="legal-page" aria-labelledby="privacy-title">
            <header class="legal-hero">
                <div class="legal-hero-inner">
                    <p class="home-kicker legal-kicker">Juridisk</p>
                    <h1 id="privacy-title">Privatlivspolitik</h1>
                    <p class="legal-intro">
                        HB Hub er et elevprojekt, ikke en virksomhed. Den gemmer kun det, den skal bruge for at køre chatten,
                        casinoet og spillene. Der er ingen reklamer, intet bliver solgt, og intet bliver brugt til at følge dig
                        rundt på nettet. Denne side forklarer præcis, hvad der bliver gemt, i et almindeligt sprog.
                    </p>
                    <p class="legal-updated">Sidst opdateret <?= hbHubLegalDanishDate(HBHUB_PRIVACY_UPDATED) ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="På denne side">
                    <p class="legal-toc-title" aria-hidden="true">På denne side</p>
                    <ol>
                        <li><a href="#who">Hvem driver HB Hub</a></li>
                        <li><a href="#what">Hvad der bliver gemt</a></li>
                        <li><a href="#why">Hvorfor det bliver gemt</a></li>
                        <li><a href="#see">Hvem kan se hvad</a></li>
                        <li><a href="#services">Eksterne tjenester</a></li>
                        <li><a href="#cookies">Cookies og local storage</a></li>
                        <li><a href="#keep">Hvor længe det bliver gemt</a></li>
                        <li><a href="#rights">Dine rettigheder</a></li>
                        <li><a href="#changes">Ændringer i politikken</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <section id="who" aria-labelledby="privacy-who">
                        <h2 id="privacy-who">1. Hvem driver HB Hub</h2>
                        <p>
                            HB Hub (hbhub.noahgajnielsen.dk) er lavet og drevet af Noah Gaj Nedergaard Nielsen i Danmark, som er
                            ansvarlig for de data, der bliver gemt på den ("dataansvarlig" efter GDPR).
                        </p>
                        <p>
                            Spørgsmål om dine data, eller en anmodning om at downloade eller slette dem: skriv til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>

                    <section id="what" aria-labelledby="privacy-what">
                        <h2 id="privacy-what">2. Hvad der bliver gemt</h2>
                        <h3>Din konto</h3>
                        <ul>
                            <li>Dit navn, og hvis du tilføjer dem: dit profilbillede, din bio, status (en emoji, en kort tekst og hvornår den rydder sig selv; når den har gjort det, bliver den ikke vist længere og bliver overskrevet, næste gang du gemmer din status eller profil), dit uddannelsesmærke (en slags uddannelse, som "IT og medier") og dit banner. Et banner er enten to farver og en retning eller et billede: et bannerbillede bliver gjort mindre og lavet om til JPEG XL i din egen browser, før det bliver sendt (så det ikke gemmer, hvor et foto blev taget), gemt som en fil på HB Hubs server ligesom en vedhæftet fil i chatten, og tager en af dine billetter til vedhæftede filer, så længe det er dit banner. Det udløber aldrig af sig selv: det bliver slettet, når du skifter det, går tilbage til farver, sletter det fra Your attachments i chatten eller sletter din konto.</li>
                            <li>Din klasse: det skoleår, du startede i 1. år på HTX, og din studieretning (X, U, T, Y eller Z), eller at du ikke går på HTX. Din årgang (eller "student", når du er forbi 3. år) bliver regnet ud fra det, så den stiger hvert år den 1. juli, uden at noget bliver ændret. Går du på HTX, er du også medlem af din klasses gruppechat (se Beskeder og chats nedenfor).</li>
                            <li>Din adgangskode, men kun som en envejs-hash. Ingen, heller ikke en admin, kan læse din adgangskode.</li>
                            <li>Hemmeligheden bag dine totrinskoder, hvis du slår totrinslogin til.</li>
                            <li>Hvis du kobler din Discord-konto til (for at logge ind med Discord): dens Discord-id og dens Discord-brugernavn. Id'et bliver kun brugt af HB Hub selv og aldrig vist. Brugernavnet bliver vist under dine indstillinger og, medmindre du slår "Show my Discord username on my profile" fra i Discord-login-kortet under dine indstillinger, på din profil. Det bliver holdt opdateret: det bliver gemt igen, hver gang du logger ind med Discord, og hver nat spørger HB Hubs server Discord om det nuværende brugernavn for hvert tilkoblet Discord-id. Intet andet fra Discord bliver gemt: ingen e-mail, ingen venner eller servere, og ikke den token, Discord giver, som kun bruges én gang til at spørge, hvem du er, og så bliver annulleret. Fjerner du koblingen under dine indstillinger, bliver begge dele slettet med det samme.</li>
                            <li>Opretter du dig med Discord, starter oprettelsesformularen med dit Discord-visningsnavn, profilbillede og din bio (når Discord deler den). De er kun forslag, du kan ændre eller fjerne: det, du beholder, bliver gemt som dit eget HB Hub-navn, -billede og -bio, ligesom alle andres, og bliver ikke opdateret, når du ændrer dem på Discord. Billedet bliver hentet fra Discord af HB Hubs server, så din browser ikke kontakter Discord for det.</li>
                            <li>Dine indstillinger, som hvilke chats du har gjort lydløse, skjult eller blokeret, om nye beskeder giver dig en browsernotifikation eller en lyd, og om du har blokeret Casino og Spil, eller en side-admin har blokeret dem for dig.</li>
                            <li>Hvornår du oprettede kontoen, og hvornår du sidst var på siden (opdateret højst én gang i minuttet).</li>
                            <li>Mislykkede logins, om kontoen er låst eller udelukket, og om en udelukkelse var fra en admin eller automatisk.</li>
                            <li>Om, og siden hvornår, kontoen er spøgelsesudelukket (se Beskeder og chats nedenfor).</li>
                        </ul>
                        <h3>Bandlyste billeder</h3>
                        <p>
                            Når en konto bliver udelukket, kommer en kopi af dens profilbillede, og nogle gange dens bannerbillede,
                            som regel på en liste over bandlyste billeder, sammen med hvilken konto det kom fra, og hvilken admin der
                            bandlyste det. Når en admin fjerner et profilbillede eller bannerbillede, der bryder reglerne, kan de sætte
                            en kopi af det på listen på samme måde uden at udelukke kontoen. Admins kan også selv tilføje billeder til
                            listen. Når du uploader et profilbillede eller bannerbillede, eller opretter dig med et profilbillede,
                            bliver det sammenlignet med listen på to måder: et fingeraftryk af filen (dens SHA-256-hash) og et
                            fingeraftryk af, hvordan billedet ser ud, lavet ud fra en lille gråtonekopi af det, så en skaleret eller
                            let beskåret kopi også bliver fundet. Serveren kan ikke læse et bannerbilledes JPEG XL-fil, så din browser
                            sender en lille PNG-kopi af det (højst 256 pixels bred eller høj) med til det andet tjek; adminens browser
                            gør det samme, når en admin bandlyser et bannerbillede. PNG-kopien bliver kun brugt til sammenligningen og
                            aldrig gemt. Er det den samme fil, eller ser det næsten ens ud med et bandlyst billede, bliver kontoen
                            udelukket med det samme og beholder billedet, så admins kan se hvorfor. Fingeraftrykkene af bandlyste
                            billeder bliver gemt sammen med dem; fingeraftrykkene af andre billeder bliver kun brugt til
                            sammenligningen og aldrig gemt.
                        </p>
                        <h3>IP-adresser</h3>
                        <p>
                            Den IP-adresse, du oprettede dig fra, og den, du senest har brugt. Kun den seneste bliver gemt, ikke en
                            historik. IP-adresser bliver også talt kortvarigt, når konti bliver oprettet, så ét sted ikke kan lave
                            hundredvis af konti, og gemt i 30 dage med en rapport om optaget server (se
                            <a href="#watchdog">Rapporter om optaget server</a> nedenfor).
                        </p>
                        <h3>Logins</h3>
                        <p>
                            Hvert login bliver gemt med, hvornår det startede, hvornår det sidst blev brugt (opdateret højst én gang
                            i minuttet), og hvornår det udløber, plus et kort navn for den browser og det system, det er på, som
                            "Chrome on Windows". Det navn bliver regnet ud fra det, din browser sender (dens user agent), og kun det
                            korte navn bliver gemt, aldrig resten. Det er der, så du kan se, hvor du er logget ind, under Logged in
                            devices på <a href="/settings#devices">indstillingssiden</a>, og logge ud af hver af dem, eller dem alle
                            på én gang. Browsere, du har logget ind fra, bliver også husket ud fra en tilfældig token (kun en hash af
                            den bliver gemt), og hvor mange forkerte adgangskoder de har prøvet.
                        </p>
                        <h3>Browserfingeraftryk</h3>
                        <p>
                            Mens du er logget ind, regner din browser cirka én gang om dagen et fingeraftryk ud af sig selv og den
                            computer, den er på: en SHA-256-hash lavet ud fra, hvordan den tegner et lille billede og afspiller en
                            kort lyd (ingen lyd bliver spillet højt), grafikkortets navn og grænser, hvilke af en liste over
                            almindelige skrifttyper der er installeret, skærmstørrelse og farvedybde, tidszone, sprog, platform,
                            berøringspunkter, processorkerner og hukommelse. Kun hashen bliver sendt og gemt, aldrig det, der gik ind
                            i den, sammen med det korte browsernavn (som "Chrome on Windows"), din konto, og hvornår den først og
                            sidst blev set. Modsat tokenen ovenfor forbliver den den samme efter du har ryddet cookies eller i et
                            privat vindue. Den bliver kun brugt, så side-admins kan se, hvilke konti der bruges i den samme browser
                            (se <a href="#see">Hvem kan se hvad</a>), aldrig til at følge dig på andre sider, og den bliver aldrig
                            delt med nogen.
                        </p>
                        <h3>Beskeder og chats</h3>
                        <ul>
                            <li>Hver besked, du sender, med tidspunktet, hvilken chat den er i, hvad den svarer på, og en eventuel GIF.</li>
                            <li>
                                Filer og billeder, du vedhæfter en besked (op til 10 i én), med deres navn, størrelse og type, hvilken
                                besked og chat de blev sendt i, og hvornår de blev sendt og udløber. De bliver gemt som filer på HB
                                Hubs server, ikke i databasen. Billeder bliver lavet om til JPEG XL i din egen browser, før de bliver
                                sendt (medmindre du sætter flueben i at beholde originalen), så de aldrig skal andre steder hen for at
                                blive konverteret. Et konverteret billede beholder ikke de detaljer, et kamera eller en telefon gemmer
                                i det, som hvor et foto blev taget. Videoer bliver også lavet om til WebM (VP9) i din egen browser, så
                                de kan afspilles i chatten og fylder mindre, og den konverterede video beholder heller ikke detaljer
                                som hvor den blev filmet. Kun hvis du sætter flueben i at beholde originalen, eller din browser ikke
                                kan konvertere den, bliver videoen sendt, som den er. Andre filer bliver zippet på serveren, så de
                                fylder mindre. Har en side-admin givet dig flere billetter til vedhæftede filer eller taget nogle væk,
                                bliver antallet gemt med din konto.
                            </li>
                            <li>
                                Reaktioner, du sætter på beskeder: hvilken emoji, på hvilken besked, og hvornår. Tager du en reaktion
                                tilbage, bliver den slettet.
                            </li>
                            <li>
                                Beskeder, du fastgør i en chat: hvilken besked, at du fastgjorde den, og hvornår, hvilket alle i
                                chatten kan se. At fastgøre eller løsne sætter også en besked i chatten om, hvem der gjorde det.
                                Løsnes den (af dig, en gruppeadmin, den anden person i en privat chat eller en side-admin), bliver det
                                ryddet, hvem der fastgjorde den og hvornår, men beskederne om det bliver ligesom andre beskeder.
                            </li>
                            <li>De chats, du er i, gruppenavne, ikoner og baggrunde, og kælenavne, du giver folk.</li>
                            <li>
                                Kælenavne i en gruppe: et kælenavn, du giver nogen i en gruppe (eller som nogen giver dig), bliver
                                gemt med deres medlemskab af gruppen, og alle i gruppen ser det i stedet for deres brugernavn. At give
                                eller fjerne et sætter også en besked i chatten om, hvem der gjorde det, ved hvem, og hvad kælenavnet
                                er, som bliver ligesom andre beskeder. Når en anden giver dig et, får du også en notifikation under
                                klokken om hvem, hvilken gruppe og kælenavnet (ikke fra en gruppe, du har gjort lydløs, eller fra en, du
                                har blokeret). Kælenavnet forsvinder, når det bliver fjernet, eller når de forlader gruppen eller bliver
                                smidt ud af den. Et kælenavn, du giver den anden person i en privat chat, bliver gemt i chattens
                                indstillinger og kun vist for dig.
                            </li>
                            <li>
                                Mens du skriver en besked i en chat, bliver tidspunktet, du sidst skrev, gemt med dit medlemskab af
                                den, så de andre i chatten ser "... is typing". Kun tidspunktet bliver gemt, aldrig det, du har
                                skrevet, og det bliver ryddet, når du sender beskeden, tømmer beskedfeltet eller holder op med at
                                skrive i et par sekunder. Lukker du siden i stedet, ser ingen det længere efter 8 sekunder. Det bliver
                                slet ikke gemt, mens du er spøgelsesudelukket eller gjort lydløs i den chat.
                            </li>
                            <li>
                                At slette en besked skjuler den for alle i chatten, men selve beskeden bliver gemt, så side-admins
                                stadig kan undersøge anmeldelser af misbrug. Dens reaktioner bliver skjult med den og gemt på samme
                                måde. Vedhæftede filer i den bliver ikke gemt: deres filer og navne bliver slettet for altid.
                            </li>
                            <li>Notifikationer under klokken: hvad der skete, hvem der gjorde det, og om du har set det. Er du side-admin, og en gruppeadmin fjerner dig fra en gruppe, gemmer den notifikation også, hvordan du var i gruppen (din rolle, dit kælenavn, lydløs, og hvor langt du havde læst), så du kan fortryde det. Fortryder du det, bliver notifikationen slettet.</li>
                            <li>
                                Når nogen @omtaler dig, i en chat eller i chatten i et spil skak, Matador eller UNO, får du en af de
                                notifikationer, der siger, hvem det var, og hvilken chat, besked eller hvilket spil det var i. Intet
                                andet bliver gemt for en omtale: det bliver regnet ud fra beskeden, hver gang den bliver vist. Du får
                                ikke en fra en chat, du har gjort lydløs, eller fra en, du har blokeret, og kun mens du ikke kigger på
                                den chat eller det spil. For at vide det bliver det sidste tidspunkt, du havde hver chat åben foran
                                dig, gemt med dit medlemskab af den, og det forsvinder, når du forlader chatten. Sletter nogen beskeden
                                (dig, en gruppeadmin eller en side-admin), bliver notifikationerne om den også slettet.
                            </li>
                            <li>
                                Spøgelsesudelukkelser: en side-admin kan spøgelsesudelukke en konto, der bryder reglerne, uden at
                                fortælle den det. Det, den gør i chats fra da af (beskeder, vedhæftede filer, rettelser, reaktioner,
                                fastgørelser, kælenavne og de chats, den starter eller tilføjer folk til), bliver gemt som alt andet,
                                men markeret, så kun den konto og side-admins kan se det. Folk, den starter en chat med eller tilføjer
                                til en gruppe, bliver gemt som inviteret, markeret på samme måde, så de aldrig ser chatten eller får en
                                anmodning eller notifikation om den, indtil en anden inviterer dem, eller de selv starter en chat med
                                den konto. Markeringerne bliver, når spøgelsesudelukkelsen bliver ophævet, så det, der var skjult,
                                forbliver skjult. De forsvinder med de beskeder og invitationer, de sidder på. For at forhindre, at en
                                spøgelsesudelukkelse bliver undgået med en ny konto, bliver de kendte enheder (cookien hbHubDevice, se
                                <a href="#privacy-cookies">Cookies og local storage</a>) tjekket, når en konto bliver oprettet eller
                                logget ind på: en konto, der højst er 3 dage gammel, og som bruges i den samme browser som en
                                spøgelsesudelukket konto, bliver selv spøgelsesudelukket, og side-admins kan se i adminloggen, hvilken
                                konto den kom fra. Ældre konti bliver ikke påvirket.
                            </li>
                        </ul>
                        <h3>Casino og spil</h3>
                        <ul>
                            <li>Din chipsaldo og hver ændring af den (indsatser, gevinster, de daglige chips, og chips, en side-admin har tilføjet eller taget væk, med hvilken admin det var), og den sidste dag, du hentede de daglige chips, og hvor mange dage i træk det var (din plads i den daglige kalender). Det forsvinder, når din konto bliver slettet.</li>
                            <li>
                                De runder, du har spillet i casinospillene, fx men ikke kun Blackjack, Baccarat, Roulette og Slots
                                (hvert spin: indsatsen, hvor hjulene stoppede, og hvad det gav), brugt til ranglisterne (casinoets
                                forside viser toppen af casinoets rangliste, og hvor mange der spiller ved bordene lige nu, uden
                                navne). Andre ser kun totalerne dér: runder, sejre, nederlag, sejrsprocent, den største gevinst, de
                                chips, du har vundet eller tabt i hvert spil og i dem alle tilsammen, og dine chips lige nu.
                            </li>
                            <li>
                                Roulette er ét bord, som alle på HB Hub deler. Mens du har indsatser på bordet, ser alle, der har
                                Roulette-siden åben, dit navn, dit profilbillede, hver indsats, du har lagt, og om du har trykket
                                Ready. Efter spinnet ser de, hvad du satsede, og hvad du vandt eller tabte på det, indtil næste runde
                                starter. Dine indsatser bliver kun gemt, til hjulet drejer: så bliver de til dit spin i runderne
                                ovenfor (som også noterer, hvilken runde det var, så spinnet kan vises for alle, der spillede det), og
                                de forsvinder, når din konto bliver slettet.
                            </li>
                            <li>Dine spil skak og Matador: pladser, træk, gemte spil, resultater (også når du forlod et spil, før det var slut, hvilket tæller som et nederlag) og chatten i spillet.</li>
                            <li>
                                Dine spil UNO: din plads, kortene på din hånd og det, du spillede, invitationer og chatten i spillet.
                                Kun du ser dine kort, mens spillet bliver spillet; når det er slut, ser alle i det de kort, der var
                                tilbage. Det hele forsvinder, når alle har forladt spillet. Det, der bliver gemt, er én linje per spil
                                til UNO-ranglisten: om du vandt, tabte eller gik, de point, du scorede, hvor mange kort du havde
                                tilbage, hvor mange der spillede, og hvornår. Andre ser kun totalerne dér (spil, sejre, nederlag,
                                sejrsprocent, point og bedste sejr).
                            </li>
                            <li>
                                Dine spil Wordle: for hver dag og hvert sprog, du spiller, de ord, du gættede, om du fik det, og
                                hvornår du startede og blev færdig. De udgør din statistik og dine stimer og din plads på
                                Wordle-ranglisterne. Andre ser kun totalerne dér (spil, sejre, sejrsprocent, gennemsnitlige gæt og
                                stimer), aldrig dine gæt.
                            </li>
                            <li>
                                Dit tilfældige ord i Wordle: for hvert sprog det sidste tilfældige ord, du fik, dine gæt, om du fik
                                det, og hvornår det blev valgt og afsluttet. Det bliver overskrevet, når du tager et nyt ord, og er
                                ikke med i din statistik, dine stimer eller ranglisterne, så ingen andre ser det. Det bliver slettet
                                med din konto.
                            </li>
                            <li>
                                Dine spil Minesweeper: for hvert spil størrelsen, om du vandt, tabte eller forlod det (eller det blev
                                stoppet, fordi et klik på brættet kom fra kode i stedet for fra dig), hvor lang tid en sejr tog, det
                                mindste antal klik, brættet kunne vindes på (for at fange sejre, der er for hurtige til et menneske,
                                og som ikke tæller), og hvornår du startede og blev færdig. Mens et spil bliver spillet, har det også,
                                hvilke felter du har åbnet (og hvor minerne er); det bliver slettet, når spillet er slut. Dine flag
                                bliver i din browser. Spillene udgør din plads på Minesweeper-ranglisterne, hvor andre kun ser
                                totalerne (bedste og gennemsnitlige tid, sejre, spillede spil og sejrsprocent). De bliver gemt, til
                                din konto bliver slettet.
                            </li>
                            <li>
                                Dine spil Sudoku: for hvert spil niveauet, om du løste det (med eller uden hints, eller for hurtigt
                                til et menneske, hvilket ikke tæller) eller forlod det, hvor mange hints du brugte, hvor lang tid det
                                tog, hvor længe det var sat på pause, og hvornår du startede og blev færdig. Mens et spil bliver
                                spillet, har det også opgaven og dens løsning, og hvornår den pause, det er i, startede (spillet
                                bliver sat på pause, når du trykker Pause eller forlader siden); opgaven og løsningen bliver slettet,
                                når spillet er slut. De tal og noter, du skriver, bliver i din browser, til brættet er fyldt og
                                tjekket. Spillene udgør din plads på Sudoku-ranglisterne, hvor andre kun ser totalerne (bedste og
                                gennemsnitlige tid, og hvor mange du har løst uden et hint). De bliver gemt, til din konto bliver
                                slettet.
                            </li>
                            <li>
                                Dit gemte spil i Ore Mining Inc.: din malm, dine bygninger, opgraderinger, Dark Matter, Antimatter,
                                Strange Matter, de færdigheder, du har lært, præstationer, hvor mange gange du har klikket og fanget
                                kometer, dine Star Shards (hvor mange du har og har fanget, og hvad du har købt for dem i Shard Shop:
                                boosts, der kører, skins og klikeffekter, og den klikeffekt, du har valgt), hvornår du startede og
                                sidst gemte, hvor mange gemninger der kom de sidste par sekunder (så ingen kan oversvømme serveren med
                                dem), og et tilfældigt id for den browserfane, du spiller i (så spillet kun kører i én fane ad
                                gangen). Dit spil sender, hvad du har gjort siden sidste gemning, hvert 30. sekund, med tidspunktet
                                for hvert køb, og hvor mange gange du klikkede hvert sekund, så serveren kan tjekke det. Kun
                                resultatet bliver gemt, ikke listen over klik. For at opdage autoklikkere kigger spilsiden på, hvor
                                længe du holder hvert klik, pauserne mellem klik, og hvor markøren er, men det bliver i din browser:
                                det eneste, den sender, er, om et klik lignede en autoklikkers, og det bliver ikke gemt. Andre ser
                                kun totalerne på Ore Mining Inc.s rangliste (udvundet malm, malm per sekund, Dark Matter, Antimatter,
                                Strange Matter, Warp Jumps, klik og præstationer) og på dens Rising-fane, som kun viser spillere, der
                                ikke har lært hele færdighedstræet (så den gemmer, hvor mange færdigheder du har lært). Det gemte spil
                                holder også styr på, hvor langt du er med dagens Starbound Shifts, hvor mange Starbound Parts du har
                                givet fra malm den dag og i alt, hvor mange dage du har klaret alle Shifts, og hvor mange ugentlige
                                Anomalies du har gennemført, den Anomaly, du er i, og dem, du har gemt for at fortsætte senere (hver
                                sit eget spil, gemt på samme måde, til du gennemfører den, forlader den eller smider den ud), for hver
                                Anomaly eller stak af dem, du har gennemført, hvor mange gange, din bedste tid og det sidste byggeri, du
                                gennemførte den for, den sidste Anomaly, du gik ind i, hvornår Afterglow fra at gennemføre en udløber,
                                hvilket Shift du byttede den dag, hvilke Engines' sidste trin du hjalp med at fylde, og det
                                asteroideskin, den baggrund og de klikgnister, du har valgt.
                            </li>
                            <li>
                                Din bedste tid for hver Anomaly (eller stak af dem) i Ore Mining Inc., du gennemfører, hvor mange
                                gange du har gennemført den, og hvornår du sidst gjorde det, og det samme for hver uges ugentlige
                                Anomaly, du gennemfører i dens uge. <strong>Det bliver vist for alle</strong> med en konto på Ore
                                Mining Inc.s ranglistes Anomaly-faner, og det er det, der giver de titler på ranglisten, som vises
                                under dit navn dér ("Gauntlet Runner", "Singularity", eller "Forgemaster" og "Engineer" for at give
                                dele til Starforge eller Cosmic Engine, når trinnet med den titel er bygget). Når Starforges Cooling
                                Fins er bygget, bliver de uger, hvor du var blandt de 3 hurtigste til den ugentlige Anomaly, også
                                vist som guld-, sølv- og bronzemedaljer på din profil. Det bliver gemt, til du sletter din konto.
                            </li>
                            <li>
                                De Starbound Parts, du giver til Ore Mining Inc.s Project Starbound (Cosmic Engine, så Starforge, så
                                byggerierne efter den), som alle bygger sammen: hvor mange, om de kom fra malm, Strange Matter, et
                                Starbound Shift eller en Anomaly (og hvilken, og hvor lang tid den tog dig), hvilken Engine og hvilket
                                trin de gik ind i, og hvornår (én linje per slags for hver gemning). <strong>Det bliver vist for
                                alle</strong> med en konto: dit navn og de dele, du gav, i Project Starbound-visningens liste over de
                                seneste dele og dens top 10, og på Ore Mining Inc.s ranglistes Project Starbound-faner (med hvor mange
                                der kom fra hver). Har du givet dele til et byggeri, når dets mærketrin er bygget (Cosmic Engines Crew
                                Deck, Starforges Forge Floor), viser din profil dets mærke (Engine Crew, Forge Crew), og gav du dele til
                                dets sidste trin, før det var færdigt, også det trins mærke (Ignition, First Light, og fra Build #3 og
                                frem kun for hvert 10. byggeri, som Lamplighter). Når Starforges Alloy Bins er bygget, viser Project
                                Starbound-visningens Hall of Engines navnene på de 3 spillere, der gav mest til hvert færdigt byggeri.
                                Byggeriernes låse tæller, hvor mange forskellige spillere der gav dele eller gennemførte bestemte
                                Anomalies (nogle kun under en bestemt tid, eller kun spillere, der ikke har lært hele
                                færdighedstræet), men uden at vise hvem. Når et trin i Project Starbound er færdigt, får alle, der har
                                lavet et Warp Jump, en notifikation om det (den siger ikke, hvem der hjalp).
                            </li>
                            <li>
                                Nedbrudsrapporter fra Ore Mining Inc., kun hvis du accepterer at sende en. Mens spillet er åbent,
                                noterer det i din browser, hvad det laver: dets gemninger, og hvordan de gik, fejl, øjeblikke hvor det
                                var langsomt eller frøs, hvilken visning du er på, og hvilke af spillets automatiske knapper der er
                                slået til, hvor meget hukommelse siden bruger, hvor mange elementer og animationer der er på den, dens
                                billedhastighed, hvor lang tid hver del af spillet tager at køre, og de sidste dele, det kørte. Det
                                bliver i din browser (se <a href="#privacy-cookies">Cookies og local storage</a>). Fryser spillet eller
                                lukker uden at gemme ordentligt, mens du spiller, spørger det, næste gang du åbner det, om det må sende
                                en nedbrudsrapport om det, også hvis du har slået <strong>Help improve HB Hub</strong> fra (så står
                                der i spørgsmålet, at det går imod den indstilling at sende den). Du kan se alt i den, før du svarer. Siger du ja, bliver den gemt med din
                                konto, tidspunktet og din browsers user agent (som siger, hvilken browser og hvilket system du bruger):
                                det, spillet noterede, hvad det lavede, da det frøs, og hvor længe, dit gemte spil i Ore Mining Inc.,
                                som det var dengang, din skærmstørrelse, hvor mange processorkerner og hvor meget hukommelse din enhed
                                har (som din browser fortæller, afrundet), og navnet på dens grafikkort (som din browser fortæller det;
                                nogle browsere giver et generelt eller opdigtet). Er det gået ned mere end én gang, siden du sidst
                                svarede, er det stadig én rapport, med hvornår og hvordan de ældre nedbrud skete i den også (op til 4
                                af dem). Siger du nej, bliver intet sendt, og den bliver slettet fra din browser.
                            </li>
                            <li>
                                En kort besked fra din browser, hvis Ore Mining Inc. går ned. Er du logget ind, har du ikke slået
                                <strong>Help improve HB Hub</strong> fra under dine <a href="/settings#improve">indstillinger</a>, og
                                gør din browser det (Chrome og nogle andre bygget på den), beder spillets side den om at fortælle HB
                                Hub, når spillets fane går ned, fx fordi den løber tør for hukommelse. Din browser sender det af sig selv uden at spørge dig,
                                så modsat nedbrudsrapporterne ovenfor er den ikke knyttet til dig. Vi gemmer kun årsagen, hvornår det
                                skete, og din browsers user agent (som siger, hvilken browser og hvilket system du bruger). Vi gemmer
                                ikke din konto, din IP-adresse eller dit gemte spil med den, og sender din browser din login-cookie
                                med, bliver den ikke læst. Side-admins kan se disse beskeder.
                            </li>
                        </ul>
                        <p>
                            Chips er virtuelle. HB Hub beder aldrig om, eller gemmer, betalingsoplysninger. Køber du ekstra chips af
                            ejeren, sker betalingen og snakken om den uden for sidens casino (i en chatbesked eller et andet sted), og
                            en side-admin tilføjer de chips, du får, hvilket viser sig som en ændring af din saldo ligesom alle andre.
                        </p>
                        <h3>At spille uden en konto</h3>
                        <p>
                            De gratis spil på <a href="/play">/play</a> (Wordle, Ore Mining Inc., Minesweeper, Sudoku, og Blackjack,
                            Baccarat, Roulette og Slots med legechips) kan spilles uden en konto. Så bliver intet om dig eller dit
                            spil gemt på HB Hubs server, bortset fra en rapport om optaget server, hvis serveren har for travlt til at
                            svare din browser (se <a href="#watchdog">Rapporter om optaget server</a> nedenfor). Dit spil bliver i
                            stedet gemt i din egen browser, krypteret af HB Hub, så det ikke kan læses eller ændres dér, og sendt med
                            hvert træk, så serveren kan tjekke det med de samme regler som de normale spil og sende det tilbage. Wordle
                            og Ore Mining Inc. gemmer det i local storage (se <a href="#cookies">Cookies og local storage</a>). Sudoku
                            bliver kun spillet i din browser og også gemt i local storage, uden overhovedet at blive sendt til
                            serveren; Minesweeper og casinospillene gemmer det kun på den åbne side, så et Minesweeper-bræt og
                            legechipsene starter forfra hver gang. Ingen cookie bliver sat (de kigger kun på, om browseren allerede har
                            cookien hbHubDevice fra et tidligere login, se <a href="#cookies">Cookies og local storage</a>), og de
                            gratis spil har ingen ranglister, statistik eller nedbrudsrapporter. Det eneste, de gemmer på serveren, er
                            hver dags Wordle-ord, som er det samme for alle.
                        </p>
                        <p>
                            Logger du ind eller opretter dig fra et gratis spil, bliver dine spil af Wordle fra i dag og dit spil af Ore
                            Mining Inc. tilføjet til din konto, men kun hvor den ikke har dem endnu (den overskriver aldrig dine egne
                            spil). Fra da af bliver de gemt som alle kontis spil (se Casino og spil ovenfor). Legechips bliver aldrig
                            tilføjet til en konto.
                        </p>
                        <h3>Feedback</h3>
                        <p>
                            Når du sender feedback med Feedback-knappen i sidebjælken, bliver det, du skrev, gemt med din konto,
                            tidspunktet og adressen på den side, du var på, da du trykkede på knappen (kun siden, som
                            <code>/chat/</code>, ikke hvilken chat eller hvad du søgte efter), og om du sendte den fra testversionen af
                            siden. Side-admins kan også markere den som færdig, hvilket gemmer, hvilken admin der gjorde det, og
                            hvornår. Markerer de den som færdig med et svar, får du svaret som en notifikation under klokken (der står
                            "An admin", ikke hvilken). Svaret bliver gemt i den notifikation, ligesom dine andre notifikationer, og i
                            adminloggen nedenfor.
                        </p>
                        <h3>Meddelelser</h3>
                        <p>
                            Side-admins kan sende en meddelelse til alle, som hver konto, der ikke er udelukket eller slettet, får som
                            en notifikation under klokken (der står "the admins", ikke hvilken). Den bliver gemt i dine notifikationer
                            som alle andre, med om du har set den. Selve meddelelsen bliver gemt med, hvilken admin der sendte den,
                            hvornår, hvor mange der fik den, og, regnet ud fra notifikationerne, hvor mange der har set den (ikke
                            hvem). Kun side-admins kan se det. Når en admin trækker den tilbage, bliver den slettet, og det bliver
                            alles notifikation om den også.
                        </p>
                        <h3 id="watchdog">Rapporter om optaget server</h3>
                        <p>
                            HB Hubs server kan kun håndtere få forespørgsler på samme tid, og når der er for mange, svarer den nogle af
                            dem med en "server optaget"-fejl (508) i stedet. Hver side på HB Hub tæller, hvor mange gange det sker for
                            den, og sender et par sekunder senere en kort rapport om det til HB Hubs egen server (og prøver igen, til
                            den kommer igennem), så admins kan se, hvornår og hvor serveren har for travlt. Det sker også, når du ikke
                            er logget ind. En rapport indeholder, hvor mange fejl der var, hvornår den første og sidste af dem skete,
                            hvornår rapporten kom frem, adressen på den side, du var på, og på dem, der gav fejlen (kun siden, som
                            <code>/chat/</code>, ikke hvilken chat eller hvad du søgte efter), din konto, hvis du er logget ind, din
                            IP-adresse, din browsers user agent (som siger, hvilken browser og hvilket system du bruger), og om den kom
                            fra testversionen af siden. Indtil den er gået igennem, bliver den gemt i din browser (se
                            <a href="#cookies">Cookies og local storage</a>). Kun side-admins kan se rapporterne.
                        </p>
                        <p>
                            Er du logget ind, kan du slå det fra med <strong>Help improve HB Hub</strong> under dine
                            <a href="/settings#improve">indstillinger</a> (det er slået til, indtil du slår det fra). Så sender siderne
                            ingen rapporter om optaget server for dig, og en rapport, der stadig ventede i din fane fra før, bliver
                            smidt væk uden at blive gemt. Ore Mining Inc. beder så heller ikke din browser om at fortælle os, når
                            spillets fane går ned (se Casino og spil ovenfor). Spillets egne nedbrudsrapporter spørger stadig først,
                            og spørgsmålet siger, at det går imod din indstilling at sende en, men kun for den ene rapport:
                            indstillingen forbliver slået fra. Selve indstillingen bliver gemt med dine andre indstillinger på din
                            konto.
                        </p>
                        <h3>Adminlog</h3>
                        <p>
                            Alt, hvad en side-admin gør i adminpanelet, bliver skrevet i en adminlog: hvilken admin det var, hvornår,
                            hvad de gjorde, og hvilken konto eller chat det handlede om. Det gælder også bare at åbne din konto eller en
                            chat, du er i, da det viser dine beskeder og personlige oplysninger, og at downloade dine data. Den gemmer
                            også nogle få detaljer, som hvor mange chips der blev tilføjet, eller hvilken besked der blev slettet, men
                            aldrig en adgangskode, et navn eller hvad en besked sagde. Før hver ændring kan adminen skrive, hvorfor de
                            gør det, og den begrundelse bliver gemt med den i loggen. Når en admin svarer på feedback, bliver svaret
                            gemt dér som begrundelsen. Når en konto bliver udelukket automatisk for at uploade et bandlyst billede,
                            kommer det også i adminloggen, med hvilket billede det var, om det blev uploadet som et banner, og om det
                            skete ved oprettelsen. Ændrer eller fjerner en side-admin din status, eller fjerner de dit profilbillede
                            eller bannerbillede, står det også i adminloggen, men ikke hvad statussen sagde.
                        </p>
                        <h3>Hvad der ikke bliver gemt</h3>
                        <p>
                            Ingen e-mailadresse, intet telefonnummer, ingen rigtig adresse eller fødselsdato. Ingen analyseprofil,
                            ingen reklame-id'er og ingen registrering af, hvilke sider du åbner, bortset fra den ene side, du sender
                            feedback fra, visningerne i Ore Mining Inc. i en nedbrudsrapport, du accepterer at sende, siderne i en
                            rapport om optaget server (og, for side-admins, adminloggen ovenfor).
                        </p>
                    </section>

                    <section id="why" aria-labelledby="privacy-why">
                        <h2 id="privacy-why">3. Hvorfor det bliver gemt</h2>
                        <ul>
                            <li><strong>For at køre siden</strong>: din konto, dine beskeder og spil er siden. Uden dem er der intet at vise dig.</li>
                            <li><strong>For at holde den sikker</strong>: IP-adresser, mislykkede logins, enhedscookien og browserfingeraftryk forhindrer folk i at bryde ind på konti, spamme oprettelser og undgå udelukkelser. Listen over bandlyste billeder forhindrer en udelukket person i at komme tilbage med en kopi af sin gamle profil.</li>
                            <li><strong>For at holde den venlig</strong>: beskeder bliver gemt, så admins kan gribe ind over for mobning eller misbrug.</li>
                            <li><strong>For at gøre den bedre</strong>: feedback, og den side, den kom fra, hjælper admins med at finde fejl og se, hvad folk gerne vil have. Nedbrudsrapporter fra Ore Mining Inc., som kun bliver sendt, hvis du accepterer, og de korte beskeder, browsere sender om nedbrud, som ikke er knyttet til dig, hjælper med at finde ud af, hvorfor spillet gik ned. Rapporter om optaget server viser, hvornår og hvor serveren har for meget at lave, så det kan blive rettet.</li>
                        </ul>
                        <p>
                            Efter GDPR er det "nødvendigt for at levere den tjeneste, du har tilmeldt dig", og HB Hubs "legitime
                            interesse" i at holde den sikker. Browsernotifikationer bliver kun vist, hvis du tillader dem i din browser
                            og slår dem til under dine indstillinger.
                        </p>
                    </section>

                    <section id="see" aria-labelledby="privacy-see">
                        <h2 id="privacy-see">4. Hvem kan se hvad</h2>
                        <ul>
                            <li><strong>Alle med en konto</strong> kan se din profil: dit navn, profilbillede, banner, din bio, status, klasse (som "2.X", "Graduate (X)" eller "Not HTX"), dit uddannelsesmærke, om du er side-admin, dit Discord-brugernavn, hvis du har koblet Discord til (medmindre du har slået det fra under dine indstillinger), hvornår du sidst var online, og hvornår du blev medlem, og din plads på ranglisterne, og de Starbound Parts, du har givet til Ore Mining Inc.s Project Starbound (med et mærke på din profil for hvert byggeri, du hjalp med at bygge, når dets mærketrin er bygget, og ét for hvert byggeris sidste trin, du hjalp med at fylde), dine bedste tider i Ore Mining Inc.s Anomalies, dine medaljer fra ugentlige Anomalies og din titel på ranglisten dér. Folk, der spiller uden en konto, ser, hvor langt Project Starbound er, men ikke hvem der gav hvad.</li>
                            <li><strong>Alle i din klasses gruppechat</strong> (alle på HB Hub, der startede på HTX samme år på samme studieretning) kan se, at du er i den, og de beskeder, du sender dér. Du kommer automatisk med i den, når du opretter dig eller skifter klasse, og bliver taget ud af din gamle klasses chat, når du skifter. Ingen er gruppeadmin dér, så kun side-admins kan fjerne nogen eller slette beskeder i den.</li>
                            <li><strong>Folk i en chat med dig</strong> kan se beskederne i den chat, og hvem der reagerede på dem med hvad, og åbne og downloade dens vedhæftede filer, bortset fra det, en spøgelsesudelukket konto gjorde dér, som kun den og side-admins ser. Gruppeejere og -admins kan slette beskeder (og dermed deres vedhæftede filer) og fjerne medlemmer i deres gruppe. Fjerner de en side-admin, kan side-adminen fortryde det fra sin notifikation: de bliver sat tilbage i gruppen, som de var, og beskeden om det i chatten bliver slettet.</li>
                            <li><strong>Folk i et spil med dig</strong> kan se dine træk og spillets chat.</li>
                            <li>
                                <strong>Side-admins</strong> kan se alt om en konto i adminpanelet, også beskeder i alle chats (også
                                slettede), IP-adresser og logins, listen over bandlyste billeder og al feedback. Adminpanelet viser de
                                vedhæftede filer, du har sendt, som stadig bliver gemt (deres navn, størrelse, hvilken chat, og hvor
                                mange billetter de tager), og dit bannerbillede sammen med dem, men åbner dem ikke, selvom sidens ejer
                                kan nå filerne på serveren. Side-admins kan slette dine vedhæftede filer og give dig flere billetter
                                eller tage nogle væk. De bruger det til at håndtere misbrug, udelukkelser og ødelagte konti og til at
                                forbedre siden. Feedback, nedbrudsrapporter fra Ore Mining Inc. og rapporter om optaget server bliver
                                kun nogensinde set af side-admins, aldrig af andre brugere. De kan aldrig se din adgangskode eller din
                                totrinshemmelighed. Alt, hvad de gør dér, også bare at kigge på din konto eller en chat, du er i,
                                kommer i adminloggen, som kun side-admins kan læse, og som ingen kan ændre eller slette.
                                Side-admins kan også se, hvilke konti der har været brugt i den samme browser (ud fra cookien
                                hbHubDevice, se <a href="#privacy-cookies">Cookies og local storage</a>, eller ud fra
                                browserfingeraftrykket, se Browserfingeraftryk under <a href="#what">Hvad der bliver gemt</a>, aldrig ud
                                fra din IP-adresse), med hvornår hver browser først og sidst blev brugt, og stille de konti ved siden af
                                hinanden på én side for at håndtere én persons ekstra konti samlet, som at udelukke dem alle på én gang.
                                Et fingeraftryk, der deles af mere end 4 konti (som et lokale med ens skolecomputere), bliver ikke brugt
                                til at koble nogen sammen, og admins tjekker først, at konti ikke bare er på en delt computer.
                            </li>
                        </ul>
                        <p class="legal-note">
                            Beskeder er ikke end-to-end-krypterede. De bliver sendt krypteret (HTTPS), men de bliver gemt, så siden
                            kan vise dem, så send ikke noget, du ikke ville have, at en admin læste.
                        </p>
                    </section>

                    <section id="services" aria-labelledby="privacy-services">
                        <h2 id="privacy-services">5. Eksterne tjenester</h2>
                        <p>Dine data bliver aldrig solgt eller delt til markedsføring. Disse tjenester er med til at køre siden:</p>
                        <ul>
                            <li><strong>Simply.com</strong> (Danmark) hoster hjemmesiden, databasen og vedhæftede filer fra chatten. Alt ovenfor bliver gemt dér, i EU.</li>
                            <li>
                                <strong>Giphy</strong> leverer GIF'erne. Dine GIF-søgninger går gennem HB Hubs server, så Giphy ikke
                                får at vide, hvem der søgte. For at spørge Giphy sjældnere bliver resultaterne af hver søgning gemt på
                                HB Hubs server i 15 minutter, uden hvem der søgte på det, og vist for alle, der søger på det samme i
                                den tid. GIFs i chatten bliver hentet direkte fra Giphy, hvilket betyder, at Giphy ser din IP-adresse,
                                når din browser viser en.
                            </li>
                            <li><strong>GitHub</strong> hoster sidens kode. Den får kun det samlede antal medlemmer og beskeder, til tællerne i README'en, aldrig noget om én person.</li>
                            <li>
                                <strong>Discord</strong>, kun hvis du logger ind eller opretter dig med Discord, eller kobler din
                                Discord-konto til. Du bliver sendt til Discord for at give lov, så Discord ser, at du bruger HB Hub, og
                                din IP-adresse, og HB Hub får dit Discord-id, brugernavn, visningsnavn og profilbillede fra Discord (se
                                Din konto ovenfor). Når du opretter dig med Discord, henter HB Hubs server det billede fra Discord,
                                hvilket kun fortæller Discord, hvilket billede der blev spurgt efter. Mens din Discord-konto er koblet
                                til, spørger HB Hubs server også Discord én gang om natten om dens nuværende brugernavn, hvilket kun
                                fortæller Discord det Discord-id, den spørger om. HB Hub sender aldrig Discord andet om dig og kan
                                ikke skrive eller læse noget på Discord for dig.
                                <a href="https://discord.com/privacy" target="_blank" rel="noopener noreferrer">Discords privatlivspolitik</a> dækker det, Discord gør.
                            </li>
                        </ul>
                    </section>

                    <section id="cookies" aria-labelledby="privacy-cookies">
                        <h2 id="privacy-cookies">6. Cookies og local storage</h2>
                        <p>
                            HB Hub bruger kun de cookies, den skal bruge for at virke. Der er ingen sporings- eller reklamecookies,
                            så den lille cookie-besked fortæller dig kun om dem: ved at bruge siden accepterer du dem. De bliver alle
                            kun sendt til HB Hub, over HTTPS, og scripts kan ikke læse dem.
                        </p>
                        <dl class="legal-cookies">
                            <?php foreach (HBHUB_PRIVACY_COOKIES as $privacyCookie => [$privacyCookieUse, $privacyCookieKept]): ?>
                                <div>
                                    <dt><code><?= htmlspecialchars($privacyCookie) ?></code></dt>
                                    <dd><?= htmlspecialchars($privacyCookieUse) ?></dd>
                                    <dd class="legal-cookie-kept">Gemmes i <?= htmlspecialchars($privacyCookieKept) ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                        <p>Nogle få små ting bliver også gemt i din browsers local storage (eller session storage eller IndexedDB). De forlader aldrig din browser, bortset fra dem til Ore Mining Inc. og de gratis spil, hvor der står det:</p>
                        <dl class="legal-cookies">
                            <?php foreach (HBHUB_PRIVACY_LOCAL_STORAGE as $privacyKey => $privacyKeyUse): ?>
                                <div>
                                    <dt><code><?= htmlspecialchars($privacyKey) ?></code></dt>
                                    <dd><?= htmlspecialchars($privacyKeyUse) ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    </section>

                    <section id="keep" aria-labelledby="privacy-keep">
                        <h2 id="privacy-keep">7. Hvor længe det bliver gemt</h2>
                        <p>
                            Dine data bliver gemt, så længe du har en konto. Logins udløber efter 30 dage, og en browser holder op med
                            at tælle som en kendt enhed efter 180 dage uden et login. Et browserfingeraftryk bliver slettet 180 dage
                            efter, det sidst blev set. Logger du ud af en enhed, eller logger du ud overalt, bliver de logins slettet
                            med det samme.
                        </p>
                        <p>
                            <strong>Vedhæftede filer</strong> bliver gemt i 90 dage, eller i 3 dage, når en er større end 25 MB, og
                            bliver så slettet af sig selv, senest natten efter. De bliver slettet før, når du sletter dem (i Your
                            attachments i chatten, eller ved at slette deres besked), når en admin sletter deres besked, når deres
                            chat bliver slettet, eller når din konto bliver slettet. Derefter bliver kun en note om, at beskeden havde
                            vedhæftede filer, ved den (hvor mange, hvor store de hver var, deres type og datoerne), aldrig filerne
                            eller deres navne.
                        </p>
                        <p>
                            Du kan selv <strong>slette din konto</strong> på <a href="/settings">indstillingssiden</a>. Det fjerner
                            med det samme dit navn, profilbillede, banner, din bio, status, dit uddannelsesmærke, din årgang, klasse,
                            dine indstillinger, din adgangskode, totrinshemmelighed, tilkoblede Discord-konto, IP-adresser, logins,
                            kendte enheder, browserfingeraftryk, dit gemte spil og dine nedbrudsrapporter i Ore Mining Inc., dine
                            rapporter om optaget server, dine spil Minesweeper og Sudoku og de filer, du har vedhæftet i chats. Dit
                            navn bliver erstattet med "Deleted User".
                        </p>
                        <p>
                            Nedbrudsrapporter fra Ore Mining Inc. og browserbeskederne om nedbrud bliver gemt i 90 dage og så slettet
                            af sig selv (senest natten efter). Side-admins kan slette nedbrudsrapporter før.
                        </p>
                        <p>
                            Rapporter om optaget server bliver gemt i 30 dage og så slettet af sig selv (senest natten efter).
                            Side-admins kan slette dem alle før. Rapporter, der blev sendt, mens du ikke var logget ind, er ikke
                            knyttet til en konto, så de bliver kun slettet på den måde.
                        </p>
                        <p>
                            De gratis spil, du spiller uden en konto, bliver kun gemt i din browser, så HB Hub har intet at slette:
                            rydder du denne sides data i din browser, bliver de slettet. Et Minesweeper-bræt og casinospillenes
                            legechips er væk, så snart du lukker eller genindlæser siden.
                        </p>
                        <p>
                            Det, der bliver, er det, andre har brug for, for at deres chats og spil giver mening: de beskeder, du har
                            sendt, og dine reaktioner på beskeder (vist som fra "Deleted User"), dine spilresultater og din
                            chiphistorik, de Starbound Parts, du gav til Ore Mining Inc.s Project Starbound (som alle byggede sammen,
                            vist som fra "Deleted User" i spillets liste over de seneste dele, dets top 10 og dets Starbound Hall), og
                            al feedback, du har sendt. Intet af det er koblet til dit navn længere.
                        </p>
                        <p>
                            Et billede på listen over bandlyste billeder bliver dér, indtil den konto, det kom fra, ikke længere er
                            udelukket, eller en admin fjerner det, også hvis den konto bliver slettet.
                        </p>
                        <p>
                            Feedback bliver gemt, indtil en side-admin sletter den. Sletter du din konto, bliver din feedback, vist som
                            fra "Deleted User", ligesom dine beskeder.
                        </p>
                        <p>
                            Adminloggen bliver gemt i et år og så slettet af sig selv (senest natten efter). Sletter du din konto,
                            bliver det, der står i den om dig, til da, vist som om "Deleted User".
                        </p>
                        <p class="legal-note">
                            For at <strong>slette alle</strong> dine data helt, også beskeder, eller for at <strong>downloade</strong>
                            en kopi af det hele, skriv til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>

                    <section id="rights" aria-labelledby="privacy-rights">
                        <h2 id="privacy-rights">8. Dine rettigheder</h2>
                        <p>Efter GDPR kan du bede om at:</p>
                        <ul>
                            <li>se de data, HB Hub har om dig, og få en kopi af dem,</li>
                            <li>få forkerte data rettet (det meste kan du selv ændre under dine indstillinger),</li>
                            <li>få dine data slettet,</li>
                            <li>gøre indsigelse mod, hvordan dine data bliver brugt, eller få dem brugt mindre.</li>
                        </ul>
                        <p>
                            Skriv til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>, så får du svar inden for
                            en måned. Mener du, at dine data bliver behandlet forkert, kan du klage til
                            <a href="https://www.datatilsynet.dk" target="_blank" rel="noopener noreferrer">Datatilsynet</a>.
                        </p>
                    </section>

                    <section id="changes" aria-labelledby="privacy-changes">
                        <h2 id="privacy-changes">9. Ændringer i politikken</h2>
                        <p>
                            HB Hub ændrer sig, efterhånden som der kommer nye funktioner, og denne side prøver at ændre sig med den.
                            Det er dog et lille sideprojekt, men jeg prøver at sørge for, at siden er opdateret. Lægger du mærke til,
                            at den ikke er, så skriv til mig på <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                            Datoen øverst viser, hvornår den sidst blev opdateret.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
