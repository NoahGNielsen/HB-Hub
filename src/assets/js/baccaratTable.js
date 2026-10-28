// A Baccarat table (gambling/baccarat/table.php): draws the table as /gambling/baccarat/play says it is, and sends the
// player's bets. Everything is decided on the server - the cards, the payouts, when the shoe is shuffled. This only
// shows it, and only ever knows the cards that have been turned over.
//
// Bets are made up here first (a chip on a spot per tap, and a right-click takes one off again), then placed all at
// once. The bets made up are kept for the next hand, so betting the same again is one click.
//
// Asks what's new every second while the page is in view, and every 4 seconds while it isn't (so the table doesn't count
// the player as away, which takes 10 seconds without hearing from them), and straight away when the next card is due.
// The whole table only comes back when its version changed: something happened, or someone joined, left, went away or
// came back. A solo table has no join code, inviting or sitting out, so those aren't on the page.
//
// The guest Baccarat at /play/baccarat (data-guest, see assets/php/guestGames/guestGames.php) is a solo table played
// the same way through /play/baccarat/play (data-api), but the server keeps nothing: every answer has the table and
// the play chips as a sealed token, kept here in the page only, and every request sends it back (guestRequest()).
(() => {
    const root = document.querySelector('[data-baccarat]');
    if (!root) return;

    const API = root.dataset.api;
    const IS_GUEST = 'guest' in root.dataset;
    const POLL_MS = 1000;
    const HIDDEN_POLL_MS = 4000;
    const BET_STEP = 10;
    const SIDES = ['player', 'banker'];
    const SPOTS = ['player', 'banker', 'tie', 'playerPair', 'bankerPair']; // the order bets are listed in
    const SPOT_NAMES = { player: 'Player', banker: 'Banker', tie: 'Tie', playerPair: 'Player pair', bankerPair: 'Banker pair' };
    const OTHER_SIDE = { player: 'banker', banker: 'player' };
    const roomId = root.dataset.room;
    const minBet = Number(root.dataset.minBet);
    const baseTitle = document.title;

    const SUITS = { S: '♠', H: '♥', D: '♦', C: '♣' };
    const SUIT_NAMES = { S: 'spades', H: 'hearts', D: 'diamonds', C: 'clubs' };
    const RANK_NAMES = { A: 'Ace', T: '10', J: 'Jack', Q: 'Queen', K: 'King' };

    const find = (selector) => root.querySelector(selector);
    const tableElement = find('[data-bac-table]');
    const statusText = find('[data-bac-status]');
    const timer = find('[data-bac-timer]');
    const timerBar = find('[data-bac-timer-bar]');
    const timerText = find('[data-bac-timer-text]');
    const hands = Object.fromEntries(SIDES.map((side) => {
        const element = find('[data-bac-hand="' + side + '"]');
        return [side, {
            element,
            total: element.querySelector('[data-bac-total]'),
            cards: element.querySelector('[data-bac-cards]'),
            note: element.querySelector('[data-bac-note]'),
        }];
    }));
    const resultText = find('[data-bac-result]');
    const shoeNotice = find('[data-bac-shoe-notice]');
    const shoe = find('[data-bac-shoe]');
    const shoeBar = find('[data-bac-shoe-bar]');
    const shoeCut = find('[data-bac-shoe-cut]');
    const shoeText = find('[data-bac-shoe-text]');
    const seatList = find('[data-bac-seats]');
    const message = find('[data-bac-message]');
    const panels = Object.fromEntries([...root.querySelectorAll('[data-bac-panel]')].map((panel) => [panel.dataset.bacPanel, panel]));
    const pendingText = find('[data-bac-pending]');
    const spotButtons = [...root.querySelectorAll('[data-bac-spot]')];
    const chipButtons = [...root.querySelectorAll('[data-bac-chip]')];
    const clearButton = find('[data-bac-bet-clear]');
    const doubleButton = find('[data-bac-bet-double]');
    const placeButton = find('[data-bac-bet-place]');
    const brokeHint = find('[data-bac-broke]');
    const placedText = find('[data-bac-placed]');
    const placedList = find('[data-bac-placed-list]');
    const placedHint = find('[data-bac-placed-hint]');
    const takeBackButton = find('[data-bac-bet-take-back]');
    const waitText = find('[data-bac-wait]');
    const playersText = find('[data-bac-players]');
    const codeButton = find('[data-bac-code]');
    const codeCopied = find('[data-bac-code-copied]');
    const sitOutButton = find('[data-bac-sit-out]');
    const inviteButton = find('[data-bac-invite]');
    const inviteDialog = find('[data-bac-invite-dialog]');
    const leaveButton = find('[data-bac-leave]');
    const leaveDialog = find('[data-bac-leave-dialog]');
    const leaveText = find('[data-bac-leave-text]');

    let version = null; // the table's version that's shown
    let revision = -1; // the room's revision in it, to tell older answers from newer ones
    let table = null;
    let chips = null;
    let pending = noBets(); // the bets being made up. Kept for the next hand, so the same bets are one click.
    let chip = chipButtons.some((button) => Number(button.dataset.bacChip) === minBet) ? minBet : BET_STEP; // the chip a tap puts down
    let isBusy = false; // something the player did is on its way
    let isPolling = false;
    let pollTimer = null;
    let deadlineAt = null; // Date.now() when the table's timer runs out
    let timerTotal = 0;
    let hasAskedAtDeadline = false;
    let shownCards = {}; // the cards each hand had last time it was drawn, so only new ones are animated
    let shownRound = null;
    let shownShuffle = null; // the shuffle notice last shown, so a new one catches the eye
    let isFirstDraw = true; // nothing is animated when the page opens

    const formatChips = (amount) => amount.toLocaleString('en-US');
    const signed = (amount) => (amount > 0 ? '+' : '−') + formatChips(Math.abs(amount));
    const sum = (bets) => SPOTS.reduce((total, spot) => total + (bets[spot] ?? 0), 0);

    function noBets() {
        return Object.fromEntries(SPOTS.map((spot) => [spot, 0]));
    }

    let guestToken = ''; // a guest's table and play chips, sealed by the server (none at first: a new table)
    let guestQueue = Promise.resolve();

    // A guest's requests go one at a time, each with the newest token: two at once would both start from the same
    // table, and the one answered last would undo the other. A check for what's new is action=poll.
    function guestRequest(options) {
        const send = async () => {
            const body = options.body ?? new URLSearchParams({ action: 'poll' });
            body.set('token', guestToken);
            try {
                const response = await fetch(API, { method: 'POST', headers: { Accept: 'application/json' }, body });
                if (response.status === 403) {
                    location.reload(); // the casino was closed: the page is gone
                    return null;
                }
                const data = await response.json().catch(() => null);
                if (!response.ok) return null;
                if (typeof data?.token === 'string') guestToken = data.token;
                return data;
            } catch {
                return null; // offline for a moment
            }
        };
        const answer = guestQueue.then(send);
        guestQueue = answer;
        return answer;
    }

    async function request(options, query = '') {
        if (IS_GUEST) return guestRequest(options);
        try {
            const response = await fetch(API + query, { headers: { Accept: 'application/json' }, ...options });
            const data = await response.json().catch(() => null);
            if (response.status === 404 && data?.error === 'notSeated') {
                location.assign('/gambling/baccarat/'); // left in another tab, gone too long, or the table is gone
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
        const dataRevision = parseInt(data.v, 10);
        if (data.same || dataRevision < revision) return;
        version = data.v;
        revision = dataRevision;
        table = data.table;
        if (typeof data.chips === 'number' && data.chips !== chips) {
            chips = data.chips;
            document.dispatchEvent(new CustomEvent('casino:wallet', { detail: { chips } })); // the count in the top bar
        }
        deadlineAt = table.timer ? Date.now() + table.timer.left : null;
        timerTotal = table.timer?.total ?? 0;
        hasAskedAtDeadline = false;
        if (data.notice) showMessage(data.notice); // a guest who ran out of play chips got a new stack
        render();
    }

    async function poll() {
        if (isPolling) return;
        isPolling = true;
        clearTimeout(pollTimer);

        const query = new URLSearchParams({ room: roomId });
        if (version !== null) query.set('v', version);
        const ride = { params: null };
        document.dispatchEvent(new CustomEvent('nav:ride', { detail: ride })); // the nav bar's request comes along (navUpdates.js)
        if (ride.params !== null) query.set('nav', ride.params);
        const data = await request({}, '?' + query);
        if (data?.nav) document.dispatchEvent(new CustomEvent('nav:update', { detail: data.nav }));
        if (data !== null) apply(data);

        isPolling = false;
        pollTimer = setTimeout(poll, document.visibilityState === 'visible' ? POLL_MS : HIDDEN_POLL_MS);
    }

    function post(action, fields = {}) {
        return request({ method: 'POST', body: new URLSearchParams({ room: roomId, action, ...fields }) });
    }

    async function act(action, fields = {}) {
        if (isBusy) return;
        isBusy = true;
        showMessage('');
        render();

        const data = await post(action, fields);
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
        renderHeader();
        renderShoeNotice();
        renderCoup();
        renderShoe();
        renderSeats();
        statusText.textContent = statusFor();
        renderControls();
        tickTimer();
        isFirstDraw = false;
    }

    function renderHeader() {
        if (playersText) playersText.textContent = String(table.room.players);
        if (inviteButton) inviteButton.hidden = !table.you?.isHost;
        if (sitOutButton) {
            sitOutButton.hidden = !table.you;
            sitOutButton.textContent = table.you?.sittingOut ? 'Sit back in' : 'Sit out';
            sitOutButton.disabled = isBusy;
        }
    }

    // Told once the shoe has been shuffled, before anyone bets on the hand from the new one, and while that's dealt
    function renderShoeNotice() {
        const kind = table.shuffle;
        shoeNotice.hidden = kind === null;
        if (kind === 'shuffled') {
            shoeNotice.textContent = 'Shuffle! 75% of the shoe has been dealt, so all ' + (table.shoe.size / 52)
                + ' decks have been shuffled into a new shoe. The next hand is dealt from it.';
        } else if (kind === 'firstHand') {
            shoeNotice.textContent = 'This is the first hand from the new shoe.';
        }
        if (kind !== shownShuffle && kind !== null && !isFirstDraw) {
            shoeNotice.classList.remove('is-new');
            void shoeNotice.offsetWidth; // so the animation starts over
            shoeNotice.classList.add('is-new');
        }
        shownShuffle = kind;
    }

    function renderCoup() {
        const coup = table.coup;
        const isDealing = table.phase === 'dealing';
        for (const side of SIDES) {
            const hand = hands[side];
            const cards = [...(coup?.[side].cards ?? [])];
            // Both hands always get two cards, so those show face down until they're turned over
            while (isDealing && cards.length < 2) cards.push(null);
            renderCards(hand.cards, side, cards);

            const shown = coup?.[side].cards ?? [];
            hand.total.textContent = shown.length > 0 ? String(coup[side].total) : '';
            const notes = [];
            if (coup?.natural && shown.length === 2 && coup[side].total >= 8) notes.push('Natural ' + coup[side].total);
            if (coup?.[side + 'Pair']) notes.push('Pair!');
            hand.note.textContent = notes.join(' · ');
            hand.element.classList.toggle('is-winner', coup?.winner === side);
            hand.element.classList.toggle('is-loser', Boolean(coup?.winner) && coup.winner !== side && coup.winner !== 'tie');
        }

        resultText.hidden = !coup?.winner;
        if (coup?.winner) {
            const player = coup.player.total;
            const banker = coup.banker.total;
            resultText.className = 'bac-result is-' + coup.winner;
            resultText.textContent = coup.winner === 'tie' ? 'Tie at ' + player
                : coup.winner === 'player' ? 'Player wins, ' + player + ' to ' + banker
                : 'Banker wins, ' + banker + ' to ' + player;
        }
        // Before the next hand is dealt, the last one stays on the table, faded
        tableElement.classList.toggle('is-between', ['waiting', 'betting'].includes(table.phase));
    }

    function renderCards(container, key, cards) {
        const before = shownCards[key] ?? [];
        container.replaceChildren(...cards.map((card, i) => cardElement(card, !isFirstDraw && card !== null && before[i] !== card)));
        shownCards[key] = cards;
    }

    // A face-down card is null: cards aren't sent until they're turned over
    function cardElement(card, isNew) {
        const element = document.createElement('span');
        element.className = 'bac-card' + (isNew ? ' is-new' : '');
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
        corner.className = 'bac-card-corner';
        const rankText = document.createElement('span');
        rankText.textContent = rank === 'T' ? '10' : rank;
        const suitText = document.createElement('span');
        suitText.textContent = SUITS[suit];
        corner.append(rankText, suitText);

        const pip = document.createElement('span');
        pip.className = 'bac-card-pip';
        pip.textContent = SUITS[suit];

        element.append(corner, pip);
        return element;
    }

    // How far through the shoe the table is, with a mark where it's shuffled
    function renderShoe() {
        const { left, size, reshuffleAt } = table.shoe;
        shoe.hidden = false;
        shoeBar.style.transform = 'scaleX(' + left / size + ')';
        shoeCut.style.left = (reshuffleAt / size) * 100 + '%';
        shoeText.textContent = formatChips(left) + ' cards left in the shoe. It\'s shuffled at ' + reshuffleAt + '.';
    }

    // Every seat at the table in order, the empty ones too
    function renderSeats() {
        const slots = [];
        for (let number = 1; number <= table.room.seats; number++) {
            const seat = table.seats.find((candidate) => candidate.seat === number);
            slots.push(seat ? seatElement(seat) : emptySeat());
        }
        seatList.replaceChildren(...slots);
    }

    function emptySeat() {
        const item = document.createElement('li');
        item.className = 'bac-seat is-empty';
        const text = document.createElement('p');
        text.className = 'bac-empty';
        text.textContent = 'Empty seat';
        item.append(text);
        return item;
    }

    // Built with textContent, as names are typed in by users
    function seatElement(seat) {
        const item = document.createElement('li');
        item.className = 'bac-seat';
        item.classList.toggle('is-you', seat.isYou);
        item.classList.toggle('is-away', seat.away || seat.left);

        const player = seat.player;
        if (player) {
            item.append(betList(player.spots, table.settled ? player.payouts : null));
            if (table.settled && player.net !== null) {
                const net = document.createElement('p');
                net.className = 'bac-seat-net' + (player.net > 0 ? ' is-up' : player.net < 0 ? ' is-down' : '');
                net.textContent = player.net === 0 ? 'Even' : signed(player.net);
                item.append(net);
            }
        }

        if (seat.nextBets) {
            const bet = document.createElement('p');
            bet.className = 'bac-seat-bet';
            bet.textContent = 'Next hand ' + formatChips(sum(seat.nextBets));
            bet.title = betSummary(seat.nextBets);
            item.append(bet);
        }

        item.append(playerTag(seat));
        return item;
    }

    // The bets as little colored tags: blue for the Player, red for the Banker, green for a tie. Once the hand is paid
    // out ($payouts), each shows what it won or lost.
    function betList(bets, payouts) {
        const list = document.createElement('ul');
        list.className = 'bac-bets';
        for (const spot of SPOTS) {
            const amount = bets[spot];
            if (!amount) continue;
            const item = document.createElement('li');
            item.className = 'bac-bet is-' + spot;
            let text = SPOT_NAMES[spot] + ' ' + formatChips(amount);
            if (payouts) {
                const change = (payouts[spot] ?? 0) - amount;
                item.classList.add(change > 0 ? 'is-won' : change < 0 ? 'is-lost' : 'is-push');
                text += change === 0 ? ' (back)' : ' ' + signed(change);
            }
            item.textContent = text;
            list.append(item);
        }
        return list;
    }

    function betSummary(bets) {
        return SPOTS.filter((spot) => bets[spot]).map((spot) => SPOT_NAMES[spot] + ' ' + formatChips(bets[spot])).join(', ');
    }

    function playerTag(seat) {
        const tag = document.createElement('div');
        tag.className = 'bac-player';

        const avatar = document.createElement('span');
        avatar.className = 'bac-avatar';
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
        name.className = 'bac-player-name';
        name.textContent = seat.isYou ? 'You' : seat.name;
        tag.append(avatar, name);

        const badges = [];
        if (seat.isHost && table.room.type !== 'solo') badges.push(['Host', 'is-host']);
        if (seat.left) badges.push(['Left', '']);
        else if (seat.away) badges.push(['Away', '']);
        else if (seat.sittingOut) badges.push(['Sitting out', '']);
        else if (table.phase === 'dealing' && !seat.player) badges.push(['Next hand', '']);
        for (const [text, className] of badges) {
            const badge = document.createElement('span');
            badge.className = 'bac-badge' + (className ? ' ' + className : '');
            badge.textContent = text;
            tag.append(badge);
        }
        return tag;
    }

    function statusFor() {
        switch (table.phase) {
            case 'waiting':
                return table.playersHere < table.minPlayers
                    ? 'Waiting for players. Baccarat needs at least ' + table.minPlayers + ' at the table.'
                    : 'Place your bets to deal the next hand.';
            case 'betting':
                return 'Bets are open.';
            case 'dealing':
                return table.coup?.natural ? 'A natural! Both hands stand.' : 'Dealing…';
            case 'results': {
                const winner = table.coup?.winner;
                const outcome = winner === 'tie' ? 'It\'s a tie. ' : winner ? SPOT_NAMES[winner] + ' wins. ' : '';
                const you = table.seats.find((seat) => seat.isYou)?.player;
                if (!you || you.net === null) return outcome + (table.you?.canBet ? 'Bets for the next hand are open.' : '');
                if (you.net > 0) return outcome + 'You won ' + formatChips(you.net) + ' chips!';
                if (you.net < 0) return outcome + 'You lost ' + formatChips(-you.net) + ' chips.';
                return outcome + 'You got your chips back.';
            }
            default:
                return '';
        }
    }

    // The most the bets can be together: the chips there are, in steps of 10
    function maxBet() {
        return Math.floor((chips ?? 0) / BET_STEP) * BET_STEP;
    }

    function renderControls() {
        const you = table.you;
        let panel = 'wait';
        if (you?.canBet) panel = 'bet';
        else if (you?.nextBets) panel = 'placed';
        for (const [name, element] of Object.entries(panels)) element.hidden = name !== panel;

        // So a player in another tab can see it's time to bet
        document.title = (panel === 'bet' && table.phase === 'betting' ? 'Bets open - ' : '') + baseTitle;

        if (panel === 'bet') {
            if (sum(pending) > maxBet()) pending = noBets(); // spent elsewhere since
            const total = sum(pending);
            pendingText.textContent = formatChips(total);
            for (const button of spotButtons) {
                const spot = button.dataset.bacSpot;
                const amount = pending[spot];
                button.querySelector('[data-bac-spot-chips]').textContent = amount > 0 ? formatChips(amount) : '';
                button.classList.toggle('has-chips', amount > 0);
                button.classList.toggle('is-blocked', OTHER_SIDE[spot] !== undefined && pending[OTHER_SIDE[spot]] > 0);
                // A spot with chips on stays enabled when there's no room for another, so they can still be right-clicked off
                button.disabled = isBusy || (total + chip > maxBet() && amount === 0);
                button.setAttribute('aria-label', SPOT_NAMES[spot] + ', pays ' + button.dataset.bacPays
                    + (amount > 0 ? ', ' + formatChips(amount) + ' on it' : ''));
            }
            for (const button of chipButtons) {
                const value = Number(button.dataset.bacChip);
                button.setAttribute('aria-pressed', String(value === chip));
                button.disabled = isBusy || value > maxBet();
            }
            clearButton.disabled = isBusy || total === 0;
            doubleButton.disabled = isBusy || total === 0 || total * 2 > maxBet();
            placeButton.disabled = isBusy || total < minBet || total > maxBet();
            placeButton.textContent = table.phase === 'results' ? 'Bet on the next hand' : 'Place bets';
            brokeHint.hidden = maxBet() >= minBet;
        } else if (panel === 'placed') {
            placedText.textContent = formatChips(sum(you.nextBets));
            placedList.replaceChildren(...betList(you.nextBets, null).children);
            placedHint.textContent = table.phase === 'results'
                ? 'They\'re on the next hand.'
                : 'Waiting for everyone at the table to bet.';
            takeBackButton.disabled = isBusy || !you.canClearBet;
        } else {
            waitText.textContent = waitingFor();
        }
    }

    function waitingFor() {
        if (table.you?.sittingOut) return 'You\'re sitting out. Sit back in to bet on the next hand.';
        const isInThisHand = table.seats.some((seat) => seat.isYou && seat.player);
        if (table.phase === 'dealing') return isInThisHand ? 'Good luck!' : 'You\'re in from the next hand.';
        if (table.playersHere < table.minPlayers) {
            return 'Bets open once ' + table.minPlayers + ' players are at the table. Share the code at the top, or invite someone.';
        }
        return '';
    }

    // Runs 5 times a second: the timer bar, and asking straight away what happened once it runs out (the next card too)
    function tickTimer() {
        if (deadlineAt === null) {
            timer.hidden = true;
            return;
        }
        const left = Math.max(0, deadlineAt - Date.now());
        timer.hidden = table.phase === 'dealing'; // the cards are timed only to space them out
        timer.classList.toggle('is-low', left <= 5000);
        timerBar.style.transform = 'scaleX(' + (timerTotal > 0 ? left / timerTotal : 0) + ')';
        timerText.textContent = Math.ceil(left / 1000) + 's';
        if (left === 0 && !hasAskedAtDeadline) {
            hasAskedAtDeadline = true;
            poll();
        }
    }

    // Making up the bets: pick a chip, then tap the spots to put it down
    chipButtons.forEach((button) => button.addEventListener('click', () => {
        chip = Number(button.dataset.bacChip);
        renderControls();
    }));
    spotButtons.forEach((button) => button.addEventListener('click', () => {
        const spot = button.dataset.bacSpot;
        const other = OTHER_SIDE[spot];
        if (other !== undefined && pending[other] > 0) {
            showMessage('Bet on Player or Banker, not both. Clear your bets to switch.');
            return;
        }
        if (sum(pending) + chip > maxBet()) return; // the empty spots are disabled then too
        showMessage('');
        pending[spot] += chip;
        renderControls();
    }));
    // A right-click takes off what a click puts down: the chip picked (or what's left, if that's less)
    spotButtons.forEach((button) => button.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        const spot = button.dataset.bacSpot;
        if (isBusy || pending[spot] === 0) return;
        showMessage('');
        pending[spot] = Math.max(0, pending[spot] - chip);
        renderControls();
    }));
    clearButton.addEventListener('click', () => {
        pending = noBets();
        showMessage('');
        renderControls();
    });
    doubleButton.addEventListener('click', () => {
        if (sum(pending) * 2 > maxBet()) return;
        for (const spot of SPOTS) pending[spot] *= 2;
        renderControls();
    });
    placeButton.addEventListener('click', () => {
        const fields = {};
        for (const spot of SPOTS) {
            if (pending[spot] > 0) fields['bets[' + spot + ']'] = String(pending[spot]);
        }
        act('bet', fields);
    });
    takeBackButton.addEventListener('click', () => act('clearBet'));

    sitOutButton?.addEventListener('click', () => act('sitOut', { out: table?.you?.sittingOut ? '0' : '1' }));

    // The join code: a click copies it, to send to others
    codeButton?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(codeButton.dataset.bacCode);
            codeCopied.textContent = 'Copied';
        } catch {
            codeCopied.textContent = ''; // no clipboard access: the code is right there to read
        }
        setTimeout(() => (codeCopied.textContent = ''), 2000);
    });

    // Inviting (only the host sees the button): find people with the chat's user search
    if (inviteDialog) {
        const search = inviteDialog.querySelector('[data-bac-invite-search]');
        const list = inviteDialog.querySelector('[data-bac-invite-list]');
        const status = inviteDialog.querySelector('[data-bac-invite-status]');
        let searchTimer = null;
        let searchNumber = 0;

        async function loadUsers() {
            const number = ++searchNumber;
            let data = null;
            try {
                const response = await fetch('/chat/users?q=' + encodeURIComponent(search.value.trim()), { headers: { Accept: 'application/json' } });
                data = response.ok ? await response.json() : null;
            } catch {
                data = null;
            }
            if (number !== searchNumber) return; // a newer search is on its way
            if (data === null) {
                status.textContent = 'Could not search. Please try again.';
                return;
            }
            const seated = new Set(table?.seats.map((seat) => seat.userId) ?? []);
            list.replaceChildren(...data.users.map((user) => userItem(user, seated.has(Number(user.userId)))));
            status.textContent = data.users.length === 0 ? 'Nobody found.' : '';
        }

        function userItem(user, isSeated) {
            const item = document.createElement('li');
            item.className = 'bac-invite-user';
            const name = document.createElement('span');
            name.className = 'bac-player-name';
            name.textContent = user.userName;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'casino-btn';
            button.textContent = isSeated ? 'At the table' : 'Invite';
            button.disabled = isSeated;
            button.addEventListener('click', () => invite(user, button));
            item.append(name, button);
            return item;
        }

        async function invite(user, button) {
            button.disabled = true;
            const data = await post('invite', { user: String(user.userId) });
            if (data === null) {
                status.textContent = 'Something went wrong. Please try again.';
                button.disabled = false;
                return;
            }
            apply(data);
            if (data.error) {
                status.textContent = data.error;
                button.disabled = false;
            } else {
                status.textContent = user.userName + ' is invited.';
                button.textContent = 'Invited';
            }
        }

        inviteButton.addEventListener('click', () => {
            search.value = '';
            status.textContent = '';
            inviteDialog.showModal();
            search.focus();
            loadUsers();
        });
        search.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(loadUsers, 200);
        });
        inviteDialog.querySelector('[data-bac-invite-close]').addEventListener('click', () => inviteDialog.close());
    }

    // Leaving: asked first when there are chips on the table
    async function leave() {
        if (IS_GUEST) {
            location.assign('/'); // nothing to leave: the table is only in this page, so back to the frontpage
            return;
        }
        const data = await post('leave');
        if (data?.left) {
            location.assign('/gambling/baccarat/');
        } else {
            showMessage('Could not leave the table. Please try again.');
        }
    }

    leaveButton.addEventListener('click', () => {
        const isPlaying = table !== null && table.phase === 'dealing' && table.seats.some((seat) => seat.isYou && seat.player);
        const hasBet = Boolean(table?.you?.nextBets);
        if (!isPlaying && !hasBet) {
            leave();
            return;
        }
        leaveText.textContent = IS_GUEST ? 'Your bets are on the hand being dealt. Leaving ends it, and your play chips start over.'
            : isPlaying ? 'Your bets are on the hand being dealt. They stay on it, and anything they win still goes to your chips.'
            : 'Your bets on the next hand will be given back.';
        leaveDialog.showModal();
        find('[data-bac-leave-cancel]').focus(); // the safe choice is the one Enter picks
    });
    find('[data-bac-leave-cancel]').addEventListener('click', () => leaveDialog.close());
    find('[data-bac-leave-confirm]').addEventListener('click', () => {
        leaveDialog.close();
        leave();
    });

    setInterval(tickTimer, 200);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') poll(); // catch up when coming back to the tab
    });
    poll();
})();
