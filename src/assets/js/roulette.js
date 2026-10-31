// Roulette (gambling/roulette/): the table the whole site shares. Everyone's chips are on the same board, and one ball
// is spun for all of them by /gambling/roulette/table (data-api, see assets/php/casino/roulette/table.php), which
// decides where it lands and pays out. This only shows it: the wheel turns to the number the server sent.
// Click a bet to put the chosen chip on it, right-click to take one off. The user's bets go to the server a moment after
// each change (sync()), so the others see them, and everyone else's are drawn as smaller chips next to them. Hovering a
// player in the list shows only theirs. The first bet on the table starts the round's clock; pressing Ready (Space)
// says the user is done, and when everyone with bets is, the wheel spins straight away. After a spin the board shows
// the spin's bets, won and lost, until the next round starts; "Same again" puts the user's last bets back.
// The table is asked what's new every second while the page is in view (poll()), and not at all while it isn't.
//
// The guest Roulette at /play/roulette (data-guest, see assets/php/guestGames/guestGames.php) is a table of the guest's
// own instead, spun through /play/roulette/spin (data-api) whenever they press Spin. Its play chips come back as a
// sealed token with every spin, kept here in the page only (so they start over when it's opened again), and a guest who
// runs out gets a new stack ("notice"). Its bets stay on the table after a spin, so spinning again is one click.
(() => {
    const root = document.querySelector('[data-roulette]');
    if (!root) return;

    const API = root.dataset.api;
    const IS_GUEST = 'guest' in root.dataset;
    const IS_REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const GUEST_SPIN_MS = 5000;
    const POLL_MS = 1000;
    const SYNC_DELAY_MS = 300; // the bets go to the server this long after the last change, so a few clicks are one request
    const TICK_MS = 200;
    const WHEEL_TURNS = 4;
    const BALL_TURNS = 6;
    const HISTORY_MAX = 12;
    const UNDO_MAX = 100;
    const CHIP_VALUES = [1000, 500, 100, 50, 10]; // the colours of a bet's chips on the table, by the biggest that fits
    const BET_STEP = Number(root.dataset.betStep);
    const ERRORS = {
        blocked: 'Gambling is blocked in your settings right now.',
        closed: 'The casino isn\'t open right now.',
        badBets: 'Those bets couldn\'t be placed. Please clear the table and try again.',
        spinning: 'The wheel is spinning. Bets for the next round go on when it\'s done.',
        ready: 'You\'re ready, so your bets can\'t change this round.',
    };

    const find = (selector) => root.querySelector(selector);
    const board = find('[data-rl-board]');
    const wheel = find('[data-rl-wheel]');
    const rotor = find('[data-rl-rotor]');
    const ball = find('[data-rl-ball]');
    const resultText = find('[data-rl-result]');
    const detailText = find('[data-rl-detail]');
    const clock = find('[data-rl-clock]');
    const clockBar = find('[data-rl-clock-bar]');
    const historyList = find('[data-rl-history]');
    const historyEmpty = find('[data-rl-history-empty]');
    const playersList = find('[data-rl-players]');
    const playersEmpty = find('[data-rl-players-empty]');
    const message = find('[data-rl-message]');
    const totalText = find('[data-rl-total]');
    const chipButtons = [...root.querySelectorAll('[data-rl-chip]')];
    const undoButton = find('[data-rl-undo]');
    const clearButton = find('[data-rl-clear]');
    const doubleButton = find('[data-rl-double]');
    const rebetButton = find('[data-rl-rebet]');
    const spinButton = find('[data-rl-spin]');
    const brokeHint = find('[data-rl-broke]');
    const navChips = document.querySelector('[data-casino-chips]'); // kept up to date by casino.js

    const spots = [...board.querySelectorAll('[data-rl-bet]')];
    const cellByNumber = new Map([...board.querySelectorAll('[data-rl-number]')].map((cell) => [Number(cell.dataset.rlNumber), cell]));
    const wheelOrder = wheel.dataset.order.split(' ').map(Number);
    const POCKET_DEG = 360 / wheelOrder.length;

    let chips = Number(root.dataset.chips);
    let chip = Number(chipButtons[0].dataset.rlChip); // the chip a click puts on the table
    let bets = new Map(); // the user's bets: bet => chips on it
    let undoStack = []; // the bets before each change
    let lastBets = new Map(); // the bets of the user's last spin, for Same again / Space on an empty table
    let isSpinning = false; // the wheel is turning (or, for a guest, the spin is on its way)
    let revealed = null; // the number the board shows the won and lost bets for, null while it shows none
    let wheelTurn = 0; // degrees, only ever growing so the wheel always turns the same way
    let ballTurn = 0;
    let covered = []; // the cells lit up for the bet under the pointer
    let guestToken = ''; // a guest's play chips, sealed by the server (none before the first spin: a new stack)

    // Only at the shared table
    let table = null; // the table as the server last sent it (hbHubRouletteTableView())
    let version = null;
    let receivedAt = 0; // Date.now() when the table came, which its closesIn and spunAgo count from
    let isResult = false; // the board shows the last spin's bets (table.last) instead of the ones going on now
    let shownRound = null; // the last spin that was shown (or was already over when the page opened)
    let isReady = false; // the user pressed Ready this round, so their bets can't change
    let isSending = false;
    let syncTimer = null;
    let wantsSync = false; // the bets changed again while they were being sent
    let wantsReady = false;
    let pollTimer = null;
    let isPolling = false;
    let isStopped = false; // the casino closed or was blocked: no more polls
    let requestCount = 0; // every request is numbered, so an older answer that comes late can't undo a newer one
    let appliedRequest = 0;
    let pendingChips = null; // the chips after a spin, kept back until the wheel stops so they don't give it away
    let shownPhase = null; // the phase the board was last drawn for, to redraw when the clock moves it on
    let focusedPlayer = null; // the player hovered in the list, whose bets are the only others shown

    const formatChips = (amount) => amount.toLocaleString('en-US');
    const total = () => [...bets.values()].reduce((sum, amount) => sum + amount, 0);
    const betMap = (object) => new Map(Object.entries(object ?? {}).map(([bet, amount]) => [bet, Number(amount)]));
    const colorName = (number) => (number === 0 ? 'green' : cellByNumber.get(number)?.classList.contains('rl-red') ? 'red' : 'black');

    // The user's chips: the top bar's when it's there, as casino.js keeps that up to date (daily chips collected in
    // another tab and the like)
    function walletChips() {
        const shown = navChips ? Number(navChips.dataset.casinoChips) : NaN;
        return Number.isFinite(shown) ? shown : chips;
    }

    function shortChips(amount) {
        if (amount < 1000) return String(amount);
        const [divisor, unit] = amount < 1000000 ? [1000, 'K'] : [1000000, 'M'];
        return String(Math.floor((amount / divisor) * 10) / 10) + unit;
    }

    function showMessage(text) {
        message.textContent = text;
    }

    function tooMany(amount) {
        const have = walletChips();
        return have < BET_STEP
            ? 'You\'re out of chips.'
            : 'That would be ' + formatChips(amount) + ' chips on the table, and you have ' + formatChips(have) + '.';
    }

    // Where the shared table's round is now, by the clock since the table came: open, betting, closed (betting is
    // over, the spin is on its way) or spinning (the wheel turns and the result shows, no bets go on)
    function phase() {
        if (IS_GUEST || table === null) return 'open';
        const now = Date.now();
        if (table.spunAgo !== null) return now - receivedAt + table.spunAgo < table.resultMs ? 'spinning' : 'open';
        if (table.phase === 'betting') return now - receivedAt < table.closesIn ? 'betting' : 'closed';
        return 'open';
    }

    // Whether the user can't change their bets right now
    function isLocked() {
        if (IS_GUEST) return isSpinning;
        return isStopped || isSpinning || isReady || ['spinning', 'closed'].includes(phase());
    }

    // The players whose bets the board shows: last spin's, or the round being bet on
    function shownPlayers() {
        if (table === null) return [];
        return isResult ? table.last?.players ?? [] : table.players;
    }

    // The user's bets on the board, and everyone else's added up per bet (only the hovered player's, when one is)
    function shownBets() {
        const me = shownPlayers().find((player) => player.me);
        const mine = IS_GUEST || !isResult ? bets : betMap(me?.bets);
        const others = new Map();
        shownPlayers().forEach((player) => {
            if (player.me || (focusedPlayer !== null && player.id !== focusedPlayer)) return;
            betMap(player.bets).forEach((amount, bet) => {
                const spot = others.get(bet) ?? { amount: 0, names: [] };
                spot.amount += amount;
                spot.names.push(player.name + ': ' + formatChips(amount));
                others.set(bet, spot);
            });
        });
        return { mine, others };
    }

    // A bet's chips on its spot: the user's (mine) or everyone else's (others), coloured by the biggest chip in them,
    // and won or lost when the board shows a result
    function setMarker(spot, kind, amount) {
        let marker = spot.querySelector('.rl-marker-' + kind);
        if (!amount) {
            marker?.remove();
            return;
        }
        if (!marker) {
            marker = document.createElement('span');
            marker.setAttribute('aria-hidden', 'true');
            spot.append(marker);
        }
        const color = CHIP_VALUES.find((value) => amount >= value) ?? CHIP_VALUES.at(-1);
        const result = revealed === null ? null
            : spot.dataset.rlNumbers.split(' ').includes(String(revealed)) ? 'is-win' : 'is-lost';
        marker.className = ['rl-marker', 'rl-marker-' + kind, 'rl-chip-' + color, result].filter(Boolean).join(' ');
        marker.textContent = shortChips(amount);
    }

    // Draws the chips on the table and the buttons for the bets as they are now
    function draw() {
        const locked = isLocked();
        const { mine, others } = shownBets();
        spots.forEach((spot) => {
            const bet = spot.dataset.rlBet;
            spot.disabled = locked;
            setMarker(spot, 'mine', mine.get(bet));
            const other = others.get(bet);
            setMarker(spot, 'others', other?.amount);
            if (other) {
                spot.title = other.names.join('\n');
            } else {
                spot.removeAttribute('title');
            }
        });
        board.querySelectorAll('.is-winner').forEach((cell) => cell.classList.remove('is-winner'));
        if (revealed !== null) cellByNumber.get(revealed)?.classList.add('is-winner');

        totalText.textContent = formatChips(total());
        undoButton.disabled = locked || undoStack.length === 0;
        clearButton.disabled = locked || bets.size === 0;
        doubleButton.disabled = locked || bets.size === 0;
        spinButton.disabled = locked || bets.size === 0;
        if (IS_GUEST) {
            spinButton.textContent = isSpinning ? 'Spinning…' : 'Spin';
        } else {
            const othersBetting = table?.players.some((player) => !player.me) ?? false;
            spinButton.textContent = isReady ? 'Ready ✓' : othersBetting ? 'Ready' : 'Spin';
            spinButton.title = (othersBetting ? 'Done betting: spins when everyone is' : 'Spin') + ' (Space)';
            rebetButton.hidden = bets.size > 0 || lastBets.size === 0;
            rebetButton.disabled = locked;
        }
        brokeHint.hidden = isSpinning || bets.size > 0 || walletChips() >= BET_STEP;
        shownPhase = phase();
    }

    // The won and lost bets stay marked until the bets change, or (at the shared table) the next round starts
    function clearResult() {
        revealed = null;
        if (isResult) {
            isResult = false;
            renderPlayers();
        }
    }

    function setBets(next, isUndo = false) {
        if (!isUndo) {
            undoStack.push(bets);
            if (undoStack.length > UNDO_MAX) undoStack.shift();
        }
        bets = next;
        clearResult();
        showMessage('');
        if (!IS_GUEST) scheduleSync();
        draw();
        showStatus();
    }

    function addBet(bet, amount) {
        if (total() + amount > walletChips()) {
            showMessage(tooMany(total() + amount));
            return;
        }
        const next = new Map(bets);
        next.set(bet, (next.get(bet) ?? 0) + amount);
        setBets(next);
    }

    function takeOff(bet, amount) {
        if (!bets.has(bet)) return;
        const next = new Map(bets);
        const left = next.get(bet) - amount;
        if (left >= BET_STEP) {
            next.set(bet, left);
        } else {
            next.delete(bet);
        }
        setBets(next);
    }

    // Lights up the numbers a bet covers while the pointer (or keyboard focus) is on it
    function highlight(spot) {
        covered.forEach((cell) => cell.classList.remove('is-covered'));
        covered = spot && !spot.disabled
            ? spot.dataset.rlNumbers.split(' ').map((number) => cellByNumber.get(Number(number)))
            : [];
        covered.forEach((cell) => cell.classList.add('is-covered'));
    }

    // Turns the wheel so the number ends under the ball at the top, while the ball goes round the other way, in
    // duration ms (0: straight there)
    function turnWheel(number, duration) {
        const target = -wheelOrder.indexOf(number) * POCKET_DEG;
        const delta = (((target - wheelTurn) % 360) + 360) % 360;
        if (IS_REDUCED_MOTION) duration = 0;
        wheelTurn += (duration ? WHEEL_TURNS * 360 : 0) + delta;
        ballTurn -= duration ? BALL_TURNS * 360 : 0;

        rotor.style.transition = duration ? 'transform ' + duration + 'ms cubic-bezier(0.12, 0.6, 0.2, 1)' : 'none';
        ball.style.transition = duration ? 'transform ' + duration + 'ms cubic-bezier(0.2, 0.55, 0.25, 1)' : 'none';
        rotor.style.transform = 'rotate(' + wheelTurn + 'deg)';
        ball.style.transform = 'rotate(' + ballTurn + 'deg)';
        return new Promise((resolve) => setTimeout(resolve, duration));
    }

    function setChips(newChips) {
        chips = newChips;
        document.dispatchEvent(new CustomEvent('casino:wallet', { detail: { chips: newChips } }));
    }

    function historyItem(number, color, title = '') {
        const item = document.createElement('li');
        item.className = 'rl-history-item rl-' + color;
        if (title) item.title = title;
        item.textContent = String(number);
        return item;
    }

    function setResultText(text, net) {
        resultText.textContent = text;
        resultText.classList.toggle('is-win', net > 0);
        resultText.classList.toggle('is-lose', net < 0);
    }

    // "17 red – you won 350!" and what was bet and paid back
    function showSpinResult(number, color, spin) {
        const name = number + ' ' + color;
        if (spin.net > 0) {
            setResultText(name + ' – you won ' + formatChips(spin.net) + '!', spin.net);
        } else if (spin.payout > 0 && spin.net === 0) {
            setResultText(name + ' – you broke even.', 0);
        } else {
            setResultText(name + ' – you lost ' + formatChips(-spin.net) + '.', spin.net);
        }
        detailText.textContent = 'You bet ' + formatChips(spin.wagered) + ' and got ' + formatChips(spin.payout) + ' back.';
    }

    // ---- The guest's own table ----

    async function guestRequest(sentBets) {
        try {
            const body = new URLSearchParams({ bets: JSON.stringify(Object.fromEntries(sentBets)), token: guestToken });
            const response = await fetch(API, { method: 'POST', headers: { Accept: 'application/json' }, body });
            if (response.status === 403) {
                location.reload(); // the casino was closed: the page is gone
                return null;
            }
            const answer = await response.json();
            if (typeof answer?.token === 'string') guestToken = answer.token;
            return answer;
        } catch {
            return null; // offline for a moment
        }
    }

    async function guestSpin() {
        if (isSpinning || bets.size === 0) return;
        if (total() > walletChips()) {
            showMessage(tooMany(total()) + ' Take some chips off the table.');
            return;
        }

        const sentBets = bets;
        isSpinning = true;
        revealed = null;
        highlight(null);
        showMessage('');
        setResultText('No more bets…', 0);
        detailText.textContent = '';
        draw();

        const answer = await guestRequest(sentBets);
        if (answer === null || typeof answer.number !== 'number') {
            isSpinning = false;
            if (answer?.error === 'notEnoughChips') {
                setChips(answer.chips);
                showMessage(tooMany(total()) + ' Take some chips off the table.');
            } else {
                showMessage(ERRORS[answer?.error] ?? 'Could not spin. Please try again.');
            }
            resultText.textContent = 'Place your bets, then spin.';
            draw();
            return;
        }

        await turnWheel(answer.number, GUEST_SPIN_MS);
        isSpinning = false;
        lastBets = sentBets;
        revealed = answer.number;
        setChips(answer.chips); // only now, so the guest bar doesn't give the result away while the wheel turns
        showSpinResult(answer.number, answer.color, answer);
        historyList.prepend(historyItem(answer.number, answer.color,
            (answer.net >= 0 ? '+' : '−') + formatChips(Math.abs(answer.net)) + ' chips'));
        while (historyList.children.length > HISTORY_MAX) historyList.lastElementChild.remove();
        historyEmpty.hidden = true;
        if (answer.notice) { // a guest who ran out: a new stack of play chips
            setChips(answer.refilledChips);
            showMessage(answer.notice);
        }
        draw();
        spinButton.focus({ preventScroll: true });
    }

    // ---- The shared table ----

    // A GET (a poll, with its query) or a POST (body) to the table. Returns the answer and its number, or null
    async function tableRequest(query, body = null) {
        const number = ++requestCount;
        try {
            const options = { headers: { Accept: 'application/json' } };
            if (body !== null) Object.assign(options, { method: 'POST', body });
            const response = await fetch(API + query, options);
            return { number, answer: await response.json() };
        } catch {
            return null; // offline for a moment, or logged out in another tab
        }
    }

    // The user's bets as a string that's the same however they're ordered, to tell whether two sets are the same
    const betsKey = (map) => JSON.stringify([...map].sort(([a], [b]) => (a < b ? -1 : 1)));

    function scheduleSync() {
        clearTimeout(syncTimer);
        syncTimer = setTimeout(() => {
            syncTimer = null;
            sync(false);
        }, SYNC_DELAY_MS);
    }

    // Sends the user's bets to the table, as they are now (and Ready with them, when ready)
    async function sync(ready) {
        if (isSending) {
            wantsSync = true;
            wantsReady ||= ready;
            return;
        }
        isSending = true;
        const body = new URLSearchParams({ action: 'bet', bets: JSON.stringify(Object.fromEntries(bets)), ready: ready ? '1' : '0' });
        const result = await tableRequest('', body);
        isSending = false;

        if (result === null) {
            showMessage('Could not reach the table. Please try again.');
        } else {
            const { error } = result.answer;
            if (error === 'notEnoughChips') {
                showMessage(tooMany(total()));
            } else if (error) {
                showMessage(ERRORS[error] ?? 'Those bets couldn\'t be placed. Please try again.');
                if (error === 'blocked' || error === 'closed') isStopped = true;
            }
            apply(result);
        }

        if (wantsSync) {
            const again = wantsReady;
            wantsSync = false;
            wantsReady = false;
            sync(again);
        }
    }

    function ready() {
        if (isLocked() || bets.size === 0) return;
        clearTimeout(syncTimer);
        syncTimer = null;
        sync(true);
    }

    async function poll() {
        clearTimeout(pollTimer);
        pollTimer = null;
        if (isPolling || isStopped || document.visibilityState !== 'visible') return;
        isPolling = true;

        const query = new URLSearchParams();
        if (version !== null) query.set('v', version);
        const ride = { params: null };
        document.dispatchEvent(new CustomEvent('nav:ride', { detail: ride })); // the nav bar's request comes along (navUpdates.js)
        if (ride.params !== null) query.set('nav', ride.params);
        const result = await tableRequest('?' + query);
        if (result?.answer?.error === 'blocked' || result?.answer?.error === 'closed') {
            isStopped = true;
            showMessage(ERRORS[result.answer.error]);
            draw();
        } else if (result !== null) {
            apply(result);
        }

        isPolling = false;
        if (!isStopped && document.visibilityState === 'visible') pollTimer = setTimeout(poll, POLL_MS);
    }

    // Takes in what the table sent
    function apply({ number, answer }) {
        if (answer.nav) document.dispatchEvent(new CustomEvent('nav:update', { detail: answer.nav }));
        if (answer.same || !answer.table || number < appliedRequest) return;
        appliedRequest = number;
        version = answer.v;
        table = answer.table;
        receivedAt = Date.now();
        if (isSpinning) {
            pendingChips = answer.chips;
        } else {
            setChips(answer.chips);
        }

        // The user's bets as the table has them, unless they've changed them since and that's still on its way
        const me = table.players.find((player) => player.me);
        if (!isSending && !wantsSync && syncTimer === null) {
            const theirs = betMap(me?.bets);
            if (betsKey(theirs) !== betsKey(bets)) bets = theirs;
        }
        isReady = me?.ready ?? false;

        const last = table.last;
        if (last !== null && last.round !== shownRound) {
            if (shownRound === null && phase() !== 'spinning') {
                shownRound = last.round; // over before the page opened: the wheel just stands on it
                turnWheel(last.number, 0);
            } else {
                spin(last);
            }
        }
        if (isResult && !isSpinning && table.players.length > 0) clearResult(); // the next round has started

        if (!isSpinning) renderHistory();
        renderPlayers();
        draw();
        showStatus();
    }

    // The wheel spins for the last round (catching up when the page heard of it late), then shows how everyone did
    async function spin(last) {
        shownRound = last.round;
        const me = last.players.find((player) => player.me);
        if (me) lastBets = betMap(me.bets);
        isSpinning = true;
        isResult = true;
        revealed = null;
        focusedPlayer = null;
        highlight(null);
        undoStack = [];
        setResultText('No more bets…', 0);
        detailText.textContent = '';
        renderPlayers();
        draw();

        const left = table.spinMs - (table.spunAgo ?? 0) - (Date.now() - receivedAt);
        await turnWheel(last.number, left > 300 ? left : 0);
        isSpinning = false;
        revealed = last.number;
        if (pendingChips !== null) {
            setChips(pendingChips);
            pendingChips = null;
        }

        const color = colorName(last.number);
        if (me) {
            showSpinResult(last.number, color, me);
        } else {
            setResultText(last.number + ' ' + color + '.', 0);
            detailText.textContent = '';
        }
        renderHistory();
        renderPlayers();
        draw();
        showStatus();
    }

    // The table's last numbers (not the one being spun until the wheel stops)
    function renderHistory() {
        const recent = table?.recent ?? [];
        historyList.replaceChildren(...recent.map((spin) => historyItem(spin.number, spin.color)));
        historyEmpty.hidden = recent.length > 0;
    }

    // Who's playing: their bets on the round, Ready or not, or after a spin what they won or lost
    function renderPlayers() {
        const players = shownPlayers();
        const items = players.map((player) => {
            const item = document.createElement('li');
            item.className = 'rl-player' + (player.me ? ' is-me' : '') + (player.id === focusedPlayer ? ' is-focused' : '');
            item.dataset.rlPlayer = String(player.id);
            item.tabIndex = 0;

            const avatar = document.createElement('span');
            avatar.className = 'casino-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            if (player.avatar) {
                const image = document.createElement('img');
                image.src = player.avatar;
                image.alt = '';
                avatar.append(image);
            } else {
                avatar.textContent = ([...player.name][0] ?? '?').toUpperCase();
            }

            const name = document.createElement('span');
            name.className = 'rl-player-name';
            name.textContent = player.me ? player.name + ' (you)' : player.name;

            const amount = document.createElement('span');
            amount.className = 'rl-player-amount';
            if (isResult && revealed !== null) {
                amount.textContent = (player.net >= 0 ? '+' : '−') + formatChips(Math.abs(player.net));
                amount.classList.add(player.net > 0 ? 'is-win' : player.net < 0 ? 'is-lose' : 'is-even');
            } else if (isResult) {
                amount.textContent = formatChips(player.wagered); // the wheel is still turning
            } else {
                amount.textContent = formatChips(player.wagered) + (player.ready ? ' ✓' : '');
                if (player.ready) amount.title = 'Ready';
            }
            item.append(avatar, name, amount);
            return item;
        });
        playersList.replaceChildren(...items);
        playersEmpty.hidden = items.length > 0;
    }

    // The line under the result, with the round's clock: what's going on at the table right now
    function showStatus() {
        if (IS_GUEST) return;
        const now = phase();
        clock.hidden = now !== 'betting';
        if (now === 'betting') {
            const left = Math.max(0, table.closesIn - (Date.now() - receivedAt));
            clockBar.style.width = (100 * left / table.betMs) + '%';
        }
        if (isResult && (isSpinning || revealed !== null)) return; // the spin's own result stays up

        if (isStopped) {
            setResultText('The table is closed to you right now.', 0);
        } else if (now === 'betting') {
            const seconds = Math.ceil(Math.max(0, table.closesIn - (Date.now() - receivedAt)) / 1000);
            setResultText('Bets close in ' + seconds + ' s', 0);
        } else if (now === 'closed' || now === 'spinning') {
            setResultText('No more bets…', 0);
        } else {
            setResultText('Place your bets to start a round.', 0);
        }

        const waiting = table?.players.filter((player) => !player.ready).length ?? 0;
        if (isReady) {
            detailText.textContent = waiting > 0
                ? 'You\'re ready. Waiting for ' + waiting + (waiting === 1 ? ' player.' : ' players.')
                : 'You\'re ready.';
        } else if (now === 'betting') {
            detailText.textContent = 'Press Ready when you\'re done. The wheel spins when everyone is, or when the time runs out.';
        } else {
            detailText.textContent = '';
        }
    }

    // Every 200 ms: the clock, and a redraw when it moves the round on (the result over, betting closed)
    function tick() {
        if (table === null) return;
        const now = phase();
        if (now !== shownPhase) {
            draw();
            if (now === 'closed') poll(); // the spin is due: whoever asks first spins it
        }
        showStatus();
    }

    // ---- Both ----

    board.addEventListener('click', (event) => {
        const spot = event.target.closest('[data-rl-bet]');
        if (spot && !spot.disabled) addBet(spot.dataset.rlBet, chip);
    });

    board.addEventListener('contextmenu', (event) => {
        const spot = event.target.closest('[data-rl-bet]');
        if (!spot) return;
        event.preventDefault();
        if (!spot.disabled) takeOff(spot.dataset.rlBet, chip);
    });

    board.addEventListener('pointerover', (event) => highlight(event.target.closest('[data-rl-bet]')));
    board.addEventListener('pointerleave', () => highlight(null));
    board.addEventListener('focusin', (event) => highlight(event.target.closest('[data-rl-bet]')));
    board.addEventListener('focusout', () => highlight(null));

    chipButtons.forEach((button) => {
        button.addEventListener('click', () => {
            chip = Number(button.dataset.rlChip);
            chipButtons.forEach((other) => other.setAttribute('aria-pressed', String(other === button)));
        });
    });

    undoButton.addEventListener('click', () => {
        if (isLocked() || undoStack.length === 0) return;
        setBets(undoStack.pop(), true);
    });

    clearButton.addEventListener('click', () => {
        if (!isLocked() && bets.size > 0) setBets(new Map());
    });

    doubleButton.addEventListener('click', () => {
        if (isLocked() || bets.size === 0) return;
        if (total() * 2 > walletChips()) {
            showMessage(tooMany(total() * 2));
            return;
        }
        setBets(new Map([...bets].map(([bet, amount]) => [bet, amount * 2])));
    });

    // The user's last spin's bets back on the table. Returns whether they fit in the chips.
    function putBackLastBets() {
        const lastTotal = [...lastBets.values()].reduce((sum, amount) => sum + amount, 0);
        if (lastTotal > walletChips()) {
            showMessage(tooMany(lastTotal));
            return false;
        }
        setBets(new Map(lastBets));
        return true;
    }

    spinButton.addEventListener('click', () => (IS_GUEST ? guestSpin() : ready()));

    // Space spins again with the same bets (Ready, at the shared table): the ones on the table, or the last spin's if
    // the table is empty. It takes over Space on the page's buttons too (it would otherwise press the bet last clicked),
    // but not in text boxes
    document.addEventListener('keydown', (event) => {
        if (event.key !== ' ' || event.ctrlKey || event.metaKey || event.altKey) return;
        if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;
        event.preventDefault(); // no scrolling the page
        if (event.repeat || isLocked()) return;
        if (bets.size === 0 && (lastBets.size === 0 || !putBackLastBets())) return;
        if (IS_GUEST) {
            guestSpin();
        } else {
            ready();
        }
    });

    // A focused button is pressed when Space is let go, so that's stopped too
    document.addEventListener('keyup', (event) => {
        if (event.key === ' ' && !event.target.closest?.('input, textarea, select, [contenteditable]')) event.preventDefault();
    });

    // The wheel starts where it last stopped
    const lastNumber = historyList.firstElementChild ? Number(historyList.firstElementChild.textContent) : null;
    if (lastNumber !== null && wheelOrder.includes(lastNumber)) turnWheel(lastNumber, 0);

    if (!IS_GUEST) {
        rebetButton.addEventListener('click', () => {
            if (!isLocked() && bets.size === 0 && lastBets.size > 0) putBackLastBets();
        });

        // Hovering (or focusing) a player shows only their bets among the others'
        const focusPlayer = (item) => {
            const id = item ? Number(item.dataset.rlPlayer) : null;
            if (id === focusedPlayer) return;
            focusedPlayer = id;
            playersList.querySelectorAll('.rl-player').forEach((other) => other.classList.toggle('is-focused', other === item));
            draw();
        };
        playersList.addEventListener('pointerover', (event) => focusPlayer(event.target.closest('[data-rl-player]')));
        playersList.addEventListener('pointerleave', () => focusPlayer(null));
        playersList.addEventListener('focusin', (event) => focusPlayer(event.target.closest('[data-rl-player]')));
        playersList.addEventListener('focusout', () => focusPlayer(null));

        setInterval(tick, TICK_MS);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') poll(); // catch up when coming back to the tab
        });
        poll();
    }
    draw();
})();
