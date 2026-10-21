// Space Ore Miner (games/spaceOreMiner/): runs the clicker game in the browser and saves it to the server.
// The page gives the game's data and numbers in data-config (hbHubMinerConfig() in
// assets/php/games/spaceOreMiner/miner.php: buildings, upgrades, darkUpgrades, achievements, comets and the numbers)
// and a random id for this tab in data-tab. The save itself comes from the first save, which also claims the game
// for this tab (a game open in another tab then gets 409 "otherTab" and stops).
// The rules in "The game" below are a copy of miner.php's, function for function, because the server plays every
// action again to check it: each click, purchase, comet and Warp Jump is done here right away (so it's smooth) and also
// kept in a list with its time, which goes to /games/spaceOreMiner/save every 30 seconds, when the tab is hidden and
// when it's closed. The server answers with the save as it worked it out, and the game carries on from that, with
// whatever was done while the save was on its way done again on top.
// Times are the server's Unix ms (the difference to this computer's clock is measured on every save).
(() => {
    const root = document.querySelector('[data-miner]');
    if (!root) return;

    const CONFIG = JSON.parse(root.dataset.config);
    const TAB = root.dataset.tab;
    const BUILDINGS = CONFIG.buildings;
    const BUILDING_IDS = Object.keys(BUILDINGS);
    const UPGRADES = CONFIG.upgrades; // cheapest first
    const DARK_UPGRADES = CONFIG.darkUpgrades;
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
    const sum = (values) => values.reduce((total, value) => total + value, 0);

    function buildingCost(s, id, amount) {
        const growth = hasDark(s, 'physics') ? CONFIG.costGrowthCheap : CONFIG.costGrowth;
        return Math.ceil(BUILDINGS[id].cost * growth ** s.buildings[id] * (growth ** amount - 1) / (growth - 1));
    }

    const canAfford = (s, cost) => cost <= s.ore * (1 + 1e-9) + 1e-9;

    function buildingRates(s) {
        const multipliers = Object.fromEntries(BUILDING_IDS.map((id) => [id, 1]));
        let global = 1 + CONFIG.darkBonus * s.darkEarned;
        if (hasDark(s, 'darkEngines')) global *= 1.5;
        for (const upgradeId of s.upgrades) {
            const upgrade = UPGRADES[upgradeId];
            if (upgrade.type === 'building') {
                multipliers[upgrade.building] *= 2;
            } else if (upgrade.type === 'synergy') {
                multipliers[upgrade.building] *= 1 + upgrade.per * Math.floor(s.buildings[upgrade.source] / upgrade.every);
            } else if (upgrade.type === 'global') {
                global *= 1 + upgrade.value;
            }
        }
        return Object.fromEntries(BUILDING_IDS.map((id) => [id, BUILDINGS[id].rate * s.buildings[id] * multipliers[id] * global]));
    }

    function buff(s, kind, time) {
        const running = s.buffs[kind];
        return running && running.until > time ? running.multiplier : 1;
    }

    function clickValue(s, time) {
        let value = 1;
        let share = 0;
        for (const upgradeId of s.upgrades) {
            if (UPGRADES[upgradeId].type === 'click') value *= UPGRADES[upgradeId].value;
            else if (UPGRADES[upgradeId].type === 'clickPct') share += UPGRADES[upgradeId].value;
        }
        const production = share > 0 ? sum(Object.values(buildingRates(s))) * buff(s, 'production', time) : 0;
        return (value + share * production) * buff(s, 'click', time);
    }

    // time Infinity: the steady rate, without comets
    function rate(s, time) {
        let total = sum(Object.values(buildingRates(s))) * buff(s, 'production', time);
        if (has(s, 'autoClicker')) total += UPGRADES.autoClicker.value * clickValue(s, time);
        return total;
    }

    function earn(s, ore) {
        s.ore += ore;
        s.runOre += ore;
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

    function darkTotal(allOre) {
        const units = allOre / CONFIG.darkUnit;
        let total = Math.floor(units ** (1 / 3));
        while ((total + 1) ** 3 <= units) total++;
        while (total > 0 && total ** 3 > units) total--;
        return total;
    }
    const warpGain = (s) => (s.runOre >= CONFIG.warpOre ? Math.max(0, darkTotal(s.allOre) - s.darkEarned) : 0);

    // Whether the last gaps between clicks vary too little to be a person's (hbHubMinerEvenClicks())
    function evenClicks(gaps) {
        if (gaps.length < CONFIG.evenClicks) return false;
        const mean = gaps.reduce((total, gap) => total + gap, 0) / gaps.length;
        if (mean <= 0) return false;
        let squares = 0;
        for (const gap of gaps) squares += (gap - mean) * (gap - mean);
        return Math.sqrt(squares / gaps.length) < CONFIG.evenDeviation * mean;
    }

    // Whether a click at time comes from an auto-clicker: like clockwork, or too evenly (hbHubMinerSteadyClick())
    function steadyClick(s, time) {
        const steady = s.steady;
        time = Math.max(time, steady.last);
        const gap = time - steady.last;
        if (Math.abs(gap - steady.gap) > CONFIG.steadyToleranceMs) steady.since = time;
        steady.gap = gap;
        steady.last = time;
        steady.gaps.push(gap);
        if (steady.gaps.length > CONFIG.evenClicks) steady.gaps.shift();
        return time - steady.since >= CONFIG.steadyMs || evenClicks(steady.gaps);
    }

    // offsets: when each click was, in ms after s.tick. Returns the ore mined, how many of the clicks were critical and
    // whether any came in like clockwork.
    function click(s, count, offsets = []) {
        const value = clickValue(s, s.tick);
        const canCrit = has(s, 'crit');
        let mined = 0;
        let crits = 0;
        let steady = false;
        for (let i = 0; i < count; i++) {
            const offset = Number.isFinite(offsets[i]) ? Math.min(Math.max(Math.trunc(offsets[i]), 0), 999) : 0;
            let ore = value;
            if (canCrit && random(s.seed, s.clicks, SALT_CRIT) < CONFIG.critChance) {
                ore *= CONFIG.critMultiplier;
                s.crits++;
                crits++;
            }
            if (steadyClick(s, s.tick + offset)) {
                ore *= CONFIG.steadyValue;
                steady = true;
            }
            s.clicks++;
            mined += ore;
        }
        s.clickOre += mined;
        earn(s, mined);
        return { mined, crits, steady };
    }

    // Returns {kind, ore (what a lucky comet gave)}, or null when there wasn't one on screen
    function catchComet(s) {
        const time = s.tick;
        if (time < s.comet.at || time > s.comet.at + cometStay(s)) return null;

        const kind = cometKind(s);
        const comet = COMETS[kind];
        let ore = 0;
        if (comet.buff !== null) {
            s.buffs[comet.buff] = { kind, multiplier: comet.multiplier, until: time + comet.ms * (has(s, 'tail') ? 2 : 1) };
        } else {
            const production = sum(Object.values(buildingRates(s))) * buff(s, 'production', time);
            ore = Math.min(s.ore * CONFIG.luckyShare, production * CONFIG.luckySeconds) + 13;
            earn(s, ore);
        }
        s.comets++;
        if (kind === 'diamond') s.diamonds++;
        s.comet.n++;
        s.comet.at = time + cometWait(s, s.comet.n);
        return { kind, ore };
    }

    function warp(s) {
        const gain = warpGain(s);
        if (gain < 1) return false;

        const keepClicks = hasDark(s, 'memory');
        s.upgrades = s.upgrades.filter((id) => keepClicks && CONFIG.clickTypes.includes(UPGRADES[id].type));
        s.buildings = Object.fromEntries(BUILDING_IDS.map((id) => [id, 0]));
        if (hasDark(s, 'headStart')) s.buildings.drone = CONFIG.headStart;
        s.ore = 0;
        s.runOre = 0;
        s.buffs = {};
        s.dark += gain;
        s.darkEarned += gain;
        s.warps++;
        s.runStarted = s.tick;
        return gain;
    }

    // Returns false when it didn't count, otherwise something truthy (what click() and catchComet() return, the
    // Dark Matter from a warp)
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
            case 'comet':
                return catchComet(s);
            case 'warp':
                return warp(s);
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
            case 'allOre': case 'clicks': case 'crits': case 'comets': case 'diamonds': case 'warps': return s[name];
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
    const stageText = root.querySelector('[data-miner-stage]');
    const saveText = root.querySelector('[data-miner-save]');
    const buffList = root.querySelector('[data-miner-buffs]');
    const bot = root.querySelector('[data-miner-bot]');
    const upgradeList = root.querySelector('[data-miner-upgrades]');
    const upgradesEmpty = root.querySelector('[data-miner-upgrades-empty]');
    const buildingList = root.querySelector('[data-miner-buildings]');
    const amounts = root.querySelector('[data-miner-amounts]');
    const darkText = root.querySelector('[data-miner-dark]');
    const warpNumbers = Object.fromEntries([...root.querySelectorAll('[data-miner-warp]')].map((node) => [node.dataset.minerWarp, node]));
    const warpButton = root.querySelector('[data-miner-warp-button]');
    const warpHint = root.querySelector('[data-miner-warp-hint]');
    const darkList = root.querySelector('[data-miner-dark-upgrades]');
    const achievementList = root.querySelector('[data-miner-achievements]');
    const achievementCount = root.querySelector('[data-miner-achievement-count]');
    const statList = root.querySelector('[data-miner-stats]');
    const cometButton = root.querySelector('[data-miner-comet]');
    const toasts = root.querySelector('[data-miner-toasts]');
    const ticker = root.querySelector('[data-miner-ticker-text]');
    const warpDialog = root.querySelector('[data-miner-warp-dialog]');
    const warpDialogText = root.querySelector('[data-miner-warp-dialog-text]');
    const otherTabDialog = root.querySelector('[data-miner-other-tab]');
    const soundButton = root.querySelector('[data-miner-sound]');
    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');

    // The asteroid changes as more is mined in a run: [ore mined this run, what it is]
    const STAGES = [[0, 'Plain grey rock'], [1e5, 'Iron asteroid'], [1e8, 'Gold asteroid'], [1e11, 'Crystal asteroid'], [1e14, 'Glowing alien core']];
    const COMET_TEXT = {
        frenzy: (ms) => 'Frenzy! Everything mines 7 times as fast for ' + Math.round(ms / 1000) + ' seconds.',
        lucky: (ms, ore) => 'Lucky! The comet was full of ore: +' + format(ore) + '.',
        storm: (ms) => 'Click Storm! Clicks mine 77 times as much for ' + Math.round(ms / 1000) + ' seconds.',
        diamond: (ms) => 'DIAMOND RUSH! 777 times the production for ' + Math.round(ms / 1000) + ' seconds!',
    };
    const BUFF_TEXT = { frenzy: 'Frenzy: production ×7', storm: 'Click Storm: clicks ×77', diamond: 'Diamond Rush: production ×777' };

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
    let wasSteady = false; // the last click came in like clockwork (told once, until the clicks are back to normal)
    let announced = new Set(); // achievements already shown
    let storeKey = '';
    let darkKey = '';
    let shownComet = -1; // the comet number on screen
    let saveSoonTimer = 0;

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

    function setSaveText(text, isError = false) {
        saveText.textContent = text;
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
            const seconds = Math.round((Date.now() - lastSavedAt) / 1000);
            setSaveText(seconds < 5 ? 'Saved just now' : 'Saved ' + (seconds < 60 ? seconds + ' seconds' : formatDuration(seconds * 1000)) + ' ago');
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
    }, 200);
    setInterval(() => {
        if (isPlaying && !document.hidden) renderStats();
    }, 1000);

    function renderAll() {
        catchUp();
        renderStore();
        renderBuffs();
        renderComet();
        renderWarp();
        renderAchievements();
        renderStats();
        renderTicker();
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

    function availableUpgrades() {
        return Object.keys(UPGRADES).filter((id) => !has(state, id) && upgradeUnlocked(state, UPGRADES[id]));
    }

    function renderStore() {
        const { shown, mystery } = visibleBuildings();
        const upgrades = availableUpgrades();
        if (!has(state, 'bulk')) buyAmount = 1;
        const key = [shown.join(), mystery, upgrades.join(), buyAmount, has(state, 'bulk')].join('|');

        if (key !== storeKey) { // something new to show: build the lists again
            storeKey = key;
            amounts.hidden = !has(state, 'bulk');
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
                const index = shown.indexOf(id);
                if (index < 9) { // the hot key that buys it (see "Hot keys" below)
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
        bot.hidden = !has(state, 'autoClicker');

        const stage = STAGES.findLastIndex(([at]) => state.runOre >= at);
        asteroid.dataset.stage = String(stage);
        const next = STAGES[stage + 1];
        stageText.textContent = STAGES[stage][1] + (next ? ' · becomes a' + (/^[AEIOU]/i.test(next[1]) ? 'n ' : ' ') + next[1].toLowerCase()
            + ' at ' + format(next[0]) + ' ore this run' : '');
    }

    function renderBuffs() {
        const running = Object.values(state.buffs).filter((item) => item.until > state.tick);
        buffList.replaceChildren(...running.map((item) => {
            const row = element('li', 'miner-buff is-' + item.kind);
            row.append(element('span', 'miner-buff-name', BUFF_TEXT[item.kind]),
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
        warpNumbers.bonus.textContent = '+' + format(CONFIG.darkBonus * 100 * state.darkEarned, { decimals: true }) + '%';
        warpButton.disabled = gain < 1;
        warpHint.textContent = state.runOre < CONFIG.warpOre
            ? 'Mine ' + format(CONFIG.warpOre - state.runOre) + ' more ore this run to unlock the Warp Jump.'
            : gain < 1 ? 'Mine a bit more first: a jump now wouldn\'t give you any Dark Matter.' : '';

        const key = state.dark + '|' + state.darkUpgrades.join();
        if (key === darkKey) return;
        darkKey = key;
        darkList.replaceChildren(...Object.entries(DARK_UPGRADES).map(([id, upgrade]) => {
            const item = element('li');
            const button = element('button', 'miner-upgrade is-dark');
            button.type = 'button';
            button.dataset.dark = id;
            const isOwned = hasDark(state, id);
            button.classList.toggle('is-owned', isOwned);
            button.classList.toggle('is-affordable', !isOwned && state.dark >= upgrade.cost);
            button.disabled = isOwned;
            button.append(
                element('span', 'miner-upgrade-icon', upgrade.icon),
                element('span', 'miner-upgrade-name', upgrade.name),
                element('span', 'miner-upgrade-cost', isOwned ? 'Bought' : upgrade.cost + ' Dark Matter'),
                element('span', 'miner-upgrade-text', upgrade.text),
            );
            item.append(button);
            return item;
        }));
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
        achievementCount.textContent = state.achievements.length + ' / ' + Object.keys(ACHIEVEMENTS).length;
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
        ['Teacher confiscates ore from student in the middle of maths.'],
        ['Ore found in the school canteen lasagne. Canteen denies everything.'],
        ['Rock-paper-scissors tournament on asteroid ends badly for scissors.'],
        ['Historians agree: this is the Ore Age.'],
        ['Comet insurance prices go through the roof.', (s) => s.comets > 0],
        ['Diamond Comet seen. Jewellers everywhere weep.', (s) => s.diamonds > 0],
        ['Auto-Clicker Bot asks for a day off. Request denied: it\'s a robot.', (s) => has(s, 'autoClicker')],
        ['Dark Matter finally found. It was behind the sofa the whole time.', (s) => s.warps > 0],
        ['The galaxy you left behind files a noise complaint about all the warping.', (s) => s.warps > 0],
        ['Stock market crashes as ore becomes worth less than gravel.', (s) => s.allOre >= 1e9],
        ['Billionaire buys a rocket. You buy a thousand.', (s) => s.allOre >= 1e12],
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
        if (field.childElementCount > 120) return; // plenty on screen already
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
        if (result.steady && !wasSteady) {
            toast('🤖 Your clicks are coming in too evenly, like an auto-clicker\'s, so each one only mines '
                + CONFIG.steadyValue * 100 + '% until you click at your own pace again.', '', 9000);
        }
        wasSteady = result.steady;
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

    darkList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dark]');
        if (!button) return;
        if (act({ type: 'dark', id: button.dataset.dark })) {
            sound('buy');
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
        toast('☄️ ' + COMET_TEXT[result.kind](comet.ms * (has(state, 'tail') ? 2 : 1), result.ore), 'is-comet is-' + result.kind, 6000);
        renderBuffs();
        renderStore();
    });

    // ----- Warp Jump

    warpButton.addEventListener('click', () => {
        const gain = warpGain(state);
        if (gain < 1) return;
        warpDialogText.textContent = 'You\'ll get ' + format(gain) + ' Dark Matter, which makes everything mine '
            + format(CONFIG.darkBonus * 100 * gain, { decimals: true }) + '% faster for good. Your ore, buildings and upgrades start over'
            + (hasDark(state, 'memory') ? ', but you keep your click upgrades.' : '.');
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

    // ----- Hot keys: each one presses the button it stands for, so they do exactly what a click would.
    // Space mines (a held-down key only counts once), C catches the comet (once the Comet Hot Key upgrade is bought, it's
    // lost on a Warp Jump like the other upgrades), 1-9 buy the buildings in the order they're
    // listed, U buys the cheapest upgrade you can afford, A switches between ×1, ×10 and ×100, W opens the Warp Jump
    // box and M turns the sound on or off. They're left alone while typing, with Ctrl/Alt/Cmd held (the browser's own
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
        a: () => {
            if (amounts.hidden) return;
            const options = [...amounts.querySelectorAll('[data-amount]')];
            const now = options.findIndex((button) => Number(button.dataset.amount) === buyAmount);
            options[(now + 1) % options.length].click();
        },
        w: () => warpButton.click(), // does nothing while it's disabled
        m: () => soundButton.click(),
    };

    document.addEventListener('keydown', (event) => {
        if (!isPlaying || event.ctrlKey || event.altKey || event.metaKey || document.querySelector('dialog[open]')) return;
        const target = event.target;
        if (target.closest?.('input, textarea, select, [contenteditable]:not([contenteditable="false"])')) return;

        if (event.key === ' ') {
            if (target.closest?.('button, a, summary')) return; // Space presses whatever has focus, as usual
            event.preventDefault(); // instead of scrolling the page
            if (!event.repeat) asteroid.click();
            return;
        }
        if (event.repeat) return;
        if (/^[1-9]$/.test(event.key)) {
            buildingList.querySelectorAll('[data-building]')[Number(event.key) - 1]?.click();
            return;
        }
        const hotKey = HOT_KEYS[event.key.toLowerCase()];
        if (hotKey && !event.shiftKey) hotKey();
    });

    // ----- Playing somewhere else

    otherTabDialog.addEventListener('cancel', (event) => event.preventDefault());
    root.querySelector('[data-miner-reload]').addEventListener('click', () => location.reload());

    save({ claim: true });
})();
