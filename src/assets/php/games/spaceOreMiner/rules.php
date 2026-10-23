<?php
// How to play Space Ore Miner, shown in the game's How to play popup (games/spaceOreMiner/index.php) and on its docs
// page (docs/games/space-ore-miner.php). The numbers are miner.php's, so it has to be included first. miner.php and
// spaceOreMiner.js play by these rules, so change them together.
?>
<ul class="casino-rule-list">
    <li>Click the asteroid to mine ore. Spend it in the store on buildings, which mine for you every second, and on upgrades that make clicks and buildings better.</li>
    <li>
        Every building costs <?= round((HBHUB_MINER_COST_GROWTH - 1) * 100) ?>% more than the one before
        (Space Pickaxe Guys only <?= round((HBHUB_MINER_BUILDINGS['pickaxe']['growth'] - 1) * 100) ?>%). Each one
        gets <?= count(HBHUB_MINER_BUILDING_UPGRADE_AT) ?> upgrades, at
        <?= implode(', ', array_slice(HBHUB_MINER_BUILDING_UPGRADE_AT, 0, -1)) ?> and
        <?= HBHUB_MINER_BUILDING_UPGRADE_AT[count(HBHUB_MINER_BUILDING_UPGRADE_AT) - 1] ?> owned, that double what it
        mines, and Space Pickaxe Guys get crew upgrades that make them mine more for every other building you own.
    </li>
    <li>Now and then a Golden Comet flies across the screen. Click it before it's gone for a big pile of ore, or seven times the production for a while.</li>
    <li>
        Later on, the Dark Matter view has the Warp Jump, which starts you over in a new galaxy for Dark Matter,
        the Antimatter view has the Big Crunch, which starts the whole universe over for Antimatter, and the
        Strange Matter view has the Big Rip, which starts the whole multiverse over for Strange Matter. The game
        keeps running whichever view you're on.
    </li>
    <li>
        Strange Matter is spent in the skill tree, on <?= count(HBHUB_MINER_SKILLS) ?> skills you keep for good. It
        grows to the right from the Strangelet on the left, with a row for each of its five branches, and a
        skill can be learned once every skill linked to the left of it is, including the ones in the
        branches next to it.
        Pick one to see what it does and what it costs.
    </li>
    <li>
        With the Auto Buy Dark Matter upgrade, a switch in the store buys for you while the game is open: the
        building <kbd>B</kbd> would pick or an upgrade, whichever pays back its price soonest. When an upgrade
        is worth waiting for, it saves up for it instead of spending the ore on buildings.
    </li>
    <li>
        With the Comet Catcher Antimatter upgrade, every Golden Comet is caught for you a moment after it shows up,
        while the game is open. Diamond Comets are left for you to catch yourself.
    </li>
    <li>
        The game saves by itself every <?= HBHUB_MINER_SAVE_SECONDS ?> seconds and when you leave. While you're away,
        your machines keep mining at <?= HBHUB_MINER_OFFLINE_RATE * 100 ?>% of the speed.
    </li>
    <li class="miner-hot-keys" id="hot-keys"><!-- the Hot Key Panel's popup in the game links here -->
        Hot keys: <kbd>Space</kbd> mines the asteroid, <kbd>C</kbd> catches a comet (once you have the Comet Hot Key upgrade). Once you have
        the Hot Key Panel upgrade, <kbd>1</kbd>-<kbd>9</kbd> and <kbd>0</kbd>
        buy the buildings in the order they're listed (the last ten instead, with Long-Range Hot Keys switched
        on, which <kbd>K</kbd> does too), <kbd>U</kbd> buys the cheapest upgrade you can afford and <kbd>B</kbd>
        the building you can afford that gives the most ore per second for its price. <kbd>A</kbd> switches between buying 1, 10 and 100, <kbd>W</kbd> opens the
        Warp Jump, <kbd>X</kbd> the Big Crunch, <kbd>R</kbd> the Big Rip and <kbd>M</kbd> turns the sound on or off. Holding Space down only mines once, but
        holding <kbd>1</kbd>-<kbd>9</kbd>, <kbd>0</kbd>, <kbd>U</kbd> or <kbd>B</kbd> keeps buying.
    </li>
    <li>Play in one tab at a time: opening the game somewhere else stops it here.</li>
    <li>
        Clicks are counted up to <?= HBHUB_MINER_CLICKS_PER_SECOND ?> a second. Auto-clickers and scripts aren't allowed, and buy
        you little anyway: clicks that look like they come from one only mine <?= HBHUB_MINER_AUTO_VALUE * 100 ?>% of what they would.
    </li>
</ul>
