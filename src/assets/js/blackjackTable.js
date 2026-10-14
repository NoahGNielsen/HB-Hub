// A Blackjack table (gambling/blackjack/table.php): draws the table as /gambling/blackjack/play says it is, and sends
// what the player does at it. Everything is decided on the server - the cards, whose turn it is, the payouts. This only
// shows it, and only ever knows the cards that are face up.
//
// Asks what's new every second while the page is in view, and every 4 seconds while it isn't (so the table doesn't count
// the player as away, which takes 10 seconds without hearing from them). The whole table only comes back when it changed.
(() => {
    const root = document.querySelector('[data-blackjack]');
    if (!root) return;

    const API = '/gambling/blackjack/play';
    const POLL_MS = 1000;
    const HIDDEN_POLL_MS = 4000;
    const BET_STEP = 10;
    const roomId = root.dataset.room;
    const minBet = Number(root.dataset.minBet);

    const SUITS = { S: '♠', H: '♥', D: '♦', C: '♣' };
    const SUIT_NAMES = { S: 'spades', H: 'hearts', D: 'diamonds', C: 'clubs' };
    const RANK_NAMES = { A: 'Ace', T: '10', J: 'Jack', Q: 'Queen', K: 'King' };
    const RESULTS = { blackjack: 'Blackjack!', win: 'Win', push: 'Push', lose: 'Lose', bust: 'Bust' };
    const MOVE_KEYS = { h: 'hit', s: 'stand', d: 'double', p: 'split' };

    const find = (selector) => root.querySelector(selector);
    const tableElement = find('[data-bj-table]');
    const dealerCards = find('[data-bj-dealer-cards]');
    const dealerTotal = find('[data-bj-dealer-total]');
    const shuffledNote = find('[data-bj-shuffled]');
    const statusText = find('[data-bj-status]');
    const timer = find('[data-bj-timer]');
    const timerBar = find('[data-bj-timer-bar]');
    const timerText = find('[data-bj-timer-text]');
    const seatList = find('[data-bj-seats]');
    const message = find('[data-bj-message]');
    const panels = Object.fromEntries([...root.querySelectorAll('[data-bj-panel]')].map((panel) => [panel.dataset.bjPanel, panel]));
    const pendingText = find('[data-bj-pending]');
    const chipButtons = [...root.querySelectorAll('[data-bj-chip]')];
    const clearButton = find('[data-bj-bet-clear]');
    const halfButton = find('[data-bj-bet-half]');
    const doubleButton = find('[data-bj-bet-double]');
    const placeButton = find('[data-bj-bet-place]');
    const brokeHint = find('[data-bj-broke]');
    const placedText = find('[data-bj-placed]');
    const placedHint = find('[data-bj-placed-hint]');
    const takeBackButton = find('[data-bj-bet-take-back]');
    const insuranceCost = find('[data-bj-insurance-cost]');
    const insuranceButtons = [...root.querySelectorAll('[data-bj-insurance]')];
    const moveButtons = [...root.querySelectorAll('[data-bj-move]')];
    const waitText = find('[data-bj-wait]');
    const leaveButton = find('[data-bj-leave]');
    const leaveDialog = find('[data-bj-leave-dialog]');
    const leaveText = find('[data-bj-leave-text]');

    let rev = null; // the table's revision that's shown
    let table = null;
    let chips = null;
    let pendingBet = minBet; // the bet being made up. Kept for the next hand, so the same bet is one click.
    let isBusy = false; // something the player did is on its way
    let isPolling = false;
    let pollTimer = null;
    let deadlineAt = null; // Date.now() when the table's timer runs out
    let timerTotal = 0;
    let hasAskedAtDeadline = false;
    let shownCards = {}; // the cards each hand had last time it was drawn, so only new ones are animated
    let shownRound = null;
    let isFirstDraw = true; // nothing is animated when the page opens

    const formatChips = (amount) => amount.toLocaleString('en-US');
    const signed = (amount) => (amount > 0 ? '+' : '−') + formatChips(Math.abs(amount));

    async function request(options, query = '') {
        try {
            const response = await fetch(API + query, { headers: { Accept: 'application/json' }, ...options });
            const data = await response.json().catch(() => null);
            if (response.status === 404 && data?.error === 'notSeated') {
                location.assign('/gambling/blackjack/'); // left in another tab, or the table is gone
                return null;
            }
            if (response.status === 403) {
                location.reload(); // blocked in settings since: the page says so
                return null;
            }
            return response.ok ? data : null;
        } catch {
            return null; // offline for a moment
        }
    }

    // A newer answer may already be shown (one from a click can overtake the check before it), so older ones are dropped
    function apply(data) {
        if (data.same || (rev !== null && data.rev < rev)) return;
        rev = data.rev;
        table = data.table;
        if (typeof data.chips === 'number' && data.chips !== chips) {
            chips = data.chips;
            document.dispatchEvent(new CustomEvent('casino:wallet', { detail: { chips } })); // the count in the top bar
        }
        deadlineAt = table.timer ? Date.now() + table.timer.left : null;
        timerTotal = table.timer?.total ?? 0;
        hasAskedAtDeadline = false;
        render();
    }

    async function poll() {
        if (isPolling) return;
        isPolling = true;
        clearTimeout(pollTimer);

        const query = new URLSearchParams({ room: roomId });
        if (rev !== null) query.set('rev', String(rev));
        const data = await request({}, '?' + query);
        if (data !== null) apply(data);

        isPolling = false;
        pollTimer = setTimeout(poll, document.visibilityState === 'visible' ? POLL_MS : HIDDEN_POLL_MS);
    }

    async function act(action, fields = {}) {
        if (isBusy) return;
        isBusy = true;
        showMessage('');
        render();

        const data = await request({ method: 'POST', body: new URLSearchParams({ room: roomId, action, ...fields }) });
        isBusy = false;
        if (data === null) {
            showMessage('Something went wrong. Please try again.');
        } else {
            if (data.error) showMessage(data.error);
            apply(data);
        }
        render();
    }

    function showMessage(text) {
        message.textContent = text;
    }

    function render() {
        if (table === null) return;
        if (table.roundId !== shownRound) {
            shownCards = {};
            shownRound = table.roundId;
        }
        renderDealer();
        seatList.replaceChildren(...table.seats.map(seatElement));
        statusText.textContent = statusFor();
        renderControls();
        tickTimer();
        isFirstDraw = false;
    }

    function renderDealer() {
        const dealer = table.dealer;
        renderCards(dealerCards, 'dealer', dealer?.cards ?? []);
        dealerTotal.textContent = dealer ? totalText(dealer.total, dealer.soft, dealer.blackjack) : '';
        shuffledNote.hidden = !table.reshuffled || ['waiting', 'betting'].includes(table.phase);
        // Before the next hand is dealt, the last one stays on the table, faded
        tableElement.classList.toggle('is-between', ['waiting', 'betting'].includes(table.phase));
    }

    function renderCards(container, key, cards) {
        const before = shownCards[key] ?? [];
        container.replaceChildren(...cards.map((card, i) => cardElement(card, !isFirstDraw && before[i] !== card)));
        shownCards[key] = cards;
    }

    // A face-down card is null: the hole card isn't sent until it's turned over
    function cardElement(card, isNew) {
        const element = document.createElement('span');
        element.className = 'bj-card' + (isNew ? ' is-new' : '');
        element.setAttribute('role', 'img');
        if (card === null) {
            element.classList.add('is-back');
            element.setAttribute('aria-label', 'Face-down card');
            return element;
        }

        const [rank, suit] = card;
        element.classList.add(suit === 'H' || suit === 'D' ? 'is-red' : 'is-black');
        element.setAttribute('aria-label', (RANK_NAMES[rank] ?? rank) + ' of ' + SUIT_NAMES[suit]);

        const corner = document.createElement('span');
        corner.className = 'bj-card-corner';
        const rankText = document.createElement('span');
        rankText.textContent = rank === 'T' ? '10' : rank;
        const suitText = document.createElement('span');
        suitText.textContent = SUITS[suit];
        corner.append(rankText, suitText);

        const pip = document.createElement('span');
        pip.className = 'bj-card-pip';
        pip.textContent = SUITS[suit];

        element.append(corner, pip);
        return element;
    }

    // A soft total (an ace counted as 11) shows both, like "7/17"
    function totalText(total, soft, blackjack) {
        if (blackjack) return 'Blackjack';
        return soft && total < 21 ? (total - 10) + '/' + total : String(total);
    }

    // Built with textContent, as names are typed in by users
    function seatElement(seat) {
        const item = document.createElement('li');
        item.className = 'bj-seat';
        item.classList.toggle('is-you', seat.isYou);
        item.classList.toggle('is-turn', table.turn?.seat === seat.seat);
        item.classList.toggle('is-away', seat.away || seat.left);

        const player = seat.player;
        if (player) {
            const hands = document.createElement('div');
            hands.className = 'bj-hands';
            player.hands.forEach((hand, h) => hands.append(handElement(seat, hand, h)));
            item.append(hands);

            const notes = [];
            if (player.insurance > 0) {
                notes.push(!table.settled ? 'Insured (' + formatChips(player.insurance) + ')'
                    : table.dealer?.blackjack ? 'Insurance paid ' + signed(player.insurance * 2)
                    : 'Insurance lost ' + signed(-player.insurance));
            }
            if (table.settled && player.net !== null) {
                notes.push(player.net === 0 ? 'Total: even' : 'Total: ' + signed(player.net));
            }
            for (const note of notes) {
                const line = document.createElement('p');
                line.className = 'bj-seat-note';
                line.textContent = note;
                item.append(line);
            }
        }

        if (seat.nextBet !== null) {
            const bet = document.createElement('p');
            bet.className = 'bj-seat-bet';
            bet.textContent = 'Bet ' + formatChips(seat.nextBet);
            item.append(bet);
        }

        item.append(playerTag(seat));
        return item;
    }

    function handElement(seat, hand, h) {
        const element = document.createElement('div');
        element.className = 'bj-hand';
        element.classList.toggle('is-turn', hand.isTurn);
        if (hand.result) element.classList.add('is-' + hand.result);

        const cards = document.createElement('div');
        cards.className = 'bj-cards';
        renderCards(cards, seat.seat + '-' + h, hand.cards);

        const info = document.createElement('p');
        info.className = 'bj-hand-info';
        const total = document.createElement('span');
        total.className = 'bj-total';
        total.textContent = hand.blackjack ? 'Blackjack' : hand.total > 21 ? 'Bust ' + hand.total : totalText(hand.total, hand.soft, false);
        const bet = document.createElement('span');
        bet.className = 'bj-hand-bet';
        bet.textContent = formatChips(hand.bet) + (hand.doubled ? ' (doubled)' : '');
        info.append(total, bet);
        element.append(cards, info);

        if (hand.result) {
            const result = document.createElement('p');
            result.className = 'bj-hand-result';
            const change = hand.payout - hand.bet;
            result.textContent = RESULTS[hand.result] + (change !== 0 ? ' ' + signed(change) : '');
            element.append(result);
        }
        return element;
    }

    function playerTag(seat) {
        const tag = document.createElement('p');
        tag.className = 'bj-player';

        const avatar = document.createElement('span');
        avatar.className = 'bj-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        if (seat.avatar) {
            const image = document.createElement('img');
            image.src = seat.avatar;
            image.alt = '';
            avatar.append(image);
        } else {
            avatar.textContent = ([...seat.name][0] ?? '?').toUpperCase();
        }

        const name = document.createElement('span');
        name.className = 'bj-player-name';
        name.textContent = seat.isYou ? 'You' : seat.name;
        tag.append(avatar, name);

        const badge = seat.left ? 'Left' : seat.away ? 'Away' : seat.sittingOut ? 'Sitting out' : null;
        if (badge) {
            const element = document.createElement('span');
            element.className = 'bj-badge';
            element.textContent = badge;
            tag.append(element);
        }
        return tag;
    }

    function turnSeat() {
        return table.turn ? table.seats.find((seat) => seat.seat === table.turn.seat) : null;
    }

    function statusFor() {
        switch (table.phase) {
            case 'waiting':
                return 'Place a bet to deal the next hand.';
            case 'betting':
                return 'Bets are open.';
            case 'insurance':
                return 'The dealer shows an ace. Insurance is offered.';
            case 'playing': {
                const seat = turnSeat();
                if (!seat?.isYou) return (seat?.name || 'A player') + '\'s turn';
                return seat.player.hands.length > 1 ? 'Your turn, hand ' + (table.turn.hand + 1) : 'Your turn';
            }
            case 'dealer':
                return 'Dealer\'s turn';
            case 'results': {
                const you = table.seats.find((seat) => seat.isYou)?.player;
                const dealer = table.dealer?.blackjack ? 'The dealer has blackjack. ' : '';
                if (!you || you.net === null) return dealer + 'Hand over.';
                if (you.net > 0) return dealer + 'You won ' + formatChips(you.net) + ' chips!';
                if (you.net < 0) return dealer + 'You lost ' + formatChips(-you.net) + ' chips.';
                return dealer + 'You got your chips back.';
            }
            default:
                return '';
        }
    }

    // The most a bet can be: the chips there are, in steps of 10
    function maxBet() {
        return Math.floor((chips ?? 0) / BET_STEP) * BET_STEP;
    }

    function renderControls() {
        const you = table.you;
        let panel = 'wait';
        if (you?.insurance) panel = 'insurance';
        else if (you?.moves.length > 0) panel = 'moves';
        else if (you?.canBet) panel = 'bet';
        else if (you?.nextBet !== null && you?.nextBet !== undefined) panel = 'placed';
        for (const [name, element] of Object.entries(panels)) element.hidden = name !== panel;

        if (panel === 'bet') {
            pendingBet = Math.min(pendingBet, maxBet());
            pendingText.textContent = formatChips(pendingBet);
            chipButtons.forEach((button) => (button.disabled = isBusy || pendingBet + Number(button.dataset.bjChip) > maxBet()));
            clearButton.disabled = isBusy || pendingBet === 0;
            halfButton.disabled = isBusy || pendingBet <= minBet;
            doubleButton.disabled = isBusy || pendingBet === 0 || pendingBet * 2 > maxBet();
            placeButton.disabled = isBusy || pendingBet < minBet || pendingBet > maxBet();
            placeButton.textContent = table.phase === 'results' ? 'Bet on the next hand' : 'Place bet';
            brokeHint.hidden = maxBet() >= minBet;
        } else if (panel === 'placed') {
            placedText.textContent = formatChips(you.nextBet);
            placedHint.textContent = table.phase === 'results'
                ? 'It\'s dealt when the next hand starts.'
                : 'Waiting for everyone at the table to bet.';
            takeBackButton.disabled = isBusy || !you.canClearBet;
        } else if (panel === 'insurance') {
            insuranceCost.textContent = formatChips(you.insurance.cost);
            insuranceButtons.forEach((button) => {
                button.disabled = isBusy || (button.dataset.bjInsurance === '1' && !you.insurance.canAfford);
            });
        } else if (panel === 'moves') {
            moveButtons.forEach((button) => (button.disabled = isBusy || !you.moves.includes(button.dataset.bjMove)));
        } else {
            waitText.textContent = waitingFor();
        }
    }

    function waitingFor() {
        switch (table.phase) {
            case 'insurance':
                return 'Waiting for the others to decide on insurance.';
            case 'playing':
                return 'Waiting for ' + (turnSeat()?.name || 'the other players') + '.';
            case 'dealer':
                return 'The dealer is playing.';
            default:
                return table.you?.canBet === false && table.seats.find((seat) => seat.isYou)?.sittingOut
                    ? 'You\'re sitting out.'
                    : '';
        }
    }

    // Runs 5 times a second: the timer bar, and asking straight away what happened once it runs out
    function tickTimer() {
        if (deadlineAt === null) {
            timer.hidden = true;
            return;
        }
        const left = Math.max(0, deadlineAt - Date.now());
        timer.hidden = false;
        timer.classList.toggle('is-low', left <= 5000);
        timerBar.style.transform = 'scaleX(' + (timerTotal > 0 ? left / timerTotal : 0) + ')';
        timerText.textContent = Math.ceil(left / 1000) + 's';
        if (left === 0 && !hasAskedAtDeadline) {
            hasAskedAtDeadline = true;
            poll();
        }
    }

    // Making up the bet
    chipButtons.forEach((button) => button.addEventListener('click', () => {
        pendingBet = Math.min(pendingBet + Number(button.dataset.bjChip), maxBet());
        renderControls();
    }));
    clearButton.addEventListener('click', () => {
        pendingBet = 0;
        renderControls();
    });
    halfButton.addEventListener('click', () => {
        pendingBet = Math.max(minBet, Math.floor(pendingBet / 2 / BET_STEP) * BET_STEP);
        renderControls();
    });
    doubleButton.addEventListener('click', () => {
        pendingBet = Math.min(pendingBet * 2, maxBet());
        renderControls();
    });
    placeButton.addEventListener('click', () => act('bet', { amount: String(pendingBet) }));
    takeBackButton.addEventListener('click', () => act('clearBet'));

    insuranceButtons.forEach((button) => button.addEventListener('click', () => act('insurance', { take: button.dataset.bjInsurance })));

    // The turnId goes with every move, so a double click (or a click just as the timer ran out) only counts once
    function move(name) {
        if (table?.you?.moves.includes(name)) act(name, { turn: String(table.turnId) });
    }
    moveButtons.forEach((button) => button.addEventListener('click', () => move(button.dataset.bjMove)));
    document.addEventListener('keydown', (event) => {
        if (panels.moves.hidden || leaveDialog.open || event.repeat || event.ctrlKey || event.metaKey || event.altKey) return;
        if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;
        const name = MOVE_KEYS[event.key.toLowerCase()];
        if (name) {
            event.preventDefault();
            move(name);
        }
    });

    // Leaving: asked first when there are chips on the table
    async function leave() {
        const data = await request({ method: 'POST', body: new URLSearchParams({ room: roomId, action: 'leave' }) });
        if (data?.left) {
            location.assign('/gambling/blackjack/');
        } else {
            showMessage('Could not leave the table. Please try again.');
        }
    }

    leaveButton.addEventListener('click', () => {
        const isInHand = ['insurance', 'playing', 'dealer'].includes(table?.phase) && table.seats.some((seat) => seat.isYou && seat.player);
        const hasBet = table?.you?.nextBet !== null && table?.you?.nextBet !== undefined;
        if (!isInHand && !hasBet) {
            leave();
            return;
        }
        leaveText.textContent = isInHand
            ? 'You\'re in the middle of a hand. Your hand will stand, and anything it wins still goes to your chips.'
            : 'Your bet on the next hand will be given back.';
        leaveDialog.showModal();
        find('[data-bj-leave-cancel]').focus(); // the safe choice is the one Enter picks
    });
    find('[data-bj-leave-cancel]').addEventListener('click', () => leaveDialog.close());
    find('[data-bj-leave-confirm]').addEventListener('click', () => {
        leaveDialog.close();
        leave();
    });

    setInterval(tickTimer, 200);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') poll(); // catch up when coming back to the tab
    });
    poll();
})();
