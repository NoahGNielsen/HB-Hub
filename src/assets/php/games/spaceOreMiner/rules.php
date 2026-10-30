<?php
// How to play Ore Mining Inc., shown in the game's How to play popup (games/spaceOreMiner/index.php) and on its docs
// page (docs/games/ore-mining-inc.php). The numbers are miner.php's, so it has to be included first. miner.php and
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
        About every <?= HBHUB_MINER_SHARD_SLOT_MS / 60000 ?> minutes while the game is open, a Star Shard flies past the other
        way, for only <?= HBHUB_MINER_SHARD_STAY_MS / 1000 ?> seconds. Click it to keep it. Star Shards are kept for good,
        and the Shard Shop (by the title) sells boosts, ore right away, a Comet Beacon that calls the next comet in,
        asteroid skins and click effects for them. Boosts, ore and comets are for your own game, so not in an Anomaly.
    </li>
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
        is worth waiting for, it saves up for it instead of spending the ore on buildings. When you have a lot
        of ore, it buys that building 10 or 100 at a time, even without Bulk Buying. In the Hands Only Anomaly
        it counts a click a second of your own, so it still buys before you have an auto-clicker, and in One
        Track it leaves each run's first building (the only kind that run can have) for you to pick.
    </li>
    <li>
        With the Comet Catcher Antimatter upgrade, every Golden Comet is caught for you a moment after it shows up,
        while the game is open. Diamond Comets are left for you to catch yourself.
    </li>
    <li>
        The Engine view has the Cosmic Engine, which everyone on HB Hub builds together, one stage at a time, out of
        Engine Parts. Once you've made a Warp Jump you can give it parts: up to <?= HBHUB_MINER_ENGINE_ORE_PARTS ?> a day
        for ore (the first for <?= hbHubMinerSkillNumber(HBHUB_MINER_ENGINE_ORE_PART) ?>, each next one that day ten
        times as much), as many as you like for <?= HBHUB_MINER_ENGINE_STRANGE_PART ?> Strange Matter each (it doesn't
        make you mine slower, as the Strange Matter bonus counts all you've earned), and by working the day's
        <?= HBHUB_MINER_SHIFT_COUNT ?> Engine Shifts, small tasks that are the same for everyone and give
        <?= HBHUB_MINER_SHIFT_PARTS ?> parts each, or <?= HBHUB_MINER_SHIFT_PARTS_ALL ?> each once all of them are done.
        Every stage finished gives everyone who plays its perk for good. Some stages have a lock too, like a number of
        different players who have helped, and only finish once it's met. Parts from ore count half from stage 11 and a
        quarter from stage 16. Once an Engine is done the next one starts, with new stages and locks that count from
        nothing again; Engine #2 is the Starforge. An Engine's last stage has to be filled within
        <?= intdiv(HBHUB_MINER_ENGINE_COUNTDOWN_MS, HBHUB_MINER_DAY_MS) ?> days of the one before it, or half its parts are
        lost and the time starts over; once it's full, everything mines <?= HBHUB_MINER_IGNITION_TIMES ?> times as much for
        <?= intdiv(HBHUB_MINER_IGNITION_MS, 3600000) ?> hours.
    </li>
    <li>
        After your first Big Rip, the Anomalies view opens: challenge runs in a universe of their own with one rule
        changed, like no comets or clicks that mine nothing. You keep your skills and Strange Matter, start without Dark
        Matter or Antimatter, and finish one by earning its goal of Antimatter. Meanwhile your own game mines as if you
        were away, and you can leave whenever you like: Save and leave keeps the run to carry on later (its clock keeps
        going), plain Leave loses it. Finishing one gives the Engine being
        built parts: all of them the first time for each Engine and for a new best time, <?= HBHUB_MINER_ANOMALY_REPEAT * 100 ?>% otherwise.
        Every time you finish one, your own game also gets an Afterglow: it mines <?= HBHUB_MINER_AFTERGLOW_TIMES ?> times as much
        for an hour (Tier I), 3 hours (Tier II), 12 hours (Tier III) or 24 hours (The Gauntlet).
        Tier II opens with the Theory of Everything, and turns Autopilot and Auto Crunch off. Tier III opens then too, and
        starts you from nothing: no skills, no Strange Matter and no upgrades, with goals of their own (like a Big Rip, or
        ore in one run). Tier I and II Anomalies can be stacked, with all their rules at once, for their parts added up
        times <?= HBHUB_MINER_ANOMALY_STACK ?> for each one after the first (apart from Unstable Vacuum, which only goes on its
        own, and a few pairs that can't be done together). Every week there's a weekly Anomaly, a stack
        that's the same for everyone and worth <?= HBHUB_MINER_ANOMALY_WEEKLY ?> times the parts that week. Finishing
        Anomalies also earns asteroid skins, which you pick in your stats.
    </li>
    <li>
        The game saves by itself every <?= HBHUB_MINER_SAVE_SECONDS ?> seconds and when you leave. While you're away,
        your machines keep mining at <?= HBHUB_MINER_OFFLINE_RATE * 100 ?>% of the speed (more once the Cosmic Engine
        has the stages for it). The daily limits and Engine Shifts start over at midnight UTC.
    </li>
    <li class="miner-hot-keys" id="hot-keys"><!-- the Hot Key Panel's popup in the game links here -->
        Hot keys: <kbd>Space</kbd> mines the asteroid, <kbd>C</kbd> catches a comet (once you have the Comet Hot Key upgrade). Once you have
        the Hot Key Panel upgrade, <kbd>1</kbd>-<kbd>9</kbd> and <kbd>0</kbd>
        buy the buildings in the order they're listed (the last ten instead, with Long-Range Hot Keys switched
        on, which <kbd>K</kbd> does too), <kbd>U</kbd> buys the cheapest upgrade you can afford and <kbd>B</kbd>
        the building you can afford that gives the most ore per second for its price. <kbd>A</kbd> switches between buying 1, 10, 100 and as many as you can afford (with Max Buying), <kbd>W</kbd> opens the
        Warp Jump, <kbd>X</kbd> the Big Crunch, <kbd>R</kbd> the Big Rip (then <kbd>Y</kbd>, or the same key again, says yes and <kbd>N</kbd> not yet) and <kbd>M</kbd> turns the sound on or off. Holding Space down only mines once, but
        holding <kbd>1</kbd>-<kbd>9</kbd>, <kbd>0</kbd>, <kbd>U</kbd> or <kbd>B</kbd> keeps buying.
    </li>
    <li>Play in one tab at a time: opening the game somewhere else stops it here.</li>
    <li>
        Clicks are counted up to <?= HBHUB_MINER_CLICKS_PER_SECOND ?> a second. Auto-clickers and scripts aren't allowed, and buy
        you little anyway: clicks that look like they come from one only mine <?= HBHUB_MINER_AUTO_VALUE * 100 ?>% of what they would.
    </li>
</ul>
