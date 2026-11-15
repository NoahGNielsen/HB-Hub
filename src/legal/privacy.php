<?php
// Privacy policy: what HB Hub stores about you, why, who can see it, the cookies it sets and how to get it deleted.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too.
// Open to everyone, like the frontpage, and so in Danish like every public page (the section ids stay English, as
// other pages link to them: who, what, why, see, services, cookies, keep, rights, changes, watchdog, page-views).
// Keep it in step with the code: when a new table, cookie, localStorage key or outside service is added, it belongs
// here, and HBHUB_PRIVACY_UPDATED gets the date of the change. It's set in assets/php/legalUpdated.php, since the
// footer needs it too: changing it there shows everyone who agreed to the old policy the "privacy policy updated" box.
// Written in a formal "we" voice and kept short: it says what's kept and why, not every detail of how.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/legalUpdated.php';

// The cookies the site sets: name => [what it's for, how long it's kept]. All of them are needed for the site to work
// (logging in, the login lock, bans), so the cookie notice (footer.php) only tells you about them, it doesn't ask.
const HBHUB_PRIVACY_COOKIES = [
    'hbHubSession' => ['Holder dig logget ind. Indeholder et tilfældigt id, der henviser til dit login.', '30 dage, eller til du logger ud'],
    'hbHubPendingLogin' => ['Husker, at din adgangskode (eller dit Discord-login) er godkendt, mens du indtaster din totrinskode.', '5 minutter'],
    'hbHubDiscordSignup' => ['Bruges, når du opretter dig med Discord. Indeholder, krypteret så kun HB Hub kan læse det, dit Discord-id, brugernavn, visningsnavn, billed-id og bio, så formularen kan udfyldes på forhånd, og kontoen kan kobles til Discord. Slettes, når kontoen er oprettet, eller du fravælger koblingen.', '30 minutter'],
    'hbHubDiscordState' => ['Bruges, når du logger ind, opretter dig eller kobler din konto til med Discord. Indeholder en tilfældig kode, handlingen og den side, du skal tilbage til, så kun din browser kan fuldføre den. Slettes, når du vender tilbage fra Discord.', '10 minutter'],
    'hbHubDevice' => ['Genkender en browser, du har været logget ind i, så andres forkerte adgangskodeforsøg ikke låser dig ude. Bruges også til at overføre en spøgelsesudelukkelse til nye konti i samme browser, til at vise side-admins, hvilke konti der bruges i samme browser, og til at give de daglige chips én gang per person (se afsnit 3). De gratis spil på /play sender en browser med denne cookie videre til login og det almindelige spil.', '180 dage'],
    'hbHubBanned' => ['Sættes, når en udelukket konto bruges i browseren, så der ikke blot kan oprettes en ny konto.', '400 dage, eller til kontoen ikke længere er udelukket og logger ind igen'],
    'hbHubToast' => ['En kort besked til den næste side, fx "Your password was changed".', '60 sekunder'],
];

// What the site keeps in your browser's local storage (and IndexedDB). It never leaves your browser, except where the
// text says so.
const HBHUB_PRIVACY_LOCAL_STORAGE = [
    'hbhub-chat-emoji-recent' => 'Dine senest brugte emoji, så de vises først i emojivælgeren.',
    'hbHubWatchdog' => 'Fejl i den åbne fane, indtil rapporten om dem er modtaget af serveren (se Fejlrapporter fra browseren). Gemmes i session storage og slettes, når rapporten er sendt, eller fanen lukkes.',
    'hbHubChatListScroll' => 'Hvor langt du har scrollet i chatlisten. Gemmes i session storage og slettes, når fanen lukkes.',
    'hbHubNotifiedMessageId' => 'Den seneste besked, du har fået en notifikation eller lyd for, så du ikke får den to gange.',
    'hbHubNavPolledAt' => 'Tidspunktet for, hvornår en af dine HB Hub-faner sidst tjekkede for nye beskeder, så baggrundsfaner ikke tjekker samtidig.',
    'hbHubChessSound, hbHubMatadorSound, hbHubUnoSound, hbHubMinerSound, hbHubSlotsSound' => 'Om lyd er slået til eller fra i spillene.',
    'hbHubMinerLateKeys' => 'Om Ore Mining Inc.s genvejstaster 1-9 og 0 er skiftet til dine sidste ti bygninger (opgraderingen Long-Range Hot Keys).',
    'hbHubMinerAutoBuy' => 'Om Ore Mining Inc.s Auto Buy er slået til eller fra.',
    'hbHubMinerKeysHelp' => 'Om Ore Mining Inc. har vist dig vejledningen til genvejstasterne.',
    'hbHubMinerAutoWarp, hbHubMinerAutoCrunch' => 'Om Ore Mining Inc.s Autopilot og Auto Crunch er slået til eller fra.',
    'hbHubMinerAutoWarpTimes, hbHubMinerAutoCrunchTimes' => 'Den grænse, du har angivet for, hvornår Autopilot og Auto Crunch udfører et Warp Jump eller Big Crunch.',
    'hbHubMinerRun:<tab id>' => 'Mens Ore Mining Inc. er åbent: dine handlinger, der endnu ikke er gemt, og en løbende nedbrudslog, opdateret hvert sekund. Lukker eller går spillet ned før en gemning, sendes handlingerne med den første gemning, næste gang du åbner det, og slettes derefter. Nedbrudsloggen bliver liggende, til du har svaret på, om en nedbrudsrapport må sendes, og sendes kun, hvis du siger ja.',
    'hbHubMiner (IndexedDB)' => 'En nedbrudsrapport, som spillet skriver, hvis det fryser. Den sendes kun, hvis du siger ja, næste gang du åbner spillet, og slettes under alle omstændigheder. Spiller du uden en konto, sendes den aldrig.',
    'hbHubSudokuGuest' => 'Kun i den gratis Sudoku på /play/sudoku: dit igangværende spil (opgave, løsning, dine tal og noter, tid og hints) samt dine bedste tider og sejre. Spillet foregår udelukkende i din browser, så intet sendes til HB Hub eller overføres til en konto.',
    'hbHubSudoku' => 'Dit igangværende Sudoku-spil med en konto (nummer, opgave, dine tal og noter, tid og hints), så du kan fortsætte senere. Dine tal sendes kun til serveren, når brættet er fyldt, eller du beder om et hint.',
    'hbHubGuestWordle' => 'Dagens Wordle-spil uden en konto, krypteret af HB Hub, så det ikke kan læses eller ændres i browseren. Sendes med hvert gæt, så serveren kan kontrollere det. Logger du ind eller opretter dig, overføres dagens spil én gang til din konto (kun for sprog, du ikke har spillet i dag) og slettes derefter. Spil fra tidligere dage overskrives.',
    'hbHubGuestWordleRandom' => 'Wordles tilfældige ord uden en konto, med dine gæt, krypteret på samme måde og sendt med hvert gæt. Overføres aldrig til en konto og slettes, når du åbner Wordle logget ind.',
    'hbHubGuestMiner' => 'Dit Ore Mining Inc.-spil uden en konto, krypteret på samme måde, samt et tilfældigt id for fanen (så spillet kun kører i én fane ad gangen). Sendes med hver gemning, så serveren kan kontrollere det. Logger du ind eller opretter dig, bliver det din kontos spil (kun hvis kontoen ikke har et) og slettes derefter.',
    'hbHubGuestMinerRun:<tab id>' => 'Det samme som hbHubMinerRun, når du spiller uden en konto. Sendes kun med den næste gemning af gæstespillet, aldrig til en kontos spil.',
    'hbHubGuestPrompt' => 'Hvor mange sekunder du har spillet de gratis spil uden en konto, så opfordringen til at oprette dig kun vises en gang imellem.',
    'hbHubAppPrompt' => 'At opfordringen til at føje HB Hub til din hjemmeskærm er vist (eller at du allerede bruger den derfra), så den kun vises én gang.',
    'hbHubCookieNotice' => 'At du har lukket cookie-beskeden.',
    'hbHubTermsAgreed, hbHubPrivacyAgreed' => 'Datoerne for de vilkår og den privatlivspolitik, du sidst har accepteret, så du kun får besked, når de ændres igen.',
];
?>
<!DOCTYPE html>
<html lang="da" class="home">
<head>
    <?php $headerHomeNav = true; $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Privatlivspolitik - HB Hub</title>
    <meta name="description" content="Hvilke oplysninger HB Hub behandler, hvorfor, hvem der har adgang til dem, hvilke cookies vi bruger, og hvordan du får dine data slettet.">
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
                        HB Hub er et ikke-kommercielt elevprojekt. Vi behandler kun de oplysninger, der er nødvendige for at
                        drive chatten, casinoet og spillene. Vi viser ingen reklamer, sælger ingen data og sporer dig ikke på
                        andre hjemmesider. Denne politik beskriver, hvilke oplysninger vi gemmer, hvorfor, hvem der har adgang
                        til dem, og hvordan du får dem slettet.
                    </p>
                    <p class="legal-updated">Sidst opdateret <?= hbHubLegalDanishDate(HBHUB_PRIVACY_UPDATED) ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="På denne side">
                    <p class="legal-toc-title" aria-hidden="true">På denne side</p>
                    <ol>
                        <li><a href="#who">Dataansvarlig</a></li>
                        <li><a href="#what">Hvilke oplysninger vi gemmer</a></li>
                        <li><a href="#why">Formål og retsgrundlag</a></li>
                        <li><a href="#see">Hvem har adgang</a></li>
                        <li><a href="#services">Eksterne tjenester</a></li>
                        <li><a href="#cookies">Cookies og lokal lagring</a></li>
                        <li><a href="#keep">Opbevaring og sletning</a></li>
                        <li><a href="#rights">Dine rettigheder</a></li>
                        <li><a href="#changes">Ændringer</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <section id="who" aria-labelledby="privacy-who">
                        <h2 id="privacy-who">1. Dataansvarlig</h2>
                        <p>
                            HB Hub (hbhub.noahgajnielsen.dk) drives af Noah Gaj Nedergaard Nielsen, Danmark, som er dataansvarlig
                            for de oplysninger, der behandles på siden, jf. databeskyttelsesforordningen (GDPR).
                        </p>
                        <p>
                            Henvendelser om dine data, herunder anmodninger om indsigt, en kopi eller sletning, sendes til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>

                    <section id="what" aria-labelledby="privacy-what">
                        <h2 id="privacy-what">2. Hvilke oplysninger vi gemmer</h2>

                        <h3>Konto og profil</h3>
                        <ul>
                            <li>Dit brugernavn og, hvis du tilføjer dem, profilbillede, bio, status (emoji, kort tekst og udløbstidspunkt), uddannelsesmærke og banner (to farver og en retning, eller et billede).</li>
                            <li>Din klasse: det år, du startede på HTX, og din studieretning, eller at du ikke går på HTX. Din årgang beregnes ud fra startåret. Går du på HTX, bliver du automatisk medlem af din klasses gruppechat.</li>
                            <li>Dine indstillinger, fx hvilke chats du har gjort lydløse, skjult eller blokeret, dine notifikationsvalg, og om Casino og Spil er blokeret (af dig eller af en side-admin).</li>
                            <li>Hvornår kontoen blev oprettet, og hvornår du sidst var aktiv.</li>
                            <li>
                                Kobler du en Discord-konto til: dens Discord-id og brugernavn. Brugernavnet vises i dine indstillinger
                                og på din profil, medmindre du slår det fra under Discord login i kortet Security. Det opdateres, hver
                                gang du logger ind med Discord, og hver nat. Vi gemmer ikke din e-mail, dine venner, servere eller
                                Discords adgangstoken, som kun bruges én gang og derefter annulleres. Fjerner du koblingen, slettes
                                oplysningerne straks.
                            </li>
                            <li>Opretter du dig med Discord, udfyldes formularen på forhånd med dit Discord-visningsnavn, -billede og -bio. Det, du beholder, gemmes som din almindelige HB Hub-profil og opdateres ikke fra Discord bagefter. Billedet hentes af HB Hubs server, ikke af din browser.</li>
                            <li>Et bannerbillede formindskes og konverteres til JPEG XL i din browser, før det sendes, så oplysninger som et fotos placering fjernes. Det gemmes som en fil på serveren, bruger en af dine billetter til vedhæftede filer og slettes, når du skifter det, fjerner det eller sletter din konto.</li>
                        </ul>

                        <h3>Login og sikkerhed</h3>
                        <ul>
                            <li>Din adgangskode, udelukkende som en envejs-hash. Ingen, heller ikke en admin, kan læse den.</li>
                            <li>Hemmeligheden bag dine totrinskoder, hvis du har slået totrinslogin til.</li>
                            <li>For hvert login: hvornår det startede, sidst blev brugt og udløber, samt et kort navn for browser og system (fx "Chrome on Windows"), udledt af din browsers user agent. Kun det korte navn gemmes. Du kan se og afslutte dine logins under Logged in devices på <a href="/settings#devices">indstillingssiden</a>.</li>
                            <li>Browsere, du har logget ind fra, genkendes ud fra en tilfældig token (vi gemmer kun en hash af den) sammen med antallet af forkerte adgangskodeforsøg.</li>
                            <li>På din konto: den IP-adresse, du oprettede dig fra, og den senest brugte. Der gemmes ingen historik over dine logins' IP-adresser. IP-adresser tælles desuden kortvarigt ved kontooprettelse for at forhindre masseoprettelse af konti.</li>
                            <li>Din IP-adresse gemmes også i <a href="#watchdog">fejlrapporter fra browseren</a> sammen med din browsers user agent, også når du ikke er logget ind. Rapporterne slettes efter 30 dage, og du kan slå dem fra med <strong>Help improve HB Hub</strong>.</li>
                            <li>Mislykkede logins, om kontoen er låst eller udelukket (og om udelukkelsen kom fra en admin eller var automatisk), og om og siden hvornår kontoen er spøgelsesudelukket.</li>
                        </ul>

                        <h3 id="fingerprint">Browserfingeraftryk</h3>
                        <p>
                            Mens du er logget ind, beregner din browser omtrent én gang om dagen et fingeraftryk: en SHA-256-hash af
                            tekniske egenskaber som grafik- og lydgengivelse, grafikkort, installerede skrifttyper, skærm, tidszone,
                            sprog, platform, processorkerner og hukommelse. Kun hashen sendes og gemmes, sammen med det korte
                            browsernavn, din konto, og hvornår den først og sidst blev set. Fingeraftrykket bevares, selvom du rydder
                            cookies.
                        </p>
                        <p>
                            <strong>Fingeraftrykket bruges kun til at forhindre misbrug</strong>, og kun på disse to måder:
                        </p>
                        <ul>
                            <li>at vise side-admins, hvilke konti der bruges i samme browser, så udelukkede brugere ikke blot kan oprette en ny konto (se <a href="#see">Hvem har adgang</a>),</li>
                            <li>at forhindre, at nogen henter de daglige chips flere gange med ekstra konti (se <a href="#why">afsnit 3</a>).</li>
                        </ul>
                        <p>
                            Det bruges aldrig til reklamer, statistik, analyseprofiler eller til at følge dig på andre hjemmesider,
                            og det deles ikke med nogen.
                        </p>
                        <p class="legal-note">
                            <strong>Du kan sige nej til fingeraftryk med "Do Not Track".</strong> Slår du Do Not Track til i din
                            browsers indstillinger, beregnes og sendes der intet fingeraftryk, og serveren kasserer alt, hvad den
                            alligevel måtte modtage fra en browser, der sender signalet. Du behøver ikke gøre andet, og siden virker
                            som normalt. Fingeraftryk, der blev gemt, før du slog det til, slettes 180 dage efter, de sidst blev set;
                            vil du have dem slettet med det samme, kan du skrive til os (se <a href="#rights">Dine rettigheder</a>).
                            Dine konti kan stadig kobles sammen via cookien hbHubDevice, som er nødvendig for sidens sikkerhed.
                            Opretter du din konto med Do Not Track slået til, er <strong>Help improve HB Hub</strong> desuden slået
                            fra som standard (se <a href="#do-not-track">Fejlrapporter fra browseren</a>).
                        </p>

                        <h3>Bandlyste billeder</h3>
                        <p>
                            Når en konto udelukkes, eller en admin fjerner et billede, der bryder reglerne, kan en kopi af
                            profilbilledet eller bannerbilledet blive føjet til en liste over bandlyste billeder sammen med, hvilken
                            konto det kom fra, og hvilken admin der tilføjede det. Admins kan også selv tilføje billeder. Nye profil-
                            og bannerbilleder sammenlignes med listen ud fra filens hash og et visuelt fingeraftryk, så også
                            skalerede eller beskårne kopier genkendes. Til bannerbilleder sender din browser en lille PNG-kopi, der
                            kun bruges til sammenligningen og ikke gemmes. Ved et match udelukkes kontoen automatisk og beholder
                            billedet, så admins kan se årsagen. Vi gemmer kun fingeraftryk af bandlyste billeder.
                        </p>

                        <h3>Beskeder og chats</h3>
                        <ul>
                            <li>De beskeder, du sender, med tidspunkt, chat, eventuelt svar og eventuel GIF.</li>
                            <li>Vedhæftede filer (op til 10 pr. besked) med navn, størrelse, type, og hvornår de blev sendt og udløber. De gemmes som filer på serveren. Billeder og videoer konverteres i din browser (til JPEG XL og WebM), medmindre du vælger at beholde originalen, eller din browser ikke kan konvertere videoen; konverterede filer indeholder ikke oplysninger som optagelsessted. Andre filer komprimeres på serveren. Hvis en side-admin har ændret dit antal billetter til vedhæftede filer, gemmes det på din konto.</li>
                            <li>Reaktioner (emoji, besked og tidspunkt). En reaktion, du fjerner, slettes.</li>
                            <li>Fastgjorte beskeder: hvem der fastgjorde dem og hvornår, synligt for alle i chatten. Fastgørelse og løsning noteres med en besked i chatten. Når en besked løsnes, slettes oplysningen om, hvem der fastgjorde den.</li>
                            <li>Dine chats samt gruppenavne, -ikoner og -baggrunde.</li>
                            <li>Kælenavne: i en gruppe gemmes kælenavnet med personens medlemskab og vises for alle i gruppen. Ændringen noteres med en besked i chatten, og den, der får et kælenavn, får en notifikation (ikke fra lydløse grupper eller blokerede personer). Kælenavnet forsvinder, når det fjernes, eller personen forlader gruppen. I en privat chat er kælenavnet kun synligt for dig.</li>
                            <li>Mens du skriver, gemmes tidspunktet, så andre ser "... is typing". Selve teksten gemmes aldrig, og tidspunktet ryddes, når du sender, tømmer feltet eller holder op med at skrive. Det gemmes ikke, hvis du er spøgelsesudelukket eller gjort lydløs i chatten.</li>
                            <li>Slettede beskeder skjules for alle, men deres tekst bevares i 3 dage, så side-admins kan undersøge anmeldelser af misbrug. Derefter slettes teksten permanent; kun en note om, at der var en besked (afsender, tidspunkter og reaktioner), bliver tilbage. Vedhæftede filer i en slettet besked slettes permanent, inklusive deres navne.</li>
                            <li>Notifikationer: hvad der skete, hvem der gjorde det, og om du har set det. Fjerner en gruppeadmin en side-admin fra en gruppe, gemmer notifikationen side-adminens tidligere medlemskab (rolle, kælenavn, lydløs og læseposition), så det kan fortrydes; notifikationen slettes derefter.</li>
                            <li>Omtaler (@navn) i chats og i chatten i skak, Matador og UNO giver en notifikation om, hvem og hvor. Du får den ikke fra lydløse chats eller blokerede personer, og kun hvis du ikke har chatten eller spillet åbent; derfor gemmes, hvornår du sidst havde hver chat åben. Slettes beskeden, slettes notifikationerne også.</li>
                            <li>
                                Spøgelsesudelukkelse: en side-admin kan spøgelsesudelukke en konto, der bryder reglerne, uden at
                                kontoen får besked. Kontoens aktivitet i chats gemmes som normalt, men markeres, så kun kontoen selv og
                                side-admins kan se den. Personer, kontoen inviterer, ser ikke chatten, før en anden inviterer dem, eller
                                de selv starter en chat med kontoen. Markeringerne bevares, hvis udelukkelsen ophæves. En ny konto (højst
                                3 dage gammel), der bruges i samme browser som en spøgelsesudelukket konto (cookien hbHubDevice), bliver
                                selv spøgelsesudelukket, og adminloggen viser, hvilken konto den hænger sammen med.
                            </li>
                        </ul>

                        <h3>Casino og spil</h3>
                        <ul>
                            <li>Din chipsaldo og alle ændringer af den (indsatser, gevinster, daglige chips og justeringer foretaget af en side-admin, med hvilken admin), samt hvornår du sidst hentede de daglige chips, og hvor mange dage i træk.</li>
                            <li>Dine runder i casinospillene (bl.a. Blackjack, Baccarat, Roulette og Slots), som bruges til ranglisterne. Andre ser kun dine totaler: runder, sejre, nederlag, sejrsprocent, største gevinst, vundne og tabte chips samt din nuværende saldo. Casinoets forside viser toppen af ranglisten og antallet af aktive spillere uden navne.</li>
                            <li>Roulette er ét fælles bord. Mens du har indsatser på bordet, kan alle med siden åben se dit navn, dit profilbillede, dine indsatser, og om du er klar. Efter spinnet vises dit resultat, indtil næste runde starter.</li>
                            <li>Skak og Matador: pladser, træk, gemte spil, resultater (at forlade et spil tæller som et nederlag) og spillets chat.</li>
                            <li>UNO: din plads, dine kort, dine træk, invitationer og spillets chat. Kun du ser dine kort under spillet; ved spillets afslutning ser deltagerne de resterende kort. Spillet slettes, når alle har forladt det. Til ranglisten gemmes én linje pr. spil (resultat, point, resterende kort, antal spillere og tidspunkt); andre ser kun totaler.</li>
                            <li>Wordle: dine gæt, resultat og tidspunkter for hver dag og hvert sprog. De udgør din statistik og din plads på ranglisten, hvor andre kun ser totaler, aldrig dine gæt. Det tilfældige ord gemmes separat, overskrives ved hvert nyt ord og vises ikke for andre.</li>
                            <li>Minesweeper: brætstørrelse, resultat, tid og tidspunkter for hvert spil, samt det mindste antal klik, brættet kunne vindes på, for at opdage sejre, der er for hurtige til et menneske. Spil, hvor et klik kom fra kode i stedet for fra dig, stoppes. Mens et spil er i gang, gemmes også åbnede felter og minernes placering; det slettes, når spillet slutter. Dine flag bliver i din browser.</li>
                            <li>Sudoku: niveau, resultat (med eller uden hints, eller for hurtigt til at tælle), antal hints, tid, pausetid og tidspunkter. Mens et spil er i gang, gemmes også opgaven og løsningen; de slettes, når spillet slutter. Dine tal og noter bliver i din browser, indtil brættet er udfyldt.</li>
                            <li>
                                Ore Mining Inc.: dit gemte spil, dvs. din fremgang (ressourcer, bygninger, opgraderinger, færdigheder,
                                præstationer, klik, Star Shards og køb i Shard Shop, valgte skins og effekter, Starbound Shifts og
                                Anomalies, inklusive gemte Anomalies og bedste tider), hvornår du startede og sidst gemte, antallet af
                                nylige gemninger (for at forhindre overbelastning), og et tilfældigt id for fanen. Hvert 30. sekund sender
                                spillet dine handlinger siden sidste gemning, så serveren kan kontrollere dem; kun resultatet gemmes.
                                Spillet registrerer i din browser, hvordan du klikker, for at opdage autoklikkere, men sender kun, om et
                                klik lignede en autoklikker, og det gemmes ikke. Ranglisten viser kun totaler, og fanen Rising viser kun
                                spillere, der ikke har lært hele færdighedstræet.
                            </li>
                            <li><strong>Synligt for alle med en konto:</strong> dine bedste tider og antal gennemførelser i Ore Mining Inc.s Anomalies (også de ugentlige) og de titler, de giver på ranglisten. Når Starforges Cooling Fins er bygget, vises dine top 3-placeringer i ugentlige Anomalies som medaljer på din profil.</li>
                            <li><strong>Synligt for alle med en konto:</strong> de Starbound Parts, du bidrager med til Project Starbound: antal, kilde (malm, Strange Matter, et Shift eller en Anomaly og din tid), hvilket byggeri og trin, og hvornår. Dit navn og dine bidrag vises i listen over seneste bidrag, top 10, ranglistens Project Starbound-faner og, når Starforges Alloy Bins er bygget, i Hall of Engines (top 3 pr. færdigt byggeri). Bidrag kan give mærker på din profil. Byggeriernes låse tæller antallet af forskellige spillere uden at vise, hvem de er. Når et trin bliver færdigt, får alle, der har lavet et Warp Jump, en notifikation, der ikke nævner, hvem der hjalp.</li>
                            <li>
                                Nedbrudsrapporter fra Ore Mining Inc., kun hvis du accepterer. Mens spillet er åbent, fører det en log i
                                din browser over bl.a. gemninger, fejl, langsomme perioder, aktiv visning, aktive automatiseringer,
                                hukommelsesforbrug, billedhastighed og kørselstider. Fryser spillet eller lukkes det uden at gemme,
                                spørger det næste gang, om en rapport må sendes, også hvis du har slået <strong>Help improve HB Hub</strong>
                                fra (spørgsmålet gør opmærksom på det). Du kan se hele rapporten, før du svarer. Siger du ja, gemmes den
                                med din konto, tidspunktet, din browsers user agent, loggen, dit gemte spil på det tidspunkt,
                                skærmstørrelse, antal processorkerner, afrundet hukommelse og grafikkortets navn, som browseren oplyser
                                det. Flere nedbrud samles i én rapport (op til 4 tidligere). Siger du nej, sendes intet, og loggen slettes.
                            </li>
                            <li>Nedbrudsbeskeder fra browseren: er du logget ind, har du ikke slået <strong>Help improve HB Hub</strong> fra under dine <a href="/settings#improve">indstillinger</a>, og understøtter din browser det (Chrome og visse andre), beder spillet browseren om automatisk at give besked, hvis fanen går ned. Beskeden er ikke knyttet til dig: vi gemmer kun årsagen, tidspunktet og browserens user agent, ikke din konto, IP-adresse eller dit spil, og en medsendt login-cookie læses ikke. Kun side-admins kan se beskederne.</li>
                        </ul>
                        <p>
                            Chips er virtuelle. HB Hub beder aldrig om og gemmer aldrig
                            betalingsoplysninger. Chips er gratis, og køb af ekstra chips er kun en sjælden undtagelse. Sker det, foregår betalingen uden for siden, og en
                            side-admin tilføjer chipsene som en almindelig ændring af din saldo.
                        </p>

                        <h3>Spil uden en konto</h3>
                        <p>
                            De gratis spil på <a href="/play">/play</a> (Wordle, Ore Mining Inc., Minesweeper, Sudoku samt Blackjack,
                            Baccarat, Roulette og Slots med legechips) kan spilles uden en konto. Der gemmes da intet om dig eller
                            dit spil på serveren, bortset fra fejlrapporter fra browseren (se
                            <a href="#watchdog">Fejlrapporter fra browseren</a>). Wordle og Ore Mining Inc. gemmes i stedet
                            krypteret i din browser og sendes med hvert træk, så serveren kan kontrollere det. Sudoku spilles
                            udelukkende i din browser, mens Minesweeper og casinospillene kun findes på den åbne side og starter
                            forfra, når den genindlæses. De gratis spil sætter ingen cookies (de tjekker kun, om cookien hbHubDevice
                            findes) og har ingen ranglister, statistik eller nedbrudsrapporter.
                        </p>
                        <p>
                            Logger du ind eller opretter dig fra et gratis spil, overføres dagens Wordle-spil og dit Ore Mining
                            Inc.-spil til din konto, men kun hvis kontoen ikke allerede har dem. Legechips overføres aldrig.
                        </p>

                        <h3>Feedback og meddelelser</h3>
                        <p>
                            Feedback, du sender via Feedback-knappen, gemmes med din konto, tidspunktet, den side, du var på (kun
                            stien, fx <code>/chat/</code>), og om den kom fra testversionen. Side-admins kan markere den som
                            færdig, hvilket gemmer hvem og hvornår, og eventuelt svare. Svaret sendes til dig som en notifikation
                            fra "An admin" og gemmes i notifikationen og i adminloggen.
                        </p>
                        <p>
                            Side-admins kan sende meddelelser til alle aktive konti som en notifikation. Meddelelsen gemmes med
                            afsender, tidspunkt, antal modtagere og antal, der har set den (ikke hvem). Trækkes den tilbage, slettes
                            den sammen med alle notifikationer om den.
                        </p>

                        <h3 id="watchdog">Fejlrapporter fra browseren</h3>
                        <p>
                            Alle sider registrerer fejl i din browser og sender kort efter en rapport til HB Hubs egen server, så
                            fejl kan findes og rettes. Det sker også, når du ikke er logget ind. Der registreres fejlsvar fra
                            serveren (fx 404 eller 508 ved overbelastning), forespørgsler uden svar, filer der ikke kan indlæses,
                            JavaScript-fejl og blokeringer fra sidens sikkerhedsregler (Content-Security-Policy). Fejl fra
                            browserudvidelser og afbrudte forespørgsler tælles ikke med.
                        </p>
                        <p>
                            En rapport indeholder antal fejl, tidspunkter, den side du var på, og for hver fejl dens type, antal,
                            adresse (uden forespørgselsdelen efter <code>?</code>), statuskode og, for JavaScript-fejl og
                            blokeringer, fejlbeskeden (højst 300 tegn) og kodelinjen. Derudover gemmes din konto (hvis du er logget
                            ind), din IP-adresse, din browsers user agent, og om rapporten kom fra testversionen. Kun side-admins
                            kan se rapporterne.
                        </p>
                        <p>
                            Er du logget ind, kan du slå dette fra med <strong>Help improve HB Hub</strong> under dine
                            <a href="/settings#improve">indstillinger</a>. Indstillingen er slået til som standard, undtagen hvis du
                            opretter din konto med Do Not Track slået til (se nedenfor). Når indstillingen er slået fra, sendes
                            ingen fejlrapporter, dine sidevisninger tælles ikke, ventende rapporter kasseres, og Ore Mining Inc.
                            beder ikke browseren om nedbrudsbeskeder. Spillets egne nedbrudsrapporter spørger stadig først, men et
                            ja gælder kun den ene rapport; indstillingen forbliver slået fra.
                        </p>
                        <p class="legal-note" id="do-not-track">
                            <strong>Med Do Not Track er "Help improve HB Hub" slået fra som standard.</strong> Har du slået Do Not
                            Track til i din browser, når du opretter din konto, oprettes kontoen med <strong>Help improve HB
                            Hub</strong> slået fra fra starten, og du får en notifikation om det. Der sendes da ingen fejlrapporter,
                            og dine sidevisninger tælles ikke, uden at du skal gøre noget. Du kan selv slå indstillingen til igen
                            under dine <a href="/settings#improve">indstillinger</a>. Signalet tjekkes kun, når kontoen oprettes:
                            slår du Do Not Track til senere, ændres indstillingen ikke, men du kan altid selv slå den fra. Uanset
                            hvornår du slår Do Not Track til, beregnes der intet browserfingeraftryk (se
                            <a href="#fingerprint">Browserfingeraftryk</a>).
                        </p>

                        <h3 id="page-views">Sidevisninger</h3>
                        <p>
                            Vi tæller, hvor mange gange hver side åbnes pr. dag, så vi kan se, hvilke sider der bruges mest. Vi
                            gemmer kun datoen, sidens sti (uden forespørgselsdelen), antal visninger, og hvor mange af dem der var
                            uden login; aldrig hvem, IP-adresse, browser eller andet, der kan knyttes til en person. Søgemaskiner og
                            andre robotter tælles ikke med, og har du slået <strong>Help improve HB Hub</strong> fra, tælles dine
                            visninger ikke.
                        </p>

                        <h3>Adminlog</h3>
                        <p>
                            Alt, hvad en side-admin gør i adminpanelet, registreres i en adminlog: hvilken admin, hvornår, hvad og
                            hvilken konto eller chat det vedrørte. Det gælder også, når en admin blot åbner en konto eller en chat
                            eller downloader data. Loggen kan indeholde få detaljer (fx antal tilføjede chips eller hvilken besked
                            der blev slettet) og adminens begrundelse, men aldrig adgangskoder, navne eller beskedindhold. Automatiske
                            udelukkelser for bandlyste billeder og ændringer af din status eller dine billeder registreres også, dog
                            ikke statussens tekst.
                        </p>

                        <h3>Hvad vi ikke gemmer</h3>
                        <p>
                            Vi gemmer ingen e-mailadresse, intet telefonnummer, ingen adresse eller fødselsdato. Vi opbygger ingen
                            analyseprofiler, bruger ingen reklame-id'er og registrerer ikke, hvilke sider du besøger, ud over det,
                            der er beskrevet ovenfor (siden i feedback, nedbrudsrapporter og fejlrapporter, samt anonyme
                            sidevisningstal).
                        </p>
                    </section>

                    <section id="why" aria-labelledby="privacy-why">
                        <h2 id="privacy-why">3. Formål og retsgrundlag</h2>
                        <ul>
                            <li><strong>Levering af tjenesten</strong>: din konto, dine beskeder og dine spil er selve tjenesten.</li>
                            <li><strong>Sikkerhed</strong>: IP-adresser, mislykkede logins, enhedscookien og browserfingeraftryk forhindrer kontoovertagelse, masseoprettelse af konti og omgåelse af udelukkelser. De samme koblinger sikrer, at de daglige chips gives én gang per person: når én af dine konti har hentet dem, får dine øvrige konti kun 20 % den dag. Det beregnes, når du henter dem, og der gemmes intet nyt om det. Listen over bandlyste billeder forhindrer udelukkede brugere i at vende tilbage med samme profil. Browserfingeraftryk bruges kun til disse formål og kan fravælges med Do Not Track (se <a href="#fingerprint">Browserfingeraftryk</a>).</li>
                            <li><strong>Et trygt fællesskab</strong>: beskeder gemmes, så admins kan gribe ind over for mobning og misbrug.</li>
                            <li><strong>Forbedring af siden</strong>: feedback, nedbrudsrapporter, fejlrapporter og sidevisningstal bruges til at finde fejl, forstå overbelastning og prioritere udviklingen.</li>
                        </ul>
                        <p>
                            Retsgrundlaget er, at behandlingen er nødvendig for at levere den tjeneste, du har tilmeldt dig
                            (GDPR art. 6, stk. 1, litra b), samt HB Hubs legitime interesse i at holde siden sikker og velfungerende
                            (art. 6, stk. 1, litra f). Browsernotifikationer vises kun, hvis du selv tillader dem i din browser og
                            slår dem til i dine indstillinger.
                        </p>
                    </section>

                    <section id="see" aria-labelledby="privacy-see">
                        <h2 id="privacy-see">4. Hvem har adgang</h2>
                        <ul>
                            <li><strong>Alle med en konto</strong> kan se din profil: navn, profilbillede, banner, bio, status, klasse (fx "2.X", "Graduate (X)" eller "Not HTX"), uddannelsesmærke, om du er side-admin, dit Discord-brugernavn (medmindre du har slået det fra), hvornår du sidst var online og blev medlem, dine placeringer på ranglisterne samt dine bidrag, tider, medaljer, titler og mærker i Ore Mining Inc. Spillere uden konto kan se Project Starbounds fremgang, men ikke hvem der har bidraget.</li>
                            <li><strong>Din klasses gruppechat</strong> (alle, der startede på HTX samme år på samme studieretning) kan se, at du er medlem, og de beskeder, du sender dér. Du tilføjes automatisk ved oprettelse og klasseskift og fjernes fra din tidligere klasses chat. Chatten har ingen gruppeadmins, så kun side-admins kan fjerne medlemmer eller slette beskeder.</li>
                            <li><strong>Deltagere i en chat</strong> kan se chattens beskeder og reaktioner og åbne dens vedhæftede filer, bortset fra indhold fra spøgelsesudelukkede konti. Gruppeejere og -admins kan slette beskeder og fjerne medlemmer. Fjerner de en side-admin, kan side-adminen fortryde det via sin notifikation, hvorefter beskeden om det i chatten slettes.</li>
                            <li><strong>Medspillere</strong> kan se dine træk og spillets chat.</li>
                            <li>
                                <strong>Side-admins</strong> kan i adminpanelet se alle oplysninger om en konto, herunder beskeder i
                                alle chats (også slettede, og slettede beskeder i 3 dage), IP-adresser, logins, bandlyste billeder, feedback, nedbrudsrapporter,
                                fejlrapporter og sidevisningstal. Adminpanelet viser oplysninger om dine vedhæftede filer (navn,
                                størrelse, chat og billetter) og dit bannerbillede, men åbner ikke filerne, selvom sidens ejer har
                                adgang til dem på serveren. Side-admins kan slette dine vedhæftede filer og ændre dit antal billetter.
                                De kan se, hvilke konti der er brugt i samme browser (ud fra cookien hbHubDevice eller
                                browserfingeraftrykket, aldrig ud fra IP-adressen), og håndtere sådanne konti samlet, fx ved at udelukke
                                dem på én gang. Et fingeraftryk, der deles af mere end 4 konti (fx ens skolecomputere), bruges ikke til
                                at koble konti sammen, og admins kontrollerer først, at der ikke blot er tale om en delt computer.
                                Side-admins kan aldrig se din adgangskode eller totrinshemmelighed. Alt, hvad de gør i adminpanelet,
                                registreres i adminloggen, som kun side-admins kan læse, og som ingen kan ændre eller slette.
                            </li>
                        </ul>
                        <p class="legal-note">
                            Beskeder er ikke end-to-end-krypterede. De sendes krypteret (HTTPS), men gemmes på serveren, så de kan
                            vises. Send derfor ikke noget, du ikke ønsker, at en admin kan læse.
                        </p>
                    </section>

                    <section id="services" aria-labelledby="privacy-services">
                        <h2 id="privacy-services">5. Eksterne tjenester</h2>
                        <p>Dine data sælges aldrig og deles ikke til markedsføring. Følgende tjenester indgår i driften af siden:</p>
                        <ul>
                            <li><strong>Simply.com</strong> (Danmark) hoster hjemmesiden, databasen og vedhæftede filer. Alle data opbevares i EU.</li>
                            <li><strong>Giphy</strong> leverer GIF'er. Søgninger sendes via HB Hubs server, så Giphy ikke ved, hvem der søger, og resultaterne gemmes i 15 minutter uden oplysning om, hvem der søgte. Selve GIF'erne hentes direkte fra Giphy, som derfor ser din IP-adresse, når en GIF vises.</li>
                            <li><strong>GitHub</strong> hoster sidens kildekode og modtager kun det samlede antal medlemmer og beskeder, aldrig oplysninger om enkeltpersoner.</li>
                            <li>
                                <strong>Discord</strong>, kun hvis du logger ind, opretter dig eller kobler din konto til med Discord.
                                Discord ser da, at du bruger HB Hub, og din IP-adresse, og HB Hub modtager dit Discord-id, brugernavn,
                                visningsnavn og profilbillede (se Konto og profil). Mens kontoen er koblet til, spørger HB Hubs server
                                hver nat Discord om dit nuværende brugernavn ud fra dit Discord-id. HB Hub sender ikke andre oplysninger
                                om dig til Discord og kan ikke læse eller skrive noget på Discord på dine vegne.
                                <a href="https://discord.com/privacy" target="_blank" rel="noopener noreferrer">Discords privatlivspolitik</a> gælder for Discords behandling.
                            </li>
                        </ul>
                    </section>

                    <section id="cookies" aria-labelledby="privacy-cookies">
                        <h2 id="privacy-cookies">6. Cookies og lokal lagring</h2>
                        <p>
                            HB Hub bruger kun cookies, der er nødvendige for, at siden fungerer. Vi bruger ingen sporings- eller
                            reklamecookies, og cookie-beskeden er derfor kun til orientering. Alle cookies sendes kun til HB Hub,
                            over HTTPS, og kan ikke læses af scripts.
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
                        <p>Følgende gemmes i din browsers local storage (eller session storage eller IndexedDB). Det forlader ikke din browser, medmindre det fremgår af beskrivelsen:</p>
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
                        <h2 id="privacy-keep">7. Opbevaring og sletning</h2>
                        <p>Som udgangspunkt opbevares dine data, så længe du har en konto. Følgende slettes automatisk (senest natten efter fristen):</p>
                        <ul>
                            <li><strong>Logins</strong> efter 30 dage, eller straks når du logger ud.</li>
                            <li><strong>Kendte browsere</strong> efter 180 dage uden login, og <strong>browserfingeraftryk</strong> 180 dage efter, de sidst blev set.</li>
                            <li><strong>Vedhæftede filer</strong> efter 90 dage (3 dage for filer over 25 MB), eller tidligere, hvis du sletter dem eller deres besked, en admin sletter beskeden, chatten slettes, eller din konto slettes. Derefter bevares kun en note om antal, størrelse, type og datoer, aldrig filerne eller deres navne.</li>
                            <li><strong>Teksten i slettede beskeder</strong> 3 dage efter, de blev slettet. Side-admins kan ikke se den derefter.</li>
                            <li><strong>Nedbrudsrapporter og nedbrudsbeskeder</strong> fra Ore Mining Inc. efter 90 dage. Side-admins kan slette rapporter tidligere.</li>
                            <li><strong>Fejlrapporter fra browseren</strong> efter 30 dage. Rapporter sendt uden login er ikke knyttet til en konto og slettes kun på denne måde.</li>
                            <li><strong>Sidevisningstal</strong> efter 90 dage. De er ikke knyttet til nogen og slettes derfor ikke sammen med din konto.</li>
                            <li><strong>Adminloggen</strong> efter ét år.</li>
                        </ul>
                        <p>
                            Du kan til enhver tid <strong>slette din konto</strong> på <a href="/settings">indstillingssiden</a>.
                            Det sletter straks dit navn, profilbillede, banner, bio, status, uddannelsesmærke, klasse,
                            indstillinger, adgangskode, totrinshemmelighed, Discord-kobling, IP-adresser, logins, kendte enheder,
                            browserfingeraftryk, dit Ore Mining Inc.-spil og dine nedbrudsrapporter, dine fejlrapporter, dine
                            Minesweeper- og Sudoku-spil, dit tilfældige Wordle-ord og dine vedhæftede filer. Dit navn erstattes
                            med "Deleted User".
                        </p>
                        <p>
                            Det, der er nødvendigt for, at andres chats og spil fortsat giver mening, bevares uden kobling til dit
                            navn og vises som fra "Deleted User": dine beskeder og reaktioner, spilresultater, chiphistorik, dine
                            bidrag til Project Starbound, din feedback (som gemmes, indtil en side-admin sletter den) og
                            adminloggens poster om dig.
                        </p>
                        <p>
                            Et bandlyst billede bevares, indtil den konto, det kom fra, ikke længere er udelukket, eller en admin
                            fjerner det, også hvis kontoen slettes.
                        </p>
                        <p>
                            Gratis spil uden en konto gemmes kun i din browser og slettes, når du rydder sidens data. Minesweeper
                            og legechips forsvinder, når siden lukkes eller genindlæses.
                        </p>
                        <p class="legal-note">
                            Ønsker du <strong>alle</strong> dine data slettet, herunder beskeder, eller vil du have en
                            <strong>kopi</strong> af dem, kan du skrive til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>

                    <section id="rights" aria-labelledby="privacy-rights">
                        <h2 id="privacy-rights">8. Dine rettigheder</h2>
                        <p>Efter GDPR har du ret til at:</p>
                        <ul>
                            <li>få indsigt i de oplysninger, vi har om dig, og få en kopi af dem,</li>
                            <li>få urigtige oplysninger rettet (det meste kan du selv ændre i dine indstillinger),</li>
                            <li>få dine oplysninger slettet,</li>
                            <li>gøre indsigelse mod behandlingen eller få den begrænset.</li>
                        </ul>
                        <p>
                            Browserfingeraftryk kan du selv fravælge når som helst ved at slå Do Not Track til i din browser (se
                            <a href="#fingerprint">Browserfingeraftryk</a>). Fejlrapporter og optælling af sidevisninger kan du slå
                            fra med <strong>Help improve HB Hub</strong> under dine <a href="/settings#improve">indstillinger</a>;
                            opretter du din konto med Do Not Track slået til, er den slået fra som standard (se
                            <a href="#do-not-track">Do Not Track</a>).
                        </p>
                        <p>
                            Skriv til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>; du får svar inden for
                            en måned. Du kan også klage til
                            <a href="https://www.datatilsynet.dk" target="_blank" rel="noopener noreferrer">Datatilsynet</a>.
                        </p>
                    </section>

                    <section id="changes" aria-labelledby="privacy-changes">
                        <h2 id="privacy-changes">9. Ændringer</h2>
                        <p>
                            Politikken opdateres, når HB Hub ændres. Datoen øverst viser, hvornår den sidst blev opdateret, og
                            ved ændringer får du besked, næste gang du besøger siden. Opdager du en uoverensstemmelse, er du
                            velkommen til at skrive til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
