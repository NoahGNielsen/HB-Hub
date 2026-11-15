<?php
// How to play Ore Mining Inc., shown in the game's How to play popup (games/spaceOreMiner/index.php) and on its docs
// page (docs/games/ore-mining-inc.php). In Danish, like the docs (a public page), also in the game, but everything
// named in the game (buildings, upgrades, views, Anomalies) keeps the English name the game shows it by. The numbers
// are miner.php's, so it has to be included first. miner.php and spaceOreMiner.js play by these rules, so change them
// together.

/**
 * A big number the Danish way (long scale: a milliard is the game's billion, a billion its trillion), like
 * "10 billioner". Only for the few big numbers below.
 */
$rulesNumber = static function (float $number): string {
    foreach ([[1e12, 'billion', 'billioner'], [1e9, 'milliard', 'milliarder'], [1e6, 'million', 'millioner']] as [$size, $one, $many]) {
        if ($number >= $size) {
            $shown = rtrim(rtrim(number_format($number / $size, 2, ',', '.'), '0'), ',');
            return $shown . ' ' . ($shown === '1' ? $one : $many);
        }
    }
    return number_format($number, 0, ',', '.');
};
?>
<ul class="casino-rule-list" lang="da">
    <li>Klik på asteroiden for at mine malm. Brug den i butikken på bygninger, som miner for dig hvert sekund, og på opgraderinger, der gør klik og bygninger bedre.</li>
    <li>
        Hver bygning koster <?= round((HBHUB_MINER_COST_GROWTH - 1) * 100) ?> % mere end den før
        (Space Pickaxe Guys kun <?= round((HBHUB_MINER_BUILDINGS['pickaxe']['growth'] - 1) * 100) ?> %). Hver af dem
        får <?= count(HBHUB_MINER_BUILDING_UPGRADE_AT) ?> opgraderinger, ved
        <?= implode(', ', array_slice(HBHUB_MINER_BUILDING_UPGRADE_AT, 0, -1)) ?> og
        <?= HBHUB_MINER_BUILDING_UPGRADE_AT[count(HBHUB_MINER_BUILDING_UPGRADE_AT) - 1] ?> ejede, som fordobler, hvad den
        miner, og Space Pickaxe Guys får crew-opgraderinger, der får dem til at mine mere for hver anden bygning, du ejer.
    </li>
    <li>En gang imellem flyver en Golden Comet hen over skærmen. Klik på den, før den er væk, for en stor bunke malm eller syv gange produktionen et stykke tid.</li>
    <li>
        Cirka hvert <?= HBHUB_MINER_SHARD_SLOT_MS / 60000 ?>. minut, mens spillet er åbent, flyver en Star Shard forbi den
        anden vej, i kun <?= HBHUB_MINER_SHARD_STAY_MS / 1000 ?> sekunder. Klik på den for at beholde den. Star Shards
        beholder du for altid, og Shard Shop-visningen sælger boosts, malm med det samme, en Comet Beacon, der kalder den
        næste komet frem, asteroideskins og klikeffekter til dem. Boosts, malm og kometer er til dit eget spil, så ikke i en
        Anomaly.
    </li>
    <li>
        Senere har Dark Matter-visningen Warp Jump, som starter dig forfra i en ny galakse for Dark Matter,
        Antimatter-visningen har Big Crunch, som starter hele universet forfra for Antimatter, og Strange Matter-visningen
        har Big Rip, som starter hele multiverset forfra for Strange Matter. Spillet bliver ved med at køre, uanset hvilken
        visning du er på.
    </li>
    <li>
        Strange Matter bruges i færdighedstræet, på <?= count(HBHUB_MINER_SKILLS) ?> færdigheder, du beholder for altid. Det
        vokser mod højre fra Strangelet til venstre, med en række for hver af dets fem grene, og en færdighed kan læres, når
        alle færdigheder, der er forbundet til venstre for den, er lært, også dem i grenene ved siden af. Vælg en for at se,
        hvad den gør, og hvad den koster.
    </li>
    <li>
        Med Dark Matter-opgraderingen Auto Buy køber en knap i butikken for dig, mens spillet er åbent: den bygning,
        <kbd>B</kbd> ville vælge, eller en opgradering, alt efter hvad der tjener sin pris hjem hurtigst. Er en opgradering
        værd at vente på, sparer den op til den i stedet for at bruge malmen på bygninger. Har du meget malm, køber den den
        bygning 10 eller 100 ad gangen, også uden Bulk Buying. I Anomalyen Hands Only tæller den et klik i sekundet af dine
        egne, så den stadig køber, før du har en autoklikker, og i One Track lader den hvert forsøgs første bygning (den
        eneste slags, det forsøg kan have) være op til dig at vælge.
    </li>
    <li>
        Med Antimatter-opgraderingen Comet Catcher bliver hver Golden Comet fanget for dig et øjeblik efter, den dukker op,
        mens spillet er åbent. Diamond Comets bliver ladt tilbage, så du selv kan fange dem.
    </li>
    <li>
        Project Starbound-visningen har Cosmic Engine, som alle på HB Hub bygger sammen, ét trin ad gangen, ud af Starbound
        Parts. Når du har tjent <?= number_format(HBHUB_MINER_ENGINE_STRANGE, 0, ',', '.') ?> Strange Matter, kan du give den
        dele (indtil da viser visningen kun, hvor langt den er): op til <?= HBHUB_MINER_ENGINE_ORE_PARTS ?> om dagen for malm
        (den første for <?= $rulesNumber(HBHUB_MINER_ENGINE_ORE_PART) ?>, hver næste den dag ti gange så meget), så mange du
        vil for <?= HBHUB_MINER_ENGINE_STRANGE_PART ?> Strange Matter hver (det får dig ikke til at mine langsommere, da
        Strange Matter-bonussen tæller alt, du har tjent), og ved at klare dagens <?= HBHUB_MINER_SHIFT_COUNT ?> Starbound
        Shifts, opgaver valgt blandt <?= count(HBHUB_MINER_SHIFTS) ?>, som er de samme for alle og giver
        <?= HBHUB_MINER_SHIFT_PARTS ?> dele hver (<?= HBHUB_MINER_SHIFT_PARTS_HARD ?> for en svær), plus
        <?= HBHUB_MINER_SHIFT_BONUS ?> mere, når de alle er klaret. Hvert færdigt trin giver alle, der spiller, dets fordel
        for altid. Nogle trin har også en lås, som et antal forskellige spillere, der har hjulpet, og bliver først færdige,
        når den er opfyldt. Dele fra malm tæller halvt fra trin 11 og en kvart fra trin 16. Når et byggeri er færdigt, starter
        det næste, med nye trin og låse, der tæller fra nul igen; byggeri #2 er Starforge, og der er
        <?= count(HBHUB_MINER_ENGINES) ?> byggerier i alt. Et byggeris sidste trin skal fyldes inden for
        <?= intdiv(HBHUB_MINER_ENGINE_COUNTDOWN_MS, HBHUB_MINER_DAY_MS) ?> dage efter det før, ellers går halvdelen af dets
        dele tabt, og tiden starter forfra; når det er fyldt, miner alt <?= HBHUB_MINER_IGNITION_TIMES ?> gange så meget i
        <?= intdiv(HBHUB_MINER_IGNITION_MS, 3600000) ?> timer.
    </li>
    <li>
        Når du har tjent <?= number_format(HBHUB_MINER_ANOMALY_STRANGE, 0, ',', '.') ?> Strange Matter, åbner
        Anomalies-visningen: udfordringer i et univers for sig med én regel ændret, som ingen kometer eller klik, der ikke
        miner noget. Du beholder dine færdigheder og din Strange Matter, starter uden Dark Matter eller Antimatter og
        gennemfører en ved at tjene dens mål af Antimatter. Imens miner dit eget spil, som om du var væk, og du kan gå, når du
        vil: Save and leave gemmer forsøget, så du kan fortsætte senere (dets ur bliver ved med at gå), almindelig Leave
        mister det. At gennemføre en giver det Project Starbound, der bliver bygget, dele: dem alle første gang for hvert
        byggeri og ved en ny bedste tid, ellers <?= HBHUB_MINER_ANOMALY_REPEAT * 100 ?> %. Hver gang du gennemfører en, får
        dit eget spil også en Afterglow: det miner <?= HBHUB_MINER_AFTERGLOW_TIMES ?> gange så meget i en time (Tier I), 3
        timer (Tier II), 12 timer (Tier III) eller 24 timer (The Gauntlet). Tier II åbner med Theory of Everything og slår
        Autopilot og Auto Crunch fra. Tier III åbner også dér og starter dig fra nul: ingen færdigheder, ingen Strange Matter
        og ingen opgraderinger, med deres egne mål (som et Big Rip eller malm i én runde). Tier I- og II-Anomalies kan
        stables, med alle deres regler på én gang, for deres dele lagt sammen gange <?= HBHUB_MINER_ANOMALY_STACK ?> for hver
        efter den første (bortset fra Unstable Vacuum, som kun kan spilles alene, og et par par, der ikke kan klares
        sammen). Hver uge er der en ugentlig Anomaly, en stak, der er den samme for alle og værd
        <?= HBHUB_MINER_ANOMALY_WEEKLY ?> gange så mange dele den uge. At gennemføre Anomalies giver også asteroideskins,
        som du vælger i Skins-visningen.
    </li>
    <li>
        Spillet gemmer af sig selv hvert <?= HBHUB_MINER_SAVE_SECONDS ?>. sekund, og når du går. Mens du er væk, bliver dine
        maskiner ved med at mine med <?= HBHUB_MINER_OFFLINE_RATE * 100 ?> % af farten (mere, når Cosmic Engine har trinene
        til det). De daglige grænser og Starbound Shifts starter forfra ved midnat UTC.
    </li>
    <li class="miner-hot-keys" id="hot-keys"><!-- the Hot Key Panel's popup in the game links here -->
        Genvejstaster: <kbd>Space</kbd> miner asteroiden, <kbd>C</kbd> fanger en komet (når du har opgraderingen Comet Hot
        Key). Når du har opgraderingen Hot Key Panel, køber <kbd>1</kbd>-<kbd>9</kbd> og <kbd>0</kbd> bygningerne i den
        rækkefølge, de står (i stedet de sidste ti, med Long-Range Hot Keys slået til, hvilket <kbd>K</kbd> også gør),
        <kbd>U</kbd> køber den billigste opgradering, du har råd til, og <kbd>B</kbd> den bygning, du har råd til, der giver
        mest malm per sekund for prisen. <kbd>A</kbd> skifter mellem at købe 1, 10, 100 og så mange, du har råd til (med Max
        Buying), <kbd>W</kbd> åbner Warp Jump, <kbd>X</kbd> Big Crunch, <kbd>R</kbd> Big Rip (så siger <kbd>Y</kbd>, eller
        samme tast igen, ja og <kbd>N</kbd> ikke endnu), og <kbd>M</kbd> slår lyden til eller fra. At holde Space nede miner
        kun én gang, men holder du <kbd>1</kbd>-<kbd>9</kbd>, <kbd>0</kbd>, <kbd>U</kbd> eller <kbd>B</kbd> nede, bliver den
        ved med at købe.
    </li>
    <li>Spil i én fane ad gangen: åbner du spillet et andet sted, stopper det her.</li>
    <li>
        Klik bliver talt op til <?= HBHUB_MINER_CLICKS_PER_SECOND ?> i sekundet. Autoklikkere og scripts er ikke tilladt og
        giver dig alligevel ikke meget: klik, der ligner, at de kommer fra en, miner kun <?= HBHUB_MINER_AUTO_VALUE * 100 ?> %
        af det, de ellers ville.
    </li>
</ul>
