<?php
// Ore Mining Inc., the clicker game: click the asteroid for ore, buy machines that mine for you, and grow from one
// miner on a rock into a galaxy-wide mining company. Warp Jump (prestige) starts over in a new galaxy for Dark Matter,
// which makes everything mine faster for good. Big Crunch (the prestige after that) collapses the whole universe, Dark
// Matter and all, for Antimatter, which makes everything mine faster and Warp Jumps give more Dark Matter. Big Rip (the
// last one) tears the whole multiverse apart, Antimatter and all, for Strange Matter, which makes everything mine
// faster for good and is spent in the skill tree (HBHUB_MINER_SKILLS).
//
// Each user has one save: a row in HBHub-MinerSaves with the whole game as JSON in saveState (the shape is in
// hbHubMinerNewState()), plus a few of its numbers in their own columns for the leaderboard.
//
// The game runs in the browser (spaceOreMiner.js) so it's smooth, but the server has the last word: every 30 seconds
// (and when the tab is hidden or closed) the page sends what the player did since the last save - clicks, buildings and
// upgrades bought, comets caught, Warp Jumps - each with the time it happened, and hbHubMinerSync() plays them again on
// the saved game with the same rules as the script. Something that couldn't have happened (buying without the ore,
// catching a comet that wasn't there, more than HBHUB_MINER_CLICKS_PER_SECOND clicks a second) just doesn't count, and
// the page carries on from the game the server sends back. Clicks the page found to look like an auto-clicker's (it
// sees the mouse, which the server can't, see looksAuto() in the script) come with 'auto' and only mine
// HBHUB_MINER_AUTO_VALUE of what they would. So the script and this file must work the same: the rules
// below are copied in spaceOreMiner.js, and the data (buildings, upgrades, achievements and the numbers) goes to the
// page as JSON from hbHubMinerConfig(), so it's only written here.
// Luck (critical clicks, when comets come and what they do) comes from hbHubMinerRandom(): the same numbers here and in
// the script for the same save, so both agree on which click was critical.
//
// Time is Unix milliseconds throughout. A gap between two saves of up to HBHUB_MINER_ONLINE_MS counts as playing;
// anything after that is time away, which mines at HBHUB_MINER_OFFLINE_RATE.
//
// The Cosmic Engine (HBHUB_MINER_ENGINE_STAGES) is the one thing in the game that isn't the player's own: a machine the
// whole site builds together out of Engine Parts, which players give from their game (the 'donate' action, and the
// daily Engine Shifts, HBHUB_MINER_SHIFTS). The rules for giving them are here like the rest; where they go (the
// HBHub-MinerEngines and HBHub-MinerContributions tables) is in engine.php, which hbHubMinerSync() calls.
//
// Anomalies (HBHUB_MINER_ANOMALIES) are challenge runs: a universe of their own with a twist in the rules, kept inside
// the save ('anomaly', see hbHubMinerNewState()). While the player is in one, everything they do happens in there, and
// their own game waits and mines as if they were away; finishing one gives the Engine parts.
// Used by games/spaceOreMiner/ (the page), games/spaceOreMiner/save (the JSON the page saves to) and its leaderboard.
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/engine.php';

const HBHUB_MINER_SAVE_SECONDS = 30; // how often the page saves while it's open
const HBHUB_MINER_ONLINE_MS = 90000; // a gap between saves up to this long counts as playing, the rest as away
const HBHUB_MINER_OFFLINE_RATE = 0.05; // of the normal ore per second, while away
const HBHUB_MINER_CLICKS_PER_SECOND = 20; // the most clicks that count, so auto-clickers don't pay
const HBHUB_MINER_AUTO_VALUE = 0.2; // the share of what a click is worth that an auto-clicker's click mines
const HBHUB_MINER_MAX_ACTIONS = 2000; // in one save
// The most saves in HBHUB_MINER_SAVES_SECONDS (saveRateCount in HBHub-MinerSaves). The page saves every 30 seconds,
// plus half a second after a Warp Jump or Big Crunch and when it's hidden or closed, so even at its busiest it's
// nowhere near; only a script sending saves by hand gets there. Each save replays up to HBHUB_MINER_MAX_ACTIONS.
const HBHUB_MINER_SAVES_MAX = 50;
const HBHUB_MINER_SAVES_SECONDS = 10;
const HBHUB_MINER_COST_GROWTH = 1.15; // each building costs this much more than the one before (unless it has its own 'growth')
const HBHUB_MINER_COST_GROWTH_PHYSICS = 0.01; // taken off that with Cheaper Physics
const HBHUB_MINER_WARP_ORE = 1e9; // ore to mine in a run before a Warp Jump
const HBHUB_MINER_OLD_FLIGHTS = 100; // the most Warp Jumps a save from before 'flights' (hbHubMinerNewState()) keeps as them
const HBHUB_MINER_DARK_UNIT = 1e9; // all-time ore for the first Dark Matter, see hbHubMinerDarkTotal()
const HBHUB_MINER_DARK_BONUS = 0.05; // production per Dark Matter earned in this universe
const HBHUB_MINER_DARK_BONUS_REACTOR = 0.075; // with Dark Matter Reactor
const HBHUB_MINER_CRUNCH_DARK = 2000; // Dark Matter to earn in a universe before a Big Crunch
const HBHUB_MINER_ANTI_UNIT = 100; // Dark Matter for the first Antimatter, see hbHubMinerAntiTotal()
const HBHUB_MINER_ANTI_BONUS = 0.1; // production per Antimatter earned in this multiverse (and the square root of it to Dark Matter)
const HBHUB_MINER_RIP_ANTI = 10000; // Antimatter to earn in a multiverse before a Big Rip
const HBHUB_MINER_STRANGE_UNIT = 1000; // Antimatter for the first Strange Matter, see hbHubMinerStrangeTotal()
const HBHUB_MINER_STRANGE_STEPS = [1, 1.12, 1.25, 1.4, 1.6, 1.8, 2, 2.24, 2.5, 2.8, 3.15, 3.55, 4, 4.5, 5, 5.6, 6.3, 7.1, 8, 9]; // a Strange Matter at each of these times a power of ten (times the unit), 20 for every ten times as much
const HBHUB_MINER_STRANGE_BONUS = 1; // production per Strange Matter ever earned
const HBHUB_MINER_LEFTOVER_ORE = 1e7; // ore every run starts with, with Big Bang Leftovers
const HBHUB_MINER_COMET_WAIT_MS = [60000, 180000]; // between two comets, halved with Comet Radar
const HBHUB_MINER_COMET_STAY_MS = 12000; // on screen, doubled with Comet Magnet
const HBHUB_MINER_DIAMOND_CHANCE = 0.05; // with Lucky Stars
const HBHUB_MINER_CRIT_CHANCE = 0.05;
const HBHUB_MINER_CRIT_MULTIPLIER = 10;
const HBHUB_MINER_HEAD_START = 10; // Mining Drones with Head Start
const HBHUB_MINER_DARK_PICKAXE = 3; // clicks mine this many times as much with Dark Pickaxe
const HBHUB_MINER_TAB_PATTERN = '/^[0-9a-f]{32}$/';

// What a comet does when it's caught: production or clicks times 'multiplier' for 'ms' (doubled with Tail Collector),
// or ore right away (lucky: 30 to 90 seconds of production, plus 13, doubled with Stardust Trail). Antimatter Comets doubles
// all of them (the multiplier, or the lucky ore). 'frenzy' is shown as Supernova, the key stays so old saves still work.
// Different comets' effects stack (a Diamond Rush during a Supernova is 7 × 777); the same comet again starts its over.
const HBHUB_MINER_COMETS = [
    'frenzy' => ['name' => 'Supernova', 'buff' => 'production', 'multiplier' => 7, 'ms' => 30000],
    'lucky' => ['name' => 'Lucky', 'buff' => null, 'multiplier' => 1, 'ms' => 0],
    'storm' => ['name' => 'Click Storm', 'buff' => 'click', 'multiplier' => 77, 'ms' => 13000],
    'diamond' => ['name' => 'Diamond Rush', 'buff' => 'production', 'multiplier' => 777, 'ms' => 7000],
];
const HBHUB_MINER_LUCKY_SECONDS = [30, 90]; // a lucky comet's ore, in seconds of production (random in between)

// The salts for hbHubMinerRandom(), one per kind of luck
const HBHUB_MINER_SALT_CRIT = 1;
const HBHUB_MINER_SALT_COMET_WAIT = 2;
const HBHUB_MINER_SALT_COMET_KIND = 3;
const HBHUB_MINER_SALT_DIAMOND = 4;
const HBHUB_MINER_SALT_LUCKY = 5;
const HBHUB_MINER_SALT_SHIFT = 6; // not the save's luck: the seed is 0, so everyone gets the same Shifts (hbHubMinerShiftTasks())
const HBHUB_MINER_SALT_ANOMALY = 7; // an Anomaly's own seed, from the save's and when it started (hbHubMinerAnomalyStart())
const HBHUB_MINER_SALT_WEEKLY = 8; // not the save's luck either: the week's Anomaly is the same for everyone (hbHubMinerWeeklyMods())
const HBHUB_MINER_SALT_SHIFT_SPARE = 9; // the day's spare Shift, the same for everyone too (hbHubMinerShiftSpare())

// Every 'icon' below (and in the upgrades after it) is the name of a drawn icon in assets/images/spaceOreMiner/icons.svg.
// The buildings, cheapest first. 'rate' is ore per second for each one before upgrades, 'upgrades' their six
// upgrades (see HBHUB_MINER_BUILDING_UPGRADE_AT), and 'growth', when it's there, how much more each one costs than the
// one before instead of HBHUB_MINER_COST_GROWTH (Space Pickaxe Guys go up slower, so having lots of them stays cheap)
const HBHUB_MINER_BUILDINGS = [
    'pickaxe' => ['name' => 'Space Pickaxe Guy', 'plural' => 'Space Pickaxe Guys', 'icon' => 'pickaxeGuy', 'cost' => 15, 'rate' => 0.1, 'growth' => 1.12,
        'text' => 'Swings a pickaxe at the rock. In zero gravity, that\'s harder than it sounds.',
        'upgrades' => ['Energy Drinks', 'Hard Hats', 'Union Contract', 'Jetpack Commute', 'Diamond Pickaxes', 'Employee of the Month']],
    'drone' => ['name' => 'Mining Drone', 'plural' => 'Mining Drones', 'icon' => 'drone', 'cost' => 100, 'rate' => 1,
        'text' => 'A little robot that nibbles ore off the asteroid all day long.',
        'upgrades' => ['Longer Batteries', 'Drone Swarm AI', 'Self-Repair Nanites', 'Magnetic Grabbers', 'Hive Mind', 'Drone Factories']],
    'laser' => ['name' => 'Laser Drill', 'plural' => 'Laser Drills', 'icon' => 'laserDrill', 'cost' => 1100, 'rate' => 8,
        'text' => 'Melts through rock like butter. Don\'t look straight into it.',
        'upgrades' => ['Focused Lens', 'Overclocked Lasers', 'Diamond Tips', 'Mirror Arrays', 'Gamma Beams', 'Planet-Sized Lens']],
    'rig' => ['name' => 'Mining Rig', 'plural' => 'Mining Rigs', 'icon' => 'rig', 'cost' => 12000, 'rate' => 47,
        'text' => 'A walking factory bolted onto the rock.',
        'upgrades' => ['Heavy Treads', 'Double Shifts', 'Autonomous Rigs', 'Bigger Buckets', 'Rig Convoys', 'Mega Rigs']],
    'base' => ['name' => 'Asteroid Base', 'plural' => 'Asteroid Bases', 'icon' => 'base', 'cost' => 130000, 'rate' => 260,
        'text' => 'A whole crew living on site. Shifts never end in space.',
        'upgrades' => ['Hydroponics', 'Ore Refinery', 'Artificial Gravity', 'Space Cafeteria', 'Crew Quarters', 'Base Network']],
    'freighter' => ['name' => 'Ore Freighter', 'plural' => 'Ore Freighters', 'icon' => 'freighter', 'cost' => 1.4e6, 'rate' => 1400,
        'text' => 'Hauls in ore from asteroids you haven\'t even seen yet.',
        'upgrades' => ['Bigger Cargo Holds', 'Hyperspace Lanes', 'Fleet Command', 'Tractor Beams', 'Cargo Drones', 'Armada']],
    'station' => ['name' => 'Space Station', 'plural' => 'Space Stations', 'icon' => 'station', 'cost' => 2e7, 'rate' => 7800,
        'text' => 'Runs mining all over the solar system from orbit.',
        'upgrades' => ['Solar Arrays', 'Fusion Core', 'Ring Habitat', 'Docking Ring', 'Orbital Elevator', 'Station Cities']],
    'excavator' => ['name' => 'Moon Excavator', 'plural' => 'Moon Excavators', 'icon' => 'moonExcavator', 'cost' => 3.3e8, 'rate' => 44000,
        'text' => 'Digs up whole moons. The moons have stopped complaining.',
        'upgrades' => ['Moon Treads', 'Crater Scoops', 'Core Tap', 'Lunar Conveyors', 'Moon Magnets', 'Moon Factory']],
    'cracker' => ['name' => 'Planet Cracker', 'plural' => 'Planet Crackers', 'icon' => 'planetCracker', 'cost' => 5.1e9, 'rate' => 260000,
        'text' => 'Cracks planets open like eggs to get at the ore inside.',
        'upgrades' => ['Seismic Charges', 'Tectonic Splitter', 'Planet Shredder', 'Core Drills', 'Magma Pumps', 'Planet Juicer']],
    'siphon' => ['name' => 'Black Hole Siphon', 'plural' => 'Black Hole Siphons', 'icon' => 'blackHole', 'cost' => 7.5e10, 'rate' => 1.6e6,
        'text' => 'Pulls ore out of a black hole. Nobody knows how. Nobody asks.',
        'upgrades' => ['Event Horizon Net', 'Hawking Pump', 'Singularity Engine', 'Spaghetti Filters', 'Gravity Lens', 'Pocket Black Holes']],
    'forge' => ['name' => 'Star Forge', 'plural' => 'Star Forges', 'icon' => 'starForge', 'cost' => 1e12, 'rate' => 1e7,
        'text' => 'Squeezes stars until ore comes out. The stars are fine, mostly.',
        'upgrades' => ['Stellar Bellows', 'Fusion Hammers', 'Neutron Anvil', 'Star Tongs', 'Supernova Furnace', 'Galactic Smithy']],
    'nebula' => ['name' => 'Nebula Harvester', 'plural' => 'Nebula Harvesters', 'icon' => 'nebula', 'cost' => 1.4e13, 'rate' => 6.5e7,
        'text' => 'Sweeps up whole nebulas with a really, really big net.',
        'upgrades' => ['Finer Nets', 'Gas Compressors', 'Cosmic Vacuum', 'Nebula Maps', 'Stardust Filters', 'Cloud Factory']],
    'dyson' => ['name' => 'Dyson Swarm', 'plural' => 'Dyson Swarms', 'icon' => 'dysonSwarm', 'cost' => 1.7e14, 'rate' => 4.3e8,
        'text' => 'A billion mirrors around a star, all of them pointed at the ore.',
        'upgrades' => ['Mirror Polish', 'Swarm Logic', 'Full Sphere', 'Shell Layers', 'Photon Collectors', 'Star Lifting']],
    'wormhole' => ['name' => 'Wormhole Gate', 'plural' => 'Wormhole Gates', 'icon' => 'wormhole', 'cost' => 2.1e15, 'rate' => 2.9e9,
        'text' => 'Opens a door to a part of space that\'s nothing but ore. Mind the gap.',
        'upgrades' => ['Stable Throats', 'Exotic Matter Struts', 'Wormhole Network', 'Toll Booths', 'Time Shortcuts', 'Pocket Dimensions']],
    'multiverse' => ['name' => 'Multiverse Drill', 'plural' => 'Multiverse Drills', 'icon' => 'multiverse', 'cost' => 2.6e16, 'rate' => 2.1e10,
        'text' => 'Drills into the universe next door and takes their ore too. They have a drill as well.',
        'upgrades' => ['Dimensional Bits', 'Parallel Shifts', 'Infinite Drill', 'Mirror Universe Miners', 'Quantum Foam Bits', 'The Everything Drill']],
    // The five after the Multiverse Drill go up gentler: each mines about 3.7 times the one before instead of about 7,
    // so the late game (where everyone has hundreds of every building) only gets a few hundred times faster with all
    // of them, not thousands, and the Big Rip and Anomaly balance mostly holds
    'timeQuarry' => ['name' => 'Time Quarry', 'plural' => 'Time Quarries', 'icon' => 'timeQuarry', 'cost' => 3.3e17, 'rate' => 7.8e10,
        'text' => 'Mines yesterday\'s ore before anyone else gets to it. Tomorrow\'s too, when nobody\'s looking.',
        'upgrades' => ['Sturdier Hourglasses', 'Leap Seconds', 'Paradox Insurance', 'Grandfather Clauses', 'Deja Vu Shifts', 'End of Time Shaft']],
    'stringLoom' => ['name' => 'Reality Weaver', 'plural' => 'Reality Weavers', 'icon' => 'stringLoom', 'cost' => 4.2e18, 'rate' => 2.9e11,
        'text' => 'Unpicks cosmic strings and knits them back up as ore. It\'s a very long scarf.',
        'upgrades' => ['Finer Needles', 'Superstring Yarn', 'Eleven-Dimensional Patterns', 'Knitting Circles', 'Tangle-Free Spools', 'The Cosmic Jumper']],
    'bigBangReactor' => ['name' => 'Big Bang Reactor', 'plural' => 'Big Bang Reactors', 'icon' => 'bigBangReactor', 'cost' => 5.4e19, 'rate' => 1.1e12,
        'text' => 'Sets off a tiny Big Bang in a box and keeps all the ore that comes out. Mind your eyebrows.',
        'upgrades' => ['Thicker Walls', 'Inflation Valves', 'Hotter Starts', 'Primordial Soup Kitchen', 'Chain Bangs', 'Bang Factory']],
    'realityPrinter' => ['name' => 'Reality Printer', 'plural' => 'Reality Printers', 'icon' => 'realityPrinter', 'cost' => 7e20, 'rate' => 4e12,
        'text' => 'Prints brand new universes made of nothing but ore. The ink costs more than the universe.',
        'upgrades' => ['Ink Refills', 'Double-Sided Printing', 'Higher Resolution', 'Paper Jam Fixer', 'Universes in Colour', 'Print Shop of Everything']],
    'infinityMine' => ['name' => 'Infinity Mine', 'plural' => 'Infinity Mines', 'icon' => 'infinityMine', 'cost' => 9e21, 'rate' => 1.5e13,
        'text' => 'A mine with no bottom. Somebody still goes down to check every Friday, just in case.',
        'upgrades' => ['Longer Ladders', 'Bottomless Buckets', 'Endless Rails', 'Infinite Shifts', 'Forever Lamps', 'Infinity Plus One']],
];
const HBHUB_MINER_BUILDING_UPGRADE_AT = [10, 25, 50, 100, 150, 200]; // owned, for each building's six upgrades
// What each costs, in how many of the building it is at the price the next one has when the upgrade shows up (rounded to
// two digits, see hbHubMinerUpgrades()): a few more of it, for twice as much from all of them
const HBHUB_MINER_BUILDING_UPGRADE_COST = [2.5, 3, 5, 8, 8, 8];

// The upgrades that aren't a building's own (those are made from HBHUB_MINER_BUILDINGS in hbHubMinerUpgrades()).
// 'type' says what it does:
// - click: clicks mine 'value' times as much. clickPct: each click also mines 'value' of the ore per second.
// - crit: a click can be critical (HBHUB_MINER_CRIT_CHANCE, HBHUB_MINER_CRIT_MULTIPLIER). auto: 'value' more clicks a second.
// - global: every building mines 'value' more (0.1 = +10%), all of them multiplied together. bulk: the x10 and x100 buttons
//   (and the Max button, for Max Buying).
// - synergy: 'building' mines 'per' more for every 'every' of 'source' owned.
// - crew: each of 'building' mines another 'value' ore per second (before its other upgrades) for every other building
//   owned, so Space Pickaxe Guys keep up with the rest.
// - comet: 'effect' often, longer, tail, diamond, lucky or key (the C key catches comets, only in spaceOreMiner.js).
// - keys: the U, B, 1-9 and 0 hot keys work (only in spaceOreMiner.js).
// 'need' is when it shows up in the store: ore (mined this run), owned (building => how many), comets (caught ever) and
// upgrades (the ids of upgrades that must be bought first). Without one, it's ore mined this run of half its cost.
const HBHUB_MINER_OTHER_UPGRADES = [
    'titanium' => ['name' => 'Titanium Pickaxe', 'icon' => 'pickaxe', 'cost' => 100, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'gloves' => ['name' => 'Reinforced Gloves', 'icon' => 'glove', 'cost' => 500, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'plasma' => ['name' => 'Plasma Gloves', 'icon' => 'plasmaGlove', 'cost' => 5000, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'quickKeys' => ['name' => 'Hot Key Panel', 'icon' => 'keyboard', 'cost' => 5000, 'type' => 'keys',
        'text' => 'Unlocks the hot keys that buy: U for the cheapest upgrade, B for the best building and 1-9 and 0 for the buildings in the store.'],
    'fuel' => ['name' => 'Better Fuel', 'icon' => 'fuelCan', 'cost' => 10000, 'type' => 'global', 'value' => 0.1, 'text' => 'All buildings mine 10% more.'],
    'buddySystem' => ['name' => 'Buddy System', 'icon' => 'buddies', 'cost' => 2000, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 0.005,
        'need' => ['owned' => ['pickaxe' => 10]], 'text' => 'Each Space Pickaxe Guy mines 0.005 more ore per second for every other building you own.'],
    'shiftBosses' => ['name' => 'Shift Bosses', 'icon' => 'clipboard', 'cost' => 2e5, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 0.02,
        'need' => ['owned' => ['pickaxe' => 25]], 'text' => 'Each Space Pickaxe Guy mines another 0.02 ore per second for every other building you own.'],
    'pickaxeAcademy' => ['name' => 'Pickaxe Academy', 'icon' => 'gradCap', 'cost' => 2e7, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 0.05,
        'need' => ['owned' => ['pickaxe' => 50]], 'text' => 'Each Space Pickaxe Guy mines another 0.05 ore per second for every other building you own.'],
    'pickaxeGuild' => ['name' => 'Pickaxe Guild', 'icon' => 'crossedPickaxes', 'cost' => 2e9, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 0.5,
        'need' => ['owned' => ['pickaxe' => 75]], 'text' => 'Each Space Pickaxe Guy mines another 0.5 ore per second for every other building you own.'],
    'pickaxeClones' => ['name' => 'Pickaxe Guy Clones', 'icon' => 'dna', 'cost' => 2e11, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 5,
        'need' => ['owned' => ['pickaxe' => 100]], 'text' => 'Each Space Pickaxe Guy mines another 5 ore per second for every other building you own.'],
    'pickaxeLegends' => ['name' => 'Living Legends', 'icon' => 'medal', 'cost' => 2e13, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 50,
        'need' => ['owned' => ['pickaxe' => 150]], 'text' => 'Each Space Pickaxe Guy mines another 50 ore per second for every other building you own.'],
    'pickaxeGalaxy' => ['name' => 'Galaxy of Pickaxes', 'icon' => 'galaxy', 'cost' => 2e15, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 500,
        'need' => ['owned' => ['pickaxe' => 200]], 'text' => 'Each Space Pickaxe Guy mines another 500 ore per second for every other building you own.'],
    'autoAim' => ['name' => 'Auto-Aim Laser', 'icon' => 'target', 'cost' => 50000, 'type' => 'clickPct', 'value' => 0.01, 'text' => 'Each click also mines 1% of your ore per second.'],
    'bulk' => ['name' => 'Bulk Buying', 'icon' => 'crates', 'cost' => 50000, 'type' => 'bulk', 'text' => 'Unlocks the ×10 and ×100 buttons, to buy buildings ten or a hundred at a time. Press A to switch between them.'],
    'bulkMax' => ['name' => 'Max Buying', 'icon' => 'cartFull', 'cost' => 5e6, 'type' => 'bulk', 'need' => ['ore' => 2.5e6, 'upgrades' => ['bulk']],
        'text' => 'Unlocks the Max button next to ×100, to buy as many of a building as you can afford at once.'],
    'quantum' => ['name' => 'Quantum Hammer', 'icon' => 'quantumHammer', 'cost' => 500000, 'type' => 'clickPct', 'value' => 0.02, 'text' => 'Each click mines another 2% of your ore per second.'],
    'cometKey' => ['name' => 'Comet Hot Key', 'icon' => 'keyC', 'cost' => 333333, 'type' => 'comet', 'effect' => 'key', 'need' => ['comets' => 1, 'upgrades' => ['quickKeys']], 'text' => 'Press C to catch a comet, without having to hit it.'],
    'radar' => ['name' => 'Comet Radar', 'icon' => 'radar', 'cost' => 777777, 'type' => 'comet', 'effect' => 'often', 'need' => ['comets' => 3], 'text' => 'Comets show up twice as often.'],
    'zeroG' => ['name' => 'Zero-G Logistics', 'icon' => 'zeroG', 'cost' => 1e6, 'type' => 'global', 'value' => 0.25, 'text' => 'All buildings mine 25% more.'],
    'dockingBays' => ['name' => 'Drone Docking Bays', 'icon' => 'link', 'cost' => 1.3e6, 'type' => 'synergy', 'building' => 'drone', 'source' => 'base', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['drone' => 1, 'base' => 1]], 'text' => 'Mining Drones mine 1% more for every Asteroid Base you own.'],
    'crit' => ['name' => 'Critical Strike Module', 'icon' => 'crit', 'cost' => 2e6, 'type' => 'crit', 'text' => 'Clicks have a 5% chance to mine 10 times as much.'],
    'magnet' => ['name' => 'Comet Magnet', 'icon' => 'magnet', 'cost' => 7.77e6, 'type' => 'comet', 'effect' => 'longer', 'need' => ['comets' => 7], 'text' => 'Comets stay on screen twice as long.'],
    'mapping' => ['name' => 'Asteroid Mapping', 'icon' => 'map', 'cost' => 1e7, 'type' => 'global', 'value' => 0.5, 'text' => 'All buildings mine 50% more.'],
    'guidedFreighters' => ['name' => 'Laser-Guided Freighters', 'icon' => 'link', 'cost' => 1.4e7, 'type' => 'synergy', 'building' => 'freighter', 'source' => 'laser', 'per' => 0.02, 'every' => 1,
        'need' => ['owned' => ['freighter' => 1, 'laser' => 1]], 'text' => 'Ore Freighters mine 2% more for every Laser Drill you own.'],
    'gauntlet' => ['name' => 'Gravity Gauntlet', 'icon' => 'gauntlet', 'cost' => 2.5e7, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'tail' => ['name' => 'Tail Collector', 'icon' => 'cometTail', 'cost' => 7.77e7, 'type' => 'comet', 'effect' => 'tail', 'need' => ['comets' => 15], 'text' => 'Comet effects last twice as long.'],
    'hangars' => ['name' => 'Station Hangars', 'icon' => 'link', 'cost' => 2e8, 'type' => 'synergy', 'building' => 'station', 'source' => 'drone', 'per' => 0.05, 'every' => 10,
        'need' => ['owned' => ['station' => 1, 'drone' => 10]], 'text' => 'Space Stations mine 5% more for every 10 Mining Drones you own.'],
    'supernova' => ['name' => 'Supernova Punch', 'icon' => 'supernovaFist', 'cost' => 5e8, 'type' => 'clickPct', 'value' => 0.03, 'text' => 'Each click mines another 3% of your ore per second.'],
    'autoClicker' => ['name' => 'Auto-Clicker Bot', 'icon' => 'robot', 'cost' => 1e9, 'type' => 'auto', 'value' => 2, 'text' => 'Clicks the asteroid 2 times a second for you.'],
    'warpTech' => ['name' => 'Warp Drive Tech', 'icon' => 'warp', 'cost' => 1e9, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'outposts' => ['name' => 'Moon Outposts', 'icon' => 'link', 'cost' => 3.3e9, 'type' => 'synergy', 'building' => 'base', 'source' => 'excavator', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['base' => 1, 'excavator' => 1]], 'text' => 'Asteroid Bases mine 1% more for every Moon Excavator you own.'],
    'luckyStars' => ['name' => 'Lucky Stars', 'icon' => 'diamond', 'cost' => 7.77e9, 'type' => 'comet', 'effect' => 'diamond', 'need' => ['comets' => 27], 'text' => 'Now and then a Diamond Comet shows up instead: 777 times the production for 7 seconds.'],
    'slingshot' => ['name' => 'Gravity Slingshot', 'icon' => 'link', 'cost' => 7.5e11, 'type' => 'synergy', 'building' => 'cracker', 'source' => 'siphon', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['cracker' => 1, 'siphon' => 1]], 'text' => 'Planet Crackers mine 1% more for every Black Hole Siphon you own.'],
    'monopoly' => ['name' => 'Galactic Monopoly', 'icon' => 'crown', 'cost' => 1e12, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'neutronGloves' => ['name' => 'Neutron Star Gloves', 'icon' => 'neutronFist', 'cost' => 1e10, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'botSwarm' => ['name' => 'Auto-Clicker Swarm', 'icon' => 'robots', 'cost' => 5e12, 'type' => 'auto', 'value' => 3, 'text' => 'Clicks the asteroid another 3 times a second for you.'],
    'orbitalForges' => ['name' => 'Orbital Forges', 'icon' => 'link', 'cost' => 5e12, 'type' => 'synergy', 'building' => 'forge', 'source' => 'station', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['forge' => 1, 'station' => 1]], 'text' => 'Star Forges mine 1% more for every Space Station you own.'],
    'stardust' => ['name' => 'Stardust Trail', 'icon' => 'sparkles', 'cost' => 7.77e12, 'type' => 'comet', 'effect' => 'lucky', 'need' => ['comets' => 50], 'text' => 'Lucky comets give twice as much ore.'],
    'bigBangPunch' => ['name' => 'Big Bang Punch', 'icon' => 'bigBang', 'cost' => 1e13, 'type' => 'clickPct', 'value' => 0.03, 'text' => 'Each click mines another 3% of your ore per second.'],
    'nebulaScoops' => ['name' => 'Nebula Scoops', 'icon' => 'link', 'cost' => 7e13, 'type' => 'synergy', 'building' => 'nebula', 'source' => 'freighter', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['nebula' => 1, 'freighter' => 1]], 'text' => 'Nebula Harvesters mine 1% more for every Ore Freighter you own.'],
    'starCharts' => ['name' => 'Star Charts', 'icon' => 'starChart', 'cost' => 1e14, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'shatteredPlanets' => ['name' => 'Shattered Planets', 'icon' => 'link', 'cost' => 8.5e14, 'type' => 'synergy', 'building' => 'dyson', 'source' => 'cracker', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['dyson' => 1, 'cracker' => 1]], 'text' => 'Dyson Swarms mine 1% more for every Planet Cracker you own.'],
    'blackHoleBridges' => ['name' => 'Black Hole Bridges', 'icon' => 'link', 'cost' => 1e16, 'type' => 'synergy', 'building' => 'wormhole', 'source' => 'siphon', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['wormhole' => 1, 'siphon' => 1]], 'text' => 'Wormhole Gates mine 1% more for every Black Hole Siphon you own.'],
    'oreLaw' => ['name' => 'Universal Ore Law', 'icon' => 'scales', 'cost' => 1e17, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'pickaxesEverywhere' => ['name' => 'Pickaxe Guys Everywhere', 'icon' => 'link', 'cost' => 1.3e17, 'type' => 'synergy', 'building' => 'multiverse', 'source' => 'pickaxe', 'per' => 0.01, 'every' => 10,
        'need' => ['owned' => ['multiverse' => 1, 'pickaxe' => 10]], 'text' => 'Multiverse Drills mine 1% more for every 10 Space Pickaxe Guys you own.'],
    'timeTunnels' => ['name' => 'Time Tunnels', 'icon' => 'link', 'cost' => 1.65e18, 'type' => 'synergy', 'building' => 'timeQuarry', 'source' => 'wormhole', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['timeQuarry' => 1, 'wormhole' => 1]], 'text' => 'Time Quarries mine 1% more for every Wormhole Gate you own.'],
    'nebulaThread' => ['name' => 'Nebula Thread', 'icon' => 'link', 'cost' => 2.1e19, 'type' => 'synergy', 'building' => 'stringLoom', 'source' => 'nebula', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['stringLoom' => 1, 'nebula' => 1]], 'text' => 'Reality Weavers mine 1% more for every Nebula Harvester you own.'],
    'cosmicAccounting' => ['name' => 'Cosmic Accounting', 'icon' => 'abacus', 'cost' => 1e20, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'starSeeds' => ['name' => 'Star Seeds', 'icon' => 'link', 'cost' => 2.7e20, 'type' => 'synergy', 'building' => 'bigBangReactor', 'source' => 'forge', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['bigBangReactor' => 1, 'forge' => 1]], 'text' => 'Big Bang Reactors mine 1% more for every Star Forge you own.'],
    'copiesOfCopies' => ['name' => 'Copies of Copies', 'icon' => 'link', 'cost' => 3.5e21, 'type' => 'synergy', 'building' => 'realityPrinter', 'source' => 'multiverse', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['realityPrinter' => 1, 'multiverse' => 1]], 'text' => 'Reality Printers mine 1% more for every Multiverse Drill you own.'],
    'dronesAllTheWayDown' => ['name' => 'Drones All the Way Down', 'icon' => 'link', 'cost' => 4.5e22, 'type' => 'synergy', 'building' => 'infinityMine', 'source' => 'drone', 'per' => 0.01, 'every' => 10,
        'need' => ['owned' => ['infinityMine' => 1, 'drone' => 10]], 'text' => 'Infinity Mines mine 1% more for every 10 Mining Drones you own.'],
    'bigOreTheory' => ['name' => 'Big Ore Theory', 'icon' => 'books', 'cost' => 1e23, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
];
const HBHUB_MINER_CLICK_TYPES = ['click', 'clickPct', 'crit', 'auto']; // kept by Persistent Memory
const HBHUB_MINER_ETERNAL_TYPES = ['bulk', 'comet']; // kept by Eternal Blueprints

// Bought with Dark Matter, kept until a Big Crunch (or after it too, with Dark Echo). Long-Range Hot Keys doesn't change
// the game, only which buildings the 1-9 and 0 keys buy, and Auto Buy only buys in the page like a player would (its
// purchases come here as normal buy and upgrade actions), so both are only in spaceOreMiner.js. The one thing Auto Buy
// changes here: its buys ('auto' => true) can be 10 or 100 at once without Bulk Buying (see hbHubMinerApply()).
const HBHUB_MINER_DARK_UPGRADES = [
    'headStart' => ['name' => 'Head Start', 'icon' => 'headStart', 'cost' => 5, 'text' => 'Start every run with ' . HBHUB_MINER_HEAD_START . ' Mining Drones.'],
    'memory' => ['name' => 'Persistent Memory', 'icon' => 'chip', 'cost' => 40, 'text' => 'Keep your click upgrades when you Warp Jump.'],
    'darkEngines' => ['name' => 'Dark Engines', 'icon' => 'darkEngine', 'cost' => 100, 'text' => 'All buildings mine 50% more.'],
    'timeDilation' => ['name' => 'Time Dilation', 'icon' => 'hourglass', 'cost' => 250, 'text' => 'Earn twice as much while you\'re away.'],
    'darkPickaxe' => ['name' => 'Dark Pickaxe', 'icon' => 'darkPickaxe', 'cost' => 15, 'text' => 'Clicks mine ' . HBHUB_MINER_DARK_PICKAXE . ' times as much.'],
    'lateKeys' => ['name' => 'Long-Range Hot Keys', 'icon' => 'hotKeys', 'cost' => 2, 'text' => 'Adds a switch to the store (or press K) that makes the 1-9 and 0 keys buy your last ten buildings instead of your first ten.'],
    'autoBuy' => ['name' => 'Auto Buy', 'icon' => 'autoBuy', 'cost' => 30, 'text' => 'Adds a switch to the store that buys buildings and upgrades for you, whatever pays for itself soonest, and saves up for an upgrade when it\'s worth the wait.'],
    'physics' => ['name' => 'Cheaper Physics', 'icon' => 'chartDown', 'cost' => 750, 'text' => 'Each building costs 14% more than the one before instead of 15% (11% instead of 12% for Space Pickaxe Guys).'],
    'reactor' => ['name' => 'Dark Matter Reactor', 'icon' => 'radiation', 'cost' => 2500, 'text' => 'Every Dark Matter you\'ve earned makes everything mine '
        . HBHUB_MINER_DARK_BONUS_REACTOR * 100 . '% faster, instead of ' . HBHUB_MINER_DARK_BONUS * 100 . '%.'],
];

// Bought with Antimatter, kept forever. Comet Catcher, like Auto Buy, only catches in the page like a player would (its
// catches come here as normal comet actions), so it's only in spaceOreMiner.js.
const HBHUB_MINER_ANTI_UPGRADES = [
    'echo' => ['name' => 'Dark Echo', 'icon' => 'cycle', 'cost' => 1, 'text' => 'Keep your Dark Matter upgrades when you Big Crunch.'],
    'converter' => ['name' => 'Matter Converter', 'icon' => 'flask', 'cost' => 3, 'text' => 'Warp Jumps give twice as much Dark Matter.'],
    'leftovers' => ['name' => 'Big Bang Leftovers', 'icon' => 'orePile', 'cost' => 5, 'text' => 'Start every run with 10 million ore.'],
    'eternal' => ['name' => 'Eternal Blueprints', 'icon' => 'blueprint', 'cost' => 10, 'text' => 'Keep Bulk Buying, Max Buying and your comet upgrades when you Warp Jump.'],
    'cometCatcher' => ['name' => 'Comet Catcher', 'icon' => 'cometCatcher', 'cost' => 15, 'text' => 'Catches every Golden Comet for you while the game is open. Diamond Comets are still yours to catch.'],
    'annihilation' => ['name' => 'Annihilation Engines', 'icon' => 'annihilation', 'cost' => 25, 'text' => 'All buildings mine twice as much.'],
    'antiComets' => ['name' => 'Antimatter Comets', 'icon' => 'antiComet', 'cost' => 50, 'text' => 'Comet effects are twice as strong.'],
];

// The skill tree, bought with Strange Matter and kept forever. A skill can be learned once every skill in its 'after' is
// learned (the lines going left from it in the tree), so it grows to the right from the Strangelet on the left. 'at' is
// where it is in the tree: [column, row 0-4], one row per branch, and 'branch' its colour there: industry (buildings,
// at the top), hands (clicks), core (the trunk across the middle), sky (comets) and cosmos (the prestiges and the
// automatic switches, at the bottom). Each skill needs the one before it in its row, and some also need one from the
// row next to them (the strings between the branches), so the branches are tied together instead of running side by
// side: Industry and Cosmos start from Hands and Sky, and the Core needs both its neighbours twice on the way.
// 'type' says what it does, with 'times' (multiplied by it), 'value' (added) or nothing (switched on); see
// HBHUB_MINER_SKILL_NEUTRAL for the full list and hbHubMinerSkillEffects() for how they add up. The text is made from
// the numbers (hbHubMinerSkillText()), unless the skill has its own 'text'.
// - production: everything mines 'times' as much. buildings: the 'buildings' listed do. perSkill, perWarp, perCrunch,
//   owned: everything mines 'value' more for every skill learned, Warp Jump ever made that counted ('flights', see
//   hbHubMinerNewState()), Big Crunch ever made, building owned.
// - buildingUpgrade: a building's own upgrades are 'times' as strong (2 becomes 2 × times). crew: the crew upgrades are.
// - cost, upgradeCost: buildings, upgrades cost 'times' as much. start: every run starts with 'count' of each of the
//   'buildings'. startOre: with this much ore. keep: the ore upgrades of these 'types' are kept on a Warp Jump.
// - click: a click's own ore is 'times' as much (not the share of production). clickShare: like the clickPct upgrades.
//   crit: clicks can be critical without the Critical Strike Module, and 'value' more often. critPower: a critical
//   click's multiplier is 'times' as big. auto: 'value' more clicks a second.
// - cometWait, cometStay, cometPower, cometLength, luckyOre: comets' wait, time on screen, effect, how long the effect
//   lasts, a lucky comet's ore, times 'times'. diamond: Diamond Comets can come without Lucky Stars, 'value' more often.
// - darkGain, antiGain, strangeGain: what a Warp Jump, Big Crunch, Big Rip gives, times 'times'. darkBonus, antiBonus:
//   added to what each Dark Matter or Antimatter earned does. crunchDark: what a Big Crunch needs,
//   times 'times'. offline: the ore while away. keepAnti: the Antimatter upgrades are kept on a Big Rip.
// - autoWarp, autoCrunch: a switch that Warp Jumps or Big Crunches by itself (only in spaceOreMiner.js, which sends
//   them as normal warp and crunch actions, like Auto Buy).
const HBHUB_MINER_SKILLS = [
    // The trunk, across the middle (row 2), every other column
    'strangelet' => ['name' => 'Strangelet', 'icon' => 'strangelet', 'branch' => 'core', 'cost' => 1, 'at' => [0, 2], 'after' => [],
        'type' => 'production', 'times' => 3],
    'strangeCore' => ['name' => 'Strange Core', 'icon' => 'strangeCore', 'branch' => 'core', 'cost' => 3, 'at' => [3, 2], 'after' => ['strangelet'],
        'type' => 'production', 'times' => 3],
    'skillSynergy' => ['name' => 'Skill Synergy', 'icon' => 'books', 'branch' => 'core', 'cost' => 5, 'at' => [5, 2],
        'after' => ['strangeCore', 'ghostHands', 'cometFuel'], 'type' => 'perSkill', 'value' => 0.1],
    'strangeHeart' => ['name' => 'Heart of Strangeness', 'icon' => 'strangeHeart', 'branch' => 'core', 'cost' => 10, 'at' => [7, 2], 'after' => ['skillSynergy'],
        'type' => 'production', 'times' => 5],
    'frequentFlyer' => ['name' => 'Frequent Flyer Miles', 'icon' => 'medal', 'branch' => 'core', 'cost' => 15, 'at' => [9, 2], 'after' => ['strangeHeart'],
        'type' => 'perWarp', 'value' => 0.01,
        'text' => 'Everything mines 1% more for every Warp Jump you\'ve made that at least doubled the Dark Matter you\'d earned in that universe.'],
    'strangeStar' => ['name' => 'Strange Star', 'icon' => 'strangeStar', 'branch' => 'core', 'cost' => 30, 'at' => [11, 2],
        'after' => ['frequentFlyer', 'poltergeist', 'strangeFortune'], 'type' => 'production', 'times' => 10],
    'thinFabric' => ['name' => 'Thin Fabric', 'icon' => 'bigRip', 'branch' => 'core', 'cost' => 40, 'at' => [13, 2], 'after' => ['strangeStar'],
        'type' => 'strangeGain', 'times' => 1.5],
    'theory' => ['name' => 'Theory of Everything', 'icon' => 'theory', 'branch' => 'core', 'cost' => 400, 'at' => [15, 2],
        'after' => ['cosmicFactory', 'multiverseHand', 'thinFabric', 'cometKingdom', 'strangeUniverse'], 'type' => 'production', 'times' => 100],

    // Industry: the buildings (row 0), starting from Strange Touch
    'oddMachines' => ['name' => 'Odd Machines', 'icon' => 'rig', 'branch' => 'industry', 'cost' => 1, 'at' => [1, 0], 'after' => ['strangeTouch'],
        'type' => 'buildings', 'buildings' => ['pickaxe', 'drone', 'laser', 'rig', 'base'], 'times' => 10],
    'discount' => ['name' => 'Strange Discounts', 'icon' => 'chartDown', 'branch' => 'industry', 'cost' => 2, 'at' => [2, 0], 'after' => ['oddMachines'],
        'type' => 'cost', 'times' => 0.9],
    'middleMachines' => ['name' => 'Stranger Machines', 'icon' => 'station', 'branch' => 'industry', 'cost' => 2, 'at' => [3, 0], 'after' => ['discount'],
        'type' => 'buildings', 'buildings' => ['freighter', 'station', 'excavator', 'cracker', 'siphon'], 'times' => 5],
    'prefab' => ['name' => 'Prefab Colonies', 'icon' => 'base', 'branch' => 'industry', 'cost' => 3, 'at' => [4, 0], 'after' => ['middleMachines'],
        'type' => 'start', 'buildings' => ['pickaxe', 'drone', 'laser', 'rig', 'base'], 'count' => 25],
    'farMachines' => ['name' => 'Strangest Machines', 'icon' => 'dysonSwarm', 'branch' => 'industry', 'cost' => 4, 'at' => [5, 0], 'after' => ['prefab'],
        'type' => 'buildings', 'buildings' => ['forge', 'nebula', 'dyson', 'wormhole', 'multiverse'], 'times' => 3],
    'bargain' => ['name' => 'Bargain Hunter', 'icon' => 'scales', 'branch' => 'industry', 'cost' => 6, 'at' => [6, 0], 'after' => ['farMachines'],
        'type' => 'upgradeCost', 'times' => 0.5],
    'bonds' => ['name' => 'Strange Bonds', 'icon' => 'link', 'branch' => 'industry', 'cost' => 6, 'at' => [7, 0], 'after' => ['bargain', 'strangePull'],
        'type' => 'owned', 'value' => 0.001],
    'strangeMemory' => ['name' => 'Strange Memory', 'icon' => 'chip', 'branch' => 'industry', 'cost' => 10, 'at' => [8, 0], 'after' => ['bonds'],
        'type' => 'keep', 'types' => ['global', 'synergy'],
        'text' => 'Keep the upgrades that make all buildings mine more, and the ones that link two buildings, when you Warp Jump.'],
    'overclock' => ['name' => 'Overclocked Upgrades', 'icon' => 'starChart', 'branch' => 'industry', 'cost' => 10, 'at' => [9, 0], 'after' => ['strangeMemory'],
        'type' => 'buildingUpgrade', 'times' => 1.25],
    'selfReplicating' => ['name' => 'Self-Replicating Machines', 'icon' => 'dna', 'branch' => 'industry', 'cost' => 15, 'at' => [10, 0], 'after' => ['overclock'],
        'type' => 'start', 'buildings' => ['pickaxe', 'drone', 'laser', 'rig', 'base', 'freighter', 'station', 'excavator', 'cracker', 'siphon',
            'forge', 'nebula', 'dyson', 'wormhole', 'multiverse'], 'count' => 10],
    'crewSpirit' => ['name' => 'Crew Spirit', 'icon' => 'buddies', 'branch' => 'industry', 'cost' => 15, 'at' => [11, 0], 'after' => ['selfReplicating'],
        'type' => 'crew', 'times' => 10],
    'perfectMemory' => ['name' => 'Perfect Memory', 'icon' => 'blueprint', 'branch' => 'industry', 'cost' => 25, 'at' => [12, 0], 'after' => ['crewSpirit'],
        'type' => 'keep', 'types' => ['building', 'crew'],
        'text' => 'Keep every building\'s own upgrades, and the Space Pickaxe Guys\' crew upgrades, when you Warp Jump.'],
    'massProduction' => ['name' => 'Mass Production', 'icon' => 'crates', 'branch' => 'industry', 'cost' => 25, 'at' => [13, 0],
        'after' => ['perfectMemory', 'strangeGravity'], 'type' => 'cost', 'times' => 0.8],
    'cosmicFactory' => ['name' => 'Cosmic Factory', 'icon' => 'starForge', 'branch' => 'industry', 'cost' => 60, 'at' => [14, 0], 'after' => ['massProduction'],
        'type' => 'production', 'times' => 10],

    // Hands: clicking (row 1)
    'strangeTouch' => ['name' => 'Strange Touch', 'icon' => 'neutronFist', 'branch' => 'hands', 'cost' => 1, 'at' => [1, 1], 'after' => ['strangelet'],
        'type' => 'click', 'times' => 10],
    'quarkKnuckles' => ['name' => 'Quark Knuckles', 'icon' => 'crit', 'branch' => 'hands', 'cost' => 2, 'at' => [2, 1], 'after' => ['strangeTouch'],
        'type' => 'crit', 'value' => 0.05,
        'text' => 'Clicks can be critical from the start of a run, even without the Critical Strike Module, and are 5% more likely to be.'],
    'ghostHands' => ['name' => 'Ghost Hands', 'icon' => 'robot', 'branch' => 'hands', 'cost' => 4, 'at' => [4, 1], 'after' => ['quarkKnuckles'],
        'type' => 'auto', 'value' => 5],
    'strangePull' => ['name' => 'Strange Pull', 'icon' => 'quantumHammer', 'branch' => 'hands', 'cost' => 6, 'at' => [6, 1], 'after' => ['ghostHands'],
        'type' => 'clickShare', 'value' => 0.05],
    'strangeCrits' => ['name' => 'Strange Crits', 'icon' => 'supernovaFist', 'branch' => 'hands', 'cost' => 10, 'at' => [8, 1], 'after' => ['strangePull'],
        'type' => 'critPower', 'times' => 2.5],
    'poltergeist' => ['name' => 'Poltergeist', 'icon' => 'robots', 'branch' => 'hands', 'cost' => 15, 'at' => [10, 1], 'after' => ['strangeCrits'],
        'type' => 'auto', 'value' => 10],
    'strangeGravity' => ['name' => 'Strange Gravity', 'icon' => 'gauntlet', 'branch' => 'hands', 'cost' => 25, 'at' => [12, 1], 'after' => ['poltergeist'],
        'type' => 'clickShare', 'value' => 0.1],
    'multiverseHand' => ['name' => 'Hand of the Multiverse', 'icon' => 'bigBang', 'branch' => 'hands', 'cost' => 50, 'at' => [14, 1], 'after' => ['strangeGravity'],
        'type' => 'clickShare', 'value' => 0.25],

    // Sky: comets (row 3), in the same columns as Hands
    'strangeSkies' => ['name' => 'Strange Skies', 'icon' => 'radar', 'branch' => 'sky', 'cost' => 1, 'at' => [1, 3], 'after' => ['strangelet'],
        'type' => 'cometWait', 'times' => 0.75],
    'slowComets' => ['name' => 'Slow Comets', 'icon' => 'magnet', 'branch' => 'sky', 'cost' => 2, 'at' => [2, 3], 'after' => ['strangeSkies'],
        'type' => 'cometStay', 'times' => 2],
    'cometFuel' => ['name' => 'Comet Fuel', 'icon' => 'antiComet', 'branch' => 'sky', 'cost' => 3, 'at' => [4, 3], 'after' => ['slowComets'],
        'type' => 'cometPower', 'times' => 2],
    'strangeDiamonds' => ['name' => 'Strange Diamonds', 'icon' => 'diamond', 'branch' => 'sky', 'cost' => 6, 'at' => [6, 3], 'after' => ['cometFuel'],
        'type' => 'diamond', 'value' => 0.05,
        'text' => 'Diamond Comets can show up even without Lucky Stars, and 5% more often.'],
    'lingering' => ['name' => 'Lingering Comets', 'icon' => 'cometTail', 'branch' => 'sky', 'cost' => 10, 'at' => [8, 3], 'after' => ['strangeDiamonds'],
        'type' => 'cometLength', 'times' => 2],
    'strangeFortune' => ['name' => 'Strange Fortune', 'icon' => 'sparkles', 'branch' => 'sky', 'cost' => 15, 'at' => [10, 3], 'after' => ['lingering'],
        'type' => 'luckyOre', 'times' => 5],
    'cometStorm' => ['name' => 'Comet Storm', 'icon' => 'comet', 'branch' => 'sky', 'cost' => 25, 'at' => [12, 3], 'after' => ['strangeFortune'],
        'type' => 'cometWait', 'times' => 0.5],
    'cometKingdom' => ['name' => 'Comet Kingdom', 'icon' => 'crown', 'branch' => 'sky', 'cost' => 50, 'at' => [14, 3], 'after' => ['cometStorm'],
        'type' => 'cometPower', 'times' => 3],

    // Cosmos: Warp Jumps, Big Crunches and Big Rips (row 4), starting from Strange Skies
    'darkCatalyst' => ['name' => 'Dark Catalyst', 'icon' => 'darkEngine', 'branch' => 'cosmos', 'cost' => 1, 'at' => [1, 4], 'after' => ['strangeSkies'],
        'type' => 'darkGain', 'times' => 2],
    'antiEcho' => ['name' => 'Antimatter Echo', 'icon' => 'cycle', 'branch' => 'cosmos', 'cost' => 2, 'at' => [2, 4], 'after' => ['darkCatalyst'],
        'type' => 'keepAnti', 'text' => 'Keep your Antimatter upgrades when you Big Rip. With Dark Echo, your Dark Matter upgrades are kept too.'],
    'timeLoop' => ['name' => 'Time Loop', 'icon' => 'hourglass', 'branch' => 'cosmos', 'cost' => 2, 'at' => [3, 4], 'after' => ['antiEcho'],
        'type' => 'offline', 'times' => 4],
    'denseDark' => ['name' => 'Dense Dark Matter', 'icon' => 'radiation', 'branch' => 'cosmos', 'cost' => 3, 'at' => [4, 4], 'after' => ['timeLoop'],
        'type' => 'darkBonus', 'value' => 0.025],
    'antiCatalyst' => ['name' => 'Antimatter Catalyst', 'icon' => 'flask', 'branch' => 'cosmos', 'cost' => 4, 'at' => [5, 4], 'after' => ['denseDark'],
        'type' => 'antiGain', 'times' => 2],
    'universeCollector' => ['name' => 'Universe Collector', 'icon' => 'abacus', 'branch' => 'cosmos', 'cost' => 5, 'at' => [6, 4], 'after' => ['antiCatalyst'],
        'type' => 'perCrunch', 'value' => 0.05],
    'shallowUniverse' => ['name' => 'Shallow Universe', 'icon' => 'bigCrunch', 'branch' => 'cosmos', 'cost' => 6, 'at' => [7, 4],
        'after' => ['universeCollector', 'strangeDiamonds'], 'type' => 'crunchDark', 'times' => 0.5],
    'strangeLeftovers' => ['name' => 'Strange Leftovers', 'icon' => 'orePile', 'branch' => 'cosmos', 'cost' => 8, 'at' => [8, 4], 'after' => ['shallowUniverse'],
        'type' => 'startOre', 'value' => 1e12],
    'autoWarp' => ['name' => 'Autopilot', 'icon' => 'autoWarp', 'branch' => 'cosmos', 'cost' => 10, 'at' => [9, 4], 'after' => ['strangeLeftovers'],
        'type' => 'autoWarp',
        'text' => 'Adds a switch to the Dark Matter view that Warp Jumps by itself whenever a jump would multiply the Dark Matter you\'ve earned by at least the number you pick (4 to start with).'],
    'denseAnti' => ['name' => 'Dense Antimatter', 'icon' => 'annihilation', 'branch' => 'cosmos', 'cost' => 10, 'at' => [10, 4], 'after' => ['autoWarp'],
        'type' => 'antiBonus', 'value' => 0.05],
    'strangeCatalyst' => ['name' => 'Strange Catalyst', 'icon' => 'strangeFlask', 'branch' => 'cosmos', 'cost' => 15, 'at' => [11, 4], 'after' => ['denseAnti'],
        'type' => 'strangeGain', 'times' => 2],
    'autoCrunch' => ['name' => 'Auto Crunch', 'icon' => 'autoCrunch', 'branch' => 'cosmos', 'cost' => 20, 'at' => [12, 4], 'after' => ['strangeCatalyst'],
        'type' => 'autoCrunch',
        'text' => 'Adds a switch to the Antimatter view that Big Crunches by itself whenever a crunch would multiply the Antimatter you\'ve earned by at least the number you pick (4 to start with).'],
    'antimatterFlood' => ['name' => 'Antimatter Flood', 'icon' => 'antiFlood', 'branch' => 'cosmos', 'cost' => 25, 'at' => [13, 4],
        'after' => ['autoCrunch', 'cometStorm'], 'type' => 'antiGain', 'times' => 3],
    'strangeUniverse' => ['name' => 'Strange Universe', 'icon' => 'galaxy', 'branch' => 'cosmos', 'cost' => 60, 'at' => [14, 4], 'after' => ['antimatterFlood'],
        'type' => 'strangeGain', 'times' => 3],
];

// What each kind of skill does with none of it learned, which hbHubMinerSkillEffects() starts from: 1 for 'times'
// (multiplied together), 0 for 'value' (added together), false for the switches, and buildings (id => times), start
// (id => how many) and keep (upgrade types) for the three with more to them. The keys are every 'type' there is.
const HBHUB_MINER_SKILL_NEUTRAL = [
    'production' => 1, 'perSkill' => 0, 'perWarp' => 0, 'perCrunch' => 0, 'owned' => 0, 'buildingUpgrade' => 1, 'crew' => 1,
    'cost' => 1, 'upgradeCost' => 1, 'startOre' => 0,
    'click' => 1, 'clickShare' => 0, 'crit' => 0, 'critPower' => 1, 'auto' => 0,
    'cometWait' => 1, 'cometStay' => 1, 'cometPower' => 1, 'cometLength' => 1, 'luckyOre' => 1, 'diamond' => 0,
    'darkGain' => 1, 'antiGain' => 1, 'strangeGain' => 1, 'darkBonus' => 0, 'antiBonus' => 0,
    'crunchDark' => 1, 'offline' => 1,
    'keepAnti' => false, 'autoWarp' => false, 'autoCrunch' => false,
    'buildings' => [], 'start' => [], 'keep' => [],
];

// Achievements: done once 'stat' reaches 'at'. The stats are in hbHubMinerStat().
const HBHUB_MINER_ACHIEVEMENTS = [
    'click1' => ['name' => 'Rock Bottom', 'text' => 'Click the asteroid for the first time.', 'stat' => 'clicks', 'at' => 1],
    'click100' => ['name' => 'Tapping Away', 'text' => 'Click the asteroid 100 times.', 'stat' => 'clicks', 'at' => 100],
    'click1000' => ['name' => 'Click 1,000 Times', 'text' => 'Click the asteroid 1,000 times.', 'stat' => 'clicks', 'at' => 1000],
    'click10000' => ['name' => 'Finger of Steel', 'text' => 'Click the asteroid 10,000 times.', 'stat' => 'clicks', 'at' => 10000],
    'click50000' => ['name' => 'Unstoppable Finger', 'text' => 'Click the asteroid 50,000 times.', 'stat' => 'clicks', 'at' => 50000],
    'crit1' => ['name' => 'Critical Hit', 'text' => 'Land a critical click.', 'stat' => 'crits', 'at' => 1],
    'ore1k' => ['name' => 'Pocket Change', 'text' => 'Mine 1,000 ore in total.', 'stat' => 'allOre', 'at' => 1e3],
    'ore1m' => ['name' => '1 Million Ore', 'text' => 'Mine 1 million ore in total.', 'stat' => 'allOre', 'at' => 1e6],
    'ore1b' => ['name' => 'Rock Star', 'text' => 'Mine 1 billion ore in total.', 'stat' => 'allOre', 'at' => 1e9],
    'ore1t' => ['name' => 'Trillion Tonnes', 'text' => 'Mine 1 trillion ore in total.', 'stat' => 'allOre', 'at' => 1e12],
    'ore1qa' => ['name' => 'Galactic Hoarder', 'text' => 'Mine 1 quadrillion ore in total.', 'stat' => 'allOre', 'at' => 1e15],
    'ore1qi' => ['name' => 'Out of This World', 'text' => 'Mine 1 quintillion ore in total.', 'stat' => 'allOre', 'at' => 1e18],
    'ore1sx' => ['name' => 'Ore-some', 'text' => 'Mine 1 sextillion ore in total.', 'stat' => 'allOre', 'at' => 1e21],
    'rate10' => ['name' => 'Side Hustle', 'text' => 'Mine 10 ore per second.', 'stat' => 'rate', 'at' => 10],
    'rate1k' => ['name' => 'Small Business', 'text' => 'Mine 1,000 ore per second.', 'stat' => 'rate', 'at' => 1e3],
    'rate1m' => ['name' => 'Mega Corp', 'text' => 'Mine 1 million ore per second.', 'stat' => 'rate', 'at' => 1e6],
    'rate1b' => ['name' => 'Ore Tsunami', 'text' => 'Mine 1 billion ore per second.', 'stat' => 'rate', 'at' => 1e9],
    'rate1t' => ['name' => 'Ore Supernova', 'text' => 'Mine 1 trillion ore per second.', 'stat' => 'rate', 'at' => 1e12],
    'pickaxe1' => ['name' => 'Hired Help', 'text' => 'Hire a Space Pickaxe Guy.', 'stat' => 'owned:pickaxe', 'at' => 1],
    'drone1' => ['name' => 'First Drone', 'text' => 'Buy a Mining Drone.', 'stat' => 'owned:drone', 'at' => 1],
    'pickaxe100' => ['name' => 'Pickaxe Army', 'text' => 'Have 100 Space Pickaxe Guys.', 'stat' => 'owned:pickaxe', 'at' => 100],
    'drone100' => ['name' => 'The Swarm', 'text' => 'Have 100 Mining Drones.', 'stat' => 'owned:drone', 'at' => 100],
    'siphon1' => ['name' => 'Own a Black Hole', 'text' => 'Buy a Black Hole Siphon.', 'stat' => 'owned:siphon', 'at' => 1],
    'forge1' => ['name' => 'Star Power', 'text' => 'Buy a Star Forge.', 'stat' => 'owned:forge', 'at' => 1],
    'multiverse1' => ['name' => 'Next Door Neighbours', 'text' => 'Buy a Multiverse Drill.', 'stat' => 'owned:multiverse', 'at' => 1],
    'timeQuarry1' => ['name' => 'Ahead of Time', 'text' => 'Buy a Time Quarry.', 'stat' => 'owned:timeQuarry', 'at' => 1],
    'infinityMine1' => ['name' => 'To Infinity', 'text' => 'Buy an Infinity Mine.', 'stat' => 'owned:infinityMine', 'at' => 1],
    'all1' => ['name' => 'Full House', 'text' => 'Have one of every building.', 'stat' => 'ownedAll', 'at' => 1],
    'all25' => ['name' => 'Collector', 'text' => 'Have 25 of every building.', 'stat' => 'ownedAll', 'at' => 25],
    'buildings100' => ['name' => 'Industrial Revolution', 'text' => 'Have 100 buildings.', 'stat' => 'buildings', 'at' => 100],
    'buildings500' => ['name' => 'Megastructure', 'text' => 'Have 500 buildings.', 'stat' => 'buildings', 'at' => 500],
    'buildings1000' => ['name' => 'Planet-Sized Factory', 'text' => 'Have 1,000 buildings.', 'stat' => 'buildings', 'at' => 1000],
    'upgrades10' => ['name' => 'Tinkerer', 'text' => 'Have 10 upgrades.', 'stat' => 'upgrades', 'at' => 10],
    'upgrades40' => ['name' => 'Mad Scientist', 'text' => 'Have 40 upgrades.', 'stat' => 'upgrades', 'at' => 40],
    'upgrades75' => ['name' => 'Gadget Galaxy', 'text' => 'Have 75 upgrades.', 'stat' => 'upgrades', 'at' => 75],
    'comet1' => ['name' => 'Shooting Star', 'text' => 'Catch a Golden Comet.', 'stat' => 'comets', 'at' => 1],
    'comet10' => ['name' => 'Comet Chaser', 'text' => 'Catch 10 comets.', 'stat' => 'comets', 'at' => 10],
    'comet50' => ['name' => 'Halley\'s Hero', 'text' => 'Catch 50 comets.', 'stat' => 'comets', 'at' => 50],
    'comet100' => ['name' => 'Comet Collector', 'text' => 'Catch 100 comets.', 'stat' => 'comets', 'at' => 100],
    'diamond1' => ['name' => 'Shine Bright', 'text' => 'Catch a Diamond Comet.', 'stat' => 'diamonds', 'at' => 1],
    'warp1' => ['name' => 'Warp Speed', 'text' => 'Warp Jump to a new galaxy.', 'stat' => 'warps', 'at' => 1],
    'warp5' => ['name' => 'Frequent Flyer', 'text' => 'Warp Jump 5 times.', 'stat' => 'warps', 'at' => 5],
    'warp25' => ['name' => 'Galaxy Hopper', 'text' => 'Warp Jump 25 times.', 'stat' => 'warps', 'at' => 25],
    'warp100' => ['name' => 'Lost Count', 'text' => 'Warp Jump 100 times.', 'stat' => 'warps', 'at' => 100],
    'crunch1' => ['name' => 'Big Crunch', 'text' => 'Collapse the universe with a Big Crunch.', 'stat' => 'crunches', 'at' => 1],
    'crunch5' => ['name' => 'Universe Recycler', 'text' => 'Big Crunch 5 times.', 'stat' => 'crunches', 'at' => 5],
    'crunch25' => ['name' => 'Universe Factory', 'text' => 'Big Crunch 25 times.', 'stat' => 'crunches', 'at' => 25],
    'rip1' => ['name' => 'Torn Apart', 'text' => 'Tear the multiverse apart with a Big Rip.', 'stat' => 'rips', 'at' => 1],
    'rip5' => ['name' => 'Fabric Softener', 'text' => 'Big Rip 5 times.', 'stat' => 'rips', 'at' => 5],
    'rip25' => ['name' => 'Reality Shredder', 'text' => 'Big Rip 25 times.', 'stat' => 'rips', 'at' => 25],
    'skill1' => ['name' => 'Strange Student', 'text' => 'Learn a skill in the skill tree.', 'stat' => 'skills', 'at' => 1],
    'skill25' => ['name' => 'Halfway Strange', 'text' => 'Learn 25 skills.', 'stat' => 'skills', 'at' => 25],
    'skillAll' => ['name' => 'Know-It-All', 'text' => 'Learn every skill in the skill tree.', 'stat' => 'allSkills', 'at' => 1],
    // The endgame: Anomalies (alone or in a stack counts, see hbHubMinerAnomalyDone()) and the Cosmic Engine
    'anomaly1' => ['name' => 'Something\'s Off', 'text' => 'Finish an Anomaly.', 'stat' => 'anomalies', 'at' => 1],
    'tier1All' => ['name' => 'Ripple Effect', 'text' => 'Finish every Tier I Anomaly.', 'stat' => 'tier:1', 'at' => 4],
    'tier2All' => ['name' => 'Torn to Pieces', 'text' => 'Finish every Tier II Anomaly.', 'stat' => 'tier:2', 'at' => 5],
    'nakedSingularity' => ['name' => 'Nothing to Hide', 'text' => 'Finish Naked Singularity.', 'stat' => 'anomaly:nakedSingularity', 'at' => 1],
    'ironUniverse' => ['name' => 'Iron Will', 'text' => 'Finish Iron Universe.', 'stat' => 'anomaly:ironUniverse', 'at' => 1],
    'handsOnly' => ['name' => 'Hands Down', 'text' => 'Finish Hands Only.', 'stat' => 'anomaly:handsOnly', 'at' => 1],
    'gauntlet' => ['name' => 'Gauntlet Runner', 'text' => 'Finish The Gauntlet.', 'stat' => 'anomaly:gauntlet', 'at' => 1],
    'tier3All' => ['name' => 'Singular', 'text' => 'Finish every Tier III Anomaly.', 'stat' => 'tier:3', 'at' => 4],
    'stack2' => ['name' => 'Double Trouble', 'text' => 'Finish a stack of 2 Anomalies.', 'stat' => 'stack', 'at' => 2],
    'stack4' => ['name' => 'Four of a Kind', 'text' => 'Finish a stack of 4 Anomalies.', 'stat' => 'stack', 'at' => 4],
    'stack6' => ['name' => 'Stacked Deck', 'text' => 'Finish a stack of 6 Anomalies.', 'stat' => 'stack', 'at' => 6],
    'weekly1' => ['name' => 'Weekly Worker', 'text' => 'Finish the weekly Anomaly.', 'stat' => 'weeklies', 'at' => 1],
    'lightHour' => ['name' => 'Faster Than Light', 'text' => 'Finish Speed of Light in under an hour.', 'stat' => 'lightHour', 'at' => 1],
    'engine1' => ['name' => 'Crew Member', 'text' => 'Give the Cosmic Engine an Engine Part.', 'stat' => 'engineGiven', 'at' => 1],
    'engine1000' => ['name' => 'Chief Engineer', 'text' => 'Give the Cosmic Engine 1,000 Engine Parts.', 'stat' => 'engineGiven', 'at' => 1000],
    'shiftDay' => ['name' => 'Full Shift', 'text' => 'Do all of a day\'s Engine Shifts.', 'stat' => 'shiftDays', 'at' => 1],
];

// ----- The Cosmic Engine. Anyone with a Warp Jump can give Engine Parts: ore from their run (HBHUB_MINER_ENGINE_ORE_PARTS
// a day at most, the first for HBHUB_MINER_ENGINE_ORE_PART and each next one that day ten times the one before, so
// giving ore is a small daily thing however much a player has), unspent Strange Matter
// (HBHUB_MINER_ENGINE_STRANGE_PART for a part, as many as they like: it costs nothing to production, as the bonus counts
// Strange Matter earned, not what's left) and Engine Shifts. The parts go into the stage being built; once it has its
// cost and its lock is met, it's done, and its perk works for everyone for good. Each stage's 'perk' is a 'type' (the
// keys of HBHUB_MINER_ENGINE_NEUTRAL, plus badge, skin, news, title and ignition, which aren't numbers in the rules)
// with 'value' (offline: the share mined while away, the highest counts; cometStay: ms added; diamond: added to the
// Diamond Comet chance) or 'times' (multiplied together). 'lock' is null, or (engine.php checks them,
// hbHubMinerEngineLock()):
// - contributors: 'count' different players have given parts to this Engine. section: to this stage's section. Parts
//   keep coming while either waits, and go on to the next stages once it's met, as it needs more players, not fewer
//   parts. With
//   'rising': only players who haven't learned every skill yet count (saveSkills).
// - source: 'count' different players have each given 'parts' parts from 'source' (HBHUB_MINER_ENGINE_SOURCE_*) to this
//   stage's section. Parts keep coming while it waits, like the two above.
// - anomalies: someone has finished each of the 'count' Anomalies of 'tier' (HBHUB_MINER_ANOMALIES). players: 'count'
//   different players have each finished all of 'anomalies'. each: each Anomaly of 'tier' has been finished by 'count'
//   players. stack: someone has finished a stack of 'count' Anomalies. weekly: 'count' players finished the weekly
//   Anomaly in the same week. time: 'count' players have finished 'anomaly' on its own in under 'ms'. An Anomaly
//   finished in a stack counts as finished (not for time). Once one of these is full, the stage
//   waits and takes no more parts from ore, Strange Matter or Shifts (the Engine is "waiting", engineOpen in the save),
//   so they don't pile up for the stages after it; parts from Anomalies always go in (they're what opens it), and
//   what's over goes on to the next stage once it's done.
// Every lock only counts what was done for the Engine being built, so each Engine has to earn them again.
// 'cost' is in parts, and the admin panel can change it (admin/engine.php, kept in engineStageCosts). Ore counts less
// in the later sections (HBHUB_MINER_ENGINE_ORE_SHARE). The last stage, Ignition, has to be full within
// HBHUB_MINER_ENGINE_COUNTDOWN_MS of the one before it being done, or half its parts are lost and the time starts over
// (engine.php, hbHubMinerEngineDrain()); once it's done, everything mines HBHUB_MINER_IGNITION_TIMES as much for
// HBHUB_MINER_IGNITION_MS (hbHubMinerIgnition()).
//
// There's more than one Engine (HBHUB_MINER_ENGINES): once one is ignited, the next starts straight away (engine.php,
// hbHubMinerEngineFinish()), with its own name and sections. Every Engine has HBHUB_MINER_ENGINE_STAGE_TOTAL stages,
// and HBHUB_MINER_ENGINE_STAGES has all of them in a row (Engine #1's first, then #2's, see hbHubMinerEngineStages()),
// so a save's engineStage counts the stages done of every Engine so far, and the perks of all of them work together.
// The Engines after the first have perks that are mostly for looks or ease, and only a little more production, so
// power doesn't keep stacking up. Perk types that aren't numbers in the rules (the script and pages use them):
// badge (the profile badge 'badge' with 'icon' for who gave parts to that Engine), skin, news (ticker lines, and
// 'sounds' for the parts: engine or forge), title, ignition, hall (finished Engines in the Engine view), again (a
// button to go into the last Anomaly again), cometBell (a sound and a glow when a comet comes), weeklyPreview (next
// week's weekly Anomaly), cometTrail (comets take the skin's colour), sparks (clicking throws sparks), backdrops
// (HBHUB_MINER_BACKDROPS), medals (weekly Anomaly medals on profiles) and pace (the best time in the Anomaly banner).
// Ones that are: shiftSwap (Shifts that can be swapped a day, hbHubMinerShiftSwap()), oreParts (more parts from ore a
// day) and oreFloor (the fewest quarter parts a part from ore is worth).
const HBHUB_MINER_ENGINE_SOURCE_ORE = 1; // contributionSource in HBHub-MinerContributions
const HBHUB_MINER_ENGINE_SOURCE_STRANGE = 2;
const HBHUB_MINER_ENGINE_SOURCE_SHIFT = 3;
const HBHUB_MINER_ENGINE_SOURCE_ANOMALY = 4; // with the Anomaly in contributionAnomaly
const HBHUB_MINER_ENGINE_ORE_PART = 1e13; // ore for the day's first part from ore
const HBHUB_MINER_ENGINE_ORE_PARTS = 5; // parts from ore a day
const HBHUB_MINER_ENGINE_STRANGE_PART = 10; // Strange Matter for a part
const HBHUB_MINER_ENGINE_STAGE_TOTAL = 25; // stages in each Engine
const HBHUB_MINER_DAY_MS = 86400000; // the daily limit and the Shifts go by UTC days: Math.floor(tick / this)
// The Engines, by engineNumber in HBHub-MinerEngines: what each is called (with 'the' in front in sentences), its
// sections (each stage's 'section') and the badge for whoever gave parts to its last stage before it was done ('crew',
// see hbHubMinerEngineCrews()). One that isn't here yet never starts: the last one waits, done, until it's added.
const HBHUB_MINER_ENGINES = [
    1 => ['name' => 'Cosmic Engine', 'sections' => ['A' => 'Foundation', 'B' => 'Power', 'C' => 'Drive', 'D' => 'Navigation', 'E' => 'Ignition'],
        'crew' => ['name' => 'Ignition', 'icon' => '🚀']],
    2 => ['name' => 'Starforge', 'sections' => ['A' => 'Anvil', 'B' => 'Bellows', 'C' => 'Crucible', 'D' => 'Hammer', 'E' => 'Quench'],
        'crew' => ['name' => 'First Light', 'icon' => '🌟']],
];
// What a part given from ore is worth in each section, in quarter parts: a whole one at first, half in Drive and a
// quarter from Navigation on. What's left of a part is kept in the save (engineOreRest) until it makes a whole one.
const HBHUB_MINER_ENGINE_ORE_SHARE = ['A' => 4, 'B' => 4, 'C' => 2, 'D' => 1, 'E' => 1];
const HBHUB_MINER_ENGINE_ORE_QUARTERS = 4;
const HBHUB_MINER_ENGINE_COUNTDOWN_MS = 604800000; // 7 days, to fill Ignition in
const HBHUB_MINER_ENGINE_DRAIN = 0.5; // of Ignition's parts kept when its time runs out
const HBHUB_MINER_IGNITION_MS = 259200000; // 72 hours of HBHUB_MINER_IGNITION_TIMES once the Engine is ignited
const HBHUB_MINER_IGNITION_TIMES = 2;
const HBHUB_MINER_ENGINE_STAGES = [ // stage 1 first
    ['name' => 'Scaffolding', 'section' => 'A', 'cost' => 500, 'lock' => null,
        'perk' => ['type' => 'offline', 'value' => 0.075, 'text' => 'While you\'re away, your machines mine 7.5% of what they would, instead of 5%.']],
    ['name' => 'Cargo Bay', 'section' => 'A', 'cost' => 1000, 'lock' => null,
        'perk' => ['type' => 'cometStay', 'value' => 2000, 'text' => 'Comets stay on screen 2 seconds longer.']],
    ['name' => 'Fuel Lines', 'section' => 'A', 'cost' => 1750, 'lock' => null,
        'perk' => ['type' => 'cometWait', 'times' => 0.9, 'text' => 'The wait between comets is 10% shorter.']],
    ['name' => 'Crew Deck', 'section' => 'A', 'cost' => 2500, 'lock' => null,
        'perk' => ['type' => 'badge', 'badge' => 'Engine Crew', 'icon' => '⚙️', 'text' => 'Everyone who has given parts to the Engine gets the Engine Crew badge on their profile.']],
    ['name' => 'Foundation Seal', 'section' => 'A', 'cost' => 3500,
        'lock' => ['type' => 'contributors', 'count' => 10, 'text' => '10 different players have given parts'],
        'perk' => ['type' => 'production', 'times' => 1.05, 'text' => 'Everything mines 5% more.']],
    ['name' => 'Reactor Ring', 'section' => 'B', 'cost' => 5000, 'lock' => null,
        'perk' => ['type' => 'darkGain', 'times' => 1.1, 'text' => 'Warp Jumps give 10% more Dark Matter.']],
    ['name' => 'Coolant Loops', 'section' => 'B', 'cost' => 6500, 'lock' => null,
        'perk' => ['type' => 'offline', 'value' => 0.1, 'text' => 'While you\'re away, your machines mine 10% of what they would, instead of 7.5%.']],
    ['name' => 'Capacitor Banks', 'section' => 'B', 'cost' => 8000,
        'lock' => ['type' => 'anomalies', 'tier' => 1, 'count' => 4, 'text' => 'Someone has finished each Tier I Anomaly'],
        'perk' => ['type' => 'cometLength', 'times' => 1.1, 'text' => 'Golden Comet effects last 10% longer.']],
    ['name' => 'Power Grid', 'section' => 'B', 'cost' => 10000, 'lock' => null,
        'perk' => ['type' => 'skin', 'text' => 'The Engine Steel asteroid skin, for everyone.']],
    ['name' => 'Power Seal', 'section' => 'B', 'cost' => 12500,
        'lock' => ['type' => 'anomalies', 'tier' => 2, 'count' => 5, 'text' => 'Someone has finished each Tier II Anomaly'],
        'perk' => ['type' => 'production', 'times' => 1.1, 'text' => 'Everything mines 10% more.']],
    ['name' => 'Thruster Mounts', 'section' => 'C', 'cost' => 15000, 'lock' => null,
        'perk' => ['type' => 'antiGain', 'times' => 1.1, 'text' => 'Big Crunches give 10% more Antimatter.']],
    ['name' => 'Fuel Injectors', 'section' => 'C', 'cost' => 18000, 'lock' => null,
        'perk' => ['type' => 'cost', 'times' => 0.95, 'text' => 'Buildings cost 5% less.']],
    ['name' => 'Plasma Chamber', 'section' => 'C', 'cost' => 22000,
        'lock' => ['type' => 'players', 'anomalies' => ['speedOfLight'], 'count' => 5, 'text' => '5 different players have finished Speed of Light'],
        'perk' => ['type' => 'diamond', 'value' => 0.005, 'text' => 'Diamond Comets come a little more often: 5.5% of comets instead of 5%.']],
    ['name' => 'Exhaust Nozzle', 'section' => 'C', 'cost' => 26000, 'lock' => null,
        'perk' => ['type' => 'news', 'sounds' => 'engine', 'text' => 'New lines in the news ticker, and the Engine makes sounds when it gets parts.']],
    ['name' => 'Drive Seal', 'section' => 'C', 'cost' => 30000,
        'lock' => ['type' => 'players', 'anomalies' => ['nakedSingularity'], 'count' => 1, 'text' => 'Someone has finished Naked Singularity'],
        'perk' => ['type' => 'production', 'times' => 1.1, 'text' => 'Everything mines 10% more.']],
    ['name' => 'Star Charts', 'section' => 'D', 'cost' => 35000, 'lock' => null,
        'perk' => ['type' => 'offline', 'value' => 0.125, 'text' => 'While you\'re away, your machines mine 12.5% of what they would, instead of 10%.']],
    ['name' => 'Gyroscopes', 'section' => 'D', 'cost' => 41000, 'lock' => null,
        'perk' => ['type' => 'click', 'times' => 1.25, 'text' => 'Clicks mine 25% more.']],
    ['name' => 'Navigation Computer', 'section' => 'D', 'cost' => 48000,
        'lock' => ['type' => 'stack', 'count' => 4, 'text' => 'Someone has finished a stack of 4 Anomalies'],
        'perk' => ['type' => 'anomalyParts', 'times' => 1.1, 'text' => 'Anomalies give 10% more Engine Parts.']],
    ['name' => 'The Bridge', 'section' => 'D', 'cost' => 56000,
        'lock' => ['type' => 'players', 'anomalies' => ['ironUniverse', 'handsOnly'], 'count' => 3, 'text' => '3 different players have finished both Iron Universe and Hands Only'],
        'perk' => ['type' => 'skin', 'text' => 'The animated Engine Core asteroid skin, for everyone.']],
    ['name' => 'Navigation Seal', 'section' => 'D', 'cost' => 65000,
        'lock' => ['type' => 'players', 'anomalies' => ['gauntlet'], 'count' => 3, 'text' => '3 different players have finished The Gauntlet'],
        'perk' => ['type' => 'production', 'times' => 1.15, 'text' => 'Everything mines 15% more.']],
    ['name' => 'Spark Plugs', 'section' => 'E', 'cost' => 75000,
        'lock' => ['type' => 'section', 'count' => 20, 'text' => '20 different players have given parts to the Ignition section'],
        'perk' => ['type' => 'cometWait', 'times' => 0.9, 'text' => 'The wait between comets is another 10% shorter.']],
    ['name' => 'Ignition Coils', 'section' => 'E', 'cost' => 87000,
        'lock' => ['type' => 'stack', 'count' => 6, 'text' => 'Someone has finished a stack of 6 Anomalies'],
        'perk' => ['type' => 'upgradeCost', 'times' => 0.9, 'text' => 'Ore upgrades cost 10% less.']],
    ['name' => 'Countdown Clock', 'section' => 'E', 'cost' => 100000,
        'lock' => ['type' => 'weekly', 'count' => 10, 'text' => '10 players have finished the weekly Anomaly in the same week'],
        'perk' => ['type' => 'title', 'text' => 'Everyone who has given parts to the Engine gets the title "Engineer" on the leaderboard.']],
    ['name' => 'Launch Clamps', 'section' => 'E', 'cost' => 115000,
        'lock' => ['type' => 'each', 'tier' => 3, 'count' => 3, 'text' => 'Every Tier III Anomaly has been finished by at least 3 players'],
        'perk' => ['type' => 'production', 'times' => 1.25, 'text' => 'Everything mines 25% more.']],
    ['name' => 'IGNITION', 'section' => 'E', 'cost' => 150000, 'lock' => null,
        'perk' => ['type' => 'ignition', 'text' => 'Everything mines twice as much for 72 hours, and everyone who gave parts to Ignition gets the Ignition badge and asteroid skin for good.']],

    // Engine #2, the Starforge: a forge for making stars. Its costs are Engine #1's times 1.25 (a first guess, as
    // Engine #1 had less than a day of real data when they were set: tune them in the admin panel).
    ['name' => 'Bedrock', 'section' => 'A', 'cost' => 625, 'lock' => null,
        'perk' => ['type' => 'news', 'sounds' => 'forge', 'text' => 'New lines in the news ticker, and the Starforge rings like an anvil when it gets parts.']],
    ['name' => 'Anvil Plate', 'section' => 'A', 'cost' => 1250, 'lock' => null,
        'perk' => ['type' => 'cometStay', 'value' => 2000, 'text' => 'Comets stay on screen another 2 seconds longer.']],
    ['name' => 'Tool Racks', 'section' => 'A', 'cost' => 2200, 'lock' => null,
        'perk' => ['type' => 'again', 'text' => 'An "Again" button in the Anomalies, to go into the last Anomaly or stack you played in one click.']],
    ['name' => 'Forge Floor', 'section' => 'A', 'cost' => 3100, 'lock' => null,
        'perk' => ['type' => 'badge', 'badge' => 'Forge Crew', 'icon' => '🔨', 'text' => 'Everyone who has given parts to the Starforge gets the Forge Crew badge on their profile.']],
    ['name' => 'Anvil Seal', 'section' => 'A', 'cost' => 4400,
        'lock' => ['type' => 'contributors', 'count' => 15, 'text' => '15 different players have given parts'],
        'perk' => ['type' => 'cometBell', 'text' => 'A bell rings and the edge of the screen glows when a comet comes.']],
    ['name' => 'Air Intakes', 'section' => 'B', 'cost' => 6250, 'lock' => null,
        'perk' => ['type' => 'oreParts', 'value' => 1, 'text' => 'You can give one more part from ore a day.']],
    ['name' => 'Bellows', 'section' => 'B', 'cost' => 8100, 'lock' => null,
        'perk' => ['type' => 'offline', 'value' => 0.15, 'text' => 'While you\'re away, your machines mine 15% of what they would, instead of 12.5%.']],
    ['name' => 'Draft Vents', 'section' => 'B', 'cost' => 10000,
        'lock' => ['type' => 'each', 'tier' => 1, 'count' => 3, 'text' => 'Every Tier I Anomaly has been finished by at least 3 players'],
        'perk' => ['type' => 'skin', 'text' => 'The Ember asteroid skin, for everyone.']],
    ['name' => 'Heat Shields', 'section' => 'B', 'cost' => 12500, 'lock' => null,
        'perk' => ['type' => 'weeklyPreview', 'text' => 'The Anomalies show next week\'s weekly Anomaly too.']],
    ['name' => 'Bellows Seal', 'section' => 'B', 'cost' => 15600,
        'lock' => ['type' => 'each', 'tier' => 2, 'count' => 2, 'text' => 'Every Tier II Anomaly has been finished by at least 2 players'],
        'perk' => ['type' => 'production', 'times' => 1.05, 'text' => 'Everything mines 5% more.']],
    ['name' => 'Crucible Shell', 'section' => 'C', 'cost' => 18750, 'lock' => null,
        'perk' => ['type' => 'shiftSwap', 'value' => 1, 'text' => 'Once a day, you can swap one of your Engine Shifts that isn\'t done for another.']],
    ['name' => 'Slag Channels', 'section' => 'C', 'cost' => 22500,
        'lock' => ['type' => 'source', 'source' => HBHUB_MINER_ENGINE_SOURCE_SHIFT, 'parts' => 75, 'count' => 10,
            'text' => '10 different players have each given 75 parts from Engine Shifts to the Crucible section'],
        'perk' => ['type' => 'cometTrail', 'text' => 'Golden Comets take the colour of your asteroid skin.']],
    ['name' => 'Pour Spout', 'section' => 'C', 'cost' => 27500,
        'lock' => ['type' => 'time', 'anomaly' => 'speedOfLight', 'ms' => 3600000, 'count' => 1, 'text' => 'Someone has finished Speed of Light on its own in under an hour'],
        'perk' => ['type' => 'skin', 'text' => 'The animated Molten asteroid skin, for everyone.']],
    ['name' => 'Alloy Bins', 'section' => 'C', 'cost' => 32500, 'lock' => null,
        'perk' => ['type' => 'hall', 'text' => 'The Hall of Engines: every finished Engine, when it was done and who built it, in the Engine view.']],
    ['name' => 'Crucible Seal', 'section' => 'C', 'cost' => 37500,
        'lock' => ['type' => 'players', 'anomalies' => ['nakedSingularity', 'ironUniverse'], 'count' => 3, 'text' => '3 different players have finished both Naked Singularity and Iron Universe'],
        'perk' => ['type' => 'sparks', 'text' => 'Clicking the asteroid throws sparks (they can be turned off in the stats).']],
    ['name' => 'Hammer Head', 'section' => 'D', 'cost' => 43750, 'lock' => null,
        'perk' => ['type' => 'backdrops', 'text' => 'Backgrounds for the game to pick from in the stats.']],
    ['name' => 'Piston Arms', 'section' => 'D', 'cost' => 51250,
        'lock' => ['type' => 'section', 'count' => 10, 'rising' => true, 'text' => '10 different players who haven\'t learned every skill yet have given parts to the Hammer section'],
        'perk' => ['type' => 'oreFloor', 'value' => 2, 'text' => 'A part given from ore is always worth at least half a part.']],
    ['name' => 'Strike Plate', 'section' => 'D', 'cost' => 60000,
        'lock' => ['type' => 'stack', 'count' => 7, 'text' => 'Someone has finished a stack of 7 Anomalies'],
        'perk' => ['type' => 'title', 'text' => 'Everyone who has given parts to the Starforge gets the title "Forgemaster" on the leaderboard.']],
    ['name' => 'Shockwave Dampers', 'section' => 'D', 'cost' => 70000,
        'lock' => ['type' => 'time', 'anomaly' => 'nakedSingularity', 'ms' => 108000000, 'count' => 1, 'text' => 'Someone has finished Naked Singularity in under 30 hours'],
        'perk' => ['type' => 'skin', 'text' => 'The animated Starforged asteroid skin, for everyone.']],
    ['name' => 'Hammer Seal', 'section' => 'D', 'cost' => 81250,
        'lock' => ['type' => 'players', 'anomalies' => ['gauntlet'], 'count' => 5, 'text' => '5 different players have finished The Gauntlet'],
        'perk' => ['type' => 'production', 'times' => 1.05, 'text' => 'Everything mines 5% more.']],
    ['name' => 'Quench Tanks', 'section' => 'E', 'cost' => 93750,
        'lock' => ['type' => 'section', 'count' => 25, 'text' => '25 different players have given parts to the Quench section'],
        'perk' => ['type' => 'cometLength', 'times' => 1.1, 'text' => 'Golden Comet effects last another 10% longer.']],
    ['name' => 'Cooling Fins', 'section' => 'E', 'cost' => 108750,
        'lock' => ['type' => 'weekly', 'count' => 15, 'text' => '15 players have finished the weekly Anomaly in the same week'],
        'perk' => ['type' => 'medals', 'text' => 'The 3 fastest at each week\'s weekly Anomaly get gold, silver and bronze medals on their profile.']],
    ['name' => 'Tempering Ring', 'section' => 'E', 'cost' => 125000,
        'lock' => ['type' => 'time', 'anomaly' => 'gauntlet', 'ms' => 172800000, 'count' => 1, 'text' => 'Someone has finished The Gauntlet in under 48 hours'],
        'perk' => ['type' => 'pace', 'text' => 'In an Anomaly, the banner shows your best time to beat and whether you\'re ahead of it.']],
    ['name' => 'Master Mould', 'section' => 'E', 'cost' => 143750,
        'lock' => ['type' => 'each', 'tier' => 3, 'count' => 5, 'text' => 'Every Tier III Anomaly has been finished by at least 5 players'],
        'perk' => ['type' => 'production', 'times' => 1.05, 'text' => 'Everything mines 5% more.']],
    ['name' => 'FIRST LIGHT', 'section' => 'E', 'cost' => 187500, 'lock' => null,
        'perk' => ['type' => 'ignition', 'text' => 'The Starforge lights its first star: everything mines twice as much for 72 hours, and everyone who gave parts to First Light gets the First Light badge and asteroid skin for good.']],
];
// What the perks do with no stage done, which hbHubMinerEngineEffects() starts from
const HBHUB_MINER_ENGINE_NEUTRAL = ['offline' => HBHUB_MINER_OFFLINE_RATE, 'cometStay' => 0, 'cometWait' => 1, 'cometLength' => 1, 'production' => 1, 'darkGain' => 1,
    'antiGain' => 1, 'cost' => 1, 'diamond' => 0, 'click' => 1, 'anomalyParts' => 1, 'upgradeCost' => 1, 'oreParts' => 0, 'oreFloor' => 0, 'shiftSwap' => 0];

// Engine Shifts: HBHUB_MINER_SHIFT_COUNT tasks a day, the same for everyone (hbHubMinerShiftTasks()), for anyone with a
// Warp Jump while the Engine takes parts. Each is worth HBHUB_MINER_SHIFT_PARTS, or HBHUB_MINER_SHIFT_PARTS_ALL once all
// of them are done (the last one pays the difference). 'goal' is in what each counts: comets caught, Warp Jumps,
// buildings and ore upgrades bought, clicks, and ms of playing with the game open (not time away).
const HBHUB_MINER_SHIFTS = [
    'comets' => ['goal' => 5, 'text' => 'Catch 5 comets'],
    'warps' => ['goal' => 2, 'text' => 'Make 2 Warp Jumps'],
    'buildings' => ['goal' => 100, 'text' => 'Buy 100 buildings'],
    'upgrades' => ['goal' => 15, 'text' => 'Buy 15 upgrades'],
    'clicks' => ['goal' => 2000, 'text' => 'Click the asteroid 2,000 times'],
    'playing' => ['goal' => 1800000, 'text' => 'Mine for 30 minutes with the game open'],
];
const HBHUB_MINER_SHIFT_COUNT = 3;
const HBHUB_MINER_SHIFT_PARTS = 10;
const HBHUB_MINER_SHIFT_PARTS_ALL = 25;

// ----- Anomalies: challenge runs, each a universe of its own inside the save with one rule changed. One starts like a
// Big Rip that gives nothing (Dark Matter, Antimatter and their upgrades start over, apart from the ones Antimatter
// Echo and Dark Echo keep), with the skill tree, the Strange Matter bonus and the counts that grow the production
// (Warp Jumps, Big Crunches, comets) kept. A Tier III one ('fresh') starts from nothing instead: everything in
// HBHUB_MINER_ANOMALY_FRESH starts over too, so no skills, no Strange Matter bonus and no upgrades at all. It's finished
// once its 'goal' is reached in it: Antimatter earned, or the 'stat' it names (runOre: ore mined in one run,
// darkEarned: Dark Matter earned in a universe). It pays its 'parts' (or its tier's) to the Cosmic Engine the first
// time, or for a new best time, and HBHUB_MINER_ANOMALY_REPEAT of them otherwise. Every time it's finished, the
// player's own game also gets an Afterglow: HBHUB_MINER_AFTERGLOW_TIMES as much production for its 'afterglow' ms (or
// its tier's), which pays back some of the time their own game spent mining as if they were away (hbHubMinerAfterglow()).
// Its 'tier' opens with 'unlock': rip
// (a Big Rip made) or theory (the Theory of Everything learned). Tier II ones also turn Autopilot and Auto Crunch off
// ('noAuto'), which only the page has. 'rules' are the other Anomalies whose rules it has too (The Gauntlet). While the
// player is in one, the Engine gets nothing else from them (no Shifts or donations), and their own game mines as if
// they were away. The rules each changes are in the functions that check hbHubMinerMod(); the goals were tuned with
// the balance sim: about 1-3 hours for Tier I and 3-8 for Tier II with the whole skill tree, and 15 to 60 or more for
// Tier III from nothing.
// Stacks: up to every Anomaly of the tiers up to HBHUB_MINER_ANOMALY_STACK_TIER at once (hbHubMinerAnomalyMods()), with
// all their rules, the lowest of their goals, and their parts together times HBHUB_MINER_ANOMALY_STACK for each one
// after the first. The weekly Anomaly (hbHubMinerWeeklyMods()) is a stack picked from the week, the same for
// everyone, worth HBHUB_MINER_ANOMALY_WEEKLY times as much that week.
const HBHUB_MINER_ANOMALY_TIERS = [
    1 => ['name' => 'Ripples', 'parts' => 25, 'afterglow' => 3600000, 'unlock' => 'rip', 'noAuto' => false, 'fresh' => false],
    2 => ['name' => 'Tears', 'parts' => 100, 'afterglow' => 10800000, 'unlock' => 'theory', 'noAuto' => true, 'fresh' => false],
    3 => ['name' => 'Singularities', 'parts' => 400, 'afterglow' => 43200000, 'unlock' => 'theory', 'noAuto' => false, 'fresh' => true],
];
const HBHUB_MINER_ANOMALIES = [
    'deadHands' => ['name' => 'Dead Hands', 'icon' => 'glove', 'tier' => 1, 'goal' => 3e21,
        'text' => 'Clicks mine nothing, and neither do auto-clickers.'],
    'darkSky' => ['name' => 'Dark Sky', 'icon' => 'comet', 'tier' => 1, 'goal' => 7e19,
        'text' => 'No comets come.'],
    'inflation' => ['name' => 'Inflation', 'icon' => 'scales', 'tier' => 1, 'goal' => 2e21,
        'text' => 'Each building costs 35% more than the one before, instead of 15% (12% for Space Pickaxe Guys).'],
    'bareMetal' => ['name' => 'Bare Metal', 'icon' => 'pickaxe', 'tier' => 1, 'goal' => 3e17,
        'text' => 'No ore upgrades at all (so no bulk buying either): only buildings.'],
    'entropy' => ['name' => 'Entropy', 'icon' => 'hourglass', 'tier' => 2, 'goal' => 5e22,
        'text' => 'Production halves every 5 minutes of a run. A Warp Jump starts the clock over.'],
    'minimalist' => ['name' => 'Minimalist', 'icon' => 'clipboard', 'tier' => 2, 'goal' => 2e16,
        'text' => 'At most 150 buildings at once, and every run starts with none.'],
    'oneTrack' => ['name' => 'One Track', 'icon' => 'link', 'tier' => 2, 'goal' => 5e18,
        'text' => 'One kind of building per run: the first one you buy is the only kind you can buy until the next Warp Jump. Every run starts with none.'],
    'unstableVacuum' => ['name' => 'Unstable Vacuum', 'icon' => 'blackHole', 'tier' => 2, 'goal' => 1e14,
        'text' => 'Each Warp Jump destroys the building you have the most of, for the rest of the Anomaly.'],
    'speedOfLight' => ['name' => 'Speed of Light', 'icon' => 'warp', 'tier' => 2, 'goal' => 5e20,
        'text' => 'Reach the goal within 2 hours of starting, time away included, or the Anomaly is over.'],
    'nakedSingularity' => ['name' => 'Naked Singularity', 'icon' => 'strangelet', 'tier' => 3, 'goal' => 1e5, 'parts' => 800,
        'text' => 'No twist at all, just the whole game from nothing, past a Big Rip: earn ten times the Antimatter one needs.'],
    'ironUniverse' => ['name' => 'Iron Universe', 'icon' => 'rig', 'tier' => 3, 'goal' => 1e21, 'stat' => 'runOre',
        'text' => 'No Warp Jumps at all: mine everything in one run.'],
    'handsOnly' => ['name' => 'Hands Only', 'icon' => 'neutronFist', 'tier' => 3, 'goal' => 1e6, 'stat' => 'darkEarned',
        'text' => 'Buildings mine nothing by themselves. Each click (and each auto-click) mines 20% of what they would in a second instead.'],
    'gauntlet' => ['name' => 'The Gauntlet', 'icon' => 'gauntlet', 'tier' => 3, 'goal' => 3, 'stat' => 'darkEarned', 'parts' => 3000,
        'afterglow' => 86400000, 'rules' => ['deadHands', 'darkSky', 'inflation', 'entropy'],
        'text' => 'Dead Hands, Dark Sky, Inflation and Entropy all at once, from nothing. Every run starts with one Space Pickaxe Guy, and Entropy stops after halving production twice.'],
];
const HBHUB_MINER_ANOMALY_REPEAT = 0.25; // of the parts, for finishing one again without beating the best time
// How many Anomalies a player can save to carry on later (hbHubMinerAnomalySave()), at most one of each key. Each is a
// whole game kept in the save, so there's a cap.
const HBHUB_MINER_ANOMALY_SAVED_MAX = 3;
const HBHUB_MINER_ANOMALY_STACK_TIER = 2; // the highest tier that stacks
const HBHUB_MINER_ANOMALY_STACK = 1.5; // the parts are multiplied by this for each Anomaly in a stack after the first
const HBHUB_MINER_ANOMALY_WEEKLY = 2; // the parts for the weekly Anomaly that week (and its Afterglow)
// The Afterglow after finishing one: production times this, for 1 hour (Tier I), 3 (Tier II), 12 (Tier III) or 24 (The
// Gauntlet), a stack's added up. Finishing another while one is running adds to it, up to this far ahead.
const HBHUB_MINER_AFTERGLOW_TIMES = 2;
const HBHUB_MINER_AFTERGLOW_MAX_MS = 172800000; // 48 hours
const HBHUB_MINER_WEEK_MS = 604800000; // weeks start on Monday at 00:00 UTC (hbHubMinerWeek())
const HBHUB_MINER_WEEKLY_SIZES = [2, 3]; // how many Anomalies the weekly one stacks, picked from
const HBHUB_MINER_WEEKLY_LEFT_OUT = ['speedOfLight']; // never in it: with Unstable Vacuum it can't be done in its time
// What starts over in a Tier III Anomaly on top of a Big Rip's (hbHubMinerNewMultiverse()), so it's from nothing
const HBHUB_MINER_ANOMALY_FRESH = [
    'skills' => [], 'strange' => 0, 'strangeEarned' => 0, 'rips' => 0, 'crunches' => 0, 'warps' => 0, 'flights' => 0,
    'clicks' => 0, 'crits' => 0, 'comets' => 0, 'diamonds' => 0, 'antiUpgrades' => [], 'darkUpgrades' => [], 'allOre' => 0.0, 'clickOre' => 0.0,
];
const HBHUB_MINER_HANDS_SHARE = 0.2; // of the buildings' ore per second each click mines, in Hands Only
// What every run starts with in The Gauntlet (building => how many), as clicks mine nothing there and a run could never
// start, and how often its Entropy halves production instead of HBHUB_MINER_ENTROPY_MS: from nothing, a run takes far
// longer than 5 minutes to reach a Warp Jump, and with halvings that often it never does (the balance sim)
const HBHUB_MINER_GAUNTLET_START = ['pickaxe' => 1];
const HBHUB_MINER_GAUNTLET_ENTROPY_MS = 300000;
// The most halvings in The Gauntlet, where nothing else could ever make a run worth more than its start: production
// goes down to 1 / 2^this and stays there until the next Warp Jump (instead of all the way to 0, HBHUB_MINER_ENTROPY_STEPS)
const HBHUB_MINER_GAUNTLET_ENTROPY_STEPS = 2;
const HBHUB_MINER_LIGHT_HOUR_MS = 3600000; // the Faster Than Light achievement

// Asteroid skins: how the asteroid looks instead of its usual colours (spaceOreMiner.css, data-skin), picked in the
// game's stats. 'unlock' is how it's earned: anomaly (that Anomaly finished, alone or in a stack), stage (that many of
// the Engines' stages done, counted over all of them like engineStage, for everyone) or crew (gave parts to that
// Engine's last stage before it was done, crews in the save). 'moving' ones are animated.
const HBHUB_MINER_SKINS = [
    'entropy' => ['name' => 'Heat Death', 'unlock' => ['anomaly' => 'entropy'], 'moving' => false],
    'minimalist' => ['name' => 'Paper White', 'unlock' => ['anomaly' => 'minimalist'], 'moving' => false],
    'oneTrack' => ['name' => 'Signal Green', 'unlock' => ['anomaly' => 'oneTrack'], 'moving' => false],
    'unstableVacuum' => ['name' => 'Void Violet', 'unlock' => ['anomaly' => 'unstableVacuum'], 'moving' => false],
    'speedOfLight' => ['name' => 'Redshift', 'unlock' => ['anomaly' => 'speedOfLight'], 'moving' => false],
    'nakedSingularity' => ['name' => 'Event Horizon', 'unlock' => ['anomaly' => 'nakedSingularity'], 'moving' => true],
    'ironUniverse' => ['name' => 'Forged Iron', 'unlock' => ['anomaly' => 'ironUniverse'], 'moving' => true],
    'handsOnly' => ['name' => 'Fingerprints', 'unlock' => ['anomaly' => 'handsOnly'], 'moving' => true],
    'gauntlet' => ['name' => 'Gauntlet Fire', 'unlock' => ['anomaly' => 'gauntlet'], 'moving' => true],
    'engineSteel' => ['name' => 'Engine Steel', 'unlock' => ['stage' => 9], 'moving' => false],
    'engineCore' => ['name' => 'Engine Core', 'unlock' => ['stage' => 19], 'moving' => true],
    'ignition' => ['name' => 'Ignition', 'unlock' => ['crew' => 1], 'moving' => true],
    'ember' => ['name' => 'Ember', 'unlock' => ['stage' => 33], 'moving' => false],
    'molten' => ['name' => 'Molten', 'unlock' => ['stage' => 38], 'moving' => true],
    'starforged' => ['name' => 'Starforged', 'unlock' => ['stage' => 44], 'moving' => true],
    'firstLight' => ['name' => 'First Light', 'unlock' => ['crew' => 2], 'moving' => true],
];

// Backgrounds for the game (spaceOreMiner.css, data-backdrop on the page), picked in the stats once the Starforge's
// backdrops perk is done (hbHubMinerEnginePerk())
const HBHUB_MINER_BACKDROPS = [
    'nebula' => ['name' => 'Nebula'],
    'deepField' => ['name' => 'Deep Field'],
    'forgeGlow' => ['name' => 'Forge Glow'],
    'aurora' => ['name' => 'Aurora'],
];

// Titles by players' names on the leaderboard, the first one a player has (games/spaceOreMiner/leaderboard.php): for
// finishing an 'anomaly', or for giving parts to 'engine' (by its number) once its title stage is done
const HBHUB_MINER_TITLES = [
    'gauntlet' => ['name' => 'Gauntlet Runner', 'anomaly' => 'gauntlet'],
    'singularity' => ['name' => 'Singularity', 'anomaly' => 'nakedSingularity'],
    'forgemaster' => ['name' => 'Forgemaster', 'engine' => 2],
    'engineer' => ['name' => 'Engineer', 'engine' => 1],
];
const HBHUB_MINER_INFLATION_GROWTH = 1.35; // each building costs this much more than the one before, in Inflation
const HBHUB_MINER_ENTROPY_MS = 300000; // production halves after each this long of a run, in Entropy
const HBHUB_MINER_ENTROPY_STEPS = 1100; // after this many halvings it's 0 (a float can't be smaller), so no more pieces
const HBHUB_MINER_MINIMALIST_MAX = 150; // buildings at once, in Minimalist
const HBHUB_MINER_LIGHT_MS = 7200000; // to reach the goal in, in Speed of Light

/**
 * Every upgrade bought with ore, by id: the buildings' own six each (type 'building', ids like drone1 to drone6),
 * then the others, sorted by cost. Each has name, icon, cost, type, text, need, and whatever its type uses.
 */
function hbHubMinerUpgrades(): array
{
    static $upgrades = null;
    if ($upgrades !== null) {
        return $upgrades;
    }

    $upgrades = [];
    foreach (HBHUB_MINER_BUILDINGS as $buildingId => $building) {
        foreach ($building['upgrades'] as $index => $name) {
            $cost = $building['cost'] * ($building['growth'] ?? HBHUB_MINER_COST_GROWTH) ** HBHUB_MINER_BUILDING_UPGRADE_AT[$index]
                * HBHUB_MINER_BUILDING_UPGRADE_COST[$index];
            $upgrades[$buildingId . ($index + 1)] = [
                'name' => $name,
                'icon' => $building['icon'],
                'cost' => (float) sprintf('%.1e', $cost), // two digits, as a number that prints neatly
                'type' => 'building',
                'building' => $buildingId,
                'need' => ['owned' => [$buildingId => HBHUB_MINER_BUILDING_UPGRADE_AT[$index]]],
                'text' => $building['plural'] . ' mine twice as much.',
            ];
        }
    }
    foreach (HBHUB_MINER_OTHER_UPGRADES as $id => $upgrade) {
        $upgrade['need'] ??= ['ore' => $upgrade['cost'] / 2];
        $upgrades[$id] = $upgrade;
    }
    uasort($upgrades, static fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
    return $upgrades;
}

/**
 * A number for a skill's text: "twice" for 2 (with $twice), words past a million ("1 trillion"), otherwise with as few
 * decimals as it needs ("2.5", "1,000").
 */
function hbHubMinerSkillNumber(float $number, bool $twice = false): string
{
    if ($twice && $number == 2) {
        return 'twice';
    }
    foreach ([[1e12, 'trillion'], [1e9, 'billion'], [1e6, 'million']] as [$size, $word]) {
        if ($number >= $size) {
            return hbHubMinerSkillNumber($number / $size) . ' ' . $word;
        }
    }
    return rtrim(rtrim(number_format($number, 2), '0'), '.');
}

/**
 * What a skill does, in words, made from its numbers so the text can't say something else than the rules do (a skill
 * with its own 'text' has that instead).
 */
function hbHubMinerSkillText(array $skill): string
{
    if (isset($skill['text'])) {
        return $skill['text'];
    }
    $times = (float) ($skill['times'] ?? 1);
    $value = (float) ($skill['value'] ?? 0);
    $timesText = hbHubMinerSkillNumber($times, true) . ($times == 2 ? '' : ' times');
    $percent = hbHubMinerSkillNumber($value * 100) . '%';
    $less = hbHubMinerSkillNumber((1 - $times) * 100) . '%';
    $range = isset($skill['buildings']) ? HBHUB_MINER_BUILDINGS[$skill['buildings'][0]]['plural']
        . (count($skill['buildings']) > 1 ? ' to ' . HBHUB_MINER_BUILDINGS[$skill['buildings'][count($skill['buildings']) - 1]]['plural'] : '') : '';

    return match ($skill['type']) {
        'production' => 'Everything mines ' . $timesText . ' as much.',
        'buildings' => 'Everything from ' . $range . ' mines ' . $timesText . ' as much.',
        'perSkill' => 'Everything mines ' . $percent . ' more for every skill you\'ve learned.',
        'perWarp' => 'Everything mines ' . $percent . ' more for every Warp Jump you\'ve ever made.',
        'perCrunch' => 'Everything mines ' . $percent . ' more for every Big Crunch you\'ve ever made.',
        'owned' => 'Everything mines ' . $percent . ' more for every building you own.',
        'buildingUpgrade' => 'A building\'s own upgrades make it mine ' . hbHubMinerSkillNumber(2 * $times) . ' times as much instead of twice.',
        'crew' => 'The Space Pickaxe Guys\' crew upgrades are ' . $timesText . ' as strong.',
        'cost' => 'Buildings cost ' . $less . ' less.',
        'upgradeCost' => 'Upgrades cost ' . $less . ' less ore.',
        'start' => 'Start every run with ' . $skill['count'] . ' of everything from ' . $range . '.',
        'startOre' => 'Start every run with ' . hbHubMinerSkillNumber($value) . ' ore.',
        'click' => 'Clicks mine ' . $timesText . ' as much (before the share of your ore per second).',
        'clickShare' => 'Each click also mines ' . $percent . ' of your ore per second.',
        'critPower' => 'Critical clicks mine ' . hbHubMinerSkillNumber(HBHUB_MINER_CRIT_MULTIPLIER * $times) . ' times as much instead of '
            . HBHUB_MINER_CRIT_MULTIPLIER . '.',
        'auto' => 'Clicks the asteroid ' . hbHubMinerSkillNumber($value) . ' more times a second for you.',
        'cometWait' => 'The wait between comets is ' . $less . ' shorter.',
        'cometStay' => 'Comets stay on screen ' . $timesText . ' as long.',
        'cometPower' => 'Comet effects are ' . $timesText . ' as strong.',
        'cometLength' => 'Comet effects last ' . $timesText . ' as long.',
        'luckyOre' => 'Lucky comets give ' . $timesText . ' as much ore.',
        'darkGain' => 'Warp Jumps give ' . $timesText . ' as much Dark Matter.',
        'antiGain' => 'Big Crunches give ' . $timesText . ' as much Antimatter.',
        'strangeGain' => 'Big Rips give ' . $timesText . ' as much Strange Matter.',
        'darkBonus' => 'Every Dark Matter you\'ve earned makes everything mine another ' . $percent . ' faster.',
        'antiBonus' => 'Every Antimatter you\'ve earned adds another ' . $percent . ' to production (and so more Dark Matter from Warp Jumps).',
        'crunchDark' => 'Big Crunches can be done after ' . hbHubMinerSkillNumber(HBHUB_MINER_CRUNCH_DARK * $times) . ' Dark Matter instead of '
            . hbHubMinerSkillNumber(HBHUB_MINER_CRUNCH_DARK) . '.',
        'offline' => 'Earn ' . $timesText . ' as much while you\'re away.',
        default => '',
    };
}

/**
 * The skill tree (HBHUB_MINER_SKILLS), each with its 'text'.
 */
function hbHubMinerSkills(): array
{
    static $skills = null;
    if ($skills === null) {
        $skills = array_map(static fn (array $skill): array => ['text' => hbHubMinerSkillText($skill)] + $skill, HBHUB_MINER_SKILLS);
    }
    return $skills;
}

/**
 * Everything the script needs to play by the same rules: the data above and the numbers, as JSON for data-config.
 */
function hbHubMinerConfig(): array
{
    return [
        'buildings' => HBHUB_MINER_BUILDINGS,
        'upgrades' => hbHubMinerUpgrades(),
        'darkUpgrades' => HBHUB_MINER_DARK_UPGRADES,
        'antiUpgrades' => HBHUB_MINER_ANTI_UPGRADES,
        'skills' => hbHubMinerSkills(),
        'skillNeutral' => HBHUB_MINER_SKILL_NEUTRAL,
        'achievements' => HBHUB_MINER_ACHIEVEMENTS,
        'comets' => HBHUB_MINER_COMETS,
        'clickTypes' => HBHUB_MINER_CLICK_TYPES,
        'eternalTypes' => HBHUB_MINER_ETERNAL_TYPES,
        'saveSeconds' => HBHUB_MINER_SAVE_SECONDS,
        'onlineMs' => HBHUB_MINER_ONLINE_MS,
        'offlineRate' => HBHUB_MINER_OFFLINE_RATE,
        'clicksPerSecond' => HBHUB_MINER_CLICKS_PER_SECOND,
        'autoValue' => HBHUB_MINER_AUTO_VALUE,
        'maxActions' => HBHUB_MINER_MAX_ACTIONS,
        'costGrowth' => HBHUB_MINER_COST_GROWTH,
        'costGrowthPhysics' => HBHUB_MINER_COST_GROWTH_PHYSICS,
        'warpOre' => HBHUB_MINER_WARP_ORE,
        'darkUnit' => HBHUB_MINER_DARK_UNIT,
        'darkBonus' => HBHUB_MINER_DARK_BONUS,
        'darkBonusReactor' => HBHUB_MINER_DARK_BONUS_REACTOR,
        'crunchDark' => HBHUB_MINER_CRUNCH_DARK,
        'antiUnit' => HBHUB_MINER_ANTI_UNIT,
        'antiBonus' => HBHUB_MINER_ANTI_BONUS,
        'ripAnti' => HBHUB_MINER_RIP_ANTI,
        'strangeUnit' => HBHUB_MINER_STRANGE_UNIT,
        'strangeSteps' => HBHUB_MINER_STRANGE_STEPS,
        'strangeBonus' => HBHUB_MINER_STRANGE_BONUS,
        'leftoverOre' => HBHUB_MINER_LEFTOVER_ORE,
        'darkPickaxe' => HBHUB_MINER_DARK_PICKAXE,
        'cometWaitMs' => HBHUB_MINER_COMET_WAIT_MS,
        'cometStayMs' => HBHUB_MINER_COMET_STAY_MS,
        'diamondChance' => HBHUB_MINER_DIAMOND_CHANCE,
        'critChance' => HBHUB_MINER_CRIT_CHANCE,
        'critMultiplier' => HBHUB_MINER_CRIT_MULTIPLIER,
        'luckySeconds' => HBHUB_MINER_LUCKY_SECONDS,
        'headStart' => HBHUB_MINER_HEAD_START,
        'buildingUpgradeAt' => HBHUB_MINER_BUILDING_UPGRADE_AT,
        'engineStages' => HBHUB_MINER_ENGINE_STAGES,
        'engines' => HBHUB_MINER_ENGINES,
        'engineNeutral' => HBHUB_MINER_ENGINE_NEUTRAL,
        'engineStageTotal' => HBHUB_MINER_ENGINE_STAGE_TOTAL,
        'engineOrePart' => HBHUB_MINER_ENGINE_ORE_PART,
        'engineOreParts' => HBHUB_MINER_ENGINE_ORE_PARTS,
        'engineStrangePart' => HBHUB_MINER_ENGINE_STRANGE_PART,
        'dayMs' => HBHUB_MINER_DAY_MS,
        'shifts' => HBHUB_MINER_SHIFTS,
        'shiftCount' => HBHUB_MINER_SHIFT_COUNT,
        'shiftParts' => HBHUB_MINER_SHIFT_PARTS,
        'shiftPartsAll' => HBHUB_MINER_SHIFT_PARTS_ALL,
        'sourceShift' => HBHUB_MINER_ENGINE_SOURCE_SHIFT,
        'sourceOre' => HBHUB_MINER_ENGINE_SOURCE_ORE,
        'sourceStrange' => HBHUB_MINER_ENGINE_SOURCE_STRANGE,
        'sourceAnomaly' => HBHUB_MINER_ENGINE_SOURCE_ANOMALY,
        'anomalies' => HBHUB_MINER_ANOMALIES,
        'anomalyTiers' => HBHUB_MINER_ANOMALY_TIERS,
        'anomalyRepeat' => HBHUB_MINER_ANOMALY_REPEAT,
        'anomalySavedMax' => HBHUB_MINER_ANOMALY_SAVED_MAX,
        'inflationGrowth' => HBHUB_MINER_INFLATION_GROWTH,
        'entropyMs' => HBHUB_MINER_ENTROPY_MS,
        'entropySteps' => HBHUB_MINER_ENTROPY_STEPS,
        'minimalistMax' => HBHUB_MINER_MINIMALIST_MAX,
        'lightMs' => HBHUB_MINER_LIGHT_MS,
        'engineOreShare' => HBHUB_MINER_ENGINE_ORE_SHARE,
        'engineOreQuarters' => HBHUB_MINER_ENGINE_ORE_QUARTERS,
        'engineCountdownMs' => HBHUB_MINER_ENGINE_COUNTDOWN_MS,
        'engineDrain' => HBHUB_MINER_ENGINE_DRAIN,
        'ignitionMs' => HBHUB_MINER_IGNITION_MS,
        'ignitionTimes' => HBHUB_MINER_IGNITION_TIMES,
        'anomalyStackTier' => HBHUB_MINER_ANOMALY_STACK_TIER,
        'anomalyStack' => HBHUB_MINER_ANOMALY_STACK,
        'anomalyWeekly' => HBHUB_MINER_ANOMALY_WEEKLY,
        'afterglowTimes' => HBHUB_MINER_AFTERGLOW_TIMES,
        'afterglowMax' => HBHUB_MINER_AFTERGLOW_MAX_MS,
        'weekMs' => HBHUB_MINER_WEEK_MS,
        'weeklySizes' => HBHUB_MINER_WEEKLY_SIZES,
        'weeklyLeftOut' => HBHUB_MINER_WEEKLY_LEFT_OUT,
        'anomalyFresh' => HBHUB_MINER_ANOMALY_FRESH,
        'handsShare' => HBHUB_MINER_HANDS_SHARE,
        'gauntletStart' => HBHUB_MINER_GAUNTLET_START,
        'gauntletEntropyMs' => HBHUB_MINER_GAUNTLET_ENTROPY_MS,
        'gauntletEntropySteps' => HBHUB_MINER_GAUNTLET_ENTROPY_STEPS,
        'lightHourMs' => HBHUB_MINER_LIGHT_HOUR_MS,
        'skins' => HBHUB_MINER_SKINS,
        'backdrops' => HBHUB_MINER_BACKDROPS,
    ];
}

/**
 * Where the game's icons are (one <symbol> per icon, see the file), with its last change on the end so a new icon isn't
 * hidden by the 90-day cache. Same-site and without the domain, as <use> only takes icons from the page's own site.
 */
function hbHubMinerIconsUrl(): string
{
    $path = '/assets/images/spaceOreMiner/icons.svg';
    return $path . '?v=' . filemtime($_SERVER['DOCUMENT_ROOT'] . $path);
}

/**
 * One of the game's icons as HTML, for the page (spaceOreMiner.js has its own icon() for the ones it draws). $name is
 * the icon's id in icons.svg, $class is added to miner-icon.
 */
function hbHubMinerIcon(string $name, string $class = ''): string
{
    return '<svg class="miner-icon' . ($class !== '' ? ' ' . htmlspecialchars($class) : '') . '" aria-hidden="true"><use href="'
        . htmlspecialchars(hbHubMinerIconsUrl() . '#' . $name) . '"></use></svg>';
}

/**
 * Now, in Unix milliseconds.
 */
function hbHubMinerNow(): int
{
    return (int) floor(microtime(true) * 1000);
}

// ----- Luck. A small hash of the save's seed, a counter (like the click number) and a salt, so the same click always
// gets the same answer here and in the script. 32-bit maths, done in pieces so PHP's 64-bit numbers don't overflow.

/**
 * $a times $b, keeping the lowest 32 bits (JavaScript's Math.imul, unsigned).
 */
function hbHubMinerImul(int $a, int $b): int
{
    $a &= 0xFFFFFFFF;
    $b &= 0xFFFFFFFF;
    return (($a * ($b & 0xFFFF)) + ((($a * ($b >> 16)) & 0xFFFF) << 16)) & 0xFFFFFFFF;
}

/**
 * A number from 0 up to (not including) 1, always the same for the same seed, counter and salt.
 */
function hbHubMinerRandom(int $seed, int $counter, int $salt): float
{
    $hash = ($seed ^ hbHubMinerImul($counter, 0x9E3779B1) ^ hbHubMinerImul($salt, 0x85EBCA77)) & 0xFFFFFFFF;
    $hash ^= $hash >> 16;
    $hash = hbHubMinerImul($hash, 0x7FEB352D);
    $hash ^= $hash >> 15;
    $hash = hbHubMinerImul($hash, 0x846CA68B);
    $hash ^= $hash >> 16;
    return $hash / 4294967296;
}

// ----- The game

/**
 * A new save, starting now. Times are Unix ms; ore numbers are floats (they get far too big for whole numbers).
 * - ore: to spend. runOre: mined this run (since the last Warp Jump). universeOre: mined in this universe (since the
 *   last Big Crunch, what Dark Matter is worked out from). allOre: mined ever. clickOre: mined by clicking.
 * - buildings: id => how many. upgrades: the ids bought this run. dark: Dark Matter to spend. darkEarned: earned in
 *   this universe (the production bonus). darkUpgrades: bought with it. warps: Warp Jumps made ever. flights: the ones
 *   that at least doubled darkEarned, which are all Frequent Flyer Miles counts. When it counted every jump, holding
 *   W down with a macro (30+ jumps a second, each for 1 Dark Matter) was the best way to play: +1% production per
 *   jump, without end. Doubling each time, there are only so many in a universe however they're made.
 * - antimatter: to spend. antimatterEarned: earned in this multiverse (since the last Big Rip: the bonus, and what
 *   Strange Matter is worked out from). antiUpgrades: bought with it. crunches: Big Crunches made ever.
 * - strange: Strange Matter to spend. strangeEarned: ever earned (the bonus). skills: the skills learned with it, in
 *   the order they were learned. rips: Big Rips made.
 * - clicks, crits, comets (caught), diamonds (Diamond Comets caught): counted ever.
 * - comet: the next or current comet: n (its number, for its luck) and at (when it shows up).
 * - buffs: the comet effects running: the comet's kind => {kind, multiplier, until}. (Older saves keyed them by
 *   'production' or 'click' instead, so nothing goes by the key: see hbHubMinerBuff().)
 * - achievements: the ids done. tick: how far the game has run. started, runStarted: when the save and this run began.
 * - engineStage: how many of the Engines' stages are done, every Engine so far counted (an Engine has
 *   HBHUB_MINER_ENGINE_STAGE_TOTAL), so which perks work (hbHubMinerEngineEffects()) and which Engine is being built
 *   (hbHubMinerEngineNumber()).
 *   engineOpen: whether the Engine takes parts right now (false for guests, and while a stage waits for an Anomaly
 *   lock). Both are the server's to set, after each save (hbHubMinerEngineAdopt()), so the replay of the next one and
 *   the script use the same numbers.
 * - engineDay: the UTC day (tick / HBHUB_MINER_DAY_MS) the next two are for, which start over on the next one.
 *   donatedToday: parts given from ore that day. shifts: how far each of the day's Engine Shifts is. shiftSwap: the
 *   Shift swapped for the spare one that day (hbHubMinerShiftSwap()), or -1.
 * - anomaly: null, or the Anomaly the player is in: mods (the HBHUB_MINER_ANOMALIES ids whose rules it has), started
 *   (Unix ms), goal (the Antimatter to earn in it, kept from when it started) and state (its own game, a save of this
 *   same shape, with no anomaly of its own). While it's there, every action is played in it (hbHubMinerApply()) and
 *   only its time moves on; this game's 'tick' waits where it was, and catches up as time away when it's left
 *   (hbHubMinerAnomalyLeave()). anomalies: the ones finished, by key (hbHubMinerAnomalyKey()) => {done: how many times,
 *   best: the fastest, in ms, engine: the Engine it was last finished for, as the first time for each Engine pays in
 *   full}. saved: the Anomalies saved to carry on later (hbHubMinerAnomalySave()), by key => the same shape as anomaly,
 *   at most HBHUB_MINER_ANOMALY_SAVED_MAX ({} in an Anomaly's game). Their games wait where they were saved.
 *   lastAnomaly: the key of the last one gone into, or null (the Again button). afterglow: when the Afterglow from
 *   finishing one runs out (Unix ms, 0 or in the past when there's none, always 0 in an Anomaly's game).
 * - mods: the Anomaly's ids in an Anomaly's own game (hbHubMinerMod()), with the ones in their 'rules' too, [] in the
 *   player's. destroyed: the buildings the Unstable Vacuum has destroyed in it.
 * - engineOreRest: quarter parts given from ore that don't make a whole part yet (HBHUB_MINER_ENGINE_ORE_SHARE).
 *   engineIgnited: when the last Engine done was ignited (Unix ms, 0 before), crews: the Engines (by number) whose last
 *   stage the player gave parts to before it was done, and crewsChecked: the last Engine done that crews was looked up
 *   for. All three are the server's to set, like engineStage (hbHubMinerEngineAdopt()).
 * - engineGiven: parts given ever, shiftDays: days with every Shift done, weeklies: weekly Anomalies finished (for the
 *   achievements). skin: the asteroid skin picked (a key of HBHUB_MINER_SKINS), or null for its usual look.
 *   backdrop: the background picked (a key of HBHUB_MINER_BACKDROPS), or null. sparks: whether clicking throws sparks
 *   (once that perk is done).
 */
function hbHubMinerNewState(int $now): array
{
    $state = [
        'seed' => random_int(1, 0x7FFFFFFF),
        'tick' => $now,
        'started' => $now,
        'runStarted' => $now,
        'ore' => 0.0,
        'runOre' => 0.0,
        'universeOre' => 0.0,
        'allOre' => 0.0,
        'clickOre' => 0.0,
        'buildings' => array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0),
        'upgrades' => [],
        'dark' => 0,
        'darkEarned' => 0,
        'darkUpgrades' => [],
        'warps' => 0,
        'flights' => 0,
        'antimatter' => 0,
        'antimatterEarned' => 0,
        'antiUpgrades' => [],
        'crunches' => 0,
        'strange' => 0,
        'strangeEarned' => 0,
        'skills' => [],
        'rips' => 0,
        'clicks' => 0,
        'crits' => 0,
        'comets' => 0,
        'diamonds' => 0,
        'comet' => ['n' => 0, 'at' => $now],
        'buffs' => [],
        'achievements' => [],
        'engineStage' => 0,
        'engineOpen' => false,
        'engineDay' => 0,
        'donatedToday' => 0,
        'shifts' => array_fill(0, HBHUB_MINER_SHIFT_COUNT, 0),
        'anomaly' => null,
        'anomalies' => [],
        'saved' => [],
        'mods' => [],
        'destroyed' => [],
        'engineOreRest' => 0,
        'engineIgnited' => 0,
        'crews' => [],
        'crewsChecked' => 0,
        'engineGiven' => 0,
        'shiftDays' => 0,
        'weeklies' => 0,
        'skin' => null,
        'shiftSwap' => -1,
        'lastAnomaly' => null,
        'afterglow' => 0,
        'backdrop' => null,
        'sparks' => true,
    ];
    $state['comet']['at'] = $now + hbHubMinerCometWait($state, 0);
    return $state;
}

/**
 * A save from the database, with anything an older version didn't have filled in, and anything unknown taken out.
 * A save from before the Big Crunch has only ever been in one universe, so all its ore was mined in this one.
 */
function hbHubMinerNormalize(array $saved, int $now): array
{
    $saved['universeOre'] ??= $saved['allOre'] ?? 0.0;
    // A save from before flights were counted: its jumps count, up to HBHUB_MINER_OLD_FLIGHTS (so a macro's thousands
    // don't stay in for good)
    $saved['flights'] ??= min((int) ($saved['warps'] ?? 0), HBHUB_MINER_OLD_FLIGHTS);
    $state = array_replace(hbHubMinerNewState($now), $saved);
    $state['buildings'] = array_replace(array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0),
        array_intersect_key($state['buildings'], HBHUB_MINER_BUILDINGS));
    $state['upgrades'] = array_values(array_intersect($state['upgrades'], array_keys(hbHubMinerUpgrades())));
    $state['darkUpgrades'] = array_values(array_intersect($state['darkUpgrades'], array_keys(HBHUB_MINER_DARK_UPGRADES)));
    $state['antiUpgrades'] = array_values(array_intersect($state['antiUpgrades'], array_keys(HBHUB_MINER_ANTI_UPGRADES)));
    $state['skills'] = array_values(array_intersect($state['skills'], array_keys(HBHUB_MINER_SKILLS)));
    unset($state['steady'], $state['fast']); // the old auto-clicker checks'
    $state['engineStage'] = max(0, min((int) $state['engineStage'], count(HBHUB_MINER_ENGINE_STAGES)));
    $state['engineOpen'] = (bool) $state['engineOpen'];
    $state['engineDay'] = (int) $state['engineDay'];
    $state['donatedToday'] = (int) $state['donatedToday'];
    $state['shifts'] = is_array($state['shifts']) && count($state['shifts']) === HBHUB_MINER_SHIFT_COUNT
        ? array_map('intval', array_values($state['shifts'])) : array_fill(0, HBHUB_MINER_SHIFT_COUNT, 0);
    foreach (['engineOreRest', 'engineIgnited', 'engineGiven', 'shiftDays', 'weeklies', 'afterglow'] as $key) {
        $state[$key] = max(0, (int) $state[$key]);
    }
    $state['engineOreRest'] %= HBHUB_MINER_ENGINE_ORE_QUARTERS;
    // Saves from before Engine #2 had ignitionCrew instead: whether they were the crew of Engine #1's Ignition
    if ($state['ignitionCrew'] ?? false) {
        $state['crews'] = [1];
    }
    unset($state['ignitionCrew']);
    $state['crews'] = is_array($state['crews']) ? array_values(array_unique(array_map('intval', $state['crews']))) : [];
    $state['crewsChecked'] = max(0, (int) $state['crewsChecked']);
    $state['skin'] = is_string($state['skin']) && isset(HBHUB_MINER_SKINS[$state['skin']]) ? $state['skin'] : null;
    $state['backdrop'] = is_string($state['backdrop']) && isset(HBHUB_MINER_BACKDROPS[$state['backdrop']]) ? $state['backdrop'] : null;
    $state['sparks'] = (bool) $state['sparks'];
    $state['shiftSwap'] = (int) $state['shiftSwap'] >= 0 && (int) $state['shiftSwap'] < HBHUB_MINER_SHIFT_COUNT ? (int) $state['shiftSwap'] : -1;
    $state['lastAnomaly'] = is_string($state['lastAnomaly']) && hbHubMinerAnomalyMods($state['lastAnomaly']) !== null ? $state['lastAnomaly'] : null;
    $state['mods'] = is_array($state['mods']) ? array_values(array_intersect($state['mods'], array_keys(HBHUB_MINER_ANOMALIES))) : [];
    $state['destroyed'] = is_array($state['destroyed']) ? array_values(array_intersect($state['destroyed'], array_keys(HBHUB_MINER_BUILDINGS))) : [];
    $anomalies = [];
    foreach (is_array($state['anomalies']) ? $state['anomalies'] : [] as $key => $record) {
        if (is_array($record) && hbHubMinerAnomalyMods((string) $key) !== null) {
            $anomalies[(string) $key] = ['done' => (int) ($record['done'] ?? 0), 'best' => (int) ($record['best'] ?? 0),
                'engine' => max(1, (int) ($record['engine'] ?? 1))]; // the ones from before Engine #2 were for Engine #1
        }
    }
    $state['anomalies'] = $anomalies;
    $state['anomaly'] = hbHubMinerNormalizeAnomaly($state['anomaly'], $now);
    $saved = [];
    foreach (is_array($state['saved']) ? $state['saved'] : [] as $key => $anomaly) {
        $anomaly = hbHubMinerNormalizeAnomaly($anomaly, $now);
        if ($anomaly !== null && hbHubMinerAnomalyKey($anomaly['mods']) === (string) $key && count($saved) < HBHUB_MINER_ANOMALY_SAVED_MAX) {
            $saved[(string) $key] = $anomaly;
        }
    }
    $state['saved'] = $saved;

    foreach (['ore', 'runOre', 'universeOre', 'allOre', 'clickOre'] as $key) {
        $state[$key] = (float) $state[$key];
    }
    return $state;
}

/**
 * An Anomaly from the database (the save's 'anomaly', or one of its 'saved'), with its game normalised like the
 * player's (with no Anomalies of its own), or null when it isn't one.
 */
function hbHubMinerNormalizeAnomaly(mixed $anomaly, int $now): ?array
{
    if (!is_array($anomaly) || !is_array($anomaly['state'] ?? null) || !is_array($anomaly['mods'] ?? null)
        || ($mods = hbHubMinerAnomalyMods(implode('+', $anomaly['mods']))) === null) {
        return null;
    }
    return [
        'mods' => $mods,
        'started' => (int) ($anomaly['started'] ?? $now),
        'goal' => (float) ($anomaly['goal'] ?? hbHubMinerAnomalyGoal($mods)),
        'state' => ['mods' => hbHubMinerAnomalyRules($mods)]
            + hbHubMinerNormalize(['anomaly' => null, 'anomalies' => [], 'saved' => []] + $anomaly['state'], $now),
    ];
}

/**
 * The save as the page gets it (all of it: nothing in it is secret): the same, but buffs, anomalies and saved stay
 * JSON objects when empty (an Anomaly's game too).
 */
function hbHubMinerView(array $state): array
{
    $state['buffs'] = (object) $state['buffs'];
    $state['anomalies'] = (object) $state['anomalies'];
    if ($state['anomaly'] !== null) {
        $state['anomaly']['state'] = hbHubMinerView($state['anomaly']['state']);
    }
    foreach ($state['saved'] as $key => $anomaly) {
        $state['saved'][$key]['state'] = hbHubMinerView($anomaly['state']);
    }
    $state['saved'] = (object) $state['saved'];
    return $state;
}

/**
 * The save as JSON, for the database. Ore stays a float (0.0, not 0).
 */
function hbHubMinerEncode(array $state): string
{
    return json_encode(hbHubMinerView($state), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
}

function hbHubMinerHas(array $state, string $upgradeId): bool
{
    return in_array($upgradeId, $state['upgrades'], true);
}

function hbHubMinerHasDark(array $state, string $upgradeId): bool
{
    return in_array($upgradeId, $state['darkUpgrades'], true);
}

function hbHubMinerHasAnti(array $state, string $upgradeId): bool
{
    return in_array($upgradeId, $state['antiUpgrades'], true);
}

function hbHubMinerHasSkill(array $state, string $skillId): bool
{
    return in_array($skillId, $state['skills'], true);
}

/**
 * Whether the game is an Anomaly's with the rules of $anomalyId (a key of HBHUB_MINER_ANOMALIES).
 */
function hbHubMinerMod(array $state, string $anomalyId): bool
{
    return in_array($anomalyId, $state['mods'], true);
}

/**
 * Whether a skill can be learned now (Strange Matter aside): it isn't learned yet, and every skill in its 'after' is.
 */
function hbHubMinerSkillOpen(array $state, string $skillId): bool
{
    if (hbHubMinerHasSkill($state, $skillId)) {
        return false;
    }
    foreach (HBHUB_MINER_SKILLS[$skillId]['after'] as $afterId) {
        if (!hbHubMinerHasSkill($state, $afterId)) {
            return false;
        }
    }
    return true;
}

/**
 * What all the skills learned do together, with the keys of HBHUB_MINER_SKILL_NEUTRAL: each 'times' multiplied in,
 * each 'value' added, the switches true, buildings (id => times) and start (id => how many) per building and keep (the
 * upgrade types kept). In the order they were learned, like the script, so both get the same numbers to the last digit.
 * Worked out once per set of skills, as the rules ask for it all the time.
 */
function hbHubMinerSkillEffects(array $state): array
{
    static $cache = [];
    $key = implode(',', $state['skills']);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $effects = HBHUB_MINER_SKILL_NEUTRAL;
    foreach ($state['skills'] as $skillId) {
        $skill = HBHUB_MINER_SKILLS[$skillId];
        $type = $skill['type'];
        if ($type === 'buildings') {
            foreach ($skill['buildings'] as $buildingId) {
                $effects['buildings'][$buildingId] = ($effects['buildings'][$buildingId] ?? 1) * $skill['times'];
            }
        } elseif ($type === 'start') {
            foreach ($skill['buildings'] as $buildingId) {
                $effects['start'][$buildingId] = max($effects['start'][$buildingId] ?? 0, $skill['count']);
            }
        } elseif ($type === 'keep') {
            $effects['keep'] = array_merge($effects['keep'], $skill['types']);
        } elseif (isset($skill['times'])) {
            $effects[$type] *= $skill['times'];
        } elseif (isset($skill['value'])) {
            $effects[$type] += $skill['value'];
        } else {
            $effects[$type] = true;
        }
    }
    return $cache[$key] = $effects;
}

/**
 * What the perks of the Cosmic Engine's stages done so far do together ('engineStage' in the save), with the keys of
 * HBHUB_MINER_ENGINE_NEUTRAL: the highest 'offline', each 'value' added and each 'times' multiplied, in stage order.
 */
function hbHubMinerEngineEffects(array $state): array
{
    static $cache = [];
    $done = min((int) $state['engineStage'], count(HBHUB_MINER_ENGINE_STAGES));
    if (isset($cache[$done])) {
        return $cache[$done];
    }

    $effects = HBHUB_MINER_ENGINE_NEUTRAL;
    foreach (array_slice(HBHUB_MINER_ENGINE_STAGES, 0, $done) as $stage) {
        $perk = $stage['perk'];
        if ($perk['type'] === 'offline') {
            $effects['offline'] = max($effects['offline'], $perk['value']);
        } elseif (isset($perk['times'])) {
            $effects[$perk['type']] *= $perk['times'];
        } elseif (isset($perk['value'])) {
            $effects[$perk['type']] += $perk['value'];
        }
    }
    return $cache[$done] = $effects;
}

/**
 * The stages of Engine $number (HBHUB_MINER_ENGINES), stage 1 first: its part of HBHUB_MINER_ENGINE_STAGES, [] for one
 * that isn't there yet.
 */
function hbHubMinerEngineStages(int $number): array
{
    return array_slice(HBHUB_MINER_ENGINE_STAGES, ($number - 1) * HBHUB_MINER_ENGINE_STAGE_TOTAL, HBHUB_MINER_ENGINE_STAGE_TOTAL);
}

/**
 * The number of the Engine being built, from the save's engineStage (once one is done, the next is: one that isn't in
 * HBHUB_MINER_ENGINES yet just has no stages).
 */
function hbHubMinerEngineNumber(array $state): int
{
    return intdiv((int) $state['engineStage'], HBHUB_MINER_ENGINE_STAGE_TOTAL) + 1;
}

/**
 * Whether a stage with a perk of $type is done (for the ones that aren't numbers, like backdrops or sparks).
 */
function hbHubMinerEnginePerk(array $state, string $type): bool
{
    foreach (array_slice(HBHUB_MINER_ENGINE_STAGES, 0, (int) $state['engineStage']) as $stage) {
        if ($stage['perk']['type'] === $type) {
            return true;
        }
    }
    return false;
}

/**
 * What Ignition does to production at the game's time: HBHUB_MINER_IGNITION_TIMES for HBHUB_MINER_IGNITION_MS after the
 * Engine was ignited ('engineIgnited'), 1 before and after.
 */
function hbHubMinerIgnition(array $state): float
{
    $ignited = $state['engineIgnited'];
    return $ignited > 0 && $state['tick'] >= $ignited && $state['tick'] < $ignited + HBHUB_MINER_IGNITION_MS ? HBHUB_MINER_IGNITION_TIMES : 1;
}

/**
 * What an Anomaly's Afterglow does to production at the game's time: HBHUB_MINER_AFTERGLOW_TIMES until 'afterglow',
 * 1 after it (and in an Anomaly's game, which never has one).
 */
function hbHubMinerAfterglow(array $state): float
{
    return $state['tick'] < $state['afterglow'] ? HBHUB_MINER_AFTERGLOW_TIMES : 1;
}

/**
 * What a part given from ore is worth now, in quarter parts: the share of the section the stage being built is in,
 * and never less than the oreFloor perk.
 */
function hbHubMinerOreQuarters(array $state): int
{
    $index = min((int) $state['engineStage'], count(HBHUB_MINER_ENGINE_STAGES) - 1);
    return max(HBHUB_MINER_ENGINE_ORE_SHARE[HBHUB_MINER_ENGINE_STAGES[$index]['section']], (int) hbHubMinerEngineEffects($state)['oreFloor']);
}

/**
 * How many parts from ore can be given a day: HBHUB_MINER_ENGINE_ORE_PARTS and the oreParts perks.
 */
function hbHubMinerOrePartsMax(array $state): int
{
    return HBHUB_MINER_ENGINE_ORE_PARTS + (int) hbHubMinerEngineEffects($state)['oreParts'];
}

/**
 * Gives $gift ([source, parts, ...], see hbHubMinerApply()) to the Engine: into $given, and counted in 'engineGiven'.
 */
function hbHubMinerGive(array &$state, array &$given, array $gift): void
{
    $given[] = $gift;
    $state['engineGiven'] += $gift[1];
}

/**
 * Whether the player can give parts to the Engine right now: it's taking them, and they've made a Warp Jump.
 */
function hbHubMinerCanGive(array $state): bool
{
    return $state['engineOpen'] && $state['warps'] >= 1;
}

/**
 * Starts the daily counts (donatedToday, shifts) over when the game's time is on a new UTC day.
 */
function hbHubMinerEngineDay(array &$state): void
{
    $day = (int) floor($state['tick'] / HBHUB_MINER_DAY_MS);
    if ($day !== $state['engineDay']) {
        $state['engineDay'] = $day;
        $state['donatedToday'] = 0;
        $state['shifts'] = array_fill(0, HBHUB_MINER_SHIFT_COUNT, 0);
        $state['shiftSwap'] = -1;
    }
}

/**
 * The day's Engine Shifts (keys of HBHUB_MINER_SHIFTS), the same for everyone: picked one by one from the ones left,
 * with luck that only depends on the day.
 */
function hbHubMinerShiftTasks(int $day): array
{
    static $cache = [];
    if (isset($cache[$day])) {
        return $cache[$day];
    }
    $left = array_keys(HBHUB_MINER_SHIFTS);
    $tasks = [];
    for ($i = 0; $i < HBHUB_MINER_SHIFT_COUNT; $i++) {
        $index = (int) floor(hbHubMinerRandom(0, $day * HBHUB_MINER_SHIFT_COUNT + $i, HBHUB_MINER_SALT_SHIFT) * count($left));
        $tasks[] = $left[$index];
        array_splice($left, $index, 1);
    }
    return $cache[$day] = $tasks;
}

/**
 * The day's spare Shift, that one of the day's can be swapped for (hbHubMinerShiftSwap()): one of the ones left, the
 * same for everyone.
 */
function hbHubMinerShiftSpare(int $day): string
{
    $left = array_values(array_diff(array_keys(HBHUB_MINER_SHIFTS), hbHubMinerShiftTasks($day)));
    return $left[(int) floor(hbHubMinerRandom(0, $day, HBHUB_MINER_SALT_SHIFT_SPARE) * count($left))];
}

/**
 * The player's Shifts today: the day's, with the one they swapped (shiftSwap) as the spare one instead. Call
 * hbHubMinerEngineDay() first.
 */
function hbHubMinerPlayerShifts(array $state): array
{
    $tasks = hbHubMinerShiftTasks($state['engineDay']);
    if ($state['shiftSwap'] >= 0) {
        $tasks[$state['shiftSwap']] = hbHubMinerShiftSpare($state['engineDay']);
    }
    return $tasks;
}

/**
 * Swaps today's Shift $index for the spare one (with the shiftSwap perk, once a day, and only one that isn't done):
 * it starts from nothing. Returns false when it can't.
 */
function hbHubMinerShiftSwap(array &$state, mixed $index): bool
{
    if (!is_int($index) || $index < 0 || $index >= HBHUB_MINER_SHIFT_COUNT || !hbHubMinerCanGive($state)
        || hbHubMinerEngineEffects($state)['shiftSwap'] < 1) {
        return false;
    }
    hbHubMinerEngineDay($state);
    $task = hbHubMinerPlayerShifts($state)[$index];
    if ($state['shiftSwap'] >= 0 || $state['shifts'][$index] >= HBHUB_MINER_SHIFTS[$task]['goal']) {
        return false;
    }
    $state['shiftSwap'] = $index;
    $state['shifts'][$index] = 0;
    return true;
}

/**
 * Counts $amount towards today's Shift $task, if it's one of the player's (hbHubMinerPlayerShifts()). A Shift
 * that's just been done goes in $given as [HBHUB_MINER_ENGINE_SOURCE_SHIFT, parts]. $given is null when the player
 * can't give parts at all (guests), and then nothing is counted either.
 */
function hbHubMinerShift(array &$state, string $task, int $amount, ?array &$given): void
{
    if ($given === null || !hbHubMinerCanGive($state)) {
        return;
    }
    hbHubMinerEngineDay($state);
    $index = array_search($task, hbHubMinerPlayerShifts($state), true);
    $goal = HBHUB_MINER_SHIFTS[$task]['goal'];
    if ($index === false || $state['shifts'][$index] >= $goal) {
        return;
    }
    $state['shifts'][$index] = min($goal, $state['shifts'][$index] + $amount);
    if ($state['shifts'][$index] < $goal) {
        return;
    }
    $done = 0;
    foreach (hbHubMinerPlayerShifts($state) as $i => $id) {
        $done += $state['shifts'][$i] >= HBHUB_MINER_SHIFTS[$id]['goal'] ? 1 : 0;
    }
    if ($done === HBHUB_MINER_SHIFT_COUNT) {
        $state['shiftDays']++;
    }
    hbHubMinerGive($state, $given, [HBHUB_MINER_ENGINE_SOURCE_SHIFT, $done === HBHUB_MINER_SHIFT_COUNT
        ? HBHUB_MINER_SHIFT_PARTS_ALL * HBHUB_MINER_SHIFT_COUNT - HBHUB_MINER_SHIFT_PARTS * (HBHUB_MINER_SHIFT_COUNT - 1) : HBHUB_MINER_SHIFT_PARTS]);
}

/**
 * The ore the next $parts parts from ore cost together today: the day's first HBHUB_MINER_ENGINE_ORE_PART, each next
 * one ten times the one before. Multiplied up, so the script gets exactly the same.
 */
function hbHubMinerOreDonationCost(array $state, int $parts): float
{
    $part = HBHUB_MINER_ENGINE_ORE_PART;
    for ($i = 0; $i < $state['donatedToday']; $i++) {
        $part *= 10;
    }
    $cost = 0.0;
    for ($i = 0; $i < $parts; $i++) {
        $cost += $part;
        $part *= 10;
    }
    return $cost;
}

/**
 * The production each Dark Matter earned adds (0.05 = +5%).
 */
function hbHubMinerDarkBonus(array $state): float
{
    return (hbHubMinerHasDark($state, 'reactor') ? HBHUB_MINER_DARK_BONUS_REACTOR : HBHUB_MINER_DARK_BONUS)
        + hbHubMinerSkillEffects($state)['darkBonus'];
}

/**
 * What Antimatter does to production: 1 + HBHUB_MINER_ANTI_BONUS (and Dense Antimatter) for each one earned in this
 * multiverse. A Warp Jump gets the square root of it (hbHubMinerWarpGain()).
 */
function hbHubMinerAntiMultiplier(array $state): float
{
    return 1 + (HBHUB_MINER_ANTI_BONUS + hbHubMinerSkillEffects($state)['antiBonus']) * $state['antimatterEarned'];
}

/**
 * What Strange Matter does to production: 1 + HBHUB_MINER_STRANGE_BONUS for each one ever earned.
 */
function hbHubMinerStrangeMultiplier(array $state): float
{
    return 1 + HBHUB_MINER_STRANGE_BONUS * $state['strangeEarned'];
}

/**
 * How much stronger comets are: 2 with Antimatter Comets, times the comet skills.
 */
function hbHubMinerCometPower(array $state): float
{
    return (hbHubMinerHasAnti($state, 'antiComets') ? 2 : 1) * hbHubMinerSkillEffects($state)['cometPower'];
}

/**
 * What an ore upgrade costs: its price, less with Bargain Hunter and the Engine's Ignition Coils.
 */
function hbHubMinerUpgradeCost(array $state, string $upgradeId): float
{
    return hbHubMinerUpgrades()[$upgradeId]['cost'] * hbHubMinerSkillEffects($state)['upgradeCost'] * hbHubMinerEngineEffects($state)['upgradeCost'];
}

/**
 * How much more each of a building costs than the one before: its own 'growth', or HBHUB_MINER_COST_GROWTH (for
 * every building HBHUB_MINER_INFLATION_GROWTH in Inflation), less HBHUB_MINER_COST_GROWTH_PHYSICS with Cheaper Physics.
 */
function hbHubMinerCostGrowth(array $state, string $buildingId): float
{
    return (hbHubMinerMod($state, 'inflation') ? HBHUB_MINER_INFLATION_GROWTH : HBHUB_MINER_BUILDINGS[$buildingId]['growth'] ?? HBHUB_MINER_COST_GROWTH)
        - (hbHubMinerHasDark($state, 'physics') ? HBHUB_MINER_COST_GROWTH_PHYSICS : 0);
}

/**
 * Whether $amount more of a building can be bought, ore aside: not one the Unstable Vacuum destroyed, not past
 * HBHUB_MINER_MINIMALIST_MAX in all in Minimalist, and in One Track only the kind the run already has (every run
 * starts with none there, see hbHubMinerStartBuildings(), so that's the one bought first).
 */
function hbHubMinerCanBuild(array $state, string $buildingId, int $amount): bool
{
    if (in_array($buildingId, $state['destroyed'], true)) {
        return false;
    }
    if (hbHubMinerMod($state, 'minimalist') && array_sum($state['buildings']) + $amount > HBHUB_MINER_MINIMALIST_MAX) {
        return false;
    }
    if (hbHubMinerMod($state, 'oneTrack')) {
        foreach ($state['buildings'] as $id => $count) {
            if ($id !== $buildingId && $count > 0) {
                return false;
            }
        }
    }
    return true;
}

/**
 * How long a run goes before each halving in Entropy: HBHUB_MINER_ENTROPY_MS, or HBHUB_MINER_GAUNTLET_ENTROPY_MS in The
 * Gauntlet.
 */
function hbHubMinerEntropyMs(array $state): int
{
    return hbHubMinerMod($state, 'gauntlet') ? HBHUB_MINER_GAUNTLET_ENTROPY_MS : HBHUB_MINER_ENTROPY_MS;
}

/**
 * The most halvings Entropy makes in a run: HBHUB_MINER_ENTROPY_STEPS, or HBHUB_MINER_GAUNTLET_ENTROPY_STEPS in The Gauntlet.
 */
function hbHubMinerEntropySteps(array $state): int
{
    return hbHubMinerMod($state, 'gauntlet') ? HBHUB_MINER_GAUNTLET_ENTROPY_STEPS : HBHUB_MINER_ENTROPY_STEPS;
}

/**
 * What Entropy leaves of production: halved for every whole hbHubMinerEntropyMs() of the run so far (1 outside it).
 * Powers of two, which a float holds exactly, so the script gets the same to the last digit.
 */
function hbHubMinerEntropy(array $state): float
{
    if (!hbHubMinerMod($state, 'entropy')) {
        return 1.0;
    }
    return 0.5 ** min(hbHubMinerEntropySteps($state), intdiv((int) max(0, $state['tick'] - $state['runStarted']), hbHubMinerEntropyMs($state)));
}

/**
 * What the next $amount of a building cost together, rounded up: each costs hbHubMinerCostGrowth() times the one before,
 * and all of them less with the discount skills.
 */
function hbHubMinerBuildingCost(array $state, string $buildingId, int $amount): float
{
    $growth = hbHubMinerCostGrowth($state, $buildingId);
    return ceil(HBHUB_MINER_BUILDINGS[$buildingId]['cost'] * hbHubMinerSkillEffects($state)['cost'] * hbHubMinerEngineEffects($state)['cost']
        * $growth ** $state['buildings'][$buildingId]
        * ($growth ** $amount - 1) / ($growth - 1));
}

/**
 * Whether there's enough ore for something costing $cost. A tiny bit of leeway, as the script and PHP can round the
 * last digit of a big number differently.
 */
function hbHubMinerCanAfford(array $state, float $cost): bool
{
    return $cost <= $state['ore'] * (1 + 1e-9) + 1e-9;
}

/**
 * The ore per second from each building (id => all of them together), with upgrades, Dark Matter, Antimatter, Strange
 * Matter and skills but without comets.
 */
function hbHubMinerBuildingRates(array $state): array
{
    $upgrades = hbHubMinerUpgrades();
    $effects = hbHubMinerSkillEffects($state);
    $multipliers = array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 1.0);
    $crew = array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0.0);
    $global = (1 + hbHubMinerDarkBonus($state) * $state['darkEarned']) * hbHubMinerAntiMultiplier($state) * hbHubMinerStrangeMultiplier($state);
    if (hbHubMinerHasDark($state, 'darkEngines')) {
        $global *= 1.5;
    }
    if (hbHubMinerHasAnti($state, 'annihilation')) {
        $global *= 2;
    }
    $global *= $effects['production'] * (1 + $effects['perSkill'] * count($state['skills'])) * (1 + $effects['perWarp'] * $state['flights'])
        * (1 + $effects['perCrunch'] * $state['crunches']) * hbHubMinerEngineEffects($state)['production'] * hbHubMinerIgnition($state)
        * hbHubMinerAfterglow($state);
    $global *= hbHubMinerEntropy($state);
    foreach ($state['upgrades'] as $upgradeId) {
        $upgrade = $upgrades[$upgradeId];
        if ($upgrade['type'] === 'building') {
            $multipliers[$upgrade['building']] *= 2 * $effects['buildingUpgrade'];
        } elseif ($upgrade['type'] === 'synergy') {
            $multipliers[$upgrade['building']] *= 1 + $upgrade['per'] * floor($state['buildings'][$upgrade['source']] / $upgrade['every']);
        } elseif ($upgrade['type'] === 'crew') {
            $crew[$upgrade['building']] += $upgrade['value'] * $effects['crew'];
        } elseif ($upgrade['type'] === 'global') {
            $global *= 1 + $upgrade['value'];
        }
    }

    $rates = [];
    $owned = array_sum($state['buildings']);
    $global *= 1 + $effects['owned'] * $owned;
    foreach (HBHUB_MINER_BUILDINGS as $buildingId => $building) {
        $rate = $building['rate'] + $crew[$buildingId] * ($owned - $state['buildings'][$buildingId]);
        $rates[$buildingId] = $rate * $state['buildings'][$buildingId] * $multipliers[$buildingId] * $global * ($effects['buildings'][$buildingId] ?? 1);
    }
    return $rates;
}

/**
 * The comet effects' multiplier on 'production' or 'click' at $time: all the ones running on it multiplied together (1
 * when none is). $time PHP_INT_MAX leaves comets out.
 */
function hbHubMinerBuff(array $state, string $buff, int $time): float
{
    $multiplier = 1.0;
    foreach ($state['buffs'] as $running) {
        if (HBHUB_MINER_COMETS[$running['kind']]['buff'] === $buff && $running['until'] > $time) {
            $multiplier *= $running['multiplier'];
        }
    }
    return $multiplier;
}

/**
 * What one click mines at $time, before a critical click: 1 (3 with Dark Pickaxe), doubled by each click upgrade (and
 * times the click skills), plus the clickPct upgrades' (and skills') share of the buildings' production (and
 * HBHUB_MINER_HANDS_SHARE more in Hands Only, where that's all they make), all times a Click Storm and the Engine's
 * Gyroscopes. Nothing at all in Dead Hands (auto-clickers' clicks included, see hbHubMinerRate()).
 */
function hbHubMinerClickValue(array $state, int $time): float
{
    if (hbHubMinerMod($state, 'deadHands')) {
        return 0.0;
    }
    $upgrades = hbHubMinerUpgrades();
    $effects = hbHubMinerSkillEffects($state);
    $value = hbHubMinerHasDark($state, 'darkPickaxe') ? (float) HBHUB_MINER_DARK_PICKAXE : 1.0;
    $share = 0.0;
    foreach ($state['upgrades'] as $upgradeId) {
        if ($upgrades[$upgradeId]['type'] === 'click') {
            $value *= $upgrades[$upgradeId]['value'];
        } elseif ($upgrades[$upgradeId]['type'] === 'clickPct') {
            $share += $upgrades[$upgradeId]['value'];
        }
    }
    $value *= $effects['click'];
    $share += $effects['clickShare'] + (hbHubMinerMod($state, 'handsOnly') ? HBHUB_MINER_HANDS_SHARE : 0);
    $production = $share > 0 ? array_sum(hbHubMinerBuildingRates($state)) * hbHubMinerBuff($state, 'production', $time) : 0;
    return ($value + $share * $production) * hbHubMinerBuff($state, 'click', $time) * hbHubMinerEngineEffects($state)['click'];
}

/**
 * How many times a second the auto upgrades (Auto-Clicker Bot and Swarm) and skills click, together.
 */
function hbHubMinerAutoClicks(array $state): float
{
    $upgrades = hbHubMinerUpgrades();
    $clicks = 0.0;
    foreach ($state['upgrades'] as $upgradeId) {
        if ($upgrades[$upgradeId]['type'] === 'auto') {
            $clicks += $upgrades[$upgradeId]['value'];
        }
    }
    return $clicks + hbHubMinerSkillEffects($state)['auto'];
}

/**
 * All the ore per second at $time: the buildings (times a production comet, and not at all in Hands Only) and the
 * auto-clickers. $time PHP_INT_MAX gives the steady rate, without comets, as the leaderboard shows it.
 */
function hbHubMinerRate(array $state, int $time): float
{
    $rate = hbHubMinerMod($state, 'handsOnly') ? 0.0 : array_sum(hbHubMinerBuildingRates($state)) * hbHubMinerBuff($state, 'production', $time);
    $autoClicks = hbHubMinerAutoClicks($state);
    if ($autoClicks > 0) {
        $rate += $autoClicks * hbHubMinerClickValue($state, $time);
    }
    return $rate;
}

function hbHubMinerEarn(array &$state, float $ore): void
{
    // Never past the biggest float: INF can't be saved as JSON, so the save would fail from then on
    foreach (['ore', 'runOre', 'universeOre', 'allOre'] as $key) {
        $state[$key] = min($state[$key] + $ore, PHP_FLOAT_MAX);
    }
}

/**
 * How long comet number $n waits after the one before, in ms.
 */
function hbHubMinerCometWait(array $state, int $n): int
{
    [$min, $max] = HBHUB_MINER_COMET_WAIT_MS;
    $wait = $min + hbHubMinerRandom($state['seed'], $n, HBHUB_MINER_SALT_COMET_WAIT) * ($max - $min);
    return (int) floor((hbHubMinerHas($state, 'radar') ? $wait / 2 : $wait) * hbHubMinerSkillEffects($state)['cometWait']
        * hbHubMinerEngineEffects($state)['cometWait']);
}

/**
 * How long a comet stays on screen, in ms: doubled with Comet Magnet, times the skills, plus the Engine's.
 */
function hbHubMinerCometStay(array $state): int
{
    return (int) floor((hbHubMinerHas($state, 'magnet') ? HBHUB_MINER_COMET_STAY_MS * 2 : HBHUB_MINER_COMET_STAY_MS)
        * hbHubMinerSkillEffects($state)['cometStay']) + hbHubMinerEngineEffects($state)['cometStay'];
}

/**
 * How long a comet's effect lasts, in ms: its own 'ms', doubled with Tail Collector, times the comet skills, and for
 * the Golden ones (not Diamond Comets) times the Engine's.
 */
function hbHubMinerCometLength(array $state, string $kind): int
{
    return (int) floor(HBHUB_MINER_COMETS[$kind]['ms'] * (hbHubMinerHas($state, 'tail') ? 2 : 1) * hbHubMinerSkillEffects($state)['cometLength']
        * ($kind === 'diamond' ? 1 : hbHubMinerEngineEffects($state)['cometLength']));
}

/**
 * What the current comet does when caught (a key of HBHUB_MINER_COMETS). It can be a Diamond Comet with Lucky Stars or
 * Strange Diamonds.
 */
function hbHubMinerCometKind(array $state): string
{
    $n = $state['comet']['n'];
    $diamondChance = (hbHubMinerHas($state, 'luckyStars') ? HBHUB_MINER_DIAMOND_CHANCE : 0) + hbHubMinerSkillEffects($state)['diamond'];
    if ($diamondChance > 0) { // the Engine's Plasma Chamber only adds to a chance there is
        $diamondChance += hbHubMinerEngineEffects($state)['diamond'];
    }
    if ($diamondChance > 0 &&hbHubMinerRandom($state['seed'], $n, HBHUB_MINER_SALT_DIAMOND) < $diamondChance) {
        return 'diamond';
    }
    $roll = hbHubMinerRandom($state['seed'], $n, HBHUB_MINER_SALT_COMET_KIND);
    return $roll < 0.45 ? 'frenzy' : ($roll < 0.9 ? 'lucky' : 'storm');
}

/**
 * Lines up the comets that flew past uncaught before the game's time: each next one waits after the one before left
 * the screen, so it comes out the same however often this is called. After a long time away, the first comet is just
 * put after now.
 */
function hbHubMinerCometsPassed(array &$state): void
{
    for ($skipped = 0; $state['tick'] > $state['comet']['at'] + hbHubMinerCometStay($state); $skipped++) {
        $leftAt = $state['comet']['at'] + hbHubMinerCometStay($state);
        $state['comet']['n']++;
        $state['comet']['at'] = ($skipped < 10000 ? $leftAt : $state['tick']) + hbHubMinerCometWait($state, $state['comet']['n']);
    }
}

/**
 * Moves the game on to $to (Unix ms): ore mined meanwhile, comet effects running out on time, and comets flying past.
 * Time after $fullUntil is time away, mined at HBHUB_MINER_OFFLINE_RATE (or the Engine's, doubled with Time Dilation,
 * times Time Loop). Time before it counts towards the "playing" Engine Shift ($given: see hbHubMinerShift()).
 * $runOreGoal: stops early, at the first ms the ore mined this run reaches it (an Anomaly's goal that grows with time,
 * see hbHubMinerAdvanceGame()); the rate is the same all through a piece, so that ms is worked out the same here and
 * in the script. Returns the ore mined while away.
 */
function hbHubMinerAdvance(array &$state, int $to, int $fullUntil = PHP_INT_MAX, ?array &$given = null, float $runOreGoal = INF): float
{
    $offlineOre = 0.0;
    while ($state['tick'] < $to && $state['runOre'] < $runOreGoal) {
        // In pieces, so the rate is the same all through each: up to an effect running out, going away, or midnight
        // (UTC, for the Shifts, which start over then)
        $next = $to;
        foreach ($state['buffs'] as $buff) {
            if ($buff['until'] > $state['tick'] && $buff['until'] < $next) {
                $next = $buff['until'];
            }
        }
        if ($fullUntil > $state['tick'] && $fullUntil < $next) {
            $next = $fullUntil;
        }
        $midnight = (int) ((floor($state['tick'] / HBHUB_MINER_DAY_MS) + 1) * HBHUB_MINER_DAY_MS);
        if ($midnight < $next) {
            $next = $midnight;
        }
        // Entropy's next halving (hbHubMinerEntropy())
        $steps = intdiv((int) max(0, $state['tick'] - $state['runStarted']), hbHubMinerEntropyMs($state));
        if (hbHubMinerMod($state, 'entropy') && $steps < hbHubMinerEntropySteps($state)) {
            $halving = $state['runStarted'] + ($steps + 1) * hbHubMinerEntropyMs($state);
            if ($halving < $next) {
                $next = $halving;
            }
        }
        // Ignition starting or running out (hbHubMinerIgnition())
        foreach ([$state['engineIgnited'], $state['engineIgnited'] + HBHUB_MINER_IGNITION_MS] as $edge) {
            if ($state['engineIgnited'] > 0 && $edge > $state['tick'] && $edge < $next) {
                $next = $edge;
            }
        }
        // The Afterglow running out (hbHubMinerAfterglow())
        if ($state['afterglow'] > $state['tick'] && $state['afterglow'] < $next) {
            $next = $state['afterglow'];
        }

        $rate = hbHubMinerRate($state, $state['tick']);
        $share = $state['tick'] >= $fullUntil ? hbHubMinerEngineEffects($state)['offline'] * (hbHubMinerHasDark($state, 'timeDilation') ? 2 : 1)
            * hbHubMinerSkillEffects($state)['offline'] : 1.0;
        $ore = $rate * ($next - $state['tick']) / 1000 * $share;
        if ($runOreGoal < INF && $ore > 0 && $state['runOre'] + $ore >= $runOreGoal) { // the goal is reached in this piece
            $next = min($next, $state['tick'] + max(1, (int) ceil(($runOreGoal - $state['runOre']) / $ore * ($next - $state['tick']))));
            $ore = $rate * ($next - $state['tick']) / 1000 * $share;
        }
        if ($state['tick'] >= $fullUntil) {
            $offlineOre += $ore;
        } else {
            hbHubMinerShift($state, 'playing', $next - $state['tick'], $given);
        }
        hbHubMinerEarn($state, $ore);
        $state['tick'] = $next;
        $state['buffs'] = array_filter($state['buffs'], static fn (array $buff): bool => $buff['until'] > $next);
    }
    hbHubMinerCometsPassed($state);
    return $offlineOre;
}

/**
 * Whether an upgrade shows up in the store (bought or not): its need (ore this run, buildings owned, comets caught,
 * upgrades bought). None ever does in Bare Metal.
 */
function hbHubMinerUpgradeUnlocked(array $state, array $upgrade): bool
{
    if (hbHubMinerMod($state, 'bareMetal')) {
        return false;
    }
    $need = $upgrade['need'];
    if (isset($need['ore']) && $state['runOre'] < $need['ore']) {
        return false;
    }
    if (isset($need['comets']) && $state['comets'] < $need['comets']) {
        return false;
    }
    foreach ($need['upgrades'] ?? [] as $upgradeId) {
        if (!hbHubMinerHas($state, $upgradeId)) {
            return false;
        }
    }
    foreach ($need['owned'] ?? [] as $buildingId => $count) {
        if ($state['buildings'][$buildingId] < $count) {
            return false;
        }
    }
    return true;
}

/**
 * The Dark Matter all the ore mined in this universe is worth together, before Antimatter and Matter Converter (see
 * hbHubMinerWarpGain()): the cube root of how many HBHUB_MINER_DARK_UNITs it is (so 1 for a billion, 10 for a
 * trillion, 100 for a quadrillion). A Warp Jump gives what that's gone up by since the last one. A square root made
 * every jump double the one before, until the numbers ran away. A float like the script's, as it can go past what an
 * int holds.
 */
function hbHubMinerDarkTotal(float $universeOre): float
{
    // ** (1 / 3) is a hair off (1000 gives 9.999...), so it's put right with whole numbers, which are exact. It's
    // never more than one off, so once each way (a loop never stopped on huge numbers, where + 1 changes nothing).
    $units = $universeOre / HBHUB_MINER_DARK_UNIT;
    $total = floor($units ** (1 / 3));
    if (($total + 1) ** 3 <= $units) {
        $total++;
    }
    if ($total > 0 && $total ** 3 > $units) {
        $total--;
    }
    return $total;
}

/**
 * The Dark Matter a Warp Jump would give now, or 0 when it can't be done yet (HBHUB_MINER_WARP_ORE not mined this run).
 * Antimatter (and Matter Converter and the skills) make the whole total bigger, which only ever goes up within a
 * universe. Antimatter only by the square root of what it does to production: with all of it, each Big Crunch gave
 * Antimatter in proportion to what you had (it speeds up the ore, the Dark Matter that ore is worth, and the Dark
 * Matter bonus to the ore), so it doubled again and again, faster each time, until it ran into Infinity and the
 * save couldn't be written any more.
 */
function hbHubMinerWarpGain(array $state): float
{
    if ($state['runOre'] < HBHUB_MINER_WARP_ORE) {
        return 0;
    }
    $total = floor(hbHubMinerDarkTotal($state['universeOre']) * sqrt(hbHubMinerAntiMultiplier($state))
        * (hbHubMinerHasAnti($state, 'converter') ? 2 : 1) * hbHubMinerSkillEffects($state)['darkGain'] * hbHubMinerEngineEffects($state)['darkGain']);
    return max(0, $total - $state['darkEarned']);
}

/**
 * The Antimatter the Dark Matter earned in a universe is worth: the square root of how many HBHUB_MINER_ANTI_UNITs it
 * is (so 4 for 2,000, 10 for 10,000, 20 for 40,000), put right with whole numbers so it's exact. (Counting up one by
 * one got slow once Dark Matter ran into the trillions.) The square root is never more than one off, so it's put right
 * once each way: a loop never stopped on huge numbers, where adding 1 no longer changes a float.
 */
function hbHubMinerAntiTotal(float $darkEarned): float
{
    $total = floor(sqrt($darkEarned / HBHUB_MINER_ANTI_UNIT));
    if (($total + 1) ** 2 * HBHUB_MINER_ANTI_UNIT <= $darkEarned) {
        $total++;
    }
    if ($total > 0 && $total ** 2 * HBHUB_MINER_ANTI_UNIT > $darkEarned) {
        $total--;
    }
    return $total;
}

/**
 * The Antimatter a Big Crunch would give now, or 0 when it can't be done yet (HBHUB_MINER_CRUNCH_DARK not earned in
 * this universe, less with Shallow Universe). Dark Matter starts over with every crunch, so each one gives what all of
 * it is worth, times the Antimatter skills.
 */
function hbHubMinerCrunchGain(array $state): float
{
    $effects = hbHubMinerSkillEffects($state);
    return $state['darkEarned'] >= HBHUB_MINER_CRUNCH_DARK * $effects['crunchDark']
        ? floor(hbHubMinerAntiTotal((float) $state['darkEarned']) * $effects['antiGain'] * hbHubMinerEngineEffects($state)['antiGain']) : 0;
}

/**
 * The Strange Matter the Antimatter earned in a multiverse is worth: one for every step it's passed, the steps being
 * HBHUB_MINER_STRANGE_UNIT times each of HBHUB_MINER_STRANGE_STEPS times a power of ten (so with 1,000 and 20 steps: 1
 * at 1,000, 21 at 10,000, 41 at 100,000). It has to grow this slowly: for a while it was a cube root, but once the
 * cheap skills were learned, the Strange Matter bonus and the skills lifted the Antimatter a multiverse reaches from
 * about 10^9 to 10^29, and the whole tree was learned within an hour of the first rip, with billions of Strange Matter
 * after it (which can't be taken back). With steps it's at most 20 for every ten times as much. Only multiplications
 * and comparisons, so the script gets exactly the same.
 */
function hbHubMinerStrangeTotal(float $antimatterEarned): int
{
    $total = 0;
    $decade = (float) HBHUB_MINER_STRANGE_UNIT;
    while ($decade * 10 <= $antimatterEarned && $decade * 10 < INF) {
        $decade *= 10;
        $total += count(HBHUB_MINER_STRANGE_STEPS);
    }
    foreach (HBHUB_MINER_STRANGE_STEPS as $step) {
        if ($decade * $step <= $antimatterEarned) {
            $total++;
        }
    }
    return $total;
}

/**
 * The Strange Matter a Big Rip would give now, or 0 when it can't be done yet (HBHUB_MINER_RIP_ANTI not earned in this
 * multiverse). Like a Warp Jump, it's only what this multiverse is worth (times the Strange Matter skills) on top of
 * all the Strange Matter ever earned, so each rip has to beat the best one before it. When every rip gave all it was
 * worth, the Strange Matter bonus made the next 10,000 Antimatter quicker each time, until rips took seconds and the
 * whole tree was learned three hours after the first one.
 */
function hbHubMinerRipGain(array $state): int
{
    if ($state['antimatterEarned'] < HBHUB_MINER_RIP_ANTI) {
        return 0;
    }
    $total = (int) floor(hbHubMinerStrangeTotal((float) $state['antimatterEarned'])
        * hbHubMinerSkillEffects($state)['strangeGain']);
    return max(0, $total - $state['strangeEarned']);
}

/**
 * Clicks the asteroid $count times at the game's time. $auto: the page found they look like an auto-clicker's, so each
 * only mines HBHUB_MINER_AUTO_VALUE. Returns the ore mined.
 */
function hbHubMinerClick(array &$state, int $count, bool $auto = false): float
{
    $effects = hbHubMinerSkillEffects($state);
    $value = hbHubMinerClickValue($state, $state['tick']) * ($auto ? HBHUB_MINER_AUTO_VALUE : 1);
    $canCrit = hbHubMinerHas($state, 'crit') || $effects['crit'] > 0;
    $critChance = HBHUB_MINER_CRIT_CHANCE + $effects['crit'];
    $critMultiplier = HBHUB_MINER_CRIT_MULTIPLIER * $effects['critPower'];
    $mined = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $ore = $value;
        if ($canCrit && hbHubMinerRandom($state['seed'], $state['clicks'], HBHUB_MINER_SALT_CRIT) < $critChance) {
            $ore *= $critMultiplier;
            $state['crits']++;
        }
        $state['clicks']++;
        $mined += $ore;
    }
    $state['clickOre'] += $mined;
    hbHubMinerEarn($state, $mined);
    return $mined;
}

/**
 * Catches the comet at the game's time, if it's on screen. Returns its kind, or null when there wasn't one (never
 * one in Dark Sky).
 */
function hbHubMinerCatchComet(array &$state): ?string
{
    $time = $state['tick'];
    if (hbHubMinerMod($state, 'darkSky') || $time < $state['comet']['at'] || $time > $state['comet']['at'] + hbHubMinerCometStay($state)) {
        return null;
    }

    $kind = hbHubMinerCometKind($state);
    $comet = HBHUB_MINER_COMETS[$kind];
    if ($comet['buff'] !== null) {
        // The same comet's effect starts over (also one an older save keeps under 'production' or 'click'), and the
        // others keep running alongside it
        $state['buffs'] = array_filter($state['buffs'], static fn (array $buff): bool => $buff['kind'] !== $kind);
        $state['buffs'][$kind] = [
            'kind' => $kind,
            'multiplier' => $comet['multiplier'] * hbHubMinerCometPower($state),
            'until' => $time + hbHubMinerCometLength($state, $kind),
        ];
    } else {
        // In Hands Only, a click's share of the buildings' production, which is all they make there
        $production = array_sum(hbHubMinerBuildingRates($state)) * hbHubMinerBuff($state, 'production', $time)
            * (hbHubMinerMod($state, 'handsOnly') ? HBHUB_MINER_HANDS_SHARE : 1);
        [$min, $max] = HBHUB_MINER_LUCKY_SECONDS;
        $seconds = $min + hbHubMinerRandom($state['seed'], $state['comet']['n'], HBHUB_MINER_SALT_LUCKY) * ($max - $min);
        hbHubMinerEarn($state, ($production * $seconds + 13)
            * (hbHubMinerHas($state, 'stardust') ? 2 : 1) * hbHubMinerCometPower($state) * hbHubMinerSkillEffects($state)['luckyOre']);
    }
    $state['comets']++;
    if ($kind === 'diamond') {
        $state['diamonds']++;
    }
    $state['comet']['n']++;
    $state['comet']['at'] = $time + hbHubMinerCometWait($state, $state['comet']['n']);
    return $kind;
}

/**
 * The buildings a run starts with (id => how many, every building): the start skills', and Head Start's drones. None
 * in Minimalist and One Track, where they'd be past the limit or more than one kind, and none of the ones the Unstable
 * Vacuum destroyed.
 */
function hbHubMinerStartBuildings(array $state): array
{
    $start = array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0);
    if (hbHubMinerMod($state, 'minimalist') || hbHubMinerMod($state, 'oneTrack')) {
        return $start;
    }
    $start = array_replace($start, hbHubMinerSkillEffects($state)['start']);
    if (hbHubMinerHasDark($state, 'headStart')) {
        $start['drone'] = max($start['drone'], HBHUB_MINER_HEAD_START);
    }
    foreach ($state['destroyed'] as $buildingId) {
        $start[$buildingId] = 0;
    }
    if (hbHubMinerMod($state, 'gauntlet')) { // clicks mine nothing there, so without one a run could never start
        foreach (HBHUB_MINER_GAUNTLET_START as $buildingId => $count) {
            $start[$buildingId] = max($start[$buildingId], $count);
        }
    }
    return $start;
}

/**
 * Starts a new run, for a Warp Jump, Big Crunch or Big Rip: ore, buildings and upgrades start over, apart from the click
 * upgrades with Persistent Memory, Bulk Buying, Max Buying and the comet upgrades with Eternal Blueprints, the upgrades the keep
 * skills keep, the drones from Head Start, the buildings from the start skills and the ore from Big Bang Leftovers or
 * Strange Leftovers. In Bare Metal no upgrade is kept.
 */
function hbHubMinerNewRun(array &$state): void
{
    $upgrades = hbHubMinerUpgrades();
    $effects = hbHubMinerSkillEffects($state);
    $kept = array_merge(hbHubMinerHasDark($state, 'memory') ? HBHUB_MINER_CLICK_TYPES : [],
        hbHubMinerHasAnti($state, 'eternal') ? HBHUB_MINER_ETERNAL_TYPES : [], $effects['keep']);
    $state['upgrades'] = hbHubMinerMod($state, 'bareMetal') ? [] : array_values(array_filter($state['upgrades'], static fn (string $id): bool =>
        in_array($upgrades[$id]['type'], $kept, true)));
    $state['buildings'] = hbHubMinerStartBuildings($state);
    $state['ore'] = (float) max(hbHubMinerHasAnti($state, 'leftovers') ? HBHUB_MINER_LEFTOVER_ORE : 0, $effects['startOre']);
    $state['runOre'] = 0.0;
    $state['buffs'] = [];
    $state['runStarted'] = $state['tick'];
}

/**
 * Warp Jump: everything of this run starts over (hbHubMinerNewRun()), for the Dark Matter it's worth. In the Unstable
 * Vacuum, the building there's most of goes for good first (the dearer one when two are level). Returns false when it
 * can't be done yet, and always in Iron Universe.
 */
function hbHubMinerWarp(array &$state): bool
{
    $gain = hbHubMinerWarpGain($state);
    if ($gain < 1 || hbHubMinerMod($state, 'ironUniverse')) {
        return false;
    }

    if (hbHubMinerMod($state, 'unstableVacuum')) {
        $most = null;
        foreach ($state['buildings'] as $buildingId => $count) {
            if ($count > 0 && ($most === null || $count >= $state['buildings'][$most])) {
                $most = $buildingId;
            }
        }
        if ($most !== null) {
            $state['destroyed'][] = $most;
        }
    }
    hbHubMinerNewRun($state);
    if ($gain >= $state['darkEarned']) {
        $state['flights']++; // Frequent Flyer Miles only counts these (see hbHubMinerNewState())
    }
    $state['dark'] += $gain;
    $state['darkEarned'] += $gain;
    $state['warps']++;
    return true;
}

/**
 * Big Crunch: the whole universe starts over, the run (hbHubMinerNewRun()) and the Dark Matter with it, and its
 * upgrades too unless you have Dark Echo, for the Antimatter it's worth. Returns false when it can't be done yet.
 */
function hbHubMinerCrunch(array &$state): bool
{
    $gain = hbHubMinerCrunchGain($state);
    if ($gain < 1) {
        return false;
    }

    $state['dark'] = 0;
    $state['darkEarned'] = 0;
    if (!hbHubMinerHasAnti($state, 'echo')) {
        $state['darkUpgrades'] = [];
    }
    $state['universeOre'] = 0.0;
    $state['antimatter'] += $gain;
    $state['antimatterEarned'] += $gain;
    $state['crunches']++;
    hbHubMinerNewRun($state); // after the Dark Matter upgrades are gone, so Head Start etc. only count when kept
    return true;
}

/**
 * Big Rip: the whole multiverse starts over, everything a Big Crunch starts over and the Antimatter with it, and its
 * upgrades too unless you have Antimatter Echo (and with them Dark Echo, which keeps the Dark Matter upgrades), for the
 * Strange Matter it's worth. Returns false when it can't be done yet.
 */
function hbHubMinerRip(array &$state): bool
{
    $gain = hbHubMinerRipGain($state);
    if ($gain < 1) {
        return false;
    }

    $state['strange'] += $gain;
    $state['strangeEarned'] += $gain;
    $state['rips']++;
    hbHubMinerNewMultiverse($state);
    return true;
}

/**
 * What a Big Rip starts over (an Anomaly starts the same way): Antimatter and its upgrades (unless Antimatter Echo
 * keeps them), Dark Matter and its upgrades (unless Dark Echo does), the universe and the run.
 */
function hbHubMinerNewMultiverse(array &$state): void
{
    if (!hbHubMinerSkillEffects($state)['keepAnti']) {
        $state['antiUpgrades'] = [];
    }
    $state['antimatter'] = 0;
    $state['antimatterEarned'] = 0;
    if (!hbHubMinerHasAnti($state, 'echo')) {
        $state['darkUpgrades'] = [];
    }
    $state['dark'] = 0;
    $state['darkEarned'] = 0;
    $state['universeOre'] = 0.0;
    hbHubMinerNewRun($state);
}

// ----- Anomalies (HBHUB_MINER_ANOMALIES)

/**
 * The Anomalies in a key of the save's 'anomalies' (their ids joined with +, in HBHUB_MINER_ANOMALIES's order, each
 * once), or null when it isn't one. More than one is a stack, which only Anomalies of the tiers up to
 * HBHUB_MINER_ANOMALY_STACK_TIER can be in.
 *
 * @return ?list<string>
 */
function hbHubMinerAnomalyMods(string $key): ?array
{
    $mods = explode('+', $key);
    $order = array_keys(HBHUB_MINER_ANOMALIES);
    $last = -1;
    foreach ($mods as $id) {
        $at = array_search($id, $order, true);
        if ($at === false || $at <= $last || (count($mods) > 1 && HBHUB_MINER_ANOMALIES[$id]['tier'] > HBHUB_MINER_ANOMALY_STACK_TIER)) {
            return null;
        }
        $last = $at;
    }
    return $mods;
}

/**
 * The key an Anomaly's record goes under in 'anomalies' and the Engine (contributionAnomaly).
 */
function hbHubMinerAnomalyKey(array $mods): string
{
    return implode('+', $mods);
}

/**
 * The rules an Anomaly's game has (its 'mods'): each of $mods, and the ones in its 'rules' after it.
 *
 * @return list<string>
 */
function hbHubMinerAnomalyRules(array $mods): array
{
    $rules = [];
    foreach ($mods as $id) {
        foreach ([$id, ...(HBHUB_MINER_ANOMALIES[$id]['rules'] ?? [])] as $rule) {
            if (!in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }
    }
    return $rules;
}

/**
 * The goal of an Anomaly with these $mods: the lowest of their goals (with the highest, a stack with the Unstable
 * Vacuum, which runs out of Warp Jumps around its own, could never be done).
 */
function hbHubMinerAnomalyGoal(array $mods): float
{
    return (float) min(array_map(static fn (string $id): float => HBHUB_MINER_ANOMALIES[$id]['goal'], $mods));
}

/**
 * What the goal counts, a number in the Anomaly's game: Antimatter earned, unless the Anomaly says otherwise ('stat').
 * Only the Tier III ones do, and they don't stack, so it's the first one's.
 */
function hbHubMinerAnomalyStat(array $mods): string
{
    return HBHUB_MINER_ANOMALIES[$mods[0]]['stat'] ?? 'antimatterEarned';
}

/**
 * The Engine Parts finishing an Anomaly with these $mods is worth, before the first-time and best-time rules: each
 * one's (or its tier's) together, times HBHUB_MINER_ANOMALY_STACK for each after the first, HBHUB_MINER_ANOMALY_WEEKLY
 * for the weekly one, and the Engine's Navigation Computer. In the player's game ($state, for the Engine's perks).
 */
function hbHubMinerAnomalyParts(array $state, array $mods, bool $isWeekly): int
{
    $parts = 0;
    foreach ($mods as $id) {
        $parts += HBHUB_MINER_ANOMALIES[$id]['parts'] ?? HBHUB_MINER_ANOMALY_TIERS[HBHUB_MINER_ANOMALIES[$id]['tier']]['parts'];
    }
    return (int) floor($parts * HBHUB_MINER_ANOMALY_STACK ** (count($mods) - 1) * ($isWeekly ? HBHUB_MINER_ANOMALY_WEEKLY : 1)
        * hbHubMinerEngineEffects($state)['anomalyParts']);
}

/**
 * How long the Afterglow (hbHubMinerAfterglow()) from finishing an Anomaly with these $mods lasts, in ms: each one's
 * (or its tier's) added up, HBHUB_MINER_ANOMALY_WEEKLY times as long for the weekly one that week.
 */
function hbHubMinerAnomalyAfterglowMs(array $mods, bool $isWeekly): int
{
    $ms = 0;
    foreach ($mods as $id) {
        $ms += HBHUB_MINER_ANOMALIES[$id]['afterglow'] ?? HBHUB_MINER_ANOMALY_TIERS[HBHUB_MINER_ANOMALIES[$id]['tier']]['afterglow'];
    }
    return $ms * ($isWeekly ? HBHUB_MINER_ANOMALY_WEEKLY : 1);
}

/**
 * The week a time (Unix ms) is in: weeks since the one 1 January 1970 was in, each from Monday 00:00 UTC (that day was a
 * Thursday, so three days are added).
 */
function hbHubMinerWeek(int $time): int
{
    return (int) floor(($time + 3 * HBHUB_MINER_DAY_MS) / HBHUB_MINER_WEEK_MS);
}

/**
 * The weekly Anomaly for $week, the same for everyone: a stack of one of HBHUB_MINER_WEEKLY_SIZES, picked one by one
 * from the stackable Anomalies (apart from HBHUB_MINER_WEEKLY_LEFT_OUT) with luck that only depends on the week.
 *
 * @return list<string>
 */
function hbHubMinerWeeklyMods(int $week): array
{
    static $cache = [];
    if (isset($cache[$week])) {
        return $cache[$week];
    }
    $left = array_values(array_filter(array_keys(HBHUB_MINER_ANOMALIES), static fn (string $id): bool =>
        HBHUB_MINER_ANOMALIES[$id]['tier'] <= HBHUB_MINER_ANOMALY_STACK_TIER && !in_array($id, HBHUB_MINER_WEEKLY_LEFT_OUT, true)));
    $size = HBHUB_MINER_WEEKLY_SIZES[(int) floor(hbHubMinerRandom(0, $week * 10, HBHUB_MINER_SALT_WEEKLY) * count(HBHUB_MINER_WEEKLY_SIZES))];
    $picked = [];
    for ($i = 0; $i < $size; $i++) {
        $index = (int) floor(hbHubMinerRandom(0, $week * 10 + $i + 1, HBHUB_MINER_SALT_WEEKLY) * count($left));
        $picked[] = $left[$index];
        array_splice($left, $index, 1);
    }
    // In the Anomalies' own order, as a key has them
    return $cache[$week] = array_values(array_intersect(array_keys(HBHUB_MINER_ANOMALIES), $picked));
}

/**
 * Whether the player has finished $anomalyId, alone or in a stack.
 */
function hbHubMinerAnomalyDone(array $state, string $anomalyId): bool
{
    foreach ($state['anomalies'] as $key => $record) {
        if (in_array($anomalyId, explode('+', (string) $key), true)) {
            return true;
        }
    }
    return false;
}

/**
 * Whether the player's game ($state, not an Anomaly's) has opened Anomaly tier $tier.
 */
function hbHubMinerAnomalyTierOpen(array $state, int $tier): bool
{
    return match (HBHUB_MINER_ANOMALY_TIERS[$tier]['unlock']) {
        'rip' => $state['rips'] >= 1,
        'theory' => hbHubMinerHasSkill($state, 'theory'),
        default => false,
    };
}

/**
 * The game being played: the Anomaly's, while the player is in one, otherwise their own.
 */
function hbHubMinerGame(array $state): array
{
    return $state['anomaly']['state'] ?? $state;
}

/**
 * Goes into an Anomaly ($mods: the ids of HBHUB_MINER_ANOMALIES, in their order) at the game's time. Its game is a copy
 * of the player's, started over like a Big Rip that gives nothing (hbHubMinerNewMultiverse()), with its own luck; a
 * Tier III one ('fresh') also starts HBHUB_MINER_ANOMALY_FRESH over, so it's from nothing. Returns false when it can't
 * be done: in one already, an unknown one or stack, a tier isn't open yet, or it's saved (it's carried on with
 * hbHubMinerAnomalyResume() or thrown away first, so hours of it are never lost by a click).
 */
function hbHubMinerAnomalyStart(array &$state, mixed $mods): bool
{
    if ($state['anomaly'] !== null || $state['mods'] !== [] || !is_array($mods) || !array_is_list($mods) || $mods === []
        || array_filter($mods, 'is_string') !== $mods || ($mods = hbHubMinerAnomalyMods(hbHubMinerAnomalyKey($mods))) === null
        || isset($state['saved'][hbHubMinerAnomalyKey($mods)])) {
        return false;
    }
    foreach ($mods as $id) {
        if (!hbHubMinerAnomalyTierOpen($state, HBHUB_MINER_ANOMALIES[$id]['tier'])) {
            return false;
        }
    }

    $game = $state;
    $game['anomaly'] = null;
    $game['anomalies'] = [];
    $game['saved'] = [];
    $game['mods'] = hbHubMinerAnomalyRules($mods);
    $game['destroyed'] = [];
    $game['afterglow'] = 0; // the player's own game's, not this one's
    $game['engineOpen'] = false; // nothing is given from in here, apart from finishing it
    $game['seed'] = (int) floor(hbHubMinerRandom($state['seed'], $state['tick'], HBHUB_MINER_SALT_ANOMALY) * 0x7FFFFFFE) + 1;
    $game['started'] = $state['tick'];
    if (HBHUB_MINER_ANOMALY_TIERS[HBHUB_MINER_ANOMALIES[$mods[0]]['tier']]['fresh']) {
        $game = array_replace($game, HBHUB_MINER_ANOMALY_FRESH);
    }
    hbHubMinerNewMultiverse($game);
    $game['comet'] = ['n' => 0, 'at' => $game['tick'] + hbHubMinerCometWait($game, 0)];
    $state['anomaly'] = ['mods' => $mods, 'started' => $state['tick'], 'goal' => hbHubMinerAnomalyGoal($mods), 'state' => $game];
    $state['lastAnomaly'] = hbHubMinerAnomalyKey($mods);
    return true;
}

/**
 * Leaves the Anomaly the player is in, at its game's time: the player's own game catches up to then, all of it as
 * time away (hbHubMinerAdvance()). Returns the ore it mined meanwhile.
 */
function hbHubMinerAnomalyLeave(array &$state): float
{
    $to = $state['anomaly']['state']['tick'];
    $state['anomaly'] = null;
    return hbHubMinerAdvance($state, $to, $state['tick']);
}

/**
 * Leaves the Anomaly the player is in like hbHubMinerAnomalyLeave(), but keeps it in 'saved' to carry on later
 * (hbHubMinerAnomalyResume()). Its clock keeps running meanwhile (the time it took is from when it started, time saved
 * included), so saving doesn't make a run any faster. Returns false when it can't: not in one, Speed of Light (its
 * 2 hours run on, so it would be over anyway), or HBHUB_MINER_ANOMALY_SAVED_MAX saved already.
 */
function hbHubMinerAnomalySave(array &$state): bool
{
    $anomaly = $state['anomaly'];
    if ($anomaly === null || in_array('speedOfLight', $anomaly['mods'], true) || count($state['saved']) >= HBHUB_MINER_ANOMALY_SAVED_MAX) {
        return false;
    }
    $state['saved'][hbHubMinerAnomalyKey($anomaly['mods'])] = $anomaly;
    hbHubMinerAnomalyLeave($state);
    return true;
}

/**
 * Goes back into a saved Anomaly ($key, a key of 'saved') at the player's game's time. Its game has waited where it was
 * saved, and catches up to now as time away (with the Engine's perks as they are now), which can finish it (Iron
 * Universe's goal grows with time). Returns false when it can't: in one already, or nothing saved under $key.
 */
function hbHubMinerAnomalyResume(array &$state, mixed $key, ?array &$given): bool
{
    if ($state['anomaly'] !== null || $state['mods'] !== [] || !is_string($key) || !isset($state['saved'][$key])) {
        return false;
    }
    $anomaly = $state['saved'][$key];
    unset($state['saved'][$key]);
    $anomaly['state']['engineStage'] = $state['engineStage'];
    $anomaly['state']['engineIgnited'] = $state['engineIgnited'];
    $state['anomaly'] = $anomaly;
    $state['lastAnomaly'] = $key;
    hbHubMinerAdvanceGame($state, $state['tick'], $anomaly['state']['tick'], $given);
    return true;
}

/**
 * When the Anomaly the player is in has its goal: it's done, its time goes in 'anomalies' (and its parts in $given, as
 * [HBHUB_MINER_ENGINE_SOURCE_ANOMALY, parts, key, ms it took, the week when it's that week's weekly Anomaly or null],
 * see hbHubMinerApply(): all of them the first time for each Engine (hbHubMinerEngineNumber()) and for a new best
 * time, HBHUB_MINER_ANOMALY_REPEAT of them otherwise), and the player leaves it. Their own game then gets its
 * Afterglow (hbHubMinerAnomalyAfterglowMs()) from that time, added to any still running, up to
 * HBHUB_MINER_AFTERGLOW_MAX_MS ahead.
 */
function hbHubMinerAnomalyCheck(array &$state, ?array &$given): void
{
    $anomaly = $state['anomaly'];
    if ($anomaly === null || $anomaly['state'][hbHubMinerAnomalyStat($anomaly['mods'])] < $anomaly['goal']) {
        return;
    }
    $key = hbHubMinerAnomalyKey($anomaly['mods']);
    $ms = $anomaly['state']['tick'] - $anomaly['started'];
    $week = hbHubMinerWeek($anomaly['state']['tick']);
    $isWeekly = hbHubMinerAnomalyKey(hbHubMinerWeeklyMods($week)) === $key;
    $record = $state['anomalies'][$key] ?? null;
    $parts = hbHubMinerAnomalyParts($state, $anomaly['mods'], $isWeekly);
    $engineNumber = hbHubMinerEngineNumber($state);
    if ($record !== null && $record['engine'] >= $engineNumber && $ms >= $record['best']) {
        $parts = (int) floor($parts * HBHUB_MINER_ANOMALY_REPEAT);
    }
    $state['anomalies'][$key] = ['done' => ($record['done'] ?? 0) + 1, 'best' => $record !== null ? min($record['best'], $ms) : $ms,
        'engine' => $engineNumber];
    if ($isWeekly) {
        $state['weeklies']++;
    }
    if ($given !== null && $state['warps'] >= 1) {
        hbHubMinerGive($state, $given, [HBHUB_MINER_ENGINE_SOURCE_ANOMALY, $parts, $key, $ms, $isWeekly ? $week : null]);
    }
    hbHubMinerAnomalyLeave($state);
    $state['afterglow'] = min(max($state['afterglow'], $state['tick']) + hbHubMinerAnomalyAfterglowMs($anomaly['mods'], $isWeekly),
        $state['tick'] + HBHUB_MINER_AFTERGLOW_MAX_MS);
}

/**
 * hbHubMinerAdvance() for the game being played: an Anomaly's, while the player is in one (and nothing counts towards
 * Shifts in there), otherwise their own. In Speed of Light, the Anomaly is over at HBHUB_MINER_LIGHT_MS from its
 * start, and the player's own game carries on from then. A goal of ore this run (Iron Universe) is checked on the way,
 * as it's reached by time going by rather than by something the player does, and the player's own game carries on
 * from the ms it was.
 */
function hbHubMinerAdvanceGame(array &$state, int $to, int $fullUntil = PHP_INT_MAX, ?array &$given = null): float
{
    if ($state['anomaly'] === null) {
        return hbHubMinerAdvance($state, $to, $fullUntil, $given);
    }
    $limit = in_array('speedOfLight', $state['anomaly']['mods'], true) ? $state['anomaly']['started'] + HBHUB_MINER_LIGHT_MS : PHP_INT_MAX;
    $runOreGoal = hbHubMinerAnomalyStat($state['anomaly']['mods']) === 'runOre' ? $state['anomaly']['goal'] : INF;
    $nothing = null;
    $offlineOre = hbHubMinerAdvance($state['anomaly']['state'], min($to, $limit), $fullUntil, $nothing, $runOreGoal);
    if ($state['anomaly']['state']['runOre'] >= $runOreGoal) {
        hbHubMinerAnomalyCheck($state, $given); // done, and back in the player's own game
        return $offlineOre + hbHubMinerAdvance($state, $to, $fullUntil, $given);
    }
    if ($to < $limit) {
        return $offlineOre;
    }
    hbHubMinerAnomalyLeave($state); // out of time
    return $offlineOre + hbHubMinerAdvance($state, $to, $fullUntil, $given);
}

/**
 * One thing the player did, at the game's time. Returns whether it counted.
 * - ['type' => 'click', 'count' => n, 'auto' => true when they look like an auto-clicker's (left out otherwise)]: see
 *   hbHubMinerSync() for the limit.
 * - ['type' => 'buy', 'building' => id, 'amount' => 1, 10 or 100] (10 and 100 need Bulk Buying). With Max Buying any
 *   amount: the page works out how many the Max button can afford, and it's checked here like any other amount.
 *   'auto' => true when Auto Buy bought it (left out otherwise): with the Auto Buy upgrade, 10 and 100 don't need Bulk
 *   Buying, so it can buy a lot at once when there's a lot of ore (in Bare Metal too) instead of sending a buy for each.
 * - ['type' => 'upgrade', 'id' => id], ['type' => 'dark', 'id' => id] (a Dark Matter upgrade), ['type' => 'anti',
 *   'id' => id] (an Antimatter upgrade), ['type' => 'skill', 'id' => id] (learning a skill).
 * - ['type' => 'comet'], ['type' => 'warp'], ['type' => 'crunch'], ['type' => 'rip'].
 * - ['type' => 'donate', 'what' => 'ore' or 'strange', 'parts' => n]: gives n parts to the Cosmic Engine, for ore
 *   (hbHubMinerOreDonationCost(), at most hbHubMinerOrePartsMax() a day) or HBHUB_MINER_ENGINE_STRANGE_PART
 *   Strange Matter each.
 * - ['type' => 'anomalyStart', 'mods' => [id, ...]] (hbHubMinerAnomalyStart(), more than one for a stack),
 *   ['type' => 'anomalyQuit'] (the run is lost), ['type' => 'anomalySave'] (hbHubMinerAnomalySave(): left, but kept),
 *   ['type' => 'anomalyResume', 'key' => key] (hbHubMinerAnomalyResume()), ['type' => 'anomalyDrop', 'key' => key]
 *   (throws a saved one away, in an Anomaly too).
 * - ['type' => 'skin', 'id' => a key of HBHUB_MINER_SKINS the player has, or null for the asteroid's usual look].
 * - ['type' => 'backdrop', 'id' => a key of HBHUB_MINER_BACKDROPS, or null], ['type' => 'sparks',
 *   'on' => bool]: with their perks (hbHubMinerEnginePerk()). ['type' => 'shiftSwap', 'index' => n]:
 *   hbHubMinerShiftSwap(). Like skin, these are the player's own game's, in an Anomaly too.
 * In an Anomaly, everything else is played in its game instead (apart from learning skills, Big Rips and donating,
 * which can't be done in there), and once its goal is reached it's done (hbHubMinerAnomalyCheck()).
 * What's given to the Engine (donations, finished Engine Shifts and Anomalies) goes in $given as [source, parts], and
 * for an Anomaly its key, time and week after that, for hbHubMinerSync() to put in. $given is null when the player
 * can't give anything (guests): then donating doesn't count.
 */
function hbHubMinerApply(array &$state, array $action, ?array &$given = null): bool
{
    $type = $action['type'] ?? null;

    if ($type === 'skin') {
        $skinId = $action['id'] ?? null;
        if ($skinId !== null && (!is_string($skinId) || !hbHubMinerSkinOpen($state, $skinId))) {
            return false;
        }
        $state['skin'] = $skinId;
        return true;
    }

    if ($type === 'backdrop') {
        $backdropId = $action['id'] ?? null;
        if (!hbHubMinerEnginePerk($state, 'backdrops')
            || ($backdropId !== null && (!is_string($backdropId) || !isset(HBHUB_MINER_BACKDROPS[$backdropId])))) {
            return false;
        }
        $state['backdrop'] = $backdropId;
        return true;
    }

    if ($type === 'sparks') {
        if (!hbHubMinerEnginePerk($state, 'sparks') || !is_bool($action['on'] ?? null)) {
            return false;
        }
        $state['sparks'] = $action['on'];
        return true;
    }

    if ($type === 'shiftSwap') {
        return hbHubMinerShiftSwap($state, $action['index'] ?? null);
    }

    if ($type === 'anomalyStart') {
        return hbHubMinerAnomalyStart($state, $action['mods'] ?? null);
    }

    if ($type === 'anomalyQuit') {
        if ($state['anomaly'] === null) {
            return false;
        }
        hbHubMinerAnomalyLeave($state);
        return true;
    }

    if ($type === 'anomalySave') {
        return hbHubMinerAnomalySave($state);
    }

    if ($type === 'anomalyResume') {
        return hbHubMinerAnomalyResume($state, $action['key'] ?? null, $given);
    }

    if ($type === 'anomalyDrop') {
        $key = $action['key'] ?? null;
        if (!is_string($key) || !isset($state['saved'][$key])) {
            return false;
        }
        unset($state['saved'][$key]);
        return true;
    }

    if ($state['anomaly'] !== null) {
        $nothing = null;
        if (in_array($type, ['skill', 'rip', 'donate'], true) || !hbHubMinerApply($state['anomaly']['state'], $action, $nothing)) {
            return false;
        }
        hbHubMinerAnomalyCheck($state, $given);
        return true;
    }

    if ($type === 'click') {
        $count = (int) ($action['count'] ?? 0);
        if ($count < 1) {
            return false;
        }
        hbHubMinerClick($state, $count, ($action['auto'] ?? false) === true);
        hbHubMinerShift($state, 'clicks', $count, $given);
        return true;
    }

    if ($type === 'buy') {
        $buildingId = $action['building'] ?? null;
        $amount = (int) ($action['amount'] ?? 1);
        $isAutoBuy = ($action['auto'] ?? false) === true && hbHubMinerHasDark($state, 'autoBuy');
        if (!is_string($buildingId) || !isset(HBHUB_MINER_BUILDINGS[$buildingId]) || $amount < 1
            || ($amount > 1 && !hbHubMinerHas($state, 'bulk') && !$isAutoBuy)
            || (!in_array($amount, [1, 10, 100], true) && !hbHubMinerHas($state, 'bulkMax')) || !hbHubMinerCanBuild($state, $buildingId, $amount)) {
            return false;
        }
        $cost = hbHubMinerBuildingCost($state, $buildingId, $amount);
        if (!hbHubMinerCanAfford($state, $cost)) {
            return false;
        }
        $state['ore'] = max(0.0, $state['ore'] - $cost);
        $state['buildings'][$buildingId] += $amount;
        hbHubMinerShift($state, 'buildings', $amount, $given);
        return true;
    }

    if ($type === 'upgrade') {
        $upgradeId = $action['id'] ?? null;
        $upgrades = hbHubMinerUpgrades();
        if (!is_string($upgradeId) || !isset($upgrades[$upgradeId]) || hbHubMinerHas($state, $upgradeId)
            || !hbHubMinerUpgradeUnlocked($state, $upgrades[$upgradeId]) || !hbHubMinerCanAfford($state, hbHubMinerUpgradeCost($state, $upgradeId))) {
            return false;
        }
        $state['ore'] = max(0.0, $state['ore'] - hbHubMinerUpgradeCost($state, $upgradeId));
        $state['upgrades'][] = $upgradeId;
        hbHubMinerShift($state, 'upgrades', 1, $given);
        return true;
    }

    if ($type === 'dark') {
        $upgradeId = $action['id'] ?? null;
        if (!is_string($upgradeId) || !isset(HBHUB_MINER_DARK_UPGRADES[$upgradeId]) || hbHubMinerHasDark($state, $upgradeId)
            || $state['dark'] < HBHUB_MINER_DARK_UPGRADES[$upgradeId]['cost']) {
            return false;
        }
        $state['dark'] -= HBHUB_MINER_DARK_UPGRADES[$upgradeId]['cost'];
        $state['darkUpgrades'][] = $upgradeId;
        if ($upgradeId === 'headStart') { // works right away, so it's not wasted on the run it's bought in
            $state['buildings']['drone'] = max($state['buildings']['drone'], hbHubMinerStartBuildings($state)['drone']);
        }
        return true;
    }

    if ($type === 'anti') {
        $upgradeId = $action['id'] ?? null;
        if (!is_string($upgradeId) || !isset(HBHUB_MINER_ANTI_UPGRADES[$upgradeId]) || hbHubMinerHasAnti($state, $upgradeId)
            || $state['antimatter'] < HBHUB_MINER_ANTI_UPGRADES[$upgradeId]['cost']) {
            return false;
        }
        $state['antimatter'] -= HBHUB_MINER_ANTI_UPGRADES[$upgradeId]['cost'];
        $state['antiUpgrades'][] = $upgradeId;
        return true;
    }

    if ($type === 'skill') {
        $skillId = $action['id'] ?? null;
        if (!is_string($skillId) || !isset(HBHUB_MINER_SKILLS[$skillId]) || !hbHubMinerSkillOpen($state, $skillId)
            || $state['strange'] < HBHUB_MINER_SKILLS[$skillId]['cost']) {
            return false;
        }
        $state['strange'] -= HBHUB_MINER_SKILLS[$skillId]['cost'];
        $state['skills'][] = $skillId;
        // The buildings a run starts with come right away, like Head Start's, so they're not wasted on this run
        foreach (hbHubMinerSkillEffects($state)['start'] as $buildingId => $count) {
            $state['buildings'][$buildingId] = max($state['buildings'][$buildingId], $count);
        }
        return true;
    }

    if ($type === 'comet') {
        if (hbHubMinerCatchComet($state) === null) {
            return false;
        }
        hbHubMinerShift($state, 'comets', 1, $given);
        return true;
    }

    if ($type === 'warp') {
        if (!hbHubMinerWarp($state)) {
            return false;
        }
        hbHubMinerShift($state, 'warps', 1, $given);
        return true;
    }

    if ($type === 'donate') {
        $parts = $action['parts'] ?? null;
        if ($given === null || !hbHubMinerCanGive($state) || !is_int($parts) || $parts < 1) {
            return false;
        }
        hbHubMinerEngineDay($state);
        if (($action['what'] ?? null) === 'ore') {
            if ($state['donatedToday'] + $parts > hbHubMinerOrePartsMax($state)
                || !hbHubMinerCanAfford($state, hbHubMinerOreDonationCost($state, $parts))) {
                return false;
            }
            $state['ore'] = max(0.0, $state['ore'] - hbHubMinerOreDonationCost($state, $parts));
            $state['donatedToday'] += $parts;
            // Worth less in the later sections: what doesn't make a whole part waits in engineOreRest for the next
            $quarters = $state['engineOreRest'] + $parts * hbHubMinerOreQuarters($state);
            $state['engineOreRest'] = $quarters % HBHUB_MINER_ENGINE_ORE_QUARTERS;
            if ($quarters >= HBHUB_MINER_ENGINE_ORE_QUARTERS) {
                hbHubMinerGive($state, $given, [HBHUB_MINER_ENGINE_SOURCE_ORE, intdiv($quarters, HBHUB_MINER_ENGINE_ORE_QUARTERS)]);
            }
            return true;
        }
        if (($action['what'] ?? null) === 'strange') {
            if ($state['strange'] < $parts * HBHUB_MINER_ENGINE_STRANGE_PART) {
                return false;
            }
            $state['strange'] -= $parts * HBHUB_MINER_ENGINE_STRANGE_PART;
            hbHubMinerGive($state, $given, [HBHUB_MINER_ENGINE_SOURCE_STRANGE, $parts]);
            return true;
        }
        return false;
    }

    if ($type === 'crunch') {
        return hbHubMinerCrunch($state);
    }

    if ($type === 'rip') {
        return hbHubMinerRip($state);
    }

    return false;
}

/**
 * Whether the player has the asteroid skin $skinId (a key of HBHUB_MINER_SKINS, see its 'unlock').
 */
function hbHubMinerSkinOpen(array $state, string $skinId): bool
{
    $unlock = HBHUB_MINER_SKINS[$skinId]['unlock'] ?? null;
    return match (true) {
        $unlock === null => false,
        isset($unlock['anomaly']) => hbHubMinerAnomalyDone($state, $unlock['anomaly']),
        isset($unlock['stage']) => $state['engineStage'] >= $unlock['stage'],
        isset($unlock['crew']) => in_array($unlock['crew'], $state['crews'], true),
        default => false,
    };
}

/**
 * The most Anomalies finished in one stack (1 for one on its own, 0 for none).
 */
function hbHubMinerBiggestStack(array $state): int
{
    $biggest = 0;
    foreach ($state['anomalies'] as $key => $record) {
        $biggest = max($biggest, count(explode('+', (string) $key)));
    }
    return $biggest;
}

/**
 * A number an achievement checks (its 'stat'). owned:<building> is how many of it there are, anomaly:<id> whether that
 * Anomaly is finished (alone or in a stack), tier:<n> how many of that tier's are.
 */
function hbHubMinerStat(array $state, string $stat): float
{
    if (str_starts_with($stat, 'anomaly:')) {
        return hbHubMinerAnomalyDone($state, substr($stat, 8)) ? 1 : 0;
    }
    if (str_starts_with($stat, 'tier:')) {
        $tier = (int) substr($stat, 5);
        return count(array_filter(array_keys(HBHUB_MINER_ANOMALIES), static fn (string $id): bool =>
            HBHUB_MINER_ANOMALIES[$id]['tier'] === $tier && hbHubMinerAnomalyDone($state, $id)));
    }
    return match ($stat) {
        'anomalies' => count($state['anomalies']),
        'stack' => hbHubMinerBiggestStack($state),
        'lightHour' => isset($state['anomalies']['speedOfLight']) && $state['anomalies']['speedOfLight']['best'] < HBHUB_MINER_LIGHT_HOUR_MS ? 1 : 0,
        'engineGiven', 'shiftDays', 'weeklies' => (float) $state[$stat],
        'rate' => hbHubMinerRate($state, PHP_INT_MAX),
        'upgrades' => count($state['upgrades']),
        'buildings' => array_sum($state['buildings']),
        'ownedAll' => min($state['buildings']),
        'skills' => count($state['skills']),
        'allSkills' => count($state['skills']) >= count(HBHUB_MINER_SKILLS) ? 1 : 0,
        'allOre', 'clicks', 'crits', 'comets', 'diamonds', 'warps', 'crunches', 'rips' => (float) $state[$stat],
        default => (float) ($state['buildings'][substr($stat, 6)] ?? 0),
    };
}

/**
 * Marks the achievements reached since the last check as done.
 */
function hbHubMinerCheckAchievements(array &$state): void
{
    foreach (HBHUB_MINER_ACHIEVEMENTS as $id => $achievement) {
        if (!in_array($id, $state['achievements'], true) && hbHubMinerStat($state, $achievement['stat']) >= $achievement['at']) {
            $state['achievements'][] = $id;
        }
    }
}

// ----- Saving

/**
 * Plays what the player did since the last save on their saved game, saves it, and returns
 * ['state' => the save (hbHubMinerView()), 'now' => ms, 'offline' => null or ['ms' => away for, 'ore' => mined meanwhile],
 * 'recovered' => how many of $recovered counted], or 'otherTab' when the game was opened in another tab since (the
 * newest tab plays; $claim is how a page that just opened takes over), or 'tooFast' after HBHUB_MINER_SAVES_MAX saves
 * in HBHUB_MINER_SAVES_SECONDS (nothing is saved, and the turned-away ones don't count towards it). A save is made on
 * the first call.
 * $actions: what hbHubMinerApply() takes, each with 'at' (when, Unix ms), oldest first. Times are kept between the
 * last save and now and in order, and clicks beyond HBHUB_MINER_CLICKS_PER_SECOND since the last save don't count.
 * $recovered: the same, from a page of the game that crashed or was closed before it knew its last save got here
 * (the page that opens next finds them in the browser and sends them with its first save, see "Crash recovery" in
 * spaceOreMiner.js). Some of them may have been saved after all, but those all happened before the save's time, so
 * only the ones after it count, played before $actions.
 * $guestState: a game played as a guest at /play/ore-mining-inc (opened from its token by the caller), to bring over
 * to the account after logging in or signing up from there. It only becomes the save when the user has none yet, so
 * it never writes over one; the answer's 'guest' then says 'imported', or 'kept' when the user's own save was kept.
 * The parts the player gave the Cosmic Engine in these actions go in with the save, and the answer's 'engine' is how
 * far it is (hbHubMinerEngineSummary(), with the top contributors and the latest parts given when $engineDetails).
 */
function hbHubMinerSync(PDO $db, int $userId, string $tab, bool $claim, array $actions, array $recovered = [], ?array $guestState = null,
    bool $engineDetails = false): array|string
{
    $db->beginTransaction();
    try {
        $now = hbHubMinerNow();
        $insert = $db->prepare('INSERT IGNORE INTO `HBHub-MinerSaves` (userId, saveState, saveTab, saveStartTimestamp, saveUpdatedTimestamp)
            VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $insert->execute([$userId, hbHubMinerEncode($guestState !== null ? hbHubMinerNormalize($guestState, $now) : hbHubMinerNewState($now)), $tab]);
        $guestResult = $guestState === null ? null : ($insert->rowCount() > 0 ? 'imported' : 'kept');
        $statement = $db->prepare('SELECT saveState, saveTab, saveRateCount,
                saveRateTimestamp > UTC_TIMESTAMP() - INTERVAL ' . HBHUB_MINER_SAVES_SECONDS . ' SECOND AS inRateWindow
            FROM `HBHub-MinerSaves` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $row = $statement->fetch();

        if (!$claim && $row['saveTab'] !== $tab) {
            $db->rollBack();
            return 'otherTab';
        }

        // Saves are counted from the first one in a window of HBHUB_MINER_SAVES_SECONDS, and the next one after it starts
        // a new window. Checked before the actions are replayed, as that's the part that costs.
        $inRateWindow = (bool) $row['inRateWindow']; // NULL before the first counted save
        if ($inRateWindow && (int) $row['saveRateCount'] >= HBHUB_MINER_SAVES_MAX) {
            $db->rollBack();
            return 'tooFast';
        }

        $state = hbHubMinerNormalize(json_decode($row['saveState'], true, 64, JSON_THROW_ON_ERROR), $now);
        $replay = hbHubMinerReplay($state, $actions, $recovered, $now, true);

        // The parts given go into the Engine, locked so two saves can't both finish the same stage (always after the
        // save's row, so they can't wait on each other), and the save gets the perks of every stage done so far
        $engine = hbHubMinerEngineRow($db, $replay['given'] !== []);
        if (hbHubMinerEngineDrainDue($engine)) { // Ignition's time ran out: this save drains it, with the row locked
            $engine = hbHubMinerEngineRow($db, true);
            if (hbHubMinerEngineDrainDue($engine)) {
                hbHubMinerEngineDrain($db, $engine);
            }
        }
        if ($replay['given'] !== []) {
            hbHubMinerEngineGive($db, $engine, $userId, $replay['given']);
            hbHubMinerAnomalyTimesSave($db, $userId, $replay['given']);
        }
        hbHubMinerEngineAdopt($state, $engine, true, $db, $userId);

        $db->prepare('UPDATE `HBHub-MinerSaves` SET saveState = ?, saveTab = ?, saveOreAllTime = ?, saveOrePerSecond = ?,
                saveDarkMatter = ?, saveAntimatter = ?, saveStrangeMatter = ?, saveWarps = ?, saveClicks = ?, saveAchievements = ?, saveSkills = ?,
                saveUpdatedTimestamp = UTC_TIMESTAMP(), saveRateCount = ?, saveRateTimestamp = IF(?, saveRateTimestamp, UTC_TIMESTAMP())
            WHERE userId = ?')
            ->execute([hbHubMinerEncode($state), $tab, $state['allOre'], hbHubMinerRate($state, PHP_INT_MAX), $state['darkEarned'],
                $state['antimatterEarned'], $state['strangeEarned'], $state['warps'], $state['clicks'], count($state['achievements']),
                count($state['skills']), $inRateWindow ? (int) $row['saveRateCount'] + 1 : 1, (int) $inRateWindow, $userId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'state' => hbHubMinerView($state),
        'now' => $now,
        'offline' => $replay['offline'],
        'recovered' => $replay['recovered'],
        'engine' => hbHubMinerEngineSummary($db, $engine, $userId, $engineDetails),
    ] + ($guestResult !== null ? ['guest' => $guestResult] : []);
}

/**
 * Plays what the player did since the last save on the game, up to $now: the part of hbHubMinerSync() that doesn't
 * touch the database, also used for guests (/play/ore-mining-inc, whose game is a token in their browser instead of a
 * row). $actions and $recovered are as hbHubMinerSync() takes them. $canGive: a member, who can give parts to the
 * Engine (guests can't). Returns 'offline' (null, or ['ms' => away for, 'ore' => mined meanwhile]), 'recovered' (how
 * many of $recovered counted) and 'given' (what was given to the Engine, [source, parts] each, see hbHubMinerApply()).
 *
 * @return array{offline: ?array{ms: int, ore: float}, recovered: int, given: list<array{0: int, 1: int, 2?: string, 3?: int, 4?: ?int}>}
 */
function hbHubMinerReplay(array &$state, array $actions, array $recovered, int $now, bool $canGive = false): array
{
    $given = $canGive ? [] : null;
    $since = min(hbHubMinerGame($state)['tick'], $now); // in an Anomaly, its game's time is the one that moves
    $fullUntil = $since + HBHUB_MINER_ONLINE_MS;
    $clicksLeft = (int) floor(($now - $since) / 1000 * HBHUB_MINER_CLICKS_PER_SECOND) + HBHUB_MINER_CLICKS_PER_SECOND;
    $offlineOre = 0.0;

    $recovered = array_values(array_filter(array_slice($recovered, 0, HBHUB_MINER_MAX_ACTIONS),
        static fn (mixed $action): bool => is_array($action) && is_numeric($action['at'] ?? null) && (int) $action['at'] > $since));
    $recoveredCount = 0;
    foreach (array_slice(array_merge($recovered, $actions), 0, HBHUB_MINER_MAX_ACTIONS) as $index => $action) {
        if (!is_array($action)) {
            continue;
        }
        if (($action['type'] ?? null) === 'click') {
            $action['count'] = min((int) ($action['count'] ?? 0), $clicksLeft);
            $clicksLeft -= max(0, $action['count']);
        }
        $at = is_numeric($action['at'] ?? null) ? (int) $action['at'] : $now;
        $offlineOre += hbHubMinerAdvanceGame($state, max(hbHubMinerGame($state)['tick'], min($at, $now)), $fullUntil, $given);
        if (hbHubMinerApply($state, $action, $given)) {
            hbHubMinerCheckAchievements($state);
            if ($index < count($recovered)) {
                $recoveredCount++;
            }
        }
    }
    $offlineOre += hbHubMinerAdvanceGame($state, $now, $fullUntil, $given);
    hbHubMinerCheckAchievements($state); // the player's own game only: what's done in an Anomaly stays in there

    return [
        'offline' => $offlineOre > 0 ? ['ms' => $now - $since, 'ore' => $offlineOre] : null,
        'recovered' => $recoveredCount,
        'given' => $given ?? [],
    ];
}
