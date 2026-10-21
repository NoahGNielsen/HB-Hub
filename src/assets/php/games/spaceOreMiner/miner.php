<?php
// Space Ore Miner, the clicker game: click the asteroid for ore, buy machines that mine for you, and grow from one
// miner on a rock into a galaxy-wide mining company. Warp Jump (prestige) starts over in a new galaxy for Dark Matter,
// which makes everything mine faster for good.
//
// Each user has one save: a row in HBHub-MinerSaves with the whole game as JSON in saveState (the shape is in
// hbHubMinerNewState()), plus a few of its numbers in their own columns for the leaderboard.
//
// The game runs in the browser (spaceOreMiner.js) so it's smooth, but the server has the last word: every 30 seconds
// (and when the tab is hidden or closed) the page sends what the player did since the last save - clicks, buildings and
// upgrades bought, comets caught, Warp Jumps - each with the time it happened, and hbHubMinerSync() plays them again on
// the saved game with the same rules as the script. Something that couldn't have happened (buying without the ore,
// catching a comet that wasn't there, more than HBHUB_MINER_CLICKS_PER_SECOND clicks a second) just doesn't count, and
// the page carries on from the game the server sends back. Clicks that come in like clockwork (an auto-clicker, see
// hbHubMinerSteadyClick()) only mine HBHUB_MINER_STEADY_VALUE of what they would. So the script and this file must work the same: the rules
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
const HBHUB_MINER_STEADY_MS = 20000; // clicks at the same pace for this long are an auto-clicker...
const HBHUB_MINER_STEADY_TOLERANCE_MS = 15; // (each gap between two clicks within this of the gap before it)
const HBHUB_MINER_STEADY_VALUE = 0.2; // ...and only mine this share of what a click is worth
const HBHUB_MINER_MAX_ACTIONS = 2000; // in one save
const HBHUB_MINER_COST_GROWTH = 1.15; // each building costs this much more than the one before
const HBHUB_MINER_COST_GROWTH_CHEAP = 1.14; // with Cheaper Physics
const HBHUB_MINER_WARP_ORE = 1e9; // ore to mine in a run before a Warp Jump
const HBHUB_MINER_DARK_UNIT = 1e9; // all-time ore for the first Dark Matter, see hbHubMinerDarkTotal()
const HBHUB_MINER_DARK_BONUS = 0.025; // production per Dark Matter ever earned
const HBHUB_MINER_COMET_WAIT_MS = [60000, 180000]; // between two comets, halved with Comet Radar
const HBHUB_MINER_COMET_STAY_MS = 12000; // on screen, doubled with Comet Magnet
const HBHUB_MINER_DIAMOND_CHANCE = 0.05; // with Lucky Stars
const HBHUB_MINER_CRIT_CHANCE = 0.05;
const HBHUB_MINER_CRIT_MULTIPLIER = 10;
const HBHUB_MINER_HEAD_START = 10; // Mining Drones with Head Start
const HBHUB_MINER_TAB_PATTERN = '/^[0-9a-f]{32}$/';

// What a comet does when it's caught: production or clicks times 'multiplier' for 'ms' (doubled with Tail Collector),
// or ore right away (lucky: 15% of the ore you have, at most 15 minutes of production, plus 13)
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

// The buildings, cheapest first. 'rate' is ore per second for each one before upgrades, 'upgrades' their three
// upgrades (at 10, 25 and 50 owned, see HBHUB_MINER_BUILDING_UPGRADE_AT)
const HBHUB_MINER_BUILDINGS = [
    'pickaxe' => ['name' => 'Space Pickaxe Guy', 'plural' => 'Space Pickaxe Guys', 'icon' => '👷', 'cost' => 15, 'rate' => 0.1,
        'text' => 'Swings a pickaxe at the rock. In zero gravity, that\'s harder than it sounds.',
        'upgrades' => ['Energy Drinks', 'Hard Hats', 'Union Contract']],
    'drone' => ['name' => 'Mining Drone', 'plural' => 'Mining Drones', 'icon' => '🛸', 'cost' => 100, 'rate' => 1,
        'text' => 'A little robot that nibbles ore off the asteroid all day long.',
        'upgrades' => ['Longer Batteries', 'Drone Swarm AI', 'Self-Repair Nanites']],
    'laser' => ['name' => 'Laser Drill', 'plural' => 'Laser Drills', 'icon' => '🔦', 'cost' => 1100, 'rate' => 8,
        'text' => 'Melts through rock like butter. Don\'t look straight into it.',
        'upgrades' => ['Focused Lens', 'Overclocked Lasers', 'Diamond Tips']],
    'rig' => ['name' => 'Mining Rig', 'plural' => 'Mining Rigs', 'icon' => '🏗️', 'cost' => 12000, 'rate' => 47,
        'text' => 'A walking factory bolted onto the rock.',
        'upgrades' => ['Heavy Treads', 'Double Shifts', 'Autonomous Rigs']],
    'base' => ['name' => 'Asteroid Base', 'plural' => 'Asteroid Bases', 'icon' => '🏠', 'cost' => 130000, 'rate' => 260,
        'text' => 'A whole crew living on site. Shifts never end in space.',
        'upgrades' => ['Hydroponics', 'Ore Refinery', 'Artificial Gravity']],
    'freighter' => ['name' => 'Ore Freighter', 'plural' => 'Ore Freighters', 'icon' => '🚀', 'cost' => 1.4e6, 'rate' => 1400,
        'text' => 'Hauls in ore from asteroids you haven\'t even seen yet.',
        'upgrades' => ['Bigger Cargo Holds', 'Hyperspace Lanes', 'Fleet Command']],
    'station' => ['name' => 'Space Station', 'plural' => 'Space Stations', 'icon' => '🛰️', 'cost' => 2e7, 'rate' => 7800,
        'text' => 'Runs mining all over the solar system from orbit.',
        'upgrades' => ['Solar Arrays', 'Fusion Core', 'Ring Habitat']],
    'excavator' => ['name' => 'Moon Excavator', 'plural' => 'Moon Excavators', 'icon' => '🌙', 'cost' => 3.3e8, 'rate' => 44000,
        'text' => 'Digs up whole moons. The moons have stopped complaining.',
        'upgrades' => ['Moon Treads', 'Crater Scoops', 'Core Tap']],
    'cracker' => ['name' => 'Planet Cracker', 'plural' => 'Planet Crackers', 'icon' => '🪐', 'cost' => 5.1e9, 'rate' => 260000,
        'text' => 'Cracks planets open like eggs to get at the ore inside.',
        'upgrades' => ['Seismic Charges', 'Tectonic Splitter', 'Planet Shredder']],
    'siphon' => ['name' => 'Black Hole Siphon', 'plural' => 'Black Hole Siphons', 'icon' => '🕳️', 'cost' => 7.5e10, 'rate' => 1.6e6,
        'text' => 'Pulls ore out of a black hole. Nobody knows how. Nobody asks.',
        'upgrades' => ['Event Horizon Net', 'Hawking Pump', 'Singularity Engine']],
];
const HBHUB_MINER_BUILDING_UPGRADE_AT = [10, 25, 50]; // owned, for each building's three upgrades
const HBHUB_MINER_BUILDING_UPGRADE_COST = [10, 50, 500]; // times the building's base cost

// The upgrades that aren't a building's own (those are made from HBHUB_MINER_BUILDINGS in hbHubMinerUpgrades()).
// 'type' says what it does:
// - click: clicks mine 'value' times as much. clickPct: each click also mines 'value' of the ore per second.
// - crit: a click can be critical (HBHUB_MINER_CRIT_CHANCE, HBHUB_MINER_CRIT_MULTIPLIER). auto: 'value' clicks a second.
// - global: every building mines 'value' more (0.1 = +10%), all of them multiplied together. bulk: the x10 and x100 buttons.
// - synergy: 'building' mines 'per' more for every 'every' of 'source' owned.
// - comet: 'effect' often, longer, tail or diamond.
// 'need' is when it shows up in the store: ore (mined this run), owned (building => how many) and comets (caught ever).
// Without one, it's ore mined this run of half its cost.
const HBHUB_MINER_OTHER_UPGRADES = [
    'titanium' => ['name' => 'Titanium Pickaxe', 'icon' => '⛏️', 'cost' => 100, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'gloves' => ['name' => 'Reinforced Gloves', 'icon' => '🧤', 'cost' => 500, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'plasma' => ['name' => 'Plasma Gloves', 'icon' => '🔥', 'cost' => 5000, 'type' => 'click', 'value' => 2, 'text' => 'Clicks mine twice as much.'],
    'fuel' => ['name' => 'Better Fuel', 'icon' => '⛽', 'cost' => 10000, 'type' => 'global', 'value' => 0.1, 'text' => 'All buildings mine 10% more.'],
    'autoAim' => ['name' => 'Auto-Aim Laser', 'icon' => '🎯', 'cost' => 50000, 'type' => 'clickPct', 'value' => 0.01, 'text' => 'Each click also mines 1% of your ore per second.'],
    'bulk' => ['name' => 'Bulk Buying', 'icon' => '🛒', 'cost' => 50000, 'type' => 'bulk', 'text' => 'Unlocks the ×10 and ×100 buttons, to buy buildings ten or a hundred at a time.'],
    'quantum' => ['name' => 'Quantum Hammer', 'icon' => '🔨', 'cost' => 500000, 'type' => 'clickPct', 'value' => 0.02, 'text' => 'Each click mines another 2% of your ore per second.'],
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
    'supernova' => ['name' => 'Supernova Punch', 'icon' => '👊', 'cost' => 5e8, 'type' => 'clickPct', 'value' => 0.05, 'text' => 'Each click mines another 5% of your ore per second.'],
    'autoClicker' => ['name' => 'Auto-Clicker Bot', 'icon' => '🤖', 'cost' => 1e9, 'type' => 'auto', 'value' => 5, 'text' => 'Clicks the asteroid 5 times a second for you.'],
    'warpTech' => ['name' => 'Warp Drive Tech', 'icon' => '🌀', 'cost' => 1e9, 'type' => 'global', 'value' => 1, 'text' => 'All buildings mine twice as much.'],
    'outposts' => ['name' => 'Moon Outposts', 'icon' => '🔗', 'cost' => 3.3e9, 'type' => 'synergy', 'building' => 'base', 'source' => 'excavator', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['base' => 1, 'excavator' => 1]], 'text' => 'Asteroid Bases mine 1% more for every Moon Excavator you own.'],
    'luckyStars' => ['name' => 'Lucky Stars', 'icon' => '💎', 'cost' => 7.77e9, 'type' => 'comet', 'effect' => 'diamond', 'need' => ['comets' => 27], 'text' => 'Now and then a Diamond Comet shows up instead: 777 times the production for 7 seconds.'],
    'slingshot' => ['name' => 'Gravity Slingshot', 'icon' => '🔗', 'cost' => 7.5e11, 'type' => 'synergy', 'building' => 'cracker', 'source' => 'siphon', 'per' => 0.01, 'every' => 1,
        'need' => ['owned' => ['cracker' => 1, 'siphon' => 1]], 'text' => 'Planet Crackers mine 1% more for every Black Hole Siphon you own.'],
    'monopoly' => ['name' => 'Galactic Monopoly', 'icon' => '👑', 'cost' => 1e12, 'type' => 'global', 'value' => 2, 'text' => 'All buildings mine three times as much.'],
];
const HBHUB_MINER_CLICK_TYPES = ['click', 'clickPct', 'crit', 'auto']; // kept by Persistent Memory

// Bought with Dark Matter, kept forever
const HBHUB_MINER_DARK_UPGRADES = [
    'headStart' => ['name' => 'Head Start', 'icon' => '🛸', 'cost' => 5, 'text' => 'Start every run with ' . HBHUB_MINER_HEAD_START . ' Mining Drones.'],
    'memory' => ['name' => 'Persistent Memory', 'icon' => '🧠', 'cost' => 25, 'text' => 'Keep your click upgrades when you Warp Jump.'],
    'darkEngines' => ['name' => 'Dark Engines', 'icon' => '⚫', 'cost' => 50, 'text' => 'All buildings mine 50% more.'],
    'timeDilation' => ['name' => 'Time Dilation', 'icon' => '⏳', 'cost' => 100, 'text' => 'Earn twice as much while you\'re away.'],
    'physics' => ['name' => 'Cheaper Physics', 'icon' => '📉', 'cost' => 250, 'text' => 'Buildings get 14% more expensive with every one you buy, instead of 15%.'],
];

// Achievements: done once 'stat' reaches 'at'. The stats are in hbHubMinerStat().
const HBHUB_MINER_ACHIEVEMENTS = [
    'click1' => ['name' => 'Rock Bottom', 'text' => 'Click the asteroid for the first time.', 'stat' => 'clicks', 'at' => 1],
    'click100' => ['name' => 'Tapping Away', 'text' => 'Click the asteroid 100 times.', 'stat' => 'clicks', 'at' => 100],
    'click1000' => ['name' => 'Click 1,000 Times', 'text' => 'Click the asteroid 1,000 times.', 'stat' => 'clicks', 'at' => 1000],
    'click10000' => ['name' => 'Finger of Steel', 'text' => 'Click the asteroid 10,000 times.', 'stat' => 'clicks', 'at' => 10000],
    'crit1' => ['name' => 'Critical Hit', 'text' => 'Land a critical click.', 'stat' => 'crits', 'at' => 1],
    'ore1k' => ['name' => 'Pocket Change', 'text' => 'Mine 1,000 ore in total.', 'stat' => 'allOre', 'at' => 1e3],
    'ore1m' => ['name' => '1 Million Ore', 'text' => 'Mine 1 million ore in total.', 'stat' => 'allOre', 'at' => 1e6],
    'ore1b' => ['name' => 'Rock Star', 'text' => 'Mine 1 billion ore in total.', 'stat' => 'allOre', 'at' => 1e9],
    'ore1t' => ['name' => 'Trillion Tonnes', 'text' => 'Mine 1 trillion ore in total.', 'stat' => 'allOre', 'at' => 1e12],
    'ore1qa' => ['name' => 'Galactic Hoarder', 'text' => 'Mine 1 quadrillion ore in total.', 'stat' => 'allOre', 'at' => 1e15],
    'ore1qi' => ['name' => 'Out of This World', 'text' => 'Mine 1 quintillion ore in total.', 'stat' => 'allOre', 'at' => 1e18],
    'rate10' => ['name' => 'Side Hustle', 'text' => 'Mine 10 ore per second.', 'stat' => 'rate', 'at' => 10],
    'rate1k' => ['name' => 'Small Business', 'text' => 'Mine 1,000 ore per second.', 'stat' => 'rate', 'at' => 1e3],
    'rate1m' => ['name' => 'Mega Corp', 'text' => 'Mine 1 million ore per second.', 'stat' => 'rate', 'at' => 1e6],
    'rate1b' => ['name' => 'Ore Tsunami', 'text' => 'Mine 1 billion ore per second.', 'stat' => 'rate', 'at' => 1e9],
    'pickaxe1' => ['name' => 'Hired Help', 'text' => 'Hire a Space Pickaxe Guy.', 'stat' => 'owned:pickaxe', 'at' => 1],
    'drone1' => ['name' => 'First Drone', 'text' => 'Buy a Mining Drone.', 'stat' => 'owned:drone', 'at' => 1],
    'pickaxe100' => ['name' => 'Pickaxe Army', 'text' => 'Have 100 Space Pickaxe Guys.', 'stat' => 'owned:pickaxe', 'at' => 100],
    'drone100' => ['name' => 'The Swarm', 'text' => 'Have 100 Mining Drones.', 'stat' => 'owned:drone', 'at' => 100],
    'siphon1' => ['name' => 'Own a Black Hole', 'text' => 'Buy a Black Hole Siphon.', 'stat' => 'owned:siphon', 'at' => 1],
    'all1' => ['name' => 'Full House', 'text' => 'Have one of every building.', 'stat' => 'ownedAll', 'at' => 1],
    'all25' => ['name' => 'Collector', 'text' => 'Have 25 of every building.', 'stat' => 'ownedAll', 'at' => 25],
    'buildings100' => ['name' => 'Industrial Revolution', 'text' => 'Have 100 buildings.', 'stat' => 'buildings', 'at' => 100],
    'buildings500' => ['name' => 'Megastructure', 'text' => 'Have 500 buildings.', 'stat' => 'buildings', 'at' => 500],
    'upgrades10' => ['name' => 'Tinkerer', 'text' => 'Have 10 upgrades.', 'stat' => 'upgrades', 'at' => 10],
    'upgrades40' => ['name' => 'Mad Scientist', 'text' => 'Have 40 upgrades.', 'stat' => 'upgrades', 'at' => 40],
    'comet1' => ['name' => 'Shooting Star', 'text' => 'Catch a Golden Comet.', 'stat' => 'comets', 'at' => 1],
    'comet10' => ['name' => 'Comet Chaser', 'text' => 'Catch 10 comets.', 'stat' => 'comets', 'at' => 10],
    'comet50' => ['name' => 'Halley\'s Hero', 'text' => 'Catch 50 comets.', 'stat' => 'comets', 'at' => 50],
    'diamond1' => ['name' => 'Shine Bright', 'text' => 'Catch a Diamond Comet.', 'stat' => 'diamonds', 'at' => 1],
    'warp1' => ['name' => 'Warp Speed', 'text' => 'Warp Jump to a new galaxy.', 'stat' => 'warps', 'at' => 1],
    'warp5' => ['name' => 'Frequent Flyer', 'text' => 'Warp Jump 5 times.', 'stat' => 'warps', 'at' => 5],
    'warp25' => ['name' => 'Galaxy Hopper', 'text' => 'Warp Jump 25 times.', 'stat' => 'warps', 'at' => 25],
];

/**
 * Every upgrade bought with ore, by id: the buildings' own three each (type 'building', ids like drone1 to drone3),
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
            $upgrades[$buildingId . ($index + 1)] = [
                'name' => $name,
                'icon' => $building['icon'],
                'cost' => $building['cost'] * HBHUB_MINER_BUILDING_UPGRADE_COST[$index],
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
        'achievements' => HBHUB_MINER_ACHIEVEMENTS,
        'comets' => HBHUB_MINER_COMETS,
        'clickTypes' => HBHUB_MINER_CLICK_TYPES,
        'saveSeconds' => HBHUB_MINER_SAVE_SECONDS,
        'onlineMs' => HBHUB_MINER_ONLINE_MS,
        'offlineRate' => HBHUB_MINER_OFFLINE_RATE,
        'clicksPerSecond' => HBHUB_MINER_CLICKS_PER_SECOND,
        'steadyMs' => HBHUB_MINER_STEADY_MS,
        'steadyToleranceMs' => HBHUB_MINER_STEADY_TOLERANCE_MS,
        'steadyValue' => HBHUB_MINER_STEADY_VALUE,
        'maxActions' => HBHUB_MINER_MAX_ACTIONS,
        'costGrowth' => HBHUB_MINER_COST_GROWTH,
        'costGrowthCheap' => HBHUB_MINER_COST_GROWTH_CHEAP,
        'warpOre' => HBHUB_MINER_WARP_ORE,
        'darkUnit' => HBHUB_MINER_DARK_UNIT,
        'darkBonus' => HBHUB_MINER_DARK_BONUS,
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
 * - ore: to spend. runOre: mined this run (since the last Warp Jump). allOre: mined ever. clickOre: mined by clicking.
 * - buildings: id => how many. upgrades: the ids bought this run. dark: Dark Matter to spend. darkEarned: ever earned
 *   (the production bonus). darkUpgrades: bought with it. warps: Warp Jumps made.
 * - clicks, crits, comets (caught), diamonds (Diamond Comets caught): counted ever.
 * - steady: the pace of the clicks, to spot auto-clickers (hbHubMinerSteadyClick()): last (the last click's time),
 *   gap (ms between the last two clicks) and since (when clicking at that pace began).
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
        'allOre' => 0.0,
        'clickOre' => 0.0,
        'buildings' => array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0),
        'upgrades' => [],
        'dark' => 0,
        'darkEarned' => 0,
        'darkUpgrades' => [],
        'warps' => 0,
        'clicks' => 0,
        'crits' => 0,
        'comets' => 0,
        'diamonds' => 0,
        'steady' => ['last' => 0, 'gap' => 0, 'since' => 0],
        'comet' => ['n' => 0, 'at' => $now],
        'buffs' => [],
        'achievements' => [],
    ];
    $state['comet']['at'] = $now + hbHubMinerCometWait($state, 0);
    return $state;
}

/**
 * A save from the database, with anything an older version didn't have filled in, and anything unknown taken out.
 */
function hbHubMinerNormalize(array $saved, int $now): array
{
    $state = array_replace(hbHubMinerNewState($now), $saved);
    $state['buildings'] = array_replace(array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0),
        array_intersect_key($state['buildings'], HBHUB_MINER_BUILDINGS));
    $state['upgrades'] = array_values(array_intersect($state['upgrades'], array_keys(hbHubMinerUpgrades())));
    $state['darkUpgrades'] = array_values(array_intersect($state['darkUpgrades'], array_keys(HBHUB_MINER_DARK_UPGRADES)));
    foreach (['ore', 'runOre', 'allOre', 'clickOre'] as $key) {
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

/**
 * What the next $amount of a building cost together, rounded up: each costs HBHUB_MINER_COST_GROWTH times the one before.
 */
function hbHubMinerBuildingCost(array $state, string $buildingId, int $amount): float
{
    $growth = hbHubMinerHasDark($state, 'physics') ? HBHUB_MINER_COST_GROWTH_CHEAP : HBHUB_MINER_COST_GROWTH;
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
 * The ore per second from each building (id => all of them together), with upgrades and Dark Matter but without comets.
 */
function hbHubMinerBuildingRates(array $state): array
{
    $upgrades = hbHubMinerUpgrades();
    $multipliers = array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 1.0);
    $global = 1 + HBHUB_MINER_DARK_BONUS * $state['darkEarned'];
    if (hbHubMinerHasDark($state, 'darkEngines')) {
        $global *= 1.5;
    }
    foreach ($state['upgrades'] as $upgradeId) {
        $upgrade = $upgrades[$upgradeId];
        if ($upgrade['type'] === 'building') {
            $multipliers[$upgrade['building']] *= 2;
        } elseif ($upgrade['type'] === 'synergy') {
            $multipliers[$upgrade['building']] *= 1 + $upgrade['per'] * floor($state['buildings'][$upgrade['source']] / $upgrade['every']);
        } elseif ($upgrade['type'] === 'global') {
            $global *= 1 + $upgrade['value'];
        }
    }

    $rates = [];
    foreach (HBHUB_MINER_BUILDINGS as $buildingId => $building) {
        $rates[$buildingId] = $building['rate'] * $state['buildings'][$buildingId] * $multipliers[$buildingId] * $global;
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
 * What one click mines at $time, before a critical click: 1, doubled by each click upgrade, plus the clickPct
 * upgrades' share of the buildings' production, all times a Click Storm.
 */
function hbHubMinerClickValue(array $state, int $time): float
{
    $upgrades = hbHubMinerUpgrades();
    $value = 1.0;
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
 * All the ore per second at $time: the buildings (times a production comet) and the Auto-Clicker Bot.
 * $time PHP_INT_MAX gives the steady rate, without comets, as the leaderboard shows it.
 */
function hbHubMinerRate(array $state, int $time): float
{
    $rate = array_sum(hbHubMinerBuildingRates($state)) * hbHubMinerBuff($state, 'production', $time);
    if (hbHubMinerHas($state, 'autoClicker')) {
        $rate += HBHUB_MINER_OTHER_UPGRADES['autoClicker']['value'] * hbHubMinerClickValue($state, $time);
    }
    return $rate;
}

function hbHubMinerEarn(array &$state, float $ore): void
{
    $state['ore'] += $ore;
    $state['runOre'] += $ore;
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
 * The Dark Matter all the ore ever mined is worth together: the cube root of how many HBHUB_MINER_DARK_UNITs it is
 * (so 1 for a billion, 10 for a trillion, 100 for a quadrillion). A Warp Jump gives what that's gone up by since the
 * last one. A square root made every jump double the one before, until the numbers ran away.
 */
function hbHubMinerDarkTotal(float $allOre): int
{
    // ** (1 / 3) is a hair off (1000 gives 9.999...), so it's put right with whole numbers, which are exact
    $units = $allOre / HBHUB_MINER_DARK_UNIT;
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
 */
function hbHubMinerWarpGain(array $state): int
{
    return $state['runOre'] >= HBHUB_MINER_WARP_ORE ? max(0, hbHubMinerDarkTotal($state['allOre']) - $state['darkEarned']) : 0;
}

/**
 * Whether a click at $time (Unix ms) comes in like clockwork: each gap since the click before is the same as the gap
 * before that (give or take HBHUB_MINER_STEADY_TOLERANCE_MS), and has been for HBHUB_MINER_STEADY_MS. Nobody's finger
 * keeps a beat that well, so that's an auto-clicker (or a held-down key), and the click only mines
 * HBHUB_MINER_STEADY_VALUE. The pace is kept in the save's 'steady', so it carries on from one save to the next, and a
 * single click off the beat starts it over.
 */
function hbHubMinerSteadyClick(array &$state, int $time): bool
{
    $steady = &$state['steady'];
    $time = max($time, (int) $steady['last']);
    $gap = $time - (int) $steady['last'];
    if (abs($gap - (int) $steady['gap']) > HBHUB_MINER_STEADY_TOLERANCE_MS) {
        $steady['since'] = $time; // a new pace, starting with this click
    }
    $steady['gap'] = $gap;
    $steady['last'] = $time;
    return $time - $steady['since'] >= HBHUB_MINER_STEADY_MS;
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
        if (hbHubMinerSteadyClick($state, $state['tick'] + $offset)) {
            $ore *= HBHUB_MINER_STEADY_VALUE;
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
            'multiplier' => $comet['multiplier'],
            'until' => $time + $comet['ms'] * (hbHubMinerHas($state, 'tail') ? 2 : 1),
        ];
    } else {
        $production = array_sum(hbHubMinerBuildingRates($state)) * hbHubMinerBuff($state, 'production', $time);
        hbHubMinerEarn($state, min($state['ore'] * HBHUB_MINER_LUCKY_SHARE, $production * HBHUB_MINER_LUCKY_SECONDS) + 13);
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
 * Warp Jump: everything of this run starts over (ore, buildings, upgrades but the click ones with Persistent Memory),
 * for the Dark Matter it's worth. Returns false when it can't be done yet.
 */
function hbHubMinerWarp(array &$state): bool
{
    $gain = hbHubMinerWarpGain($state);
    if ($gain < 1) {
        return false;
    }

    $upgrades = hbHubMinerUpgrades();
    $keepClicks = hbHubMinerHasDark($state, 'memory');
    $state['upgrades'] = array_values(array_filter($state['upgrades'], static fn (string $id): bool =>
        $keepClicks && in_array($upgrades[$id]['type'], HBHUB_MINER_CLICK_TYPES, true)));
    $state['buildings'] = array_fill_keys(array_keys(HBHUB_MINER_BUILDINGS), 0);
    if (hbHubMinerHasDark($state, 'headStart')) {
        $state['buildings']['drone'] = HBHUB_MINER_HEAD_START;
    }
    $state['ore'] = 0.0;
    $state['runOre'] = 0.0;
    $state['buffs'] = [];
    $state['dark'] += $gain;
    $state['darkEarned'] += $gain;
    $state['warps']++;
    $state['runStarted'] = $state['tick'];
    return true;
}

/**
 * One thing the player did, at the game's time. Returns whether it counted.
 * - ['type' => 'click', 'count' => n, 'offsets' => [ms after 'at' of each click]]: see hbHubMinerSync() for the limit.
 * - ['type' => 'buy', 'building' => id, 'amount' => 1, 10 or 100] (10 and 100 need Bulk Buying).
 * - ['type' => 'upgrade', 'id' => id], ['type' => 'dark', 'id' => id] (a Dark Matter upgrade).
 * - ['type' => 'comet'], ['type' => 'warp'].
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

    if ($type === 'comet') {
        return hbHubMinerCatchComet($state) !== null;
    }

    if ($type === 'warp') {
        return hbHubMinerWarp($state);
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
        'allOre', 'clicks', 'crits', 'comets', 'diamonds', 'warps' => (float) $state[$stat],
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
                saveDarkMatter = ?, saveWarps = ?, saveClicks = ?, saveAchievements = ?, saveUpdatedTimestamp = UTC_TIMESTAMP()
            WHERE userId = ?')
            ->execute([hbHubMinerEncode($state), $tab, $state['allOre'], hbHubMinerRate($state, PHP_INT_MAX), $state['darkEarned'],
                $state['warps'], $state['clicks'], count($state['achievements']), $userId]);
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
