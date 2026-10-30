// Ore Mining Inc. (games/spaceOreMiner/): runs the clicker game in the browser and saves it to the server.
// The page gives the game's data and numbers in data-config (hbHubMinerConfig() in
// assets/php/games/spaceOreMiner/miner.php: buildings, upgrades, darkUpgrades, antiUpgrades, achievements, comets and
// the numbers)
// a random id for this tab in data-tab, and where the drawn icons are in data-icons (see iconBox()).The save itself comes from the first save, which also claims the game
// for this tab (a game open in another tab then gets 409 "otherTab" and stops).
// The rules in "The game" below are a copy of miner.php's, function for function, because the server plays every
// action again to check it: each click, purchase, comet, Warp Jump and Big Crunch is done here right away (so it's
// smooth) and also kept in a list with its time, which goes to /games/ore-mining-inc/save every 30 seconds, when the tab is hidden and
// when it's closed. The server answers with the save as it worked it out, and the game carries on from that, with
// whatever was done while the save was on its way done again on top.
// Times are the server's Unix ms (the difference to this computer's clock is measured on every save).
// The page has six views (Ore, Dark Matter, Antimatter, Strange Matter, Engine, Anomalies, in data-miner-view): the menu's links switch
// between them here, with history.pushState, so the game keeps running instead of the page loading again. The Strange
// Matter view has the skill tree, drawn here from the skills (see renderTree()). The Engine view is the Cosmic Engine
// the whole site builds together (see "The Cosmic Engine"): every save's answer says how far it is. The Anomalies view has
// the challenge runs (see "Anomalies"): while the player is in one, its game is the one played (state), inside the
// save (saveState), and a banner over every view says so. Stats, achievements and how to
// play are popups (data-miner-popup), opened by the buttons by the title (data-miner-open).
// What hasn't been saved yet is also kept in the browser, so a page that crashes or freezes doesn't lose it: the next
// one sends it with its first save. A watchdog worker (data-watchdog, spaceOreMinerWatchdog.js) notices when the page
// freezes, and the next time the game is opened after one, it asks whether it may send a crash report. See "Crash
// recovery" and "Crash reports" near the end.
//
// The guest version at /play/ore-mining-inc (data-guest, see assets/php/guestGames/guestGames.php) plays the same, but
// saves to /play/ore-mining-inc/save (data-save), which keeps nothing: each answer has the game as a sealed token, kept
// in localStorage (GUEST_KEY, with the tab playing it) and sent with the next save. A guest can't send crash reports,
// so any crashes found are just cleared. On the members' page, a guest token found in the browser goes with the first
// save, to become the account's save if it has none yet (that's where logging in or signing up from /play leads).
(() => {
    const root = document.querySelector('[data-miner]');
    if (!root) return;

    const SAVE_URL = root.dataset.save;
    const IS_GUEST = 'guest' in root.dataset;
    const GUEST_KEY = 'hbHubGuestMiner'; // a guest's game: {tab: the tab playing it, token}

    const CONFIG = JSON.parse(root.dataset.config);
    const TAB = root.dataset.tab;
    const ICONS = root.dataset.icons; // the drawn icons, see iconBox()
    const BUILDINGS = CONFIG.buildings;
    const BUILDING_IDS = Object.keys(BUILDINGS);
    const UPGRADES = CONFIG.upgrades; // cheapest first
    const DARK_UPGRADES = CONFIG.darkUpgrades;
    const ANTI_UPGRADES = CONFIG.antiUpgrades;
    const SKILLS = CONFIG.skills;
    const ACHIEVEMENTS = CONFIG.achievements;
    const COMETS = CONFIG.comets;
    const SALT_CRIT = 1; // HBHUB_MINER_SALT_* in miner.php
    const SALT_COMET_WAIT = 2;
    const SALT_COMET_KIND = 3;
    const SALT_DIAMOND = 4;
    const SALT_LUCKY = 5;
    const SALT_SHIFT = 6;
    const SALT_ANOMALY = 7;
    const SALT_WEEKLY = 8;
    const ENGINE_STAGES = CONFIG.engineStages; // stage 1 first
    const SHIFTS = CONFIG.shifts;
    const ANOMALIES = CONFIG.anomalies;
    const ANOMALY_IDS = Object.keys(ANOMALIES);
    const ANOMALY_TIERS = CONFIG.anomalyTiers; // by tier number
    const SKINS = CONFIG.skins;

    // ----- The game: the same as miner.php. s is the save (hbHubMinerNewState() there has its shape).

    // A number from 0 up to 1, always the same for the same seed, counter and salt (hbHubMinerRandom())
    function random(seed, counter, salt) {
        let hash = (seed ^ Math.imul(counter >>> 0, 0x9E3779B1) ^ Math.imul(salt, 0x85EBCA77)) >>> 0;
        hash ^= hash >>> 16;
        hash = Math.imul(hash, 0x7FEB352D) >>> 0;
        hash ^= hash >>> 15;
        hash = Math.imul(hash, 0x846CA68B) >>> 0;
        hash ^= hash >>> 16;
        return (hash >>> 0) / 4294967296;
    }

    const has = (s, id) => s.upgrades.includes(id);
    const hasDark = (s, id) => s.darkUpgrades.includes(id);
    const hasAnti = (s, id) => s.antiUpgrades.includes(id);
    const hasSkill = (s, id) => s.skills.includes(id);
    const mod = (s, id) => s.mods.includes(id); // an Anomaly's game with its rules (hbHubMinerMod())
    const sum =(values) => values.reduce((total, value) => total + value, 0);

    // Whether a skill can be learned now, Strange Matter aside (hbHubMinerSkillOpen())
    const skillOpen = (s, id) => !hasSkill(s, id) && SKILLS[id].after.every((afterId) => hasSkill(s, afterId));

    // What all the skills learned do together (hbHubMinerSkillEffects()), worked out once per set of skills: kept with
    // the skills list it's for, which the copies of a save made below ({...s}) share
    const skillCache = new WeakMap();
    function skillEffects(s) {
        const cached = skillCache.get(s.skills);
        if (cached && cached.count === s.skills.length) return cached.effects;
        const effects = { ...CONFIG.skillNeutral, buildings: {}, start: {}, keep: [] };
        for (const id of s.skills) {
            const skill = SKILLS[id];
            const type = skill.type;
            if (type === 'buildings') {
                for (const buildingId of skill.buildings) effects.buildings[buildingId] = (effects.buildings[buildingId] ?? 1) * skill.times;
            } else if (type === 'start') {
                for (const buildingId of skill.buildings) effects.start[buildingId] = Math.max(effects.start[buildingId] ?? 0, skill.count);
            } else if (type === 'keep') {
                effects.keep = effects.keep.concat(skill.types);
            } else if (skill.times !== undefined) {
                effects[type] *= skill.times;
            } else if (skill.value !== undefined) {
                effects[type] += skill.value;
            } else {
                effects[type] = true;
            }
        }
        skillCache.set(s.skills, { count: s.skills.length, effects });
        return effects;
    }

    // What the perks of the Cosmic Engine's stages done so far do together (hbHubMinerEngineEffects()), by how many
    const engineCache = new Map();
    function engineEffects(s) {
        const done = Math.min(s.engineStage ?? 0, ENGINE_STAGES.length);
        if (engineCache.has(done)) return engineCache.get(done);
        const effects = { ...CONFIG.engineNeutral };
        for (const stage of ENGINE_STAGES.slice(0, done)) {
            const perk = stage.perk;
            if (perk.type === 'offline') effects.offline = Math.max(effects.offline, perk.value);
            else if (perk.times !== undefined) effects[perk.type] *= perk.times;
            else if (perk.value !== undefined) effects[perk.type] += perk.value;
        }
        engineCache.set(done, effects);
        return effects;
    }

    // What Ignition does to production at the game's time (hbHubMinerIgnition())
    const ignition = (s) => (s.engineIgnited > 0 && s.tick >= s.engineIgnited && s.tick < s.engineIgnited + CONFIG.ignitionMs ? CONFIG.ignitionTimes : 1);

    // What a part given from ore is worth now, in quarter parts (hbHubMinerOreQuarters())
    const oreQuarters = (s) => CONFIG.engineOreShare[ENGINE_STAGES[Math.min(s.engineStage ?? 0, ENGINE_STAGES.length - 1)].section];

    // Gives gift to the Engine: into given, and counted in engineGiven (hbHubMinerGive())
    function give(s, given, gift) {
        given.push(gift);
        s.engineGiven = (s.engineGiven ?? 0) + gift[1];
    }

    // Whether the player can give the Engine parts right now (hbHubMinerCanGive())
    const canGive = (s) => s.engineOpen === true && s.warps >= 1;

    // The daily counts start over on a new UTC day (hbHubMinerEngineDay())
    function engineDay(s) {
        const day = Math.floor(s.tick / CONFIG.dayMs);
        if (day !== s.engineDay) {
            s.engineDay = day;
            s.donatedToday = 0;
            s.shifts = new Array(CONFIG.shiftCount).fill(0);
        }
    }

    // The day's Engine Shifts, the same for everyone (hbHubMinerShiftTasks())
    const shiftCache = new Map();
    function shiftTasks(day) {
        if (shiftCache.has(day)) return shiftCache.get(day);
        const left = Object.keys(SHIFTS);
        const tasks = [];
        for (let i = 0; i < CONFIG.shiftCount; i++) {
            const index = Math.floor(random(0, day * CONFIG.shiftCount + i, SALT_SHIFT) * left.length);
            tasks.push(left.splice(index, 1)[0]);
        }
        shiftCache.set(day, tasks);
        return tasks;
    }

    // Counts amount towards today's Shift task, and puts a Shift just done in given (hbHubMinerShift()). given is null
    // for guests, who can't give parts, and then nothing is counted.
    function shift(s, task, amount, given) {
        if (given === null || !canGive(s)) return;
        engineDay(s);
        const tasks = shiftTasks(s.engineDay);
        const index = tasks.indexOf(task);
        const goal = SHIFTS[task].goal;
        if (index < 0 || s.shifts[index] >= goal) return;
        s.shifts[index] = Math.min(goal, s.shifts[index] + amount);
        if (s.shifts[index] < goal) return;
        const done = tasks.filter((id, i) => s.shifts[i] >= SHIFTS[id].goal).length;
        if (done === CONFIG.shiftCount) s.shiftDays = (s.shiftDays ?? 0) + 1;
        give(s, given, [CONFIG.sourceShift, done === CONFIG.shiftCount
            ? CONFIG.shiftPartsAll * CONFIG.shiftCount - CONFIG.shiftParts * (CONFIG.shiftCount - 1) : CONFIG.shiftParts]);
    }

    // The ore the next parts parts from ore cost together today (hbHubMinerOreDonationCost())
    function oreDonationCost(s, parts) {
        let part = CONFIG.engineOrePart;
        for (let i = 0; i < s.donatedToday; i++) part *= 10;
        let cost = 0;
        for (let i = 0; i < parts; i++) {
            cost += part;
            part *= 10;
        }
        return cost;
    }

    const darkBonus = (s) => (hasDark(s, 'reactor') ? CONFIG.darkBonusReactor : CONFIG.darkBonus) + skillEffects(s).darkBonus;
    const antiMultiplier = (s) => 1 + (CONFIG.antiBonus + skillEffects(s).antiBonus) * s.antimatterEarned;
    const strangeMultiplier = (s) => 1 + CONFIG.strangeBonus * s.strangeEarned;
    const cometPower = (s) => (hasAnti(s, 'antiComets') ? 2 : 1) * skillEffects(s).cometPower;
    const upgradeCost = (s, id) => UPGRADES[id].cost * skillEffects(s).upgradeCost * engineEffects(s).upgradeCost;

    // How much more each of a building costs than the one before (hbHubMinerCostGrowth())
    const costGrowth = (s, id) => (mod(s, 'inflation') ? CONFIG.inflationGrowth : BUILDINGS[id].growth ?? CONFIG.costGrowth)
        - (hasDark(s, 'physics') ? CONFIG.costGrowthPhysics : 0);

    // Whether amount more of a building can be bought, ore aside (hbHubMinerCanBuild()): not one the Unstable Vacuum
    // destroyed, not past minimalistMax in Minimalist, only the kind the run has in One Track
    function canBuild(s, id, amount) {
        if (s.destroyed.includes(id)) return false;
        if (mod(s, 'minimalist') && sum(Object.values(s.buildings)) + amount > CONFIG.minimalistMax) return false;
        if (mod(s, 'oneTrack') && BUILDING_IDS.some((other) => other !== id && s.buildings[other] > 0)) return false;
        return true;
    }

    // How long a run goes before each halving in Entropy, longer in The Gauntlet (hbHubMinerEntropyMs())
    const entropyMs = (s) => (mod(s, 'gauntlet') ? CONFIG.gauntletEntropyMs : CONFIG.entropyMs);
    const entropySteps = (s) => (mod(s, 'gauntlet') ? CONFIG.gauntletEntropySteps : CONFIG.entropySteps); // the most halvings

    // What Entropy leaves of production: halved for every whole entropyMs() of the run (hbHubMinerEntropy())
    function entropy(s) {
        if (!mod(s, 'entropy')) return 1;
        return 0.5 ** Math.min(entropySteps(s), Math.floor(Math.max(0, s.tick - s.runStarted) / entropyMs(s)));
    }

    function buildingCost(s, id, amount) {
        const growth = costGrowth(s, id);
        return Math.ceil(BUILDINGS[id].cost * skillEffects(s).cost * engineEffects(s).cost
            * growth ** s.buildings[id] * (growth ** amount - 1) / (growth - 1));
    }

    const canAfford = (s, cost) => cost <= s.ore * (1 + 1e-9) + 1e-9;

    // What the upgrades bought add up to, for buildingRates(), clickValue() and autoClicks(): one pass over them here
    // instead of one in each (miner.php does the same passes inside those three, in the same order, so the numbers come
    // out exactly the same). Auto Buy works it out once and tries each upgrade on top with ratePartsWith(), instead of
    // going through every upgrade bought again for each one it weighs up. Shape: {multipliers, crew (by building),
    // global (before the buildings owned count), click, clickShare, clicks}.
    function rateParts(s) {
        const effects = skillEffects(s);
        let global = (1 + darkBonus(s) * s.darkEarned) * antiMultiplier(s) * strangeMultiplier(s);
        if (hasDark(s, 'darkEngines')) global *= 1.5;
        if (hasAnti(s, 'annihilation')) global *= 2;
        global *= effects.production * (1 + effects.perSkill * s.skills.length) * (1 + effects.perWarp * s.flights)
            * (1 + effects.perCrunch * s.crunches) * engineEffects(s).production * ignition(s);
        global *= entropy(s);
        const parts = {
            multipliers: Object.fromEntries(BUILDING_IDS.map((id) => [id, 1])),
            crew: Object.fromEntries(BUILDING_IDS.map((id) => [id, 0])),
            global,
            click: hasDark(s, 'darkPickaxe') ? CONFIG.darkPickaxe : 1,
            clickShare: 0,
            clicks: 0,
        };
        for (const upgradeId of s.upgrades) addRatePart(s, parts, UPGRADES[upgradeId], effects);
        return parts;
    }

    // What a building or synergy upgrade multiplies its building's ore per second by
    function upgradeMultiplier(s, upgrade, effects) {
        return upgrade.type === 'building' ? 2 * effects.buildingUpgrade
            : 1 + upgrade.per * Math.floor(s.buildings[upgrade.source] / upgrade.every);
    }

    function addRatePart(s, parts, upgrade, effects) {
        if (upgrade.type === 'building' || upgrade.type === 'synergy') {
            parts.multipliers[upgrade.building] *= upgradeMultiplier(s, upgrade, effects);
        } else if (upgrade.type === 'crew') {
            parts.crew[upgrade.building] += upgrade.value * effects.crew;
        } else if (upgrade.type === 'global') {
            parts.global *= 1 + upgrade.value;
        } else if (upgrade.type === 'click') {
            parts.click *= upgrade.value;
        } else if (upgrade.type === 'clickPct') {
            parts.clickShare += upgrade.value;
        } else if (upgrade.type === 'auto') {
            parts.clicks += upgrade.value;
        }
    }

    // The parts with one more upgrade bought, as if it were last in s.upgrades (where buying puts it)
    function ratePartsWith(s, parts, upgradeId) {
        const more = { ...parts, multipliers: { ...parts.multipliers }, crew: { ...parts.crew } };
        addRatePart(s, more, UPGRADES[upgradeId], skillEffects(s));
        return more;
    }

    // The only part that depends on how many there are of each building is the synergy upgrades' multipliers. This is
    // which buildings' multipliers each building is counted for, as a Map {source => [building, ...]}; with more of a
    // building that isn't in it, the same parts still hold.
    function rateSynergies(s) {
        const synergies = new Map();
        for (const upgradeId of s.upgrades) {
            const upgrade = UPGRADES[upgradeId];
            if (upgrade.type !== 'synergy') continue;
            if (!synergies.has(upgrade.source)) synergies.set(upgrade.source, []);
            synergies.get(upgrade.source).push(upgrade.building);
        }
        return synergies;
    }

    // The parts for s, which has more of a building than the save the parts are from: the multipliers of buildings
    // (the ones rateSynergies() gives for it) are worked out again, in the same order rateParts() does
    function ratePartsRecounted(s, parts, buildings) {
        const effects = skillEffects(s);
        const multipliers = { ...parts.multipliers };
        for (const id of buildings) multipliers[id] = 1;
        for (const upgradeId of s.upgrades) {
            const upgrade = UPGRADES[upgradeId];
            if ((upgrade.type === 'building' || upgrade.type === 'synergy') && buildings.includes(upgrade.building)) {
                multipliers[upgrade.building] *= upgradeMultiplier(s, upgrade, effects);
            }
        }
        return { ...parts, multipliers };
    }

    // Calls each(id, ore per second) for every building, in order
    function eachBuildingRate(s, parts, each) {
        const effects = skillEffects(s);
        const owned = sum(Object.values(s.buildings));
        const global = parts.global * (1 + effects.owned * owned);
        for (const id of BUILDING_IDS) {
            each(id, (BUILDINGS[id].rate + parts.crew[id] * (owned - s.buildings[id])) * s.buildings[id] * parts.multipliers[id] * global * (effects.buildings[id] ?? 1));
        }
    }

    function buildingRates(s, parts = rateParts(s)) {
        const rates = {};
        eachBuildingRate(s, parts, (id, value) => {
            rates[id] = value;
        });
        return rates;
    }

    // All the buildings' ore per second added up, the same as sum(Object.values(buildingRates())) without the object
    function production(s, parts) {
        let total = 0;
        eachBuildingRate(s, parts, (id, value) => {
            total += value;
        });
        return total;
    }

    // All the comet effects running on 'production' or 'click', multiplied together (hbHubMinerBuff())
    function buff(s, kind, time) {
        let multiplier = 1;
        for (const running of Object.values(s.buffs)) {
            if (COMETS[running.kind].buff === kind && running.until > time) multiplier *= running.multiplier;
        }
        return multiplier;
    }

    function clickValue(s, time, parts = rateParts(s)) {
        if (mod(s, 'deadHands')) return 0;
        const effects = skillEffects(s);
        const value = parts.click * effects.click;
        const share = parts.clickShare + (effects.clickShare + (mod(s, 'handsOnly') ? CONFIG.handsShare : 0));
        const steady = share > 0 ? production(s, parts) * buff(s, 'production', time) : 0;
        return (value + share * steady) * buff(s, 'click', time) * engineEffects(s).click;
    }

    // How many times a second the auto upgrades and skills click, together (hbHubMinerAutoClicks())
    function autoClicks(s, parts = rateParts(s)) {
        return parts.clicks + skillEffects(s).auto;
    }

    // time Infinity: the steady rate, without comets. parts: rateParts(s), when it's already been worked out.
    function rate(s, time, parts = rateParts(s)) {
        let total = mod(s, 'handsOnly') ? 0 : production(s, parts) * buff(s, 'production', time); // only clicks mine in Hands Only
        const clicks = autoClicks(s, parts);
        if (clicks > 0) total += clicks * clickValue(s, time, parts);
        return total;
    }

    function earn(s, ore) {
        s.ore = Math.min(s.ore + ore, Number.MAX_VALUE);
        s.runOre = Math.min(s.runOre + ore, Number.MAX_VALUE);
        s.universeOre = Math.min(s.universeOre + ore, Number.MAX_VALUE);
        s.allOre = Math.min(s.allOre + ore, Number.MAX_VALUE);
    }

    function cometWait(s, n) {
        const [min, max] = CONFIG.cometWaitMs;
        const wait = min + random(s.seed, n, SALT_COMET_WAIT) * (max - min);
        return Math.floor((has(s, 'radar') ? wait / 2 : wait) * skillEffects(s).cometWait * engineEffects(s).cometWait);
    }

    const cometStay = (s) => Math.floor((has(s, 'magnet') ? CONFIG.cometStayMs * 2 : CONFIG.cometStayMs) * skillEffects(s).cometStay)
        + engineEffects(s).cometStay;

    // How long a comet's effect lasts, in ms (hbHubMinerCometLength())
    const cometLength = (s, kind) => Math.floor(COMETS[kind].ms * (has(s, 'tail') ? 2 : 1) * skillEffects(s).cometLength
        * (kind === 'diamond' ? 1 : engineEffects(s).cometLength));

    function cometKind(s) {
        const n = s.comet.n;
        let diamondChance = (has(s, 'luckyStars') ? CONFIG.diamondChance : 0) + skillEffects(s).diamond;
        if (diamondChance > 0) diamondChance += engineEffects(s).diamond; // only adds to a chance there is
        if (diamondChance > 0 &&random(s.seed, n, SALT_DIAMOND) < diamondChance) return 'diamond';
        const roll = random(s.seed, n, SALT_COMET_KIND);
        return roll < 0.45 ? 'frenzy' : (roll < 0.9 ? 'lucky' : 'storm');
    }

    function cometsPassed(s) {
        for (let skipped = 0; s.tick > s.comet.at + cometStay(s); skipped++) {
            const leftAt = s.comet.at + cometStay(s);
            s.comet.n++;
            s.comet.at = (skipped < 10000 ? leftAt : s.tick) + cometWait(s, s.comet.n);
        }
    }

    // given: see shift(). runOreGoal: stops at the first ms the ore this run reaches it (hbHubMinerAdvance())
    function advance(s, to, fullUntil = Infinity, given = null, runOreGoal = Infinity) {
        let offlineOre = 0;
        while (s.tick < to && s.runOre < runOreGoal) {
            let next = to;
            for (const running of Object.values(s.buffs)) {
                if (running.until > s.tick && running.until < next) next = running.until;
            }
            if (fullUntil > s.tick && fullUntil < next) next = fullUntil;
            const midnight = (Math.floor(s.tick / CONFIG.dayMs) + 1) * CONFIG.dayMs;
            if (midnight < next) next = midnight;
            const steps = Math.floor(Math.max(0, s.tick - s.runStarted) / entropyMs(s)); // Entropy's next halving
            if (mod(s, 'entropy') && steps < entropySteps(s)) {
                const halving = s.runStarted + (steps + 1) * entropyMs(s);
                if (halving < next) next = halving;
            }
            for (const edge of [s.engineIgnited, s.engineIgnited + CONFIG.ignitionMs]) { // Ignition starting or running out
                if (s.engineIgnited > 0 && edge > s.tick && edge < next) next = edge;
            }

            const perSecond = rate(s, s.tick);
            const share = s.tick >= fullUntil ? engineEffects(s).offline * (hasDark(s, 'timeDilation') ? 2 : 1) * skillEffects(s).offline : 1;
            let ore = perSecond * (next - s.tick) / 1000 * share;
            if (runOreGoal < Infinity && ore > 0 && s.runOre + ore >= runOreGoal) { // the goal is reached in this piece
                next = Math.min(next, s.tick + Math.max(1, Math.ceil((runOreGoal - s.runOre) / ore * (next - s.tick))));
                ore = perSecond * (next - s.tick) / 1000 * share;
            }
            if (s.tick >= fullUntil) {
                offlineOre += ore;
            } else {
                shift(s, 'playing', next - s.tick, given);
            }
            earn(s, ore);
            s.tick = next;
            for (const [kind, running] of Object.entries(s.buffs)) {
                if (running.until <= next) delete s.buffs[kind];
            }
        }
        cometsPassed(s);
        return offlineOre;
    }

    function upgradeUnlocked(s, upgrade) {
        if (mod(s, 'bareMetal')) return false;
        const need = upgrade.need;
        if (need.ore !== undefined && s.runOre < need.ore) return false;
        if (need.comets !== undefined && s.comets < need.comets) return false;
        if (need.upgrades && !need.upgrades.every((id) => has(s, id))) return false;
        for (const id in need.owned) { // plain loops, as the store asks this of every upgrade a few times a second
            if (!(s.buildings[id] >= need.owned[id])) return false;
        }
        return true;
    }

    function darkTotal(universeOre) {
        const units = universeOre / CONFIG.darkUnit;
        let total = Math.floor(units ** (1 / 3));
        if ((total + 1) ** 3 <= units) total++;
        if (total > 0 && total ** 3 > units) total--;
        return total;
    }

    function warpGain(s) {
        if (s.runOre < CONFIG.warpOre) return 0;
        const total = Math.floor(darkTotal(s.universeOre) * Math.sqrt(antiMultiplier(s)) * (hasAnti(s, 'converter') ? 2 : 1) * skillEffects(s).darkGain
            * engineEffects(s).darkGain);
        return Math.max(0, total - s.darkEarned);
    }

    function antiTotal(darkEarned) {
        let total = Math.floor(Math.sqrt(darkEarned / CONFIG.antiUnit));
        if ((total + 1) ** 2 * CONFIG.antiUnit <= darkEarned) total++;
        if (total > 0 && total ** 2 * CONFIG.antiUnit > darkEarned) total--;
        return total;
    }

    function crunchGain(s) {
        const effects = skillEffects(s);
        return s.darkEarned >= CONFIG.crunchDark * effects.crunchDark
            ? Math.floor(antiTotal(s.darkEarned) * effects.antiGain * engineEffects(s).antiGain) : 0;
    }

    // One for every step the Antimatter earned in this multiverse has passed: strangeUnit times each of strangeSteps
    // times a power of ten (hbHubMinerStrangeTotal()). nextStrange() is how much it takes for the next one.
    function strangeTotal(antimatterEarned) {
        let total = 0;
        let decade = CONFIG.strangeUnit;
        while (decade * 10 <= antimatterEarned && decade * 10 < Infinity) {
            decade *= 10;
            total += CONFIG.strangeSteps.length;
        }
        for (const step of CONFIG.strangeSteps) {
            if (decade * step <= antimatterEarned) total++;
        }
        return total;
    }

    function nextStrange(antimatterEarned) {
        let decade = CONFIG.strangeUnit;
        while (decade * 10 <= antimatterEarned && decade * 10 < Infinity) decade *= 10;
        const step = CONFIG.strangeSteps.find((at) => decade * at > antimatterEarned);
        return step === undefined ? decade * 10 : decade * step;
    }

    function ripGain(s) {
        if (s.antimatterEarned < CONFIG.ripAnti) return 0;
        return Math.max(0, Math.floor(strangeTotal(s.antimatterEarned) * skillEffects(s).strangeGain) - s.strangeEarned);
    }

    // For the hint: the first Antimatter earned in this multiverse at which a Big Rip would give more than it does now,
    // and what it would give then ({at, gain}), or null past all numbers. Only for showing, so it's not in the PHP.
    function nextRip(s) {
        const effects = skillEffects(s);
        const now = ripGain(s);
        let at = Math.max(s.antimatterEarned, CONFIG.ripAnti);
        for (let step = 0; step < 10000 && at < Infinity; step++) {
            const gain = Math.max(0, Math.floor(strangeTotal(at) * effects.strangeGain) - s.strangeEarned);
            if (gain > now) return { at, gain };
            at = nextStrange(at);
        }
        return null;
    }

    // auto: they look like an auto-clicker's (looksAuto()), so each only mines autoValue. Returns the ore mined and how
    // many of the clicks were critical.
    function click(s, count, auto = false) {
        const effects = skillEffects(s);
        const value = clickValue(s, s.tick) * (auto ? CONFIG.autoValue : 1);
        const canCrit = has(s, 'crit') || effects.crit > 0;
        const critChance = CONFIG.critChance + effects.crit;
        const critMultiplier = CONFIG.critMultiplier * effects.critPower;
        let mined = 0;
        let crits = 0;
        for (let i = 0; i < count; i++) {
            let ore = value;
            if (canCrit && random(s.seed, s.clicks, SALT_CRIT) < critChance) {
                ore *= critMultiplier;
                s.crits++;
                crits++;
            }
            s.clicks++;
            mined += ore;
        }
        s.clickOre += mined;
        earn(s, mined);
        return { mined, crits };
    }

    // Returns {kind, ore (what a lucky comet gave)}, or null when there wasn't one on screen
    function catchComet(s) {
        const time = s.tick;
        if (mod(s, 'darkSky') || time < s.comet.at || time > s.comet.at + cometStay(s)) return null;

        const kind = cometKind(s);
        const comet = COMETS[kind];
        let ore = 0;
        if (comet.buff !== null) {
            // The same comet's effect starts over and the others keep running alongside it (hbHubMinerCatchComet())
            for (const [key, running] of Object.entries(s.buffs)) {
                if (running.kind === kind) delete s.buffs[key];
            }
            s.buffs[kind] = { kind, multiplier: comet.multiplier * cometPower(s), until: time + cometLength(s, kind) };
        } else {
            const production = sum(Object.values(buildingRates(s))) * buff(s, 'production', time) * (mod(s, 'handsOnly') ? CONFIG.handsShare : 1);
            const [min, max] = CONFIG.luckySeconds;
            const seconds = min + random(s.seed, s.comet.n, SALT_LUCKY) * (max - min);
            ore = (production * seconds + 13)
                * (has(s, 'stardust') ? 2 : 1) * cometPower(s) * skillEffects(s).luckyOre;
            earn(s, ore);
        }
        s.comets++;
        if (kind === 'diamond') s.diamonds++;
        s.comet.n++;
        s.comet.at = time + cometWait(s, s.comet.n);
        return { kind, ore };
    }

    // The buildings a run starts with (hbHubMinerStartBuildings()): none in Minimalist and One Track, nor of the ones the
    // Unstable Vacuum destroyed
    function startBuildings(s) {
        if (mod(s, 'minimalist') || mod(s, 'oneTrack')) return Object.fromEntries(BUILDING_IDS.map((id) => [id, 0]));
        const start = Object.fromEntries(BUILDING_IDS.map((id) => [id, skillEffects(s).start[id] ?? 0]));
        if (hasDark(s, 'headStart')) start.drone = Math.max(start.drone, CONFIG.headStart);
        for (const id of s.destroyed) start[id] = 0;
        if (mod(s, 'gauntlet')) { // clicks mine nothing there
            for (const [id, count] of Object.entries(CONFIG.gauntletStart)) start[id] = Math.max(start[id], count);
        }
        return start;
    }

    // A new run, for a Warp Jump, Big Crunch or Big Rip (hbHubMinerNewRun())
    function newRun(s) {
        const effects = skillEffects(s);
        const kept = [...(hasDark(s, 'memory') ? CONFIG.clickTypes : []), ...(hasAnti(s, 'eternal') ? CONFIG.eternalTypes : []), ...effects.keep];
        s.upgrades = mod(s, 'bareMetal') ? [] : s.upgrades.filter((id) => kept.includes(UPGRADES[id].type));
        s.buildings = startBuildings(s);
        s.ore = Math.max(hasAnti(s, 'leftovers') ? CONFIG.leftoverOre : 0, effects.startOre);
        s.runOre = 0;
        s.buffs = {};
        s.runStarted = s.tick;
    }

    function warp(s) {
        const gain = warpGain(s);
        if (gain < 1 || mod(s, 'ironUniverse')) return false;

        if (mod(s, 'unstableVacuum')) { // the building there's most of goes for good (hbHubMinerWarp())
            let most = null;
            for (const id of BUILDING_IDS) {
                if (s.buildings[id] > 0 && (most === null || s.buildings[id] >= s.buildings[most])) most = id;
            }
            if (most !== null) s.destroyed.push(most);
        }
        newRun(s);
        if (gain >= s.darkEarned) s.flights++; // only these count for Frequent Flyer Miles (hbHubMinerWarp())
        s.dark += gain;
        s.darkEarned += gain;
        s.warps++;
        return gain;
    }

    function crunch(s) {
        const gain = crunchGain(s);
        if (gain < 1) return false;

        s.dark = 0;
        s.darkEarned = 0;
        if (!hasAnti(s, 'echo')) s.darkUpgrades = [];
        s.universeOre = 0;
        s.antimatter += gain;
        s.antimatterEarned += gain;
        s.crunches++;
        newRun(s);
        return gain;
    }

    function rip(s) {
        const gain = ripGain(s);
        if (gain < 1) return false;

        s.strange += gain;
        s.strangeEarned += gain;
        s.rips++;
        newMultiverse(s);
        return gain;
    }

    // What a Big Rip starts over, and an Anomaly starts with (hbHubMinerNewMultiverse())
    function newMultiverse(s) {
        if (!skillEffects(s).keepAnti) s.antiUpgrades = [];
        s.antimatter = 0;
        s.antimatterEarned = 0;
        if (!hasAnti(s, 'echo')) s.darkUpgrades = [];
        s.dark = 0;
        s.darkEarned = 0;
        s.universeOre = 0;
        newRun(s);
    }

    // ----- Anomalies (hbHubMinerAnomaly*() in miner.php). s.anomaly is the one the player is in: {mods, started, goal,
    // state (its own game)}, and s.anomalies the ones finished, by key: {done, best}.

    // The Anomalies in a key, or null when it isn't one: in their order, each once, and only stackable ones in a stack
    // (hbHubMinerAnomalyMods())
    function anomalyMods(key) {
        const mods = key.split('+');
        let last = -1;
        for (const id of mods) {
            const at = ANOMALY_IDS.indexOf(id);
            if (at <= last || (mods.length > 1 && ANOMALIES[id].tier > CONFIG.anomalyStackTier)) return null;
            last = at;
        }
        return mods;
    }
    const anomalyKey = (mods) => mods.join('+');
    // The rules an Anomaly's game has: each one, and its 'rules' (hbHubMinerAnomalyRules())
    const anomalyRules = (mods) => [...new Set(mods.flatMap((id) => [id, ...(ANOMALIES[id].rules ?? [])]))];
    const anomalyGoal = (mods) => Math.min(...mods.map((id) => ANOMALIES[id].goal));
    const anomalyStat = (mods) => ANOMALIES[mods[0]].stat ?? 'antimatterEarned';
    function anomalyParts(s, mods, isWeekly) {
        let parts = 0;
        for (const id of mods) parts += ANOMALIES[id].parts ?? ANOMALY_TIERS[ANOMALIES[id].tier].parts;
        return Math.floor(parts * CONFIG.anomalyStack ** (mods.length - 1) * (isWeekly ? CONFIG.anomalyWeekly : 1) * engineEffects(s).anomalyParts);
    }

    // The week a time is in, from Monday 00:00 UTC (hbHubMinerWeek()), and that week's Anomaly (hbHubMinerWeeklyMods())
    const weekOf = (time) => Math.floor((time + 3 * CONFIG.dayMs) / CONFIG.weekMs);
    const weeklyCache = new Map();
    function weeklyMods(week) {
        if (weeklyCache.has(week)) return weeklyCache.get(week);
        const left = ANOMALY_IDS.filter((id) => ANOMALIES[id].tier <= CONFIG.anomalyStackTier && !CONFIG.weeklyLeftOut.includes(id));
        const size = CONFIG.weeklySizes[Math.floor(random(0, week * 10, SALT_WEEKLY) * CONFIG.weeklySizes.length)];
        const picked = [];
        for (let i = 0; i < size; i++) picked.push(left.splice(Math.floor(random(0, week * 10 + i + 1, SALT_WEEKLY) * left.length), 1)[0]);
        const mods = ANOMALY_IDS.filter((id) => picked.includes(id));
        weeklyCache.set(week, mods);
        return mods;
    }

    // Whether the player has finished an Anomaly, alone or in a stack (hbHubMinerAnomalyDone())
    const anomalyDone = (s, id) => Object.keys(s.anomalies).some((key) => key.split('+').includes(id));

    // Whether the player's own game has opened Anomaly tier tier
    function anomalyTierOpen(s, tier) {
        const unlock = ANOMALY_TIERS[tier].unlock;
        return unlock === 'rip' ? s.rips >= 1 : unlock === 'theory' ? hasSkill(s, 'theory') : false;
    }

    // The game being played: the Anomaly's while the player is in one (hbHubMinerGame())
    const gameOf = (s) => s.anomaly?.state ?? s;

    function anomalyStart(s, mods) {
        if (s.anomaly || s.mods.length > 0 || !Array.isArray(mods) || mods.length === 0 || !mods.every((id) => typeof id === 'string')) return false;
        mods = anomalyMods(anomalyKey(mods));
        if (!mods || !mods.every((id) => anomalyTierOpen(s, ANOMALIES[id].tier))) return false;

        let game = structuredClone({ ...s, anomaly: null });
        game.anomalies = {};
        game.mods = anomalyRules(mods);
        game.destroyed = [];
        game.engineOpen = false;
        game.seed = Math.floor(random(s.seed, s.tick, SALT_ANOMALY) * 0x7FFFFFFE) + 1;
        game.started = s.tick;
        if (ANOMALY_TIERS[ANOMALIES[mods[0]].tier].fresh) game = { ...game, ...structuredClone(CONFIG.anomalyFresh) }; // Tier III: from nothing
        newMultiverse(game);
        game.comet = { n: 0, at: game.tick + cometWait(game, 0) };
        s.anomaly = { mods, started: s.tick, goal: anomalyGoal(mods), state: game };
        return true;
    }

    // Leaves the Anomaly at its game's time; the player's own game catches up as time away. Returns the ore it mined.
    function anomalyLeave(s) {
        const to = s.anomaly.state.tick;
        s.anomaly = null;
        return advance(s, to, s.tick);
    }

    // Done once its goal is reached: its time goes in s.anomalies, its parts in given, and the player leaves it
    // (hbHubMinerAnomalyCheck())
    function anomalyCheck(s, given) {
        const anomaly = s.anomaly;
        if (!anomaly || anomaly.state[anomalyStat(anomaly.mods)] < anomaly.goal) return;
        const key = anomalyKey(anomaly.mods);
        const ms = anomaly.state.tick - anomaly.started;
        const week = weekOf(anomaly.state.tick);
        const isWeekly = anomalyKey(weeklyMods(week)) === key;
        const record = s.anomalies[key] ?? null;
        let parts = anomalyParts(s, anomaly.mods, isWeekly);
        if (record !== null && ms >= record.best) parts = Math.floor(parts * CONFIG.anomalyRepeat);
        s.anomalies[key] = { done: (record?.done ?? 0) + 1, best: record !== null ? Math.min(record.best, ms) : ms };
        if (isWeekly) s.weeklies = (s.weeklies ?? 0) + 1;
        if (given !== null && s.warps >= 1) give(s, given, [CONFIG.sourceAnomaly, parts, key, ms, isWeekly ? week : null]);
        anomalyLeave(s);
    }

    // advance() for the game being played; Speed of Light is over at lightMs (hbHubMinerAdvanceGame())
    function advanceGame(s, to, fullUntil = Infinity, given = null) {
        if (!s.anomaly) return advance(s, to, fullUntil, given);
        const limit = s.anomaly.mods.includes('speedOfLight') ? s.anomaly.started + CONFIG.lightMs : Infinity;
        const runOreGoal = anomalyStat(s.anomaly.mods) === 'runOre' ? s.anomaly.goal : Infinity; // reached by time going by
        const offlineOre = advance(s.anomaly.state, Math.min(to, limit), fullUntil, null, runOreGoal);
        if (s.anomaly.state.runOre >= runOreGoal) {
            anomalyCheck(s, given); // done, and back in the player's own game
            return offlineOre + advance(s, to, fullUntil, given);
        }
        if (to < limit) return offlineOre;
        anomalyLeave(s); // out of time
        return offlineOre + advance(s, to, fullUntil, given);
    }

    // Returns false when it didn't count, otherwise something truthy (what click() and catchComet() return, the
    // Dark Matter from a warp, the Antimatter from a crunch, the Strange Matter from a rip). What's given to the Engine
    // goes in given as [source, parts] (and the key, for an Anomaly) (null for guests, who can't give anything), see
    // hbHubMinerApply(). In an Anomaly, the action is played in its game.
    function apply(s, action, given = null) {
        if (action.type === 'skin') { // the player's own, in an Anomaly too
            if (action.id !== null && !skinOpen(s, action.id)) return false;
            s.skin = action.id;
            return true;
        }
        if (s.anomaly && action.type !== 'anomalyStart' && action.type !== 'anomalyQuit') {
            if (['skill', 'rip', 'donate'].includes(action.type)) return false;
            const result = apply(s.anomaly.state, action, null);
            if (!result) return false;
            anomalyCheck(s, given);
            return result;
        }
        switch (action.type) {
            case 'click': {
                if (!(action.count >= 1)) return false;
                const result = click(s, action.count, action.auto === true);
                shift(s, 'clicks', action.count, given);
                return result;
            }
            case 'buy': {
                const { building, amount } = action;
                if (!BUILDINGS[building] || !Number.isInteger(amount) || amount < 1 || (amount > 1 && !has(s, 'bulk'))
                    || (![1, 10, 100].includes(amount) && !has(s, 'bulkMax')) || !canBuild(s, building, amount)) return false;
                const cost = buildingCost(s, building, amount);
                if (!canAfford(s, cost)) return false;
                s.ore = Math.max(0, s.ore - cost);
                s.buildings[building] += amount;
                shift(s, 'buildings', amount, given);
                return true;
            }
            case 'upgrade': {
                const upgrade = UPGRADES[action.id];
                if (!upgrade || has(s, action.id) || !upgradeUnlocked(s, upgrade) || !canAfford(s, upgradeCost(s, action.id))) return false;
                s.ore = Math.max(0, s.ore - upgradeCost(s, action.id));
                s.upgrades.push(action.id);
                shift(s, 'upgrades', 1, given);
                return true;
            }
            case 'dark': {
                const upgrade = DARK_UPGRADES[action.id];
                if (!upgrade || hasDark(s, action.id) || s.dark < upgrade.cost) return false;
                s.dark -= upgrade.cost;
                s.darkUpgrades.push(action.id);
                if (action.id === 'headStart') s.buildings.drone = Math.max(s.buildings.drone, startBuildings(s).drone);
                return true;
            }
            case 'anti': {
                const upgrade = ANTI_UPGRADES[action.id];
                if (!upgrade || hasAnti(s, action.id) || s.antimatter < upgrade.cost) return false;
                s.antimatter -= upgrade.cost;
                s.antiUpgrades.push(action.id);
                return true;
            }
            case 'skill': {
                const skill = SKILLS[action.id];
                if (!skill || !skillOpen(s, action.id) || s.strange < skill.cost) return false;
                s.strange -= skill.cost;
                s.skills.push(action.id);
                for (const [buildingId, count] of Object.entries(skillEffects(s).start)) s.buildings[buildingId] = Math.max(s.buildings[buildingId], count);
                return true;
            }
            case 'comet': {
                const result = catchComet(s);
                if (result) shift(s, 'comets', 1, given);
                return result;
            }
            case 'warp': {
                const result = warp(s);
                if (result) shift(s, 'warps', 1, given);
                return result;
            }
            case 'donate': {
                const { parts } = action;
                if (given === null || !canGive(s) || !Number.isInteger(parts) || parts < 1) return false;
                engineDay(s);
                if (action.what === 'ore') {
                    if (s.donatedToday + parts > CONFIG.engineOreParts || !canAfford(s, oreDonationCost(s, parts))) return false;
                    s.ore = Math.max(0, s.ore - oreDonationCost(s, parts));
                    s.donatedToday += parts;
                    // Worth less in the later sections; what doesn't make a whole part waits in engineOreRest
                    const quarters = (s.engineOreRest ?? 0) + parts * oreQuarters(s);
                    s.engineOreRest = quarters % CONFIG.engineOreQuarters;
                    if (quarters >= CONFIG.engineOreQuarters) give(s, given, [CONFIG.sourceOre, Math.floor(quarters / CONFIG.engineOreQuarters)]);
                    return true;
                }
                if (action.what === 'strange') {
                    if (s.strange < parts * CONFIG.engineStrangePart) return false;
                    s.strange -= parts * CONFIG.engineStrangePart;
                    give(s, given, [CONFIG.sourceStrange, parts]);
                    return true;
                }
                return false;
            }
            case 'crunch':
                return crunch(s);
            case 'rip':
                return rip(s);
            case 'anomalyStart':
                return anomalyStart(s, action.mods);
            case 'anomalyQuit':
                if (!s.anomaly) return false;
                anomalyLeave(s);
                return true;
            default:
                return false;
        }
    }

    // Whether the player has an asteroid skin (hbHubMinerSkinOpen())
    function skinOpen(s, id) {
        const unlock = SKINS[id]?.unlock;
        if (!unlock) return false;
        if (unlock.anomaly) return anomalyDone(s, unlock.anomaly);
        if (unlock.stage) return (s.engineStage ?? 0) >= unlock.stage;
        if (unlock.ignition) return s.ignitionCrew === true;
        return false;
    }

    // The most Anomalies finished in one stack (hbHubMinerBiggestStack())
    const biggestStack = (s) => Math.max(0, ...Object.keys(s.anomalies).map((key) => key.split('+').length));

    function stat(s, name) {
        if (name.startsWith('anomaly:')) return anomalyDone(s, name.slice(8)) ? 1 : 0;
        if (name.startsWith('tier:')) return ANOMALY_IDS.filter((id) => ANOMALIES[id].tier === Number(name.slice(5)) && anomalyDone(s, id)).length;
        switch (name) {
            case 'anomalies': return Object.keys(s.anomalies).length;
            case 'stack': return biggestStack(s);
            case 'lightHour': return s.anomalies.speedOfLight && s.anomalies.speedOfLight.best < CONFIG.lightHourMs ? 1 : 0;
            case 'engineGiven': case 'shiftDays': case 'weeklies': return s[name] ?? 0;
            case 'rate': return rate(s, Infinity);
            case 'upgrades': return s.upgrades.length;
            case 'buildings': return sum(Object.values(s.buildings));
            case 'ownedAll': return Math.min(...Object.values(s.buildings));
            case 'skills': return s.skills.length;
            case 'allSkills': return s.skills.length >= Object.keys(SKILLS).length ? 1 : 0;
            case 'allOre': case 'clicks': case 'crits': case 'comets': case 'diamonds': case 'warps': case 'crunches': case 'rips': return s[name];
            default: return s.buildings[name.slice(6)] ?? 0; // owned:<building>
        }
    }

    function checkAchievements(s) {
        for (const [id, achievement] of Object.entries(ACHIEVEMENTS)) {
            if (!s.achievements.includes(id) && stat(s, achievement.stat) >= achievement.at) s.achievements.push(id);
        }
    }

    // ----- Numbers and words

    // [size, word, short], biggest first: from a million (10^6) up to a centillion (10^303), which is as far as a
    // number goes. Past nonillion they're put together from Latin bits, like un + vigint + illion for an
    // unvigintillion (10^66), UVg for short. leaderboardNumberNames() in games/spaceOreMiner/leaderboard.php is the same.
    const BIG_NUMBERS = (() => {
        const names = [['million', 'M'], ['billion', 'B'], ['trillion', 'T'], ['quadrillion', 'Qa'], ['quintillion', 'Qi'],
            ['sextillion', 'Sx'], ['septillion', 'Sp'], ['octillion', 'Oc'], ['nonillion', 'No']];
        const units = [['', ''], ['un', 'U'], ['duo', 'D'], ['tre', 'T'], ['quattuor', 'Qa'], ['quin', 'Qi'], ['sex', 'Sx'],
            ['septen', 'Sp'], ['octo', 'Oc'], ['novem', 'No']];
        const tens = [['dec', 'Dc'], ['vigint', 'Vg'], ['trigint', 'Tg'], ['quadragint', 'Qag'], ['quinquagint', 'Qig'],
            ['sexagint', 'Sxg'], ['septuagint', 'Spg'], ['octogint', 'Ocg'], ['nonagint', 'Nog']];
        for (const [ten, tenShort] of tens) {
            for (const [unit, unitShort] of units) names.push([unit + ten + 'illion', unitShort + tenShort]);
        }
        names.push(['centillion', 'Ce']);
        // Parsed rather than 10 ** n, which can be a hair off this big
        return names.map(([word, short], index) => [Number('1e' + (3 * index + 6)), word, short]).reverse();
    })();

    // 1,234 / 1.23M, or 1.234 million with long. decimals: one decimal under 100, for rates like 0.1
    function format(number, { long = false, decimals = false } = {}) {
        if (!Number.isFinite(number)) return '∞';
        for (const [size, word, short] of BIG_NUMBERS) {
            if (number >= size) {
                return long ? (number / size).toFixed(3) + ' ' + word : String(Number((number / size).toFixed(2))) + short;
            }
        }
        if (decimals && number < 100 && number % 1 !== 0) return number.toFixed(1);
        return Math.floor(number).toLocaleString('en-US');
    }

    function formatDuration(ms) {
        const minutes = Math.round(ms / 60000);
        if (minutes < 60) return minutes <= 1 ? 'a minute' : minutes + ' minutes';
        const hours = Math.round(minutes / 60);
        if (hours < 48) return hours === 1 ? 'an hour' : hours + ' hours';
        return Math.round(hours / 24) + ' days';
    }

    const formatDate = (ms) => new Date(ms).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    // One of the game's drawn icons by its name (the 'icon' in the game's data, or a <symbol> id in icons.svg, which
    // data-icons points to), in a span with className. Only decoration: the text next to it says what it is.
    function iconBox(className, name) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'miner-icon');
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', ICONS + '#' + name);
        svg.append(use);
        const box = element('span', className);
        box.append(svg);
        return box;
    }

    // ----- The page

    const asteroid = root.querySelector('[data-miner-asteroid]');
    const field = root.querySelector('[data-miner-field]');
    const oreText = root.querySelector('[data-miner-ore]');
    const rateText = root.querySelector('[data-miner-rate]');
    const clickText = root.querySelector('[data-miner-click]');
    const saveText = root.querySelector('[data-miner-save]');
    const buffList = root.querySelector('[data-miner-buffs]');
    const bot = root.querySelector('[data-miner-bot]');
    const scene = root.querySelector('[data-miner-scene]');
    const surface = root.querySelector('[data-miner-surface]');
    const upgradeList = root.querySelector('[data-miner-upgrades]');
    const upgradesEmpty = root.querySelector('[data-miner-upgrades-empty]');
    const upgradesBox = root.querySelector('[data-miner-upgrades-box]');
    const upgradesMore = root.querySelector('[data-miner-upgrades-more]');
    const buildingList = root.querySelector('[data-miner-buildings]');
    const amounts = root.querySelector('[data-miner-amounts]');
    const keySwitch = root.querySelector('[data-miner-key-switch]');
    const autoSwitch = root.querySelector('[data-miner-auto-switch]');
    const warpView = root.querySelector('[data-miner-warp-view]');
    const darkText = root.querySelector('[data-miner-dark]');
    const warpNumbers = Object.fromEntries([...root.querySelectorAll('[data-miner-warp]')].map((node) => [node.dataset.minerWarp, node]));
    const warpProgress = root.querySelector('[data-miner-warp-progress]');
    const warpButton = root.querySelector('[data-miner-warp-button]');
    const warpHint = root.querySelector('[data-miner-warp-hint]');
    const darkList = root.querySelector('[data-miner-dark-upgrades]');
    const darkOwned = root.querySelector('[data-miner-dark-owned]');
    const crunchView = root.querySelector('[data-miner-crunch-view]');
    const crunchSections = root.querySelectorAll('[data-miner-crunch-section]'); // the jump's half of the top, and the upgrades
    const crunchLocked = root.querySelector('[data-miner-crunch-locked]');
    const antiText = root.querySelector('[data-miner-anti]');
    const crunchNumbers = Object.fromEntries([...root.querySelectorAll('[data-miner-crunch]')].map((node) => [node.dataset.minerCrunch, node]));
    const crunchProgress = root.querySelector('[data-miner-crunch-progress]');
    const crunchButton = root.querySelector('[data-miner-crunch-button]');
    const crunchHint = root.querySelector('[data-miner-crunch-hint]');
    const antiList = root.querySelector('[data-miner-anti-upgrades]');
    const antiOwned = root.querySelector('[data-miner-anti-owned]');
    const crunchDialog = root.querySelector('[data-miner-crunch-dialog]');
    const crunchDialogText = root.querySelector('[data-miner-crunch-dialog-text]');
    const autoWarpSwitch = root.querySelector('[data-miner-auto-warp]');
    const autoCrunchSwitch = root.querySelector('[data-miner-auto-crunch]');
    const autoWarpTimesBox = root.querySelector('[data-miner-auto-warp-times]');
    const autoCrunchTimesBox = root.querySelector('[data-miner-auto-crunch-times]');
    const ripView = root.querySelector('[data-miner-rip-view]');
    const ripSections = root.querySelectorAll('[data-miner-rip-section]');
    const ripLocked = root.querySelector('[data-miner-rip-locked]');
    const strangeText = root.querySelector('[data-miner-strange]');
    const ripNumbers = Object.fromEntries([...root.querySelectorAll('[data-miner-rip]')].map((node) => [node.dataset.minerRip, node]));
    const ripProgress = root.querySelector('[data-miner-rip-progress]');
    const ripButton = root.querySelector('[data-miner-rip-button]');
    const ripHint = root.querySelector('[data-miner-rip-hint]');
    const ripDialog = root.querySelector('[data-miner-rip-dialog]');
    const ripDialogText = root.querySelector('[data-miner-rip-dialog-text]');
    const skillsOwned = root.querySelector('[data-miner-skills-owned]');
    const tree = root.querySelector('[data-miner-tree]');
    const skillInfo = Object.fromEntries(['icon', 'name', 'branch', 'text', 'needs', 'cost', 'learn']
        .map((part) => [part, root.querySelector('[data-miner-skill-' + part + ']')]));
    const achievementList = root.querySelector('[data-miner-achievements]');
    const achievementCounts = root.querySelectorAll('[data-miner-achievement-count]'); // on the button and in the popup
    const statList = root.querySelector('[data-miner-stats]');
    const statsPopup = root.querySelector('[data-miner-popup="stats"]');
    const views = Object.fromEntries([...root.querySelectorAll('[data-miner-view]')].map((node) => [node.dataset.minerView, node]));
    const menu = root.querySelector('[data-miner-menu]');
    const laterMenu = menu.querySelector('[data-miner-menu-later]'); // the Engine and Anomalies, hidden until they open
    const laterLinks = Object.fromEntries([...laterMenu.querySelectorAll('[data-miner-view-link]')].map((node) => [node.dataset.minerViewLink, node]));
    const menuCounts = Object.fromEntries([...root.querySelectorAll('[data-miner-menu-count]')].map((node) => [node.dataset.minerMenuCount, node]));
    const menuNews = Object.fromEntries([...root.querySelectorAll('[data-miner-menu-news]')].map((node) => [node.dataset.minerMenuNews, node]));
    const cometButton = root.querySelector('[data-miner-comet]');
    const toasts = root.querySelector('[data-miner-toasts]');
    const ticker = root.querySelector('[data-miner-ticker-text]');
    const warpDialog = root.querySelector('[data-miner-warp-dialog]');
    const warpDialogText = root.querySelector('[data-miner-warp-dialog-text]');
    const otherTabDialog = root.querySelector('[data-miner-other-tab]');
    const keysDialog = root.querySelector('[data-miner-keys-dialog]');
    const soundButton = root.querySelector('[data-miner-sound]');
    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');

    // The asteroid changes as more is mined in a run, at this much ore mined this run: plain grey rock, iron, gold,
    // crystal, glowing alien core, molten, amethyst, neutron star, black hole core, nebula heart
    const STAGES = [0, 1e5, 1e8, 1e11, 1e14, 1e17, 1e20, 1e23, 1e26, 1e29];
    // The buildings in the picture of the asteroid, so it fills up as the company grows: one more of a building shows for
    // each number in 'at' that's owned. The ones in SURFACE stand on the asteroid (data-miner-surface, inside it), the rest
    // are around it (data-miner-scene). parts are its pieces, each a span with the class miner-sprite-<part> and one of
    // the drawn icons (see iconBox(); the same as in the store, or a scene* piece of one) or nothing (drawn in CSS: the
    // laser beam, the rocks the Moon Excavator dumps, the Black Hole Siphon's pull and the ore going up it, the embers
    // out of the Star Forge's chimney, the nebula, the Dyson Swarm, and the multiverse rift with its flash and the ore
    // that falls out of it, which fill the space around the asteroid). Where the nth
    // one goes and how it moves is in spaceOreMiner.css (.miner-sprite.is-<building>.is-<n>; the Ore Freighters' ways are
    // picked by flyFreighter()). The Dyson Swarm's ring is
    // two from the start: the half behind the asteroid and the half in front of it.
    const SCENE = {
        pickaxe: { at: [1, 5, 10, 25, 50, 100, 200], parts: { tool: 'pickaxe', body: 'sceneMiner' } },
        drone: { at: [1, 5, 15, 40, 100], parts: { body: 'drone' } },
        laser: { at: [1, 25, 100], parts: { beam: '', body: 'laserDrill' } },
        rig: { at: [1, 50], parts: { body: 'sceneRig' } },
        base: { at: [1], parts: { body: 'sceneBase' } },
        freighter: { at: [1, 10, 50], parts: { body: 'freighter' } },
        station: { at: [1], parts: { body: 'station' } },
        excavator: { at: [1], parts: { body: 'sceneMoon', arm: 'sceneExcavatorArm', rocks: '' } },
        cracker: { at: [1], parts: { body: 'sceneCrackerCore', left: 'sceneCrackerLeft', right: 'sceneCrackerRight', wedge: 'sceneCrackerWedge', sparks: 'sceneCrackerSparks' } },
        siphon: { at: [1], parts: { pull: '', body: 'sceneBlackHole', nozzle: 'sceneSiphon', ore: '' } },
        forge: { at: [1], parts: { rock: 'sceneForgeRock', body: 'sceneForgeFurnace', star: 'sceneForgeSun', grate: 'sceneForgeGrate', bar: 'sceneForgeBar', embers: '' } },
        nebula: { at: [1], parts: { body: '' } },
        dyson: { at: [1, 1], parts: { body: '' } },
        wormhole: { at: [1], parts: { body: 'wormhole' } },
        multiverse: { at: [1], parts: { glow: '', flash: '', edge: '', body: '', ours: 'sceneMultiverseDrill', theirs: 'sceneMultiverseDrillMirror', spill: '' } },
    };
    const SURFACE = ['pickaxe', 'rig', 'base'];
    // (how long it lasts, the ore a lucky one gave, its multiplier with Antimatter Comets)
    const COMET_TEXT = {
        frenzy: (ms, ore, times) => 'Supernova! Everything mines ' + format(times) + ' times as fast for ' + Math.round(ms / 1000) + ' seconds.',
        lucky: (ms, ore) => 'Lucky! The comet was full of ore: +' + format(ore) + '.',
        storm: (ms, ore, times) => 'Click Storm! Clicks mine ' + format(times) + ' times as much for ' + Math.round(ms / 1000) + ' seconds.',
        diamond: (ms, ore, times) => 'DIAMOND RUSH! ' + format(times) + ' times the production for ' + Math.round(ms / 1000) + ' seconds!',
    };
    const BUFF_TEXT = { frenzy: 'Supernova: production', storm: 'Click Storm: clicks', diamond: 'Diamond Rush: production' };

    // The whole save, as the server last sent it with what's been done since, and the game being played: the same, or
    // the Anomaly's game while the player is in one (gameOf()). Nearly everything shows and buys in state; the Engine,
    // the achievements and the stats are the player's own game's, so they use saveState.
    let saveState = null;
    let state = null;
    let isPlaying = false;
    let pending = []; // done since the last save was sent: what hbHubMinerApply() takes, plus at
    let isSending = false;
    let lastServerTick = 0; // the time of the save the server last made
    let clockOffset = 0; // the server's clock minus this computer's
    let lastSentAt = 0;
    let lastSavedAt = 0;
    let saveFailed = false;
    let buyAmount = 1;
    let clickTokens = CONFIG.clicksPerSecond; // clicks that would still count right now (the server's limit)
    let clickTokensAt = performance.now();
    let wasAuto = false; // the last click looked like an auto-clicker's (told once, until the clicks are back to normal)
    let press = null; // the pointer pressing the asteroid right now: {id, at (its event's timeStamp)}
    let lastHold = null; // how long the last press on the asteroid lasted, in ms (null when it wasn't let go on it)
    let recentClicks = []; // the last AUTO_CLICKS clicks in a row, for looksAuto(): {at, hold, x, y}
    let announced = new Set(); // achievements already shown
    let storeKey = '';
    let darkKey = '';
    let antiKey = '';
    let treeKey = '';
    let pickedSkill = null; // the skill shown over the tree next to it, null while none is
    let shownComet = -1; // the comet number on screen
    let shownCometAt = 0; // when it was drawn, in performance.now() time (Comet Catcher waits a moment after)
    let saveSoonTimer = 0;
    const sprites = Object.fromEntries(Object.keys(SCENE).map((id) => [id, []])); // the ones in the picture, per building
    let stageTimer = 0; // takes is-changing off the asteroid once it's faded into its new stage (renderStage())

    // The Long-Range Hot Keys switch, kept in this browser like the sound. It only does anything with the upgrade.
    const LATE_KEYS_KEY = 'hbHubMinerLateKeys';
    let lateKeys = false;
    try {
        lateKeys = localStorage.getItem(LATE_KEYS_KEY) === 'on';
    } catch {
        // no storage (a private window): it starts off
    }

    // The Auto Buy switch, kept the same way. It only does anything with the upgrade (see autoBuy()).
    const AUTO_BUY_KEY = 'hbHubMinerAutoBuy';
    let isAutoBuying = false;
    try {
        isAutoBuying = localStorage.getItem(AUTO_BUY_KEY) === 'on';
    } catch {
        // no storage: it starts off
    }

    // The Autopilot and Auto Crunch switches, kept the same way. They only do anything with their skills (see
    // autoPrestige()).
    const AUTO_WARP_KEY = 'hbHubMinerAutoWarp';
    const AUTO_CRUNCH_KEY = 'hbHubMinerAutoCrunch';
    let isAutoWarping = false;
    let isAutoCrunching = false;
    try {
        isAutoWarping = localStorage.getItem(AUTO_WARP_KEY) === 'on';
        isAutoCrunching = localStorage.getItem(AUTO_CRUNCH_KEY) === 'on';
    } catch {
        // no storage: they start off
    }

    // How many times as much Dark Matter (or Antimatter) a jump (or crunch) has to give before they make it: the box
    // next to each switch, 4 until it's changed, at least 2 (lower and they'd go off over and over).
    const AUTO_PRESTIGE_TIMES = 4;
    const AUTO_PRESTIGE_MIN_TIMES = 2;
    const AUTO_WARP_TIMES_KEY = 'hbHubMinerAutoWarpTimes';
    const AUTO_CRUNCH_TIMES_KEY = 'hbHubMinerAutoCrunchTimes';
    const readTimes = (text) => {
        const times = Number(text);
        return text !== null && text !== '' && Number.isFinite(times) && times >= AUTO_PRESTIGE_MIN_TIMES ? times : null;
    };
    let autoWarpTimes = AUTO_PRESTIGE_TIMES;
    let autoCrunchTimes = AUTO_PRESTIGE_TIMES;
    try {
        autoWarpTimes = readTimes(localStorage.getItem(AUTO_WARP_TIMES_KEY)) ?? AUTO_PRESTIGE_TIMES;
        autoCrunchTimes = readTimes(localStorage.getItem(AUTO_CRUNCH_TIMES_KEY)) ?? AUTO_PRESTIGE_TIMES;
    } catch {
        // no storage: 4 each
    }

    // Whether the popup about the hot keys has been shown in this browser (see showKeysHelp())
    const KEYS_HELP_KEY = 'hbHubMinerKeysHelp';

    const serverNow = () => Date.now() + clockOffset;

    // Runs the game up to now, but no further than the server will count as playing since its last save (after time
    // away it's the server that works out what was mined, at the offline rate, so the next save is made right away)
    function catchUp() {
        const now = serverNow();
        const limit = lastServerTick + CONFIG.onlineMs;
        const given = IS_GUEST ? null : [];
        const was = anomalyNow();
        advanceGame(saveState, Math.min(now, limit), Infinity, given);
        followGame(was);
        announceGiven(given);
        if (now > limit && Date.now() - lastSentAt > 3000) save();
    }

    // Does something now and keeps it for the next save. Returns what apply() returns.
    function act(action) {
        if (!isPlaying) return false;
        catchUp();
        const at = Math.round(state.tick);
        const given = IS_GUEST ? null : [];
        const was = anomalyNow();
        const result = apply(saveState, action, given);
        followGame(was, action.type === 'anomalyQuit');
        announceGiven(given);
        if (!result) return false;
        if (!['click', 'buy', 'upgrade'].includes(action.type)) note('Did ' + action.type + (action.id ? ' ' + action.id : '')); // not the ones Auto Buy does all the time

        // Clicks in the same second go as one (unless one looks like an auto-clicker's and the other doesn't)
        const last = pending[pending.length - 1];
        if (action.type === 'click' && last?.type === 'click' && at - last.at < 1000 && last.auto === action.auto) {
            last.count += action.count;
        } else {
            pending.push({ ...action, at });
        }
        if (pending.length >= CONFIG.maxActions / 2) save();
        return result;
    }

    // ----- Saving

    // Only shows while loading or when something's wrong; a save that worked says nothing
    function setSaveText(text, isError = false) {
        saveText.textContent = text;
        saveText.hidden = text === '';
        saveText.classList.toggle('is-error', isError);
    }

    // Carries on from the save the server sent, with what was done since it was sent done again on top
    function adopt(saved, isFirst) {
        saveState = saved;
        for (const game of [saveState, saveState.anomaly?.state].filter(Boolean)) {
            if (!game.buffs || Array.isArray(game.buffs)) game.buffs = {};
            if (!game.anomalies || Array.isArray(game.anomalies)) game.anomalies = {};
        }
        lastServerTick = gameOf(saveState).tick;
        for (const action of pending) {
            // Given again, to count the same as the server will: but they were already shown when they were done
            const given = IS_GUEST ? null : [];
            advanceGame(saveState, Math.max(gameOf(saveState).tick, action.at), Infinity, given);
            apply(saveState, action, given);
            checkAchievements(saveState);
        }
        state = gameOf(saveState);
        if (isFirst) announced = new Set(saveState.achievements);
        storeKey = '';
        darkKey = '';
        antiKey = '';
        treeKey = '';
    }

    const KEEPALIVE_BYTES = 60000; // a bit under the 64 KB browsers allow a keepalive request

    // A guest's game as this browser keeps it ({tab, token}), or null
    function guestSave() {
        try {
            const saved = JSON.parse(localStorage.getItem(GUEST_KEY));
            return typeof saved?.token === 'string' ? saved : null;
        } catch {
            return null; // no storage, or something broken in it
        }
    }

    function keepGuestSave(token) {
        try {
            localStorage.setItem(GUEST_KEY, JSON.stringify({ tab: TAB, token }));
        } catch {
            // no storage, or it's full: the game only lasts as long as the page
        }
    }

    function forgetGuestSave() {
        try {
            localStorage.removeItem(GUEST_KEY);
        } catch {
            // no storage
        }
    }

    async function save({ claim = false, keepalive = false } = {}) {
        if (isSending || (!isPlaying && !claim)) return;
        // A guest's game opened in another tab since: like the members' 409 "otherTab", but found here, as the
        // browser is the only place that knows
        if (IS_GUEST && !claim && guestSave()?.tab !== TAB) {
            note('Another tab took the game over');
            stop();
            otherTabDialog.showModal();
            return;
        }
        const was = step('save');
        isSending = true;
        clearTimeout(saveSoonTimer);
        // No more than the server replays in one save (it drops the rest): what's over goes with the next one. A lot can
        // pile up while a save is on its way (1600 Warp Jumps in crash report #32)
        const batch = pending.slice(0, CONFIG.maxActions);
        pending = pending.slice(CONFIG.maxActions);
        inflight = batch;
        const sentAt = Date.now();
        lastSentAt = sentAt;
        const body = new URLSearchParams({ action: 'sync', tab: TAB, actions: JSON.stringify(batch) });
        if (claim) body.set('claim', '1');
        if (root.dataset.view === 'engine') body.set('engine', '1'); // its top contributors and latest parts
        if (claim && recovered.length > 0) body.set('recovered', JSON.stringify(recovered));
        const guestToken = guestSave()?.token ?? '';
        if (IS_GUEST) body.set('token', guestToken);
        else if (claim && guestToken !== '') body.set('guest', guestToken); // brought over to the account, see the top
        // A keepalive request can't be bigger than 64 KB (the browser fails it at once), so a big batch goes as a normal
        // one, which still gets there as long as the page isn't closed
        if (body.toString().length > KEEPALIVE_BYTES) keepalive = false;
        writeRun(); // so a crash while it's on its way still has the batch
        note((claim ? 'Loading the save' : 'Saving') + ': ' + batch.length + ' actions' + (claim && recovered.length > 0 ? ', ' + recovered.length + ' recovered' : '')
            + (keepalive ? ' (hidden or closing)' : ''));
        step(was);

        try {
            const response = await fetch(SAVE_URL, {
                method: 'POST', headers: { Accept: 'application/json' }, body, keepalive,
            });
            step('save: answer');
            const data = await response.json().catch(() => ({}));
            step('save: answer');
            if (response.status === 409 && data.error === 'otherTab') {
                note('Another tab took the game over');
                stop();
                otherTabDialog.showModal();
                return;
            }
            if (response.status === 403) { // blocked, or the games were closed: the page says which
                location.reload();
                return;
            }
            if (!response.ok || !data.state) throw new Error(data.error || 'HTTP ' + response.status);

            clockOffset = Math.round(data.now - (sentAt + Date.now()) / 2);
            if (IS_GUEST && typeof data.token === 'string') keepGuestSave(data.token);
            if (data.guest) {
                forgetGuestSave();
                toast('orePile', data.guest === 'imported'
                    ? 'Your guest game is on your account now. Welcome to HB Hub!'
                    : 'You already had a game on this account, so you\'re carrying on with that one. The guest game was left behind.',
                'is-welcome', 9000);
            }
            step('save: adopt');
            adopt(data.state, claim);
            if (data.engine) adoptEngine(data.engine);
            lastSavedAt = Date.now();
            saveFailed = false;
            note('Saved in ' + (lastSavedAt - sentAt) + ' ms');
            if (claim) {
                step('save: start');
                finishRecovery(data.recovered ?? 0);
                start();
            }
            if (data.offline && data.offline.ms > CONFIG.onlineMs) {
                toast('orePile', 'Welcome back! While you were away for ' + formatDuration(data.offline.ms) + ', your machines mined '
                    + format(data.offline.ore) + ' ore.', 'is-welcome', 9000);
            }
        } catch (error) {
            pending = batch.concat(pending); // they go with the next save
            saveFailed = true;
            note('Save failed: ' + (error?.message ?? error));
            if (claim) {
                setSaveText('Couldn\'t load your save. Trying again…', true);
                setTimeout(() => save({ claim: true }), 5000);
            }
        } finally {
            isSending = false;
            inflight = [];
            writeRun();
            renderSave();
            step(null);
        }
        // More was done while it was on its way. Not after a save that failed: it would be sent again straight away and
        // fail again, over and over, and a request the browser turns down at once (see KEEPALIVE_BYTES) never even lets
        // the page breathe in between, which froze the whole tab. It goes with the next save instead (when the page is
        // shown again, or closed).
        if (document.hidden && pending.length > 0 && !saveFailed) save({ keepalive: true });
    }

    // Soon, for things worth keeping straight away (Warp Jumps, Dark Matter), without a save for every click
    function saveSoon() {
        clearTimeout(saveSoonTimer);
        saveSoonTimer = setTimeout(save, 500);
    }

    function renderSave() {
        if (!isPlaying) return;
        if (saveFailed) {
            setSaveText('Couldn\'t save, trying again soon. Don\'t close the tab yet.', true);
        } else if (lastSavedAt > 0) {
            setSaveText('');
        }
    }

    function start() {
        if (isPlaying) return;
        isPlaying = true;
        root.classList.remove('is-loading');
        asteroid.disabled = false;
        renderAll();
        requestAnimationFrame(frame);
    }

    // The game was opened somewhere else: what wasn't saved here never will be (that page has the game now)
    function stop() {
        isPlaying = false;
        pending = [];
        inflight = []; // turned away too
        isClosed = true;
        writeRun();
        asteroid.disabled = true;
        cometButton.hidden = true;
        setSaveText('Stopped: playing somewhere else.', true);
    }

    // ----- The game's timers. The browser handles clicks and drawing before timers, so with an auto-clicker going and
    // the page slow to draw, setInterval could go seconds without a turn even with every click handled in time: the
    // store, Auto Buy, saving and the watchdog's beat all stopped while the clicks and frames went on, which the
    // watchdog took for a freeze (crash reports #4 and #6). So each timer made with every() is also run by the frame
    // loop (runLateTimers()) once it's TIMER_LATE_MS late.
    const TIMER_LATE_MS = 500;
    const timers = []; // {ms, run, at (performance.now() of its last run)}

    function every(ms, run) {
        const timer = { ms, run, at: performance.now() };
        timers.push(timer);
        setInterval(() => {
            timer.at = performance.now();
            run();
        }, ms);
    }

    function runLateTimers() {
        const now = performance.now();
        for (const timer of timers) {
            if (now - timer.at < timer.ms + TIMER_LATE_MS) continue;
            timer.at = now;
            timer.run();
        }
    }

    // Saves every so often while the page is visible, and when it's hidden or closed
    every(1000, () => {
        step('save timer');
        if (isPlaying && !document.hidden && Date.now() - lastSentAt >= (saveFailed ? 10 : CONFIG.saveSeconds) * 1000) save();
        renderSave();
        step(null);
    });
    document.addEventListener('visibilitychange', () => {
        note(document.hidden ? 'Hidden' : 'Shown');
        beat(); // straight away, so the watchdog stops (or starts) watching now, not a second later
        if (!isPlaying) return;
        save({ keepalive: document.hidden });
    });
    window.addEventListener('pagehide', () => {
        if (isPlaying && pending.length > 0) save({ keepalive: true });
        isClosed = true;
        note('Closed');
        writeRun();
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return; // back from the browser's back/forward cache: open again
        isClosed = false;
        note('Opened again (back/forward)');
        writeRun();
    });

    // ----- Drawing

    // The ore you have. From a million up the number's word starts a new line ("293.962\nquattuordecillion", shown
    // by white-space: pre-line in the CSS), so "ore" doesn't jump between lines as the digits change width
    function showOre() {
        oreText.textContent = format(state.ore, { long: true }).replace(' ', '\n');
    }

    let lastFrameAt = 0;
    let frameGap = 0; // ms between the last two frames, for mine(): how slow the page is to draw right now

    function frame(at) {
        if (!isPlaying) return;
        step('frame');
        framesSinceBeat++; // for the frame rate in crash reports (see beat())
        if (lastFrameAt > 0) frameGap = at - lastFrameAt;
        lastFrameAt = at;
        if (!document.hidden) {
            catchUp();
            showOre();
            rateText.textContent = format(rate(state, state.tick), { decimals: true });
        }
        requestAnimationFrame(frame);
        step(null);
        runLateTimers();
    }

    // The slower parts, a few times a second. Each part tells the watchdog what it's doing, so a freeze in one of them
    // says which.
    every(200, () => {
        if (!isPlaying || document.hidden) return;
        forgetFieldBox(); // the page may have moved it (see fieldBox())
        step('tick: achievements');
        checkAchievements(saveState);
        renderAchievementsUnlocked();
        step('tick: Autopilot and Auto Crunch');
        autoPrestige();
        step('tick: store');
        renderStore();
        step('tick: comets');
        renderBuffs();
        renderComet();
        autoCatchComet();
        step('tick: Warp Jump');
        renderWarp();
        step('tick: Big Crunch');
        renderCrunch();
        step('tick: Big Rip and skill tree');
        renderRip();
        if (root.dataset.view === 'engine') {
            step('tick: Engine');
            renderEngine();
        }
        step('tick: Anomalies');
        renderAnomalyBanner();
        if (root.dataset.view === 'anomalies') renderAnomalies();
        step('tick: menu');
        renderMenu();
        step(null);
    });
    // Auto Buy on its own, quicker timer (10 times a second, twice the tick above), so it keeps up with fast production
    every(100, () => {
        if (!isPlaying || document.hidden) return;
        step('tick: Auto Buy');
        autoBuy();
        step(null);
    });
    every(1000, () => {
        if (!isPlaying || document.hidden || !statsPopup.open) return;
        step('stats');
        renderStats();
        step(null);
    });

    // withNews: a new line in the news ticker too (not for what Autopilot and Auto Crunch do every few seconds)
    function renderAll(withNews = true) {
        catchUp();
        renderStore();
        renderBuffs();
        renderComet();
        renderWarp();
        renderCrunch();
        renderRip();
        renderEngine();
        renderAnomalyBanner();
        renderAnomalies();
        renderMenu();
        renderAchievements();
        renderStats();
        if (withNews) renderTicker();
    }

    // The Dark Matter, Antimatter and Strange Matter in the menu, and a dot on a view with something to do there: a
    // jump, crunch or rip that would give something, an upgrade or skill that can be bought, or the day's parts from
    // ore that can still be given to the Engine
    function renderMenu() {
        const donated = saveState.engineDay === today() ? saveState.donatedToday : 0;
        menuNews.engine.hidden = !canGiveNow() || donated >= CONFIG.engineOreParts
            || !canAfford(saveState, oreDonationCost({ donatedToday: donated }, 1));
        menuCounts.darkMatter.textContent = state.warps > 0 ? format(state.dark) : '';
        menuCounts.antimatter.textContent = state.crunches > 0 ? format(state.antimatter) : '';
        menuCounts.strangeMatter.textContent = saveState.rips > 0 ? format(saveState.strange) : '';
        menuNews.darkMatter.hidden = warpGain(state) < 1
            && !Object.entries(DARK_UPGRADES).some(([id, upgrade]) => !hasDark(state, id) && state.dark >= upgrade.cost);
        menuNews.antimatter.hidden = crunchGain(state) < 1
            && !Object.entries(ANTI_UPGRADES).some(([id, upgrade]) => !hasAnti(state, id) && state.antimatter >= upgrade.cost);
        menuNews.strangeMatter.hidden = saveState.anomaly !== null || (ripGain(state) < 1
            && !Object.entries(SKILLS).some(([id, skill]) => skillOpen(state, id) && state.strange >= skill.cost));
        const finished = Object.keys(saveState.anomalies).length;
        menuCounts.anomalies.textContent = finished > 0 ? String(finished) : '';
        menuNews.anomalies.hidden = saveState.anomaly !== null || finished > 0 || !anomalyTierOpen(saveState, 1); // the first one to try
        renderLaterViews();
    }

    // The second bar of the menu: the Engine once you've made a Warp Jump (when you can start giving it parts), the
    // Anomalies once their first tier is open (the first Big Rip), and the bar itself once either is. Until then
    // they're hidden, so a new player isn't shown things they can't use yet; a view opened by its address (or Back)
    // before it's open goes back to Ore.
    function renderLaterViews() {
        const isOpen = { engine: saveState.warps >= 1, anomalies: saveState.anomaly !== null || anomalyTierOpen(saveState, 1) };
        for (const [view, open] of Object.entries(isOpen)) laterLinks[view].hidden = !open;
        laterMenu.hidden = !Object.values(isOpen).some(Boolean);
        if (isOpen[root.dataset.view] === false) {
            history.replaceState(null, '', menu.querySelector('[data-miner-view-link="ore"]').href);
            showView('ore');
        }
    }

    // The buildings shown: every one owned or nearly affordable, and the next one as a mystery
    function visibleBuildings() {
        const shown = [];
        let mystery = null;
        BUILDING_IDS.forEach((id, index) => {
            const isShown = index === 0 || state.buildings[id] > 0 || state.runOre >= BUILDINGS[id].cost / 3
                || state.buildings[BUILDING_IDS[index - 1]] > 0;
            if (isShown) shown.push(id);
            else mystery ??= id;
        });
        return { shown, mystery };
    }

    // The hot keys that buy buildings, in the order they're on the keyboard: 1-9, then 0 for the tenth
    const BUILDING_KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];

    // Which of the shown buildings the 1 key buys (the rest follow on): the first, or with Long-Range Hot Keys switched
    // on, the one that leaves the last ten for 1-9 and 0
    const keyOffset = (count) => (lateKeys && hasDark(state, 'lateKeys') ? Math.max(0, count - BUILDING_KEYS.length) : 0);

    // The upgrades show one row of this many (the cheapest) until they're opened up with the arrow under them
    const UPGRADES_ROW = 3;

    // The arrow under the upgrades: how many more there are, or that it closes them again
    function renderUpgradesMore() {
        const isOpen = upgradesBox.classList.contains('is-open');
        const more = upgradeList.children.length - UPGRADES_ROW;
        upgradesMore.textContent = isOpen ? '▴ Show less' : '▾ ' + more + ' more upgrade' + (more === 1 ? '' : 's');
        upgradesMore.setAttribute('aria-expanded', String(isOpen));
    }

    function setUpgradesOpen(isOpen) {
        upgradesBox.classList.toggle('is-open', isOpen);
        renderUpgradesMore();
    }

    function availableUpgrades() {
        const owned = new Set(state.upgrades); // has() would look through the whole list for each upgrade
        return Object.keys(UPGRADES).filter((id) => !owned.has(id) && upgradeUnlocked(state, UPGRADES[id]));
    }

    // The store's amount buttons say 1, 10, 100 or max (Max Buying)
    const parseAmount = (value) => (value === 'max' ? 'max' : Number(value));

    // How many of a building a click on it buys, at the amount picked: with Max that's as many as can be afforded, but at
    // least 1, so it still shows what the next one costs when not even that can be. The guess from the cost formula can
    // be one off after the rounding up, so it's checked against buildingCost() both ways.
    function storeAmount(id, amount = buyAmount) {
        if (amount !== 'max') return amount;
        const growth = costGrowth(state, id);
        let n = Math.max(1, Math.floor(Math.log(state.ore * (growth - 1) / buildingCost(state, id, 1) + 1) / Math.log(growth)));
        while (n > 1 && !canAfford(state, buildingCost(state, id, n))) n--;
        while (canAfford(state, buildingCost(state, id, n + 1))) n++;
        if (mod(state, 'minimalist')) n = Math.max(1, Math.min(n, CONFIG.minimalistMax - sum(Object.values(state.buildings))));
        return n;
    }

    // Why a building can't be bought right now, ore aside (canBuild()), for its button: '' when it can
    function buildingBlocked(s, id, amount) {
        if (canBuild(s, id, amount)) return '';
        if (s.destroyed.includes(id)) return 'Destroyed by the Unstable Vacuum.';
        if (mod(s, 'minimalist')) return 'Minimalist: ' + CONFIG.minimalistMax + ' buildings at most.';
        return 'One Track: only ' + BUILDINGS[BUILDING_IDS.find((other) => s.buildings[other] > 0)].plural + ' this run.';
    }

    function renderStore() {
        const { shown, mystery } = visibleBuildings();
        const upgrades = availableUpgrades();
        if (!has(state, 'bulk') || (buyAmount === 'max' && !has(state, 'bulkMax'))) buyAmount = 1;
        const offset = keyOffset(shown.length);
        const hasKeys = has(state, 'quickKeys');
        const key = [shown.join(), mystery, upgrades.join(), buyAmount, has(state, 'bulk'), has(state, 'bulkMax'), hasKeys, hasDark(state, 'lateKeys'), lateKeys, offset,
            hasDark(state, 'autoBuy'), isAutoBuying, skillEffects(state).upgradeCost].join('|');

        if (key !== storeKey) { // something new to show: build the lists again
            storeKey = key;
            amounts.hidden = !has(state, 'bulk');
            autoSwitch.hidden = !hasDark(state, 'autoBuy');
            autoSwitch.classList.toggle('is-active', isAutoBuying);
            autoSwitch.setAttribute('aria-pressed', String(isAutoBuying));
            keySwitch.hidden = !hasDark(state, 'lateKeys') || !hasKeys; // it only moves the 1-9 and 0 keys
            keySwitch.classList.toggle('is-active', lateKeys);
            keySwitch.setAttribute('aria-pressed', String(lateKeys));
            amounts.querySelector('[data-amount="max"]').hidden = !has(state, 'bulkMax');
            for (const button of amounts.querySelectorAll('[data-amount]')) {
                const isActive = parseAmount(button.dataset.amount) === buyAmount;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', String(isActive));
            }

            upgradeList.replaceChildren(...upgrades.map((id) => {
                const upgrade = UPGRADES[id];
                const item = element('li');
                const button = element('button', 'miner-upgrade');
                button.type = 'button';
                button.dataset.upgrade = id;
                button.append(
                    // a building's own upgrades show the building, with a ×2 on it (spaceOreMiner.css)
                    iconBox('miner-upgrade-icon' + (upgrade.type === 'building' ? ' is-boost' : ''), upgrade.icon),
                    element('span', 'miner-upgrade-name', upgrade.name),
                    element('span', 'miner-upgrade-cost', format(upgradeCost(state, id)) + ' ore'),
                    element('span', 'miner-upgrade-text', upgrade.text),
                );
                item.append(button);
                return item;
            }));
            upgradesEmpty.textContent = mod(state, 'bareMetal') ? 'No upgrades in Bare Metal: only buildings.' : 'Mine some more ore and upgrades will show up here.';
            upgradesEmpty.hidden = upgrades.length > 0;
            upgradesMore.hidden = upgrades.length <= UPGRADES_ROW;
            renderUpgradesMore();

            buildingList.replaceChildren(...shown.map((id) => {
                const building = BUILDINGS[id];
                const item = element('li');
                const button = element('button', 'miner-building');
                button.type = 'button';
                button.dataset.building = id;
                const main = element('span', 'miner-building-main');
                main.append(
                    element('span', 'miner-building-name', building.name),
                    element('span', 'miner-building-cost'),
                    element('span', 'miner-building-info'),
                );
                button.append(iconBox('miner-building-icon', building.icon), main, element('span', 'miner-building-owned'));
                const index = shown.indexOf(id) - offset;
                if (hasKeys && index >= 0 && index < BUILDING_KEYS.length) { // the hot key that buys it (see "Hot keys" below)
                    button.prepend(element('kbd', 'miner-key', BUILDING_KEYS[index]));
                    button.setAttribute('aria-keyshortcuts', BUILDING_KEYS[index]);
                }
                button.title = building.text;
                item.append(button);
                return item;
            }), ...(mystery ? [(() => {
                const item = element('li');
                const box = element('div', 'miner-building is-mystery');
                const main = element('span', 'miner-building-main');
                main.append(element('span', 'miner-building-name', '???'),
                    element('span', 'miner-building-cost', format(BUILDINGS[mystery].cost) + ' ore'),
                    element('span', 'miner-building-info', 'Mine more to find out what this is.'));
                box.append(iconBox('miner-building-icon', 'question'), main);
                item.append(box);
                return item;
            })()] : []));
        }

        // What changes all the time: what's affordable, prices and counts
        for (const button of upgradeList.querySelectorAll('[data-upgrade]')) {
            button.classList.toggle('is-affordable', canAfford(state, upgradeCost(state, button.dataset.upgrade)));
        }
        const rates = buildingRates(state);
        const total = sum(Object.values(rates));
        for (const button of buildingList.querySelectorAll('[data-building]')) {
            const id = button.dataset.building;
            const owned = state.buildings[id];
            const amount = storeAmount(id);
            const cost = buildingCost(state, id, amount);
            const each = owned > 0 ? rates[id] / owned : buildingRates({ ...state, buildings: { ...state.buildings, [id]: 1 } })[id];
            const blocked = buildingBlocked(state, id, amount);
            button.classList.toggle('is-affordable', blocked === '' && canAfford(state, cost));
            button.classList.toggle('is-blocked', blocked !== '');
            button.querySelector('.miner-building-cost').textContent = (buyAmount !== 1 ? '×' + format(amount) + ': ' : '') + format(cost) + ' ore';
            button.querySelector('.miner-building-info').textContent = blocked !== '' ? blocked : 'Each mines ' + format(each, { decimals: true }) + '/s'
                + (owned > 0 && total > 0 ? ' · ' + Math.round(rates[id] / total * 100) + '% of your production' : '');
            button.querySelector('.miner-building-owned').textContent = owned > 0 ? format(owned) : '';
            button.setAttribute('aria-label', BUILDINGS[id].name + ', you have ' + owned + '. Buy ' + amount + ' for ' + format(cost) + ' ore.');
        }

        clickText.textContent = format(clickValue(state, state.tick), { decimals: true });
        bot.hidden = autoClicks(state) === 0;
        renderScene();

        renderStage();
    }

    // The asteroid's look (STAGES). When it changes, is-changing fades the colours into the new ones with a flash (see
    // spaceOreMiner.css); not the first time, when the page opens on it, or it would fade in from grey rock every time.
    function renderStage() {
        // The skin picked (data-skin, see renderSkins()) goes over the stage's colours, apart from the flash
        const skin = saveState.skin ?? '';
        if ((asteroid.dataset.skin ?? '') !== skin) {
            if (skin === '') delete asteroid.dataset.skin;
            else asteroid.dataset.skin = skin;
        }
        const stage = String(STAGES.findLastIndex((at) => state.runOre >= at));
        if (asteroid.dataset.stage === stage) return;
        if (asteroid.dataset.stage !== undefined) {
            asteroid.classList.remove('is-changing');
            void asteroid.offsetWidth; // so the flash starts over if it's still going
            asteroid.classList.add('is-changing');
            clearTimeout(stageTimer);
            stageTimer = setTimeout(() => asteroid.classList.remove('is-changing'), 1700); // once the colours are done
        }
        asteroid.dataset.stage = stage;
    }

    // The buildings in the picture (SCENE). Only what changed is added or taken away, so the rest keep moving instead of
    // all starting over with every purchase.
    function renderScene() {
        for (const [id, { at, parts }] of Object.entries(SCENE)) {
            const count = at.filter((owned) => state.buildings[id] >= owned).length;
            const drawn = sprites[id];
            while (drawn.length < count) {
                const sprite = element('span', 'miner-sprite is-' + id + ' is-' + (drawn.length + 1));
                sprite.append(...Object.entries(parts).map(([part, icon]) => (icon
                    ? iconBox('miner-sprite-' + part, icon) : element('span', 'miner-sprite-' + part))));
                (SURFACE.includes(id) ? surface : scene).append(sprite);
                drawn.push(sprite);
                if (id === 'freighter') {
                    flyFreighter(sprite);
                    // A new way each time it's across (the bob of its body counts its turns too, so only its own flight)
                    sprite.addEventListener('animationiteration', (event) => {
                        if (event.target !== sprite || event.animationName !== 'miner-fly') return;
                        step('Ore Freighter turning');
                        flyFreighter(sprite);
                        step(null);
                    });
                }
            }
            while (drawn.length > count) drawn.pop().remove();
        }
    }

    // Picks the way an Ore Freighter flies next (see .miner-sprite.is-freighter in the CSS): from the left or the right
    // to the other side, at any height on each, so it can go either way, straight or climbing or diving. It's turned to
    // face where it's going (flipped when that's to the left), and goes behind the asteroid when the way crosses it.
    // It's off to the side when this is called, so the jump to the new start can't be seen.
    function flyFreighter(sprite) {
        const fromX = Math.random() < 0.5 ? -12 : 112;
        const toX = 100 - fromX;
        const fromY = 8 + Math.random() * 84;
        const toY = 8 + Math.random() * 84;
        // The angles are in pixels, so they need the picture's size (a guess while the view is hidden)
        const width = scene.clientWidth || 600;
        const height = scene.clientHeight || 300;
        const dx = (toX - fromX) / 100 * width;
        const dy = (toY - fromY) / 100 * height;
        const isLeft = dx < 0;
        const turn = isLeft ? Math.atan2(-dy, -dx) : Math.atan2(dy, dx);

        // How close the way comes to the middle of the picture, where the asteroid is
        const ax = fromX / 100 * width - width / 2;
        const ay = fromY / 100 * height - height / 2;
        const along = Math.min(1, Math.max(0, -(ax * dx + ay * dy) / (dx * dx + dy * dy)));
        const closest = Math.hypot(ax + dx * along, ay + dy * along);
        const rockR = parseFloat(getComputedStyle(field).getPropertyValue('--rock-r')) || 110;

        sprite.style.setProperty('--from-x', fromX + '%');
        sprite.style.setProperty('--to-x', toX + '%');
        sprite.style.setProperty('--from-y', fromY.toFixed(1) + '%');
        sprite.style.setProperty('--to-y', toY.toFixed(1) + '%');
        sprite.style.setProperty('--turn', turn.toFixed(3) + 'rad');
        sprite.style.setProperty('--flip', isLeft ? '-1' : '1');
        sprite.classList.toggle('is-behind', closest < rockR * 1.2);
    }

    function renderBuffs() {
        field.classList.toggle('is-frenzy', buff(state, 'production', state.tick) > 1); // the picture's miners work faster
        const running = Object.values(state.buffs).filter((item) => item.until > state.tick);
        buffList.replaceChildren(...running.map((item) => {
            const row = element('li', 'miner-buff is-' + item.kind);
            row.append(element('span', 'miner-buff-name', BUFF_TEXT[item.kind] + ' ×' + format(item.multiplier)),
                element('span', 'miner-buff-time', Math.ceil((item.until - state.tick) / 1000) + 's'));
            return row;
        }));
    }

    function renderComet() {
        const comet = state.comet;
        const stay = cometStay(state);
        const isOnScreen = state.tick >= comet.at && state.tick <= comet.at + stay;
        if (!isOnScreen || mod(state, 'darkSky')) {
            cometButton.hidden = true;
            return;
        }
        if (shownComet === comet.n && !cometButton.hidden) return;

        // A new comet: a random path across the screen, starting as far along it as the comet already is
        shownComet = comet.n;
        shownCometAt = performance.now();
        const kind = cometKind(state);
        cometButton.classList.toggle('is-diamond', kind === 'diamond');
        cometButton.setAttribute('aria-label', kind === 'diamond' ? 'Catch the Diamond Comet' : 'Catch the Golden Comet');
        cometButton.style.setProperty('--comet-from', (10 + Math.random() * 60) + 'vh');
        cometButton.style.setProperty('--comet-to', (10 + Math.random() * 60) + 'vh');
        cometButton.style.setProperty('--comet-left', (10 + Math.random() * 70) + 'vw');
        cometButton.style.setProperty('--comet-ms', stay + 'ms');
        cometButton.style.setProperty('--comet-delay', -(state.tick - comet.at) + 'ms');
        cometButton.hidden = false;
        cometButton.classList.remove('is-flying');
        void cometButton.offsetWidth; // so the animation starts over
        cometButton.classList.add('is-flying');
        sound('comet');
    }

    function renderWarp() {
        const gain = warpGain(state);
        darkText.textContent = format(state.dark);
        warpNumbers.runOre.textContent = format(state.runOre);
        warpNumbers.target.textContent = format(CONFIG.warpOre);
        warpNumbers.gain.textContent = format(gain);
        warpNumbers.bonus.textContent = '+' + format(darkBonus(state) * 100 * state.darkEarned, { decimals: true }) + '%';
        setProgress(warpProgress, state.runOre / CONFIG.warpOre);
        warpView.classList.toggle('is-ready', gain >= 1);
        warpButton.disabled = gain < 1;
        warpHint.textContent = state.runOre < CONFIG.warpOre
            ? 'Mine ' + format(CONFIG.warpOre - state.runOre) + ' more ore this run to unlock the Warp Jump.'
            : gain < 1 ? 'Mine a bit more first: a jump now wouldn\'t give you any Dark Matter.' : '';
        renderAutoSwitch(autoWarpSwitch, autoWarpTimesBox, skillEffects(state).autoWarp && !isNoAuto(), isAutoWarping);

        const key = state.dark + '|' + state.darkUpgrades.join();
        if (key === darkKey) return;
        darkKey = key;
        darkOwned.textContent = state.darkUpgrades.length + ' of ' + Object.keys(DARK_UPGRADES).length + ' bought';
        darkList.replaceChildren(...prestigeUpgrades(DARK_UPGRADES, state.darkUpgrades, state.dark, 'dark', 'Dark Matter'));
    }

    // The bar towards a Warp Jump or Big Crunch: part is how far along it is (1 or more is there)
    function setProgress(bar, part) {
        const percent = Math.min(100, Math.floor(part * 100));
        if (bar.getAttribute('aria-valuenow') === String(percent)) return;
        bar.setAttribute('aria-valuenow', percent);
        bar.style.setProperty('--progress', percent + '%');
        bar.classList.toggle('is-full', percent >= 100);
    }

    // The Dark Matter or Antimatter upgrades' cards, cheapest first: kind is the data-* attribute and the class (is-dark,
    // is-anti). Each one has its icon, name, what it does and its price, or a tick once it's bought.
    function prestigeUpgrades(upgrades, owned, toSpend, kind, currency) {
        return Object.entries(upgrades).sort(([, a], [, b]) => a.cost - b.cost).map(([id, upgrade]) => {
            const item = element('li');
            const button = element('button', 'miner-perk is-' + kind);
            button.type = 'button';
            button.dataset[kind] = id;
            const isOwned = owned.includes(id);
            button.classList.toggle('is-owned', isOwned);
            button.classList.toggle('is-affordable', !isOwned && toSpend >= upgrade.cost);
            button.disabled = isOwned;
            button.append(
                iconBox('miner-perk-icon', upgrade.icon),
                element('span', 'miner-perk-name', upgrade.name),
                element('span', 'miner-perk-text', upgrade.text),
                element('span', 'miner-perk-cost', isOwned ? 'Bought' : format(upgrade.cost) + ' ' + currency),
            );
            item.append(button);
            return item;
        });
    }

    // The Antimatter view is locked until there's Dark Matter to talk about: the first Warp Jump
    function renderCrunch() {
        const isLocked = state.warps === 0;
        crunchView.classList.toggle('is-locked', isLocked);
        crunchSections.forEach((section) => { section.hidden = isLocked; });
        crunchLocked.hidden = !isLocked;
        antiText.textContent = format(state.antimatter);
        if (isLocked) {
            crunchView.classList.remove('is-ready');
            return;
        }
        const gain = crunchGain(state);
        crunchNumbers.dark.textContent = format(state.darkEarned);
        const target = CONFIG.crunchDark * skillEffects(state).crunchDark;
        crunchNumbers.gain.textContent = format(gain);
        crunchNumbers.target.textContent = format(target);
        crunchNumbers.bonus.textContent = '+' + format((antiMultiplier(state) - 1) * 100) + '%';
        setProgress(crunchProgress, state.darkEarned / target);
        crunchView.classList.toggle('is-ready', gain >= 1);
        crunchButton.disabled = gain < 1;
        crunchHint.textContent = gain < 1
            ? 'Earn ' + format(target - state.darkEarned) + ' more Dark Matter in this universe to unlock the Big Crunch.' : '';
        renderAutoSwitch(autoCrunchSwitch, autoCrunchTimesBox, skillEffects(state).autoCrunch && !isNoAuto(), isAutoCrunching);

        const key = state.antimatter + '|' + state.antiUpgrades.join();
        if (key === antiKey) return;
        antiKey = key;
        antiOwned.textContent = state.antiUpgrades.length + ' of ' + Object.keys(ANTI_UPGRADES).length + ' bought';
        antiList.replaceChildren(...prestigeUpgrades(ANTI_UPGRADES, state.antiUpgrades, state.antimatter, 'anti', 'Antimatter'));
    }

    // The Autopilot or Auto Crunch switch and its "x4" box: only there with its skill, the switch lit while it's on
    function renderAutoSwitch(button, timesBox, isThere, isOn) {
        button.hidden = !isThere;
        timesBox.hidden = !isThere;
        button.classList.toggle('is-active', isOn);
        button.setAttribute('aria-pressed', String(isOn));
    }

    // The Strange Matter view is locked until there's Antimatter to talk about: the first Big Crunch
    function renderRip() {
        const isLocked = state.crunches === 0;
        ripView.classList.toggle('is-locked', isLocked);
        ripSections.forEach((section) => { section.hidden = isLocked; });
        ripLocked.hidden = !isLocked;
        strangeText.textContent = format(state.strange);
        if (isLocked) {
            ripView.classList.remove('is-ready');
            return;
        }
        // Until a rip gives something, the bar and the target are for the first Antimatter that does: 10,000 for the
        // first rip, after that whatever beats the best multiverse so far
        const gain = ripGain(state);
        const next = nextRip(state);
        const target = gain < 1 && next ? next.at : CONFIG.ripAnti;
        ripNumbers.anti.textContent = format(state.antimatterEarned);
        ripNumbers.target.textContent = format(target);
        ripNumbers.gain.textContent = format(gain);
        ripNumbers.bonus.textContent = '+' + format((strangeMultiplier(state) - 1) * 100) + '%';
        setProgress(ripProgress, state.antimatterEarned / target);
        ripView.classList.toggle('is-ready', gain >= 1);
        ripButton.disabled = gain < 1 || saveState.anomaly !== null;
        if (saveState.anomaly) {
            ripHint.textContent = 'There are no Big Rips in an Anomaly: its goal is Antimatter.';
        } else if (!next) {
            ripHint.textContent = '';
        } else if (gain >= 1) {
            ripHint.textContent = '+' + format(next.gain - gain) + ' more Strange Matter at ' + format(next.at) + ' Antimatter.';
        } else if (state.strangeEarned === 0) {
            ripHint.textContent = 'Earn ' + format(target - state.antimatterEarned) + ' more Antimatter in this multiverse to unlock the Big Rip.';
        } else {
            ripHint.textContent = 'A Big Rip has to beat your best multiverse: it gives ' + format(next.gain) + ' Strange Matter at '
                + format(next.at) + ' Antimatter.';
        }
        renderTree();
    }

    // ----- The skill tree. Built once from where the skills are ('at': [column, row]): a grid of buttons, one per
    // skill, over an SVG in the same units (one per cell) with a line from each skill back to each skill it needs. It
    // grows from left to right, a row per branch, with lines between the rows where a skill needs one from the branch
    // next to it. After that only classes change: on the buttons is-learned, is-open (can be learned now),
    // is-affordable and is-picked (the one shown over the tree), and on the lines is-lit (both ends learned) and is-open
    // (only the left one).

    const TREE_ROWS = 5;
    const BRANCH_NAMES = { core: 'Core', industry: 'Industry', hands: 'Hands', sky: 'Sky', cosmos: 'Cosmos' };
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const treeNodes = {}; // skill id => its button
    const treeLines = []; // {line, from (the skill needed), to}
    const skillPanel = root.querySelector('[data-miner-skill-info]');
    const treeWrap = root.querySelector('[data-miner-tree-wrap]'); // what the panel is placed in
    const treeScroll = root.querySelector('[data-miner-tree-scroll]');

    function buildTree() {
        const columns = Math.max(...Object.values(SKILLS).map((skill) => skill.at[0])) + 1;
        tree.style.setProperty('--columns', columns);
        tree.style.setProperty('--rows', TREE_ROWS);
        const lines = document.createElementNS(SVG_NS, 'svg');
        lines.setAttribute('class', 'miner-tree-lines');
        lines.setAttribute('viewBox', '0 0 ' + columns + ' ' + TREE_ROWS);
        lines.setAttribute('preserveAspectRatio', 'none');
        lines.setAttribute('aria-hidden', 'true');
        for (const [id, skill] of Object.entries(SKILLS)) {
            for (const afterId of skill.after) {
                const line = document.createElementNS(SVG_NS, 'line');
                line.setAttribute('class', 'miner-tree-line is-' + skill.branch);
                line.setAttribute('x1', SKILLS[afterId].at[0] + 0.5);
                line.setAttribute('y1', SKILLS[afterId].at[1] + 0.5);
                line.setAttribute('x2', skill.at[0] + 0.5);
                line.setAttribute('y2', skill.at[1] + 0.5);
                lines.append(line);
                treeLines.push({ line, from: afterId, to: id });
            }
        }
        tree.append(lines);
        for (const [id, skill] of Object.entries(SKILLS)) {
            const button = element('button', 'miner-skill is-' + skill.branch);
            button.type = 'button';
            button.dataset.skill = id;
            button.title = skill.name;
            button.style.gridColumn = String(skill.at[0] + 1);
            button.style.gridRow = String(skill.at[1] + 1);
            button.append(iconBox('miner-skill-icon', skill.icon));
            tree.append(button);
            treeNodes[id] = button;
        }
    }

    function renderTree() {
        if (tree.childElementCount === 0) buildTree();
        const key = state.skills.join() + '|' + state.strange + '|' + pickedSkill;
        if (key === treeKey) return;
        treeKey = key;
        for (const [id, button] of Object.entries(treeNodes)) {
            const skill = SKILLS[id];
            const isLearned = hasSkill(state, id);
            const isOpen = skillOpen(state, id);
            button.classList.toggle('is-learned', isLearned);
            button.classList.toggle('is-open', isOpen);
            button.classList.toggle('is-affordable', isOpen && state.strange >= skill.cost);
            button.classList.toggle('is-picked', id === pickedSkill);
            button.setAttribute('aria-pressed', String(id === pickedSkill));
            button.setAttribute('aria-label', skill.name + ', ' + (isLearned ? 'learned'
                : isOpen ? 'can be learned for ' + format(skill.cost) + ' Strange Matter' : 'needs the skills before it first'));
        }
        for (const { line, from, to } of treeLines) {
            line.classList.toggle('is-lit', hasSkill(state, from) && hasSkill(state, to));
            line.classList.toggle('is-open', hasSkill(state, from) && !hasSkill(state, to));
        }
        skillsOwned.textContent = state.skills.length + ' of ' + Object.keys(SKILLS).length + ' learned';
        renderSkillInfo();
    }

    // "A", "A and B", "A, B and C"
    const listText = (items) => (items.length > 1 ? items.slice(0, -1).join(', ') + ' and ' + items[items.length - 1] : items.join(''));

    // The picked skill, over the tree next to it: what it does, what it needs and the button that learns it
    function renderSkillInfo() {
        skillPanel.hidden = pickedSkill === null;
        if (pickedSkill === null) return;
        const skill = SKILLS[pickedSkill];
        const isLearned = hasSkill(state, pickedSkill);
        const isOpen = skillOpen(state, pickedSkill);
        const missing = skill.after.filter((id) => !hasSkill(state, id)).map((id) => SKILLS[id].name);
        skillPanel.className = 'miner-skill-info is-' + skill.branch + (isLearned ? ' is-learned' : '');
        skillInfo.icon.replaceChildren(iconBox('miner-skill-info-box', skill.icon));
        skillInfo.name.textContent = skill.name;
        skillInfo.branch.textContent = BRANCH_NAMES[skill.branch];
        skillInfo.text.textContent = skill.text;
        skillInfo.needs.textContent = isLearned ? 'Learned.'
            : missing.length > 0 ? 'Needs ' + listText(missing) + ' first.'
            : saveState.anomaly ? 'Skills can\'t be learned in an Anomaly.'
            : state.strange < skill.cost ? 'You need ' + format(skill.cost - state.strange) + ' more Strange Matter.' : '';
        skillInfo.cost.textContent = format(skill.cost) + ' Strange Matter';
        skillInfo.learn.hidden = isLearned;
        skillInfo.learn.disabled = !isOpen || state.strange < skill.cost || saveState.anomaly !== null;
        placeSkillInfo(); // its height can change with the text
    }

    // Puts the picked skill's panel over the tree beside its button: to the right of it if there's room in the tree's
    // box, else to the left, else under it (on narrow screens), and never past the box's sides. Positions are in px from
    // the box's corner (.miner-tree-wrap), which the panel is absolutely placed in. Again on resizing, scrolling the tree
    // sideways and coming back to the view (showView()).
    function placeSkillInfo() {
        if (pickedSkill === null || treeWrap.offsetParent === null) return; // nothing picked, or on another view
        const box = treeWrap.getBoundingClientRect();
        const node = treeNodes[pickedSkill].getBoundingClientRect();
        const width = skillPanel.offsetWidth;
        const height = skillPanel.offsetHeight;
        const gap = 12;
        const clamp = (value, max) => Math.max(0, Math.min(value, max));
        let left;
        let top;
        if (node.right + gap + width <= box.right) left = node.right - box.left + gap;
        else if (node.left - gap - width >= box.left) left = node.left - box.left - gap - width;
        if (left === undefined) {
            left = clamp(node.left - box.left + node.width / 2 - width / 2, box.width - width);
            top = node.bottom - box.top + gap;
        } else {
            left = clamp(left, box.width - width); // for a button scrolled out of sight
            top = clamp(node.top - box.top + node.height / 2 - height / 2, box.height - height);
        }
        skillPanel.style.left = left + 'px';
        skillPanel.style.top = top + 'px';
    }

    // Shows a skill over the tree, or closes it (null)
    function pickSkill(id) {
        pickedSkill = id;
        renderTree();
    }

    function renderAchievements() {
        achievementList.replaceChildren(...Object.entries(ACHIEVEMENTS).map(([id, achievement]) => {
            const isDone = saveState.achievements.includes(id);
            const item = element('li', 'miner-achievement' + (isDone ? ' is-done' : ''));
            item.dataset.achievement = id;
            item.append(iconBox('miner-achievement-icon', isDone ? 'trophy' : 'lock'),
                element('span', 'miner-achievement-name', achievement.name),
                element('span', 'miner-achievement-text', achievement.text));
            item.setAttribute('aria-label', achievement.name + ': ' + achievement.text + (isDone ? ' Done.' : ' Not done yet.'));
            return item;
        }));
        for (const count of achievementCounts) count.textContent = saveState.achievements.length + ' / ' + Object.keys(ACHIEVEMENTS).length;
    }

    function renderAchievementsUnlocked() {
        const fresh = saveState.achievements.filter((id) => !announced.has(id));
        if (fresh.length === 0) return;
        for (const id of fresh) {
            announced.add(id);
            toast('trophy', 'Achievement: ' + ACHIEVEMENTS[id].name + ' - ' + ACHIEVEMENTS[id].text, 'is-achievement');
        }
        sound('achievement');
        renderAchievements();
    }

    function renderStats() {
        const rows = [
            ['Ore mined ever', format(saveState.allOre)],
            ['Ore mined this run', format(saveState.runOre)],
            ['Ore from clicks', format(saveState.clickOre)],
            ['Clicks', format(saveState.clicks)],
            ['Critical clicks', format(saveState.crits)],
            ['Comets caught', format(saveState.comets)],
            ['Diamond Comets caught', format(saveState.diamonds)],
            ['Warp Jumps', format(saveState.warps)],
            ...(hasSkill(saveState, 'frequentFlyer') ? [['Warp Jumps for Frequent Flyer Miles', format(saveState.flights)]] : []),
            ...(saveState.crunches > 0 ? [
                ['Ore mined this universe', format(saveState.universeOre)],
                ['Big Crunches', format(saveState.crunches)],
                [saveState.rips > 0 ? 'Antimatter earned this multiverse' : 'Antimatter earned', format(saveState.antimatterEarned)],
            ] : []),
            ...(saveState.rips > 0 ? [
                ['Big Rips', format(saveState.rips)],
                ['Strange Matter earned', format(saveState.strangeEarned)],
                ['Skills', saveState.skills.length + ' / ' + Object.keys(SKILLS).length],
            ] : []),
            ['Buildings', format(sum(Object.values(saveState.buildings)))],
            ['Upgrades', saveState.upgrades.length + ' / ' + Object.keys(UPGRADES).length],
            ...(Object.keys(saveState.anomalies).length > 0 ? [['Anomalies finished', ANOMALY_IDS.filter((id) => saveState.anomalies[id]).length + ' / ' + ANOMALY_IDS.length]] : []),
            ...(Object.keys(saveState.anomalies).length > 0 ? [['Biggest stack finished', String(biggestStack(saveState))]] : []),
            ...(saveState.engineGiven > 0 ? [['Engine Parts given', format(saveState.engineGiven)]] : []),
            ['Playing since', formatDate(saveState.started)],
            ['This run started', formatDate(saveState.runStarted)],
        ];
        statList.replaceChildren(...rows.map(([name, value]) => {
            const row = element('div');
            row.append(element('dt', '', name), element('dd', '', value));
            return row;
        }));
        renderSkins();
    }

    // The asteroid skins (CONFIG.skins), in the stats popup: the usual look and each skin, the ones not earned yet
    // saying how to get them. Picking one is an action like any other (apply()), so it's saved with the game.
    const skinList = root.querySelector('[data-miner-skins]');
    const SKIN_UNLOCK_TEXT = (unlock) => (unlock.anomaly ? 'Finish ' + ANOMALIES[unlock.anomaly].name + '.'
        : unlock.stage ? 'Comes with the Cosmic Engine\'s ' + ENGINE_STAGES[unlock.stage - 1].name + '.' : 'For everyone who gives parts to Ignition.');

    function renderSkins() {
        const buttons = [[null, { name: 'Usual', unlock: null }], ...Object.entries(SKINS)].map(([id, skin]) => {
            const isOpen = id === null || skinOpen(saveState, id);
            const button = element('button', 'miner-skin', skin.name);
            button.type = 'button';
            button.dataset.skin = id ?? '';
            button.setAttribute('aria-pressed', String((saveState.skin ?? null) === id));
            button.disabled = !isOpen;
            button.title = isOpen ? (skin.moving ? 'Animated' : '') : SKIN_UNLOCK_TEXT(skin.unlock);
            return button;
        });
        skinList.replaceChildren(...buttons);
    }

    skinList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-skin]');
        if (!button || button.disabled) return;
        if (!act({ type: 'skin', id: button.dataset.skin || null })) return;
        sound('buy');
        renderSkins();
        renderStage();
        saveSoon();
    });

    // ----- Messages in the corner

    // iconName is one of the icons (see iconBox()) to show in front of the text, or '' for none. Gives back the <li>, so
    // a message that gets replaced later (Auto Buy's) can be taken down early.
    function toast(iconName, text, className = '', keepFor = 5000) {
        const item = element('li', 'miner-toast ' + className);
        if (iconName) item.append(iconBox('miner-toast-icon', iconName));
        item.append(element('span', 'miner-toast-text', text));
        toasts.append(item);
        while (toasts.children.length > 4) toasts.firstElementChild.remove();
        setTimeout(() => {
            item.classList.add('is-leaving');
            setTimeout(() => item.remove(), 400);
        }, keepFor);
        return item;
    }

    // ----- The news ticker: a new line every few seconds, some only once you have what they're about

    /** True Monday to Friday from 8:00 to 15:00 (the player's own clock), for the news that only makes sense in school. */
    const schoolHours = () => {
        const now = new Date();
        return now.getDay() >= 1 && now.getDay() <= 5 && now.getHours() >= 8 && now.getHours() < 15;
    };

    /** True from midnight to 5:00 (the player's own clock), for the news telling you to go to bed. */
    const lateNight = () => new Date().getHours() < 5;

    /** True on Saturday and Sunday (the player's own clock). */
    const weekend = () => new Date().getDay() === 0 || new Date().getDay() === 6;

    /** How many buildings the save has, all kinds together. */
    const owned = (s) => Object.values(s.buildings).reduce((total, count) => total + count, 0);

    const NEWS = [
        ['Local alien files complaint about missing moon.', (s) => s.buildings.excavator > 0],
        ['Scientists confirm: asteroid is "still mostly rock".'],
        ['Space Pickaxe Guy asks for a pickaxe that works in zero gravity.', (s) => s.buildings.pickaxe > 0],
        ['Mining Drones form a union. Demands: more batteries, fewer asteroids.', (s) => s.buildings.drone > 0],
        ['Laser Drill accidentally writes "HI MOM" on the side of Mars.', (s) => s.buildings.laser > 0],
        ['Mining Rig spotted taking a nap on the dark side of the asteroid.', (s) => s.buildings.rig > 0],
        ['Asteroid Base opens its first café. The coffee floats away immediately.', (s) => s.buildings.base > 0],
        ['Ore Freighter stuck in traffic behind a slow comet.', (s) => s.buildings.freighter > 0],
        ['Space Station\'s Wi-Fi password is still "password".', (s) => s.buildings.station > 0],
        ['The Moon is now 3% smaller. Werewolves confused.', (s) => s.buildings.excavator > 0],
        ['Planet Cracker cracks a planet. Planet says "ow".', (s) => s.buildings.cracker > 0],
        ['Black Hole Siphon pulls out a sock that went missing in 1987.', (s) => s.buildings.siphon > 0],
        ['Star Forge workers asked to stop calling the Sun "the big oven".', (s) => s.buildings.forge > 0],
        ['Nebula Harvester finds a nebula shaped like a duck. Keeps it.', (s) => s.buildings.nebula > 0],
        ['Dyson Swarm blocks out a star. Nearby planet asks who turned off the lights.', (s) => s.buildings.dyson > 0],
        ['Wormhole Gate opens onto a galaxy that\'s only cats. Gate quickly closed.', (s) => s.buildings.wormhole > 0],
        ['Multiverse Drill meets another you, drilling the other way. Awkward.', (s) => s.buildings.multiverse > 0],
        ['Antimatter and matter meet at a party. Nobody leaves.', (s) => s.crunches > 0],
        ['Universe collapses, starts over. Pluto finally a planet in this one.', (s) => s.crunches > 0],
        ['Breaking: person clicks rock, gets rich. Economists furious.'],
        ['Golden Comet sighted nearby. Experts recommend clicking it.'],
        ['Survey: 9 out of 10 asteroids would rather not be mined.'],
        ['Weather forecast: 40% chance of ore today.'],
        ['Aliens next door ask if they can borrow a cup of ore.'],
        ['Report finds space is "really quite big".'],
        ['Miners name their asteroid Gerald. Gerald has no comment.'],
        ['Your mum called. She wants to know when you\'ll get a real job instead of mining rocks.'],
        ['Pluto still not a planet. Pluto would like everyone to stop bringing it up.'],
        ['Space tourism review: "One star. Nothing to do but rocks."'],
        ['Gravity reported to be working as intended.'],
        ['Teacher confiscates ore from student in the middle of maths.', schoolHours],
        ['Ore found in the school canteen lasagne. Canteen denies everything.', schoolHours],
        ['Rock-paper-scissors tournament on asteroid ends badly for scissors.'],
        ['Historians agree: this is the Ore Age.'],
        ['Comet insurance prices go through the roof.', (s) => s.comets > 0],
        ['Diamond Comet seen. Jewellers everywhere weep.', (s) => s.diamonds > 0],
        ['Auto-Clicker Bot asks for a day off. Request denied: it\'s a robot.', (s) => has(s, 'autoClicker')],
        ['Dark Matter finally found. It was behind the sofa the whole time.', (s) => s.warps > 0],
        ['The galaxy you left behind files a noise complaint about all the warping.', (s) => s.warps > 0],
        ['Stock market crashes as ore becomes worth less than gravel.', (s) => s.allOre >= 1e9],
        ['Billionaire buys a rocket. Ore Mining Inc. buys a thousands.', (s) => s.allOre >= 1e12],

        // The buildings
        ['Space Pickaxe Guy loses his pickaxe. It is now orbiting the asteroid at 3 km/h.', (s) => s.buildings.pickaxe > 0],
        ['Space Pickaxe Guys vote on a team name. "The Rock Botherers" wins by one vote.', (s) => s.buildings.pickaxe > 0],
        ['Space Pickaxe Guy asks about the pension plan. Is told to keep swinging.', (s) => s.buildings.pickaxe > 0],
        ['Space Pickaxe Guy\'s lunchbox drifts off into deep space. Search party sent.', (s) => s.buildings.pickaxe > 0],
        ['So many Space Pickaxe Guys on the asteroid that there\'s now a queue to hit it.', (s) => s.buildings.pickaxe >= 100],
        ['Mining Drone gets stuck in a crater and beeps sadly for three hours.', (s) => s.buildings.drone > 0],
        ['Mining Drones caught racing each other on the night shift.', (s) => s.buildings.drone > 0],
        ['Mining Drone mistakes a passing astronaut for ore. Apologises.', (s) => s.buildings.drone > 0],
        ['Mining Drone update 2.0 adds a "nibble harder" button.', (s) => s.buildings.drone > 0],
        ['Homework eaten by a Mining Drone. Teacher not convinced.', (s) => s.buildings.drone > 0],
        ['Mining Drones now outnumber the stars. They count them at night to make sure.', (s) => s.buildings.drone >= 100],
        ['Laser Drill used to reheat a pizza. The pizza is now a small sun.', (s) => s.buildings.laser > 0],
        ['Laser Drill safety poster: "Do not look into laser with remaining eye."', (s) => s.buildings.laser > 0],
        ['Cats all over the galaxy chase the Laser Drill\'s dot. Production unaffected.', (s) => s.buildings.laser > 0],
        ['Laser Drill operator wins the local laser tag tournament. Banned for life.', (s) => s.buildings.laser > 0],
        ['Laser Drills visible from three galaxies away. Aliens think it\'s a concert.', (s) => s.buildings.laser >= 100],
        ['Mining Rig takes its first steps. Crew cries a little.', (s) => s.buildings.rig > 0],
        ['Mining Rig steps on a smaller Mining Rig. Investigation ongoing.', (s) => s.buildings.rig > 2],
        ['Mining Rig asks for directions to the ore. It is standing on it.', (s) => s.buildings.rig > 0],
        ['Mining Rig gets a parking ticket for standing on the same rock for 400 days.', (s) => s.buildings.rig > 0],
        ['Asteroid Base holds its first quiz night. Every answer is "rock".', (s) => s.buildings.base > 0],
        ['Asteroid Base gym closes after the weights keep floating up to the ceiling.', (s) => s.buildings.base > 0],
        ['Asteroid Base crew: "The view is nice, but it\'s the same view every day."', (s) => s.buildings.base > 0],
        ['Asteroid Base plants its first tree. Tree immediately asks to go home.', (s) => s.buildings.base > 0],
        ['Ore Freighter captain asked to stop doing donuts around Saturn.', (s) => s.buildings.freighter > 0],
        ['Ore Freighter delivery arrives 3 light-years late. Ore still fresh.', (s) => s.buildings.freighter > 0],
        ['Ore Freighter misses the asteroid and delivers ore to the wrong galaxy.', (s) => s.buildings.freighter > 0],
        ['Ore Freighter\'s GPS: "In 400 million kilometres, turn left."', (s) => s.buildings.freighter > 0],
        ['Space Station holds a fire drill. Nobody wants to use the "outside" exit.', (s) => s.buildings.station > 0],
        ['Space Station astronaut films water floating. Video gets 9 billion views.', (s) => s.buildings.station > 0],
        ['Space Station\'s microwave is the most fought-over thing in orbit.', (s) => s.buildings.station > 0],
        ['Space Station flies over the school. Students wave. Station does not wave back.', (s) => s.buildings.station > 0],
        ['Moon Excavator digs up a flag on the Moon. Puts it back, feeling bad.', (s) => s.buildings.excavator > 0],
        ['Moon Excavator finds no cheese at all. Scientists quietly relieved.', (s) => s.buildings.excavator > 0],
        ['Surfers on Earth say the tides are "a bit off" lately.', (s) => s.buildings.excavator > 0],
        ['Moon Excavator drivers asked to stop leaving tyre tracks that spell rude words.', (s) => s.buildings.excavator > 0],
        ['Planet Cracker finds the planet\'s yolk. Ore Mining Inc. makes an omelette.', (s) => s.buildings.cracker > 0],
        ['Astronomers update their star maps for the third time this week.', (s) => s.buildings.cracker > 0],
        ['Planet Cracker cracks the wrong planet. Sends a fruit basket.', (s) => s.buildings.cracker > 0],
        ['People from a cracked planet get 10% off their next planet.', (s) => s.buildings.cracker > 0],
        ['Black Hole Siphon pulls out the TV remote. Nobody wants to know how it got there.', (s) => s.buildings.siphon > 0],
        ['Black Hole Siphon clogged. Plumber refuses to look inside.', (s) => s.buildings.siphon > 0],
        ['Black Hole Siphon worker briefly stretched into spaghetti. Says it tickled.', (s) => s.buildings.siphon > 0],
        ['Time runs slower by the Black Hole Siphon. Workers ask to be paid by the hour.', (s) => s.buildings.siphon > 0],
        ['Star Forge offers a free sunburn to every visitor.', (s) => s.buildings.forge > 0],
        ['Star Forge worker forgets sunscreen. Is now a small star.', (s) => s.buildings.forge > 0],
        ['Star Forge makes a horseshoe the size of Jupiter. Nobody has a horse that big.', (s) => s.buildings.forge > 0],
        ['Star Forge marshmallow night cancelled: no marshmallows survived.', (s) => s.buildings.forge > 0],
        ['Nebula Harvester finds a nebula shaped like a teacher. Miners refuse to touch it.', (s) => s.buildings.nebula > 0],
        ['Space dust allergy season starts early, thanks to the Nebula Harvesters.', (s) => s.buildings.nebula > 0],
        ['Nebula Harvester sneezes. A new star system forms.', (s) => s.buildings.nebula > 0],
        ['Famous telescope picture goes missing. Nebula Harvester looks guilty.', (s) => s.buildings.nebula > 0],
        ['Dyson Swarm panels need cleaning. Window cleaner\'s quote: "a lot".', (s) => s.buildings.dyson > 0],
        ['Star inside the Dyson Swarm asks for a bit of privacy.', (s) => s.buildings.dyson > 0],
        ['Solar panel sellers all over the galaxy retire happy.', (s) => s.buildings.dyson > 0],
        ['From far away, the Dyson Swarm makes the star look like a disco ball.', (s) => s.buildings.dyson > 0],
        ['Wormhole Gate takes a shortcut. Arrives yesterday.', (s) => s.buildings.wormhole > 0],
        ['Wormhole Gate delivers ore before it was mined. Accountants cry.', (s) => s.buildings.wormhole > 0],
        ['Man walks through the Wormhole Gate and comes back with a slightly different haircut.', (s) => s.buildings.wormhole > 0],
        ['Wormhole Gate used to skip Monday. Monday still happens, just somewhere else.', (s) => s.buildings.wormhole > 0],
        ['Multiverse Drill finds a universe where the rocks mine people.', (s) => s.buildings.multiverse > 0],
        ['Multiverse Drill reaches a universe where the asteroid clicks you.', (s) => s.buildings.multiverse > 0],
        ['In one universe you never started mining. That universe is very boring.', (s) => s.buildings.multiverse > 0],
        ['Multiverse Drill finds a universe where Pluto is the only planet. Pluto very smug.', (s) => s.buildings.multiverse > 0],
        ['You from another universe sends a postcard: "The ore is better here."', (s) => s.buildings.multiverse > 0],
        ['There are now more Multiverse Drills than universes. Physicists worried.', (s) => s.buildings.multiverse >= 50],
        ['Asteroid now has more machines on it than rock.', (s) => owned(s) >= 500],
        ['Ore Mining Inc. runs out of names for its buildings. Starts calling them "Steve".', (s) => owned(s) >= 1000],

        // Clicks, comets and achievements
        ['Doctors warn of a new illness: "clicker\'s finger".', (s) => s.clicks >= 1000],
        ['Your mouse asks if it can retire.', (s) => s.clicks >= 10000],
        ['Mouse makers name a factory after you.', (s) => s.clicks >= 100000],
        ['Critical click shakes the asteroid. Nearby miners spill their coffee.', (s) => s.crits > 0],
        ['Scientists study your critical clicks. Conclusion: "very clicky".', (s) => s.crits >= 100],
        ['Comet makes a wish on a shooting star. Wishes not to be clicked.', (s) => s.comets > 0],
        ['Comet asks for directions. Gets clicked.', (s) => s.comets > 0],
        ['Comets complain about being caught so much. They\'re advised to hire a lawyer.', (s) => s.comets >= 10],
        ['Comets now take the long way round your asteroid.', (s) => s.comets >= 100],
        ['Diamond prices crash as someone keeps catching Diamond Comets.', (s) => s.diamonds >= 5],
        ['Everything is suddenly going faster. Experts blame a comet.', (s) => Object.values(s.buffs).some((buff) => buff.until > s.tick)],
        ['Local miner gets an achievement. Tells everyone about it.', (s) => s.achievements.length > 0],
        ['Trophy shelf on the asteroid collapses under the weight.', (s) => s.achievements.length >= 10],

        // Warp Jumps and Big Crunches
        ['Warp Jump leaves behind one very confused Space Pickaxe Guy.', (s) => s.warps > 0],
        ['Dark Matter turns out to be normal matter that\'s just shy.', (s) => s.warps > 0],
        ['Old asteroid sends a message: "Did you mean to leave me?"', (s) => s.warps > 0],
        ['Travel agents ask you to stop warping without telling them.', (s) => s.warps >= 5],
        ['Universe guidebook adds a page about you. It just says "warps a lot".', (s) => s.warps >= 10],
        ['Dark Matter scientists turn off the lights to see it better.', (s) => s.darkUpgrades.length > 0],
        ['Big Crunch survivors start a support group. Meetings are very short.', (s) => s.crunches > 0],
        ['Antimatter touches a sandwich. Sandwich and lunch break both gone.', (s) => s.crunches > 0],
        ['Universe 2: now with 20% fewer mistakes.', (s) => s.crunches > 0],
        ['Antimatter shop opens. Everything is sold backwards.', (s) => s.antiUpgrades.length > 0],
        ['Time keeps starting over. Calendars sold out everywhere.', (s) => s.crunches >= 3],
        ['Big Rip survivors say the multiverse felt "a bit drafty" for a second.', (s) => s.rips > 0],
        ['Strange Matter turns out to be normal matter that went to art school.', (s) => s.rips > 0],
        ['Physicist finds a strangelet in their soup. Soup now stranger than before.', (s) => s.rips > 0],
        ['Multiverse sewn back together. A few universes are on inside out.', (s) => s.rips >= 3],
        ['Space Pickaxe Guys try to climb the skill tree. It\'s not that kind of tree.', (s) => s.skills.length > 0],
        ['Skill tree grows a new branch. Gardeners from three galaxies come to look.', (s) => s.skills.length >= 10],
        ['Autopilot warps the company to a new galaxy. Nobody was told.', (s) => hasSkill(s, 'autoWarp')],
        ['Scientists finally write down the Theory of Everything. It just says "mine more ore".', (s) => hasSkill(s, 'theory')],

        // How much ore you've mined
        ['Start-up miner dreams of owning a whole pickaxe one day.', (s) => s.allOre < 1000],
        ['Ore Mining Inc. hits a million ore. Throws a party on the asteroid.', (s) => s.allOre >= 1e6],
        ['Bank asks if you\'d like to open an ore account.', (s) => s.allOre >= 1e6],
        ['Tax office sends you a letter. It is 9 light-years long.', (s) => s.allOre >= 1e9],
        ['Ore now used as money in 14 galaxies.', (s) => s.allOre >= 1e15],
        ['There\'s officially more ore in your storage than in the universe. Nobody knows how.', (s) => s.allOre >= 1e18],
        ['Your ore pile can now be seen from other dimensions.', (s) => s.allOre >= 1e21],

        // School, night and weekend
        ['Student caught mining ore during an English test. Says it counts as reading.', schoolHours],
        ['Headteacher announces: ore is not a good excuse for missing homework.', schoolHours],
        ['IT department notices one computer using all the school Wi-Fi to mine rocks.', schoolHours],
        ['Teacher asks what everyone is doing. Whole class says "maths".', schoolHours],
        ['Physics teacher confirms: a click is not a unit of force.', schoolHours],
        ['Breaking: the break is over. Get back to class.', schoolHours],
        ['It\'s the middle of the night. The asteroid would like to sleep now.', lateNight],
        ['Night shift Space Pickaxe Guys saw you clicking at this hour. They\'re worried.', lateNight],
        ['Fun fact: the asteroid doesn\'t have a bedtime. You do.', lateNight],
        ['It\'s the weekend. Space Pickaxe Guys ask for double pay.', weekend],
        ['Weekend sale on the asteroid: all rocks 0% off.', weekend],

        // Everything else
        ['Scientists find a new mineral. They call it "more ore".'],
        ['Rock reported missing. It was in your pocket.'],
        ['Space lawyer argues the asteroid has the right to remain silent. Asteroid agrees.'],
        ['Astronaut drops a spoon. Spoon is now a satellite :)'],
        ['Survey: rocks rank "being mined" just above "being skipped across a lake".'],
        ['Galaxy\'s first ore museum opens. It\'s just rocks.'],
        ['Ore prices up 2%, down 2%, up 2% again. Economists take a nap.'],
        ['Alien tourist asks where the gift shop is. Is given a rock.'],
        ['New study: staring at ore for hours is "probably fine".'],
        ['Asteroid belt tightened by one notch.'],
        ['Mars sends a strongly worded letter about "all the drilling".'],
        ['Saturn\'s rings checked for ore. Rings say "excuse me?".'],
        ['Venus too hot to mine. Miners go to the beach instead.'],
        ['Jupiter refuses to be mined. Says it\'s "mostly gas, thank you".'],
        ['Neptune complains that nobody ever visits.'],
        ['Mercury asks everyone to stop calling it "the small hot one".'],
        ['Earth sends the miners a care package: socks and a bag of crisps.'],
        ['Astronomers spot a star shaped like a pickaxe. Miners salute.'],
        ['Space radio has played the same song for 4 years. Nobody can reach the off button.'],
        ['Rock band formed on the asteroid. It\'s literally a band of rocks.'],
        ['Meteor shower forecast: bring an umbrella. A strong one.'],
        ['Space pigeon delivers ore order. Wants to be paid in breadcrumbs.'],
        ['Alien inspector gives the asteroid 4 out of 5 stars. Lost a point for being "too rocky".'],
        ['Report: ore mined today could fill 400 swimming pools. Nobody asked.'],
        ['Rocket fuel prices go up. Miners start pedalling.'],
        ['Robot vacuum sent to clean up space. Still going.'],
        ['Astronaut found after 6 years: "I was just getting some ore."'],
        ['Study shows 100% of rocks are made of rock.'],
        ['Space police pull over an ore truck for going faster than light.'],
        ['Alien kids want ore-flavoured ice cream. Ice cream shop says no.'],
        ['Galactic news: nothing happened today. Apart from all the mining.'],
        ['Ore found inside a smaller ore. Scientists: "It\'s ore all the way down."'],
        ['Astronaut tries to whistle in space. Nothing happens.'],
        ['Moonwalk championship cancelled after everyone floats away.'],
        ['Rock collector asks to be left alone with the asteroid for "just five minutes".'],
        ['Martians offer one Mars for two ore. Deal turned down.'],
        ['Space weather: solar wind from the east, light comet showers in the afternoon.'],
        ['New planet discovered. Already has a "Mining soon" sign on it.'],
        ['Satellite photobombs a telescope picture. Says sorry.'],
        ['Self-help book "Just Keep Clicking" tops the galactic bestseller list.'],
        ['Ore Mining Inc. picks a motto. "We rock" wins.'],
        ['Black holes officially less scary than Monday mornings.'],
        ['Galaxy closed for repairs. Nobody notices.'],
        ['Space ant found carrying 50 times its weight in ore. Hired on the spot.'],
        ['Alien chef serves ore soup. Reviews say "crunchy".'],
        ['Asteroid gets a birthday cake. The candles won\'t stay lit in space.'],
        ['Galactic Rock Board rates your rock "decent".'],
        ['Earth sends its best pickaxe. It\'s a spoon.'],
        ['HB Hub chat goes quiet. Everyone is busy mining ore.'],
        ['Casino offers to swap chips for ore. Ore Mining Inc. says no thanks.'],
        ['Matador players ask if the asteroid is for sale. It\'s not.'],
        ['Chess grandmaster loses to an asteroid. The asteroid didn\'t move once.'],
        ['Alien newspaper runs out of rock puns. Uses "ore-some" again.'],
        ['Hamster on a wheel hired to power the asteroid. Asks for a raise.'],
        ['Shooting star turns out to be a lost Ore Freighter. Wish still counts.'],
        ['Scientists fail to explain why the asteroid smells like toast.'],
        ['UFO sighted near the asteroid. It\'s just a frisbee.'],
        ['Galaxy-wide survey: favourite colour of ore is "ore-ange".'],
        ['Astronaut\'s mum asks if they\'re wearing a warm enough spacesuit.'],
        ['Space spider builds a web between two asteroids. Catches a comet.'],
        ['Alien influencer posts selfie with the asteroid. 3 likes.'],
        ['Asteroid voted "Most Likely to Be Mined" in the galaxy yearbook.'],
        ['Galactic lottery won by a rock. Rock spends it all on some ore.'],
        ['Miner discovers the keyboard. Spacebar mines the asteroid, and the Hot Key Panel upgrade adds more. See How to play.'],
        // The Cosmic Engine, once there's something built
        ['Scientists ask what the Cosmic Engine is for. The engineers say: "You\'ll see."', (s) => s.warps > 0],
        ['Cosmic Engine news: the Scaffolding is up. Machines keep working a little while their miners are away.', (s) => s.engineStage >= 1],
        ['Cosmic Engine news: the Cargo Bay opens. Comets slow down to have a look.', (s) => s.engineStage >= 2],
        ['Cosmic Engine news: Fuel Lines connected. Comets are queuing up to get in.', (s) => s.engineStage >= 3],
        ['Cosmic Engine news: the Crew Deck is done. Badges handed out, snacks still missing.', (s) => s.engineStage >= 4],
        ['Cosmic Engine news: the Foundation Seal holds. Everything mines a bit more, out of pride.', (s) => s.engineStage >= 5],
        ['Cosmic Engine news: the Reactor Ring spins up. Warp Jumps feel a little darker already.', (s) => s.engineStage >= 6],
        ['Cosmic Engine news: Coolant Loops installed. The machines say it\'s nice and chilly in there.', (s) => s.engineStage >= 7],
        ['Cosmic Engine news: Power Grid online. Someone already plugged a kettle into it.', (s) => s.engineStage >= 9],
        ['Cosmic Engine news: Thruster Mounts bolted on. Big Crunches now feel slightly crunchier.', (s) => s.engineStage >= 11],
        // The Exhaust Nozzle's perk: lines that only come once it's done
        ['Cosmic Engine news: the Exhaust Nozzle works. Neighbouring galaxies complain about the noise.', (s) => s.engineStage >= 14],
        ['Engine Crew union demands a second Exhaust Nozzle "for symmetry".', (s) => s.engineStage >= 14],
        ['The Cosmic Engine burps. Three nebulas are now slightly warmer.', (s) => s.engineStage >= 14],
        ['Tourists queue for selfies with the Cosmic Engine. It does not smile back.', (s) => s.engineStage >= 14],
        ['Engine inspector finds a Space Pickaxe Guy asleep in the Plasma Chamber. He says it\'s "cosy".', (s) => s.engineStage >= 14],
        ['Cosmic Engine news: the Star Charts are in. Nobody can fold them back up.', (s) => s.engineStage >= 16],
        ['Cosmic Engine news: the Bridge has a captain\'s chair now. Everyone wants to sit in it.', (s) => s.engineStage >= 19],
        ['Cosmic Engine news: the Countdown Clock is ticking. Very loudly. All night.', (s) => s.engineStage >= 23],
        ['Cosmic Engine news: Launch Clamps fitted. The Engine is straining to go somewhere.', (s) => s.engineStage >= 24],
        ['IGNITION! The Cosmic Engine roars to life. The universe will need a moment.', (s) => s.engineStage >= 25],
        ['Scientists confirm: stacking Anomalies is "not recommended". Miners stack them anyway.', (s) => s.engineStage >= 14],
        ['Survivor of The Gauntlet asked how it was. Just stares into the distance.', (s) => s.engineStage >= 14],
    ];
    let lastNews = -1;

    function renderTicker() {
        if (engineNews !== null) { // a stage just done (adoptEngine()) goes first
            ticker.textContent = engineNews;
            engineNews = null;
            ticker.classList.remove('is-new');
            void ticker.offsetWidth;
            ticker.classList.add('is-new');
            return;
        }
        const choices = NEWS.map((news, index) => index).filter((index) => index !== lastNews && (!NEWS[index][1] || NEWS[index][1](state)));
        lastNews = choices[Math.floor(Math.random() * choices.length)];
        ticker.textContent = NEWS[lastNews][0];
        ticker.classList.remove('is-new');
        void ticker.offsetWidth;
        ticker.classList.add('is-new');
    }
    setInterval(() => {
        if (!isPlaying || document.hidden) return;
        step('news ticker');
        renderTicker();
        step(null);
    }, 9000);

    // ----- Sounds, made here rather than loaded. Browsers only let a page play sound once it's been clicked.

    const SOUND_KEY = 'hbHubMinerSound';
    let isSoundOn = true;
    try {
        isSoundOn = localStorage.getItem(SOUND_KEY) !== 'off';
    } catch {
        // no storage (a private window): the sound is just on
    }
    let audio = null;
    let lastClickSound = 0;

    function wakeAudio() {
        try {
            audio ??= new AudioContext();
            if (audio.state === 'suspended') audio.resume();
        } catch {
            audio = null; // no Web Audio: no sounds
        }
    }

    // One note: [frequency, starts after (s), lasts (s), wave, volume, slides to frequency]
    function tone([frequency, after, length, wave = 'sine', volume = 0.2, slideTo = null]) {
        const now = audio.currentTime + after;
        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.type = wave;
        oscillator.frequency.setValueAtTime(frequency, now);
        if (slideTo) oscillator.frequency.exponentialRampToValueAtTime(slideTo, now + length);
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(volume, now + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + length);
        oscillator.connect(gain).connect(audio.destination);
        oscillator.start(now);
        oscillator.stop(now + length + 0.05);
    }

    const SOUNDS = {
        click: [[200, 0, 0.07, 'triangle', 0.12, 90]],
        crit: [[520, 0, 0.12, 'square', 0.08, 1040], [200, 0, 0.08, 'triangle', 0.12, 90]],
        buy: [[660, 0, 0.1, 'sine', 0.15], [990, 0.07, 0.14, 'sine', 0.15]],
        comet: [[1200, 0, 0.3, 'sine', 0.06], [1500, 0.12, 0.3, 'sine', 0.06], [1800, 0.24, 0.4, 'sine', 0.06]],
        catch: [[523, 0, 0.15, 'triangle', 0.2], [659, 0.08, 0.15, 'triangle', 0.2], [784, 0.16, 0.15, 'triangle', 0.2], [1047, 0.24, 0.35, 'triangle', 0.2]],
        achievement: [[784, 0, 0.18, 'square', 0.07], [988, 0.12, 0.18, 'square', 0.07], [1175, 0.24, 0.4, 'square', 0.07]],
        warp: [[80, 0, 1.2, 'sawtooth', 0.1, 2400], [120, 0.1, 1.1, 'sine', 0.15, 3000]],
        rip: [[1600, 0, 0.9, 'sawtooth', 0.08, 50], [220, 0.05, 0.5, 'square', 0.05, 1800], [60, 0.4, 1.1, 'sine', 0.18, 30]],
        // The Cosmic Engine (once the Exhaust Nozzle is done): a clunk and a hiss when it gets parts, a roar for a stage
        engine: [[90, 0, 0.18, 'square', 0.08, 60], [140, 0.1, 0.25, 'triangle', 0.1, 70], [2400, 0.15, 0.3, 'sawtooth', 0.02, 1200]],
        engineStage: [[55, 0, 1.6, 'sawtooth', 0.12, 220], [110, 0.2, 1.4, 'square', 0.06, 440], [880, 0.9, 0.6, 'sine', 0.1, 1760]],
    };

    function sound(name) {
        if (!isSoundOn) return;
        if (name === 'click' || name === 'crit') {
            const now = performance.now();
            if (now - lastClickSound < 35) return;
            lastClickSound = now;
        }
        wakeAudio();
        if (audio === null) return;
        SOUNDS[name].forEach(tone);
    }

    function renderSound() {
        soundButton.setAttribute('aria-pressed', String(isSoundOn));
        soundButton.title = isSoundOn ? 'Sound on' : 'Sound off';
    }

    soundButton.addEventListener('click', () => {
        isSoundOn = !isSoundOn;
        try {
            localStorage.setItem(SOUND_KEY, isSoundOn ? 'on' : 'off');
        } catch {
            // only kept until the page is closed
        }
        renderSound();
        sound('buy');
    });
    renderSound();
    document.addEventListener('pointerdown', wakeAudio, { once: true });
    document.addEventListener('keydown', wakeAudio, { once: true });

    // ----- Clicking the asteroid: a floating number where it was clicked and bits of rock flying off

    function burst(x, y, ore, isCrit) {
        // Plenty on screen already, or none to see (mined with Space from another view, where they'd never finish
        // animating and so never go away)
        if (field.childElementCount > 120 || views.ore.hidden) return;
        const number = element('span', 'miner-float' + (isCrit ? ' is-crit' : ''), '+' + format(ore, { decimals: true }) + (isCrit ? ' CRIT!' : ''));
        number.style.setProperty('--x', x + 'px');
        number.style.setProperty('--y', y + 'px');
        number.addEventListener('animationend', () => number.remove());
        field.append(number);
        if (reduceMotion.matches) return;

        for (let i = 0; i < (isCrit ? 10 : 5); i++) {
            const bit = element('span', 'miner-bit');
            const angle = Math.random() * Math.PI * 2;
            const distance = 40 + Math.random() * (isCrit ? 120 : 70);
            bit.style.setProperty('--x', x + 'px');
            bit.style.setProperty('--y', y + 'px');
            bit.style.setProperty('--dx', Math.cos(angle) * distance + 'px');
            bit.style.setProperty('--dy', Math.sin(angle) * distance + 'px');
            bit.style.setProperty('--size', 4 + Math.random() * 6 + 'px');
            bit.addEventListener('animationend', () => bit.remove());
            field.append(bit);
        }
    }

    // ----- Spotting auto-clickers. The server can't (all it gets is how many clicks there were each second), so the page
    // looks at each click as it happens and marks the ones that look like an auto-clicker's, which then only mine
    // autoValue (the click action's 'auto'). A click does when:
    // - it isn't from a person at all (isTrusted is false: a script or an extension clicked the asteroid), or
    // - it ends AUTO_CLICKS clicks in a row (each less than AUTO_GAP_MS after the one before) that were all pressed for
    //   less than AUTO_HOLD_MS (an auto-clicker presses and lets go at once, a finger can't), or that all came at the
    //   same pace (the gaps between them within AUTO_STEADY_MS of each other) without the pointer moving a pixel.
    // So one human-looking click, or a second's break, starts it over. None of it is saved or sent anywhere.
    const AUTO_CLICKS = 40;
    const AUTO_GAP_MS = 1000;
    const AUTO_HOLD_MS = 10;
    const AUTO_STEADY_MS = 20;

    asteroid.addEventListener('pointerdown', (event) => {
        press = { id: event.pointerId, at: event.timeStamp };
    });
    asteroid.addEventListener('pointerup', (event) => {
        if (press?.id === event.pointerId) lastHold = event.timeStamp - press.at;
        press = null;
    });

    // Whether a click (or the Space press that mined) looks like an auto-clicker's. fromKeyboard: it had no pointer.
    function looksAuto(event, fromKeyboard) {
        const hold = fromKeyboard ? null : lastHold;
        lastHold = null;
        if (!event.isTrusted) return true;

        const at = event.timeStamp;
        if (recentClicks.length > 0 && at - recentClicks[recentClicks.length - 1].at >= AUTO_GAP_MS) recentClicks = [];
        recentClicks.push({ at, hold, x: fromKeyboard ? null : event.clientX, y: fromKeyboard ? null : event.clientY });
        if (recentClicks.length > AUTO_CLICKS) recentClicks.shift();
        if (recentClicks.length < AUTO_CLICKS) return false;

        if (recentClicks.every((recent) => recent.hold !== null && recent.hold < AUTO_HOLD_MS)) return true;
        const first = recentClicks[0];
        if (!recentClicks.every((recent) => recent.x === first.x && recent.y === first.y)) return false;
        const gaps = recentClicks.slice(1).map((recent, i) => recent.at - recentClicks[i].at);
        return Math.max(...gaps) - Math.min(...gaps) <= AUTO_STEADY_MS;
    }

    // Mines once, for a click on the asteroid or Space (event is the click or the keydown)
    function mine(event, fromKeyboard) {
        const auto = looksAuto(event, fromKeyboard);

        // Only as many clicks as the server counts (clicksPerSecond, with a second's worth to start with)
        const now = performance.now();
        clickTokens = Math.min(CONFIG.clicksPerSecond, clickTokens + (now - clickTokensAt) / 1000 * CONFIG.clicksPerSecond);
        clickTokensAt = now;
        if (clickTokens < 1) return;
        clickTokens--;

        const result = act(auto ? { type: 'click', count: 1, auto: true } : { type: 'click', count: 1 });
        if (!result) return;
        if (auto && !wasAuto) {
            toast('robot', 'Hey! Looks like you\'re using an auto-clicker, so your clicks are only worth '
                + CONFIG.autoValue * 100 + '% now.', '', 9000);
        }
        wasAuto = auto;
        showOre();

        // Behind: the clicks are coming in faster than this computer can show them (an auto-clicker on a slow one), so
        // they only count until it's caught up. The browser always handles clicks waiting before its timers, so without
        // this they piled up and the game's timers never got a turn: the store, Auto Buy and the watchdog's beat all
        // stopped, which looked like a freeze (crash report #3)
        // Slow to draw (under 1000 / SLOW_DRAW_MS frames a second): the bits flying off each click are much of what it
        // has to draw, so they wait until it's quicker again (crash reports #4 and #6, at about 14 frames a second)
        if (performance.now() - event.timeStamp > CLICK_BEHIND_MS || frameGap > SLOW_DRAW_MS) return;
        const box = fieldBox();
        const x = fromKeyboard ? box.width / 2 : event.clientX - box.left;
        const y = fromKeyboard ? box.height / 2 : event.clientY - box.top;
        burst(x, y, result.mined, result.crits > 0);
        sound(result.crits > 0 ? 'crit' : 'click');
        // The shake, started over: switching to its other name does that (see .is-hit-again in the CSS)
        const hit = asteroid.classList.contains('is-hit') ? 'is-hit-again' : 'is-hit';
        asteroid.classList.remove('is-hit', 'is-hit-again');
        asteroid.classList.add(hit);
    }

    const CLICK_BEHIND_MS = 100; // a click handled this long after it happened: mine() is behind
    const SLOW_DRAW_MS = 50; // this long between frames: the page is slow to draw (under 20 frames a second)

    // Where the picture is on the screen, for the clicks' bursts. Kept, because asking for it right after the last
    // click's bits were added makes the browser lay the whole page out there and then, which on a slow computer took
    // most of a click's time; forgotten a few times a second (the tick below) and when the page scrolls or resizes.
    let fieldBoxKept = null;
    const fieldBox = () => (fieldBoxKept ??= field.getBoundingClientRect());
    const forgetFieldBox = () => {
        fieldBoxKept = null;
    };
    addEventListener('resize', forgetFieldBox);
    addEventListener('scroll', forgetFieldBox, { capture: true, passive: true });

    asteroid.addEventListener('click', (event) => mine(event, event.detail === 0));

    // Holding Enter on the asteroid would click it over and over, so like Space, only the first press counts
    asteroid.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.repeat) event.preventDefault();
    });

    // ----- The store

    amounts.addEventListener('click', (event) => {
        const button = event.target.closest('[data-amount]');
        if (!button) return;
        buyAmount = parseAmount(button.dataset.amount);
        renderStore();
    });

    keySwitch.addEventListener('click', () => {
        setLateKeys(!lateKeys);
        sound('buy');
    });

    function setLateKeys(isOn) {
        lateKeys = isOn;
        try {
            localStorage.setItem(LATE_KEYS_KEY, isOn ? 'on' : 'off');
        } catch {
            // only kept until the page is closed
        }
        renderStore();
    }

    autoSwitch.addEventListener('click', () => {
        setAutoBuying(!isAutoBuying);
        sound('buy');
    });

    function setAutoBuying(isOn) {
        isAutoBuying = isOn;
        note('Auto Buy ' + (isOn ? 'on' : 'off'));
        try {
            localStorage.setItem(AUTO_BUY_KEY, isOn ? 'on' : 'off');
        } catch {
            // only kept until the page is closed
        }
        renderStore();
    }

    // The shown building that adds the most ore per second for each ore it costs, of those that can be afforded amount at
    // a time, as {id, cost, gain (how much the ore per second goes up)}, or null. The gain is how much the whole steady
    // rate goes up, so crew, synergy and click-share upgrades count too. The B key and Auto Buy both pick with this.
    // amount can be 'max' too (storeAmount()). parts: rateParts(state), when it's already been worked out; each building
    // is tried on top of it.
    function bestBuilding(amount, parts = rateParts(state)) {
        const before = rate(state, Infinity, parts);
        const synergies = rateSynergies(state);
        let best = null;
        for (const id of visibleBuildings().shown) {
            const count = storeAmount(id, amount);
            const cost = buildingCost(state, id, count);
            if (!canAfford(state, cost) || !canBuild(state, id, count)) continue;
            const after ={ ...state, buildings: { ...state.buildings, [id]: state.buildings[id] + count } };
            const counted = synergies.get(id);
            const gain = rate(after, Infinity, counted ? ratePartsRecounted(after, parts, counted) : parts) - before;
            if (!best || gain / cost > best.gain / best.cost) best = { id, cost, gain };
        }
        return best;
    }

    // ----- Auto Buy (the Dark Matter upgrade): while it's switched on, 10 times a second it buys whatever pays for
    // itself soonest. The choices are the building the B key would buy (one at a time) and every upgrade in the store,
    // affordable or not. Each one's payback time is how long until it can be afforded (at the ore per second now) plus
    // how long the ore per second it adds takes to earn its price back. When the best is an upgrade that can't be
    // afforded yet, it waits and saves up for it instead of spending the ore on buildings that would only slow it down.
    // Upgrades that don't add to the steady ore per second (clicks without auto-clickers, comets, hot keys, Bulk
    // Buying) are bought once they cost no more than AUTO_BUY_EXTRA_SECONDS of production. It buys through act(), like a
    // click on the store would, so the server checks every purchase as usual.
    const AUTO_BUY_EXTRA_SECONDS = 60;
    const AUTO_BUY_PER_TICK = 15; // at most, so a full wallet after a Warp Jump doesn't send hundreds of actions at once

    // What Auto Buy should do now: {type: 'buy', ...} or {type: 'upgrade', ...} (the action to take), or null to wait
    function autoBuyChoice() {
        const parts = rateParts(state);
        const before = rate(state, Infinity, parts);
        const building = bestBuilding(1, parts);
        let best = building && building.gain > 0 ? { action: { type: 'buy', building: building.id, amount: 1 }, payback: building.cost / building.gain } : null;
        for (const id of availableUpgrades()) {
            const cost = upgradeCost(state, id);
            const gain = rate(state, Infinity, ratePartsWith(state, parts, id)) - before;
            if (gain <= 0) {
                if (canAfford(state, cost) && cost <= before * AUTO_BUY_EXTRA_SECONDS) return { type: 'upgrade', id };
                continue;
            }
            const payback = (canAfford(state, cost) ? 0 : (cost - state.ore) / before) + cost / gain; // Infinity with no production yet
            if (!best || payback < best.payback) best = { action: { type: 'upgrade', id }, payback };
        }
        if (!best || (best.action.type === 'upgrade' && !canAfford(state, upgradeCost(state, best.action.id)))) return null;
        return best.action;
    }

    // Auto Buy only ever has one message in the corner. autoBuyToast is it (null when there's none showing) and
    // autoBuyToastCount how many upgrades it's about: one shows that upgrade like before, more shows "Auto Buy bought 3
    // upgrades", and a new upgrade while it's still up replaces it with the count one higher.
    let autoBuyToast = null;
    let autoBuyToastCount = 0;

    function autoBuy() {
        if (!isAutoBuying || !hasDark(state, 'autoBuy')) return;
        catchUp();
        const bought = []; // the upgrades bought this time, for the message
        for (let i = 0; i < AUTO_BUY_PER_TICK; i++) {
            const action = autoBuyChoice();
            if (!action || !act(action)) break;
            if (action.type === 'upgrade') bought.push(action.id);
        }
        if (!bought.length) return;
        const isStillShowing = autoBuyToast && autoBuyToast.isConnected && !autoBuyToast.classList.contains('is-leaving');
        autoBuyToastCount = (isStillShowing ? autoBuyToastCount : 0) + bought.length;
        if (autoBuyToast) autoBuyToast.remove();
        const upgrade = UPGRADES[bought[0]];
        autoBuyToast = autoBuyToastCount === 1
            ? toast(upgrade.icon, 'Auto Buy: ' + upgrade.name + ': ' + upgrade.text)
            : toast('autoBuy', 'Auto Buy bought ' + autoBuyToastCount + ' upgrades');
    }

    buildingList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-building]');
        if (!button) return;
        if (act({ type: 'buy', building: button.dataset.building, amount: storeAmount(button.dataset.building) })) {
            sound('buy');
            renderStore();
        }
    });

    upgradeList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-upgrade]');
        if (!button) return;
        if (act({ type: 'upgrade', id: button.dataset.upgrade })) {
            sound('buy');
            toast(UPGRADES[button.dataset.upgrade].icon, UPGRADES[button.dataset.upgrade].name + ': ' + UPGRADES[button.dataset.upgrade].text);
            renderStore();
            if (button.dataset.upgrade === 'quickKeys') showKeysHelp();
        }
    });

    /**
     * The first time the Hot Key Panel is bought in this browser, a popup says what the keys do, with a link to the
     * whole list in the docs. Not on touch screens (no keyboard to use them with, like the number keys in the store), and
     * not while another popup is open. Only from a click in the store: Auto Buy doesn't stop the game for it.
     */
    function showKeysHelp() {
        if (!matchMedia('(hover: hover)').matches || document.querySelector('dialog[open]')) return;
        try {
            if (localStorage.getItem(KEYS_HELP_KEY) === 'seen') return;
            localStorage.setItem(KEYS_HELP_KEY, 'seen');
        } catch {
            return; // no storage: it would pop up every time, so it's left out
        }
        keysDialog.showModal();
    }

    keysDialog.addEventListener('click', (event) => {
        if (event.target === keysDialog || event.target.closest('[data-miner-keys-close]')) keysDialog.close();
    });

    // Pointing the mouse at the arrow opens all the upgrades, and they stay open until the mouse leaves them. Only for a
    // mouse: a tap also sends pointer events, and the click right after would close them again. Clicking (or a tap, or
    // Enter) opens and closes them too.
    upgradesMore.addEventListener('pointerenter', (event) => {
        if (event.pointerType === 'mouse') setUpgradesOpen(true);
    });

    upgradesBox.addEventListener('pointerleave', (event) => {
        if (event.pointerType === 'mouse') setUpgradesOpen(false);
    });

    upgradesMore.addEventListener('click', () => setUpgradesOpen(!upgradesBox.classList.contains('is-open')));

    darkList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dark]');
        if (!button) return;
        if (act({ type: 'dark', id: button.dataset.dark })) {
            sound('buy');
            if (button.dataset.dark === 'lateKeys') setLateKeys(true); // bought to be used, so it starts on
            if (button.dataset.dark === 'autoBuy') setAutoBuying(true); // same
            toast(DARK_UPGRADES[button.dataset.dark].icon, DARK_UPGRADES[button.dataset.dark].name + ': ' + DARK_UPGRADES[button.dataset.dark].text);
            renderWarp();
            renderStore();
            saveSoon();
        }
    });

    // ----- Comets

    cometButton.addEventListener('click', () => takeComet(false));

    // Catches the comet on screen, from a click (or the C key) or from Comet Catcher (isAuto)
    function takeComet(isAuto) {
        const result = act({ type: 'comet' });
        cometButton.hidden = true;
        if (!result) return;
        const comet = COMETS[result.kind];
        sound('catch');
        toast(isAuto ? 'cometCatcher' : result.kind === 'diamond' ? 'diamond' : 'comet',
            (isAuto ? 'Comet Catcher: ' : '') + COMET_TEXT[result.kind](comet.ms > 0 ? cometLength(state, result.kind) : 0, result.ore, comet.multiplier * cometPower(state)),
            'is-comet is-' + result.kind, 6000);
        renderBuffs();
        renderStore();
    }

    // ----- Comet Catcher (the Antimatter upgrade): a few times a second, once a Golden Comet has been on screen for
    // AUTO_COMET_DELAY_MS (so it's seen flying in first), it catches it through act(), like a click would, so the server
    // checks it as usual. Diamond Comets are left for the player. Only while the page is open and shown, like Auto Buy.
    const AUTO_COMET_DELAY_MS = 1500;

    function autoCatchComet() {
        if (!hasAnti(state, 'cometCatcher') || cometButton.hidden || shownComet !== state.comet.n) return;
        if (performance.now() - shownCometAt < AUTO_COMET_DELAY_MS || cometKind(state) === 'diamond') return;
        takeComet(true);
    }

    // ----- Warp Jump

    warpButton.addEventListener('click', () => {
        const gain = warpGain(state);
        if (gain < 1) return;
        const keepSkills = state.skills.filter((id) => SKILLS[id].type === 'keep').map((id) => 'what ' + SKILLS[id].name + ' keeps');
        const kept = [...(hasDark(state, 'memory') ? ['your click upgrades'] : []), ...(hasAnti(state, 'eternal') ? ['Bulk Buying and your comet upgrades'] : []),
            ...keepSkills];
        warpDialogText.textContent = 'You\'ll get ' + format(gain) + ' Dark Matter, which makes everything mine '
            + format(darkBonus(state) * 100 * gain, { decimals: true }) + '% faster until the next Big Crunch. Your ore, buildings and upgrades start over'
            + (kept.length > 0 ? ', but you keep ' + listText(kept) + '.' : '.');
        warpDialog.showModal();
    });
    root.querySelector('[data-miner-warp-cancel]').addEventListener('click', () => warpDialog.close());
    root.querySelector('[data-miner-warp-confirm]').addEventListener('click', () => {
        warpDialog.close();
        doWarp(false);
    });

    // Plays the "everything jumps" animation on the views: is-warping, is-crunching or is-ripping on the page
    function shake(className) {
        root.classList.remove('is-warping', 'is-crunching', 'is-ripping');
        void root.offsetWidth;
        root.classList.add(className);
    }

    // A Warp Jump, from the box (isAuto false) or Autopilot (quieter: no sound or animation, as it can be every few
    // seconds). Jumps from the box straight after each other (hot keys held by a macro) only get the sound and shake
    // once every WARP_FX_GAP_MS: each is a second of sound piling up, and the shake makes the browser lay the page out
    // again there and then.
    const WARP_FX_GAP_MS = 500;
    let lastWarpFxAt = -Infinity;

    function doWarp(isAuto) {
        const gain = act({ type: 'warp' });
        if (!gain) return;
        if (!isAuto && performance.now() - lastWarpFxAt >= WARP_FX_GAP_MS) {
            lastWarpFxAt = performance.now();
            sound('warp');
            shake('is-warping');
        }
        toast('warp', (isAuto ? 'Autopilot: a' : 'Welcome to a') + ' new galaxy! You got ' + format(gain) + ' Dark Matter.', 'is-comet', isAuto ? 4000 : 7000);
        renderAll(!isAuto);
        saveSoon();
    }

    // ----- Big Crunch

    antiList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-anti]');
        if (!button) return;
        if (act({ type: 'anti', id: button.dataset.anti })) {
            sound('buy');
            toast(ANTI_UPGRADES[button.dataset.anti].icon, ANTI_UPGRADES[button.dataset.anti].name + ': ' + ANTI_UPGRADES[button.dataset.anti].text);
            renderCrunch();
            renderStore();
            saveSoon();
        }
    });

    crunchButton.addEventListener('click', () => {
        const gain = crunchGain(state);
        if (gain < 1) return;
        const percent = format((CONFIG.antiBonus + skillEffects(state).antiBonus) * 100 * gain);
        crunchDialogText.textContent = 'You\'ll get ' + format(gain) + ' Antimatter, which makes everything mine '
            + percent + '% faster and Warp Jumps give ' + percent
            + '% more Dark Matter, until the next Big Rip. Your ore, buildings, upgrades and Dark Matter start over'
            + (hasAnti(state, 'echo') ? ', but you keep your Dark Matter upgrades.' : ', and so do your Dark Matter upgrades.');
        crunchDialog.showModal();
    });
    root.querySelector('[data-miner-crunch-cancel]').addEventListener('click', () => crunchDialog.close());
    root.querySelector('[data-miner-crunch-confirm]').addEventListener('click', () => {
        crunchDialog.close();
        doCrunch(false);
    });

    // A Big Crunch, from the box or Auto Crunch (quieter, like doWarp())
    function doCrunch(isAuto) {
        const gain = act({ type: 'crunch' });
        if (!gain) return;
        if (!isAuto) {
            sound('warp');
            shake('is-crunching');
        }
        toast('bigCrunch', (isAuto ? 'Auto Crunch: the' : 'The') + ' universe collapsed and started over. You got ' + format(gain) + ' Antimatter.',
            'is-comet', isAuto ? 4000 : 7000);
        renderAll(!isAuto);
        saveSoon();
    }

    // ----- Autopilot and Auto Crunch (the skills): while one is switched on, a few times a second it Warp Jumps once a
    // jump would make the Dark Matter earned in this universe at least autoWarpTimes as much, or Big Crunches once a
    // crunch would make the Antimatter earned in this multiverse at least autoCrunchTimes as much (the boxes next to the
    // switches). Through act(), like the buttons, so the server checks them as usual. Only while the page is open, like
    // Auto Buy.
    function autoPrestige() {
        if (isNoAuto()) return; // off in Tier II Anomalies
        const effects = skillEffects(state);
        if (isAutoCrunching && effects.autoCrunch) {
            catchUp();
            const gain = crunchGain(state);
            if (gain >= 1 && gain >= state.antimatterEarned * (autoCrunchTimes - 1)) {
                doCrunch(true);
                return;
            }
        }
        if (isAutoWarping && effects.autoWarp) {
            catchUp();
            const gain = warpGain(state);
            if (gain >= 1 && gain >= state.darkEarned * (autoWarpTimes - 1)) doWarp(true);
        }
    }

    function setAutoWarping(isOn) {
        isAutoWarping = isOn;
        note('Autopilot ' + (isOn ? 'on' : 'off'));
        try {
            localStorage.setItem(AUTO_WARP_KEY, isOn ? 'on' : 'off');
        } catch {
            // only kept until the page is closed
        }
        renderWarp();
    }

    function setAutoCrunching(isOn) {
        isAutoCrunching = isOn;
        note('Auto Crunch ' + (isOn ? 'on' : 'off'));
        try {
            localStorage.setItem(AUTO_CRUNCH_KEY, isOn ? 'on' : 'off');
        } catch {
            // only kept until the page is closed
        }
        renderCrunch();
    }

    autoWarpSwitch.addEventListener('click', () => {
        setAutoWarping(!isAutoWarping);
        sound('buy');
    });
    autoCrunchSwitch.addEventListener('click', () => {
        setAutoCrunching(!isAutoCrunching);
        sound('buy');
    });

    // The "x4" boxes: a number of 2 or more is kept (in this browser, like the switches); anything else puts the old
    // one back. onTimes gets the new number.
    function bindTimesBox(box, times, key, name, onTimes) {
        const input = box.querySelector('input');
        input.min = String(AUTO_PRESTIGE_MIN_TIMES);
        input.value = String(times);
        input.addEventListener('change', () => {
            const newTimes = readTimes(input.value.trim());
            if (newTimes === null) {
                input.value = String(times);
                return;
            }
            times = newTimes;
            input.value = String(times);
            onTimes(times);
            note(name + ' at x' + times);
            try {
                localStorage.setItem(key, String(times));
            } catch {
                // only kept until the page is closed
            }
        });
    }

    bindTimesBox(autoWarpTimesBox, autoWarpTimes, AUTO_WARP_TIMES_KEY, 'Autopilot', (times) => { autoWarpTimes = times; });
    bindTimesBox(autoCrunchTimesBox, autoCrunchTimes, AUTO_CRUNCH_TIMES_KEY, 'Auto Crunch', (times) => { autoCrunchTimes = times; });

    // ----- Big Rip and the skill tree

    ripButton.addEventListener('click', () => {
        const gain = ripGain(state);
        if (gain < 1) return;
        ripDialogText.textContent = 'You\'ll get ' + format(gain) + ' Strange Matter to spend on skills, and it makes everything mine '
            + format(CONFIG.strangeBonus * 100 * gain) + '% faster for good. Your ore, buildings, upgrades, Dark Matter and Antimatter start over'
            + (skillEffects(state).keepAnti ? ', but you keep your Antimatter upgrades.' : ', and so do your Antimatter upgrades.');
        ripDialog.showModal();
    });
    root.querySelector('[data-miner-rip-cancel]').addEventListener('click', () => ripDialog.close());
    root.querySelector('[data-miner-rip-confirm]').addEventListener('click', () => {
        ripDialog.close();
        const gain = act({ type: 'rip' });
        if (!gain) return;
        sound('rip');
        shake('is-ripping');
        toast('bigRip', 'The multiverse tore apart and started over. You got ' + format(gain) + ' Strange Matter.', 'is-comet', 7000);
        renderAll();
        saveSoon();
    });

    // A skill opens its panel (or closes it, if it's the one already open). The panel closes again with its ×, Escape
    // or a click anywhere outside it and the skills.
    tree.addEventListener('click', (event) => {
        const button = event.target.closest('[data-skill]');
        if (!button) return;
        pickSkill(button.dataset.skill === pickedSkill ? null : button.dataset.skill);
    });
    root.querySelector('[data-miner-skill-close]').addEventListener('click', () => {
        const button = treeNodes[pickedSkill];
        pickSkill(null);
        button?.focus();
    });
    document.addEventListener('click', (event) => {
        // A target that's no longer on the page was replaced by the click itself (the panel's icon), so it was inside
        if (pickedSkill === null || !event.target.isConnected || event.target.closest('[data-miner-skill-info], [data-skill]')) return;
        pickSkill(null);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || pickedSkill === null || document.querySelector('dialog[open]')) return;
        const button = treeNodes[pickedSkill];
        const wasInside = skillPanel.contains(document.activeElement);
        pickSkill(null);
        if (wasInside) button.focus();
    });
    addEventListener('resize', placeSkillInfo);
    treeScroll.addEventListener('scroll', placeSkillInfo, { passive: true });

    skillInfo.learn.addEventListener('click', () => {
        const id = pickedSkill;
        if (!act({ type: 'skill', id })) return;
        const skill = SKILLS[id];
        sound('buy');
        if (skill.type === 'autoWarp') setAutoWarping(true); // learned to be used, so it starts on, like Auto Buy
        if (skill.type === 'autoCrunch') setAutoCrunching(true);
        toast(skill.icon, skill.name + ': ' + skill.text);
        renderAll(false);
        saveSoon();
    });

    // ----- The Cosmic Engine (the Engine view): the stage being built and its lock, what you can give it, today's
    // Engine Shifts, every stage with its perk, the top contributors and the latest parts given. engine is the
    // server's, from every save's answer (hbHubMinerEngineSummary() in assets/php/games/spaceOreMiner/engine.php: number,
    // done, parts, total, costs, finished, open, lock); top, feed and mine only come while this view is open
    // (engine=1 in save()), and the last ones are kept until the next.

    const engineView = root.querySelector('[data-miner-engine-view]');
    const engineNumbers = Object.fromEntries([...root.querySelectorAll('[data-miner-engine]')].map((node) => [node.dataset.minerEngine, node]));
    const engineProgress = root.querySelector('[data-miner-engine-progress]');
    const engineLock = root.querySelector('[data-miner-engine-lock]');
    const engineGiveNote = root.querySelector('[data-miner-engine-give-note]');
    const oreDonateButton = root.querySelector('[data-miner-donate="ore"]');
    const strangeDonateButton = root.querySelector('[data-miner-donate="strange"]');
    const strangeDonateCount = root.querySelector('[data-miner-donate-count]');
    const shiftList = root.querySelector('[data-miner-shifts]');
    const shiftNote = root.querySelector('[data-miner-shifts-note]');
    const engineStageList = root.querySelector('[data-miner-engine-stages]');
    const engineTop = root.querySelector('[data-miner-engine-top]');
    const engineFeed = root.querySelector('[data-miner-engine-feed]');
    const SOURCE_TEXT = { [CONFIG.sourceOre]: 'ore', [CONFIG.sourceStrange]: 'Strange Matter', [CONFIG.sourceShift]: 'an Engine Shift' };

    let engine = null;
    let engineListsKey = '';
    let shiftsKey = '';
    let engineNews = null; // a stage just done, for the news ticker's next line (see renderTicker())

    // A new answer from the server. A stage done since the one before gets a toast and the ticker straight away (not
    // on the first answer, which only says how far it is).
    function adoptEngine(next) {
        if (engine && next.number === engine.number && next.done > engine.done) {
            for (let stage = engine.done + 1; stage <= next.done && stage <= ENGINE_STAGES.length; stage++) {
                const { name, perk } = ENGINE_STAGES[stage - 1];
                toast('starForge', 'The Cosmic Engine\'s ' + name + ' is finished! ' + perk.text, 'is-welcome', 9000);
                engineNews = 'Cosmic Engine news: the ' + name + ' is finished. ' + perk.text;
            }
            if (engineEffectsOn('news')) sound('engineStage');
            renderTicker();
        }
        engine = engine && next.number === engine.number ? { ...engine, ...next } : next;
        engineListsKey = '';
    }

    // Whether the player can give parts from their own game right now: not from inside an Anomaly
    const canGiveNow = () => canGive(saveState) && !saveState.anomaly;

    // Whether the perk of 'type' that isn't a number (news, skin, title, ...) is done, so everyone has it
    const engineEffectsOn = (type) => ENGINE_STAGES.slice(0, saveState?.engineStage ?? 0).some((stage) => stage.perk.type === type);

    // What was just given (apply()'s given): a toast for each Shift done (donations and Anomalies have their own), and
    // the parts on the bar straight away, until the next save says how far the Engine really is
    function announceGiven(given) {
        if (!given || given.length === 0) return;
        if (engineEffectsOn('news')) sound('engine'); // the Exhaust Nozzle's Engine sounds
        for (const [source, parts] of given) {
            if (engine) {
                engine.parts += parts;
                engine.total += parts;
            }
            if (source === CONFIG.sourceShift) {
                sound('buy');
                toast('clipboard', 'Engine Shift done! +' + format(parts) + ' Engine Parts for the Cosmic Engine.');
            }
        }
        if (!isSending) saveSoon();
    }

    const partsText = (parts) => format(parts) + (parts === 1 ? ' part' : ' parts');
    const today = () => Math.floor(state.tick / CONFIG.dayMs);

    function renderEngine() {
        if (!engine) return;
        const done = engine.done;
        const isAllDone = done >= ENGINE_STAGES.length; // every stage there is so far
        const index = Math.min(done, ENGINE_STAGES.length - 1);
        const stage = ENGINE_STAGES[index];
        const cost = engine.costs[index];
        engineNumbers.number.textContent = '#' + engine.number;
        engineNumbers.name.textContent = isAllDone ? 'Ignited!' : stage.name;
        engineNumbers.stage.textContent = isAllDone ? 'All ' + ENGINE_STAGES.length + ' stages done. Engine #' + (engine.number + 1) + ' is coming.'
            : 'Stage ' + (done + 1) + ' of ' + CONFIG.engineStageTotal + ', ' + CONFIG.engineSections[stage.section];
        engineNumbers.parts.textContent = format(isAllDone ? cost : engine.parts);
        engineNumbers.cost.textContent = format(cost);
        engineNumbers.total.textContent = format(engine.total);
        setProgress(engineProgress, isAllDone ? 1 : engine.parts / cost);
        let lockText = '';
        if (!isAllDone && stage.lock) {
            const lock = engine.lock ?? { have: 0, need: stage.lock.count };
            lockText = 'Lock: ' + stage.lock.text + ' (' + format(Math.min(lock.have, lock.need)) + ' / ' + format(lock.need) + ').'
                + (engine.parts >= cost ? ' The stage is full and finishes once this is met.' : '');
        } else if (!isAllDone && engine.countdown > 0) { // Ignition (hbHubMinerEngineDrain())
            lockText = 'Time left to fill it: ' + formatDuration(Math.max(0, engine.countdown - serverNow())) + '. If it isn\'t full by then, '
                + (1 - CONFIG.engineDrain) * 100 + '% of its parts are lost and the time starts over.';
        } else if (isAllDone && engine.ignited > 0) {
            const left = engine.ignited + CONFIG.ignitionMs - serverNow();
            lockText = left > 0 ? 'Everything mines ' + CONFIG.ignitionTimes + ' times as much for another ' + formatDuration(left) + '.'
                : 'The Engine was ignited ' + formatDate(engine.ignited) + '.';
        }
        engineLock.textContent = lockText;
        engineLock.hidden = lockText === '';
        engineView.classList.toggle('is-ready', canGiveNow());
        renderEngineGive();
        renderShifts();
        renderEngineLists();
    }

    // The donation buttons, or why you can't give anything
    function renderEngineGive() {
        let note = '';
        if (IS_GUEST) note = 'Only players with an account can give the Engine parts. Sign up to help build it!';
        else if (saveState.warps < 1) note = 'Make your first Warp Jump to start giving the Engine parts.';
        else if (!saveState.engineOpen) {
            note = engine.done >= ENGINE_STAGES.length ? 'The Engine is ignited! Engine #' + (engine.number + 1) + ' is coming. Parts from Anomalies are kept for it.'
                : 'The Engine is full and waiting for its lock, so it only takes parts from Anomalies until then.';
        } else if (saveState.anomaly) note = 'You\'re in an Anomaly: leave it, or finish it, to give parts from your own game.';
        engineGiveNote.textContent = note;
        engineGiveNote.hidden = note === '';
        const isOpen = canGiveNow();

        const donated = saveState.engineDay === today() ? saveState.donatedToday : 0;
        const left = CONFIG.engineOreParts - donated;
        const oreCost = oreDonationCost({ donatedToday: donated }, 1);
        engineNumbers.oreCost.textContent = format(oreCost);
        const share = oreQuarters(saveState) / CONFIG.engineOreQuarters; // less in the later sections
        engineNumbers.oreLeft.textContent = (left > 0 ? left + ' of ' + CONFIG.engineOreParts + ' left today' : 'No more today')
            + (share < 1 ? '. In this section a part from ore counts as ' + (share === 0.5 ? 'half' : 'a quarter') + ' a part.' : '');
        oreDonateButton.disabled = !isOpen || left < 1 || !canAfford(saveState, oreCost);

        const most = Math.floor(saveState.strange / CONFIG.engineStrangePart);
        strangeDonateCount.max = String(Math.max(1, most));
        engineNumbers.strangeCost.textContent = format(strangeCount() * CONFIG.engineStrangePart);
        engineNumbers.strangeHave.textContent = format(saveState.strange);
        strangeDonateButton.disabled = !isOpen || most < 1;
    }

    // How many parts of Strange Matter the box asks for: at least 1, at most what there is
    const strangeCount = () => Math.max(1, Math.min(Math.floor(Number(strangeDonateCount.value)) || 1,
        Math.floor(saveState.strange / CONFIG.engineStrangePart)));

    // Today's Shifts, with how far each is (none yet when the save's day is an older one)
    function renderShifts() {
        const tasks = shiftTasks(today());
        const progress = saveState.engineDay === today() ? saveState.shifts : tasks.map(() => 0);
        const key = tasks.join() + '|' + progress.join() + '|' + canGiveNow();
        if (key === shiftsKey) return;
        shiftsKey = key;
        shiftList.replaceChildren(...tasks.map((id, i) => {
            const goal = SHIFTS[id].goal;
            const item = element('li', 'miner-shift' + (progress[i] >= goal ? ' is-done' : ''));
            const bar = element('div', 'miner-progress-bar');
            bar.setAttribute('role', 'progressbar');
            bar.setAttribute('aria-label', SHIFTS[id].text);
            bar.setAttribute('aria-valuemin', '0');
            bar.setAttribute('aria-valuemax', '100');
            bar.append(element('span', 'miner-progress-fill'));
            setProgress(bar, progress[i] / goal);
            const count = id === 'playing' ? Math.floor(progress[i] / 60000) + ' / ' + Math.floor(goal / 60000) + ' minutes'
                : format(progress[i]) + ' / ' + format(goal);
            item.append(element('span', 'miner-shift-name', SHIFTS[id].text), bar,
                element('span', 'miner-shift-count', progress[i] >= goal ? 'Done' : count));
            return item;
        }));
        const midnight = new Date((today() + 1) * CONFIG.dayMs).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        shiftNote.textContent = (canGiveNow() ? '' : IS_GUEST || saveState.warps < 1 ? 'Shifts start after your first Warp Jump. '
            : saveState.anomaly ? 'Shifts only count in your own game, not in an Anomaly. ' : 'Shifts are paused while the Engine waits for its lock. ')
            + 'Each Shift gives ' + CONFIG.shiftParts + ' parts, or ' + CONFIG.shiftPartsAll + ' each once all ' + CONFIG.shiftCount
            + ' are done. New Shifts at ' + midnight + '.';
    }

    // Every stage there is so far, the top contributors and the latest parts given, built again only when they change
    function renderEngineLists() {
        const key = engine.done + '|' + engine.costs.join() + '|' + (engine.top ? engine.feed.length + ':' + (engine.feed[0]?.at ?? 0) + ':' + engine.mine : '');
        if (key === engineListsKey) return;
        engineListsKey = key;

        let section = null;
        const stages = [];
        ENGINE_STAGES.forEach((stage, index) => {
            if (stage.section !== section) {
                section = stage.section;
                stages.push(element('li', 'miner-engine-section', section + '. ' + CONFIG.engineSections[section]));
            }
            const isDone = index < engine.done;
            const item = element('li', 'miner-engine-stage' + (isDone ? ' is-done' : index === engine.done ? ' is-now' : ''));
            const head = element('div', 'miner-engine-stage-head');
            const finishedAt = engine.finished[index + 1];
            head.append(
                element('span', 'miner-engine-stage-number', String(index + 1)),
                element('span', 'miner-engine-stage-name', stage.name),
                element('span', 'miner-engine-stage-cost', isDone ? 'Done' + (finishedAt ? ' ' + formatDate(finishedAt) : '') : partsText(engine.costs[index])),
            );
            item.append(head, element('p', 'miner-engine-stage-perk', stage.perk.text));
            if (stage.lock) item.append(element('p', 'miner-engine-stage-lock', 'Lock: ' + stage.lock.text + '.'));
            stages.push(item);
        });
        engineStageList.replaceChildren(...stages);

        engineNumbers.mine.hidden = IS_GUEST || engine.mine === undefined;
        engineNumbers.mine.textContent = 'You\'ve given ' + partsText(engine.mine ?? 0) + ' to this Engine.';
        if (!engine.top) { // guests never get them: players' names are only for members
            const text = IS_GUEST ? 'Sign up or log in to see who\'s building the Engine.' : 'Loading…';
            engineTop.replaceChildren(element('li', 'miner-engine-empty', text));
            engineFeed.replaceChildren(element('li', 'miner-engine-empty', text));
            return;
        }
        engineTop.replaceChildren(...(engine.top.length === 0 ? [element('li', 'miner-engine-empty', 'Nobody has given parts yet. Be the first!')]
            : engine.top.map((row, index) => {
                const item = element('li', 'miner-engine-row' + (row.you ? ' is-you' : ''));
                item.append(element('span', 'miner-engine-rank', String(index + 1)), element('span', 'miner-engine-who', row.name),
                    element('span', 'miner-engine-parts', partsText(row.parts)));
                return item;
            })));
        engineFeed.replaceChildren(...(engine.feed.length === 0 ? [element('li', 'miner-engine-empty', 'Nothing yet.')]
            : engine.feed.map((row) => {
                const item = element('li', 'miner-engine-row');
                item.append(element('span', 'miner-engine-who', row.name),
                    element('span', 'miner-engine-what', 'gave ' + partsText(row.parts) + ' from ' + (row.source === CONFIG.sourceAnomaly ? anomalyName(row.anomaly) : SOURCE_TEXT[row.source] ?? 'somewhere')),
                    element('span', 'miner-engine-when', formatDuration(Math.max(0, serverNow() - row.at)) + ' ago'));
                return item;
            })));
    }

    oreDonateButton.addEventListener('click', () => {
        const donated = saveState.engineDay === today() ? saveState.donatedToday : 0;
        const cost = oreDonationCost({ donatedToday: donated }, 1);
        const share = oreQuarters(saveState) / CONFIG.engineOreQuarters;
        if (!act({ type: 'donate', what: 'ore', parts: 1 })) return;
        sound('buy');
        toast('orePile', 'You gave the Cosmic Engine ' + (share === 1 ? '1 part' : share === 0.5 ? 'half a part' : 'a quarter of a part')
            + ' for ' + format(cost) + ' ore. Thanks!');
        renderEngine(); // the parts are on the bar already, see announceGiven()
    });
    strangeDonateButton.addEventListener('click', () => {
        const parts = strangeCount();
        if (!act({ type: 'donate', what: 'strange', parts })) return;
        sound('buy');
        toast('strangelet', 'You gave the Cosmic Engine ' + partsText(parts) + ' for ' + format(parts * CONFIG.engineStrangePart) + ' Strange Matter. Thanks!');
        renderEngine();
    });
    strangeDonateCount.addEventListener('input', () => {
        if (saveState) renderEngineGive();
    });

    // ----- Anomalies (the Anomalies view, and the banner over every view while the player is in one). The rules are in
    // "The game" (anomalyStart() and the rest); this is the list to pick one from, going in and out, and telling how a
    // run ended. saveState.anomalies has the ones finished ({done, best} by key), saveState.anomaly the one being played.

    const anomalyTierList = root.querySelector('[data-miner-anomaly-tiers]');
    const anomaliesCount = root.querySelector('[data-miner-anomalies-count]');
    const anomaliesNow = root.querySelector('[data-miner-anomalies-now]');
    const anomalyBanner = root.querySelector('[data-miner-anomaly-banner]');
    const anomalyBannerIcon = root.querySelector('[data-miner-anomaly-banner-icon]');
    const anomalyBannerName = root.querySelector('[data-miner-anomaly-banner-name]');
    const anomalyBannerInfo = root.querySelector('[data-miner-anomaly-banner-info]');
    const anomalyDialog = root.querySelector('[data-miner-anomaly-dialog]');
    const anomalyDialogTitle = root.querySelector('[data-miner-anomaly-dialog-title]');
    const anomalyDialogText = root.querySelector('[data-miner-anomaly-dialog-text]');
    const anomalyDialogConfirm = root.querySelector('[data-miner-anomaly-confirm]');
    const weeklyText = root.querySelector('[data-miner-weekly-text]');
    const weeklyBest = root.querySelector('[data-miner-weekly-best]');
    const weeklyStart = root.querySelector('[data-miner-weekly-start]');
    const stackPicks = root.querySelector('[data-miner-stack-picks]');
    const stackInfo = root.querySelector('[data-miner-stack-text]');
    const stackStart = root.querySelector('[data-miner-stack-start]');
    const TIER_NUMERALS = { 1: 'I', 2: 'II', 3: 'III' };
    const TIER_UNLOCK_TEXT = { rip: 'Opens with your first Big Rip.', theory: 'Opens once you\'ve learned the Theory of Everything.' };

    let anomaliesKey = '';
    let anomalyBannerKey = '';
    let anomalyAsked = null; // what the dialog asks: the key of the Anomaly to go into, or 'leave'

    const anomalyName = (key) => (anomalyMods(key ?? '') ?? []).map((id) => ANOMALIES[id].name).join(' + ') || 'an Anomaly';

    // Whether the Anomaly being played turns Autopilot and Auto Crunch off (Tier II)
    const isNoAuto = () => saveState?.anomaly?.mods.some((id) => ANOMALY_TIERS[ANOMALIES[id].tier].noAuto) ?? false;

    // A run's time as 1:02:03 (or 2:03 under an hour)
    function formatClock(ms) {
        const seconds = Math.floor(ms / 1000);
        const hours = Math.floor(seconds / 3600);
        const rest = String(Math.floor(seconds / 60) % 60).padStart(hours > 0 ? 2 : 1, '0') + ':' + String(seconds % 60).padStart(2, '0');
        return hours > 0 ? hours + ':' + rest : rest;
    }

    // The Anomaly the player is in right now, for followGame() to compare with afterwards, or null
    function anomalyNow() {
        const anomaly = saveState.anomaly;
        if (!anomaly) return null;
        const key = anomalyKey(anomaly.mods);
        return { key, record: saveState.anomalies[key] ?? null, allOre: saveState.allOre, started: anomaly.started };
    }

    // After anything that can take the player into or out of an Anomaly: state is the game being played again, and how
    // the run ended is told (finished, out of time, or left: isLeaving). was is anomalyNow() from before.
    function followGame(was, isLeaving = false) {
        const game = gameOf(saveState);
        if (game === state) return;
        state = game;
        storeKey = ''; // everything is built again for the other game, on the next tick
        darkKey = '';
        antiKey = '';
        treeKey = '';
        anomaliesKey = '';
        shownComet = -1;
        if (!was || saveState.anomaly) return;

        const name = anomalyName(was.key);
        const away = ' Your own game mined ' + format(Math.max(0, saveState.allOre - was.allOre)) + ' ore meanwhile.';
        const record = saveState.anomalies[was.key];
        if (record && record.done > (was.record?.done ?? 0)) {
            const ms = saveState.tick - was.started;
            const isBest = was.record === null || ms < was.record.best;
            const full = anomalyParts(saveState, anomalyMods(was.key), anomalyKey(weeklyMods(weekOf(saveState.tick))) === was.key);
            const parts = isBest ? full : Math.floor(full * CONFIG.anomalyRepeat);
            sound('achievement');
            toast(ANOMALIES[anomalyMods(was.key)[0]].icon, name + ' finished in ' + formatClock(ms)
                + (was.record === null ? ', for the first time!' : isBest ? ', a new best time!' : '.')
                + (IS_GUEST || saveState.warps < 1 ? '' : ' +' + partsText(parts) + ' for the Cosmic Engine.') + away, 'is-welcome', 10000);
        } else if (isLeaving) {
            toast('warp', 'You left ' + name + '.' + away, '', 7000);
        } else {
            toast('hourglass', 'Out of time: ' + name + ' is over.' + away, 'is-welcome', 9000);
        }
        saveSoon();
    }

    // The banner over every view while in an Anomaly: its name, the time (left, in Speed of Light) and the goal
    function renderAnomalyBanner() {
        const anomaly = saveState.anomaly;
        anomalyBanner.hidden = !anomaly;
        root.classList.toggle('is-in-anomaly', Boolean(anomaly));
        if (!anomaly) {
            anomalyBannerKey = '';
            return;
        }
        const key = anomalyKey(anomaly.mods);
        if (key !== anomalyBannerKey) {
            anomalyBannerKey = key;
            anomalyBannerIcon.replaceChildren(iconBox('miner-anomaly-banner-box', ANOMALIES[anomaly.mods[0]].icon));
            anomalyBannerName.textContent = 'In ' + anomalyName(key) + ':';
        }
        const time = anomaly.mods.includes('speedOfLight')
            ? formatClock(Math.max(0, anomaly.started + CONFIG.lightMs - state.tick)) + ' left'
            : formatClock(state.tick - anomaly.started);
        const stat = anomalyStat(anomaly.mods);
        anomalyBannerInfo.textContent = time + ' · ' + format(state[stat]) + ' of ' + format(anomaly.goal) + ' ' + GOAL_STAT_TEXT[stat]
            + (isNoAuto() ? ' · Autopilot and Auto Crunch are off in here' : '');
    }

    // What an Anomaly's goal counts, in words (anomalyStat())
    const GOAL_STAT_TEXT = { antimatterEarned: 'Antimatter', runOre: 'ore this run', darkEarned: 'Dark Matter' };
    function goalText(mods) {
        const goal = format(anomalyGoal(mods));
        const stat = anomalyStat(mods);
        return stat === 'runOre' ? 'mine ' + goal + ' ore in one run' : 'earn ' + goal + ' ' + GOAL_STAT_TEXT[stat] + ' in it';
    }

    // The parts an Anomaly or stack is worth now: in full the first time and for a new best time, and twice as many as
    // the weekly one this week
    const partsNow = (mods) => anomalyParts(saveState, mods, anomalyKey(weeklyMods(weekOf(state.tick))) === anomalyKey(mods));

    // Whether the player can go into these Anomalies now: in none already, and every one's tier open
    const canStart = (mods) => !saveState.anomaly && mods.length > 0 && mods.every((id) => anomalyTierOpen(saveState, ANOMALIES[id].tier));

    // This week's Anomaly: what it is, its goal and parts, and the player's best time at it
    function renderWeekly() {
        const mods = weeklyMods(weekOf(state.tick));
        const key = anomalyKey(mods);
        const record = saveState.anomalies[key] ?? null;
        weeklyText.textContent = anomalyName(key) + ': ' + mods.map((id) => ANOMALIES[id].text).join(' ') + ' Goal: ' + goalText(mods) + ', for '
            + partsText(partsNow(mods)) + ' (' + CONFIG.anomalyWeekly + ' times as many as usual, this week only).';
        weeklyBest.textContent = record ? 'Your best time at it: ' + formatClock(record.best) + '.' : 'You haven\'t finished it yet.';
        weeklyStart.dataset.anomalyStart = key;
        weeklyStart.disabled = !canStart(mods);
    }

    // The stack builder: a switch for each stackable Anomaly, and the goal and parts of the ones picked
    let stackPicked = [];
    function renderStack() {
        const ids = ANOMALY_IDS.filter((id) => ANOMALIES[id].tier <= CONFIG.anomalyStackTier);
        if (stackPicks.childElementCount === 0) {
            stackPicks.replaceChildren(...ids.map((id) => {
                const button = element('button', 'miner-stack-pick', ANOMALIES[id].name);
                button.type = 'button';
                button.dataset.stackPick = id;
                return button;
            }));
        }
        stackPicked = stackPicked.filter((id) => anomalyTierOpen(saveState, ANOMALIES[id].tier));
        for (const button of stackPicks.children) {
            button.setAttribute('aria-pressed', String(stackPicked.includes(button.dataset.stackPick)));
            button.disabled = !anomalyTierOpen(saveState, ANOMALIES[button.dataset.stackPick].tier);
        }
        const mods = ids.filter((id) => stackPicked.includes(id));
        const record = mods.length > 1 ? saveState.anomalies[anomalyKey(mods)] ?? null : null;
        stackInfo.textContent = mods.length < 2 ? 'Pick at least two.'
            : anomalyName(anomalyKey(mods)) + '. Goal: ' + goalText(mods) + ', for ' + partsText(partsNow(mods)) + '.'
                + (record ? ' Your best time at it: ' + formatClock(record.best) + '.' : '');
        stackStart.dataset.anomalyStart = anomalyKey(mods);
        stackStart.disabled = mods.length < 2 || !canStart(mods);
    }

    // The Anomalies view: every Anomaly by tier, with its rule, goal, parts, best time and badge, built again only
    // when something in it changes
    function renderAnomalies() {
        const finished = ANOMALY_IDS.filter((id) => saveState.anomalies[id]).length; // each on its own, not the stacks
        anomaliesCount.textContent = finished + ' of ' + ANOMALY_IDS.length;
        anomaliesNow.textContent = saveState.anomaly ? 'You\'re in ' + anomalyName(anomalyKey(saveState.anomaly.mods)) + ' right now.' : '';
        const week = weekOf(state.tick);
        const key = JSON.stringify(saveState.anomalies) + '|' + (saveState.anomaly ? anomalyKey(saveState.anomaly.mods) : '')
            + '|' + Object.keys(ANOMALY_TIERS).map((tier) => anomalyTierOpen(saveState, Number(tier))).join() + '|' + week + '|' + saveState.engineStage
            + '|' + stackPicked.join();
        if (key === anomaliesKey) return;
        anomaliesKey = key;
        renderWeekly();
        renderStack();

        anomalyTierList.replaceChildren(...Object.entries(ANOMALY_TIERS).map(([tierText, tier]) => {
            const tierNumber = Number(tierText);
            const isOpen = anomalyTierOpen(saveState, tierNumber);
            const box = element('section', 'miner-anomaly-tier' + (isOpen ? '' : ' is-locked'));
            box.append(element('h3', 'miner-store-title', 'Tier ' + TIER_NUMERALS[tierNumber] + ': ' + tier.name),
                element('p', 'miner-engine-small', (tier.fresh ? 'These start you from nothing: no skills, no Strange Matter and no upgrades. They don\'t stack.'
                    : partsText(tier.parts) + ' each.')
                    + (tier.noAuto ? ' Autopilot and Auto Crunch are off in these.' : '')
                    + (isOpen ? '' : ' ' + TIER_UNLOCK_TEXT[tier.unlock])));
            const list = element('ul', 'miner-anomaly-list');
            for (const [id, anomaly] of Object.entries(ANOMALIES)) {
                if (anomaly.tier !== tierNumber) continue;
                const record = saveState.anomalies[id] ?? null;
                const isIn = saveState.anomaly !== null && anomalyKey(saveState.anomaly.mods) === id;
                const item = element('li', 'miner-anomaly' + (record ? ' is-done' : '') + (isIn ? ' is-now' : ''));
                const main = element('div', 'miner-anomaly-main');
                main.append(element('span', 'miner-anomaly-name', anomaly.name),
                    element('span', 'miner-anomaly-text', anomaly.text),
                    element('span', 'miner-anomaly-goal', 'Goal: ' + goalText([id]) + (tier.fresh ? '. ' + partsText(partsNow([id])) + '.' : '.')),
                    element('span', 'miner-anomaly-best', record ? 'Badge earned. Best time ' + formatClock(record.best) + ', finished '
                        + (record.done === 1 ? 'once' : record.done + ' times') + '.' : 'Not finished yet.'));
                const button = element('button', 'casino-btn' + (isIn ? '' : ' casino-btn-primary'), isIn ? 'You\'re in it' : record ? 'Go again' : 'Go in');
                button.type = 'button';
                button.dataset.anomalyStart = id;
                button.disabled = !isOpen || saveState.anomaly !== null;
                // The badge: the Anomaly's icon, lit once it's finished
                const badge = iconBox('miner-anomaly-badge', anomaly.icon);
                badge.title = record ? anomaly.name + ' badge' : 'Finish it for its badge';
                item.append(badge, main, button);
                list.append(item);
            }
            box.append(list);
            return box;
        }));
    }

    // Asks before going into an Anomaly or stack (its key in the button's data-anomaly-start)
    function askStart(button) {
        if (!button || button.disabled) return;
        const key = button.dataset.anomalyStart;
        const mods = anomalyMods(key);
        if (!mods) return;
        const tiers = mods.map((id) => ANOMALY_TIERS[ANOMALIES[id].tier]);
        anomalyAsked = key;
        anomalyDialogTitle.textContent = 'Go into ' + anomalyName(key) + '?';
        anomalyDialogText.textContent = mods.map((id) => ANOMALIES[id].text).join(' ') + ' To finish it, ' + goalText(mods) + '. '
            + (tiers[0].fresh ? 'You start from nothing: no skills, no Strange Matter and no upgrades'
                : 'You start with your skills and Strange Matter, but no Dark Matter or Antimatter'
                    + (tiers.some((tier) => tier.noAuto) ? ', and Autopilot and Auto Crunch are off' : ''))
            + '. Your own game waits and mines as if you were away until you\'re back, and you can leave whenever you like.';
        anomalyDialogConfirm.textContent = 'Go in';
        anomalyDialog.showModal();
    }
    anomalyTierList.addEventListener('click', (event) => askStart(event.target.closest('[data-anomaly-start]')));
    weeklyStart.addEventListener('click', () => askStart(weeklyStart));
    stackStart.addEventListener('click', () => askStart(stackStart));
    stackPicks.addEventListener('click', (event) => {
        const button = event.target.closest('[data-stack-pick]');
        if (!button || button.disabled) return;
        const id = button.dataset.stackPick;
        stackPicked = stackPicked.includes(id) ? stackPicked.filter((other) => other !== id) : [...stackPicked, id];
        renderStack();
    });
    root.querySelector('[data-miner-anomaly-leave]').addEventListener('click', () => {
        if (!saveState?.anomaly) return;
        anomalyAsked = 'leave';
        anomalyDialogTitle.textContent = 'Leave ' + anomalyName(anomalyKey(saveState.anomaly.mods)) + '?';
        anomalyDialogText.textContent = 'You\'ll go back to your own game, and this run is lost: next time you go in, it starts over.';
        anomalyDialogConfirm.textContent = 'Leave';
        anomalyDialog.showModal();
    });
    root.querySelector('[data-miner-anomaly-cancel]').addEventListener('click', () => anomalyDialog.close());
    anomalyDialogConfirm.addEventListener('click', () => {
        anomalyDialog.close();
        if (anomalyAsked === 'leave') {
            if (!act({ type: 'anomalyQuit' })) return;
        } else {
            const mods = anomalyMods(anomalyAsked);
            if (!act({ type: 'anomalyStart', mods })) return;
            sound('rip');
            shake('is-ripping');
            toast(ANOMALIES[mods[0]].icon, 'Welcome to ' + anomalyName(anomalyAsked) + '. ' + mods.map((id) => ANOMALIES[id].text).join(' '), 'is-comet', 7000);
            if (root.dataset.view !== 'ore') {
                history.pushState(null, '', menu.querySelector('[data-miner-view-link="ore"]').href);
                showView('ore');
            }
        }
        renderAll();
        saveSoon();
    });

    // ----- Hot keys: each one presses the button it stands for, so they do exactly what a click would.
    // Space mines (a held-down key only counts once), C catches the comet (once the Comet Hot Key upgrade is bought, it's
    // lost on a Warp Jump like the other upgrades). With the Hot Key Panel upgrade (lost the same way, and needed before
    // the Comet Hot Key shows up), 1-9 and 0 buy the buildings in the order they're
    // listed (the last ten, with Long-Range Hot Keys switched on: see keyOffset()), U buys the
    // cheapest upgrade you can afford and B the affordable building with the most ore/s for its price (at the ×1/×10/×100
    // picked). K flips the Long-Range switch, A switches between ×1, ×10
    // and ×100, W opens the Warp Jump box, X the Big Crunch box, R the Big Rip box (Y or the same key again says yes in
    // them, N not yet) and M turns the sound on or off. They're left alone while typing, with Ctrl/Alt/Cmd held (the browser's own
    // shortcuts) and while a box is open. The buying keys (1-9, 0, B and U) can be held down to keep buying; the others
    // only count once per press, so holding M doesn't flick the sound on and off.

    // In the Warp Jump, Big Crunch and Big Rip boxes, Y (or the key that opened the box, pressed again) says yes and N
    // not yet. A held-down key doesn't count, so holding W can't open the box and jump straight away.
    for (const [dialog, openKey, name] of [[warpDialog, 'w', 'warp'], [crunchDialog, 'x', 'crunch'], [ripDialog, 'r', 'rip']]) {
        dialog.addEventListener('keydown', (event) => {
            if (event.repeat || event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) return;
            const key = event.key.toLowerCase();
            const button = key === 'y' || key === openKey ? 'confirm' : key === 'n' ? 'cancel' : null;
            if (!button) return;
            event.preventDefault();
            root.querySelector('[data-miner-' + name + '-' + button + ']').click();
        });
    }

    const REPEAT_KEYS = ['b', 'u'];
    const HOT_KEYS = {
        c: () => {
            if (has(state, 'cometKey') && !cometButton.hidden) cometButton.click();
        },
        u: () => {
            // Picked by cost from the buttons in the store, so it doesn't depend on the order they're listed in or on
            // an upgrade that's only just shown up and isn't drawn yet
            if (!has(state, 'quickKeys')) return;
            catchUp();
            let cheapest = null;
            for (const button of upgradeList.querySelectorAll('[data-upgrade]')) {
                const cost = upgradeCost(state, button.dataset.upgrade);
                if (canAfford(state, cost) && (!cheapest || cost < upgradeCost(state, cheapest.dataset.upgrade))) cheapest = button;
            }
            cheapest?.click();
        },
        b: () => {
            // The affordable building (at the amount picked) that adds the most ore per second for each ore it costs
            if (!has(state, 'quickKeys')) return;
            catchUp();
            const best = bestBuilding(buyAmount);
            if (best) buildingList.querySelector('[data-building="' + best.id + '"]')?.click();
        },
        a: () => {
            if (amounts.hidden) return;
            const options = [...amounts.querySelectorAll('[data-amount]')].filter((button) => !button.hidden);
            const now = options.findIndex((button) => parseAmount(button.dataset.amount) === buyAmount);
            options[(now + 1) % options.length].click();
        },
        k: () => {
            if (!keySwitch.hidden) keySwitch.click();
        },
        w: () => warpButton.click(), // does nothing while it's disabled
        x: () => crunchButton.click(), // same
        r: () => ripButton.click(), // same
        m: () => soundButton.click(),
    };

    document.addEventListener('keydown', (event) => {
        // defaultPrevented: a box's Y/N/W key (above) that's already been used. The box is closed by the time the key
        // gets here, so without it the W that said yes to a Warp Jump opened the box again at once, and every press
        // was a whole jump: with a key-repeating macro, 30 jumps a second froze the page (crash reports #31 and #32)
        if (!isPlaying || event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey || document.querySelector('dialog[open]')) return;
        const target = event.target;
        if (target.closest?.('input, textarea, select, [contenteditable]:not([contenteditable="false"])')) return;

        if (event.key === ' ') {
            // Space presses whatever has focus, as usual - except the menu's links, which Space does nothing to (and
            // which keep the focus after being clicked)
            if (target.closest?.('button, a:not([data-miner-view-link]), summary')) return;
            event.preventDefault(); // instead of scrolling the page
            if (!event.repeat && !asteroid.disabled) mine(event, true); // not asteroid.click(), which a script would do too
            return;
        }
        const keyIndex = BUILDING_KEYS.indexOf(event.key);
        if (keyIndex >= 0) {
            // Held down, these keep buying (as fast as the keyboard repeats), like B and U below
            if (!has(state, 'quickKeys')) return;
            const buttons = buildingList.querySelectorAll('[data-building]');
            buttons[keyOffset(buttons.length) + keyIndex]?.click();
            return;
        }
        const key = event.key.toLowerCase();
        if (event.repeat && !REPEAT_KEYS.includes(key)) return;
        const hotKey = HOT_KEYS[key];
        if (hotKey && !event.shiftKey) hotKey();
    });

    // ----- The views: the menu's links show their view here instead of loading the page again (Ctrl/middle click still
    // open it in a new tab, which takes the game over from this one). The address changes with it, so Back and
    // Forward go between the views and a refresh stays on the same one.

    function viewFromAddress() {
        const view = new URLSearchParams(location.search).get('view');
        return view && views[view] ? view : 'ore';
    }

    function showView(view) {
        root.dataset.view = view;
        note('View: ' + view);
        for (const [name, node] of Object.entries(views)) node.hidden = name !== view;
        for (const link of menu.querySelectorAll('[data-miner-view-link]')) {
            if (link.dataset.minerViewLink === view) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        }
        if (scrollY > 0) scrollTo({ top: 0 }); // the Ore view fits the screen from the top
        forgetFieldBox(); // a hidden picture has no box
        placeSkillInfo(); // the screen may have changed size while the tree was out of sight
        if (view === 'engine' && isPlaying) {
            renderEngine();
            saveSoon(); // for the top contributors and the latest parts given, which only come while it's open
        }
    }

    menu.addEventListener('click', (event) => {
        const link = event.target.closest('[data-miner-view-link]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (link.dataset.minerViewLink === root.dataset.view) return;
        history.pushState(null, '', link.href);
        showView(link.dataset.minerViewLink);
    });
    addEventListener('popstate', () => showView(viewFromAddress()));

    // ----- The popups: stats, achievements and how to play. Closed with ×, Escape or a click outside them.

    for (const button of root.querySelectorAll('[data-miner-open]')) {
        button.addEventListener('click', () => {
            const popup = root.querySelector('[data-miner-popup="' + button.dataset.minerOpen + '"]');
            if (popup === statsPopup && state) renderStats();
            popup.showModal();
        });
    }
    for (const popup of root.querySelectorAll('[data-miner-popup]')) {
        popup.addEventListener('click', (event) => {
            if (event.target === popup || event.target.closest('[data-miner-close]')) popup.close();
        });
    }

    // ----- Playing somewhere else

    otherTabDialog.addEventListener('cancel', (event) => event.preventDefault());
    root.querySelector('[data-miner-reload]').addEventListener('click', () => location.reload());

    // ----- The trail: the last TRAIL_MAX things that happened, as [Unix ms, text], for a crash report: saves and how
    // they went, errors, slow frames, freezes, the page being hidden or shown, views, the auto switches and the bigger
    // actions (Warp Jumps, upgrades bought with Dark Matter...). The same text twice in a row is one line that counts.
    // Never what's typed, and nothing goes anywhere unless the player agrees to send a crash report.

    const TRAIL_MAX = 100;
    const NOTE_MAX = 500; // characters in one line
    const trail = [];
    let lastNote = '';
    let lastNoteCount = 0;

    function note(text) {
        text = String(text).slice(0, NOTE_MAX);
        const last = trail[trail.length - 1];
        if (last && text === lastNote) {
            lastNoteCount++;
            last[0] = Date.now();
            last[1] = text + ' (' + lastNoteCount + ' times)';
            return;
        }
        lastNote = text;
        lastNoteCount = 1;
        trail.push([Date.now(), text]);
        if (trail.length > TRAIL_MAX) trail.shift();
    }

    // "spaceOreMiner.js" from "https://.../assets/js/spaceOreMiner.js?v=123"
    const fileName = (url) => String(url ?? '').split('?')[0].split('/').pop() || '?';

    // Where an error came from: the first few lines of its stack, with the addresses cut down to the file
    function stackText(error) {
        const lines = String(error?.stack ?? '').split('\n').slice(1, 5)
            .map((line) => line.trim().replace(/https?:\/\/[^\s)]+\//g, '').replace(/\?v=\d+/g, ''));
        return lines.length > 0 ? ' < ' + lines.join(' < ') : '';
    }

    addEventListener('error', (event) => {
        note('Error: ' + event.message + ' (' + fileName(event.filename) + ':' + event.lineno + ':' + event.colno + ')' + stackText(event.error));
    });
    addEventListener('unhandledrejection', (event) => {
        note('Error in a promise: ' + (event.reason?.message ?? String(event.reason)) + stackText(event.reason));
    });

    // Slow frames: the browser tells (long-animation-frame, or in older ones only longtask) when a frame took long,
    // and with long-animation-frame which scripts it ran and for how long (invoker is what started them, like
    // "TimerHandler:setInterval" or "FrameRequestCallback", and sourceCharPosition where in the file the function is).
    // The ones over SLOW_FRAME_MS are put together and go in the trail once a second, with the worst one's details.
    const SLOW_FRAME_MS = 200;
    let slowFrames = null; // since the last beat: {count, worst (ms), text}

    function watchSlowFrames() {
        const types = window.PerformanceObserver?.supportedEntryTypes ?? [];
        const type = types.includes('long-animation-frame') ? 'long-animation-frame' : types.includes('longtask') ? 'longtask' : null;
        if (type === null) return; // a browser that doesn't say: no slow frames in the trail
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (entry.duration < SLOW_FRAME_MS) continue;
                slowFrames ??= { count: 0, worst: 0, text: '' };
                slowFrames.count++;
                if (entry.duration > slowFrames.worst) {
                    slowFrames.worst = entry.duration;
                    slowFrames.text = slowFrameText(entry);
                }
            }
        }).observe({ type, buffered: true });
    }

    function slowFrameText(entry) {
        if (!entry.scripts) return ''; // a longtask, which doesn't say
        const parts = [...entry.scripts].sort((a, b) => b.duration - a.duration).slice(0, 3).map((script) => (script.invoker || script.invokerType)
            + (script.sourceFunctionName ? ' in ' + script.sourceFunctionName : '')
            + (script.sourceURL ? ' (' + fileName(script.sourceURL) + ' at ' + script.sourceCharPosition + ')' : '')
            + ' ' + Math.round(script.duration) + ' ms');
        if (entry.styleAndLayoutStart > 0) parts.push('drawing ' + Math.round(entry.startTime + entry.duration - entry.styleAndLayoutStart) + ' ms');
        return parts.join('; ');
    }

    function noteSlowFrames() {
        if (!slowFrames) return;
        note((slowFrames.count === 1 ? 'A slow frame' : slowFrames.count + ' slow frames') + ', the worst ' + Math.round(slowFrames.worst) + ' ms'
            + (slowFrames.text ? ': ' + slowFrames.text : ''));
        slowFrames = null;
    }

    // ----- The watchdog (spaceOreMinerWatchdog.js, a worker, so it keeps running when this page freezes): step()
    // tells it what the page is doing right now (null: nothing, it's waiting), and beat() that the page is fine, every
    // second, with the crash report it would save if the page froze before the next beat. The game's timers and its
    // save each name their steps; clicks and key presses are named by the listeners below, which come first and last.

    let watchdog = null;
    let stepName = null;
    try {
        watchdog = new Worker(root.dataset.watchdog);
        watchdog.addEventListener('message', (event) => {
            if (event.data.type === 'recovered') note('The page answered again after freezing for ' + (event.data.ms / 1000).toFixed(1) + ' s');
        });
    } catch {
        // no workers: freezes aren't noticed while they happen, but a page that's closed by one still is (isCrash())
    }

    // How long each step takes, since the last sample (see beat()): name => {count, total, max} in ms. The watchdog
    // also keeps the last few steps itself, with their times (at is this page's performance.now(), for that).
    let stepStartedAt = performance.now();
    let stepTimes = new Map();

    // Returns the step it was doing before, for the ones that go on with it afterwards
    function step(name) {
        const was = stepName;
        if (name !== stepName) {
            const now = performance.now();
            if (stepName !== null) {
                const time = stepTimes.get(stepName) ?? { count: 0, total: 0, max: 0 };
                time.count++;
                time.total += now - stepStartedAt;
                time.max = Math.max(time.max, now - stepStartedAt);
                stepTimes.set(stepName, time);
            }
            stepName = name;
            stepStartedAt = now;
            watchdog?.postMessage({ type: 'step', name, at: now });
        }
        return was;
    }

    // What a click or key press was on, like "button[data-building=drone]". Not which key while typing in a box.
    function describeEvent(event) {
        const target = event.target instanceof Element ? event.target : null;
        if (event.type === 'keydown') return target?.closest('input, textarea, [contenteditable]') ? 'typing' : event.key;
        const node = target?.closest('button, a, summary, dialog') ?? target;
        if (!node) return '?';
        const [data] = Object.entries(node.dataset);
        return node.tagName.toLowerCase() + (data ? '[data-' + data[0].replace(/[A-Z]/g, (letter) => '-' + letter.toLowerCase())
            + (data[1] ? '=' + data[1] : '') + ']' : '');
    }

    for (const type of ['click', 'keydown', 'pointerdown', 'pointerup']) {
        addEventListener(type, (event) => step(type + ': ' + describeEvent(event)), { capture: true });
        addEventListener(type, () => step(null));
    }

    const BEAT_MS = 1000;
    const STALL_MS = 3000; // a beat this late while the page is shown: it froze for a moment
    const LOW_FPS = 20; // frames a second, under which the trail says the frame rate dropped (and back over 1.5 times it)
    const SAMPLE_MS = 30000;
    const SAMPLES_MAX = 20; // the last 10 minutes
    const SAMPLE_STEPS = 5; // the slowest steps in each sample
    // Every SAMPLE_MS: {at (Unix ms), memory (MB used by scripts, null where the browser doesn't say), elements (on the
    // page), animations (running on it), fps and lowestFps (frames a second: on average, and in its slowest second, while
    // the page was shown and playing; null when it wasn't), slowest (the SAMPLE_STEPS steps that took longest at once:
    // [name, longest ms, ms in all, times])}
    const samples = [];
    let lastBeatAt = Date.now();
    let wasVisible = !document.hidden;
    let lastSampleAt = 0;
    let framesSinceBeat = 0; // counted by frame()
    let sampleFrames = 0;
    let sampleSeconds = 0;
    let lowestFps = null;
    let isFpsLow = false;
    let gpu; // the graphics card's name (graphicsCard()), looked up on the first beat so it doesn't slow the start

    // The graphics card, as WebGL tells it, or null. Some browsers (like Brave) can give a general or made-up one.
    function graphicsCard() {
        try {
            const gl = document.createElement('canvas').getContext('webgl');
            if (!gl) return null;
            const info = gl.getExtension('WEBGL_debug_renderer_info');
            const name = String(gl.getParameter(info ? info.UNMASKED_RENDERER_WEBGL : gl.RENDERER)).slice(0, 200);
            gl.getExtension('WEBGL_lose_context')?.loseContext();
            return name;
        } catch {
            return null;
        }
    }

    function beat() {
        step('beat');
        const now = Date.now();
        const isVisible = !document.hidden;
        if (isVisible && wasVisible && now - lastBeatAt > STALL_MS) note('The page froze for about ' + ((now - lastBeatAt - BEAT_MS) / 1000).toFixed(1) + ' s');
        if (isVisible && wasVisible && isPlaying && now > lastBeatAt) {
            const fps = framesSinceBeat / ((now - lastBeatAt) / 1000);
            sampleFrames += framesSinceBeat;
            sampleSeconds += (now - lastBeatAt) / 1000;
            lowestFps = Math.min(lowestFps ?? Infinity, Math.round(fps));
            if (!isFpsLow && fps < LOW_FPS) {
                isFpsLow = true;
                note('The frame rate dropped to ' + Math.round(fps) + ' fps');
            } else if (isFpsLow && fps >= LOW_FPS * 1.5) {
                isFpsLow = false;
                note('The frame rate is back up to ' + Math.round(fps) + ' fps');
            }
        }
        framesSinceBeat = 0;
        lastBeatAt = now;
        wasVisible = isVisible;
        if (gpu === undefined) gpu = graphicsCard();
        noteSlowFrames();
        if (now - lastSampleAt >= SAMPLE_MS) {
            lastSampleAt = now;
            samples.push({
                at: now,
                memory: performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576 * 10) / 10 : null,
                elements: document.getElementsByTagName('*').length,
                animations: document.getAnimations?.().length ?? null,
                fps: sampleSeconds > 0 ? Math.round(sampleFrames / sampleSeconds) : null,
                lowestFps,
                slowest: [...stepTimes].sort(([, a], [, b]) => b.max - a.max).slice(0, SAMPLE_STEPS)
                    .map(([name, time]) => [name, Math.round(time.max * 10) / 10, Math.round(time.total), time.count]),
            });
            if (samples.length > SAMPLES_MAX) samples.shift();
            stepTimes = new Map();
            sampleFrames = 0;
            sampleSeconds = 0;
            lowestFps = null;
        }
        const report = crashReport();
        writeRun(report);
        watchdog?.postMessage({ type: 'beat', visible: isVisible, report });
        step(null);
    }

    // What a crash report about this page would say right now: {kind, page (tab, opened and lastSeen in Unix ms,
    // visible, closed, view, screen, cores, deviceMemory (GB, rounded by the browser), gpu (graphicsCard()), switches
    // (what's switched on)}, pending (actions not saved yet), trail, samples, state (the save as the page has it)}. The
    // watchdog adds hang.
    function crashReport() {
        const switches = [
            [isAutoBuying && state && hasDark(state, 'autoBuy'), 'Auto Buy'],
            [isAutoWarping && state && skillEffects(state).autoWarp, 'Autopilot'],
            [isAutoCrunching && state && skillEffects(state).autoCrunch, 'Auto Crunch'],
            [state && hasAnti(state, 'cometCatcher'), 'Comet Catcher'],
            [lateKeys && state && hasDark(state, 'lateKeys'), 'Late-game keys'],
            [isSoundOn, 'Sound'],
            [reduceMotion.matches, 'Reduced motion'],
        ];
        return {
            kind: 'closed',
            page: {
                tab: TAB,
                opened: openedAt,
                lastSeen: Date.now(),
                visible: !document.hidden,
                closed: isClosed,
                view: root.dataset.view,
                screen: innerWidth + '×' + innerHeight + ' at ' + devicePixelRatio + 'x',
                cores: navigator.hardwareConcurrency ?? null,
                deviceMemory: navigator.deviceMemory ?? null,
                gpu: gpu ?? null,
                switches: switches.filter(([isOn]) => isOn).map(([, name]) => name),
            },
            pending: inflight.length + pending.length,
            trail: trail.slice(),
            samples: samples.slice(),
            state: saveState,
        };
    }

    // ----- Crash recovery. What hasn't been saved yet is kept in this browser's local storage, under RUN_KEY + this
    // page's tab id, as {report (crashReport()), actions (the last save's while it's on its way, then what's been done
    // since)}: written every second (beat()), when a save is sent or answered, and when the page is hidden or closed,
    // and taken away once the page is closed with nothing left to save. A page that crashes, freezes until the browser
    // closes it, or is closed before its last save got there leaves it behind, and the next page of the game sends those
    // actions with its first save (recovered, see hbHubMinerSync()). Only the ones newer than the save count there, so
    // the ones that did get there aren't counted twice. Then they're taken away, except from a page that crashed
    // (isCrash()), which stays (without its actions) until the player has answered about its crash report.

    const RUN_KEY = IS_GUEST ? 'hbHubGuestMinerRun:' : 'hbHubMinerRun:'; // a guest's never end up in an account's game
    const CRASH_STALE_MS = 10000; // a page on screen says something every second: after this long without, it's gone
    const openedAt = Date.now();
    let inflight = []; // the last save's actions while it's on its way
    let isClosed = false; // the page was closed (or the game opened somewhere else): it ended properly
    const leftovers = readRuns(); // what other pages of the game left behind: [{key, report, actions}]
    let recovered = leftovers.flatMap((run) => run.actions).sort((a, b) => a.at - b.at);

    function readRuns() {
        const runs = [];
        try {
            const keys = [];
            for (let i = 0; i < localStorage.length; i++) keys.push(localStorage.key(i));
            for (const key of keys) {
                if (!key?.startsWith(RUN_KEY) || key === RUN_KEY + TAB) continue;
                let run = null;
                try {
                    run = JSON.parse(localStorage.getItem(key));
                } catch {
                    // broken: taken away like the others
                }
                runs.push({
                    key,
                    report: run?.report?.page ? run.report : null,
                    actions: Array.isArray(run?.actions) ? run.actions.filter((action) => action && Number.isFinite(action.at)) : [],
                });
            }
        } catch {
            // no storage: nothing to recover
        }
        return runs;
    }

    // A page that went away while it was on screen, without closing properly
    function isCrash(run) {
        const page = run.report?.page;
        return Boolean(page) && !page.closed && page.visible && Date.now() - page.lastSeen > CRASH_STALE_MS;
    }

    // report: crashReport(), when it's already been made
    function writeRun(report = crashReport()) {
        try {
            const actions = inflight.concat(pending);
            if (isClosed && actions.length === 0) localStorage.removeItem(RUN_KEY + TAB);
            else localStorage.setItem(RUN_KEY + TAB, JSON.stringify({ report, actions }));
        } catch {
            // no storage, or it's full: nothing to recover from
        }
    }

    // After the first save, with how many of the recovered actions counted
    function finishRecovery(count) {
        if (recovered.length > 0) note('Recovered ' + count + ' of ' + recovered.length + ' actions from before');
        if (count > 0) {
            toast('orePile', 'The game didn\'t close properly last time, but what you did just before has been saved now.', 'is-welcome', 9000);
        }
        recovered = [];
        for (const run of leftovers) {
            try {
                if (!crashesAnswered && crashRuns.includes(run)) localStorage.setItem(run.key, JSON.stringify({ report: run.report, actions: [] }));
                else localStorage.removeItem(run.key);
            } catch {
                // no storage
            }
        }
    }

    // ----- Crash reports. When the page opens, it looks for crashes: the freezes the watchdog saved in IndexedDB, and
    // the pages that crashed (isCrash()). If there are any, it asks whether it may send a crash report, with the
    // newest one in the words and all that would be sent under "What's in it". If the player says yes, it goes to
    // /games/ore-mining-inc/crash as one report: the newest crash, with up to CRASH_EARLIER_MAX older ones in it under
    // earlier (sentReport()), so one yes is one report, not one per freeze (a page that struggles can freeze several
    // times before it's closed). Either way they're deleted. Closing the popup without answering keeps them, to ask
    // again next time.

    const CRASH_EARLIER_MAX = 4;
    const crashDialog = root.querySelector('[data-miner-crash-dialog]');
    const crashDialogText = root.querySelector('[data-miner-crash-text]');
    const crashDialogJson = root.querySelector('[data-miner-crash-json]');
    const crashRuns = leftovers.filter(isCrash);
    let crashes = []; // what's asked about, newest first
    let hangIds = []; // their records in IndexedDB
    let crashesAnswered = false;

    function crashDatabase() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open('hbHubMiner', 1);
            request.onupgradeneeded = () => request.result.createObjectStore('crashes', { keyPath: 'id' });
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    // The freezes the watchdog saved (its records, see spaceOreMinerWatchdog.js), or none without IndexedDB
    async function readHangs() {
        try {
            const db = await crashDatabase();
            return await new Promise((resolve, reject) => {
                const request = db.transaction('crashes').objectStore('crashes').getAll();
                request.onsuccess = () => resolve(request.result.filter((record) => record?.page && record.hang));
                request.onerror = () => reject(request.error);
            });
        } catch {
            return [];
        }
    }

    async function deleteHangs(ids) {
        if (ids.length === 0) return;
        try {
            const store = (await crashDatabase()).transaction('crashes', 'readwrite').objectStore('crashes');
            for (const id of ids) store.delete(id);
        } catch {
            // they're asked about again next time
        }
    }

    // "1.2 seconds", "40 seconds", "3 minutes"
    const secondsText = (ms) => (ms < 10000 ? (ms / 1000).toFixed(1) + ' seconds' : ms < 60000 ? Math.round(ms / 1000) + ' seconds' : formatDuration(ms));

    function crashText(report) {
        const when = new Date(report.hang?.at ?? report.page.lastSeen)
            .toLocaleString('en-GB', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
        if (report.kind === 'hang') {
            return 'On ' + when + ', Ore Mining Inc. froze and stopped answering' + (report.hang.recovered
                ? ' for ' + secondsText(report.hang.lasted) + ' before it came back.'
                : '. It was still frozen ' + secondsText(report.hang.lasted) + ' later, so the browser most likely closed it.');
        }
        return 'On ' + when + ', Ore Mining Inc. stopped while you were playing, without closing properly. That happens when the '
            + 'browser closes a page that froze or ran out of memory, or when the computer turns off.';
    }

    // The report as it's sent: the newest crash (without the watchdog's id for it), and the older ones as earlier, newest
    // first, each with only its kind, page and hang (the save, trail and samples are much the same as the newest's)
    function sentReport() {
        const { id, ...newest } = crashes[0];
        const earlier = crashes.slice(1, 1 + CRASH_EARLIER_MAX).map(({ kind, page, hang }) => ({ kind, page, ...(hang ? { hang } : {}) }));
        return earlier.length > 0 ? { ...newest, earlier } : newest;
    }

    async function askAboutCrashes() {
        const hangs = await readHangs();
        const hungTabs = new Set(hangs.map((hang) => hang.page.tab));
        crashes = [...hangs, ...crashRuns.map((run) => run.report).filter((report) => !hungTabs.has(report.page.tab))]
            .sort((a, b) => b.page.lastSeen - a.page.lastSeen);
        hangIds = hangs.map((hang) => hang.id);
        if (crashes.length === 0) return;
        note('Found ' + crashes.length + ' crash' + (crashes.length === 1 ? '' : 'es') + ' from before');
        if (IS_GUEST) {
            answerCrashes(false); // a guest can't send crash reports (the page has no popup for it): just cleared
            return;
        }
        const more = crashes.length - 1;
        crashDialogText.textContent = crashText(crashes[0]) + (more > 0 ? ' It happened ' + more + ' more ' + (more === 1 ? 'time' : 'times') + ' before that too.' : '');
        crashDialogJson.textContent = JSON.stringify(sentReport(), null, 2);
        crashDialog.showModal();
    }

    async function answerCrashes(isSending) {
        if (crashesAnswered) return; // answered already (a second click before the popup closed)
        crashDialog?.close();
        crashesAnswered = true;
        for (const run of crashRuns) {
            try {
                localStorage.removeItem(run.key);
            } catch {
                // no storage
            }
        }
        deleteHangs(hangIds);
        if (!isSending) return;

        let isSent = false;
        try {
            const response = await fetch('/games/ore-mining-inc/crash', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ action: 'report', kind: crashes[0].kind, report: JSON.stringify(sentReport()) }),
            });
            isSent = response.ok;
        } catch {
            // not sent
        }
        toast('', isSent ? 'Thanks! The crash report was sent, so it can be looked into.' : 'Couldn\'t send the crash report, sorry.', '', 6000);
    }

    root.querySelector('[data-miner-crash-send]')?.addEventListener('click', () => answerCrashes(true));
    root.querySelector('[data-miner-crash-skip]')?.addEventListener('click', () => answerCrashes(false));

    note('Opened on the ' + root.dataset.view + ' view');
    watchSlowFrames();
    every(BEAT_MS, beat);
    askAboutCrashes();
    save({ claim: true });
})();
