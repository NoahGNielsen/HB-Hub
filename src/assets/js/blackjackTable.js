// A Blackjack table (casino/blackjack/table.php): draws the table as /casino/blackjack/play says it is, and sends
// what the player does at it. Everything is decided on the server - the cards, whose turn it is, the payouts. This only
// shows it, and only ever knows the cards that are face up.
//
// Asks what's new every second while the page is in view, and every 4 seconds while it isn't (so the table doesn't count
// the player as away, which takes 10 seconds without hearing from them). The whole table only comes back when its
// version changed: something happened, or someone joined, left, went away or came back.
//
// The guest Blackjack at /play/blackjack (data-guest, see assets/php/guestGames/guestGames.php) is a solo table played
// the same way through /play/blackjack/play (data-api), but the server keeps nothing: every answer has the table and
// the play chips as a sealed token, kept here in the page only, and every request sends it back (guestRequest()).
(() => {
    const root = document.querySelector('[data-blackjack]');
    if (!root) return;

    const API = root.dataset.api;
    const IS_GUEST = 'guest' in root.dataset;
    const POLL_MS = 1000;
    const HIDDEN_POLL_MS = 4000;
    const BET_STEP = 10;
    const roomId = root.dataset.room;
    const minBet = Number(root.dataset.minBet);
    const baseTitle = document.title;

    const SUITS = { S: '♠', H: '♥', D: '♦', C: '♣' };
    const SUIT_NAMES = { S: 'spades', H: 'hearts', D: 'diamonds', C: 'clubs' };
    const RANK_NAMES = { A: 'Ace', T: '10', J: 'Jack', Q: 'Queen', K: 'King' };
    const RESULTS = { blackjack: 'Blackjack!', win: 'Win', push: 'Push', lose: 'Lose', bust: 'Bust' };
    const MOVE_KEYS = { h: 'hit', s: 'stand', d: 'double', p: 'split' };
    const IN_HAND_PHASES = ['insurance', 'playing', 'dealer'];

    const find = (selector) => root.querySelector(selector);
    const tableElement = find('[data-bj-table]');
    const dealerCards = find('[data-bj-dealer-cards]');
    const dealerTotal = find('[data-bj-dealer-total]');
    const shuffledNote = find('[data-bj-shuffled]');
    const shoe = find('[data-bj-shoe]');
    const shoeBar = find('[data-bj-shoe-bar]');
    const shoeCut = find('[data-bj-shoe-cut]');
    const shoeText = find('[data-bj-shoe-text]');
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
    // Members with fewer chips than this (HBHUB_CASINO_ALL_IN_BELOW) can bet all of them, whatever the table's minimum
    const allInButton = find('[data-bj-all-in]');
    const ALL_IN_BELOW = allInButton ? Number(allInButton.dataset.bjAllIn) : 0;
    const placedText = find('[data-bj-placed]');
    const placedHint = find('[data-bj-placed-hint]');
    const takeBackButton = find('[data-bj-bet-take-back]');
    const insuranceCost = find('[data-bj-insurance-cost]');
    const insuranceButtons = [...root.querySelectorAll('[data-bj-insurance]')];
    const moveButtons = [...root.querySelectorAll('[data-bj-move]')];
    const waitText = find('[data-bj-wait]');
    const playersText = find('[data-bj-players]');
    const codeButton = find('[data-bj-code]');
    const codeCopied = find('[data-bj-code-copied]');
    const sitOutButton = find('[data-bj-sit-out]');
    const inviteButton = find('[data-bj-invite]');
    const inviteDialog = find('[data-bj-invite-dialog]');
    const leaveButton = find('[data-bj-leave]');
    const leaveDialog = find('[data-bj-leave-dialog]');
    const leaveText = find('[data-bj-leave-text]');

    let version = null; // the table's version that's shown
    let revision = -1; // the room's revision in it, to tell older answers from newer ones
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
    let dealerStepAt = null; // Date.now() when the dealer's next step is due, so the next check is sent right then
    let celebratedRound = null; // the round whose results' effects have been shown (celebrate()), so they only go off once
    let guestToken = ''; // a guest's table and play chips, sealed by the server (none at first: a new table)
    let guestQueue = Promise.resolve();

    const formatChips = (amount) => amount.toLocaleString('en-US');
    const signed = (amount) => (amount > 0 ? '+' : '−') + formatChips(Math.abs(amount));
    const isInHand = () => IN_HAND_PHASES.includes(table.phase);

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
                location.assign('/casino/blackjack/'); // left in another tab, gone too long, or the table is gone
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
        dealerStepAt = typeof table.dealerStepIn === 'number' ? Date.now() + table.dealerStepIn : null;
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
        pollTimer = setTimeout(poll, nextPollIn());
    }

    // While the dealer plays, the next check goes just after their next step is due, so the hole card and every card
    // after it comes at the pace the server sets (slower when it's close), not whenever the next 1-second check is.
    function nextPollIn() {
        if (document.visibilityState !== 'visible') return HIDDEN_POLL_MS;
        if (table?.phase === 'dealer' && dealerStepAt !== null) {
            return Math.min(POLL_MS, Math.max(250, dealerStepAt - Date.now() + 80));
        }
        return POLL_MS;
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
        renderDealer();
        renderShoe();
        renderSeats();
        statusText.textContent = statusFor();
        renderControls();
        tickTimer();
        if (table.settled && table.roundId !== celebratedRound) {
            celebratedRound = table.roundId;
            if (!isFirstDraw) celebrate(); // not for a hand that was already over when the page opened
        }
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

    function renderDealer() {
        const dealer = table.dealer;
        renderCards(dealerCards, 'dealer', dealer?.cards ?? []);
        dealerTotal.textContent = dealer ? totalText(dealer.total, dealer.soft, dealer.blackjack) : '';
        // The dealer's next card is a close one (dealer on 16 against a 20): the table holds its breath (blackjack.css)
        tableElement.classList.toggle('is-tense', table.phase === 'dealer' && dealer?.tense === true);
        shuffledNote.hidden = !table.reshuffled || ['waiting', 'betting'].includes(table.phase);
        // Before the next hand is dealt, the last one stays on the table, faded
        tableElement.classList.toggle('is-between', ['waiting', 'betting'].includes(table.phase));
    }

    // How far through the shoe the table is, with a mark where it's shuffled. It's shuffled when the next hand is
    // dealt, so once the cards left are down to the mark, the next hand comes from a new shoe.
    function renderShoe() {
        const { left, size, reshuffleAt } = table.shoe;
        shoe.hidden = false;
        shoeBar.style.transform = 'scaleX(' + left / size + ')';
        shoeCut.style.left = (reshuffleAt / size) * 100 + '%';
        shoeText.textContent = formatChips(left) + ' cards left in the shoe. '
            + (left <= reshuffleAt ? 'It\'s shuffled before the next hand.' : 'It\'s shuffled at ' + reshuffleAt + '.');
    }

    // A card that wasn't there last time is dealt in, and one that was face down (the hole card) is turned over
    function renderCards(container, key, cards) {
        const before = shownCards[key] ?? [];
        container.replaceChildren(...cards.map((card, i) => {
            if (isFirstDraw || before[i] === card) return cardElement(card, null);
            return cardElement(card, before[i] === null && card !== null ? 'flip' : 'new');
        }));
        shownCards[key] = cards;
    }

    // A face-down card is null: the hole card isn't sent until it's turned over. animation is 'new' (dealt in),
    // 'flip' (turned over: the card's back is laid over its face and spins away, see .is-flip in blackjack.css) or null.
    function cardElement(card, animation) {
        const element = document.createElement('span');
        element.className = 'bj-card' + (animation ? ' is-' + animation : '');
        if (animation === 'flip') {
            const cover = document.createElement('span');
            cover.className = 'bj-card-cover';
            element.append(cover);
        }
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
        item.className = 'bj-seat is-empty';
        const text = document.createElement('p');
        text.className = 'bj-empty';
        text.textContent = 'Empty seat';
        item.append(text);
        return item;
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
        element.dataset.bjHand = seat.seat + '-' + h; // where celebrate() sets off a win's sparks
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

    // ---------- Win effects ----------
    //
    // When a hand is paid out, the winners get more the bigger it is:
    //   a win                every winning hand at the table throws off a few sparks (more for your own)
    //   a blackjack          yours: a "Blackjack!" banner over the table and a burst of confetti
    //   21 with 5+ cards     yours: a "Five-card 21!" banner, gold confetti and fireworks
    //   every split hand won yours: a "Both hands win!" (or "All 3 hands win!") banner and confetti from each hand
    // Only the biggest one's banner is shown. Sparks, confetti and fireworks are particles on one canvas over the page
    // (made here, so the guest table gets it too), drawn only while there are any. With reduced motion it's only the
    // banner, standing still.
    const IS_REDUCED_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const CONFETTI = ['#e8b931', '#ff4f6d', '#4fc3f7', '#81e36b', '#ff7ab8', '#b388ff', '#ffffff'];
    const GOLD = ['#ffd23f', '#ffe48a', '#e8b931', '#fff6cf'];
    const SPARKS = ['#ffd23f', '#ffffff', '#7dffb0'];
    const BANNER_MS = 2800;
    const MAX_PARTICLES = 500;
    const WIN_RESULTS = ['win', 'blackjack'];
    let fxCanvas = null;
    let fxContext = null;
    let particles = []; // { kind: spark|confetti|ember, x, y, vx, vy (px, px a second), gravity, drag, rotation, spin, size, color, age, life (s) }
    let fxFrame = 0;
    let fxLastTime = 0;
    let fxTimers = [];
    let banner = null;

    const randomBetween = (low, high) => low + Math.random() * (high - low);
    const pick = (list) => list[Math.floor(Math.random() * list.length)];

    function celebrate() {
        fxTimers.forEach(clearTimeout);
        fxTimers = [];
        banner?.remove();

        const youSeat = table.seats.find((seat) => seat.isYou && seat.player);
        table.seats.forEach((seat) => seat.player?.hands.forEach((hand, h) => {
            if (WIN_RESULTS.includes(hand.result)) sparksAt(handCenter(seat.seat, h), seat === youSeat ? 26 : 12);
        }));
        if (!youSeat) return;

        const hands = youSeat.player.hands;
        const won = hands.filter((hand) => WIN_RESULTS.includes(hand.result));
        const fiveCard = hands.findIndex((hand) => hand.result === 'win' && hand.total === 21 && hand.cards.length >= 5);
        const amount = youSeat.player.net > 0 ? '+' + formatChips(youSeat.player.net) + ' chips' : '';

        if (fiveCard !== -1) {
            showBanner('Five-card 21!', amount, 'is-five');
            const center = handCenter(youSeat.seat, fiveCard);
            confettiBurst(center, 120, GOLD);
            [0, 450, 900].forEach((delay, i) => later(() => fireworkAt(fireworkSpot(i)), delay));
        } else if (hands.length > 1 && won.length === hands.length) {
            showBanner(hands.length === 2 ? 'Both hands win!' : 'All ' + hands.length + ' hands win!', amount, 'is-sweep');
            hands.forEach((hand, h) => later(() => confettiBurst(handCenter(youSeat.seat, h), 70, CONFETTI), h * 250));
            later(() => fireworkAt(fireworkSpot(1)), 400);
        } else if (hands.some((hand) => hand.result === 'blackjack')) {
            showBanner('Blackjack!', amount, 'is-blackjack');
            confettiBurst(bannerCenter(), 160, CONFETTI);
        }
    }

    function later(callback, ms) {
        fxTimers.push(setTimeout(callback, ms));
    }

    // The banner across the middle of the table, gone again after BANNER_MS
    function showBanner(title, amount, className) {
        banner = document.createElement('div');
        banner.className = 'bj-banner ' + className;
        banner.setAttribute('aria-hidden', 'true'); // the status line already says what happened
        const titleText = document.createElement('span');
        titleText.className = 'bj-banner-title';
        titleText.textContent = title;
        banner.append(titleText);
        if (amount) {
            const amountText = document.createElement('span');
            amountText.className = 'bj-banner-amount';
            amountText.textContent = amount;
            banner.append(amountText);
        }
        tableElement.append(banner);
        const shown = banner;
        later(() => shown.classList.add('is-leaving'), BANNER_MS);
        later(() => shown.remove(), BANNER_MS + 400);
    }

    function centerOf(element) {
        const box = element.getBoundingClientRect();
        return { x: box.left + box.width / 2, y: box.top + box.height / 2 };
    }

    function handCenter(seatNumber, h) {
        const element = seatList.querySelector('[data-bj-hand="' + seatNumber + '-' + h + '"] .bj-cards');
        return element ? centerOf(element) : centerOf(tableElement);
    }

    const bannerCenter = () => centerOf(banner ?? tableElement);

    // Somewhere over the top half of the table, left, middle or right
    function fireworkSpot(i) {
        const box = tableElement.getBoundingClientRect();
        return { x: box.left + box.width * [0.25, 0.5, 0.75][i % 3] + randomBetween(-30, 30), y: box.top + box.height * randomBetween(0.15, 0.35) };
    }

    function sparksAt({ x, y }, count) {
        for (let i = 0; i < count; i++) {
            const angle = Math.random() * Math.PI * 2;
            const speed = randomBetween(90, 300);
            addParticle({
                kind: 'spark', x, y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed - 60,
                gravity: 260, drag: 1.8, size: randomBetween(3, 7), spin: randomBetween(-6, 6),
                color: pick(SPARKS), life: randomBetween(0.5, 1.1),
            });
        }
    }

    // Paper strips shot up and out from a point, then fluttering down
    function confettiBurst({ x, y }, count, colors) {
        for (let i = 0; i < count; i++) {
            addParticle({
                kind: 'confetti', x, y, vx: randomBetween(-480, 480), vy: randomBetween(-760, -220),
                gravity: 620, drag: 0.9, size: randomBetween(8, 13), spin: randomBetween(-8, 8), flip: randomBetween(6, 14),
                color: pick(colors), life: randomBetween(2.2, 3.4),
            });
        }
    }

    function fireworkAt({ x, y }) {
        const color = pick(GOLD.concat(CONFETTI));
        const count = 46;
        for (let i = 0; i < count; i++) {
            const angle = (i / count) * Math.PI * 2;
            const speed = randomBetween(160, 280);
            addParticle({
                kind: 'ember', x, y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed,
                gravity: 180, drag: 1.4, size: randomBetween(2, 3.5), color, life: randomBetween(0.9, 1.4),
            });
        }
    }

    function addParticle(particle) {
        if (IS_REDUCED_MOTION || particles.length >= MAX_PARTICLES) return;
        if (!fxCanvas) {
            fxCanvas = document.createElement('canvas');
            fxCanvas.className = 'bj-fx';
            fxCanvas.setAttribute('aria-hidden', 'true');
            root.append(fxCanvas);
            fxContext = fxCanvas.getContext('2d');
        }
        if (!fxFrame) {
            sizeCanvas();
            fxLastTime = performance.now();
            fxFrame = requestAnimationFrame(fxTick);
        }
        particles.push({ rotation: Math.random() * Math.PI * 2, spin: 0, age: 0, ...particle });
    }

    function sizeCanvas() {
        const ratio = window.devicePixelRatio || 1;
        fxCanvas.width = Math.round(window.innerWidth * ratio);
        fxCanvas.height = Math.round(window.innerHeight * ratio);
        fxContext.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    // Moves every particle on by the time since the last frame, draws the ones still going, and stops when they're gone
    function fxTick(now) {
        const seconds = Math.min(0.05, Math.max(0, now - fxLastTime) / 1000); // no big jump after the tab was hidden
        fxLastTime = now;
        fxContext.clearRect(0, 0, window.innerWidth, window.innerHeight);
        particles = particles.filter((particle) => {
            particle.age += seconds;
            if (particle.age >= particle.life || particle.y > window.innerHeight + 40) return false;
            const slow = Math.max(0, 1 - particle.drag * seconds);
            particle.vx *= slow;
            particle.vy = particle.vy * slow + particle.gravity * seconds;
            particle.x += particle.vx * seconds;
            particle.y += particle.vy * seconds;
            particle.rotation += particle.spin * seconds;
            drawParticle(particle, Math.min(1, (particle.life - particle.age) * 3)); // fades out in its last third of a second
            return true;
        });
        fxFrame = particles.length ? requestAnimationFrame(fxTick) : 0;
        if (!fxFrame) fxContext.clearRect(0, 0, window.innerWidth, window.innerHeight);
    }

    function drawParticle(particle, alpha) {
        const context = fxContext;
        const size = particle.size;
        context.save();
        context.globalAlpha = alpha;
        context.translate(particle.x, particle.y);
        context.fillStyle = particle.color;
        if (particle.kind === 'confetti') {
            // A strip of paper turning in the air
            context.rotate(particle.rotation);
            context.scale(1, Math.cos(particle.age * particle.flip));
            context.fillRect(-size / 2, -size / 4, size, size / 2);
        } else if (particle.kind === 'spark') {
            // A four-pointed twinkle
            const waist = size * 0.25;
            context.globalCompositeOperation = 'lighter';
            context.rotate(particle.rotation);
            context.beginPath();
            context.moveTo(0, -size);
            context.lineTo(waist, -waist);
            context.lineTo(size, 0);
            context.lineTo(waist, waist);
            context.lineTo(0, size);
            context.lineTo(-waist, waist);
            context.lineTo(-size, 0);
            context.lineTo(-waist, -waist);
            context.closePath();
            context.fill();
        } else {
            // A firework's ember
            context.globalCompositeOperation = 'lighter';
            context.beginPath();
            context.ellipse(0, 0, size, size, 0, 0, Math.PI * 2);
            context.fill();
        }
        context.restore();
    }

    function playerTag(seat) {
        const tag = document.createElement('div');
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

        const badges = [];
        if (seat.isHost && table.room.type !== 'solo') badges.push(['Host', 'is-host']);
        if (seat.left) badges.push(['Left', '']);
        else if (seat.away) badges.push(['Away', '']);
        else if (seat.sittingOut) badges.push(['Sitting out', '']);
        else if (isInHand() && !seat.player) badges.push(['Next hand', '']);
        for (const [text, className] of badges) {
            const badge = document.createElement('span');
            badge.className = 'bj-badge' + (className ? ' ' + className : '');
            badge.textContent = text;
            tag.append(badge);
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
                return 'The dealer could have blackjack. Insurance is offered.';
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

    // Whether the player can go all in: some chips, but fewer than the smallest bet
    const canGoAllIn = () => allInButton !== null && chips > 0 && chips < ALL_IN_BELOW;

    function renderControls() {
        const you = table.you;
        let panel = 'wait';
        if (you?.insurance) panel = 'insurance';
        else if (you?.moves.length > 0) panel = 'moves';
        else if (you?.canBet) panel = 'bet';
        else if (you?.nextBet !== null && you?.nextBet !== undefined) panel = 'placed';
        for (const [name, element] of Object.entries(panels)) element.hidden = name !== panel;

        // So a player in another tab can see it's their turn
        document.title = (panel === 'moves' || panel === 'insurance' ? 'Your turn - ' : '') + baseTitle;

        if (panel === 'bet') {
            const canAllIn = canGoAllIn();
            const isAllIn = canAllIn && pendingBet === chips;
            if (!isAllIn) pendingBet = Math.min(pendingBet, maxBet());
            pendingText.textContent = formatChips(pendingBet);
            chipButtons.forEach((button) => (button.disabled = isBusy || pendingBet + Number(button.dataset.bjChip) > maxBet()));
            if (allInButton) {
                allInButton.hidden = !canAllIn;
                allInButton.disabled = isBusy || isAllIn;
            }
            clearButton.disabled = isBusy || pendingBet === 0;
            halfButton.disabled = isBusy || pendingBet <= minBet;
            doubleButton.disabled = isBusy || pendingBet === 0 || pendingBet * 2 > maxBet();
            placeButton.disabled = isBusy || (!isAllIn && (pendingBet < minBet || pendingBet > maxBet()));
            placeButton.textContent = isAllIn ? 'Go all in' : table.phase === 'results' ? 'Bet on the next hand' : 'Place bet';
            brokeHint.hidden = maxBet() >= minBet || canAllIn;
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
        if (table.you?.sittingOut) return 'You\'re sitting out. Sit back in to play the next hand.';
        const isInThisHand = table.seats.some((seat) => seat.isYou && seat.player);
        if (isInHand() && !isInThisHand) return 'You\'re in from the next hand.';
        switch (table.phase) {
            case 'insurance':
                return 'Waiting for the others to decide on insurance.';
            case 'playing':
                return 'Waiting for ' + (turnSeat()?.name || 'the other players') + '.';
            case 'dealer':
                return 'The dealer is playing.';
            default:
                return '';
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
    allInButton?.addEventListener('click', () => {
        pendingBet = chips; // every chip there is (only shown with fewer than 10)
        renderControls();
    });
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
        if (panels.moves.hidden || leaveDialog.open || inviteDialog?.open) return;
        if (event.repeat || event.ctrlKey || event.metaKey || event.altKey) return;
        if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;
        const name = MOVE_KEYS[event.key.toLowerCase()];
        if (name) {
            event.preventDefault();
            move(name);
        }
    });

    sitOutButton?.addEventListener('click', () => act('sitOut', { out: table?.you?.sittingOut ? '0' : '1' }));

    // The join code: a click copies it, to send to others
    codeButton?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(codeButton.dataset.bjCode);
            codeCopied.textContent = 'Copied';
        } catch {
            codeCopied.textContent = ''; // no clipboard access: the code is right there to read
        }
        setTimeout(() => (codeCopied.textContent = ''), 2000);
    });

    // Inviting (only the host sees the button): find people with the chat's user search
    if (inviteDialog) {
        const search = inviteDialog.querySelector('[data-bj-invite-search]');
        const list = inviteDialog.querySelector('[data-bj-invite-list]');
        const status = inviteDialog.querySelector('[data-bj-invite-status]');
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
            item.className = 'bj-invite-user';
            const name = document.createElement('span');
            name.className = 'bj-player-name';
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
        inviteDialog.querySelector('[data-bj-invite-close]').addEventListener('click', () => inviteDialog.close());
    }

    // Leaving: asked first when there are chips on the table
    async function leave() {
        if (IS_GUEST) {
            location.assign('/'); // nothing to leave: the table is only in this page, so back to the frontpage
            return;
        }
        const data = await post('leave');
        if (data?.left) {
            location.assign('/casino/blackjack/');
        } else {
            showMessage('Could not leave the table. Please try again.');
        }
    }

    leaveButton.addEventListener('click', () => {
        const isPlaying = table !== null && isInHand() && table.seats.some((seat) => seat.isYou && seat.player);
        const hasBet = table?.you?.nextBet !== null && table?.you?.nextBet !== undefined;
        if (!isPlaying && !hasBet) {
            leave();
            return;
        }
        leaveText.textContent = IS_GUEST ? 'You\'re in the middle of a hand. Leaving ends it, and your play chips start over.'
            : isPlaying ? 'You\'re in the middle of a hand. Your hand will stand, and anything it wins still goes to your chips.'
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
