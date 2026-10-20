// Roulette (gambling/roulette/): places chips on the table and spins them through /gambling/roulette/spin, which
// decides where the ball lands and pays out. This only shows it: the wheel turns to the number the server sent.
// The bets stay on the table after a spin, so spinning again with the same bets is one click, or Space (which also
// puts the last spin's bets back if the table was cleared since).
// Click a bet to put the chosen chip on it, right-click to take one off.
(() => {
    const root = document.querySelector('[data-roulette]');
    if (!root) return;

    const API = '/gambling/roulette/spin';
    const SPIN_MS = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 5000;
    const WHEEL_TURNS = 4;
    const BALL_TURNS = 6;
    const HISTORY_MAX = 12;
    const UNDO_MAX = 100;
    const CHIP_VALUES = [1000, 500, 100, 50, 10]; // the colours of a bet's chips on the table, by the biggest that fits
    const BET_STEP = Number(root.dataset.betStep);
    const COLOR_NAMES = { red: 'red', black: 'black', green: 'green' };
    const ERRORS = {
        blocked: 'Gambling is blocked in your settings right now.',
        closed: 'The casino isn\'t open right now.',
        badBets: 'Those bets couldn\'t be placed. Please clear the table and try again.',
    };

    const find = (selector) => root.querySelector(selector);
    const board = find('[data-rl-board]');
    const wheel = find('[data-rl-wheel]');
    const rotor = find('[data-rl-rotor]');
    const ball = find('[data-rl-ball]');
    const resultText = find('[data-rl-result]');
    const detailText = find('[data-rl-detail]');
    const historyList = find('[data-rl-history]');
    const historyEmpty = find('[data-rl-history-empty]');
    const message = find('[data-rl-message]');
    const totalText = find('[data-rl-total]');
    const chipButtons = [...root.querySelectorAll('[data-rl-chip]')];
    const undoButton = find('[data-rl-undo]');
    const clearButton = find('[data-rl-clear]');
    const doubleButton = find('[data-rl-double]');
    const spinButton = find('[data-rl-spin]');
    const brokeHint = find('[data-rl-broke]');
    const navChips = document.querySelector('[data-casino-chips]'); // kept up to date by casino.js

    const spots = [...board.querySelectorAll('[data-rl-bet]')];
    const spotByBet = new Map(spots.map((spot) => [spot.dataset.rlBet, spot]));
    const cellByNumber = new Map([...board.querySelectorAll('[data-rl-number]')].map((cell) => [Number(cell.dataset.rlNumber), cell]));
    const wheelOrder = wheel.dataset.order.split(' ').map(Number);
    const POCKET_DEG = 360 / wheelOrder.length;

    let chips = Number(root.dataset.chips);
    let chip = Number(chipButtons[0].dataset.rlChip); // the chip a click puts on the table
    let bets = new Map(); // bet => chips on it
    let undoStack = []; // the bets before each change
    let lastBets = new Map(); // the bets of the last spin, for Space on an empty table
    let isSpinning = false;
    let wheelTurn = 0; // degrees, only ever growing so the wheel always turns the same way
    let ballTurn = 0;
    let covered = []; // the cells lit up for the bet under the pointer

    const formatChips = (amount) => amount.toLocaleString('en-US');
    const total = () => [...bets.values()].reduce((sum, amount) => sum + amount, 0);

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

    // Draws the chips on the table and the buttons for the bets as they are now
    function draw() {
        spots.forEach((spot) => {
            const amount = bets.get(spot.dataset.rlBet);
            let marker = spot.querySelector('.rl-marker');
            spot.disabled = isSpinning;
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
            const marks = ['is-win', 'is-lost'].filter((mark) => marker.classList.contains(mark));
            marker.className = ['rl-marker', 'rl-chip-' + color, ...marks].join(' ');
            marker.textContent = shortChips(amount);
        });

        totalText.textContent = formatChips(total());
        undoButton.disabled = isSpinning || undoStack.length === 0;
        clearButton.disabled = isSpinning || bets.size === 0;
        doubleButton.disabled = isSpinning || bets.size === 0;
        spinButton.disabled = isSpinning || bets.size === 0;
        spinButton.textContent = isSpinning ? 'Spinning…' : 'Spin';
        brokeHint.hidden = isSpinning || bets.size > 0 || walletChips() >= BET_STEP;
    }

    // The winning number and the won and lost bets stay marked until the bets change or the next spin
    function clearResultMarks() {
        board.querySelectorAll('.is-winner').forEach((cell) => cell.classList.remove('is-winner'));
        board.querySelectorAll('.rl-marker').forEach((marker) => marker.classList.remove('is-win', 'is-lost'));
    }

    function setBets(next) {
        undoStack.push(bets);
        if (undoStack.length > UNDO_MAX) undoStack.shift();
        bets = next;
        clearResultMarks();
        showMessage('');
        draw();
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

    // Turns the wheel so the number ends under the ball at the top, while the ball goes round the other way
    function turnWheel(number, isAnimated = true) {
        const target = -wheelOrder.indexOf(number) * POCKET_DEG;
        const delta = (((target - wheelTurn) % 360) + 360) % 360;
        const duration = isAnimated ? SPIN_MS : 0;
        wheelTurn += (isAnimated ? WHEEL_TURNS * 360 : 0) + delta;
        ballTurn -= isAnimated ? BALL_TURNS * 360 : 0;

        rotor.style.transition = duration ? 'transform ' + duration + 'ms cubic-bezier(0.12, 0.6, 0.2, 1)' : 'none';
        ball.style.transition = duration ? 'transform ' + duration + 'ms cubic-bezier(0.2, 0.55, 0.25, 1)' : 'none';
        rotor.style.transform = 'rotate(' + wheelTurn + 'deg)';
        ball.style.transform = 'rotate(' + ballTurn + 'deg)';
        return new Promise((resolve) => setTimeout(resolve, duration));
    }

    async function request(sentBets) {
        try {
            const response = await fetch(API, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ bets: JSON.stringify(Object.fromEntries(sentBets)) }),
            });
            return await response.json();
        } catch {
            return null; // offline for a moment, or logged out in another tab
        }
    }

    function setChips(newChips) {
        chips = newChips;
        document.dispatchEvent(new CustomEvent('casino:wallet', { detail: { chips: newChips } }));
    }

    function addToHistory(number, color, net) {
        const item = document.createElement('li');
        item.className = 'rl-history-item rl-' + color;
        item.title = (net >= 0 ? '+' : '−') + formatChips(Math.abs(net)) + ' chips';
        item.textContent = String(number);
        historyList.prepend(item);
        while (historyList.children.length > HISTORY_MAX) historyList.lastElementChild.remove();
        historyEmpty.hidden = true;
    }

    function showResult(spin, sentBets) {
        const name = spin.number + ' ' + COLOR_NAMES[spin.color];
        let text;
        if (spin.net > 0) {
            text = name + ' – you won ' + formatChips(spin.net) + '!';
        } else if (spin.payout > 0 && spin.net === 0) {
            text = name + ' – you broke even.';
        } else {
            text = name + ' – you lost ' + formatChips(-spin.net) + '.';
        }
        resultText.textContent = text;
        resultText.classList.toggle('is-win', spin.net > 0);
        resultText.classList.toggle('is-lose', spin.net < 0);
        detailText.textContent = 'You bet ' + formatChips(spin.wagered) + ' and got '
            + formatChips(spin.payout) + ' back.';

        cellByNumber.get(spin.number)?.classList.add('is-winner');
        sentBets.forEach((amount, bet) => {
            spotByBet.get(bet)?.querySelector('.rl-marker')?.classList.add(spin.wins[bet] ? 'is-win' : 'is-lost');
        });
        addToHistory(spin.number, spin.color, spin.net);
    }

    async function spin() {
        if (isSpinning || bets.size === 0) return;
        if (total() > walletChips()) {
            showMessage(tooMany(total()) + ' Take some chips off the table.');
            return;
        }

        const sentBets = bets;
        isSpinning = true;
        clearResultMarks();
        highlight(null);
        showMessage('');
        resultText.classList.remove('is-win', 'is-lose');
        resultText.textContent = 'No more bets…';
        detailText.textContent = '';
        draw();

        const answer = await request(sentBets);
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

        await turnWheel(answer.number);
        isSpinning = false;
        lastBets = sentBets;
        setChips(answer.chips); // only now, so the top bar doesn't give the result away while the wheel turns
        showResult(answer, sentBets);
        draw();
        spinButton.focus({ preventScroll: true });
    }

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
        if (isSpinning || undoStack.length === 0) return;
        bets = undoStack.pop();
        clearResultMarks();
        showMessage('');
        draw();
    });

    clearButton.addEventListener('click', () => {
        if (!isSpinning && bets.size > 0) setBets(new Map());
    });

    doubleButton.addEventListener('click', () => {
        if (isSpinning || bets.size === 0) return;
        if (total() * 2 > walletChips()) {
            showMessage(tooMany(total() * 2));
            return;
        }
        setBets(new Map([...bets].map(([bet, amount]) => [bet, amount * 2])));
    });

    spinButton.addEventListener('click', spin);

    // Space spins again with the same bets: the ones on the table, or the last spin's if the table is empty. It
    // takes over Space on the page's buttons too (it would otherwise press the bet last clicked), but not in text boxes
    document.addEventListener('keydown', (event) => {
        if (event.key !== ' ' || event.ctrlKey || event.metaKey || event.altKey) return;
        if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;
        event.preventDefault(); // no scrolling the page
        if (event.repeat || isSpinning) return;
        if (bets.size === 0 && lastBets.size > 0) {
            const lastTotal = [...lastBets.values()].reduce((sum, amount) => sum + amount, 0);
            if (lastTotal > walletChips()) {
                showMessage(tooMany(lastTotal));
                return;
            }
            setBets(new Map(lastBets));
        }
        spin();
    });

    // A focused button is pressed when Space is let go, so that's stopped too
    document.addEventListener('keyup', (event) => {
        if (event.key === ' ' && !event.target.closest?.('input, textarea, select, [contenteditable]')) event.preventDefault();
    });

    // The wheel starts where the user's last spin ended
    const lastNumber = historyList.firstElementChild ? Number(historyList.firstElementChild.textContent) : null;
    if (lastNumber !== null && wheelOrder.includes(lastNumber)) turnWheel(lastNumber, false);
    draw();
})();
