// Space Ore Miner (games/spaceOreMiner/): runs the clicker game in the browser and saves it to the server.
// The page gives the game's data and numbers in data-config (hbHubMinerConfig() in
// assets/php/games/spaceOreMiner/miner.php: buildings, upgrades, darkUpgrades, antiUpgrades, achievements, comets and
// the numbers)
// and a random id for this tab in data-tab. The save itself comes from the first save, which also claims the game
// for this tab (a game open in another tab then gets 409 "otherTab" and stops).
// The rules in "The game" below are a copy of miner.php's, function for function, because the server plays every
// action again to check it: each click, purchase, comet, Warp Jump and Big Crunch is done here right away (so it's
// smooth) and also kept in a list with its time, which goes to /games/spaceOreMiner/save every 30 seconds, when the tab is hidden and
// when it's closed. The server answers with the save as it worked it out, and the game carries on from that, with
// whatever was done while the save was on its way done again on top.
// Times are the server's Unix ms (the difference to this computer's clock is measured on every save).
// The page has three views (Ore, Dark Matter, Antimatter, in data-miner-view): the menu's links switch between them here,
// with history.pushState, so the game keeps running instead of the page loading again. Stats, achievements and how to
// play are popups (data-miner-popup), opened by the buttons by the title (data-miner-open).
(() => {
    const root = document.querySelector('[data-miner]');
    if (!root) return;

    const CONFIG = JSON.parse(root.dataset.config);
    const TAB = root.dataset.tab;
    const BUILDINGS = CONFIG.buildings;
    const BUILDING_IDS = Object.keys(BUILDINGS);
    const UPGRADES = CONFIG.upgrades; // cheapest first
    const DARK_UPGRADES = CONFIG.darkUpgrades;
    const ANTI_UPGRADES = CONFIG.antiUpgrades;
    const ACHIEVEMENTS = CONFIG.achievements;
    const COMETS = CONFIG.comets;
    const SALT_CRIT = 1; // HBHUB_MINER_SALT_* in miner.php
    const SALT_COMET_WAIT = 2;
    const SALT_COMET_KIND = 3;
    const SALT_DIAMOND = 4;

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
    const sum = (values) => values.reduce((total, value) => total + value, 0);
    const darkBonus = (s) => (hasDark(s, 'reactor') ? CONFIG.darkBonusReactor : CONFIG.darkBonus);
    const antiMultiplier = (s) => 1 + CONFIG.antiBonus * s.antimatterEarned;
    const cometPower = (s) => (hasAnti(s, 'antiComets') ? 2 : 1);

    // How much more each of a building costs than the one before (hbHubMinerCostGrowth())
    const costGrowth = (s, id) => (BUILDINGS[id].growth ?? CONFIG.costGrowth) - (hasDark(s, 'physics') ? CONFIG.costGrowthPhysics : 0);

    function buildingCost(s, id, amount) {
        const growth = costGrowth(s, id);
        return Math.ceil(BUILDINGS[id].cost * growth ** s.buildings[id] * (growth ** amount - 1) / (growth - 1));
    }

    const canAfford = (s, cost) => cost <= s.ore * (1 + 1e-9) + 1e-9;

    function buildingRates(s) {
        const multipliers = Object.fromEntries(BUILDING_IDS.map((id) => [id, 1]));
        const crew = Object.fromEntries(BUILDING_IDS.map((id) => [id, 0]));
        let global = (1 + darkBonus(s) * s.darkEarned) * antiMultiplier(s);
        if (hasDark(s, 'darkEngines')) global *= 1.5;
        if (hasAnti(s, 'annihilation')) global *= 2;
        for (const upgradeId of s.upgrades) {
            const upgrade = UPGRADES[upgradeId];
            if (upgrade.type === 'building') {
                multipliers[upgrade.building] *= 2;
            } else if (upgrade.type === 'synergy') {
                multipliers[upgrade.building] *= 1 + upgrade.per * Math.floor(s.buildings[upgrade.source] / upgrade.every);
            } else if (upgrade.type === 'crew') {
                crew[upgrade.building] += upgrade.value;
            } else if (upgrade.type === 'global') {
                global *= 1 + upgrade.value;
            }
        }
        const owned = sum(Object.values(s.buildings));
        return Object.fromEntries(BUILDING_IDS.map((id) => [id,
            (BUILDINGS[id].rate + crew[id] * (owned - s.buildings[id])) * s.buildings[id] * multipliers[id] * global]));
    }

    function buff(s, kind, time) {
        const running = s.buffs[kind];
        return running && running.until > time ? running.multiplier : 1;
    }

    function clickValue(s, time) {
        let value = hasDark(s, 'darkPickaxe') ? CONFIG.darkPickaxe : 1;
        let share = 0;
        for (const upgradeId of s.upgrades) {
            if (UPGRADES[upgradeId].type === 'click') value *= UPGRADES[upgradeId].value;
            else if (UPGRADES[upgradeId].type === 'clickPct') share += UPGRADES[upgradeId].value;
        }
        const production = share > 0 ? sum(Object.values(buildingRates(s))) * buff(s, 'production', time) : 0;
        return (value + share * production) * buff(s, 'click', time);
    }

    // How many times a second the auto upgrades click, together (hbHubMinerAutoClicks())
    function autoClicks(s) {
        let clicks = 0;
        for (const upgradeId of s.upgrades) {
            if (UPGRADES[upgradeId].type === 'auto') clicks += UPGRADES[upgradeId].value;
        }
        return clicks;
    }

    // time Infinity: the steady rate, without comets
    function rate(s, time) {
        let total = sum(Object.values(buildingRates(s))) * buff(s, 'production', time);
        const clicks = autoClicks(s);
        if (clicks > 0) total += clicks * clickValue(s, time);
        return total;
    }

    function earn(s, ore) {
        s.ore += ore;
        s.runOre += ore;
        s.universeOre += ore;
        s.allOre += ore;
    }

    function cometWait(s, n) {
        const [min, max] = CONFIG.cometWaitMs;
        const wait = min + random(s.seed, n, SALT_COMET_WAIT) * (max - min);
        return Math.floor(has(s, 'radar') ? wait / 2 : wait);
    }

    const cometStay = (s) => (has(s, 'magnet') ? CONFIG.cometStayMs * 2 : CONFIG.cometStayMs);

    function cometKind(s) {
        const n = s.comet.n;
        if (has(s, 'luckyStars') && random(s.seed, n, SALT_DIAMOND) < CONFIG.diamondChance) return 'diamond';
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
                ore *= CONFIG.offlineRate * (hasDark(s, 'timeDilation') ? 2 : 1);
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
        return Object.entries(need.owned ?? {}).every(([id, count]) => s.buildings[id] >= count);
    }

    function darkTotal(universeOre) {
        const units = universeOre / CONFIG.darkUnit;
        let total = Math.floor(units ** (1 / 3));
        while ((total + 1) ** 3 <= units) total++;
        while (total > 0 && total ** 3 > units) total--;
        return total;
    }

    function warpGain(s) {
        if (s.runOre < CONFIG.warpOre) return 0;
        const total = Math.floor(darkTotal(s.universeOre) * antiMultiplier(s) * (hasAnti(s, 'converter') ? 2 : 1));
        return Math.max(0, total - s.darkEarned);
    }

    function antiTotal(darkEarned) {
        let total = 0;
        while ((total + 1) ** 2 * CONFIG.antiUnit <= darkEarned) total++;
        return total;
    }
    const crunchGain = (s) => (s.darkEarned >= CONFIG.crunchDark ? antiTotal(s.darkEarned) : 0);

    // Whether a click at time comes from an auto-clicker: too fast for too long (hbHubMinerFastClick())
    function fastClick(s, time) {
        const fast = s.fast;
        const count = fast.times.length;
        time = Math.max(time, count > 0 ? fast.times[count - 1] : 0);
        fast.times.push(time);
        if (fast.times.length > CONFIG.fastClicks + 1) fast.times.shift();
        if (fast.times.length <= CONFIG.fastClicks || time - fast.times[0] >= 1000) fast.since = time;
        return time - fast.since >= CONFIG.fastMs;
    }

    // offsets: when each click was, in ms after s.tick. Returns the ore mined, how many of the clicks were critical and
    // whether any looked like an auto-clicker's.
    function click(s, count, offsets = []) {
        const value = clickValue(s, s.tick);
        const canCrit = has(s, 'crit');
        let mined = 0;
        let crits = 0;
        let auto = false;
        for (let i = 0; i < count; i++) {
            const offset = Number.isFinite(offsets[i]) ? Math.min(Math.max(Math.trunc(offsets[i]), 0), 999) : 0;
            let ore = value;
            if (canCrit && random(s.seed, s.clicks, SALT_CRIT) < CONFIG.critChance) {
                ore *= CONFIG.critMultiplier;
                s.crits++;
                crits++;
            }
            if (fastClick(s, s.tick + offset)) {
                ore *= CONFIG.fastValue;
                auto = true;
            }
            s.clicks++;
            mined += ore;
        }
        s.clickOre += mined;
        earn(s, mined);
        return { mined, crits, auto };
    }

    // Returns {kind, ore (what a lucky comet gave)}, or null when there wasn't one on screen
    function catchComet(s) {
        const time = s.tick;
        if (time < s.comet.at || time > s.comet.at + cometStay(s)) return null;

        const kind = cometKind(s);
        const comet = COMETS[kind];
        let ore = 0;
        if (comet.buff !== null) {
            s.buffs[comet.buff] = { kind, multiplier: comet.multiplier * cometPower(s), until: time + comet.ms * (has(s, 'tail') ? 2 : 1) };
        } else {
            const production = sum(Object.values(buildingRates(s))) * buff(s, 'production', time);
            ore = (Math.min(s.ore * CONFIG.luckyShare, production * CONFIG.luckySeconds) + 13)
                * (has(s, 'stardust') ? 2 : 1) * cometPower(s);
            earn(s, ore);
        }
        s.comets++;
        if (kind === 'diamond') s.diamonds++;
        s.comet.n++;
        s.comet.at = time + cometWait(s, s.comet.n);
        return { kind, ore };
    }

    // A new run, for a Warp Jump or a Big Crunch (hbHubMinerNewRun())
    function newRun(s) {
        const kept = [...(hasDark(s, 'memory') ? CONFIG.clickTypes : []), ...(hasAnti(s, 'eternal') ? CONFIG.eternalTypes : [])];
        s.upgrades = s.upgrades.filter((id) => kept.includes(UPGRADES[id].type));
        s.buildings = Object.fromEntries(BUILDING_IDS.map((id) => [id, 0]));
        if (hasDark(s, 'headStart')) s.buildings.drone = CONFIG.headStart;
        s.ore = hasAnti(s, 'leftovers') ? CONFIG.leftoverOre : 0;
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

    // Returns false when it didn't count, otherwise something truthy (what click() and catchComet() return, the
    // Dark Matter from a warp, the Antimatter from a crunch)
    function apply(s, action) {
        switch (action.type) {
            case 'click':
                return action.count >= 1 ? click(s, action.count, action.offsets) : false;
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
                if (!upgrade || has(s, action.id) || !upgradeUnlocked(s, upgrade) || !canAfford(s, upgrade.cost)) return false;
                s.ore = Math.max(0, s.ore - upgrade.cost);
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
            case 'comet':
                return catchComet(s);
            case 'warp':
                return warp(s);
            case 'crunch':
                return crunch(s);
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
            case 'allOre': case 'clicks': case 'crits': case 'comets': case 'diamonds': case 'warps': case 'crunches': return s[name];
            default: return s.buildings[name.slice(6)] ?? 0; // owned:<building>
        }
    }

    function checkAchievements(s) {
        for (const [id, achievement] of Object.entries(ACHIEVEMENTS)) {
            if (!s.achievements.includes(id) && stat(s, achievement.stat) >= achievement.at) s.achievements.push(id);
        }
    }

    // ----- Numbers and words

    const BIG_NUMBERS = [
        [1e33, 'decillion', 'Dc'], [1e30, 'nonillion', 'No'], [1e27, 'octillion', 'Oc'], [1e24, 'septillion', 'Sp'],
        [1e21, 'sextillion', 'Sx'], [1e18, 'quintillion', 'Qi'], [1e15, 'quadrillion', 'Qa'], [1e12, 'trillion', 'T'],
        [1e9, 'billion', 'B'], [1e6, 'million', 'M'],
    ];

    // 1,234 / 1.23M, or 1.234 million with long. decimals: one decimal under 100, for rates like 0.1
    function format(number, { long = false, decimals = false } = {}) {
        if (!Number.isFinite(number)) return '∞';
        if (number >= 1e36) return number.toExponential(2).replace('e+', 'e');
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

    // ----- The page

    const asteroid = root.querySelector('[data-miner-asteroid]');
    const field = root.querySelector('[data-miner-field]');
    const oreText = root.querySelector('[data-miner-ore]');
    const rateText = root.querySelector('[data-miner-rate]');
    const clickText = root.querySelector('[data-miner-click]');
    const saveText = root.querySelector('[data-miner-save]');
    const buffList = root.querySelector('[data-miner-buffs]');
    const bot = root.querySelector('[data-miner-bot]');
    const upgradeList = root.querySelector('[data-miner-upgrades]');
    const upgradesEmpty = root.querySelector('[data-miner-upgrades-empty]');
    const upgradesBox = root.querySelector('[data-miner-upgrades-box]');
    const upgradesMore = root.querySelector('[data-miner-upgrades-more]');
    const buildingList = root.querySelector('[data-miner-buildings]');
    const amounts = root.querySelector('[data-miner-amounts]');
    const keySwitch = root.querySelector('[data-miner-key-switch]');
    const darkText = root.querySelector('[data-miner-dark]');
    const warpNumbers = Object.fromEntries([...root.querySelectorAll('[data-miner-warp]')].map((node) => [node.dataset.minerWarp, node]));
    const warpButton = root.querySelector('[data-miner-warp-button]');
    const warpHint = root.querySelector('[data-miner-warp-hint]');
    const darkList = root.querySelector('[data-miner-dark-upgrades]');
    const crunchSection = root.querySelector('[data-miner-crunch-section]');
    const crunchLocked = root.querySelector('[data-miner-crunch-locked]');
    const antiText = root.querySelector('[data-miner-anti]');
    const crunchNumbers = Object.fromEntries([...root.querySelectorAll('[data-miner-crunch]')].map((node) => [node.dataset.minerCrunch, node]));
    const crunchButton = root.querySelector('[data-miner-crunch-button]');
    const crunchHint = root.querySelector('[data-miner-crunch-hint]');
    const antiList = root.querySelector('[data-miner-anti-upgrades]');
    const crunchDialog = root.querySelector('[data-miner-crunch-dialog]');
    const crunchDialogText = root.querySelector('[data-miner-crunch-dialog-text]');
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
    const soundButton = root.querySelector('[data-miner-sound]');
    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');

    // The asteroid changes as more is mined in a run, at this much ore mined this run: plain grey rock, iron, gold,
    // crystal, glowing alien core
    const STAGES = [0, 1e5, 1e8, 1e11, 1e14];
    // (how long it lasts, the ore a lucky one gave, its multiplier with Antimatter Comets)
    const COMET_TEXT = {
        frenzy: (ms, ore, times) => 'Frenzy! Everything mines ' + format(times) + ' times as fast for ' + Math.round(ms / 1000) + ' seconds.',
        lucky: (ms, ore) => 'Lucky! The comet was full of ore: +' + format(ore) + '.',
        storm: (ms, ore, times) => 'Click Storm! Clicks mine ' + format(times) + ' times as much for ' + Math.round(ms / 1000) + ' seconds.',
        diamond: (ms, ore, times) => 'DIAMOND RUSH! ' + format(times) + ' times the production for ' + Math.round(ms / 1000) + ' seconds!',
    };
    const BUFF_TEXT = { frenzy: 'Frenzy: production', storm: 'Click Storm: clicks', diamond: 'Diamond Rush: production' };

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
    let announced = new Set(); // achievements already shown
    let storeKey = '';
    let darkKey = '';
    let antiKey = '';
    let shownComet = -1; // the comet number on screen
    let saveSoonTimer = 0;

    // The Long-Range Hot Keys switch, kept in this browser like the sound. It only does anything with the upgrade.
    const LATE_KEYS_KEY = 'hbHubMinerLateKeys';
    let lateKeys = false;
    try {
        lateKeys = localStorage.getItem(LATE_KEYS_KEY) === 'on';
    } catch {
        // no storage (a private window): it starts off
    }

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

        // Clicks in the same second go as one, with when each was (offsets, ms after at) for the auto-clicker check
        const last = pending[pending.length - 1];
        const at = Math.round(state.tick);
        if (action.type === 'click' && last?.type === 'click' && at - last.at < 1000) {
            last.count += action.count;
            last.offsets.push(...Array(action.count).fill(at - last.at));
        } else {
            pending.push({ ...action, at, ...(action.type === 'click' ? { offsets: Array(action.count).fill(0) } : {}) });
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
    }

    async function save({ claim = false, keepalive = false } = {}) {
        if (isSending || (!isPlaying && !claim)) return;
        isSending = true;
        clearTimeout(saveSoonTimer);
        const batch = pending;
        pending = [];
        const sentAt = Date.now();
        lastSentAt = sentAt;
        const body = new URLSearchParams({ action: 'sync', tab: TAB, actions: JSON.stringify(batch) });
        if (claim) body.set('claim', '1');

        try {
            const response = await fetch('/games/spaceOreMiner/save', {
                method: 'POST', headers: { Accept: 'application/json' }, body, keepalive,
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409 && data.error === 'otherTab') {
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
            adopt(data.state, claim);
            lastSavedAt = Date.now();
            saveFailed = false;
            if (claim) start();
            if (data.offline && data.offline.ms > CONFIG.onlineMs) {
                toast('Welcome back! While you were away for ' + formatDuration(data.offline.ms) + ', your machines mined '
                    + format(data.offline.ore) + ' ore.', 'is-welcome', 9000);
            }
        } catch {
            pending = batch.concat(pending); // they go with the next save
            saveFailed = true;
            if (claim) {
                setSaveText('Couldn\'t load your save. Trying again…', true);
                setTimeout(() => save({ claim: true }), 5000);
            }
        } finally {
            isSending = false;
            renderSave();
        }
        if (document.hidden && pending.length > 0) save({ keepalive: true }); // more was done while it was on its way
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

    function stop() {
        isPlaying = false;
        asteroid.disabled = true;
        cometButton.hidden = true;
        setSaveText('Stopped: playing somewhere else.', true);
    }

    // Saves every so often while the page is visible, and when it's hidden or closed
    setInterval(() => {
        if (isPlaying && !document.hidden && Date.now() - lastSentAt >= (saveFailed ? 10 : CONFIG.saveSeconds) * 1000) save();
        renderSave();
    }, 1000);
    document.addEventListener('visibilitychange', () => {
        if (!isPlaying) return;
        save({ keepalive: document.hidden });
    });
    window.addEventListener('pagehide', () => {
        if (isPlaying && pending.length > 0) save({ keepalive: true });
    });

    // ----- Drawing

    function frame() {
        if (!isPlaying) return;
        if (!document.hidden) {
            catchUp();
            oreText.textContent = format(state.ore, { long: true });
            rateText.textContent = format(rate(state, state.tick), { decimals: true });
        }
        requestAnimationFrame(frame);
    }

    // The slower parts, a few times a second
    setInterval(() => {
        if (!isPlaying || document.hidden) return;
        checkAchievements(state);
        renderAchievementsUnlocked();
        renderStore();
        renderBuffs();
        renderComet();
        renderWarp();
        renderCrunch();
        renderMenu();
    }, 200);
    setInterval(() => {
        if (isPlaying && !document.hidden && statsPopup.open) renderStats();
    }, 1000);

    function renderAll() {
        catchUp();
        renderStore();
        renderBuffs();
        renderComet();
        renderWarp();
        renderCrunch();
        renderMenu();
        renderAchievements();
        renderStats();
        renderTicker();
    }

    // The Dark Matter and Antimatter in the menu, and a dot on a view with something to do there: a jump or crunch
    // that would give something, or an upgrade that can be bought
    function renderMenu() {
        menuCounts.darkMatter.textContent = state.darkEarned > 0 || state.dark > 0 ? format(state.dark) : '';
        menuCounts.antimatter.textContent = state.antimatterEarned > 0 ? format(state.antimatter) : '';
        menuNews.darkMatter.hidden = warpGain(state) < 1
            && !Object.entries(DARK_UPGRADES).some(([id, upgrade]) => !hasDark(state, id) && state.dark >= upgrade.cost);
        menuNews.antimatter.hidden = crunchGain(state) < 1
            && !Object.entries(ANTI_UPGRADES).some(([id, upgrade]) => !hasAnti(state, id) && state.antimatter >= upgrade.cost);
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

    // Which of the shown buildings the 1 key buys (the rest follow on): the first, or with Long-Range Hot Keys switched
    // on, the one that leaves the last nine for 1-9
    const keyOffset = (count) => (lateKeys && hasDark(state, 'lateKeys') ? Math.max(0, count - 9) : 0);

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
        return Object.keys(UPGRADES).filter((id) => !has(state, id) && upgradeUnlocked(state, UPGRADES[id]));
    }

    function renderStore() {
        const { shown, mystery } = visibleBuildings();
        const upgrades = availableUpgrades();
        if (!has(state, 'bulk')) buyAmount = 1;
        const offset = keyOffset(shown.length);
        const key = [shown.join(), mystery, upgrades.join(), buyAmount, has(state, 'bulk'), hasDark(state, 'lateKeys'), lateKeys, offset].join('|');

        if (key !== storeKey) { // something new to show: build the lists again
            storeKey = key;
            amounts.hidden = !has(state, 'bulk');
            keySwitch.hidden = !hasDark(state, 'lateKeys');
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
                    element('span', 'miner-upgrade-icon', upgrade.icon),
                    element('span', 'miner-upgrade-name', upgrade.name),
                    element('span', 'miner-upgrade-cost', format(upgrade.cost) + ' ore'),
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
                button.append(element('span', 'miner-building-icon', building.icon), main, element('span', 'miner-building-owned'));
                const index = shown.indexOf(id) - offset;
                if (index >= 0 && index < 9) { // the hot key that buys it (see "Hot keys" below)
                    button.prepend(element('kbd', 'miner-key', String(index + 1)));
                    button.setAttribute('aria-keyshortcuts', String(index + 1));
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
                box.append(element('span', 'miner-building-icon', '❔'), main);
                item.append(box);
                return item;
            })()] : []));
        }

        // What changes all the time: what's affordable, prices and counts
        for (const button of upgradeList.querySelectorAll('[data-upgrade]')) {
            button.classList.toggle('is-affordable', canAfford(state, UPGRADES[button.dataset.upgrade].cost));
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

        asteroid.dataset.stage = String(STAGES.findLastIndex((at) => state.runOre >= at));
    }

    function renderBuffs() {
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
        warpNumbers.gain.textContent = format(gain);
        warpNumbers.bonus.textContent = '+' + format(darkBonus(state) * 100 * state.darkEarned, { decimals: true }) + '%';
        warpButton.disabled = gain < 1;
        warpHint.textContent = state.runOre < CONFIG.warpOre
            ? 'Mine ' + format(CONFIG.warpOre - state.runOre) + ' more ore this run to unlock the Warp Jump.'
            : gain < 1 ? 'Mine a bit more first: a jump now wouldn\'t give you any Dark Matter.' : '';

        const key = state.dark + '|' + state.darkUpgrades.join();
        if (key === darkKey) return;
        darkKey = key;
        darkList.replaceChildren(...prestigeUpgrades(DARK_UPGRADES, state.darkUpgrades, state.dark, 'dark', 'Dark Matter'));
    }

    // The Dark Matter or Antimatter upgrades' buttons: kind is the data-* attribute and the class (is-dark, is-anti)
    function prestigeUpgrades(upgrades, owned, toSpend, kind, currency) {
        return Object.entries(upgrades).map(([id, upgrade]) => {
            const item = element('li');
            const button = element('button', 'miner-upgrade is-' + kind);
            button.type = 'button';
            button.dataset[kind] = id;
            const isOwned = owned.includes(id);
            button.classList.toggle('is-owned', isOwned);
            button.classList.toggle('is-affordable', !isOwned && toSpend >= upgrade.cost);
            button.disabled = isOwned;
            button.append(
                element('span', 'miner-upgrade-icon', upgrade.icon),
                element('span', 'miner-upgrade-name', upgrade.name),
                element('span', 'miner-upgrade-cost', isOwned ? 'Bought' : format(upgrade.cost) + ' ' + currency),
                element('span', 'miner-upgrade-text', upgrade.text),
            );
            item.append(button);
            return item;
        });
    }

    // The Antimatter view is locked until there's Dark Matter (or Antimatter) to talk about
    function renderCrunch() {
        crunchSection.hidden = state.darkEarned === 0 && state.antimatterEarned === 0;
        crunchLocked.hidden = !crunchSection.hidden;
        antiText.textContent = format(state.antimatter);
        if (crunchSection.hidden) return;
        const gain = crunchGain(state);
        crunchNumbers.dark.textContent = format(state.darkEarned);
        crunchNumbers.gain.textContent = format(gain);
        crunchNumbers.bonus.textContent = '+' + format(CONFIG.antiBonus * 100 * state.antimatterEarned) + '%';
        crunchButton.disabled = gain < 1;
        crunchHint.textContent = gain < 1
            ? 'Earn ' + format(CONFIG.crunchDark - state.darkEarned) + ' more Dark Matter in this universe to unlock the Big Crunch.' : '';

        const key = state.antimatter + '|' + state.antiUpgrades.join();
        if (key === antiKey) return;
        antiKey = key;
        antiList.replaceChildren(...prestigeUpgrades(ANTI_UPGRADES, state.antiUpgrades, state.antimatter, 'anti', 'Antimatter'));
    }

    function renderAchievements() {
        achievementList.replaceChildren(...Object.entries(ACHIEVEMENTS).map(([id, achievement]) => {
            const isDone = state.achievements.includes(id);
            const item = element('li', 'miner-achievement' + (isDone ? ' is-done' : ''));
            item.dataset.achievement = id;
            item.append(element('span', 'miner-achievement-icon', isDone ? '🏆' : '🔒'),
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
            toast('🏆 Achievement: ' + ACHIEVEMENTS[id].name + ' - ' + ACHIEVEMENTS[id].text, 'is-achievement');
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
            ...(state.antimatterEarned > 0 ? [
                ['Ore mined this universe', format(state.universeOre)],
                ['Big Crunches', format(state.crunches)],
                ['Antimatter earned', format(state.antimatterEarned)],
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

    function toast(text, className = '', keepFor = 5000) {
        const item = element('li', 'miner-toast ' + className, text);
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
        ['Astronaut drops a spoon. Spoon is now a satellite.'],
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
        ['Galactic lottery won by a rock. Rock spends it all on more rock.'],
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
        if (isPlaying && !document.hidden) renderTicker();
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

    asteroid.addEventListener('click', (event) => {
        // Only as many clicks as the server counts (clicksPerSecond, with a second's worth to start with)
        const now = performance.now();
        clickTokens = Math.min(CONFIG.clicksPerSecond, clickTokens + (now - clickTokensAt) / 1000 * CONFIG.clicksPerSecond);
        clickTokensAt = now;
        if (clickTokens < 1) return;
        clickTokens--;

        const result = act({ type: 'click', count: 1 });
        if (!result) return;
        const box = field.getBoundingClientRect();
        const fromKeyboard = event.detail === 0;
        const x = fromKeyboard ? box.width / 2 : event.clientX - box.left;
        const y = fromKeyboard ? box.height / 2 : event.clientY - box.top;
        burst(x, y, result.mined, result.crits > 0);
        sound(result.crits > 0 ? 'crit' : 'click');
        if (result.auto && !wasAuto) {
            toast('🤖 Hey! Looks like you\'re using an auto-clicker, so your clicks are only worth '
                + CONFIG.fastValue * 100 + '% now.', '', 9000);
        }
        wasAuto = result.auto;
        asteroid.classList.remove('is-hit');
        void asteroid.offsetWidth;
        asteroid.classList.add('is-hit');
        oreText.textContent = format(state.ore, { long: true });
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
            toast(UPGRADES[button.dataset.upgrade].icon + ' ' + UPGRADES[button.dataset.upgrade].name + ': ' + UPGRADES[button.dataset.upgrade].text);
            renderStore();
        }
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
            toast(DARK_UPGRADES[button.dataset.dark].icon + ' ' + DARK_UPGRADES[button.dataset.dark].name + ': ' + DARK_UPGRADES[button.dataset.dark].text);
            renderWarp();
            renderStore();
            saveSoon();
        }
    });

    // ----- Comets

    cometButton.addEventListener('click', () => {
        const result = act({ type: 'comet' });
        cometButton.hidden = true;
        if (!result) return;
        const comet = COMETS[result.kind];
        sound('catch');
        toast('☄️ ' + COMET_TEXT[result.kind](comet.ms * (has(state, 'tail') ? 2 : 1), result.ore, comet.multiplier * cometPower(state)),
            'is-comet is-' + result.kind, 6000);
        renderBuffs();
        renderStore();
    });

    // ----- Warp Jump

    warpButton.addEventListener('click', () => {
        const gain = warpGain(state);
        if (gain < 1) return;
        const kept = [...(hasDark(state, 'memory') ? ['your click upgrades'] : []), ...(hasAnti(state, 'eternal') ? ['Bulk Buying and your comet upgrades'] : [])];
        warpDialogText.textContent = 'You\'ll get ' + format(gain) + ' Dark Matter, which makes everything mine '
            + format(darkBonus(state) * 100 * gain, { decimals: true }) + '% faster until the next Big Crunch. Your ore, buildings and upgrades start over'
            + (kept.length > 0 ? ', but you keep ' + kept.join(' and ') + '.' : '.');
        warpDialog.showModal();
    });
    root.querySelector('[data-miner-warp-cancel]').addEventListener('click', () => warpDialog.close());
    root.querySelector('[data-miner-warp-confirm]').addEventListener('click', () => {
        warpDialog.close();
        const gain = act({ type: 'warp' });
        if (!gain) return;
        sound('warp');
        root.classList.remove('is-warping');
        void root.offsetWidth;
        root.classList.add('is-warping');
        toast('🌀 Welcome to a new galaxy! You got ' + format(gain) + ' Dark Matter.', 'is-comet', 7000);
        renderAll();
        saveSoon();
    });

    // ----- Big Crunch

    antiList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-anti]');
        if (!button) return;
        if (act({ type: 'anti', id: button.dataset.anti })) {
            sound('buy');
            toast(ANTI_UPGRADES[button.dataset.anti].icon + ' ' + ANTI_UPGRADES[button.dataset.anti].name + ': ' + ANTI_UPGRADES[button.dataset.anti].text);
            renderCrunch();
            renderStore();
            saveSoon();
        }
    });

    crunchButton.addEventListener('click', () => {
        const gain = crunchGain(state);
        if (gain < 1) return;
        crunchDialogText.textContent = 'You\'ll get ' + format(gain) + ' Antimatter, which makes everything mine '
            + format(CONFIG.antiBonus * 100 * gain) + '% faster and Warp Jumps give ' + format(CONFIG.antiBonus * 100 * gain)
            + '% more Dark Matter, for good. Your ore, buildings, upgrades and Dark Matter start over'
            + (hasAnti(state, 'echo') ? ', but you keep your Dark Matter upgrades.' : ', and so do your Dark Matter upgrades.');
        crunchDialog.showModal();
    });
    root.querySelector('[data-miner-crunch-cancel]').addEventListener('click', () => crunchDialog.close());
    root.querySelector('[data-miner-crunch-confirm]').addEventListener('click', () => {
        crunchDialog.close();
        const gain = act({ type: 'crunch' });
        if (!gain) return;
        sound('warp');
        root.classList.remove('is-crunching');
        void root.offsetWidth;
        root.classList.add('is-crunching');
        toast('💥 The universe collapsed and started over. You got ' + format(gain) + ' Antimatter.', 'is-comet', 7000);
        renderAll();
        saveSoon();
    });

    // ----- Hot keys: each one presses the button it stands for, so they do exactly what a click would.
    // Space mines (a held-down key only counts once), C catches the comet (once the Comet Hot Key upgrade is bought, it's
    // lost on a Warp Jump like the other upgrades), 1-9 buy the buildings in the order they're
    // listed (the last nine, with Long-Range Hot Keys switched on: see keyOffset()), K flips that switch, U buys the
    // cheapest upgrade you can afford, B the cheapest building (at the ×1/×10/×100 picked), A switches between ×1, ×10
    // and ×100, W opens the Warp Jump box, X the Big Crunch box and M turns the sound on or off. They're left alone while typing, with Ctrl/Alt/Cmd held (the browser's own
    // shortcuts) and while a box is open.

    const HOT_KEYS = {
        c: () => {
            if (has(state, 'cometKey') && !cometButton.hidden) cometButton.click();
        },
        u: () => {
            // Picked by cost from the buttons in the store, so it doesn't depend on the order they're listed in or on
            // an upgrade that's only just shown up and isn't drawn yet
            catchUp();
            let cheapest = null;
            for (const button of upgradeList.querySelectorAll('[data-upgrade]')) {
                const cost = UPGRADES[button.dataset.upgrade].cost;
                if (canAfford(state, cost) && (!cheapest || cost < UPGRADES[cheapest.dataset.upgrade].cost)) cheapest = button;
            }
            cheapest?.click();
        },
        b: () => {
            // Same as U, for the buildings, at the amount picked
            catchUp();
            let cheapest = null;
            let cheapestCost = 0;
            for (const button of buildingList.querySelectorAll('[data-building]')) {
                const cost = buildingCost(state, button.dataset.building, buyAmount);
                if (canAfford(state, cost) && (!cheapest || cost < cheapestCost)) {
                    cheapest = button;
                    cheapestCost = cost;
                }
            }
            cheapest?.click();
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
            if (!event.repeat) asteroid.click();
            return;
        }
        if (event.repeat) return;
        if (/^[1-9]$/.test(event.key)) {
            const buttons = buildingList.querySelectorAll('[data-building]');
            buttons[keyOffset(buttons.length) + Number(event.key) - 1]?.click();
            return;
        }
        const hotKey = HOT_KEYS[event.key.toLowerCase()];
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
        for (const [name, node] of Object.entries(views)) node.hidden = name !== view;
        for (const link of menu.querySelectorAll('[data-miner-view-link]')) {
            if (link.dataset.minerViewLink === view) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        }
        if (scrollY > 0) scrollTo({ top: 0 }); // the Ore view fits the screen from the top
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

    save({ claim: true });
})();
