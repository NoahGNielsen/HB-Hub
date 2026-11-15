<?php
// Code of conduct: how people are expected to treat each other on HB Hub - in chats, groups, games and the casino -
// and how to report someone. The Terms of Service (terms.php) hold the actual rules and what admins can do; this page
// is the friendlier, longer version of their "How to behave" section, with examples.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too, just like terms.php and privacy.php. Open to everyone, like the
// frontpage, and so in Danish like every public page. The site footer (footer.php) links here.
// When an expectation changes, HBHUB_CONDUCT_UPDATED gets the date of the change.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/legalUpdated.php'; // only for hbHubLegalDanishDate()

const HBHUB_CONDUCT_UPDATED = '14 November 2026';
?>
<!DOCTYPE html>
<html lang="da" class="home">
<head>
    <?php $headerHomeNav = true; $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Adfærdskodeks - HB Hub</title>
    <meta name="description" content="Sådan behandler vi hinanden på HB Hub: i chatten, i grupper, i spillene og casinoet, og hvordan du anmelder nogen, der går over stregen.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <article class="legal-page" aria-labelledby="conduct-title">
            <header class="legal-hero">
                <div class="legal-hero-inner">
                    <p class="home-kicker legal-kicker">Juridisk</p>
                    <h1 id="conduct-title">Adfærdskodeks</h1>
                    <p class="legal-intro">
                        Alle på HB Hub går på den samme skole. Du ser dem, du chatter med, på gangen i morgen, så behandl dem,
                        som du selv gerne vil behandles dér. Denne side forklarer, hvad det betyder i praksis. Selve reglerne,
                        og hvad der sker, når de bliver brudt, står i <a href="/legal/terms">vilkårene</a>.
                    </p>
                    <p class="legal-updated">Sidst opdateret <?= hbHubLegalDanishDate(HBHUB_CONDUCT_UPDATED) ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="På denne side">
                    <p class="legal-toc-title" aria-hidden="true">På denne side</p>
                    <ol>
                        <li><a href="#short">Den korte version</a></li>
                        <li><a href="#chat">I chatten</a></li>
                        <li><a href="#groups">At styre en gruppe</a></li>
                        <li><a href="#privacy">Andres privatliv</a></li>
                        <li><a href="#profile">Din profil</a></li>
                        <li><a href="#games">Spil og casinoet</a></li>
                        <li><a href="#site">Selve siden</a></li>
                        <li><a href="#report">At anmelde nogen</a></li>
                        <li><a href="#admins">Hvad admins gør</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <section id="short" aria-labelledby="conduct-short">
                        <h2 id="conduct-short">1. Den korte version</h2>
                        <ul>
                            <li><strong>Vær sød.</strong> Det er fint at fjolle. At gøre nogen til grinet, når de ikke selv griner, er ikke.</li>
                            <li><strong>Hold det skolevenligt.</strong> Ville du ikke vise det i et klasselokale, så læg det ikke op her.</li>
                            <li><strong>Respektér privatliv.</strong> Det, nogen fortæller dig i en chat, er ikke dit at give videre.</li>
                            <li><strong>Spil fair.</strong> Vind og tab uden at snyde, og uden at være en dårlig vinder eller taber.</li>
                            <li><strong>Sig fra.</strong> Er der noget galt, så sig det til en admin. Du kommer ikke i problemer for at anmelde.</li>
                        </ul>
                    </section>

                    <section id="chat" aria-labelledby="conduct-chat">
                        <h2 id="conduct-chat">2. I chatten</h2>
                        <p>Ikke i orden, hverken i private chats, grupper, spilchats, GIFs, reaktioner eller vedhæftede filer:</p>
                        <ul>
                            <li>mobning, at gøre grin med eller gå flere sammen mod nogen, også "bare for sjov",</li>
                            <li>trusler, eller at sige til nogen, at de skal gøre skade på sig selv,</li>
                            <li>had eller skældsord mod nogen, fx på grund af deres race, religion, køn, seksualitet eller handicap,</li>
                            <li>seksuelt indhold, blod og gore, eller noget, der er ment til at chokere,</li>
                            <li>at spamme beskeder, GIFs, reaktioner, vedhæftede filer, @omtaler eller notifikationer, eller oversvømme en chat, så andre ikke kan snakke,</li>
                            <li>svindel, falske giveaways eller links til skadelige eller lyssky sider - links til IP-loggere (som finder ud af, hvor nogen er, i det øjeblik de klikker), linkforkortere, der kan skjule, hvor et link fører hen, kryptosvindel- og phishingsider, og andre kendte dårlige sider er blokeret, og at ændre linket for at snige et igennem er lige så slemt,</li>
                            <li>at sende virus eller andre skadelige filer, eller filer, det ikke er dine at dele (som piratkopierede film, spil eller musik).</li>
                        </ul>
                        <p>
                            Beder nogen dig om at holde op med at skrive til dem, så hold op. Bliv ikke ved med at kontakte nogen, der
                            har blokeret dig eller forladt en chat med dig, fx ved at tilføje dem til en ny gruppe.
                        </p>
                        <p class="legal-note">
                            Sletter du en besked, bliver den skjult i chatten, men admins kan stadig se den (se
                            <a href="/legal/privacy#see">privatlivspolitikken</a>), så "jeg har slettet den" gør ikke en ond besked
                            god igen. Tænk, før du sender.
                        </p>
                    </section>

                    <section id="groups" aria-labelledby="conduct-groups">
                        <h2 id="conduct-groups">3. At styre en gruppe</h2>
                        <p>Laver du en gruppe, eller er du admin i en, hjælper du med at gøre den til et godt sted at være:</p>
                        <ul>
                            <li>Vælg et gruppenavn, ikon og en baggrund, som alle i gruppen ville være okay med.</li>
                            <li>Lav ikke grupper for at snakke om, eller gøre grin med, nogen, der ikke er med i dem.</li>
                            <li>Det er fint at fjerne folk, men brug det ikke til at udstille eller ydmyge nogen.</li>
                            <li>Kælenavne, du giver folk, skal være nogle, de ville være glade for at se, især i en gruppe, hvor alle kan se dem.</li>
                        </ul>
                        <p>
                            Gruppeadmins kan slette beskeder i deres gruppe og løsne alt, der er fastgjort i den, men sker der noget
                            alvorligt dér, så sig det stadig til en side-admin, så det kan blive håndteret ordentligt. Alle kan
                            fastgøre en besked, så hele chatten kan se den, så fastgør det, der er nyttigt for alle, ikke noget, der
                            skal gøre nogen flov.
                        </p>
                        <p>
                            Klassechats er anderledes: alle i din klasse kommer automatisk med i en, og ingen i den er gruppeadmin.
                            Det er en chat for hele klassen, så hold den til noget, alle i den kan være en del af, og sig det til en
                            side-admin, hvis der er noget dér, der skal tages hånd om.
                        </p>
                    </section>

                    <section id="privacy" aria-labelledby="conduct-privacy">
                        <h2 id="conduct-privacy">4. Andres privatliv</h2>
                        <ul>
                            <li>Tag ikke skærmbilleder af eller videresend nogens beskeder for at gøre dem flove eller sprede drama.</li>
                            <li>Læg ikke billeder eller videoer af andre op uden at spørge dem først.</li>
                            <li>Del ikke nogens personlige oplysninger, som deres adresse, telefonnummer eller konti på andre sider.</li>
                            <li>Log ikke ind på en andens konto, heller ikke hvis de har ladet den stå åben. Log dem ud, og sig det til dem i stedet.</li>
                        </ul>
                    </section>

                    <section id="profile" aria-labelledby="conduct-profile">
                        <h2 id="conduct-profile">5. Din profil</h2>
                        <p>
                            Dit navn, profilbillede, banner, din bio og status kan ses af alle på HB Hub, så de samme regler gælder
                            for dem som for beskeder. Brug et navn, folk fra skolen kan kende dig på, og få ikke din profil til at
                            ligne en andens. Navne, bioer, statusser og de kælenavne, du giver folk i chats, kan kun skrives med det
                            latinske alfabet (som a-z, æ, ø og å), tal, tegnsætning og emoji, så alle kan læse dem. De links, der er
                            blokeret i beskeder, kan heller ikke stå i en bio, status eller et kælenavn. Vælg den klasse og det
                            uddannelsesmærke, der virkelig er dine.
                        </p>
                        <p>
                            En udelukket kontos profilbillede kan ikke bruges igen, og det kan et profilbillede, som en admin har
                            fjernet og bandlyst, heller ikke. Uploader du det, eller en skaleret eller beskåret kopi af det, også når
                            du opretter dig, bliver kontoen udelukket med det samme (også den konto, det blev fjernet fra), så brug
                            ikke et billede fra en, der er blevet udelukket, og sæt ikke et billede, en admin har fjernet, tilbage.
                        </p>
                    </section>

                    <section id="games" aria-labelledby="conduct-games">
                        <h2 id="conduct-games">6. Spil og casinoet</h2>
                        <ul>
                            <li>Drillerier skal holdes venlige. Sig "godt spillet", uanset om du vandt eller tabte.</li>
                            <li>Træk ikke et spil i langdrag med vilje, og forlad ikke et bord, bare fordi du taber. Andre venter på dig.</li>
                            <li>Ingen snyd, scripts, autoklikkere eller at gå sammen mod én spiller. Har du fundet en fejl? Rapportér den, brug den ikke.</li>
                            <li>Afslør ikke dagens Wordle-ord for andre. Del dine farvede felter, ikke ordet, til dagen er omme.</li>
                            <li>Pres ikke nogen til at spille, satse eller give dig deres chips.</li>
                            <li>Chips er kun for sjov. Byt dem, eller spilresultater, aldrig med hinanden for rigtige penge eller rigtige ting. Den eneste måde at købe chips på er fra ejeren (se <a href="/legal/terms#chips">vilkårene</a>).</li>
                        </ul>
                        <p class="legal-note">
                            Hvis casinoet eller spillene tager mere af din tid, end du gerne vil, især i timerne, kan du blokere dem
                            for dig selv under dine <a href="/settings">indstillinger</a>. Hvis spil, også med rigtige penge,
                            begynder at føles som et problem for dig eller en ven, giver
                            <a href="https://stopspillet.dk" target="_blank" rel="noopener noreferrer">StopSpillet</a> gratis og
                            anonym hjælp.
                        </p>
                    </section>

                    <section id="site" aria-labelledby="conduct-site">
                        <h2 id="conduct-site">7. Selve siden</h2>
                        <p>
                            HB Hub er drevet af én elev i fritiden. Prøv ikke at ødelægge den, overbelaste den eller komme uden om
                            dens sikkerhed. Finder du et sikkerhedsproblem, så rapportér det privat via
                            <a href="https://github.com/NoahGNielsen/HB-Hub/security/advisories/new" target="_blank" rel="noopener noreferrer">GitHub</a>
                            eller på e-mail i stedet for at fortælle alle om det, så det kan blive rettet først. Tak, det hjælper virkelig.
                        </p>
                    </section>

                    <section id="report" aria-labelledby="conduct-report">
                        <h2 id="conduct-report">8. At anmelde nogen</h2>
                        <p>
                            Bryder nogen dette adfærdskodeks eller får dig til at føle dig utryg, så sig det til en side-admin, brug
                            Feedback-knappen nederst i sidebjælken (kun side-admins kan læse den), eller skriv til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>. Det hjælper at sige, hvem det var,
                            hvilken chat eller hvilket spil det skete i, og cirka hvornår. Anmeldelser bliver holdt private, og den,
                            du anmelder, får ikke at vide, hvem der anmeldte dem.
                        </p>
                        <p>
                            Du behøver ikke blive ved med at læse deres beskeder, mens det bliver undersøgt: du kan gøre chatten
                            lydløs, skjule den eller forlade den fra dens menu, og Bloker på en beskedanmodning forhindrer dem i at
                            sende dig nye.
                        </p>
                        <p class="legal-note">
                            Er det alvorligt, som trusler, at nogen er i fare, eller noget, der også sker uden for HB Hub, så snak
                            også med en lærer, en forælder eller en anden voksen, du stoler på. Er nogen i akut fare, så ring 112.
                        </p>
                    </section>

                    <section id="admins" aria-labelledby="conduct-admins">
                        <h2 id="conduct-admins">9. Hvad admins gør</h2>
                        <p>
                            Admins kigger på hver anmeldelse og beslutter, hvad der skal ske, ud fra hvor alvorligt det er. Tit er en
                            advarsel nok. Andre gange bliver beskeder slettet, chips taget væk, eller en konto udelukket eller
                            spøgelsesudelukket (det, den gør i chats, bliver kun vist for den selv, uden at den får det at vide). Hele
                            listen står i <a href="/legal/terms#moderation">vilkårene</a>. Admins kan også gøre alt det af en
                            hvilken som helst grund, eller ingen, uden at forklare hvorfor.
                        </p>
                        <p>
                            Denne side kan ikke nævne alt, så admins griber også ind over for ting, der ikke står her, men tydeligt
                            går imod ånden i den. Admins følger også dette adfærdskodeks. Synes du, en afgørelse var uretfærdig, så
                            skriv til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a> og forklar hvorfor.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
