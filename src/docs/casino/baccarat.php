<?php
// Help docs: Baccarat - the bets and what they pay, the table's own rules (assets/php/casino/baccarat/rules.php,
// included as the game shows them, with the third-card rules), an example hand and a few tips. The numbers are
// room.php's, round.php's and cards.php's constants. Open to everyone. Built by assets/php/docs/top.php and bottom.php.
// Player and Banker keep the names the table shows them by.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/room.php';

$docsCommission = HBHUB_BACCARAT_BANKER_COMMISSION / 10; // per thousand in round.php, a percentage here

$docsPath = '/docs/casino/baccarat';
$docsIntro = 'Der bliver givet to hænder, Player (spilleren) og Banker (bankeren). Sats på den, du tror, ender tættest på
    9. Ingen træffer valg, når kortene er ude, så <a href="/casino/baccarat/">Baccarat</a> er hurtigt at lære.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Spillere</dt><dd><?= HBHUB_BACCARAT_MIN_PLAYERS ?>-<?= HBHUB_BACCARAT_SEATS ?></dd></div>
                        <div><dt>Kortspil</dt><dd><?= HBHUB_BACCARAT_DECKS ?></dd></div>
                        <div><dt>Indsatser</dt><dd>5</dd></div>
                        <div><dt>Tid til at satse</dt><dd><?= HBHUB_BACCARAT_BETTING_MS / 1000 ?> s</dd></div>
                    </dl>

                    <section id="bets" aria-labelledby="docs-bets">
                        <h2 id="docs-bets">Indsatserne</h2>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Indsats</th><th scope="col">Vinder, når</th><th scope="col">Betaler</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td><span class="casino-bac-player">Player</span></td><td>Players hånd er tættere på 9</td><td>1:1</td></tr>
                                    <tr><td><span class="casino-bac-banker">Banker</span></td><td>Bankers hånd er tættere på 9</td><td>1:1, minus <?= $docsCommission ?> % af gevinsten</td></tr>
                                    <tr><td>Tie</td><td>Begge hænder ender på det samme tal</td><td>8:1</td></tr>
                                    <tr><td>Player Pair</td><td>Players første to kort har samme værdi</td><td>11:1</td></tr>
                                    <tr><td>Banker Pair</td><td>Bankers første to kort har samme værdi</td><td>11:1</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            Banker vinder lidt oftere end Player, og derfor betaler en sejr på Banker lidt mindre: med de
                            <?= $docsCommission ?> % trukket fra har ingen af siderne en fordel. Ved uafgjort får man indsatser på
                            Player og Banker tilbage.
                        </p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">Reglerne ved HB Hubs borde</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/casino/baccarat/rules.php'; ?>
                        <p>
                            Reglerne for det tredje kort ser indviklede ud, men du behøver aldrig huske dem: bordet følger dem for
                            dig. Det eneste, du vælger, er indsatsen.
                        </p>
                    </section>

                    <section id="example" aria-labelledby="docs-example">
                        <h2 id="docs-example">Et eksempel på en hånd</h2>
                        <ol class="docs-steps">
                            <li><span class="casino-bac-player">Player</span> får en 4'er og en konge: 4 + 0 = <strong>4</strong>. <span class="casino-bac-banker">Banker</span> får en 7'er og en 9'er: 7 + 9 = 16, altså <strong>6</strong>.</li>
                            <li>Ingen har 8 eller 9, så Player, der står på 0-5, tager et tredje kort: en 3'er. Nu har Player <strong>7</strong>.</li>
                            <li>Banker har 6, og Players tredje kort var en 3'er, så Banker står på <strong>6</strong>.</li>
                            <li>7 slår 6: indsatser på Player vinder 1:1.</li>
                        </ol>
                    </section>

                    <section id="playing" aria-labelledby="docs-playing">
                        <h2 id="docs-playing">At lægge indsatser</h2>
                        <p>
                            Vælg en chip, tryk så på et felt for at lægge den dér (højreklik på et felt for at tage en af), og tryk
                            på <strong>Place bets</strong>. &times;2 fordobler alt, hvad du har lagt. Du kan satse på Player eller
                            Banker (ikke begge), og på Tie og parrene også. Dine indsatser skal tilsammen være mindst bordets
                            mindsteindsats, i trin af <?= HBHUB_BACCARAT_BET_STEP ?>. Med færre end <?= HBHUB_CASINO_ALL_IN_BELOW ?>
                            chips tilbage kan du vælge <strong>All in</strong>-chippen og trykke på et felt for at lægge alle dine
                            chips dér.
                        </p>
                    </section>

                    <section id="tips" aria-labelledby="docs-tips">
                        <h2 id="docs-tips">Et par tips</h2>
                        <ul>
                            <li>Player og Banker er så tæt på en fair indsats, som casinoet kommer.</li>
                            <li>Tie og parrene betaler meget, men rammer sjældent: i det lange løb taber de meget hurtigere.</li>
                            <li>Tidligere hænder ændrer ikke den næste. En "stime" af sejre til Banker siger intet om, hvad der kommer.</li>
                        </ul>
                    </section>

                    <section id="tables" aria-labelledby="docs-tables">
                        <h2 id="docs-tables">Borde og rangliste</h2>
                        <p>
                            Tryk på <strong>Play solo</strong> for et bord for dig selv, åbn et for andre (offentligt, med en kode
                            eller kun for inviterede), eller gå med ved et fra lobbyen. Mere i
                            <a href="/docs/casino/#tables">Sådan virker casinoet</a>. Hver hånd tæller med på
                            <a href="/casino/baccarat/leaderboard">Baccarat-ranglisten</a>.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
