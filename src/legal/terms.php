<?php
// Terms of service: the rules for using HB Hub - accounts, how to behave in the chat, what the chips are (and aren't),
// fair play in the games, what admins can do about it, and that the site comes as-is.
// A .legal-page article (legal.css: a dark hero, then the contents next to the text) under the frontpage's top bar
// (homeNav.php), so it loads homepage.css too, just like privacy.php. Open to everyone, like the frontpage, and so in
// Danish like every public page. Sign-up (onboarding.php) links here. The section ids stay English, as other pages
// link to them.
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
    <meta name="description" content="Reglerne for at bruge HB Hub: din konto, hvordan du opfører dig i chatten, hvad casinoets chips er, fair play og hvad der sker, hvis reglerne bliver brudt.">
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
                        HB Hub er et elevprojekt, lavet for sjov og for at chatte med folk fra skolen. Disse vilkår er
                        husreglerne. De er korte og skrevet i et almindeligt sprog, så læs dem gerne. Ved at oprette en konto
                        eller bruge siden accepterer du dem.
                    </p>
                    <p class="legal-updated">Sidst opdateret <?= hbHubLegalDanishDate(HBHUB_TERMS_UPDATED) ?></p>
                </div>
            </header>

            <div class="legal-layout">
                <nav class="legal-toc" aria-label="På denne side">
                    <p class="legal-toc-title" aria-hidden="true">På denne side</p>
                    <ol>
                        <li><a href="#about">Om disse vilkår</a></li>
                        <li><a href="#account">Din konto</a></li>
                        <li><a href="#behave">Sådan opfører du dig</a></li>
                        <li><a href="#content">Det, du lægger op</a></li>
                        <li><a href="#chips">Casinoet og chips</a></li>
                        <li><a href="#games">Spil og fair play</a></li>
                        <li><a href="#moderation">Når reglerne bliver brudt</a></li>
                        <li><a href="#leaving">At forlade HB Hub</a></li>
                        <li><a href="#asis">Siden leveres, som den er</a></li>
                        <li><a href="#changes">Ændringer i vilkårene</a></li>
                        <li><a href="#law">Lovgivning og kontakt</a></li>
                    </ol>
                </nav>

                <div class="legal-body">
                    <section id="about" aria-labelledby="terms-about">
                        <h2 id="terms-about">1. Om disse vilkår</h2>
                        <p>
                            HB Hub (hbhub.noahgajnielsen.dk) er lavet og drevet af Noah Gaj Nedergaard Nielsen i Danmark. Det
                            er ikke en virksomhed, og det er ikke drevet af skolen. Disse vilkår er en aftale mellem dig og den
                            person, der driver HB Hub.
                        </p>
                        <p>
                            Hvordan dine data bliver behandlet, står for sig selv i <a href="/legal/privacy">privatlivspolitikken</a>.
                        </p>
                    </section>

                    <section id="account" aria-labelledby="terms-account">
                        <h2 id="terms-account">2. Din konto</h2>
                        <ul>
                            <li><strong>Én konto per person.</strong> Lav ikke ekstra konti, fx for at få flere daglige chips eller for at komme uden om en udelukkelse. Admins kan se, hvilke konti der bliver brugt i den samme browser (også efter du har ryddet cookies eller i et privat vindue, ud fra browserens fingeraftryk, se privatlivspolitikken), og kan behandle dem som én person (se afsnit 7). Det gør casinoet også: når én af en persons konti har hentet de daglige chips, får de andre kun 20 % af deres den dag.</li>
                            <li><strong>Vær dig selv.</strong> Brug et navn, som folk fra skolen kan kende dig på, og lad være med at udgive dig for at være en anden.</li>
                            <li><strong>Din klasse.</strong> Når du opretter dig, skriver du, hvilken årgang på HTX du går på (1., 2. eller 3.) og din studieretning, eller at du ikke går på HTX. Vælg din rigtige klasse. Din årgang stiger af sig selv hvert år den 1. juli, og efter 3. år bliver du vist som student, med din studieretning. På HTX kommer du med i din klasses gruppechat med det samme, uden en beskedanmodning. Du kan gøre den lydløs eller skjule den, men ikke forlade den; skifter du klasse under dine <a href="/settings">indstillinger</a>, flytter du over i den anden klasses chat.</li>
                            <li>Navne, bioer, statusser og kælenavne i chats kan kun bruge bogstaver fra det latinske alfabet (som a-z, æ, ø og å), tal, tegnsætning og emoji. Andre alfabeter, som arabisk, kinesisk eller kyrillisk, kan ikke bruges i dem. En bio, status eller et kælenavn må heller ikke have et link til en af de blokerede sider (se afsnit 3), og et navn må ikke være en e-mailadresse. Et nyt brugernavn skal være på mindst 3 tegn.</li>
                            <li><strong>Hold det for dig selv.</strong> Del ikke din adgangskode, og lad ikke andre bruge din konto. Du er ansvarlig for det, der sker på den.</li>
                            <li>Det er en god idé at slå totrinslogin til under dine <a href="/settings">indstillinger</a>. Tror du, at en anden er kommet ind på din konto, så skift din adgangskode og sig det til en admin.</li>
                            <li><strong>Login med Discord.</strong> Du kan oprette dig med din egen Discord-konto, eller koble den til senere, for at logge ind med den. Opretter du dig med Discord, bliver dit navn, din bio og dit billede kun udfyldt fra Discord til at starte med: de skal stadig følge disse vilkår og adfærdskodekset, ligesom alt andet du lægger på din profil. Kobl kun en Discord-konto til, som er din egen: den, der kan komme ind på den, kan også komme ind på din HB Hub-konto (bortset fra totrinskoden, hvis den er slået til). Fjern koblingen under dine indstillinger, hvis du mister den. En tilkoblet kontos Discord-brugernavn bliver vist på din profil (og holdt opdateret med Discord hver nat), medmindre du slår det fra under dine indstillinger.</li>
                        </ul>
                    </section>

                    <section id="behave" aria-labelledby="terms-behave">
                        <h2 id="terms-behave">3. Sådan opfører du dig</h2>
                        <p>Behandl folk på HB Hub, som du ville gøre på skolen. Det betyder ingen:</p>
                        <ul>
                            <li>mobning, chikane, trusler eller had mod nogen, fx på grund af deres race, religion, køn, seksualitet eller handicap,</li>
                            <li>seksuelt eller voldeligt indhold, eller andet, du ikke ville vise i et klasselokale (GIFs og billeder tæller også),</li>
                            <li>deling af andres private oplysninger, billeder eller beskeder uden deres tilladelse,</li>
                            <li>spam, svindel eller links til skadelige sider (links til IP-loggere, linkforkortere, der kan skjule, hvor et link fører hen, kryptosvindel- og phishingsider, og andre farlige sider),</li>
                            <li>noget ulovligt, eller at opfordre andre til at gøre noget ulovligt eller farligt,</li>
                            <li>forsøg på at bryde ind på andres konti, overbelaste siden eller komme uden om dens sikkerhed.</li>
                        </ul>
                        <p>
                            Hvis nogen bryder disse regler eller får dig til at føle dig utryg, så sig det til en admin eller skriv til
                            <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a>.
                        </p>
                    </section>

                    <section id="content" aria-labelledby="terms-content">
                        <h2 id="terms-content">4. Det, du lægger op</h2>
                        <p>
                            Det, du skriver og uploader (beskeder, reaktioner, vedhæftede filer, profilbilleder og bannere,
                            gruppeikoner og baggrunde), er stadig dit. Ved at lægge det op giver du HB Hub lov til at gemme det og
                            vise det til dem, det er tiltænkt, hvilket er nødvendigt, for at siden kan virke. Det gælder også, at
                            billeder du vedhæfter, bliver lavet om til JPEG XL (uden at miste detaljer, bortset fra JPEG-fotos, der
                            bliver gjort mindre på en måde, der ser ens ud), at et bannerbillede bliver gjort mindre og lavet om til
                            JPEG XL, at videoer bliver lavet om til WebM (VP9, gjort mindre, når de er større end 1080p, på en måde
                            der ligner originalen), og at andre filer bliver zippet, så de fylder mindre og kan afspilles i chatten.
                        </p>
                        <ul>
                            <li>Upload kun billeder og filer, du har lov til at dele. Læg ikke billeder af andre op uden at spørge dem.</li>
                            <li>
                                Vedhæftede filer bliver ikke liggende for evigt. Alle har 10 billetter (side-admins har 20), og
                                hver påbegyndt 5 MB af en vedhæftet fil tager én. En besked kan have op til 10 vedhæftede filer, 50 MB
                                tilsammen, og hver af dem tager sine egne billetter. Side-admins kan give en konto flere billetter
                                eller tage nogle væk (fx hvis vedhæftede filer bliver misbrugt). En fil bliver slettet efter 90 dage
                                (3 dage, når den tager mere end 5 billetter), og så kan billetterne bruges igen. HB Hub er ikke en
                                backup, så gem din egen kopi af alt, der betyder noget. Når serverens plads til vedhæftede filer er
                                fyldt, kan ingen sende nye, før nogle er slettet.
                            </li>
                            <li>
                                For at stoppe spam kan du sende op til 10 beskeder hvert 5. sekund, i alle dine chats tilsammen
                                (filer og GIFs tæller som beskeder). Alt ud over det bliver ikke sendt.
                            </li>
                            <li>Du er ansvarlig for det, du lægger op. Admins kan fjerne alt, der bryder disse vilkår.</li>
                            <li>
                                Beskeder er ikke end-to-end-krypterede, og admins kan læse dem, når de undersøger en anmeldelse
                                (se <a href="/legal/privacy#see">privatlivspolitikken</a>). Send ikke noget, du ikke ville have, at en admin så.
                            </li>
                            <li>
                                Feedback, du sender med Feedback-knappen, går kun til side-admins. De kan bruge dine idéer til at
                                ændre siden uden at skylde dig noget for dem. Brug den ikke til at spamme, chikanere eller fornærme
                                nogen; de samme regler gælder som i chatten.
                            </li>
                        </ul>
                    </section>

                    <section id="chips" aria-labelledby="terms-chips">
                        <h2 id="terms-chips">5. Casinoet og chips</h2>
                        <p>
                            Casinoet på HB Hub er et spil. Du får <?= termsNumber(HBHUB_CASINO_START_CHIPS) ?> chips, når du
                            starter, og <?= termsNumber(HBHUB_CASINO_DAILY_CALENDAR[0]) ?> til
                            <?= termsNumber(max(HBHUB_CASINO_DAILY_CALENDAR)) ?> mere hver dag gratis. Hvor mange afhænger af,
                            hvor mange dage i træk du henter dem: de følger en kalender på <?= HBHUB_CASINO_DAILY_DAYS ?> dage på
                            casinoets forside, og springer du en dag over, starter den forfra på dag 1.
                        </p>
                        <ul>
                            <li><strong>Chips er ikke penge.</strong> De kan ikke byttes tilbage til rigtige penge, gavekort eller noget andet, og intet, du vinder i casinoet, kan udbetales.</li>
                            <li>Sælg, køb eller sats ikke chips, konti eller casinoresultater for rigtige penge eller rigtige ting med hinanden, hverken på HB Hub eller andre steder.</li>
                            <li>Side-admins kan give en konto chips eller tage dem væk. Det er sådan, købte chips bliver tilføjet, og sådan saldoer bliver rettet eller nulstillet, fx efter en fejl, eller hvis chips er fået ved snyd.</li>
                        </ul>
                        <h3>Legechips i de gratis spil</h3>
                        <p>
                            Blackjack, Baccarat, Roulette og Slots kan også prøves uden en konto på <a href="/play">/play</a>,
                            med legechips. Legechips er endnu mindre end chips: de er heller ikke penge, de holder kun, til du
                            lukker eller genindlæser siden, du får en ny bunke, når du løber tør, og de bliver aldrig til chips på
                            en konto, heller ikke hvis du opretter dig eller logger ind.
                        </p>
                        <h3>At købe ekstra chips</h3>
                        <p>
                            Chips er normalt gratis. Vil du have flere, kan du købe dem af ejeren af HB Hub til
                            <?= termsNumber(HBHUB_CASINO_CHIPS_PER_KRONE) ?> chips for 1 kr., som beskrevet på siden
                            <a href="/casino/buy-chips">Buy chips</a>. Det er den eneste måde at købe chips på.
                        </p>
                        <ul>
                            <li>Købte chips er de samme som alle andre chips. De kan tabes i spillene, og alt ovenfor gælder også for dem.</li>
                            <li>
                                <strong>Ingen refusion, uanset grund.</strong> Bliver du udelukket, sletter du din konto, blokerer
                                du casinoet under dine indstillinger, eller blokerer en admin det for dig, får du ikke dine penge
                                tilbage. Det samme gælder for købte chips, der bliver tabt, rettet eller nulstillet.
                            </li>
                        </ul>
                        <p class="legal-note">
                            Vil du helst ikke se casinoet eller spillene, især i skoletiden, kan du blokere dem for dig selv under
                            dine <a href="/settings">indstillinger</a>. Hvis spil af nogen art, også med rigtige penge, begynder at
                            føles som et problem, kan du få gratis og anonym hjælp hos
                            <a href="https://stopspillet.dk" target="_blank" rel="noopener noreferrer">StopSpillet</a>.
                        </p>
                    </section>

                    <section id="games" aria-labelledby="terms-games">
                        <h2 id="terms-games">6. Spil og fair play</h2>
                        <ul>
                            <li>Spil fair. Ingen snyd, ingen scripts eller bots, og intet samarbejde for at ødelægge et spil for andre.</li>
                            <li>
                                Har du fundet en fejl, fx en der giver dig gratis chips? Rapportér den med Feedback-knappen i
                                sidebjælken eller til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a> i stedet
                                for at bruge den. At bruge den med vilje tæller som snyd.
                            </li>
                            <li>Spil har tidsgrænser. Går du, eller laver du ikke dit træk i tide, fortsætter spillet uden dig, og det kan koste dig spillet.</li>
                            <li>At forlade et spil skak, Matador eller UNO, mens det bliver spillet (eller lukke det, til du bliver regnet som væk), tæller som et nederlag på dets rangliste, medmindre værten har sat spillet på pause (eller, i UNO, du allerede er løbet tør for kort, mens de andre spiller videre). I Matador tæller det stadig, hvis du kommer tilbage.</li>
                            <li>
                                Wordle har ét engelsk og ét dansk ord om dagen, og du får ét forsøg på hvert. Et ord, du starter på,
                                men ikke gætter inden midnat (dansk tid), tæller som misset og afslutter din stime. Afslør ikke dagens
                                ord for andre, fx i en gruppechat, før dagen er omme. Tilfældige ord, valgt kun til dig, kan spilles
                                så mange gange, du vil, når du har spillet dagens ord på det sprog, og tæller ikke med i statistik,
                                stimer eller ranglisten.
                            </li>
                            <li>
                                Minesweeper-tider på ranglisten bliver taget af HB Hubs server. Et spil, du forlader eller starter
                                forfra, tæller som spillet, men ikke vundet. Ingen bots eller scripts, der løser brættet for dig: en
                                sejr, der er hurtigere end et menneske kan klikke (mere end 10 af brættets nødvendige klik i
                                sekundet), tæller ikke og vises kun som spillet. Det samme gælder et spil, hvor et klik på brættet
                                kommer fra kode (som browserens konsol) i stedet for fra dig: det bliver stoppet dér.
                            </li>
                            <li>
                                Sudoku-tider på ranglisten bliver taget af HB Hubs server, uden den tid spillet var sat på pause, eller
                                du var væk fra siden. Kun opgaver løst uden et hint tæller, og at starte et nyt spil afslutter det, du
                                var i gang med. Ingen løsere, bots eller scripts: en opgave, der er udfyldt hurtigere, end et menneske
                                kan (mindre end et sekund for hvert tomt felt), tæller ikke.
                            </li>
                            <li>
                                Ore Mining Inc. gemmer på HB Hubs server og tjekker dér, hvad du gør, så kun ting, der virkelig
                                kunne være sket, tæller. Klik tæller op til 20 i sekundet, og et spil kører kun i én fane ad gangen.
                                Ingen autoklikkere, makroer eller scripts: klik, der ligner, at de kommer fra en, miner kun 20 % af det,
                                de ellers ville. Admins kan nulstille et gemt spil, der er snydt i, eller fjerne det fra ranglisten.
                                Hvis spillet går ned eller bliver lukket, før det er gemt, bliver det, du gjorde lige før, holdt i din
                                browser og gemt (og tjekket på samme måde), næste gang du åbner spillet i den browser, men det er ikke
                                et løfte: fremskridt, der aldrig bliver gemt, kan gå tabt.
                            </li>
                            <li>
                                Ore Mining Inc.s Star Shards bliver kun fanget i spillet, mens det er åbent, og kun brugt i dets
                                Shard Shop. Ligesom chips er de ikke penge: de kan ikke købes, sælges, byttes eller gives til en anden,
                                og hvad der er i butikken (dens ting og priser), kan ændre sig, efterhånden som spillet bliver
                                balanceret. Et script eller en makro, der fanger dem for dig, tæller som snyd, ligesom en autoklikker.
                            </li>
                            <li>
                                Ore Mining Inc.s Project Starbound bliver bygget af alle sammen, det ene byggeri efter det andet
                                (Cosmic Engine, så Starforge, så op til 98 mere). Starbound Parts, du giver til dem (for malm, Strange
                                Matter, Engine Shifts eller færdige Anomalies), kan ikke tages tilbage, flytter ikke med til næste
                                byggeri, og dit navn og hvor mange du gav, bliver vist for alle med en konto. Deres fordele gælder
                                for alle spillere og bliver, når næste byggeri starter. Admins kan ændre, hvor mange dele et trin
                                skal bruge, og trinene, fordelene og Anomalies (deres regler, mål, dele og Afterglow) kan ændre sig,
                                efterhånden som spillet bliver balanceret, også for en Anomaly, du er halvvejs igennem. Dele givet med
                                et gemt spil, der er snydt i, kan blive fjernet.
                            </li>
                            <li>
                                Ore Mining Inc.s Anomalies er udfordringer inde i dit spil. Mens du er i en, miner dit eget spil, som
                                om du var væk, og forlader du en, før den er færdig, mister du det forsøg. Hvor en Anomaly slår
                                Autopilot og Auto Crunch fra, tæller det som snyd at bruge et script eller noget andet til at gøre
                                deres arbejde. Det, du gør i en Anomaly, tæller ikke med i din statistik eller på ranglisten, men dine
                                bedste tider bliver vist på ranglistens Anomaly-faner, og bedste tider eller titler, medaljer eller
                                låse, der er klaret ved snyd, kan blive fjernet. Hvert byggeris sidste trin (Ignition, First Light,
                                ...) skal fyldes i tide, ellers mister det en del af det, der er i det, og ingen kan kræve det, der
                                går tabt på den måde, tilbage.
                            </li>
                            <li>
                                De gratis spil på <a href="/play">/play</a> kan spilles uden en konto, og disse vilkår gælder også
                                dér. Uden en konto bliver dine spil af Wordle, Ore Mining Inc. og Sudoku kun gemt i din browser, så
                                rydder du sidens data eller bruger en anden browser, starter de forfra, og et Minesweeper-bræt holder
                                kun, til du lukker eller genindlæser siden (og dets sejre kommer ikke på ranglisten; det gør gæsters
                                Sudoku-tider heller ikke, som bliver i din browser). Logger du ind eller opretter dig fra et af dem,
                                kommer dagens spil af Wordle (ikke tilfældige ord) og dit spil af Ore Mining Inc. med dig, men kun
                                over på en konto, der ikke har dem endnu: din kontos egne spil bliver aldrig overskrevet. I en
                                browser, der har været logget ind på HB Hub før, sender de gratis spil dig i stedet til login og det
                                normale spil. Brug ikke scripts eller bots til at oversvømme de gratis spil med forespørgsler.
                            </li>
                        </ul>
                    </section>

                    <section id="moderation" aria-labelledby="terms-moderation">
                        <h2 id="terms-moderation">7. Når reglerne bliver brudt</h2>
                        <p>Afhængigt af hvor alvorligt det er, kan admins:</p>
                        <ul>
                            <li>slette beskeder, vedhæftede filer, billeder, bannerbilleder eller grupper, og bandlyse et profilbillede eller bannerbillede, de sletter, så det ikke kan bruges igen,</li>
                            <li>løsne beskeder, der er fastgjort i en chat,</li>
                            <li>ændre eller fjerne et navn, en bio eller en status,</li>
                            <li>nulstille eller tage chips væk, eller fjerne spilresultater fra ranglisterne,</li>
                            <li>tage billetter til vedhæftede filer væk, så færre (eller ingen) nye vedhæftede filer kan sendes,</li>
                            <li>blokere Casino og Spil for kontoen hele tiden. Modsat en blokering, du selv har sat, kan den ikke slås fra under dine indstillinger; kun en admin kan ophæve den,</li>
                            <li>
                                spøgelsesudelukke kontoen, i et stykke tid eller for altid, uden at fortælle personen det. Chatten
                                virker som normalt for dem, men det, de gør i den (beskeder, vedhæftede filer, rettelser, reaktioner,
                                fastgørelser, kælenavne og de chats, de starter eller tilføjer folk til), bliver kun vist for dem selv
                                og side-admins. Ingen andre ser det eller får en notifikation eller beskedanmodning fra det. En ny
                                konto (op til 3 dage gammel), der er lavet eller brugt i den samme browser, bliver også
                                spøgelsesudelukket,
                            </li>
                            <li>udelukke kontoen, i et stykke tid eller for altid. En udelukkelse dækker også den browser, den blev brugt i, og som regel kontoens profilbillede, og kan dække dens bannerbillede.</li>
                            <li>gøre alt det ovenstående ved alle en persons konti på én gang. Konti, der har været brugt i den samme browser (ud fra dens cookie eller dens fingeraftryk), bliver regnet som den samme person, og admins tjekker først, at det ikke bare er en delt eller ens computer.</li>
                        </ul>
                        <p>
                            Det er også imod reglerne at bruge et bandlyst billede: en konto, der uploader et som sit profilbillede
                            eller banner, eller opretter sig med et, bliver udelukket med det samme, uden at en admin skal gøre
                            noget. Det gælder også redigerede kopier, der ser næsten ens ud, som en, der er skaleret eller beskåret,
                            og den konto, billedet blev fjernet fra. Bandlyste billeder er udelukkede kontis profilbilleder og
                            bannerbilleder, profilbilleder og bannerbilleder, som admins har fjernet og bandlyst, og andre billeder,
                            admins har tilføjet. Admins kan også ændre eller fjerne en status, der bryder reglerne.
                        </p>
                        <p>
                            Admins afgør, hvad der bryder reglerne, også for ting, disse vilkår ikke nævner.
                            <strong>
                                Admins kan også udelukke, spøgelsesudelukke eller slette enhver konto, eller gøre alt andet på denne
                                liste ved den, når som helst og af enhver grund, eller helt uden grund, uden varsel og uden at
                                forklare personen hvorfor.
                            </strong>
                            Mener du, at en afgørelse var forkert, så skriv til <a href="mailto:admin@noahgajnielsen.dk">admin@noahgajnielsen.dk</a> og forklar hvorfor.
                        </p>
                    </section>

                    <section id="leaving" aria-labelledby="terms-leaving">
                        <h2 id="terms-leaving">8. At forlade HB Hub</h2>
                        <p>
                            Du kan stoppe med at bruge HB Hub, når du vil, og slette din konto på
                            <a href="/settings">indstillingssiden</a>. <a href="/legal/privacy#keep">Privatlivspolitikken</a>
                            forklarer, hvad der så bliver fjernet, og hvordan du får det hele slettet.
                        </p>
                        <p>
                            HB Hub kan også lukke en konto, der bryder disse vilkår, eller lukke hele siden. Hvis siden lukker, vil
                            der så vidt muligt være en besked om det på den i forvejen.
                        </p>
                    </section>

                    <section id="asis" aria-labelledby="terms-asis">
                        <h2 id="terms-asis">9. Siden leveres, som den er</h2>
                        <p>
                            HB Hub er et gratis sideprojekt, drevet af én elev i fritiden. Der bliver stadig arbejdet på det, så
                            ting kan ændre sig, gå i stykker, være nede for vedligeholdelse eller i værste fald gå tabt, uden varsel.
                            Gem din egen kopi af alt, der er vigtigt for dig.
                        </p>
                        <p>
                            Siden tilbydes, som den er, uden noget løfte om, at den altid virker eller er fri for fejl. I det omfang
                            dansk lov tillader det, er HB Hub ikke ansvarlig for tab, der skyldes, at du bruger den, at den er nede,
                            eller hvad andre brugere lægger op.
                        </p>
                    </section>

                    <section id="changes" aria-labelledby="terms-changes">
                        <h2 id="terms-changes">10. Ændringer i vilkårene</h2>
                        <p>
                            Efterhånden som HB Hub får nye funktioner, kan disse vilkår ændre sig. Datoen øverst viser, hvornår de
                            sidst gjorde det. Når de ændrer sig, fortæller en boks på siden dig det, næste gang du kommer forbi, og
                            ændres der noget stort, bliver det også meldt ud på siden. Bliver du ved med at bruge HB Hub efter en
                            ændring, accepterer du de nye vilkår.
                        </p>
                    </section>

                    <section id="law" aria-labelledby="terms-law">
                        <h2 id="terms-law">11. Lovgivning og kontakt</h2>
                        <p>
                            Disse vilkår følger dansk lov. Intet i dem tager rettigheder fra dig, som du alligevel har efter loven.
                        </p>
                        <p>
                            Spørgsmål om vilkårene, en anmeldelse eller noget andet? Skriv til
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
