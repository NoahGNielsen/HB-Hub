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
// The page has four views (Ore, Dark Matter, Antimatter, Strange Matter, in data-miner-view): the menu's links switch
// between them here, with history.pushState, so the game keeps running instead of the page loading again. The Strange
// Matter view has the skill tree, drawn here from the skills (see renderTree()). Stats, achievements and how to
// play are popups (data-miner-popup), opened by the buttons by the title (data-miner-open).
// What hasn't been saved yet is also kept in the browser, so a page that crashes or freezes doesn't lose it: the next
// one sends it with its first save. A watchdog worker (data-watchdog, spaceOreMinerWatchdog.js) notices when the page
// freezes, and the next time the game is opened after one, it asks whether it may send a crash report. See "Crash
// recovery" and "Crash reports" near the end.
(() => {
    const root = document.querySelector('[data-miner]');
    if (!root) return;

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
    const sum = (values) => values.reduce((total, value) => total + value, 0);

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

    const darkBonus = (s) => (hasDark(s, 'reactor') ? CONFIG.darkBonusReactor : CONFIG.darkBonus) + skillEffects(s).darkBonus;
    const antiMultiplier = (s) => 1 + (CONFIG.antiBonus + skillEffects(s).antiBonus) * s.antimatterEarned;
    const strangeMultiplier = (s) => 1 + CONFIG.strangeBonus * s.strangeEarned;
    const cometPower = (s) => (hasAnti(s, 'antiComets') ? 2 : 1) * skillEffects(s).cometPower;
    const upgradeCost = (s, id) => UPGRADES[id].cost * skillEffects(s).upgradeCost;

    // How much more each of a building costs than the one before (hbHubMinerCostGrowth())
    const costGrowth = (s, id) => (BUILDINGS[id].growth ?? CONFIG.costGrowth) - (hasDark(s, 'physics') ? CONFIG.costGrowthPhysics : 0);

    function buildingCost(s, id, amount) {
        const growth = costGrowth(s, id);
        return Math.ceil(BUILDINGS[id].cost * skillEffects(s).cost * growth ** s.buildings[id] * (growth ** amount - 1) / (growth - 1));
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
        global *= effects.production * (1 + effects.perSkill * s.skills.length) * (1 + effects.perWarp * s.warps)
            * (1 + effects.perCrunch * s.crunches);
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

    function buff(s, kind, time) {
        const running = s.buffs[kind];
        return running && running.until > time ? running.multiplier : 1;
    }

    function clickValue(s, time, parts = rateParts(s)) {
        const effects = skillEffects(s);
        const value = parts.click * effects.click;
        const share = parts.clickShare + effects.clickShare;
        const steady = share > 0 ? production(s, parts) * buff(s, 'production', time) : 0;
        return (value + share * steady) * buff(s, 'click', time);
    }

    // How many times a second the auto upgrades and skills click, together (hbHubMinerAutoClicks())
    function autoClicks(s, parts = rateParts(s)) {
        return parts.clicks + skillEffects(s).auto;
    }

    // time Infinity: the steady rate, without comets. parts: rateParts(s), when it's already been worked out.
    function rate(s, time, parts = rateParts(s)) {
        let total = production(s, parts) * buff(s, 'production', time);
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
        return Math.floor((has(s, 'radar') ? wait / 2 : wait) * skillEffects(s).cometWait);
    }

    const cometStay = (s) => Math.floor((has(s, 'magnet') ? CONFIG.cometStayMs * 2 : CONFIG.cometStayMs) * skillEffects(s).cometStay);

    // How long a comet's effect lasts, in ms (hbHubMinerCometLength())
    const cometLength = (s, kind) => Math.floor(COMETS[kind].ms * (has(s, 'tail') ? 2 : 1) * skillEffects(s).cometLength);

    function cometKind(s) {
        const n = s.comet.n;
        const diamondChance = (has(s, 'luckyStars') ? CONFIG.diamondChance : 0) + skillEffects(s).diamond;
        if (diamondChance > 0 && random(s.seed, n, SALT_DIAMOND) < diamondChance) return 'diamond';
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

    function advance(s, to, fullUntil = Infinity) {
        let offlineOre = 0;
        while (s.tick < to) {
            let next = to;
            for (const running of Object.values(s.buffs)) {
                if (running.until > s.tick && running.until < next) next = running.until;
            }
            if (fullUntil > s.tick && fullUntil < next) next = fullUntil;

            let ore = rate(s, s.tick) * (next - s.tick) / 1000;
            if (s.tick >= fullUntil) {
                ore *= CONFIG.offlineRate * (hasDark(s, 'timeDilation') ? 2 : 1) * skillEffects(s).offline;
                offlineOre += ore;
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
        const total = Math.floor(darkTotal(s.universeOre) * Math.sqrt(antiMultiplier(s)) * (hasAnti(s, 'converter') ? 2 : 1) * skillEffects(s).darkGain);
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
        return s.darkEarned >= CONFIG.crunchDark * effects.crunchDark ? Math.floor(antiTotal(s.darkEarned) * effects.antiGain) : 0;
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
        if (time < s.comet.at || time > s.comet.at + cometStay(s)) return null;

        const kind = cometKind(s);
        const comet = COMETS[kind];
        let ore = 0;
        if (comet.buff !== null) {
            s.buffs[comet.buff] = { kind, multiplier: comet.multiplier * cometPower(s), until: time + cometLength(s, kind) };
        } else {
            const production = sum(Object.values(buildingRates(s))) * buff(s, 'production', time);
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

    // A new run, for a Warp Jump, Big Crunch or Big Rip (hbHubMinerNewRun())
    function newRun(s) {
        const effects = skillEffects(s);
        const kept = [...(hasDark(s, 'memory') ? CONFIG.clickTypes : []), ...(hasAnti(s, 'eternal') ? CONFIG.eternalTypes : []), ...effects.keep];
        s.upgrades = s.upgrades.filter((id) => kept.includes(UPGRADES[id].type));
        s.buildings = Object.fromEntries(BUILDING_IDS.map((id) => [id, effects.start[id] ?? 0]));
        if (hasDark(s, 'headStart')) s.buildings.drone = Math.max(s.buildings.drone, CONFIG.headStart);
        s.ore = Math.max(hasAnti(s, 'leftovers') ? CONFIG.leftoverOre : 0, effects.startOre);
        s.runOre = 0;
        s.buffs = {};
        s.runStarted = s.tick;
    }

    function warp(s) {
        const gain = warpGain(s);
        if (gain < 1) return false;

        newRun(s);
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

        if (!skillEffects(s).keepAnti) s.antiUpgrades = [];
        s.antimatter = 0;
        s.antimatterEarned = 0;
        if (!hasAnti(s, 'echo')) s.darkUpgrades = [];
        s.dark = 0;
        s.darkEarned = 0;
        s.universeOre = 0;
        s.strange += gain;
        s.strangeEarned += gain;
        s.rips++;
        newRun(s);
        return gain;
    }

    // Returns false when it didn't count, otherwise something truthy (what click() and catchComet() return, the
    // Dark Matter from a warp, the Antimatter from a crunch, the Strange Matter from a rip)
    function apply(s, action) {
        switch (action.type) {
            case 'click':
                return action.count >= 1 ? click(s, action.count, action.auto === true) : false;
            case 'buy': {
                const { building, amount } = action;
                if (!BUILDINGS[building] || ![1, 10, 100].includes(amount) || (amount > 1 && !has(s, 'bulk'))) return false;
                const cost = buildingCost(s, building, amount);
                if (!canAfford(s, cost)) return false;
                s.ore = Math.max(0, s.ore - cost);
                s.buildings[building] += amount;
                return true;
            }
            case 'upgrade': {
                const upgrade = UPGRADES[action.id];
                if (!upgrade || has(s, action.id) || !upgradeUnlocked(s, upgrade) || !canAfford(s, upgradeCost(s, action.id))) return false;
                s.ore = Math.max(0, s.ore - upgradeCost(s, action.id));
                s.upgrades.push(action.id);
                return true;
            }
            case 'dark': {
                const upgrade = DARK_UPGRADES[action.id];
                if (!upgrade || hasDark(s, action.id) || s.dark < upgrade.cost) return false;
                s.dark -= upgrade.cost;
                s.darkUpgrades.push(action.id);
                if (action.id === 'headStart') s.buildings.drone = Math.max(s.buildings.drone, CONFIG.headStart);
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
            case 'comet':
                return catchComet(s);
            case 'warp':
                return warp(s);
            case 'crunch':
                return crunch(s);
            case 'rip':
                return rip(s);
            default:
                return false;
        }
    }

    function stat(s, name) {
        switch (name) {
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
    // crystal, glowing alien core, molten, amethyst, neutron star, black hole core
    const STAGES = [0, 1e5, 1e8, 1e11, 1e14, 1e17, 1e20, 1e23, 1e26];
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

    // Whether the popup about the hot keys has been shown in this browser (see showKeysHelp())
    const KEYS_HELP_KEY = 'hbHubMinerKeysHelp';

    const serverNow = () => Date.now() + clockOffset;

    // Runs the game up to now, but no further than the server will count as playing since its last save (after time
    // away it's the server that works out what was mined, at the offline rate, so the next save is made right away)
    function catchUp() {
        const now = serverNow();
        const limit = lastServerTick + CONFIG.onlineMs;
        advance(state, Math.min(now, limit));
        if (now > limit && Date.now() - lastSentAt > 3000) save();
    }

    // Does something now and keeps it for the next save. Returns what apply() returns.
    function act(action) {
        if (!isPlaying) return false;
        catchUp();
        const result = apply(state, action);
        if (!result) return false;
        if (!['click', 'buy', 'upgrade'].includes(action.type)) note('Did ' + action.type + (action.id ? ' ' + action.id : '')); // not the ones Auto Buy does all the time

        // Clicks in the same second go as one (unless one looks like an auto-clicker's and the other doesn't)
        const last = pending[pending.length - 1];
        const at = Math.round(state.tick);
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
        state = saved;
        if (!state.buffs || Array.isArray(state.buffs)) state.buffs = {};
        lastServerTick = state.tick;
        for (const action of pending) {
            advance(state, Math.max(state.tick, action.at));
            apply(state, action);
            checkAchievements(state);
        }
        if (isFirst) announced = new Set(state.achievements);
        storeKey = '';
        darkKey = '';
        antiKey = '';
        treeKey = '';
    }

    const KEEPALIVE_BYTES = 60000; // a bit under the 64 KB browsers allow a keepalive request

    async function save({ claim = false, keepalive = false } = {}) {
        if (isSending || (!isPlaying && !claim)) return;
        const was = step('save');
        isSending = true;
        clearTimeout(saveSoonTimer);
        const batch = pending;
        pending = [];
        inflight = batch;
        const sentAt = Date.now();
        lastSentAt = sentAt;
        const body = new URLSearchParams({ action: 'sync', tab: TAB, actions: JSON.stringify(batch) });
        if (claim) body.set('claim', '1');
        if (claim && recovered.length > 0) body.set('recovered', JSON.stringify(recovered));
        // A keepalive request can't be bigger than 64 KB (the browser fails it at once), so a big batch goes as a normal
        // one, which still gets there as long as the page isn't closed
        if (body.toString().length > KEEPALIVE_BYTES) keepalive = false;
        writeRun(); // so a crash while it's on its way still has the batch
        note((claim ? 'Loading the save' : 'Saving') + ': ' + batch.length + ' actions' + (claim && recovered.length > 0 ? ', ' + recovered.length + ' recovered' : '')
            + (keepalive ? ' (hidden or closing)' : ''));
        step(was);

        try {
            const response = await fetch('/games/ore-mining-inc/save', {
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
            step('save: adopt');
            adopt(data.state, claim);
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

    // Saves every so often while the page is visible, and when it's hidden or closed
    setInterval(() => {
        step('save timer');
        if (isPlaying && !document.hidden && Date.now() - lastSentAt >= (saveFailed ? 10 : CONFIG.saveSeconds) * 1000) save();
        renderSave();
        step(null);
    }, 1000);
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

    function frame() {
        if (!isPlaying) return;
        step('frame');
        framesSinceBeat++; // for the frame rate in crash reports (see beat())
        if (!document.hidden) {
            catchUp();
            oreText.textContent = format(state.ore, { long: true });
            rateText.textContent = format(rate(state, state.tick), { decimals: true });
        }
        requestAnimationFrame(frame);
        step(null);
    }

    // The slower parts, a few times a second. Each part tells the watchdog what it's doing, so a freeze in one of them
    // says which.
    setInterval(() => {
        if (!isPlaying || document.hidden) return;
        step('tick: achievements');
        checkAchievements(state);
        renderAchievementsUnlocked();
        step('tick: Auto Buy');
        autoBuy();
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
        step('tick: menu');
        renderMenu();
        step(null);
    }, 200);
    setInterval(() => {
        if (!isPlaying || document.hidden || !statsPopup.open) return;
        step('stats');
        renderStats();
        step(null);
    }, 1000);

    // withNews: a new line in the news ticker too (not for what Autopilot and Auto Crunch do every few seconds)
    function renderAll(withNews = true) {
        catchUp();
        renderStore();
        renderBuffs();
        renderComet();
        renderWarp();
        renderCrunch();
        renderRip();
        renderMenu();
        renderAchievements();
        renderStats();
        if (withNews) renderTicker();
    }

    // The Dark Matter, Antimatter and Strange Matter in the menu, and a dot on a view with something to do there: a
    // jump, crunch or rip that would give something, or an upgrade or skill that can be bought
    function renderMenu() {
        menuCounts.darkMatter.textContent = state.warps > 0 ? format(state.dark) : '';
        menuCounts.antimatter.textContent = state.crunches > 0 ? format(state.antimatter) : '';
        menuCounts.strangeMatter.textContent = state.rips > 0 ? format(state.strange) : '';
        menuNews.darkMatter.hidden = warpGain(state) < 1
            && !Object.entries(DARK_UPGRADES).some(([id, upgrade]) => !hasDark(state, id) && state.dark >= upgrade.cost);
        menuNews.antimatter.hidden = crunchGain(state) < 1
            && !Object.entries(ANTI_UPGRADES).some(([id, upgrade]) => !hasAnti(state, id) && state.antimatter >= upgrade.cost);
        menuNews.strangeMatter.hidden = ripGain(state) < 1
            && !Object.entries(SKILLS).some(([id, skill]) => skillOpen(state, id) && state.strange >= skill.cost);
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

    function renderStore() {
        const { shown, mystery } = visibleBuildings();
        const upgrades = availableUpgrades();
        if (!has(state, 'bulk')) buyAmount = 1;
        const offset = keyOffset(shown.length);
        const hasKeys = has(state, 'quickKeys');
        const key = [shown.join(), mystery, upgrades.join(), buyAmount, has(state, 'bulk'), hasKeys, hasDark(state, 'lateKeys'), lateKeys, offset,
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
            for (const button of amounts.querySelectorAll('[data-amount]')) {
                const isActive = Number(button.dataset.amount) === buyAmount;
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
            const cost = buildingCost(state, id, buyAmount);
            const each = owned > 0 ? rates[id] / owned : buildingRates({ ...state, buildings: { ...state.buildings, [id]: 1 } })[id];
            button.classList.toggle('is-affordable', canAfford(state, cost));
            button.querySelector('.miner-building-cost').textContent = (buyAmount > 1 ? '×' + buyAmount + ': ' : '') + format(cost) + ' ore';
            button.querySelector('.miner-building-info').textContent = 'Each mines ' + format(each, { decimals: true }) + '/s'
                + (owned > 0 && total > 0 ? ' · ' + Math.round(rates[id] / total * 100) + '% of your production' : '');
            button.querySelector('.miner-building-owned').textContent = owned > 0 ? format(owned) : '';
            button.setAttribute('aria-label', BUILDINGS[id].name + ', you have ' + owned + '. Buy ' + buyAmount + ' for ' + format(cost) + ' ore.');
        }

        clickText.textContent = format(clickValue(state, state.tick), { decimals: true });
        bot.hidden = autoClicks(state) === 0;
        renderScene();

        asteroid.dataset.stage = String(STAGES.findLastIndex((at) => state.runOre >= at));
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
        if (!isOnScreen) {
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
        renderAutoSwitch(autoWarpSwitch, skillEffects(state).autoWarp, isAutoWarping);

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
        renderAutoSwitch(autoCrunchSwitch, skillEffects(state).autoCrunch, isAutoCrunching);

        const key = state.antimatter + '|' + state.antiUpgrades.join();
        if (key === antiKey) return;
        antiKey = key;
        antiOwned.textContent = state.antiUpgrades.length + ' of ' + Object.keys(ANTI_UPGRADES).length + ' bought';
        antiList.replaceChildren(...prestigeUpgrades(ANTI_UPGRADES, state.antiUpgrades, state.antimatter, 'anti', 'Antimatter'));
    }

    // The Autopilot or Auto Crunch switch: only there with its skill, lit while it's on
    function renderAutoSwitch(button, isThere, isOn) {
        button.hidden = !isThere;
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
        ripButton.disabled = gain < 1;
        if (!next) {
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
            : state.strange < skill.cost ? 'You need ' + format(skill.cost - state.strange) + ' more Strange Matter.' : '';
        skillInfo.cost.textContent = format(skill.cost) + ' Strange Matter';
        skillInfo.learn.hidden = isLearned;
        skillInfo.learn.disabled = !isOpen || state.strange < skill.cost;
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
            const isDone = state.achievements.includes(id);
            const item = element('li', 'miner-achievement' + (isDone ? ' is-done' : ''));
            item.dataset.achievement = id;
            item.append(iconBox('miner-achievement-icon', isDone ? 'trophy' : 'lock'),
                element('span', 'miner-achievement-name', achievement.name),
                element('span', 'miner-achievement-text', achievement.text));
            item.setAttribute('aria-label', achievement.name + ': ' + achievement.text + (isDone ? ' Done.' : ' Not done yet.'));
            return item;
        }));
        for (const count of achievementCounts) count.textContent = state.achievements.length + ' / ' + Object.keys(ACHIEVEMENTS).length;
    }

    function renderAchievementsUnlocked() {
        const fresh = state.achievements.filter((id) => !announced.has(id));
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
            ['Ore mined ever', format(state.allOre)],
            ['Ore mined this run', format(state.runOre)],
            ['Ore from clicks', format(state.clickOre)],
            ['Clicks', format(state.clicks)],
            ['Critical clicks', format(state.crits)],
            ['Comets caught', format(state.comets)],
            ['Diamond Comets caught', format(state.diamonds)],
            ['Warp Jumps', format(state.warps)],
            ...(state.crunches > 0 ? [
                ['Ore mined this universe', format(state.universeOre)],
                ['Big Crunches', format(state.crunches)],
                [state.rips > 0 ? 'Antimatter earned this multiverse' : 'Antimatter earned', format(state.antimatterEarned)],
            ] : []),
            ...(state.rips > 0 ? [
                ['Big Rips', format(state.rips)],
                ['Strange Matter earned', format(state.strangeEarned)],
                ['Skills', state.skills.length + ' / ' + Object.keys(SKILLS).length],
            ] : []),
            ['Buildings', format(sum(Object.values(state.buildings)))],
            ['Upgrades', state.upgrades.length + ' / ' + Object.keys(UPGRADES).length],
            ['Playing since', formatDate(state.started)],
            ['This run started', formatDate(state.runStarted)],
        ];
        statList.replaceChildren(...rows.map(([name, value]) => {
            const row = element('div');
            row.append(element('dt', '', name), element('dd', '', value));
            return row;
        }));
    }

    // ----- Messages in the corner

    // iconName is one of the icons (see iconBox()) to show in front of the text, or '' for none
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
    ];
    let lastNews = -1;

    function renderTicker() {
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
        const box = field.getBoundingClientRect();
        const x = fromKeyboard ? box.width / 2 : event.clientX - box.left;
        const y = fromKeyboard ? box.height / 2 : event.clientY - box.top;
        burst(x, y, result.mined, result.crits > 0);
        sound(result.crits > 0 ? 'crit' : 'click');
        if (auto && !wasAuto) {
            toast('robot', 'Hey! Looks like you\'re using an auto-clicker, so your clicks are only worth '
                + CONFIG.autoValue * 100 + '% now.', '', 9000);
        }
        wasAuto = auto;
        asteroid.classList.remove('is-hit');
        void asteroid.offsetWidth;
        asteroid.classList.add('is-hit');
        oreText.textContent = format(state.ore, { long: true });
    }

    asteroid.addEventListener('click', (event) => mine(event, event.detail === 0));

    // Holding Enter on the asteroid would click it over and over, so like Space, only the first press counts
    asteroid.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.repeat) event.preventDefault();
    });

    // ----- The store

    amounts.addEventListener('click', (event) => {
        const button = event.target.closest('[data-amount]');
        if (!button) return;
        buyAmount = Number(button.dataset.amount);
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
    // parts: rateParts(state), when it's already been worked out; each building is tried on top of it.
    function bestBuilding(amount, parts = rateParts(state)) {
        const before = rate(state, Infinity, parts);
        const synergies = rateSynergies(state);
        let best = null;
        for (const id of visibleBuildings().shown) {
            const cost = buildingCost(state, id, amount);
            if (!canAfford(state, cost)) continue;
            const after = { ...state, buildings: { ...state.buildings, [id]: state.buildings[id] + amount } };
            const counted = synergies.get(id);
            const gain = rate(after, Infinity, counted ? ratePartsRecounted(after, parts, counted) : parts) - before;
            if (!best || gain / cost > best.gain / best.cost) best = { id, cost, gain };
        }
        return best;
    }

    // ----- Auto Buy (the Dark Matter upgrade): while it's switched on, a few times a second it buys whatever pays for
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

    function autoBuy() {
        if (!isAutoBuying || !hasDark(state, 'autoBuy')) return;
        catchUp();
        for (let i = 0; i < AUTO_BUY_PER_TICK; i++) {
            const action = autoBuyChoice();
            if (!action || !act(action)) return;
            if (action.type === 'upgrade') toast(UPGRADES[action.id].icon, 'Auto Buy: ' + UPGRADES[action.id].name + ': ' + UPGRADES[action.id].text);
        }
    }

    buildingList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-building]');
        if (!button) return;
        if (act({ type: 'buy', building: button.dataset.building, amount: buyAmount })) {
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
    // seconds)
    function doWarp(isAuto) {
        const gain = act({ type: 'warp' });
        if (!gain) return;
        if (!isAuto) {
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
    // jump would at least double the Dark Matter earned in this universe, or Big Crunches once a crunch would give at
    // least AUTO_CRUNCH_SHARE of the Antimatter earned in this multiverse. Through act(), like the buttons, so the
    // server checks them as usual. Only while the page is open, like Auto Buy.
    const AUTO_CRUNCH_SHARE = 0.25;

    function autoPrestige() {
        const effects = skillEffects(state);
        if (isAutoCrunching && effects.autoCrunch) {
            catchUp();
            const gain = crunchGain(state);
            if (gain >= 1 && gain >= state.antimatterEarned * AUTO_CRUNCH_SHARE) {
                doCrunch(true);
                return;
            }
        }
        if (isAutoWarping && effects.autoWarp) {
            catchUp();
            const gain = warpGain(state);
            if (gain >= 1 && gain >= state.darkEarned) doWarp(true);
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

    // ----- Hot keys: each one presses the button it stands for, so they do exactly what a click would.
    // Space mines (a held-down key only counts once), C catches the comet (once the Comet Hot Key upgrade is bought, it's
    // lost on a Warp Jump like the other upgrades). With the Hot Key Panel upgrade (lost the same way, and needed before
    // the Comet Hot Key shows up), 1-9 and 0 buy the buildings in the order they're
    // listed (the last ten, with Long-Range Hot Keys switched on: see keyOffset()), U buys the
    // cheapest upgrade you can afford and B the affordable building with the most ore/s for its price (at the ×1/×10/×100
    // picked). K flips the Long-Range switch, A switches between ×1, ×10
    // and ×100, W opens the Warp Jump box, X the Big Crunch box, R the Big Rip box and M turns the sound on or off. They're left alone while typing, with Ctrl/Alt/Cmd held (the browser's own
    // shortcuts) and while a box is open. The buying keys (1-9, 0, B and U) can be held down to keep buying; the others
    // only count once per press, so holding M doesn't flick the sound on and off.

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
            const options = [...amounts.querySelectorAll('[data-amount]')];
            const now = options.findIndex((button) => Number(button.dataset.amount) === buyAmount);
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
        if (!isPlaying || event.ctrlKey || event.altKey || event.metaKey || document.querySelector('dialog[open]')) return;
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
        placeSkillInfo(); // the screen may have changed size while the tree was out of sight
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
            state,
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

    const RUN_KEY = 'hbHubMinerRun:';
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
    // newest one in the words and all that would be sent under "What's in it". If the player says yes, the newest
    // CRASH_SEND_MAX go to /games/ore-mining-inc/crash; either way they're deleted. Closing the popup without answering
    // keeps them, to ask again next time.

    const CRASH_SEND_MAX = 5;
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

    // The report as it's sent: without the watchdog's id for it
    function sentReport(report) {
        const { id, ...rest } = report;
        return rest;
    }

    async function askAboutCrashes() {
        const hangs = await readHangs();
        const hungTabs = new Set(hangs.map((hang) => hang.page.tab));
        crashes = [...hangs, ...crashRuns.map((run) => run.report).filter((report) => !hungTabs.has(report.page.tab))]
            .sort((a, b) => b.page.lastSeen - a.page.lastSeen);
        hangIds = hangs.map((hang) => hang.id);
        if (crashes.length === 0) return;
        note('Found ' + crashes.length + ' crash' + (crashes.length === 1 ? '' : 'es') + ' from before');
        const more = crashes.length - 1;
        crashDialogText.textContent = crashText(crashes[0]) + (more > 0 ? ' It happened ' + more + ' more ' + (more === 1 ? 'time' : 'times') + ' before that too.' : '');
        crashDialogJson.textContent = JSON.stringify(crashes.slice(0, CRASH_SEND_MAX).map(sentReport), null, 2);
        crashDialog.showModal();
    }

    async function answerCrashes(isSending) {
        crashDialog.close();
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

        let sent = 0;
        for (const report of crashes.slice(0, CRASH_SEND_MAX)) {
            try {
                const response = await fetch('/games/ore-mining-inc/crash', {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams({ action: 'report', kind: report.kind, report: JSON.stringify(sentReport(report)) }),
                });
                if (response.ok) sent++;
            } catch {
                // counted as not sent
            }
        }
        toast('', sent > 0 ? 'Thanks! The crash report was sent, so it can be looked into.' : 'Couldn\'t send the crash report, sorry.', '', 6000);
    }

    root.querySelector('[data-miner-crash-send]').addEventListener('click', () => answerCrashes(true));
    root.querySelector('[data-miner-crash-skip]').addEventListener('click', () => answerCrashes(false));

    note('Opened on the ' + root.dataset.view + ' view');
    watchSlowFrames();
    setInterval(beat, BEAT_MS);
    askAboutCrashes();
    save({ claim: true });
})();
