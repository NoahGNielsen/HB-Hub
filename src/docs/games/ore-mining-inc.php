<?php
// Help docs: Ore Mining Inc. - getting started, every building (straight from HBHUB_MINER_BUILDINGS in
// assets/php/games/spaceOreMiner/miner.php, so a new one or a new price shows up here by itself), the comets, the three
// prestiges, the skill tree, saving and the game's own How to play (rules.php, included as the game shows it).
// How much Strange Matter a Big Rip gives is still being balanced, so it's only described, never given as numbers.
// Open to everyone. Built by assets/php/docs/top.php and bottom.php.
$hbHubSessionOptional = true;
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/miner.php';

/**
 * A big number the way people say it: 12,000, 1.4 million, 75 billion. Above a quadrillion it's still words, up to
 * the decillions, which is further than any price in the store.
 * The list is keyed by the power of ten, not the number itself: PHP turns float keys into ints, and 1e21 and up
 * don't fit in one (that broke the page with "0 octillion").
 */
function docsMinerNumber(float $number): string
{
    $names = [33 => 'decillion', 30 => 'nonillion', 27 => 'octillion', 24 => 'septillion', 21 => 'sextillion',
        18 => 'quintillion', 15 => 'quadrillion', 12 => 'trillion', 9 => 'billion', 6 => 'million'];
    foreach ($names as $power => $name) {
        $size = 10.0 ** $power;
        if ($number >= $size) {
            return rtrim(rtrim(number_format($number / $size, 1), '0'), '.') . ' ' . $name;
        }
    }
    return $number < 1 ? (string) $number : number_format($number);
}

$docsPath = '/docs/games/ore-mining-inc';
$docsIntro = 'Click an asteroid for ore, hire machines that mine for you, and keep growing, from one pickaxe to a drill
    that mines the multiverse. Play it on the <a href="/games/ore-mining-inc/">Ore Mining Inc.</a> page.';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/top.php';
?>
                    <dl class="docs-facts">
                        <div><dt>Buildings</dt><dd><?= count(HBHUB_MINER_BUILDINGS) ?></dd></div>
                        <div><dt>Skills</dt><dd><?= count(HBHUB_MINER_SKILLS) ?></dd></div>
                        <div><dt>Achievements</dt><dd><?= count(HBHUB_MINER_ACHIEVEMENTS) ?></dd></div>
                    </dl>

                    <section id="start" aria-labelledby="docs-start">
                        <h2 id="docs-start">Getting started</h2>
                        <ol class="docs-steps">
                            <li>Click the asteroid (or press <kbd>Space</kbd>) to mine ore.</li>
                            <li>Buy a Space Pickaxe Guy in the store. Buildings mine for you every second, even while you don't click.</li>
                            <li>Keep buying buildings and upgrades. Each building gets upgrades when you own 10, 25, 50 and more of it, and each one doubles what it mines.</li>
                            <li>Catch the Golden Comets that fly past for a big boost.</li>
                            <li>Once you've mined a lot, Warp Jump to a new galaxy and start over, stronger.</li>
                        </ol>
                    </section>

                    <section id="buildings" aria-labelledby="docs-buildings">
                        <h2 id="docs-buildings">The buildings</h2>
                        <p>
                            Every one you buy costs <?= round((HBHUB_MINER_COST_GROWTH - 1) * 100) ?>% more than the one before.
                            What they mine is before any upgrades.
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Building</th><th scope="col">First one costs</th><th scope="col">Ore per second</th></tr>
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
                            A good rule of thumb: buy whatever gives the most ore per second for its price. The <kbd>B</kbd> key does
                            exactly that, once you have the Hot Key Panel upgrade.
                        </p>
                    </section>

                    <section id="comets" aria-labelledby="docs-comets">
                        <h2 id="docs-comets">Golden Comets</h2>
                        <p>Every few minutes a comet flies across the screen. Click it before it's gone, and you get one of these:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Comet</th><th scope="col">What you get</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (HBHUB_MINER_COMETS as $docsComet): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($docsComet['name']) ?></td>
                                            <td>
                                                <?php if ($docsComet['buff'] === null): ?>
                                                    A pile of ore straight away: <?= HBHUB_MINER_LUCKY_SECONDS[0] ?> to <?= HBHUB_MINER_LUCKY_SECONDS[1] ?> seconds of production
                                                <?php else: ?>
                                                    <?= $docsComet['multiplier'] ?>&times; <?= $docsComet['buff'] === 'click' ? 'every click' : 'production' ?> for <?= $docsComet['ms'] / 1000 ?> seconds
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p>Diamond Rush is rare. Different comets' effects stack: a Diamond Rush caught during a Supernova multiplies on top of it. Comet upgrades in the store make comets come more often, stay longer and give more.</p>
                    </section>

                    <section id="prestige" aria-labelledby="docs-prestige">
                        <h2 id="docs-prestige">Warp Jump, Big Crunch and Big Rip</h2>
                        <p>
                            Sooner or later, growing gets slow. That's when you start over, for something that makes the next run
                            much faster. There are three ways, each bigger than the last, and each in its own view (the menu at the
                            top switches views without stopping the game):
                        </p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Prestige</th><th scope="col">When</th><th scope="col">Starts over</th><th scope="col">Gives</th></tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Warp Jump <kbd>W</kbd></td>
                                        <td>After <?= docsMinerNumber(HBHUB_MINER_WARP_ORE) ?> ore in a run</td>
                                        <td>Your ore, buildings and upgrades</td>
                                        <td>Dark Matter: the cube root of the ore mined in this universe, in billions. Each one is +<?= HBHUB_MINER_DARK_BONUS * 100 ?>% production, and buys Dark Matter upgrades</td>
                                    </tr>
                                    <tr>
                                        <td>Big Crunch <kbd>X</kbd></td>
                                        <td>After <?= number_format(HBHUB_MINER_CRUNCH_DARK) ?> Dark Matter in a universe</td>
                                        <td>Everything above, Dark Matter and its upgrades too</td>
                                        <td>Antimatter: the square root of the Dark Matter, in hundreds. Each one is +<?= HBHUB_MINER_ANTI_BONUS * 100 ?>% production and more Dark Matter from every Warp Jump, and buys Antimatter upgrades</td>
                                    </tr>
                                    <tr>
                                        <td>Big Rip <kbd>R</kbd></td>
                                        <td>After <?= number_format(HBHUB_MINER_RIP_ANTI) ?> Antimatter in a multiverse</td>
                                        <td>Everything above, Antimatter and its upgrades too</td>
                                        <td>Strange Matter: more for every ten times as much Antimatter, but slowly. Each one ever earned is +<?= HBHUB_MINER_STRANGE_BONUS * 100 ?>% production for good, and it buys skills</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            A Warp Jump and a Big Rip only give what's more than you've had from them before, so each one has to beat
                            your best. The Antimatter view opens after your first Warp Jump, the Strange Matter view after your first
                            Big Crunch. A dot in the menu shows when there's something to do in a view.
                        </p>
                    </section>

                    <section id="skills" aria-labelledby="docs-skills">
                        <h2 id="docs-skills">The skill tree</h2>
                        <p>
                            Strange Matter buys skills, which you keep for good, through every prestige. The tree grows from the
                            Strangelet on the left to the Theory of Everything on the right, in five branches:
                        </p>
                        <ul>
                            <li><strong>Industry</strong>: buildings, cheaper prices, starting buildings, and keeping upgrades through Warp Jumps.</li>
                            <li><strong>Hands</strong>: clicks, critical clicks and auto-clicks.</li>
                            <li><strong>Core</strong>, across the middle: big production boosts.</li>
                            <li><strong>Sky</strong>: comets.</li>
                            <li><strong>Cosmos</strong>: more Dark Matter, Antimatter and Strange Matter, more ore while you're away, and the Autopilot and Auto Crunch switches, which Warp Jump and Big Crunch for you.</li>
                        </ul>
                        <p>
                            A skill can be learned once every skill linked to the left of it is, the ones in the branch next to it
                            included. Pick a skill to see what it does, what it needs and what it costs.
                        </p>
                    </section>

                    <section id="rules" aria-labelledby="docs-rules">
                        <h2 id="docs-rules">How to play, from the game</h2>
                        <?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/games/spaceOreMiner/rules.php'; ?>
                    </section>

                    <section id="saving" aria-labelledby="docs-saving">
                        <h2 id="docs-saving">Saving, and playing fair</h2>
                        <p>
                            Your game is saved on HB Hub's server every <?= HBHUB_MINER_SAVE_SECONDS ?> seconds and when you leave,
                            so you can carry on from any device. While you're away, your buildings keep mining at
                            <?= HBHUB_MINER_OFFLINE_RATE * 100 ?>% of the speed.
                        </p>
                        <p>
                            If the game crashes or freezes before it's saved, what you did just before is kept in your browser
                            and saved the next time you open the game there. After a crash, the game asks whether it may send a
                            crash report, so the problem can be found and fixed. It's only sent if you say yes, and you can see
                            what's in it first (more in the <a href="/legal/privacy#what">privacy policy</a>).
                        </p>
                        <p>
                            The server plays every click and purchase again to check it, so the
                            <a href="/games/ore-mining-inc/leaderboard">leaderboard</a> can't be cheated from the browser. Clicks only
                            count up to <?= HBHUB_MINER_CLICKS_PER_SECOND ?> a second, and clicks at a perfectly even pace (like an
                            auto-clicker's, or a held-down key) only mine <?= HBHUB_MINER_AUTO_VALUE * 100 ?>% until the pace changes.
                            Auto-clickers and scripts aren't allowed anyway.
                        </p>
                    </section>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/assets/php/docs/bottom.php'; ?>
