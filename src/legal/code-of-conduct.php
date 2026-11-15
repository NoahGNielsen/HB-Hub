<?php
// Code of conduct: how people are expected to treat each other on HB Hub - in chats, groups, games and the casino -
// and how to report someone. The Terms of Service (terms.php) hold the actual rules and what admins can do; this page
// expands on their "Adfærd" section with concrete examples.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too, just like terms.php and privacy.php. Open to everyone, like the
// frontpage, and so in Danish like every public page. The site footer (footer.php) links here.
// When an expectation changes, HBHUB_CONDUCT_UPDATED gets the date of the change.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/legalUpdated.php'; // only for hbHubLegalDanishDate()

const HBHUB_CONDUCT_UPDATED = '15 November 2026';
?>
<!DOCTYPE html>
<html lang="da" class="home">
<head>
    <?php $headerHomeNav = true; $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Adfærdskodeks - HB Hub</title>
    <meta name="description" content="Retningslinjer for, hvordan vi behandler hinanden på HB Hub i chats, grupper, spil og casinoet, og hvordan du anmelder overtrædelser.">
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
                        Alle på HB Hub går på den samme skole, og du forventes at behandle andre her, som du ville gøre på
                        skolen. Adfærdskodekset uddyber reglerne i <a href="/legal/terms">vilkårene</a> med konkrete eksempler.
                        Vilkårene beskriver også, hvad der sker ved overtrædelser.
                    </p>
                    <p class="legal-updated">Sidst opdateret <?= hbHubLegalDanishDate(HBHUB_CONDUCT_UPDATED) ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="På denne side">
                    <p class="legal-toc-title" aria-hidden="true">På denne side</p>
                    <ol>
                        <li><a href="#short">Grundprincipper</a></li>
                        <li><a href="#chat">Beskeder og chats</a></li>
                        <li><a href="#groups">Grupper</a></li>
                        <li><a href="#privacy">Andres privatliv</a></li>
                        <li><a href="#profile">Din profil</a></li>
                        <li><a href="#games">Spil og casino</a></li>
                        <li><a href="#site">Sidens sikkerhed</a></li>
                        <li><a href="#report">Anmeldelser</a></li>
                        <li><a href="#admins">Håndhævelse</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <section id="short" aria-labelledby="conduct-short">
                        <h2 id="conduct-short">1. Grundprincipper</h2>
                        <ul>
                            <li><strong>Vis respekt.</strong> Humor er velkommen, men ikke på andres bekostning.</li>
                            <li><strong>Hold indholdet passende.</strong> Del kun det, du også ville vise i et klasselokale.</li>
                            <li><strong>Respektér privatliv.</strong> Det, andre fortæller dig, er ikke dit at dele.</li>
                            <li><strong>Spil fair.</strong> Ingen snyd, og opfør dig ordentligt, uanset om du vinder eller taber.</li>
                            <li><strong>Sig til.</strong> Anmeld problemer til en admin. Du kommer aldrig i problemer for at anmelde.</li>
                        </ul>
                    </section>

                    <section id="chat" aria-labelledby="conduct-chat">
                        <h2 id="conduct-chat">2. Beskeder og chats</h2>
                        <p>Følgende er ikke tilladt i nogen chat, heller ikke i GIF'er, reaktioner og vedhæftede filer:</p>
                        <ul>
                            <li>mobning, chikane eller at gå flere sammen mod nogen, også selvom det er "for sjov",</li>
                            <li>trusler eller opfordringer til selvskade,</li>
                            <li>had eller nedsættende udtryk, fx på grund af race, religion, køn, seksualitet eller handicap,</li>
                            <li>seksuelt, voldeligt eller chokerende indhold,</li>
                            <li>spam med beskeder, GIF'er, reaktioner, filer, @omtaler eller notifikationer,</li>
                            <li>svindel, falske giveaways og links til skadelige sider. Links til IP-loggere, linkforkortere, krypto- og phishingsvindel og andre kendte skadelige sider blokeres, og det er en overtrædelse at forsøge at omgå blokeringen,</li>
                            <li>virus og andre skadelige filer samt filer, du ikke har ret til at dele, fx piratkopierede film, spil eller musik.</li>
                        </ul>
                        <p>
                            Beder nogen dig om at stoppe med at skrive til dem, skal du respektere det. Kontakt ikke en person, der
                            har blokeret dig eller forladt en chat med dig, fx ved at tilføje vedkommende til en ny gruppe.
                        </p>
                        <p class="legal-note">
                            En slettet besked skjules i chatten, men side-admins kan se dens tekst i 3 dage (se
                            <a href="/legal/privacy#see">privatlivspolitikken</a>). At slette en besked fjerner derfor ikke ansvaret for den.
                        </p>
                    </section>

                    <section id="groups" aria-labelledby="conduct-groups">
                        <h2 id="conduct-groups">3. Grupper</h2>
                        <p>Opretter du en gruppe eller er admin i en, har du et særligt ansvar for, at den er et godt sted at være:</p>
                        <ul>
                            <li>Gruppens navn, ikon og baggrund skal være acceptable for alle medlemmer.</li>
                            <li>Opret ikke grupper for at tale om eller gøre grin med personer, der ikke er med.</li>
                            <li>Du må fjerne medlemmer, men ikke for at udstille eller ydmyge dem.</li>
                            <li>Kælenavne skal være nogle, personen selv ville være tilfreds med.</li>
                            <li>Alle kan fastgøre beskeder. Fastgør kun det, der er nyttigt for hele chatten, aldrig noget, der skal gøre nogen flov.</li>
                        </ul>
                        <p>
                            Gruppeadmins kan slette beskeder og løsne fastgjorte beskeder i deres gruppe. Alvorlige hændelser skal
                            alligevel anmeldes til en side-admin.
                        </p>
                        <p>
                            Klassechats har ingen gruppeadmins, og alle i klassen er automatisk medlemmer. Hold derfor indholdet
                            relevant for hele klassen, og kontakt en side-admin, hvis der opstår problemer.
                        </p>
                    </section>

                    <section id="privacy" aria-labelledby="conduct-privacy">
                        <h2 id="conduct-privacy">4. Andres privatliv</h2>
                        <ul>
                            <li>Tag ikke skærmbilleder af eller videresend andres beskeder for at udstille dem eller skabe konflikter.</li>
                            <li>Del ikke billeder eller videoer af andre uden deres tilladelse.</li>
                            <li>Del ikke andres personlige oplysninger, fx adresse, telefonnummer eller konti på andre sider.</li>
                            <li>Brug ikke andres konti, heller ikke hvis de står åbne. Log personen ud, og giv vedkommende besked.</li>
                        </ul>
                    </section>

                    <section id="profile" aria-labelledby="conduct-profile">
                        <h2 id="conduct-profile">5. Din profil</h2>
                        <p>
                            Dit navn, profilbillede, banner, din bio og status kan ses af alle på HB Hub, og de samme regler gælder
                            for dem som for beskeder. Dit navn behøver ikke at være dit rigtige, men efterlign ikke andre, og
                            angiv din rigtige klasse og dit rigtige uddannelsesmærke. Navne, bioer, statusser og kælenavne må kun
                            indeholde latinske bogstaver (herunder æ, ø og å), tal, tegnsætning og emoji og må ikke indeholde
                            blokerede links.
                        </p>
                        <p>
                            Profilbilleder fra udelukkede konti og billeder, som en admin har fjernet og bandlyst, kan ikke bruges
                            igen. Uploades et sådant billede eller en skaleret eller beskåret kopi af det, også ved oprettelse,
                            udelukkes kontoen straks, og det samme gælder den konto, billedet oprindeligt blev fjernet fra.
                        </p>
                    </section>

                    <section id="games" aria-labelledby="conduct-games">
                        <h2 id="conduct-games">6. Spil og casino</h2>
                        <ul>
                            <li>Hold en venlig tone over for dine medspillere, uanset resultatet.</li>
                            <li>Træk ikke et spil i langdrag med vilje, og forlad ikke et spil, fordi du er ved at tabe.</li>
                            <li>Snyd, scripts, autoklikkere og samarbejde mod en enkelt spiller er ikke tilladt. Fejl skal rapporteres, ikke udnyttes.</li>
                            <li>Afslør ikke dagens Wordle-ord, før dagen er omme. Del gerne dine farvede felter.</li>
                            <li>Pres ikke andre til at spille, satse eller give dig chips.</li>
                            <li>Chips og spilresultater må aldrig byttes for rigtige penge eller ting. Chips kan kun købes hos ejeren (se <a href="/legal/terms#chips">vilkårene</a>).</li>
                        </ul>
                        <p class="legal-note">
                            Du kan blokere casinoet og spillene for dig selv, fx i skoletiden, under <a href="/settings">indstillinger</a>.
                            Oplever du eller en ven, at spil begynder at blive et problem, også med rigtige penge, tilbyder
                            <a href="https://stopspillet.dk" target="_blank" rel="noopener noreferrer">StopSpillet</a> gratis og
                            anonym rådgivning.
                        </p>
                    </section>

                    <section id="site" aria-labelledby="conduct-site">
                        <h2 id="conduct-site">7. Sidens sikkerhed</h2>
                        <p>
                            Forsøg ikke at overbelaste siden, få adgang til noget, du ikke har adgang til, eller omgå dens
                            sikkerhed. Finder du et sikkerhedsproblem, skal du rapportere det privat via
                            <a href="https://github.com/NoahGNielsen/HB-Hub/security/advisories/new" target="_blank" rel="noopener noreferrer">GitHub</a>
                            eller på e-mail i stedet for at offentliggøre det, så det kan rettes først.
                        </p>
                    </section>

                    <section id="report" aria-labelledby="conduct-report">
                        <h2 id="conduct-report">8. Anmeldelser</h2>
                        <p>
                            Overtræder nogen adfærdskodekset, eller føler du dig utryg, kan du kontakte en side-admin, bruge
                            Feedback-knappen nederst i sidebjælken (kun side-admins kan læse den) eller skrive til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>. Oplys gerne, hvem det drejer sig om,
                            hvilken chat eller hvilket spil det skete i, og cirka hvornår. Anmeldelser behandles fortroligt, og den
                            anmeldte får ikke at vide, hvem der har anmeldt.
                        </p>
                        <p>
                            Mens sagen undersøges, kan du gøre chatten lydløs, skjule den eller forlade den fra dens menu. Vælger du
                            Bloker på en beskedanmodning, kan afsenderen ikke sende dig nye.
                        </p>
                        <p class="legal-note">
                            Ved alvorlige hændelser, fx trusler, eller hvis det også foregår uden for HB Hub, bør du også tale med en
                            lærer, en forælder eller en anden voksen, du har tillid til. Er nogen i akut fare, så ring 112.
                        </p>
                    </section>

                    <section id="admins" aria-labelledby="conduct-admins">
                        <h2 id="conduct-admins">9. Håndhævelse</h2>
                        <p>
                            Admins behandler alle anmeldelser og reagerer ud fra, hvor alvorlig overtrædelsen er. Det kan være en
                            advarsel, sletning af beskeder, fratagelse af chips, udelukkelse eller spøgelsesudelukkelse (hvor
                            kontoens aktivitet i chats kun vises for den selv, uden at den får besked). Den fulde liste står i
                            <a href="/legal/terms#moderation">vilkårene</a>. Admins kan træffe disse afgørelser uden at angive en grund.
                        </p>
                        <p>
                            Adfærdskodekset kan ikke dække alle situationer, så admins kan også gribe ind over for adfærd, der ikke er
                            nævnt her, men som tydeligt strider mod dets formål. Admins er selv underlagt adfærdskodekset. Er du
                            uenig i en afgørelse, kan du skrive til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>
                            med en begrundelse.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
