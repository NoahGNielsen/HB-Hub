<?php
// Space Ore Miner, the clicker game: click the asteroid for ore, buy machines that mine for you, and grow from one
// miner on a rock into a galaxy-wide mining company. Warp Jump (prestige) starts over in a new galaxy for Dark Matter,
// which makes everything mine faster for good. Big Crunch (the prestige after that) collapses the whole universe, Dark
// Matter and all, for Antimatter, which makes everything mine faster and Warp Jumps give more Dark Matter, for good.
//
// Each user has one save: a row in HBHub-MinerSaves with the whole game as JSON in saveState (the shape is in
// hbHubMinerNewState()), plus a few of its numbers in their own columns for the leaderboard.
//
// The game runs in the browser (spaceOreMiner.js) so it's smooth, but the server has the last word: every 30 seconds
// (and when the tab is hidden or closed) the page sends what the player did since the last save - clicks, buildings and
// upgrades bought, comets caught, Warp Jumps - each with the time it happened, and hbHubMinerSync() plays them again on
// the saved game with the same rules as the script. Something that couldn't have happened (buying without the ore,
// catching a comet that wasn't there, more than HBHUB_MINER_CLICKS_PER_SECOND clicks a second) just doesn't count, and
// the page carries on from the game the server sends back. Clicks that come in faster than a person can keep up (an
// auto-clicker, see hbHubMinerFastClick()) only mine HBHUB_MINER_FAST_VALUE of what they would. So the
// script and this file must work the same: the rules
// below are copied in spaceOreMiner.js, and the data (buildings, upgrades, achievements and the numbers) goes to the
// page as JSON from hbHubMinerConfig(), so it's only written here.
// Luck (critical clicks, when comets come and what they do) comes from hbHubMinerRandom(): the same numbers here and in
// the script for the same save, so both agree on which click was critical.
//
// Time is Unix milliseconds throughout. A gap between two saves of up to HBHUB_MINER_ONLINE_MS counts as playing;
// anything after that is time away, which mines at HBHUB_MINER_OFFLINE_RATE.
// Used by games/spaceOreMiner/ (the page), games/spaceOreMiner/save (the JSON the page saves to) and its leaderboard.
require_once __DIR__ . '/../../database.php';

const HBHUB_MINER_SAVE_SECONDS = 30; // how often the page saves while it's open
const HBHUB_MINER_ONLINE_MS = 90000; // a gap between saves up to this long counts as playing, the rest as away
const HBHUB_MINER_OFFLINE_RATE = 0.05; // of the normal ore per second, while away
const HBHUB_MINER_CLICKS_PER_SECOND = 20; // the most clicks that count, so auto-clickers don't pay
const HBHUB_MINER_FAST_CLICKS = 15; // more clicks than this in a second...
const HBHUB_MINER_FAST_MS = 180000; // ...kept up for this long is an auto-clicker...
const HBHUB_MINER_FAST_VALUE = 0.2; // ...and only mines this share of what a click is worth
const HBHUB_MINER_MAX_ACTIONS = 2000; // in one save
const HBHUB_MINER_COST_GROWTH = 1.15; // each building costs this much more than the one before (unless it has its own 'growth')
const HBHUB_MINER_COST_GROWTH_PHYSICS = 0.01; // taken off that with Cheaper Physics
const HBHUB_MINER_WARP_ORE = 1e9; // ore to mine in a run before a Warp Jump
const HBHUB_MINER_DARK_UNIT = 1e9; // all-time ore for the first Dark Matter, see hbHubMinerDarkTotal()
const HBHUB_MINER_DARK_BONUS = 0.05; // production per Dark Matter earned in this universe
const HBHUB_MINER_DARK_BONUS_REACTOR = 0.075; // with Dark Matter Reactor
const HBHUB_MINER_CRUNCH_DARK = 2000; // Dark Matter to earn in a universe before a Big Crunch
const HBHUB_MINER_ANTI_UNIT = 100; // Dark Matter for the first Antimatter, see hbHubMinerAntiTotal()
const HBHUB_MINER_ANTI_BONUS = 0.1; // production, and Dark Matter from a Warp Jump, per Antimatter ever earned
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
// or ore right away (lucky: 15% of the ore you have, at most 15 minutes of production, plus 13, doubled with Stardust
// Trail). Antimatter Comets doubles all of them (the multiplier, or the lucky ore).
const HBHUB_MINER_COMETS = [
    'frenzy' => ['name' => 'Frenzy', 'buff' => 'production', 'multiplier' => 7, 'ms' => 30000],
    'lucky' => ['name' => 'Lucky', 'buff' => null, 'multiplier' => 1, 'ms' => 0],
    'storm' => ['name' => 'Click Storm', 'buff' => 'click', 'multiplier' => 77, 'ms' => 13000],
    'diamond' => ['name' => 'Diamond Rush', 'buff' => 'production', 'multiplier' => 777, 'ms' => 7000],
];
const HBHUB_MINER_LUCKY_SHARE = 0.15;
const HBHUB_MINER_LUCKY_SECONDS = 900;

// The salts for hbHubMinerRandom(), one per kind of luck
const HBHUB_MINER_SALT_CRIT = 1;
const HBHUB_MINER_SALT_COMET_WAIT = 2;
const HBHUB_MINER_SALT_COMET_KIND = 3;
const HBHUB_MINER_SALT_DIAMOND = 4;

// The buildings, cheapest first. 'rate' is ore per second for each one before upgrades, 'upgrades' their six
// upgrades (see HBHUB_MINER_BUILDING_UPGRADE_AT), and 'growth', when it's there, how much more each one costs than the
// one before instead of HBHUB_MINER_COST_GROWTH (Space Pickaxe Guys go up slower, so having lots of them stays cheap)
const HBHUB_MINER_BUILDINGS = [
    'pickaxe' => ['name' => 'Space Pickaxe Guy', 'plural' => 'Space Pickaxe Guys', 'icon' => '👷', 'cost' => 15, 'rate' => 0.1, 'growth' => 1.12,
        'text' => 'Swings a pickaxe at the rock. In zero gravity, that\'s harder than it sounds.',
        'upgrades' => ['Energy Drinks', 'Hard Hats', 'Union Contract', 'Jetpack Commute', 'Diamond Pickaxes', 'Employee of the Month']],
    'drone' => ['name' => 'Mining Drone', 'plural' => 'Mining Drones', 'icon' => '🛸', 'cost' => 100, 'rate' => 1,
        'text' => 'A little robot that nibbles ore off the asteroid all day long.',
        'upgrades' => ['Longer Batteries', 'Drone Swarm AI', 'Self-Repair Nanites', 'Magnetic Grabbers', 'Hive Mind', 'Drone Factories']],
    'laser' => ['name' => 'Laser Drill', 'plural' => 'Laser Drills', 'icon' => '🔦', 'cost' => 1100, 'rate' => 8,
        'text' => 'Melts through rock like butter. Don\'t look straight into it.',
        'upgrades' => ['Focused Lens', 'Overclocked Lasers', 'Diamond Tips', 'Mirror Arrays', 'Gamma Beams', 'Planet-Sized Lens']],
    'rig' => ['name' => 'Mining Rig', 'plural' => 'Mining Rigs', 'icon' => '🏗️', 'cost' => 12000, 'rate' => 47,
        'text' => 'A walking factory bolted onto the rock.',
        'upgrades' => ['Heavy Treads', 'Double Shifts', 'Autonomous Rigs', 'Bigger Buckets', 'Rig Convoys', 'Mega Rigs']],
    'base' => ['name' => 'Asteroid Base', 'plural' => 'Asteroid Bases', 'icon' => '🏠', 'cost' => 130000, 'rate' => 260,
        'text' => 'A whole crew living on site. Shifts never end in space.',
        'upgrades' => ['Hydroponics', 'Ore Refinery', 'Artificial Gravity', 'Space Cafeteria', 'Crew Quarters', 'Base Network']],
    'freighter' => ['name' => 'Ore Freighter', 'plural' => 'Ore Freighters', 'icon' => '🚀', 'cost' => 1.4e6, 'rate' => 1400,
        'text' => 'Hauls in ore from asteroids you haven\'t even seen yet.',
        'upgrades' => ['Bigger Cargo Holds', 'Hyperspace Lanes', 'Fleet Command', 'Tractor Beams', 'Cargo Drones', 'Armada']],
    'station' => ['name' => 'Space Station', 'plural' => 'Space Stations', 'icon' => '🛰️', 'cost' => 2e7, 'rate' => 7800,
        'text' => 'Runs mining all over the solar system from orbit.',
        'upgrades' => ['Solar Arrays', 'Fusion Core', 'Ring Habitat', 'Docking Ring', 'Orbital Elevator', 'Station Cities']],
    'excavator' => ['name' => 'Moon Excavator', 'plural' => 'Moon Excavators', 'icon' => '🌙', 'cost' => 3.3e8, 'rate' => 44000,
        'text' => 'Digs up whole moons. The moons have stopped complaining.',
        'upgrades' => ['Moon Treads', 'Crater Scoops', 'Core Tap', 'Lunar Conveyors', 'Moon Magnets', 'Moon Factory']],
    'cracker' => ['name' => 'Planet Cracker', 'plural' => 'Planet Crackers', 'icon' => '🪐', 'cost' => 5.1e9, 'rate' => 260000,
        'text' => 'Cracks planets open like eggs to get at the ore inside.',
        'upgrades' => ['Seismic Charges', 'Tectonic Splitter', 'Planet Shredder', 'Core Drills', 'Magma Pumps', 'Planet Juicer']],
    'siphon' => ['name' => 'Black Hole Siphon', 'plural' => 'Black Hole Siphons', 'icon' => '🕳️', 'cost' => 7.5e10, 'rate' => 1.6e6,
        'text' => 'Pulls ore out of a black hole. Nobody knows how. Nobody asks.',
        'upgrades' => ['Event Horizon Net', 'Hawking Pump', 'Singularity Engine', 'Spaghetti Filters', 'Gravity Lens', 'Pocket Black Holes']],
    'forge' => ['name' => 'Star Forge', 'plural' => 'Star Forges', 'icon' => '⭐', 'cost' => 1e12, 'rate' => 1e7,
        'text' => 'Squeezes stars until ore comes out. The stars are fine, mostly.',
        'upgrades' => ['Stellar Bellows', 'Fusion Hammers', 'Neutron Anvil', 'Star Tongs', 'Supernova Furnace', 'Galactic Smithy']],
    'nebula' => ['name' => 'Nebula Harvester', 'plural' => 'Nebula Harvesters', 'icon' => '🌌', 'cost' => 1.4e13, 'rate' => 6.5e7,
        'text' => 'Sweeps up whole nebulas with a really, really big net.',
        'upgrades' => ['Finer Nets', 'Gas Compressors', 'Cosmic Vacuum', 'Nebula Maps', 'Stardust Filters', 'Cloud Factory']],
    'dyson' => ['name' => 'Dyson Swarm', 'plural' => 'Dyson Swarms', 'icon' => '☀️', 'cost' => 1.7e14, 'rate' => 4.3e8,
        'text' => 'A billion mirrors around a star, all of them pointed at the ore.',
        'upgrades' => ['Mirror Polish', 'Swarm Logic', 'Full Sphere', 'Shell Layers', 'Photon Collectors', 'Star Lifting']],
    'wormhole' => ['name' => 'Wormhole Gate', 'plural' => 'Wormhole Gates', 'icon' => '⭕', 'cost' => 2.1e15, 'rate' => 2.9e9,
        'text' => 'Opens a door to a part of space that\'s nothing but ore. Mind the gap.',
        'upgrades' => ['Stable Throats', 'Exotic Matter Struts', 'Wormhole Network', 'Toll Booths', 'Time Shortcuts', 'Pocket Dimensions']],
    'multiverse' => ['name' => 'Multiverse Drill', 'plural' => 'Multiverse Drills', 'icon' => '🌐', 'cost' => 2.6e16, 'rate' => 2.1e10,
        'text' => 'Drills into the universe next door and takes their ore too. They have a drill as well.',
        'upgrades' => ['Dimensional Bits', 'Parallel Shifts', 'Infinite Drill', 'Mirror Universe Miners', 'Quantum Foam Bits', 'The Everything Drill']],
];
const HBHUB_MINER_BUILDING_UPGRADE_AT = [10, 25, 50, 100, 150, 200]; // owned, for each building's six upgrades
// What each costs, in how many of the building it is at the price the next one has when the upgrade shows up (rounded to
// two digits, see hbHubMinerUpgrades()): a few more of it, for twice as much from all of them
const HBHUB_MINER_BUILDING_UPGRADE_COST = [2.5, 3, 5, 8, 8, 8];

// The upgrades that aren't a building's own (those are made from HBHUB_MINER_BUILDINGS in hbHubMinerUpgrades()).
// 'type' says what it does:
// - click: clicks mine 'value' times as much. clickPct: each click also mines 'value' of the ore per second.
// - crit: a click can be critical (HBHUB_MINER_CRIT_CHANCE, HBHUB_MINER_CRIT_MULTIPLIER). auto: 'value' more clicks a second.
// - global: every building mines 'value' more (0.1 = +10%), all of them multiplied together. bulk: the x10 and x100 buttons.
// - synergy: 'building' mines 'per' more for every 'every' of 'source' owned.
// - crew: each of 'building' mines another 'value' ore per second (before its other upgrades) for every other building
//   owned, so Space Pickaxe Guys keep up with the rest.
// - comet: 'effect' often, longer, tail, diamond, lucky or key (the C key catches comets, only in spaceOreMiner.js).
// 'need' is when it shows up in the store: ore (mined this run), owned (building => how many) and comets (caught ever).
// Without one, it's ore mined this run of half its cost.
const HBHUB_MINER_OTHER_UPGRADES = [
    'titanium' => ['name' => 'Titanium Pickaxe', 'icon' => '⛏️', 'cost' => 100, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'gloves' => ['name' => 'Reinforced Gloves', 'icon' => '🧤', 'cost' => 500, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'plasma' => ['name' => 'Plasma Gloves', 'icon' => '🔥', 'cost' => 5000, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'fuel' => ['name' => 'Better Fuel', 'icon' => '⛽', 'cost' => 10000, 'type' => 'global', 'value' => 0.1, 'text' => 'All buildings mine 10% more.'],
    'buddySystem' => ['name' => 'Buddy System', 'icon' => '🤝', 'cost' => 2000, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 0.005,
        'need' => ['owned' => ['pickaxe' => 10]], 'text' => 'Each Space Pickaxe Guy mines 0.005 more ore per second for every other building you own.'],
    'shiftBosses' => ['name' => 'Shift Bosses', 'icon' => '📋', 'cost' => 2e5, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 0.02,
        'need' => ['owned' => ['pickaxe' => 25]], 'text' => 'Each Space Pickaxe Guy mines another 0.02 ore per second for every other building you own.'],
    'pickaxeAcademy' => ['name' => 'Pickaxe Academy', 'icon' => '🎓', 'cost' => 2e7, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 0.05,
        'need' => ['owned' => ['pickaxe' => 50]], 'text' => 'Each Space Pickaxe Guy mines another 0.05 ore per second for every other building you own.'],
    'pickaxeGuild' => ['name' => 'Pickaxe Guild', 'icon' => '⚒️', 'cost' => 2e9, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 0.5,
        'need' => ['owned' => ['pickaxe' => 75]], 'text' => 'Each Space Pickaxe Guy mines another 0.5 ore per second for every other building you own.'],
    'pickaxeClones' => ['name' => 'Pickaxe Guy Clones', 'icon' => '🧬', 'cost' => 2e11, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 5,
        'need' => ['owned' => ['pickaxe' => 100]], 'text' => 'Each Space Pickaxe Guy mines another 5 ore per second for every other building you own.'],
    'pickaxeLegends' => ['name' => 'Living Legends', 'icon' => '🏆', 'cost' => 2e13, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 50,
        'need' => ['owned' => ['pickaxe' => 150]], 'text' => 'Each Space Pickaxe Guy mines another 50 ore per second for every other building you own.'],
    'pickaxeGalaxy' => ['name' => 'Galaxy of Pickaxes', 'icon' => '🌠', 'cost' => 2e15, 'type' => 'crew', 'building' => 'pickaxe', 'value' => 500,
        'need' => ['owned' => ['pickaxe' => 200]], 'text' => 'Each Space Pickaxe Guy mines another 500 ore per second for every other building you own.'],
    'autoAim' => ['name' => 'Auto-Aim Laser', 'icon' => '🎯', 'cost' => 50000, 'type' => 'clickPct', 'value' => 0.01, 'text' => 'Each click also mines 1% of your ore per second.'],
    'bulk' => ['name' => 'Bulk Buying', 'icon' => '🛒', 'cost' => 50000, 'type' => 'bulk', 'text' => 'Unlocks the ×10 and ×100 buttons, to buy buildings ten or a hundred at a time.'],
    'quantum' => ['name' => 'Quantum Hammer', 'icon' => '🔨', 'cost' => 500000, 'type' => 'clickPct', 'value' => 0.02, 'text' => 'Each click mines another 2% of your ore per second.'],
    'cometKey' => ['name' => 'Comet Hot Key', 'icon' => '⌨️', 'cost' => 333333, 'type' => 'comet', 'effect' => 'key', 'need' => ['comets' => 1], 'text' => 'Press C to catch a comet, without having to hit it.'],
    'radar' => ['name' => 'Comet Radar', 'icon' => '📡', 'cost' => 777777, 'type' => 'comet', 'effect' => 'often', 'need' => ['comets' => 3], 'text' => 'Comets show up twice as often.'],
    'zeroG' => ['name' => 'Zero-G Logistics', 'icon' => '🧭', 'cost' => 1e6, 'type' => 'global', 'value' => 0.25, 'text' => 'All buildings mine 25% more.'],
    'dockingBays' => ['name' => 'Drone Docking Bays', 'icon' => '🔗', 'cost' => 1.3e6, 'type' => 'synergy', 'building' => 'drone', 'source' => 'base', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['drone' => 1, 'base' => 1]], 'text' => 'Mining Drones mine 1% more for every Asteroid Base you own.'],
    'crit' => ['name' => 'Critical Strike Module', 'icon' => '💥', 'cost' => 2e6, 'type' => 'crit', 'text' => 'Clicks have a 5% chance to mine 10 times as much.'],
    'magnet' => ['name' => 'Comet Magnet', 'icon' => '🧲', 'cost' => 7.77e6, 'type' => 'comet', 'effect' => 'longer', 'need' => ['comets' => 7], 'text' => 'Comets stay on screen twice as long.'],
    'mapping' => ['name' => 'Asteroid Mapping', 'icon' => '🗺️', 'cost' => 1e7, 'type' => 'global', 'value' => 0.5, 'text' => 'All buildings mine 50% more.'],
    'guidedFreighters' => ['name' => 'Laser-Guided Freighters', 'icon' => '🔗', 'cost' => 1.4e7, 'type' => 'synergy', 'building' => 'freighter', 'source' => 'laser', 'per' => 0.02, 'every' => 1,
        'need' => ['owned' => ['freighter' => 1, 'laser' => 1]], 'text' => 'Ore Freighters mine 2% more for every Laser Drill you own.'],
    'gauntlet' => ['name' => 'Gravity Gauntlet', 'icon' => '🦾', 'cost' => 2.5e7, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'tail' => ['name' => 'Tail Collector', 'icon' => '☄️', 'cost' => 7.77e7, 'type' => 'comet', 'effect' => 'tail', 'need' => ['comets' => 15], 'text' => 'Comet effects last twice as long.'],
    'hangars' => ['name' => 'Station Hangars', 'icon' => '🔗', 'cost' => 2e8, 'type' => 'synergy', 'building' => 'station', 'source' => 'drone', 'per' => 0.05, 'every' => 10,
        'need' => ['owned' => ['station' => 1, 'drone' => 10]], 'text' => 'Space Stations mine 5% more for every 10 Mining Drones you own.'],
    'supernova' => ['name' => 'Supernova Punch', 'icon' => '👊', 'cost' => 5e8, 'type' => 'clickPct', 'value' => 0.03, 'text' => 'Each click mines another 3% of your ore per second.'],
    'autoClicker' => ['name' => 'Auto-Clicker Bot', 'icon' => '🤖', 'cost' => 1e9, 'type' => 'auto', 'value' => 2, 'text' => 'Clicks the asteroid 2 times a second for you.'],
    'warpTech' => ['name' => 'Warp Drive Tech', 'icon' => '🌀', 'cost' => 1e9, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'outposts' => ['name' => 'Moon Outposts', 'icon' => '🔗', 'cost' => 3.3e9, 'type' => 'synergy', 'building' => 'base', 'source' => 'excavator', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['base' => 1, 'excavator' => 1]], 'text' => 'Asteroid Bases mine 1% more for every Moon Excavator you own.'],
    'luckyStars' => ['name' => 'Lucky Stars', 'icon' => '💎', 'cost' => 7.77e9, 'type' => 'comet', 'effect' => 'diamond', 'need' => ['comets' => 27], 'text' => 'Now and then a Diamond Comet shows up instead: 777 times the production for 7 seconds.'],
    'slingshot' => ['name' => 'Gravity Slingshot', 'icon' => '🔗', 'cost' => 7.5e11, 'type' => 'synergy', 'building' => 'cracker', 'source' => 'siphon', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['cracker' => 1, 'siphon' => 1]], 'text' => 'Planet Crackers mine 1% more for every Black Hole Siphon you own.'],
    'monopoly' => ['name' => 'Galactic Monopoly', 'icon' => '👑', 'cost' => 1e12, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'neutronGloves' => ['name' => 'Neutron Star Gloves', 'icon' => '✊', 'cost' => 1e10, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'botSwarm' => ['name' => 'Auto-Clicker Swarm', 'icon' => '🤖', 'cost' => 5e12, 'type' => 'auto', 'value' => 3, 'text' => 'Clicks the asteroid another 3 times a second for you.'],
    'orbitalForges' => ['name' => 'Orbital Forges', 'icon' => '🔗', 'cost' => 5e12, 'type' => 'synergy', 'building' => 'forge', 'source' => 'station', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['forge' => 1, 'station' => 1]], 'text' => 'Star Forges mine 1% more for every Space Station you own.'],
    'stardust' => ['name' => 'Stardust Trail', 'icon' => '✨', 'cost' => 7.77e12, 'type' => 'comet', 'effect' => 'lucky', 'need' => ['comets' => 50], 'text' => 'Lucky comets give twice as much ore.'],
    'bigBangPunch' => ['name' => 'Big Bang Punch', 'icon' => '💫', 'cost' => 1e13, 'type' => 'clickPct', 'value' => 0.03, 'text' => 'Each click mines another 3% of your ore per second.'],
    'nebulaScoops' => ['name' => 'Nebula Scoops', 'icon' => '🔗', 'cost' => 7e13, 'type' => 'synergy', 'building' => 'nebula', 'source' => 'freighter', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['nebula' => 1, 'freighter' => 1]], 'text' => 'Nebula Harvesters mine 1% more for every Ore Freighter you own.'],
    'starCharts' => ['name' => 'Star Charts', 'icon' => '🌟', 'cost' => 1e14, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'shatteredPlanets' => ['name' => 'Shattered Planets', 'icon' => '🔗', 'cost' => 8.5e14, 'type' => 'synergy', 'building' => 'dyson', 'source' => 'cracker', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['dyson' => 1, 'cracker' => 1]], 'text' => 'Dyson Swarms mine 1% more for every Planet Cracker you own.'],
    'blackHoleBridges' => ['name' => 'Black Hole Bridges', 'icon' => '🔗', 'cost' => 1e16, 'type' => 'synergy', 'building' => 'wormhole', 'source' => 'siphon', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['wormhole' => 1, 'siphon' => 1]], 'text' => 'Wormhole Gates mine 1% more for every Black Hole Siphon you own.'],
    'oreLaw' => ['name' => 'Universal Ore Law', 'icon' => '⚖️', 'cost' => 1e17, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'pickaxesEverywhere' => ['name' => 'Pickaxe Guys Everywhere', 'icon' => '🔗', 'cost' => 1.3e17, 'type' => 'synergy', 'building' => 'multiverse', 'source' => 'pickaxe', 'per' => 0.01, 'every' => 10,
        'need' => ['owned' => ['multiverse' => 1, 'pickaxe' => 10]], 'text' => 'Multiverse Drills mine 1% more for every 10 Space Pickaxe Guys you own.'],
    'cosmicAccounting' => ['name' => 'Cosmic Accounting', 'icon' => '🧮', 'cost' => 1e20, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'bigOreTheory' => ['name' => 'Big Ore Theory', 'icon' => '📚', 'cost' => 1e23, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
];
const HBHUB_MINER_CLICK_TYPES = ['click', 'clickPct', 'crit', 'auto']; // kept by Persistent Memory
const HBHUB_MINER_ETERNAL_TYPES = ['bulk', 'comet']; // kept by Eternal Blueprints

// Bought with Dark Matter, kept until a Big Crunch (or after it too, with Dark Echo). Long-Range Hot Keys doesn't change
// the game, only which buildings the 1-9 keys buy, so it's only in spaceOreMiner.js.
const HBHUB_MINER_DARK_UPGRADES = [
    'headStart' => ['name' => 'Head Start', 'icon' => '🛸', 'cost' => 5, 'text' => 'Start every run with ' . HBHUB_MINER_HEAD_START . ' Mining Drones.'],
    'memory' => ['name' => 'Persistent Memory', 'icon' => '🧠', 'cost' => 40, 'text' => 'Keep your click upgrades when you Warp Jump.'],
    'darkEngines' => ['name' => 'Dark Engines', 'icon' => '⚫', 'cost' => 100, 'text' => 'All buildings mine 50% more.'],
    'timeDilation' => ['name' => 'Time Dilation', 'icon' => '⏳', 'cost' => 250, 'text' => 'Earn twice as much while you\'re away.'],
    'darkPickaxe' => ['name' => 'Dark Pickaxe', 'icon' => '⛏️', 'cost' => 15, 'text' => 'Clicks mine ' . HBHUB_MINER_DARK_PICKAXE . ' times as much.'],
    'lateKeys' => ['name' => 'Long-Range Hot Keys', 'icon' => '🎛️', 'cost' => 2, 'text' => 'Adds a switch to the store (or press K) that makes the 1-9 keys buy your last nine buildings instead of your first nine.'],
    'physics' => ['name' => 'Cheaper Physics', 'icon' => '📉', 'cost' => 750, 'text' => 'Each building costs 14% more than the one before instead of 15% (11% instead of 12% for Space Pickaxe Guys).'],
    'reactor' => ['name' => 'Dark Matter Reactor', 'icon' => '☢️', 'cost' => 2500, 'text' => 'Every Dark Matter you\'ve earned makes everything mine '
        . HBHUB_MINER_DARK_BONUS_REACTOR * 100 . '% faster, instead of ' . HBHUB_MINER_DARK_BONUS * 100 . '%.'],
];

// Bought with Antimatter, kept forever
const HBHUB_MINER_ANTI_UPGRADES = [
    'echo' => ['name' => 'Dark Echo', 'icon' => '🔁', 'cost' => 1, 'text' => 'Keep your Dark Matter upgrades when you Big Crunch.'],
    'converter' => ['name' => 'Matter Converter', 'icon' => '⚗️', 'cost' => 3, 'text' => 'Warp Jumps give twice as much Dark Matter.'],
    'leftovers' => ['name' => 'Big Bang Leftovers', 'icon' => '💫', 'cost' => 5, 'text' => 'Start every run with 10 million ore.'],
    'eternal' => ['name' => 'Eternal Blueprints', 'icon' => '📜', 'cost' => 10, 'text' => 'Keep Bulk Buying and your comet upgrades when you Warp Jump.'],
    'annihilation' => ['name' => 'Annihilation Engines', 'icon' => '💥', 'cost' => 25, 'text' => 'All buildings mine twice as much.'],
    'antiComets' => ['name' => 'Antimatter Comets', 'icon' => '☄️', 'cost' => 50, 'text' => 'Comet effects are twice as strong.'],
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
];

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
 * Everything the script needs to play by the same rules: the data above and the numbers, as JSON for data-config.
 */
function hbHubMinerConfig(): array
{
    return [
        'buildings' => HBHUB_MINER_BUILDINGS,
        'upgrades' => hbHubMinerUpgrades(),
        'darkUpgrades' => HBHUB_MINER_DARK_UPGRADES,
        'antiUpgrades' => HBHUB_MINER_ANTI_UPGRADES,
        'achievements' => HBHUB_MINER_ACHIEVEMENTS,
        'comets' => HBHUB_MINER_COMETS,
        'clickTypes' => HBHUB_MINER_CLICK_TYPES,
        'eternalTypes' => HBHUB_MINER_ETERNAL_TYPES,
        'saveSeconds' => HBHUB_MINER_SAVE_SECONDS,
        'onlineMs' => HBHUB_MINER_ONLINE_MS,
        'offlineRate' => HBHUB_MINER_OFFLINE_RATE,
        'clicksPerSecond' => HBHUB_MINER_CLICKS_PER_SECOND,
        'fastClicks' => HBHUB_MINER_FAST_CLICKS,
        'fastMs' => HBHUB_MINER_FAST_MS,
        'fastValue' => HBHUB_MINER_FAST_VALUE,
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
        'leftoverOre' => HBHUB_MINER_LEFTOVER_ORE,
        'darkPickaxe' => HBHUB_MINER_DARK_PICKAXE,
        'cometWaitMs' => HBHUB_MINER_COMET_WAIT_MS,
        'cometStayMs' => HBHUB_MINER_COMET_STAY_MS,
        'diamondChance' => HBHUB_MINER_DIAMOND_CHANCE,
        'critChance' => HBHUB_MINER_CRIT_CHANCE,
        'critMultiplier' => HBHUB_MINER_CRIT_MULTIPLIER,
        'luckyShare' => HBHUB_MINER_LUCKY_SHARE,
        'luckySeconds' => HBHUB_MINER_LUCKY_SECONDS,
        'headStart' => HBHUB_MINER_HEAD_START,
        'buildingUpgradeAt' => HBHUB_MINER_BUILDING_UPGRADE_AT,
    ];
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
 *   this universe (the production bonus). darkUpgrades: bought with it. warps: Warp Jumps made ever.
 * - antimatter: to spend. antimatterEarned: ever earned (the bonus). antiUpgrades: bought with it. crunches: Big
 *   Crunches made.
 * - clicks, crits, comets (caught), diamonds (Diamond Comets caught): counted ever.
 * - fast: how fast the clicks come, to spot auto-clickers (hbHubMinerFastClick()): times (of the last
 *   HBHUB_MINER_FAST_CLICKS + 1 clicks, oldest first) and since (when clicking faster than that began).
 * - comet: the next or current comet: n (its number, for its luck) and at (when it shows up).
 * - buffs: the comet effects running: 'production' or 'click' => {kind, multiplier, until}.
 * - achievements: the ids done. tick: how far the game has run. started, runStarted: when the save and this run began.
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
        'antimatter' => 0,
        'antimatterEarned' => 0,
        'antiUpgrades' => [],
        'crunches' => 0,
        'clicks' => 0,
        'crits' => 0,
        'comets' => 0,
        'diamonds' => 0,
        'fast' => ['since' => 0, 'times' => []],
        'comet' => ['n' => 0, 'at' => $now],
        'buffs' => [],
        'achievements' => [],
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
    $state = array_replace(hbHubMinerNewState($now), $saved);
    $state['buildings'] = array_replace(array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0),
        array_intersect_key($state['buildings'], HBHUB_MINER_BUILDINGS));
    $state['upgrades'] = array_values(array_intersect($state['upgrades'], array_keys(hbHubMinerUpgrades())));
    $state['darkUpgrades'] = array_values(array_intersect($state['darkUpgrades'], array_keys(HBHUB_MINER_DARK_UPGRADES)));
    $state['antiUpgrades'] = array_values(array_intersect($state['antiUpgrades'], array_keys(HBHUB_MINER_ANTI_UPGRADES)));
    $state['fast'] = [
        'since' => (int) ($state['fast']['since'] ?? 0),
        'times' => array_map('intval', array_slice(array_values($state['fast']['times'] ?? []), -(HBHUB_MINER_FAST_CLICKS + 1))),
    ];
    unset($state['steady']); // the old auto-clicker check's

    foreach (['ore', 'runOre', 'universeOre', 'allOre', 'clickOre'] as $key) {
        $state[$key] = (float) $state[$key];
    }
    return $state;
}

/**
 * The save as the page gets it (all of it: nothing in it is secret): the same, but buffs stays a JSON object when empty.
 */
function hbHubMinerView(array $state): array
{
    $state['buffs'] = (object) $state['buffs'];
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

/**
 * The production each Dark Matter earned adds (0.025 = +2.5%).
 */
function hbHubMinerDarkBonus(array $state): float
{
    return hbHubMinerHasDark($state, 'reactor') ? HBHUB_MINER_DARK_BONUS_REACTOR : HBHUB_MINER_DARK_BONUS;
}

/**
 * What Antimatter does to production, and to the Dark Matter from a Warp Jump: 1 + HBHUB_MINER_ANTI_BONUS for each one
 * ever earned.
 */
function hbHubMinerAntiMultiplier(array $state): float
{
    return 1 + HBHUB_MINER_ANTI_BONUS * $state['antimatterEarned'];
}

/**
 * How much stronger comets are: 2 with Antimatter Comets.
 */
function hbHubMinerCometPower(array $state): int
{
    return hbHubMinerHasAnti($state, 'antiComets') ? 2 : 1;
}

/**
 * How much more each of a building costs than the one before: its own 'growth', or HBHUB_MINER_COST_GROWTH, less
 * HBHUB_MINER_COST_GROWTH_PHYSICS with Cheaper Physics.
 */
function hbHubMinerCostGrowth(array $state, string $buildingId): float
{
    return (HBHUB_MINER_BUILDINGS[$buildingId]['growth'] ?? HBHUB_MINER_COST_GROWTH)
        - (hbHubMinerHasDark($state, 'physics') ? HBHUB_MINER_COST_GROWTH_PHYSICS : 0);
}

/**
 * What the next $amount of a building cost together, rounded up: each costs hbHubMinerCostGrowth() times the one before.
 */
function hbHubMinerBuildingCost(array $state, string $buildingId, int $amount): float
{
    $growth = hbHubMinerCostGrowth($state, $buildingId);
    return ceil(HBHUB_MINER_BUILDINGS[$buildingId]['cost'] * $growth ** $state['buildings'][$buildingId]
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
 * The ore per second from each building (id => all of them together), with upgrades, Dark Matter and Antimatter but
 * without comets.
 */
function hbHubMinerBuildingRates(array $state): array
{
    $upgrades = hbHubMinerUpgrades();
    $multipliers = array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 1.0);
    $crew = array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0.0);
    $global = (1 + hbHubMinerDarkBonus($state) * $state['darkEarned']) * hbHubMinerAntiMultiplier($state);
    if (hbHubMinerHasDark($state, 'darkEngines')) {
        $global *= 1.5;
    }
    if (hbHubMinerHasAnti($state, 'annihilation')) {
        $global *= 2;
    }
    foreach ($state['upgrades'] as $upgradeId) {
        $upgrade = $upgrades[$upgradeId];
        if ($upgrade['type'] === 'building') {
            $multipliers[$upgrade['building']] *= 2;
        } elseif ($upgrade['type'] === 'synergy') {
            $multipliers[$upgrade['building']] *= 1 + $upgrade['per'] * floor($state['buildings'][$upgrade['source']] / $upgrade['every']);
        } elseif ($upgrade['type'] === 'crew') {
            $crew[$upgrade['building']] += $upgrade['value'];
        } elseif ($upgrade['type'] === 'global') {
            $global *= 1 + $upgrade['value'];
        }
    }

    $rates = [];
    $owned = array_sum($state['buildings']);
    foreach (HBHUB_MINER_BUILDINGS as $buildingId => $building) {
        $rate = $building['rate'] + $crew[$buildingId] * ($owned - $state['buildings'][$buildingId]);
        $rates[$buildingId] = $rate * $state['buildings'][$buildingId] * $multipliers[$buildingId] * $global;
    }
    return $rates;
}

/**
 * A comet effect's multiplier at $time (1 when none is running). $time PHP_INT_MAX leaves comets out.
 */
function hbHubMinerBuff(array $state, string $buff, int $time): float
{
    $running = $state['buffs'][$buff] ?? null;
    return $running !== null && $running['until'] > $time ? (float) $running['multiplier'] : 1.0;
}

/**
 * What one click mines at $time, before a critical click: 1 (3 with Dark Pickaxe), doubled by each click upgrade, plus
 * the clickPct upgrades' share of the buildings' production, all times a Click Storm.
 */
function hbHubMinerClickValue(array $state, int $time): float
{
    $upgrades = hbHubMinerUpgrades();
    $value = hbHubMinerHasDark($state, 'darkPickaxe') ? (float) HBHUB_MINER_DARK_PICKAXE : 1.0;
    $share = 0.0;
    foreach ($state['upgrades'] as $upgradeId) {
        if ($upgrades[$upgradeId]['type'] === 'click') {
            $value *= $upgrades[$upgradeId]['value'];
        } elseif ($upgrades[$upgradeId]['type'] === 'clickPct') {
            $share += $upgrades[$upgradeId]['value'];
        }
    }
    $production = $share > 0 ? array_sum(hbHubMinerBuildingRates($state)) * hbHubMinerBuff($state, 'production', $time) : 0;
    return ($value + $share * $production) * hbHubMinerBuff($state, 'click', $time);
}

/**
 * How many times a second the auto upgrades (Auto-Clicker Bot and Swarm) click, together.
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
    return $clicks;
}

/**
 * All the ore per second at $time: the buildings (times a production comet) and the auto-clickers.
 * $time PHP_INT_MAX gives the steady rate, without comets, as the leaderboard shows it.
 */
function hbHubMinerRate(array $state, int $time): float
{
    $rate = array_sum(hbHubMinerBuildingRates($state)) * hbHubMinerBuff($state, 'production', $time);
    $autoClicks = hbHubMinerAutoClicks($state);
    if ($autoClicks > 0) {
        $rate += $autoClicks * hbHubMinerClickValue($state, $time);
    }
    return $rate;
}

function hbHubMinerEarn(array &$state, float $ore): void
{
    $state['ore'] += $ore;
    $state['runOre'] += $ore;
    $state['universeOre'] += $ore;
    $state['allOre'] += $ore;
}

/**
 * How long comet number $n waits after the one before, in ms.
 */
function hbHubMinerCometWait(array $state, int $n): int
{
    [$min, $max] = HBHUB_MINER_COMET_WAIT_MS;
    $wait = $min + hbHubMinerRandom($state['seed'], $n, HBHUB_MINER_SALT_COMET_WAIT) * ($max - $min);
    return (int) floor(hbHubMinerHas($state, 'radar') ? $wait / 2 : $wait);
}

function hbHubMinerCometStay(array $state): int
{
    return hbHubMinerHas($state, 'magnet') ? HBHUB_MINER_COMET_STAY_MS * 2 : HBHUB_MINER_COMET_STAY_MS;
}

/**
 * What the current comet does when caught (a key of HBHUB_MINER_COMETS).
 */
function hbHubMinerCometKind(array $state): string
{
    $n = $state['comet']['n'];
    if (hbHubMinerHas($state, 'luckyStars') && hbHubMinerRandom($state['seed'], $n, HBHUB_MINER_SALT_DIAMOND) < HBHUB_MINER_DIAMOND_CHANCE) {
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
 * Time after $fullUntil is time away, mined at HBHUB_MINER_OFFLINE_RATE (doubled with Time Dilation).
 * Returns the ore mined while away.
 */
function hbHubMinerAdvance(array &$state, int $to, int $fullUntil = PHP_INT_MAX): float
{
    $offlineOre = 0.0;
    while ($state['tick'] < $to) {
        // In pieces, so the rate is the same all through each: up to an effect running out, or going away
        $next = $to;
        foreach ($state['buffs'] as $buff) {
            if ($buff['until'] > $state['tick'] && $buff['until'] < $next) {
                $next = $buff['until'];
            }
        }
        if ($fullUntil > $state['tick'] && $fullUntil < $next) {
            $next = $fullUntil;
        }

        $ore = hbHubMinerRate($state, $state['tick']) * ($next - $state['tick']) / 1000;
        if ($state['tick'] >= $fullUntil) {
            $ore *= HBHUB_MINER_OFFLINE_RATE * (hbHubMinerHasDark($state, 'timeDilation') ? 2 : 1);
            $offlineOre += $ore;
        }
        hbHubMinerEarn($state, $ore);
        $state['tick'] = $next;
        $state['buffs'] = array_filter($state['buffs'], static fn (array $buff): bool => $buff['until'] > $next);
    }
    hbHubMinerCometsPassed($state);
    return $offlineOre;
}

/**
 * Whether an upgrade shows up in the store (bought or not): its need (ore this run, buildings owned, comets caught).
 */
function hbHubMinerUpgradeUnlocked(array $state, array $upgrade): bool
{
    $need = $upgrade['need'];
    if (isset($need['ore']) && $state['runOre'] < $need['ore']) {
        return false;
    }
    if (isset($need['comets']) && $state['comets'] < $need['comets']) {
        return false;
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
 * every jump double the one before, until the numbers ran away.
 */
function hbHubMinerDarkTotal(float $universeOre): int
{
    // ** (1 / 3) is a hair off (1000 gives 9.999...), so it's put right with whole numbers, which are exact
    $units = $universeOre / HBHUB_MINER_DARK_UNIT;
    $total = (int) floor($units ** (1 / 3));
    while (($total + 1) ** 3 <= $units) {
        $total++;
    }
    while ($total > 0 && $total ** 3 > $units) {
        $total--;
    }
    return $total;
}

/**
 * The Dark Matter a Warp Jump would give now, or 0 when it can't be done yet (HBHUB_MINER_WARP_ORE not mined this run).
 * Antimatter (and Matter Converter) make the whole total bigger, which only ever goes up within a universe.
 */
function hbHubMinerWarpGain(array $state): int
{
    if ($state['runOre'] < HBHUB_MINER_WARP_ORE) {
        return 0;
    }
    $total = (int) floor(hbHubMinerDarkTotal($state['universeOre']) * hbHubMinerAntiMultiplier($state)
        * (hbHubMinerHasAnti($state, 'converter') ? 2 : 1));
    return max(0, $total - $state['darkEarned']);
}

/**
 * The Antimatter the Dark Matter earned in a universe is worth: the square root of how many HBHUB_MINER_ANTI_UNITs it
 * is (so 4 for 2,000, 10 for 10,000, 20 for 40,000), worked out with whole numbers only so it's exact.
 */
function hbHubMinerAntiTotal(int $darkEarned): int
{
    $total = 0;
    while (($total + 1) ** 2 * HBHUB_MINER_ANTI_UNIT <= $darkEarned) {
        $total++;
    }
    return $total;
}

/**
 * The Antimatter a Big Crunch would give now, or 0 when it can't be done yet (HBHUB_MINER_CRUNCH_DARK not earned in
 * this universe). Dark Matter starts over with every crunch, so each one gives what all of it is worth.
 */
function hbHubMinerCrunchGain(array $state): int
{
    return $state['darkEarned'] >= HBHUB_MINER_CRUNCH_DARK ? hbHubMinerAntiTotal((int) $state['darkEarned']) : 0;
}

/**
 * Whether a click at $time (Unix ms) comes from an auto-clicker, so it only mines HBHUB_MINER_FAST_VALUE. That's when
 * there have been more than HBHUB_MINER_FAST_CLICKS clicks in every second for HBHUB_MINER_FAST_MS: this click and the
 * HBHUB_MINER_FAST_CLICKS before it all came within a second, and have done since 'since'. Nobody's finger keeps that
 * up for so long. It's kept in the save's 'fast', so it carries on from one save to the next, and one slower second
 * starts it over. The script does the same in fastClick().
 */
function hbHubMinerFastClick(array &$state, int $time): bool
{
    $fast = &$state['fast'];
    $count = count($fast['times']);
    $time = max($time, $count > 0 ? (int) $fast['times'][$count - 1] : 0);
    $fast['times'][] = $time;
    if ($count + 1 > HBHUB_MINER_FAST_CLICKS + 1) {
        array_shift($fast['times']);
    }
    if (count($fast['times']) <= HBHUB_MINER_FAST_CLICKS || $time - $fast['times'][0] >= 1000) {
        $fast['since'] = $time; // not that fast, so it starts over from this click
    }
    return $time - $fast['since'] >= HBHUB_MINER_FAST_MS;
}

/**
 * Clicks the asteroid $count times at the game's time. $offsets: when each click was, in ms after that (the page groups
 * the clicks of a second into one action); a missing one is 0. Returns the ore mined.
 */
function hbHubMinerClick(array &$state, int $count, array $offsets = []): float
{
    $value = hbHubMinerClickValue($state, $state['tick']);
    $canCrit = hbHubMinerHas($state, 'crit');
    $mined = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $offset = is_numeric($offsets[$i] ?? null) ? min(max((int) $offsets[$i], 0), 999) : 0;
        $ore = $value;
        if ($canCrit && hbHubMinerRandom($state['seed'], $state['clicks'], HBHUB_MINER_SALT_CRIT) < HBHUB_MINER_CRIT_CHANCE) {
            $ore *= HBHUB_MINER_CRIT_MULTIPLIER;
            $state['crits']++;
        }
        if (hbHubMinerFastClick($state, $state['tick'] + $offset)) {
            $ore *= HBHUB_MINER_FAST_VALUE;
        }
        $state['clicks']++;
        $mined += $ore;
    }
    $state['clickOre'] += $mined;
    hbHubMinerEarn($state, $mined);
    return $mined;
}

/**
 * Catches the comet at the game's time, if it's on screen. Returns its kind, or null when there wasn't one.
 */
function hbHubMinerCatchComet(array &$state): ?string
{
    $time = $state['tick'];
    if ($time < $state['comet']['at'] || $time > $state['comet']['at'] + hbHubMinerCometStay($state)) {
        return null;
    }

    $kind = hbHubMinerCometKind($state);
    $comet = HBHUB_MINER_COMETS[$kind];
    if ($comet['buff'] !== null) {
        $state['buffs'][$comet['buff']] = [
            'kind' => $kind,
            'multiplier' => $comet['multiplier'] * hbHubMinerCometPower($state),
            'until' => $time + $comet['ms'] * (hbHubMinerHas($state, 'tail') ? 2 : 1),
        ];
    } else {
        $production = array_sum(hbHubMinerBuildingRates($state)) * hbHubMinerBuff($state, 'production', $time);
        hbHubMinerEarn($state, (min($state['ore'] * HBHUB_MINER_LUCKY_SHARE, $production * HBHUB_MINER_LUCKY_SECONDS) + 13)
            * (hbHubMinerHas($state, 'stardust') ? 2 : 1) * hbHubMinerCometPower($state));
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
 * Starts a new run, for a Warp Jump or a Big Crunch: ore, buildings and upgrades start over, apart from the click
 * upgrades with Persistent Memory, Bulk Buying and the comet upgrades with Eternal Blueprints, the drones from Head
 * Start and the ore from Big Bang Leftovers.
 */
function hbHubMinerNewRun(array &$state): void
{
    $upgrades = hbHubMinerUpgrades();
    $kept = array_merge(hbHubMinerHasDark($state, 'memory') ? HBHUB_MINER_CLICK_TYPES : [],
        hbHubMinerHasAnti($state, 'eternal') ? HBHUB_MINER_ETERNAL_TYPES : []);
    $state['upgrades'] = array_values(array_filter($state['upgrades'], static fn (string $id): bool =>
        in_array($upgrades[$id]['type'], $kept, true)));
    $state['buildings'] = array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0);
    if (hbHubMinerHasDark($state, 'headStart')) {
        $state['buildings']['drone'] = HBHUB_MINER_HEAD_START;
    }
    $state['ore'] = hbHubMinerHasAnti($state, 'leftovers') ? HBHUB_MINER_LEFTOVER_ORE : 0.0;
    $state['runOre'] = 0.0;
    $state['buffs'] = [];
    $state['runStarted'] = $state['tick'];
}

/**
 * Warp Jump: everything of this run starts over (hbHubMinerNewRun()), for the Dark Matter it's worth. Returns false
 * when it can't be done yet.
 */
function hbHubMinerWarp(array &$state): bool
{
    $gain = hbHubMinerWarpGain($state);
    if ($gain < 1) {
        return false;
    }

    hbHubMinerNewRun($state);
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
 * One thing the player did, at the game's time. Returns whether it counted.
 * - ['type' => 'click', 'count' => n, 'offsets' => [ms after 'at' of each click]]: see hbHubMinerSync() for the limit.
 * - ['type' => 'buy', 'building' => id, 'amount' => 1, 10 or 100] (10 and 100 need Bulk Buying).
 * - ['type' => 'upgrade', 'id' => id], ['type' => 'dark', 'id' => id] (a Dark Matter upgrade), ['type' => 'anti',
 *   'id' => id] (an Antimatter upgrade).
 * - ['type' => 'comet'], ['type' => 'warp'], ['type' => 'crunch'].
 */
function hbHubMinerApply(array &$state, array $action): bool
{
    $type = $action['type'] ?? null;

    if ($type === 'click') {
        $count = (int) ($action['count'] ?? 0);
        if ($count < 1) {
            return false;
        }
        hbHubMinerClick($state, $count, is_array($action['offsets'] ?? null) ? array_values($action['offsets']) : []);
        return true;
    }

    if ($type === 'buy') {
        $buildingId = $action['building'] ?? null;
        $amount = (int) ($action['amount'] ?? 1);
        if (!is_string($buildingId) || !isset(HBHUB_MINER_BUILDINGS[$buildingId]) || !in_array($amount, [1, 10, 100], true)
            || ($amount > 1 && !hbHubMinerHas($state, 'bulk'))) {
            return false;
        }
        $cost = hbHubMinerBuildingCost($state, $buildingId, $amount);
        if (!hbHubMinerCanAfford($state, $cost)) {
            return false;
        }
        $state['ore'] = max(0.0, $state['ore'] - $cost);
        $state['buildings'][$buildingId] += $amount;
        return true;
    }

    if ($type === 'upgrade') {
        $upgradeId = $action['id'] ?? null;
        $upgrades = hbHubMinerUpgrades();
        if (!is_string($upgradeId) || !isset($upgrades[$upgradeId]) || hbHubMinerHas($state, $upgradeId)
            || !hbHubMinerUpgradeUnlocked($state, $upgrades[$upgradeId]) || !hbHubMinerCanAfford($state, $upgrades[$upgradeId]['cost'])) {
            return false;
        }
        $state['ore'] = max(0.0, $state['ore'] - $upgrades[$upgradeId]['cost']);
        $state['upgrades'][] = $upgradeId;
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
            $state['buildings']['drone'] = max($state['buildings']['drone'], HBHUB_MINER_HEAD_START);
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

    if ($type === 'comet') {
        return hbHubMinerCatchComet($state) !== null;
    }

    if ($type === 'warp') {
        return hbHubMinerWarp($state);
    }

    if ($type === 'crunch') {
        return hbHubMinerCrunch($state);
    }

    return false;
}

/**
 * A number an achievement checks (its 'stat'). owned:<building> is how many of it there are.
 */
function hbHubMinerStat(array $state, string $stat): float
{
    return match ($stat) {
        'rate' => hbHubMinerRate($state, PHP_INT_MAX),
        'upgrades' => count($state['upgrades']),
        'buildings' => array_sum($state['buildings']),
        'ownedAll' => min($state['buildings']),
        'allOre', 'clicks', 'crits', 'comets', 'diamonds', 'warps', 'crunches' => (float) $state[$stat],
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
 * ['state' => the save (hbHubMinerView()), 'now' => ms, 'offline' => null or ['ms' => away for, 'ore' => mined meanwhile]],
 * or 'otherTab' when the game was opened in another tab since (the newest tab plays; $claim is how a page that just
 * opened takes over). A save is made on the first call.
 * $actions: what hbHubMinerApply() takes, each with 'at' (when, Unix ms), oldest first. Times are kept between the
 * last save and now and in order, and clicks beyond HBHUB_MINER_CLICKS_PER_SECOND since the last save don't count.
 */
function hbHubMinerSync(PDO $db, int $userId, string $tab, bool $claim, array $actions): array|string
{
    $db->beginTransaction();
    try {
        $now = hbHubMinerNow();
        $db->prepare('INSERT IGNORE INTO `HBHub-MinerSaves` (userId, saveState, saveTab, saveStartTimestamp, saveUpdatedTimestamp)
            VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$userId, hbHubMinerEncode(hbHubMinerNewState($now)), $tab]);
        $statement = $db->prepare('SELECT saveState, saveTab FROM `HBHub-MinerSaves` WHERE userId = ? FOR UPDATE');
        $statement->execute([$userId]);
        $row = $statement->fetch();

        if (!$claim && $row['saveTab'] !== $tab) {
            $db->rollBack();
            return 'otherTab';
        }

        $state = hbHubMinerNormalize(json_decode($row['saveState'], true, 64, JSON_THROW_ON_ERROR), $now);
        $since = min($state['tick'], $now);
        $fullUntil = $since + HBHUB_MINER_ONLINE_MS;
        $clicksLeft = (int) floor(($now - $since) / 1000 * HBHUB_MINER_CLICKS_PER_SECOND) + HBHUB_MINER_CLICKS_PER_SECOND;
        $offlineOre = 0.0;

        foreach (array_slice($actions, 0, HBHUB_MINER_MAX_ACTIONS) as $action) {
            if (!is_array($action)) {
                continue;
            }
            if (($action['type'] ?? null) === 'click') {
                $action['count'] = min((int) ($action['count'] ?? 0), $clicksLeft);
                $clicksLeft -= max(0, $action['count']);
            }
            $at = is_numeric($action['at'] ?? null) ? (int) $action['at'] : $now;
            $offlineOre += hbHubMinerAdvance($state, max($state['tick'], min($at, $now)), $fullUntil);
            if (hbHubMinerApply($state, $action)) {
                hbHubMinerCheckAchievements($state);
            }
        }
        $offlineOre += hbHubMinerAdvance($state, $now, $fullUntil);
        hbHubMinerCheckAchievements($state);

        $db->prepare('UPDATE `HBHub-MinerSaves` SET saveState = ?, saveTab = ?, saveOreAllTime = ?, saveOrePerSecond = ?,
                saveDarkMatter = ?, saveAntimatter = ?, saveWarps = ?, saveClicks = ?, saveAchievements = ?,
                saveUpdatedTimestamp = UTC_TIMESTAMP()
            WHERE userId = ?')
            ->execute([hbHubMinerEncode($state), $tab, $state['allOre'], hbHubMinerRate($state, PHP_INT_MAX), $state['darkEarned'],
                $state['antimatterEarned'], $state['warps'], $state['clicks'], count($state['achievements']), $userId]);
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
        'offline' => $offlineOre > 0 ? ['ms' => $now - $since, 'ore' => $offlineOre] : null,
    ];
}
