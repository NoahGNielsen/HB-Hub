<?php
// Help docs: Ore Mining Inc. - getting started, every building (straight from HBHUB_MINER_BUILDINGS in
// assets/php/games/spaceOreMiner/miner.php, so a new one or a new price shows up here by itself), the comets, Star
// Shards and the Shard Shop (from HBHUB_MINER_SHOP), the three
// prestiges, the skill tree, the Cosmic Engines (the first two builds' stages straight from HBHUB_MINER_ENGINE_STAGES
// and HBHUB_MINER_ENGINES, with their default costs, and a table of the builds after them from HBHUB_MINER_BUILDS), the Anomalies (from HBHUB_MINER_ANOMALIES and HBHUB_MINER_ANOMALY_TIERS), saving and the game's own How to
// play (rules.php, included as the game shows it).
// How much Strange Matter a Big Rip gives is still being balanced, so it's only described, never given as numbers.
// The page is in Danish like the rest of the docs, but the game is in English, so everything named in the game
// (buildings, comets, skills, Anomalies, stages, perks, locks) keeps its English name, and the texts that come from
// miner.php are shown as the game writes them.
// Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/miner.php';

/**
 * A big number the way people say it in Danish: 12.000, 1,4 millioner, 75 milliarder. Danish uses the long scale, so a
 * milliard is what the game (in English) calls a billion, and a billion is its trillion. Above that it's still words,
 * up to the kvintilliards (10^33), which is further than any price in the store.
 * The list is keyed by the power of ten, not the number itself: PHP turns float keys into ints, and 1e21 and up
 * don't fit in one (that broke the page with "0 octillion").
 */
function docsMinerNumber(float $number): string
{
    $names = [33 => ['kvintilliard', 'kvintilliarder'], 30 => ['kvintillion', 'kvintillioner'],
        27 => ['kvadrilliard', 'kvadrilliarder'], 24 => ['kvadrillion', 'kvadrillioner'],
        21 => ['trilliard', 'trilliarder'], 18 => ['trillion', 'trillioner'], 15 => ['billiard', 'billiarder'],
        12 => ['billion', 'billioner'], 9 => ['milliard', 'milliarder'], 6 => ['million', 'millioner']];
    foreach ($names as $power => [$one, $many]) {
        $size = 10.0 ** $power;
        if ($number >= $size) {
            $shown = rtrim(rtrim(number_format($number / $size, 1, ',', '.'), '0'), ',');
            return $shown . ' ' . ($shown === '1' ? $one : $many);
        }
    }
    return $number < 1 ? str_replace('.', ',', (string) $number) : number_format($number, 0, ',', '.');
}

$docsPath = '/docs/games/ore-mining-inc';
$docsIntro = 'Klik på en asteroide for at få malm, ansæt maskiner, der miner for dig, og bliv ved med at vokse, fra én
    hakke til et bor, der miner multiverset. Spil det på <a href="/games/ore-mining-inc/">Ore Mining Inc.</a>-siden.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Bygninger</dt><dd><?= count(HBHUB_MINER_BUILDINGS) ?></dd></div>
                        <div><dt>Færdigheder</dt><dd><?= count(HBHUB_MINER_SKILLS) ?></dd></div>
                        <div><dt>Præstationer</dt><dd><?= count(HBHUB_MINER_ACHIEVEMENTS) ?></dd></div>
                    </dl>

                    <p class="legal-note">
                        Spillet er på engelsk, så bygninger, kometer, færdigheder og alt andet står med deres engelske navne her.
                        Store tal står på dansk: en milliard er det, spillet kalder <em>a billion</em>.
                    </p>

                    <section id="start" aria-labelledby="docs-start">
                        <h2 id="docs-start">Kom i gang</h2>
                        <ol class="docs-steps">
                            <li>Klik på asteroiden (eller tryk <kbd>Mellemrum</kbd>) for at mine malm.</li>
                            <li>Køb en Space Pickaxe Guy i butikken. Bygninger miner for dig hvert sekund, også mens du ikke klikker.</li>
                            <li>Bliv ved med at købe bygninger og opgraderinger. Hver bygning får opgraderinger, når du ejer 10, 25, 50 og flere af den, og hver af dem fordobler, hvad den miner.</li>
                            <li>Fang de Golden Comets, der flyver forbi, for et stort boost.</li>
                            <li>Når du har minet meget, så lav et Warp Jump til en ny galakse, og start forfra, stærkere.</li>
                        </ol>
                    </section>

                    <section id="buildings" aria-labelledby="docs-buildings">
                        <h2 id="docs-buildings">Bygningerne</h2>
                        <p>
                            Hver ny, du køber, koster <?= round((HBHUB_MINER_COST_GROWTH - 1) * 100) ?> % mere end den før. Det, de
                            miner, er før opgraderinger.
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Bygning</th><th scope="col">Den første koster</th><th scope="col">Malm per sekund</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (HBHUB_MINER_BUILDINGS as $docsBuilding): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($docsBuilding['name']) ?></td>
                                            <td><?= docsMinerNumber($docsBuilding['cost']) ?></td>
                                            <td><?= docsMinerNumber($docsBuilding['rate']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            En god tommelfingerregel: køb det, der giver mest malm per sekund for prisen. Tasten <kbd>B</kbd> gør
                            præcis det, når du har opgraderingen Hot Key Panel.
                        </p>
                    </section>

                    <section id="comets" aria-labelledby="docs-comets">
                        <h2 id="docs-comets">Golden Comets</h2>
                        <p>Med få minutters mellemrum flyver en komet hen over skærmen. Klik på den, før den er væk, så får du en af disse:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Komet</th><th scope="col">Hvad du får</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (HBHUB_MINER_COMETS as $docsComet): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($docsComet['name']) ?></td>
                                            <td>
                                                <?php if ($docsComet['buff'] === null): ?>
                                                    En bunke malm med det samme: <?= HBHUB_MINER_LUCKY_SECONDS[0] ?> til <?= HBHUB_MINER_LUCKY_SECONDS[1] ?> sekunders produktion
                                                <?php else: ?>
                                                    <?= $docsComet['multiplier'] ?>&times; <?= $docsComet['buff'] === 'click' ? 'hvert klik' : 'produktion' ?> i <?= $docsComet['ms'] / 1000 ?> sekunder
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p>Diamond Rush er sjælden. Forskellige kometers effekter lægges sammen: et Diamond Rush, der bliver fanget under en Supernova, ganges oven i den. Kometopgraderinger i butikken får kometer til at komme oftere, blive længere og give mere.</p>
                    </section>

                    <section id="star-shards" aria-labelledby="docs-star-shards">
                        <h2 id="docs-star-shards">Star Shards og Shard Shop</h2>
                        <p>
                            Cirka hvert <?= HBHUB_MINER_SHARD_SLOT_MS / 60000 ?>. minut flyver en glitrende Star Shard hen over skærmen
                            den modsatte vej af kometerne. Den bliver kun i <?= HBHUB_MINER_SHARD_STAY_MS / 1000 ?> sekunder, så hold
                            øje: klik på den for at beholde den. Den kommer kun, mens spillet er åbent, også i en Anomaly (den går
                            stadig til dit eget spil), og Comet Catcher lader den være til dig. Star Shards er dine for altid: Warp
                            Jumps, Big Crunches og Big Rips tager dem ikke.
                        </p>
                        <p>Brug dem i Shard Shop, en visning for sig i spillets menu:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Ting</th><th scope="col">Star Shards</th><th scope="col">Hvad du får</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (HBHUB_MINER_SHOP as $docsItem): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($docsItem['name']) ?></td>
                                            <td><?= $docsItem['cost'] ?></td>
                                            <td><?= htmlspecialchars($docsItem['kind'] === 'skin' ? 'Et asteroideskin: ' . $docsItem['text'] : ($docsItem['kind'] === 'effect' ? 'En klikeffekt: ' . $docsItem['text'] : $docsItem['text'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            Boosts virker oven i kometernes effekter og fortsætter gennem Warp Jumps; køber du en igen, lægges dens
                            tid til, op til <?= HBHUB_MINER_SHOP_BOOST_MAX_MS / 3600000 ?> timer frem. Malm er så mange timer af det,
                            du miner i sekundet lige nu. Boosts, malm og Comet Beacon er til dit eget spil, så de kan ikke købes i en
                            Anomaly. Skins og klikeffekter beholder du for altid, og du kan slå dem til og fra i butikken eller i
                            Skins-visningen, som har alle skins, og hvordan du får dem, du ikke har endnu.
                        </p>
                    </section>

                    <section id="prestige" aria-labelledby="docs-prestige">
                        <h2 id="docs-prestige">Warp Jump, Big Crunch og Big Rip</h2>
                        <p>
                            Før eller siden går væksten langsomt. Det er dér, du starter forfra, for noget, der gør næste runde meget
                            hurtigere. Der er tre måder, hver større end den forrige, og hver i sin egen visning (menuen øverst skifter
                            visning uden at stoppe spillet):
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Prestige</th><th scope="col">Hvornår</th><th scope="col">Starter forfra</th><th scope="col">Giver</th></tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Warp Jump <kbd>W</kbd></td>
                                        <td>Efter <?= docsMinerNumber(HBHUB_MINER_WARP_ORE) ?> malm i en runde</td>
                                        <td>Din malm, dine bygninger og opgraderinger</td>
                                        <td>Dark Matter: kubikroden af den malm, der er minet i dette univers, i milliarder. Hver er +<?= HBHUB_MINER_DARK_BONUS * 100 ?> % produktion og køber Dark Matter-opgraderinger</td>
                                    </tr>
                                    <tr>
                                        <td>Big Crunch <kbd>X</kbd></td>
                                        <td>Efter <?= number_format(HBHUB_MINER_CRUNCH_DARK, 0, ',', '.') ?> Dark Matter i et univers</td>
                                        <td>Alt ovenfor, også Dark Matter og dens opgraderinger</td>
                                        <td>Antimatter: kvadratroden af Dark Matter, i hundreder. Hver er +<?= HBHUB_MINER_ANTI_BONUS * 100 ?> % produktion og mere Dark Matter fra hvert Warp Jump, og køber Antimatter-opgraderinger</td>
                                    </tr>
                                    <tr>
                                        <td>Big Rip <kbd>R</kbd></td>
                                        <td>Efter <?= number_format(HBHUB_MINER_RIP_ANTI, 0, ',', '.') ?> Antimatter i et multivers</td>
                                        <td>Alt ovenfor, også Antimatter og dens opgraderinger</td>
                                        <td>Strange Matter: mere for hver ti gange så meget Antimatter, men langsomt. Hver, du nogensinde har tjent, er +<?= HBHUB_MINER_STRANGE_BONUS * 100 ?> % produktion for altid, og den køber færdigheder</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            Et Warp Jump og et Big Rip giver kun det, der er mere, end du har fået fra dem før, så hver skal slå din
                            bedste. Antimatter-visningen åbner efter dit første Warp Jump, Strange Matter-visningen efter dit første
                            Big Crunch. Project Starbound og Anomalies har deres egen bjælke under menuen, og Shard Shop og Skins en
                            anden under den: Project Starbound-visningen åbner, når du har tjent
                            <?= number_format(HBHUB_MINER_ENGINE_STRANGE, 0, ',', '.') ?> Strange Matter (før det viser den kun raketten,
                            og hvor langt den er), og du kan starte Anomalies, når du også har tjent
                            <?= number_format(HBHUB_MINER_ANOMALY_STRANGE, 0, ',', '.') ?> Strange Matter. En prik i menuen viser, når
                            der er noget at gøre i en visning.
                        </p>
                    </section>

                    <section id="skills" aria-labelledby="docs-skills">
                        <h2 id="docs-skills">Færdighedstræet</h2>
                        <p>
                            Strange Matter køber færdigheder, som du beholder for altid, gennem alle prestiges. Træet vokser fra
                            Strangelet til venstre til Theory of Everything til højre, i fem grene:
                        </p>
                        <ul>
                            <li><strong>Industry</strong>: bygninger, lavere priser, startbygninger og at beholde opgraderinger gennem Warp Jumps.</li>
                            <li><strong>Hands</strong>: klik, kritiske klik og autoklik.</li>
                            <li><strong>Core</strong>, hen over midten: store produktionsboosts.</li>
                            <li><strong>Sky</strong>: kometer.</li>
                            <li><strong>Cosmos</strong>: mere Dark Matter, Antimatter og Strange Matter, mere malm, mens du er væk, og knapperne Autopilot og Auto Crunch, som laver Warp Jump og Big Crunch for dig, når det ville gange din Dark Matter eller Antimatter med det tal, du skriver ved dem (4 til at starte med).</li>
                        </ul>
                        <p>
                            En færdighed kan læres, når alle færdigheder, der er forbundet til venstre for den, er lært, også dem i
                            grenen ved siden af. Vælg en færdighed for at se, hvad den gør, hvad den kræver, og hvad den koster.
                        </p>
                    </section>

                    <section id="engine" aria-labelledby="docs-engine">
                        <h2 id="docs-engine">Project Starbound</h2>
                        <p>
                            Project Starbound-visningen har en maskine, som alle på HB Hub bygger sammen ud af Starbound Parts, tegnet
                            som en raket på sin affyringsrampe: hvert færdigt trin lægger en del til den, den, der bliver bygget,
                            fyldes op, efterhånden som dele kommer ind, og du kan pege på en del for at se dens trin. Når du har tjent
                            <?= number_format(HBHUB_MINER_ENGINE_STRANGE, 0, ',', '.') ?> Strange Matter (indtil da viser visningen kun
                            raketten, og hvor langt den er), kan du hjælpe på tre måder:
                        </p>
                        <ul>
                            <li><strong>Malm</strong>: op til <?= HBHUB_MINER_ENGINE_ORE_PARTS ?> dele om dagen (én mere, når Starforges Air Intakes er færdige). Dagens første koster <?= docsMinerNumber(HBHUB_MINER_ENGINE_ORE_PART) ?> malm, og hver næste ti gange så meget. Fra hvert byggeris tredje sektion (trin 11) tæller en del fra malm som en halv del, og fra dets fjerde (trin 16) som en kvart, eller en halv igen, når Starforges Piston Arms er færdige.</li>
                            <li><strong>Strange Matter</strong>: <?= HBHUB_MINER_ENGINE_STRANGE_PART ?> for en del, så mange du vil. Det får dig ikke til at mine langsommere, da Strange Matter-bonussen tæller alt, du nogensinde har tjent, ikke det, du har tilbage.</li>
                            <li>
                                <strong>Starbound Shifts</strong>: <?= HBHUB_MINER_SHIFT_COUNT ?> opgaver om dagen, valgt blandt
                                <?= count(HBHUB_MINER_SHIFTS) ?> og de samme for alle (som "<?= htmlspecialchars(HBHUB_MINER_SHIFTS['comets']['text']) ?>"
                                eller "<?= htmlspecialchars(HBHUB_MINER_SHIFTS['buildings']['text']) ?>"), værd <?= HBHUB_MINER_SHIFT_PARTS ?>
                                dele hver, plus <?= HBHUB_MINER_SHIFT_BONUS ?>, når de alle er klaret. Nogle er svære (som
                                "<?= htmlspecialchars(HBHUB_MINER_SHIFTS['rip']['text']) ?>" eller
                                "<?= htmlspecialchars(HBHUB_MINER_SHIFTS['playingHard']['text']) ?>") og værd <?= HBHUB_MINER_SHIFT_PARTS_HARD ?> dele.
                            </li>
                        </ul>
                        <p>
                            Delene går ind i ét trin ad gangen. Når et trin har alle sine dele, er det færdigt, og alle, der spiller,
                            får dets fordel for altid. Nogle trin har også en lås, som skal være opfyldt, før de bliver færdige,
                            uanset hvor mange dele de har: indtil da venter delene dér (eller, for en lås, der kræver Anomalies, tager
                            byggeriet kun dele fra Anomalies). Et fyldt trin bliver færdigt, så snart dets lås er opfyldt, også selvom
                            ingen giver en del mere: det bliver tjekket igen inden for et halvt minut, mens nogen spiller. At
                            gennemføre <a href="#anomalies">Anomalies</a> giver også dele, og de er den største kilde til dem. De
                            daglige grænser og Shifts starter forfra ved midnat UTC.
                            <a href="/games/ore-mining-inc/leaderboard?board=starbound">Ranglisten</a> viser, hvem der har givet mest.
                        </p>
                        <p>
                            Det sidste trin, Ignition, har en nedtælling: det skal være fyldt inden for
                            <?= intdiv(HBHUB_MINER_ENGINE_COUNTDOWN_MS, HBHUB_MINER_DAY_MS) ?> dage efter, at trinnet før det er
                            færdigt. Er det ikke det, går <?= (1 - HBHUB_MINER_ENGINE_DRAIN) * 100 ?> % af dets dele tabt, og det får
                            <?= intdiv(HBHUB_MINER_ENGINE_COUNTDOWN_MS, HBHUB_MINER_DAY_MS) ?> dage til. Når det er fyldt, miner alt
                            <?= HBHUB_MINER_IGNITION_TIMES ?> gange så meget i <?= intdiv(HBHUB_MINER_IGNITION_MS, 3600000) ?> timer, og
                            alle, der gav dele til Ignition, får Ignition-mærket på deres profil og Ignition-asteroideskinnet.
                        </p>
                        <p>
                            Når et byggeri er færdigt, starter det næste med det samme, med nye trin og nye låse. Dets låse tæller fra
                            nul igen (en Anomaly, der er gennemført for Project Starbound før, tæller ikke), og hver Anomaly eller stak
                            giver alle sine dele igen første gang, den bliver gennemført for det nye byggeri. Alle fordele fra
                            Engines før det bliver. Byggeri #2, Starforge, har fordele, der mest er for udseendets skyld eller gør
                            spillet nemmere at spille, og kun 3 &times; 5 % mere produktion, så spillet ikke bliver ved med at blive
                            hurtigere. Dets sidste trin, First Light, virker som Ignition, med et First Light-mærke og -skin. Når
                            Starforges Alloy Bins er færdige, har Project Starbound-visningen en Starbound Hall med alle byggerier, der
                            er færdige indtil videre.
                        </p>
                        <p>
                            Der er <?= count(HBHUB_MINER_ENGINES) ?> byggerier i alt. Fra byggeri #3 og frem har hvert byggeri sit eget
                            navn, sine egne sektioner og trinnavne, men den samme plan: trin 1 tilføjer nyhedslinjer om det, hver
                            sektion slutter med et segl, seglene i dets 2. og 4. sektion får alt til at mine 1 % mere, og dets sidste
                            trin virker som Ignition (<?= HBHUB_MINER_IGNITION_TIMES ?> gange så meget i
                            <?= intdiv(HBHUB_MINER_IGNITION_MS, 3600000) ?> timer). Alle andre trin fører kun byggeriet videre. Seglene
                            har låse: 8 forskellige spillere, der giver dele, 6 spillere, der giver dele til 2. sektion, en stak på 3
                            Anomalies, og alle Tier I Anomalies gennemført for det byggeri. Hvert byggeri koster lidt mere end det
                            før. Kun hvert 10. byggeri giver et mærke, til dem, der gav dele til dets sidste trin.
                        </p>
                        <p>Dette er trinene i de første to byggerier:</p>
                        <?php foreach (array_slice(HBHUB_MINER_ENGINES, 0, 2, true) as $docsEngineNumber => $docsEngine): ?>
                            <h3>Byggeri #<?= $docsEngineNumber ?>: <?= htmlspecialchars($docsEngine['name']) ?></h3>
                            <div class="docs-table-wrap">
                                <table class="docs-table">
                                    <thead>
                                        <tr><th scope="col">Trin</th><th scope="col">Dele</th><th scope="col">Fordel</th><th scope="col">Lås</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (hbHubMinerEngineStages($docsEngineNumber) as $index => $stage): ?>
                                            <?php if ($index % 5 === 0): ?>
                                                <tr><th scope="rowgroup" colspan="4"><?= htmlspecialchars($stage['section'] . '. ' . $docsEngine['sections'][$stage['section']]) ?></th></tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td><?= $index + 1 ?>. <?= htmlspecialchars($stage['name']) ?></td>
                                                <td><?= number_format($stage['cost'], 0, ',', '.') ?></td>
                                                <td><?= htmlspecialchars($stage['perk']['text']) ?></td>
                                                <td><?= $stage['lock'] !== null ? htmlspecialchars($stage['lock']['text']) : '' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                        <h3>Byggeri #3 til #<?= array_key_last(HBHUB_MINER_ENGINES) ?></h3>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Byggeri</th><th scope="col">Sektioner</th><th scope="col">Sidste trin</th><th scope="col">Dele</th><th scope="col">Mærke</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (HBHUB_MINER_BUILDS as $docsEngineNumber => $docsEngine): ?>
                                        <tr>
                                            <td>#<?= $docsEngineNumber ?>. <?= htmlspecialchars($docsEngine['name']) ?></td>
                                            <td><?= htmlspecialchars(implode(', ', $docsEngine['sections'])) ?></td>
                                            <td><?= htmlspecialchars($docsEngine['finale']) ?></td>
                                            <td><?= number_format(array_sum(array_column(hbHubMinerEngineStages($docsEngineNumber), 'cost')), 0, ',', '.') ?></td>
                                            <td><?= isset($docsEngine['crew']) ? htmlspecialchars($docsEngine['crew']['icon'] . ' ' . $docsEngine['crew']['name']) : '' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p>De dele, hvert trin skal bruge, kan stadig ændre sig, efterhånden som Project Starbound bliver justeret.</p>
                    </section>

                    <section id="anomalies" aria-labelledby="docs-anomalies">
                        <h2 id="docs-anomalies">Anomalies</h2>
                        <p>
                            Anomalies-visningen har udfordringer: universer for sig, hver med én regel ændret. I Tier I og II går du
                            ind med dit færdighedstræ, din Strange Matter-bonus og de tællere, der får dig til at mine hurtigere (Warp
                            Jumps, Big Crunches, fangede kometer), men uden Dark Matter, Antimatter eller malm. Antimatter Echo og Dark
                            Echo beholder deres opgraderinger, som ved et Big Rip. Du gennemfører en ved at tjene dens mål af
                            Antimatter i den. Tier III starter dig i stedet fra nul: ingen færdigheder, ingen Strange Matter-bonus,
                            ingen opgraderinger og ingen af de tællere, og hver har sit eget mål. Cosmic Engines fordele virker i alle
                            Anomalies.
                        </p>
                        <p>
                            <strong>Stakke:</strong> alle Tier I- og Tier II-Anomalies kan spilles sammen, med alle deres regler på én
                            gang, fra stakbyggeren i Anomalies-visningen. En staks mål er det laveste af dens Anomalies' mål, og den er
                            værd deres dele lagt sammen, gange <?= HBHUB_MINER_ANOMALY_STACK ?> for hver Anomaly efter den første. En
                            stak har sin egen bedste tid. Unstable Vacuum kan ikke stables (dens Warp Jumps slipper op før de fleste
                            stakkes mål), og et par par kan ikke vælges sammen, fordi de ikke kan klares:
                            <?= htmlspecialchars(implode(', ', array_map(static fn (array $pair): string => HBHUB_MINER_ANOMALIES[$pair[0]]['name'] . ' med ' . HBHUB_MINER_ANOMALIES[$pair[1]]['name'], HBHUB_MINER_ANOMALY_CLASHES))) ?>.
                        </p>
                        <p>
                            <strong>Den ugentlige Anomaly</strong> er en stak på <?= implode(' eller ', HBHUB_MINER_WEEKLY_SIZES) ?>,
                            valgt for alle hver uge (ugerne starter mandag kl. 00:00 UTC), aldrig en, der ikke kan klares. At
                            gennemføre den i den uge er værd <?= HBHUB_MINER_ANOMALY_WEEKLY ?> gange så mange dele, og
                            <a href="/games/ore-mining-inc/leaderboard?board=weekly">ranglisten</a> viser de hurtigste tider hver uge.
                        </p>
                        <p>
                            <strong>Belønninger til dig:</strong> et mærke og en bedste tid for hver Anomaly, præstationer,
                            asteroideskins (en farve for hver Tier II-Anomaly, et animeret for hver Tier III, og flere fra Cosmic
                            Engine), valgt i Skins-visningen, og ranglistetitlerne "Singularity" (Naked Singularity) og "Gauntlet
                            Runner" (The Gauntlet). De kommer før byggeriernes titler, "Forgemaster" og "Engineer".
                        </p>
                        <p>
                            Mens du er i en, siger et banner det på alle visninger, og dit eget spil venter og miner, som om du var
                            væk. Du kan gå, når du vil. Med <strong>Save and leave</strong> beholder du forsøget og fortsætter fra
                            Anomalies-visningen senere (op til <?= HBHUB_MINER_ANOMALY_SAVED_MAX ?> gemt på én gang, men ikke Speed of
                            Light). Et gemt forsøgs ur bliver ved med at gå, og det miner, som om du var væk, til du fortsætter. Med
                            almindelig <strong>Leave</strong> er det forsøg tabt. Færdigheder, Big Rips, donationer og Starbound Shifts
                            virker kun i dit eget spil. Det, du gør i en Anomaly, tæller ikke med i din statistik eller på ranglisten,
                            men at gennemføre Anomalies har sine egne præstationer.
                        </p>
                        <p>
                            At gennemføre en giver det Project Starbound, der bliver bygget, dens dele første gang (og igen første
                            gang for hvert nyt byggeri), og når du slår din bedste tid, og ellers <?= HBHUB_MINER_ANOMALY_REPEAT * 100 ?> %
                            af dem (rundet ned). Disse dele går ind, også mens Project Starbound er fyldt og venter på en lås, da nogle
                            låse kræver Anomalies. Du får også et mærke for hver af dem i Anomalies-visningen, med din bedste tid.
                        </p>
                        <p>
                            <strong>Afterglow:</strong> hver gang du gennemfører en (ikke kun første gang), miner dit eget spil
                            <?= HBHUB_MINER_AFTERGLOW_TIMES ?> gange så meget et stykke tid bagefter, for at gøre op for den tid, det
                            brugte på at mine, som om du var væk: en time for en Tier I-Anomaly, 3 for Tier II, 12 for Tier III og 24 for
                            The Gauntlet. En staks bliver lagt sammen, og den ugentlige Anomalys varer <?= HBHUB_MINER_ANOMALY_WEEKLY ?>
                            gange så længe i den uge. Gennemfører du en til, mens en kører, bliver den lagt til, op til
                            <?= HBHUB_MINER_AFTERGLOW_MAX_MS / 3600000 ?> timer frem. Den bliver vist under din malm med kometeffekterne
                            og virker sammen med dem og med Ignition.
                        </p>
                        <?php foreach (HBHUB_MINER_ANOMALY_TIERS as $docsTier => $docsTierData): ?>
                            <h3>Tier <?= str_repeat('I', $docsTier) ?>: <?= htmlspecialchars($docsTierData['name']) ?></h3>
                            <p>
                                <?= $docsTierData['unlock'] === 'strange' ? 'Åbner, når du har tjent ' . number_format(HBHUB_MINER_ANOMALY_STRANGE, 0, ',', '.') . ' Strange Matter.' : 'Åbner, når du har lært Theory of Everything.' ?>
                                <?= $docsTierData['fresh'] ? 'Fra nul, og de kan ikke stables.' : $docsTierData['parts'] . ' dele hver.' ?><?= $docsTierData['noAuto'] ? ' Autopilot og Auto Crunch er slået fra i disse.' : '' ?>
                            </p>
                            <div class="docs-table-wrap">
                                <table class="docs-table">
                                    <thead>
                                        <tr><th scope="col">Anomaly</th><th scope="col">Regel</th><th scope="col">Mål</th><?= $docsTierData['fresh'] ? '<th scope="col">Dele</th>' : '' ?></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (HBHUB_MINER_ANOMALIES as $docsAnomaly): ?>
                                            <?php if ($docsAnomaly['tier'] !== $docsTier) continue; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($docsAnomaly['name']) ?></td>
                                                <td><?= htmlspecialchars($docsAnomaly['text']) ?></td>
                                                <td><?= docsMinerNumber($docsAnomaly['goal']) ?> <?= ['runOre' => 'malm i én runde', 'darkEarned' => 'Dark Matter'][$docsAnomaly['stat'] ?? ''] ?? 'Antimatter' ?></td>
                                                <?php if ($docsTierData['fresh']): ?>
                                                    <td><?= number_format($docsAnomaly['parts'] ?? $docsTierData['parts'], 0, ',', '.') ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                        <p>Målene kan stadig ændre sig, efterhånden som Anomalies bliver balanceret.</p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Sådan spiller du, fra spillet</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/rules.php'; ?>
                    </section>

                    <section id="saving" aria-labelledby="docs-saving">
                        <h2 id="docs-saving">At gemme, og at spille fair</h2>
                        <p>
                            Dit spil bliver gemt på HB Hubs server hvert <?= HBHUB_MINER_SAVE_SECONDS ?>. sekund, og når du går, så du
                            kan fortsætte fra en hvilken som helst enhed. Mens du er væk, bliver dine bygninger ved med at mine med
                            <?= HBHUB_MINER_OFFLINE_RATE * 100 ?> % af farten, eller mere, når Cosmic Engine har trinene til det.
                        </p>
                        <p>
                            Går spillet ned eller fryser, før det er gemt, bliver det, du gjorde lige før, holdt i din browser og gemt,
                            næste gang du åbner spillet dér. Efter et nedbrud spørger spillet, om det må sende en nedbrudsrapport, så
                            problemet kan findes og rettes. Den bliver kun sendt, hvis du siger ja, og du kan se, hvad der står i den,
                            først. Nogle browsere sender også selv en kort besked, hvis spillets fane løber tør for hukommelse eller
                            holder op med at svare, og den siger ikke, hvem du er (mere i
                            <a href="/legal/privacy#what">privatlivspolitikken</a>).
                        </p>
                        <p>
                            Serveren spiller hvert klik og køb igen for at tjekke det, så der ikke kan snydes på
                            <a href="/games/ore-mining-inc/leaderboard">ranglisten</a> fra browseren. Klik tæller kun op til
                            <?= HBHUB_MINER_CLICKS_PER_SECOND ?> i sekundet, og klik i et helt jævnt tempo (som en autoklikkers, eller en
                            tast, der bliver holdt nede) miner kun <?= HBHUB_MINER_AUTO_VALUE * 100 ?> %, indtil tempoet ændrer sig.
                            Autoklikkere og scripts er alligevel ikke tilladt.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
