<?php
// Help docs: Ore Mining Inc. - getting started, every building (straight from HBHUB_MINER_BUILDINGS in
// assets/php/games/spaceOreMiner/miner.php, so a new one or a new price shows up here by itself), the comets, Star
// Shards and the Shard Shop (from HBHUB_MINER_SHOP), the three
// prestiges, the skill tree, the Cosmic Engines (each Engine's stages straight from HBHUB_MINER_ENGINE_STAGES and
// HBHUB_MINER_ENGINES, with their default costs), the Anomalies (from HBHUB_MINER_ANOMALIES and HBHUB_MINER_ANOMALY_TIERS), saving and the game's own How to
// play (rules.php, included as the game shows it).
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

                    <section id="star-shards" aria-labelledby="docs-star-shards">
                        <h2 id="docs-star-shards">Star Shards and the Shard Shop</h2>
                        <p>
                            About every <?= HBHUB_MINER_SHARD_SLOT_MS / 60000 ?> minutes, a glittering Star Shard flies across the
                            screen the other way from the comets. It only stays <?= HBHUB_MINER_SHARD_STAY_MS / 1000 ?> seconds,
                            so keep an eye out: click it to keep it. It only comes while the game is open, also in an Anomaly
                            (it still goes to your own game), and Comet Catcher leaves it for you. Star Shards are yours for
                            good: Warp Jumps, Big Crunches and Big Rips don't take them.
                        </p>
                        <p>Spend them in the Shard Shop, a view of its own in the game's menu:</p>
                        <div class="docs-table-wrap">
                            <table class="docs-table">
                                <thead>
                                    <tr><th scope="col">Item</th><th scope="col">Star Shards</th><th scope="col">What you get</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (HBHUB_MINER_SHOP as $docsItem): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($docsItem['name']) ?></td>
                                            <td><?= $docsItem['cost'] ?></td>
                                            <td><?= htmlspecialchars($docsItem['kind'] === 'skin' ? 'An asteroid skin: ' . $docsItem['text'] : ($docsItem['kind'] === 'effect' ? 'A click effect: ' . $docsItem['text'] : $docsItem['text'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p>
                            Boosts work on top of the comets' effects and keep going through Warp Jumps; buying one again adds
                            its time on, up to <?= HBHUB_MINER_SHOP_BOOST_MAX_MS / 3600000 ?> hours ahead. Ore is that many hours of
                            what you mine a second right now. Boosts, ore and the Comet Beacon are for your own game, so they
                            can't be bought in an Anomaly. Skins and click effects are kept for good, and you can switch them
                            on and off in the shop (skins in your stats too).
                        </p>
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
                            Big Crunch. Project Starbound and Anomalies views have their own bar under the menu: you can give Project Starbound
                            parts after your first Warp Jump, and start Anomalies after your first Big Rip. A dot in the menu
                            shows when there's something to do in a view.
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
                            <li><strong>Cosmos</strong>: more Dark Matter, Antimatter and Strange Matter, more ore while you're away, and the Autopilot and Auto Crunch switches, which Warp Jump and Big Crunch for you once it would multiply your Dark Matter or Antimatter by the number you put next to them (4 to start with).</li>
                        </ul>
                        <p>
                            A skill can be learned once every skill linked to the left of it is, the ones in the branch next to it
                            included. Pick a skill to see what it does, what it needs and what it costs.
                        </p>
                    </section>

                    <section id="engine" aria-labelledby="docs-engine">
                        <h2 id="docs-engine">Project Starbound</h2>
                        <p>
                            The Project Starbound view has a machine everyone on HB Hub builds together, out of Starbound Parts, drawn as a rocket
                            on its launch pad: each stage done adds a part to it, the one being built fills up as parts come in, and
                            you can point at a part to see its stage. Once you've made your first Warp Jump, you can help in three ways:
                        </p>
                        <ul>
                            <li><strong>Ore</strong>: up to <?= HBHUB_MINER_ENGINE_ORE_PARTS ?> parts a day (one more once the Starforge's Air Intakes are done). The day's first costs <?= docsMinerNumber(HBHUB_MINER_ENGINE_ORE_PART) ?> ore, and each next one ten times as much. From each build's third section (stage 11) a part from ore counts as half a part, and from its fourth (stage 16) as a quarter, or half again once the Starforge's Piston Arms are done.</li>
                            <li><strong>Strange Matter</strong>: <?= HBHUB_MINER_ENGINE_STRANGE_PART ?> for a part, as many as you like. It doesn't make you mine slower, as the Strange Matter bonus counts all you've ever earned, not what you have left.</li>
                            <li>
                                <strong>Starbound Shifts</strong>: <?= HBHUB_MINER_SHIFT_COUNT ?> small tasks a day, the same for everyone (like
                                <?= strtolower(HBHUB_MINER_SHIFTS['comets']['text']) ?> or <?= strtolower(HBHUB_MINER_SHIFTS['buildings']['text']) ?>),
                                worth <?= HBHUB_MINER_SHIFT_PARTS ?> parts each, or <?= HBHUB_MINER_SHIFT_PARTS_ALL ?> each once all of them are done.
                            </li>
                        </ul>
                        <p>
                            The parts go into one stage at a time. When a stage has all its parts, it's finished, and everyone who
                            plays gets its perk for good. Some stages have a lock too, which has to be met before they finish
                            however many parts they have: until then the parts wait there (or, for a lock that needs Anomalies, the
                            build only takes parts from Anomalies). Finishing <a href="#anomalies">Anomalies</a> gives parts
                            too, and they're the biggest source of them. The daily limits and Shifts start over at midnight UTC. The
                            <a href="/games/ore-mining-inc/leaderboard?board=starbound">leaderboard</a> shows who has given the most.
                        </p>
                        <p>
                            The last stage, Ignition, has a countdown: it has to be full within
                            <?= intdiv(HBHUB_MINER_ENGINE_COUNTDOWN_MS, HBHUB_MINER_DAY_MS) ?> days of the stage before it being
                            finished. If it isn't, <?= (1 - HBHUB_MINER_ENGINE_DRAIN) * 100 ?>% of its parts are lost and it
                            gets another <?= intdiv(HBHUB_MINER_ENGINE_COUNTDOWN_MS, HBHUB_MINER_DAY_MS) ?> days. Once it's full,
                            everything mines <?= HBHUB_MINER_IGNITION_TIMES ?> times as much for
                            <?= intdiv(HBHUB_MINER_IGNITION_MS, 3600000) ?> hours, and everyone who gave parts to Ignition gets the
                            Ignition badge on their profile and the Ignition asteroid skin.
                        </p>
                        <p>
                            Once a build is done, the next one starts straight away, with new stages and new locks. Its locks
                            count from nothing again (an Anomaly finished for Project Starbound before doesn't count), and each Anomaly
                            or stack gives all its parts again the first time it's finished for the new build. Every perk of the
                            Engines before it stays. Build #2, the Starforge, has perks that are mostly for looks or to make the
                            game easier to play, and only 3 &times; 5% more production, so the game doesn't keep getting
                            faster. Its last stage, First Light, works like Ignition, with a First Light badge and skin. Once
                            the Starforge's Alloy Bins are done, the Project Starbound view has a Starbound Hall with every build done so
                            far. These are the stages:
                        </p>
                        <?php foreach (HBHUB_MINER_ENGINES as $docsEngineNumber => $docsEngine): ?>
                            <h3>Build #<?= $docsEngineNumber ?>: the <?= htmlspecialchars($docsEngine['name']) ?></h3>
                            <div class="docs-table-wrap">
                                <table class="docs-table">
                                    <thead>
                                        <tr><th scope="col">Stage</th><th scope="col">Parts</th><th scope="col">Perk</th><th scope="col">Lock</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (hbHubMinerEngineStages($docsEngineNumber) as $index => $stage): ?>
                                            <?php if ($index % 5 === 0): ?>
                                                <tr><th scope="rowgroup" colspan="4"><?= htmlspecialchars($stage['section'] . '. ' . $docsEngine['sections'][$stage['section']]) ?></th></tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td><?= $index + 1 ?>. <?= htmlspecialchars($stage['name']) ?></td>
                                                <td><?= number_format($stage['cost']) ?></td>
                                                <td><?= htmlspecialchars($stage['perk']['text']) ?></td>
                                                <td><?= $stage['lock'] !== null ? htmlspecialchars($stage['lock']['text']) : '' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                        <p>The parts each stage needs can still change as Project Starbound is tuned.</p>
                    </section>

                    <section id="anomalies" aria-labelledby="docs-anomalies">
                        <h2 id="docs-anomalies">Anomalies</h2>
                        <p>
                            The Anomalies view has challenge runs: universes of their own, each with one rule changed. In Tiers
                            I and II you go in with your skill tree, your Strange Matter bonus and the counts that make you mine
                            faster (Warp Jumps, Big Crunches, comets caught), but with no Dark Matter, Antimatter or ore.
                            Antimatter Echo and Dark Echo keep their upgrades, like on a Big Rip. You finish one by earning its
                            goal of Antimatter in it. Tier III starts you from nothing instead: no skills, no Strange Matter
                            bonus, no upgrades and none of those counts, and each has a goal of its own. The Cosmic Engine's perks
                            work in every Anomaly.
                        </p>
                        <p>
                            <strong>Stacks:</strong> any Tier I and Tier II Anomalies can be played together, with all their rules
                            at once, from the stack builder in the Anomalies view. A stack's goal is the lowest of its Anomalies'
                            goals, and it's worth their parts added up, times <?= HBHUB_MINER_ANOMALY_STACK ?> for each Anomaly
                            after the first. A stack has its own best time. Unstable Vacuum doesn't stack (its Warp Jumps run out
                            before most stacks' goals), and a few pairs can't be picked together because they can't be done:
                            <?= implode(', ', array_map(static fn (array $pair): string => HBHUB_MINER_ANOMALIES[$pair[0]]['name'] . ' with ' . HBHUB_MINER_ANOMALIES[$pair[1]]['name'], HBHUB_MINER_ANOMALY_CLASHES)) ?>.
                        </p>
                        <p>
                            <strong>The weekly Anomaly</strong> is a stack of <?= implode(' or ', HBHUB_MINER_WEEKLY_SIZES) ?>
                            picked for everyone each week (weeks start on Monday at 00:00 UTC), never one that can't be done. Finishing it that week is worth
                            <?= HBHUB_MINER_ANOMALY_WEEKLY ?> times the parts, and the
                            <a href="/games/ore-mining-inc/leaderboard?board=weekly">leaderboard</a> lists the fastest times each
                            week.
                        </p>
                        <p>
                            <strong>Rewards for you:</strong> a badge and best time for each Anomaly, achievements, asteroid skins
                            (a colour for each Tier II Anomaly, an animated one for each Tier III, and more from the Cosmic
                            Engine), picked in your stats, and the leaderboard titles "Singularity" (Naked Singularity) and
                            "Gauntlet Runner" (The Gauntlet). Those come before the builds' titles, "Forgemaster" and
                            "Engineer".
                        </p>
                        <p>
                            While you're in one, a banner says so on every view, and your own game waits and mines as if you
                            were away. You can leave whenever you like. With <strong>Save and leave</strong> you keep the run
                            and carry on from the Anomalies view later (up to <?= HBHUB_MINER_ANOMALY_SAVED_MAX ?> saved at once,
                            but not Speed of Light). A saved run's clock keeps going, and it mines as if you were away until you
                            carry on. With plain <strong>Leave</strong>, that run is lost. Skills, Big Rips, donations and
                            Starbound Shifts only work in your own game. What you do in an Anomaly doesn't count towards your
                            stats or the leaderboard, but finishing Anomalies has achievements of its own.
                        </p>
                        <p>
                            Finishing one gives Project Starbound being built its parts the first time (and again the first time
                            for each new build) and whenever you beat your best time, and <?= HBHUB_MINER_ANOMALY_REPEAT * 100 ?>% of them otherwise (rounded down). These parts go in
                            even while Project Starbound is full and waiting for a lock, since some locks need Anomalies. You also get
                            a badge for each one in the Anomalies view, with your best time.
                        </p>
                        <p>
                            <strong>Afterglow:</strong> every time you finish one (not only the first time), your own game
                            mines <?= HBHUB_MINER_AFTERGLOW_TIMES ?> times as much for a while afterwards, to make up for the time
                            it spent mining as if you were away: an hour for a Tier I Anomaly, 3 for Tier II, 12 for Tier III and
                            24 for The Gauntlet. A stack's are added up, and the weekly Anomaly's lasts
                            <?= HBHUB_MINER_ANOMALY_WEEKLY ?> times as long that week. Finishing another while one is running
                            adds to it, up to <?= HBHUB_MINER_AFTERGLOW_MAX_MS / 3600000 ?> hours ahead. It shows under your ore
                            with the comet effects, and works with them and with Ignition.
                        </p>
                        <?php foreach (HBHUB_MINER_ANOMALY_TIERS as $docsTier => $docsTierData): ?>
                            <h3>Tier <?= str_repeat('I', $docsTier) ?>: <?= htmlspecialchars($docsTierData['name']) ?></h3>
                            <p>
                                <?= $docsTierData['unlock'] === 'rip' ? 'Opens with your first Big Rip.' : 'Opens once you\'ve learned the Theory of Everything.' ?>
                                <?= $docsTierData['fresh'] ? 'From nothing, and they don\'t stack.' : $docsTierData['parts'] . ' parts each.' ?><?= $docsTierData['noAuto'] ? ' Autopilot and Auto Crunch are off in these.' : '' ?>
                            </p>
                            <div class="docs-table-wrap">
                                <table class="docs-table">
                                    <thead>
                                        <tr><th scope="col">Anomaly</th><th scope="col">Rule</th><th scope="col">Goal</th><?= $docsTierData['fresh'] ? '<th scope="col">Parts</th>' : '' ?></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (HBHUB_MINER_ANOMALIES as $docsAnomaly): ?>
                                            <?php if ($docsAnomaly['tier'] !== $docsTier) continue; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($docsAnomaly['name']) ?></td>
                                                <td><?= htmlspecialchars($docsAnomaly['text']) ?></td>
                                                <td><?= docsMinerNumber($docsAnomaly['goal']) ?> <?= ['runOre' => 'ore in one run', 'darkEarned' => 'Dark Matter'][$docsAnomaly['stat'] ?? ''] ?? 'Antimatter' ?></td>
                                                <?php if ($docsTierData['fresh']): ?>
                                                    <td><?= number_format($docsAnomaly['parts'] ?? $docsTierData['parts']) ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                        <p>The goals can still change as the Anomalies are balanced.</p>
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
                            <?= HBHUB_MINER_OFFLINE_RATE * 100 ?>% of the speed, or more once the Cosmic Engine has the stages for it.
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
