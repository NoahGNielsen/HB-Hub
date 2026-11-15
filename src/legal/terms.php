<?php
// Terms of service: the rules for using HB Hub - accounts, how to behave in the chat, what the chips are (and aren't),
// fair play in the games, what admins can do about it, and that the site comes as-is.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too, just like privacy.php. Open to everyone, like the frontpage, and so in
// Danish like every public page. Sign-up (onboarding.php) links here. The section ids stay English, as other pages
// link to them (#chips, #moderation).
// When a rule changes, HBHUB_TERMS_UPDATED gets the date of the change. It's set in assets/php/legalUpdated.php, since
// the footer needs it too: changing it there shows everyone who agreed to the old terms the "terms updated" box.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/wallet.php'; // only for the start and daily chip amounts
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/legalUpdated.php';

/**
 * A whole number the Danish way: 25.000
 */
function termsNumber(int $number): string
{
    return number_format($number, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="da" class="home">
<head>
    <?php $headerHomeNav = true; $headerLocale = 'da_DK'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/header.php'; ?>
    <link rel="stylesheet" href="/assets/css/homepage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/homepage.css') ?>">
    <link rel="stylesheet" href="/assets/css/legal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/legal.css') ?>">
    <title>Vilkår - HB Hub</title>
    <meta name="description" content="Vilkårene for brug af HB Hub: din konto, adfærd i chatten, casinoets chips, fair play i spillene og konsekvenserne ved overtrædelser.">
</head>
<body class="home-body">
    <?php $homeNavSectionBase = '/'; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/homeNav.php'; ?>

    <main>
        <article class="legal-page" aria-labelledby="terms-title">
            <header class="legal-hero">
                <div class="legal-hero-inner">
                    <p class="home-kicker legal-kicker">Juridisk</p>
                    <h1 id="terms-title">Vilkår</h1>
                    <p class="legal-intro">
                        HB Hub er et ikke-kommercielt elevprojekt med chat, casino og spil for skolens elever. Disse vilkår
                        gælder for al brug af siden. Ved at oprette en konto eller bruge siden accepterer du dem.
                    </p>
                    <p class="legal-updated">Sidst opdateret <?= hbHubLegalDanishDate(HBHUB_TERMS_UPDATED) ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="På denne side">
                    <p class="legal-toc-title" aria-hidden="true">På denne side</p>
                    <ol>
                        <li><a href="#about">Om vilkårene</a></li>
                        <li><a href="#account">Din konto</a></li>
                        <li><a href="#behave">Adfærd</a></li>
                        <li><a href="#content">Indhold</a></li>
                        <li><a href="#chips">Casino og chips</a></li>
                        <li><a href="#games">Spil og fair play</a></li>
                        <li><a href="#moderation">Overtrædelser</a></li>
                        <li><a href="#leaving">Opsigelse</a></li>
                        <li><a href="#asis">Ansvar</a></li>
                        <li><a href="#changes">Ændringer</a></li>
                        <li><a href="#law">Lovvalg og kontakt</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <section id="about" aria-labelledby="terms-about">
                        <h2 id="terms-about">1. Om vilkårene</h2>
                        <p>
                            HB Hub (hbhub.noahgajnielsen.dk) drives af Noah Gaj Nedergaard Nielsen, Danmark. HB Hub er ikke
                            en virksomhed og drives ikke af skolen. Vilkårene udgør aftalen mellem dig og den, der driver HB Hub.
                        </p>
                        <p>
                            Behandlingen af dine personoplysninger er beskrevet i <a href="/legal/privacy">privatlivspolitikken</a>,
                            og <a href="/legal/code-of-conduct">adfærdskodekset</a> uddyber reglerne for, hvordan du opfører dig.
                        </p>
                    </section>

                    <section id="account" aria-labelledby="terms-account">
                        <h2 id="terms-account">2. Din konto</h2>
                        <ul>
                            <li>
                                <strong>Én konto pr. person.</strong> Du må ikke oprette ekstra konti, fx for at få flere daglige
                                chips eller for at omgå en udelukkelse. Konti, der bruges i den samme browser, kan genkendes (se
                                <a href="/legal/privacy">privatlivspolitikken</a>) og behandles som én person (se afsnit 7). Når én
                                af en persons konti har hentet dagens chips, får de øvrige kun 20 % af deres daglige chips den dag.
                            </li>
                            <li><strong>Din identitet.</strong> Du vælger selv dit navn, og det behøver ikke at være dit rigtige, men du må ikke udgive dig for at være en anden.</li>
                            <li>
                                <strong>Din klasse.</strong> Angiv din korrekte årgang (1.-3.) og studieretning på HTX, eller at du
                                ikke går på HTX. Årgangen opdateres automatisk den 1. juli, og efter 3. år vises du som student. Går
                                du på HTX, bliver du automatisk medlem af din klasses gruppechat. Den kan gøres lydløs eller skjules,
                                men ikke forlades. Skifter du klasse under <a href="/settings">indstillinger</a>, flyttes du til den
                                nye klasses chat.
                            </li>
                            <li>
                                <strong>Navne og profiltekster.</strong> Navne, bioer, statusser og kælenavne må kun indeholde
                                latinske bogstaver (herunder æ, ø og å), tal, tegnsætning og emoji. De må ikke linke til blokerede
                                sider (se afsnit 3), et navn må ikke være en e-mailadresse, og et brugernavn skal være på mindst 3 tegn.
                            </li>
                            <li>
                                <strong>Sikkerhed.</strong> Du er ansvarlig for alt, der sker på din konto. Del ikke din adgangskode,
                                og lad ikke andre bruge kontoen. Vi anbefaler, at du slår totrinslogin til under
                                <a href="/settings">indstillinger</a>. Har du mistanke om, at andre har adgang til din konto, skal du
                                skifte adgangskode og kontakte en admin.
                            </li>
                            <li>
                                <strong>Login med Discord.</strong> Du kan oprette dig med eller tilkoble en Discord-konto og bruge
                                den til at logge ind. Tilkobl kun din egen konto: den, der har adgang til den, kan også logge ind på
                                din HB Hub-konto (dog stadig kun med din totrinskode, hvis den er slået til). Navn, bio og billede,
                                der hentes fra Discord ved oprettelsen, skal overholde disse vilkår som alt andet på din profil. Dit
                                Discord-brugernavn vises på din profil og opdateres dagligt, medmindre du slår det fra under
                                indstillinger. Mister du adgangen til Discord-kontoen, bør du fjerne koblingen.
                            </li>
                        </ul>
                    </section>

                    <section id="behave" aria-labelledby="terms-behave">
                        <h2 id="terms-behave">3. Adfærd</h2>
                        <p>Behandl andre på HB Hub, som du ville gøre på skolen. Det er ikke tilladt at:</p>
                        <ul>
                            <li>mobbe, chikanere, true eller udtrykke had mod andre, fx på grund af race, religion, køn, seksualitet eller handicap,</li>
                            <li>dele seksuelt eller voldeligt indhold eller andet, der ikke hører hjemme i et klasselokale (herunder GIFs og billeder),</li>
                            <li>dele andres private oplysninger, billeder eller beskeder uden deres samtykke,</li>
                            <li>sende spam, svindel eller links til skadelige sider, herunder IP-loggere, linkforkortere, der skjuler destinationen, og krypto- eller phishingsvindel,</li>
                            <li>gøre noget ulovligt eller opfordre andre til ulovlige eller farlige handlinger,</li>
                            <li>forsøge at få adgang til andres konti, overbelaste siden eller omgå dens sikkerhed.</li>
                        </ul>
                        <p>
                            Overtrædelser, eller noget der får dig til at føle dig utryg, kan anmeldes til en admin eller på
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>

                    <section id="content" aria-labelledby="terms-content">
                        <h2 id="terms-content">4. Indhold</h2>
                        <p>
                            Du bevarer rettighederne til det, du lægger op: beskeder, reaktioner, vedhæftede filer, profil- og
                            bannerbilleder, gruppeikoner og baggrunde. Du giver HB Hub ret til at opbevare og vise indholdet for de
                            modtagere, det er tiltænkt, og til at komprimere eller konvertere det, så det fylder mindre og kan
                            vises i chatten (fx billeder til JPEG XL, videoer til WebM og andre filer til zip).
                        </p>
                        <ul>
                            <li>Du er ansvarlig for dit indhold og må kun dele det, du har ret til. Del ikke billeder af andre uden deres samtykke. Admins kan fjerne indhold, der overtræder vilkårene.</li>
                            <li>
                                <strong>Vedhæftede filer er midlertidige.</strong> Hver konto har 10 billetter (side-admins 20), og
                                en fil bruger én billet pr. påbegyndt 5 MB. En besked kan have op til 10 filer på i alt 50 MB. Filer
                                slettes efter 90 dage, eller efter 3 dage, hvis de bruger mere end 5 billetter, hvorefter billetterne
                                frigives. Side-admins kan tildele eller fratage billetter, fx ved misbrug. Er serverens lagerplads
                                fuld, kan der ikke sendes nye filer, før der er frigjort plads. HB Hub er ikke en backup, så gem selv
                                en kopi af det, der er vigtigt for dig.
                            </li>
                            <li>For at forhindre spam kan du sende højst 10 beskeder pr. 5 sekunder på tværs af alle chats (filer og GIFs medregnet). Beskeder ud over grænsen sendes ikke.</li>
                            <li>
                                Beskeder er ikke end-to-end-krypterede. Side-admins kan læse beskeder i alle chats, også private
                                beskeder og slettede beskeder i 3 dage, fx når de behandler en anmeldelse. Alt, hvad de ser, registreres
                                i adminloggen (se <a href="/legal/privacy#see">privatlivspolitikken</a>).
                            </li>
                            <li>
                                Feedback sendt med Feedback-knappen går kun til side-admins, som frit kan bruge dine idéer uden
                                modydelse. De samme adfærdsregler gælder som i chatten.
                            </li>
                        </ul>
                    </section>

                    <section id="chips" aria-labelledby="terms-chips">
                        <h2 id="terms-chips">5. Casino og chips</h2>
                        <p>
                            Casinoet er et spil med virtuelle chips. Du får <?= termsNumber(HBHUB_CASINO_START_CHIPS) ?> chips ved
                            start og derefter <?= termsNumber(HBHUB_CASINO_DAILY_CALENDAR[0]) ?>-<?= termsNumber(max(HBHUB_CASINO_DAILY_CALENDAR)) ?>
                            chips gratis hver dag. Beløbet følger en kalender på <?= HBHUB_CASINO_DAILY_DAYS ?> dage på casinoets
                            forside og stiger, jo flere dage i træk du henter dem. Springer du en dag over, starter kalenderen forfra.
                        </p>
                        <ul>
                            <li><strong>Chips er ikke penge.</strong> De kan ikke veksles til penge, gavekort eller andet, og gevinster i casinoet kan ikke udbetales.</li>
                            <li>Du må ikke købe, sælge eller spille om chips, konti eller casinoresultater for rigtige penge eller ting, hverken på HB Hub eller andre steder, bortset fra køb direkte hos ejeren, se <a href="#chips-koeb">nedenfor</a>.</li>
                            <li>Side-admins kan tilføje og fratage chips, fx for at tilføje chips i de sjældne tilfælde, hvor nogen har købt dem, eller rette og nulstille saldoer efter fejl eller snyd.</li>
                        </ul>
                        <h3>Legechips i de gratis spil</h3>
                        <p>
                            Blackjack, Baccarat, Roulette og Slots kan prøves uden konto på <a href="/play">/play</a> med legechips.
                            Legechips har ingen værdi, gælder kun, til siden lukkes eller genindlæses, fornyes, når de slipper op, og
                            overføres aldrig til en konto.
                        </p>
                        <h3 id="chips-koeb">Køb af chips</h3>
                        <p>
                            Chips er gratis, og casinoet er lavet til at blive spillet med de gratis chips. At købe chips er
                            <strong>ikke en almindelig del af HB Hub</strong>, men en undtagelse, som ejeren kun gør i enkelte
                            tilfælde. Der findes ingen betaling på siden, og du skal aldrig købe chips for at kunne bruge HB Hub
                            eller spille i casinoet.
                        </p>
                        <ul>
                            <li>
                                Ekstra chips kan kun købes direkte fra ejeren af HB Hub til
                                <?= termsNumber(HBHUB_CASINO_CHIPS_PER_KRONE) ?> chips pr. 1 kr., som beskrevet på siden
                                <a href="/casino/buy-chips">Buy chips</a>.
                            </li>
                            <li>Ejeren bestemmer selv, om et køb kan lade sig gøre, og kan sige nej uden at give en grund. Ingen har krav på at kunne købe chips.</li>
                            <li>
                                <strong>Er du under 18 år, kan du aldrig købe chips.</strong> Det er der ingen undtagelser fra.
                            </li>
                            <li>Købte chips er almindelige chips. De kan tabes i spillene, og ovenstående regler gælder også for dem.</li>
                            <li>
                                <strong>Køb refunderes ikke.</strong> Det gælder også, hvis du bliver udelukket, sletter din konto,
                                eller casinoet bliver blokeret af dig selv eller en admin, og hvis købte chips tabes, rettes eller
                                nulstilles.
                            </li>
                        </ul>
                        <p class="legal-note">
                            Du kan blokere casinoet og spillene for dig selv under <a href="/settings">indstillinger</a>, fx i
                            skoletiden. Føles spil, også om rigtige penge, som et problem, kan du få gratis og anonym hjælp hos
                            <a href="https://stopspillet.dk" target="_blank" rel="noopener noreferrer">StopSpillet</a>.
                        </p>
                    </section>

                    <section id="games" aria-labelledby="terms-games">
                        <h2 id="terms-games">6. Spil og fair play</h2>
                        <h3>Generelt</h3>
                        <ul>
                            <li>
                                <strong>Spil fair.</strong> Snyd, scripts, bots, autoklikkere, makroer og samarbejde om at ødelægge
                                et spil for andre er ikke tilladt. Spilresultater registreres og kontrolleres af HB Hubs server, og
                                resultater, der er opnået hurtigere, end et menneske kan, tæller ikke.
                            </li>
                            <li>
                                Finder du en fejl, fx en der giver gratis chips, skal du rapportere den med Feedback-knappen eller på
                                <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>. Bevidst udnyttelse af fejl
                                regnes som snyd.
                            </li>
                            <li>Spil har tidsgrænser. Forlader du et spil eller trækker du ikke i tide, fortsætter spillet uden dig.</li>
                            <li>
                                Forlader du et igangværende spil skak, Matador eller UNO (eller er du væk så længe, at du regnes som
                                udgået), tæller det som et nederlag på ranglisten, medmindre værten har sat spillet på pause, eller du
                                i UNO allerede har spillet alle dine kort. I Matador tæller nederlaget, selv om du vender tilbage.
                            </li>
                            <li>Admins kan fjerne resultater fra ranglisterne og nulstille spil, hvor der er snydt.</li>
                        </ul>
                        <h3>Regler for de enkelte spil</h3>
                        <p>Spillenes fulde regler står i <a href="/docs/games/">hjælpen</a>. Derudover gælder:</p>
                        <ul>
                            <li>
                                <strong>Wordle:</strong> Der er ét dansk og ét engelsk ord om dagen med ét forsøg på hvert. Et
                                påbegyndt ord, der ikke er gættet inden midnat (dansk tid), tæller som misset og afslutter din stime.
                                Afslør ikke dagens ord for andre, før dagen er omme. Tilfældige ord tæller ikke med i statistik,
                                stimer eller rangliste.
                            </li>
                            <li>
                                <strong>Minesweeper:</strong> Et spil, du forlader eller starter forfra, tæller som spillet, men ikke
                                vundet. En sejr med mere end 10 nødvendige klik i sekundet tæller ikke, og et spil, hvor klik kommer
                                fra kode (fx browserens konsol), stoppes.
                            </li>
                            <li>
                                <strong>Sudoku:</strong> Tiden måles uden pauser og tid væk fra siden. Kun opgaver løst uden hints
                                tæller, og et nyt spil afslutter det igangværende. En opgave løst på under et sekund pr. tomt felt
                                tæller ikke.
                            </li>
                            <li>
                                <strong>Ore Mining Inc.:</strong> Spillet kører i én fane ad gangen, og højst 20 klik i sekundet
                                tæller. Klik, der ligner automatisering, giver kun 20 % af det normale udbytte. Fremskridt, der ikke
                                er nået at blive gemt, forsøges gendannet næste gang, men kan gå tabt. Star Shards har ingen værdi
                                uden for spillet og kan ikke købes, sælges eller overføres. Starbound Parts givet til Project Starbound
                                kan ikke tages tilbage, og dit navn og antal dele vises for alle med en konto. Forlader du en Anomaly,
                                før den er færdig, mister du forsøget, og et byggeris sidste trin mister en del af indholdet, hvis det
                                ikke fyldes i tide. Butikken, byggerierne, Anomalies og deres fordele kan ændres, efterhånden som
                                spillet balanceres, og fremskridt, titler og dele opnået ved snyd kan fjernes.
                            </li>
                        </ul>
                        <h3>Gratis spil uden konto</h3>
                        <p>
                            De gratis spil på <a href="/play">/play</a> er også omfattet af disse vilkår. Uden konto gemmes Wordle,
                            Sudoku og Ore Mining Inc. kun i din browser, og et Minesweeper-bræt kun, til siden lukkes. Gæsters
                            Minesweeper- og Sudoku-resultater kommer ikke på ranglisten. Opretter du dig eller logger ind fra et
                            gratis spil, overføres dagens Wordle og dit Ore Mining Inc.-spil til kontoen, men kun hvis kontoen ikke
                            allerede har dem. Har browseren tidligere været logget ind, sendes du i stedet til login. Spillene må
                            ikke overbelastes med automatiserede forespørgsler.
                        </p>
                    </section>

                    <section id="moderation" aria-labelledby="terms-moderation">
                        <h2 id="terms-moderation">7. Overtrædelser</h2>
                        <p>Afhængigt af overtrædelsens alvor kan admins:</p>
                        <ul>
                            <li>slette beskeder, vedhæftede filer, billeder og grupper, løsne fastgjorte beskeder og ændre eller fjerne navne, bioer og statusser,</li>
                            <li>bandlyse slettede profil- og bannerbilleder, så de ikke kan bruges igen,</li>
                            <li>nulstille eller fratage chips, fjerne resultater fra ranglisterne og fratage billetter til vedhæftede filer,</li>
                            <li>blokere Casino og Spil for kontoen. En sådan blokering kan kun ophæves af en admin,</li>
                            <li>
                                spøgelsesudelukke kontoen midlertidigt eller permanent uden at give besked. Chatten virker normalt for
                                personen, men alt, hvad de gør i den, er kun synligt for dem selv og side-admins. Nye konti (op til 3
                                dage gamle), der oprettes eller bruges i samme browser, spøgelsesudelukkes også,
                            </li>
                            <li>udelukke kontoen midlertidigt eller permanent. Udelukkelsen omfatter også den browser, kontoen er brugt i, og som regel kontoens profilbillede og evt. bannerbillede,</li>
                            <li>
                                anvende ovenstående på alle en persons konti på én gang. Konti brugt i samme browser regnes som samme
                                person, efter at admins har kontrolleret, at der ikke blot er tale om en delt computer.
                            </li>
                        </ul>
                        <p>
                            Upload eller brug af et bandlyst billede, herunder redigerede kopier som skalerede eller beskårne
                            versioner, medfører automatisk udelukkelse. Bandlyste billeder omfatter udelukkede kontis profil- og
                            bannerbilleder samt billeder, som admins har fjernet eller tilføjet til listen.
                        </p>
                        <p>
                            Admins afgør, hvad der er en overtrædelse, også i tilfælde, som disse vilkår ikke nævner.
                            <strong>
                                Admins kan når som helst og uden varsel eller begrundelse udelukke, spøgelsesudelukke eller slette
                                enhver konto eller træffe enhver anden af ovenstående foranstaltninger.
                            </strong>
                            Mener du, at en afgørelse er forkert, kan du skrive til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a> med en begrundelse.
                        </p>
                    </section>

                    <section id="leaving" aria-labelledby="terms-leaving">
                        <h2 id="terms-leaving">8. Opsigelse</h2>
                        <p>
                            Du kan til enhver tid stoppe med at bruge HB Hub og slette din konto under
                            <a href="/settings">indstillinger</a>. Hvad der slettes, står i
                            <a href="/legal/privacy#keep">privatlivspolitikken</a>.
                        </p>
                        <p>
                            HB Hub kan lukke konti, der overtræder vilkårene, eller lukke siden helt. Lukker siden, varsles det så
                            vidt muligt på siden i forvejen.
                        </p>
                    </section>

                    <section id="asis" aria-labelledby="terms-asis">
                        <h2 id="terms-asis">9. Ansvar</h2>
                        <p>
                            HB Hub er et gratis projekt, der drives i fritiden og løbende udvikles. Funktioner kan derfor ændres,
                            fejle, være utilgængelige eller i værste fald gå tabt uden varsel.
                        </p>
                        <p>
                            Siden leveres, som den er, uden garanti for, at den altid virker eller er fejlfri. I det omfang dansk
                            ret tillader det, er HB Hub ikke ansvarlig for tab som følge af brug af siden, nedetid eller indhold
                            lagt op af andre brugere.
                        </p>
                    </section>

                    <section id="changes" aria-labelledby="terms-changes">
                        <h2 id="terms-changes">10. Ændringer</h2>
                        <p>
                            Vilkårene kan ændres, efterhånden som HB Hub udvikler sig. Datoen øverst viser den seneste ændring, og
                            du får besked på siden, næste gang du besøger den. Fortsætter du med at bruge HB Hub efter en ændring,
                            accepterer du de nye vilkår.
                        </p>
                    </section>

                    <section id="law" aria-labelledby="terms-law">
                        <h2 id="terms-law">11. Lovvalg og kontakt</h2>
                        <p>
                            Vilkårene er underlagt dansk ret og begrænser ikke de rettigheder, du har efter gældende lovgivning.
                        </p>
                        <p>
                            Spørgsmål om vilkårene eller anmeldelser kan sendes til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>
                </div>
            </div>
        </article>
    </main>
    <?php $hbHubShowSiteFooter = true; include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/footer.php'; ?>
</body>
</html>
