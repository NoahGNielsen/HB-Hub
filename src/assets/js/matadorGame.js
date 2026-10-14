// A Matador game (games/matador/game.php): draws the game as /games/matador/play says it is, and sends what the
// player does. Everything is decided on the server - the die, the cards, the rent, whose turn it is. This only shows it.
// The board's fields are in the page already (with their prices and rent in data-*); this puts the pieces, owners and
// houses on them.
//
// Asks what's new every second while the page is in view, and every 4 seconds while it isn't (so the game doesn't count
// the player as away, which takes 10 seconds without hearing from them). The whole game only comes back when its
// version changed: something happened, someone wrote in the chat, or someone joined, left, went away or came back.
//
// A ping is played when the player's turn starts, unless they turned the sound off (remembered in this browser).
//
// The turn's timer counts down here, from the time left the server sent (the server's and the player's clocks don't
// have to agree). The server passes the turn on once it runs out; this only shows it.
(() => {
    const root = document.querySelector('[data-matador]');
    if (!root) return;

    const API = '/games/matador/play';
    const POLL_MS = 1000;
    const HIDDEN_POLL_MS = 4000;
    const STEP_MS = 140; // a piece moving one field
    const MAX_STEPPED = 12; // further than this (a card, jail) the piece just jumps
    const FIELD_COUNT = 40;
    const TURN_MS = Number(root.dataset.turnSeconds) * 1000;
    const TIMER_LOW_SECONDS = 10; // the timer turns red from here
    const roomId = root.dataset.room;
    const baseTitle = document.title;
    const DIE_FACES = ['⚀', '⚁', '⚂', '⚃', '⚄', '⚅'];
    const KINDS = { street: 'Title deed', ferry: 'Ferry', brewery: 'Brewery' };
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const find = (selector) => root.querySelector(selector);
    const message = find('[data-mt-message]');
    const actions = find('[data-mt-actions]');
    const playerList = find('[data-mt-players]');
    const bankText = find('[data-mt-bank]');
    const tradesPanel = find('[data-mt-trades-panel]');
    const tradeList = find('[data-mt-trades]');
    const tradesEmpty = find('[data-mt-trades-empty]');
    const tradeOpenButton = find('[data-mt-trade-open]');
    const logList = find('[data-mt-log]');
    const die = find('[data-mt-die]');
    const statusText = find('[data-mt-status]');
    const timer = find('[data-mt-timer]');
    const timerFill = find('[data-mt-timer-fill]');
    const timerText = find('[data-mt-timer-text]');
    const card = find('[data-mt-card]');
    const cardText = find('[data-mt-card-text]');
    const cardWho = find('[data-mt-card-who]');
    const playerCount = find('[data-mt-player-count]');
    const roundText = find('[data-mt-round]');
    const savedText = find('[data-mt-saved]');
    const codeButton = find('[data-mt-code]');
    const codeCopied = find('[data-mt-code-copied]');
    const inviteButton = find('[data-mt-invite]');
    const saveStopButton = find('[data-mt-save-stop]');
    const leaveButton = find('[data-mt-leave]');
    const soundButton = find('[data-mt-sound]');
    const chatList = find('[data-mt-chat]');
    const chatEmpty = find('[data-mt-chat-empty]');
    const chatMessage = find('[data-mt-chat-message]');
    const chatForm = find('[data-mt-chat-form]');
    const chatText = find('[data-mt-chat-text]');

    // The board's fields, by number, with what the page says about them
    const FIELDS = [...root.querySelectorAll('[data-field]')]
        .sort((a, b) => Number(a.dataset.field) - Number(b.dataset.field))
        .map((element) => ({
            element,
            name: element.dataset.name,
            type: element.dataset.type,
            group: element.dataset.group ?? null,
            price: Number(element.dataset.price ?? 0),
            house: Number(element.dataset.house ?? 0),
            rent: element.dataset.rent ? element.dataset.rent.split(',').map(Number) : [],
            houses: element.querySelector('[data-mt-houses]'),
            tokens: element.querySelector('[data-mt-tokens]'),
        }));
    const GROUP_FIELDS = {};
    FIELDS.forEach((field, number) => {
        if (field.group !== null) (GROUP_FIELDS[field.group] ??= []).push(number);
    });

    let version = null; // the game's version that's shown
    let revision = -1; // the room's revision in it, to tell older answers from newer ones
    let game = null;
    let isBusy = false; // something the player did is on its way
    let isPolling = false;
    let pollTimer = null;
    let isFirstDraw = true; // nothing is animated when the page opens
    let shownRoll = null; // the last roll and card shown (their n), so only new ones are animated
    let shownCard = null;
    const shownAt = {}; // the field each piece is drawn on, by seat, while it walks to where it is
    const walkers = {}; // the timers of pieces that are walking
    let deedField = null; // the property whose card is open
    let pingedTurn = null; // the turn of the player's that's been pinged for (round:seat), null when it isn't theirs
    let shownChat = null; // the newest chat message drawn (its id)
    let shownChatCount = 0; // the newest chat message seen in an answer, so older answers don't take it away again
    let turnEndsAt = null; // when the turn's time runs out, on performance.now()'s clock
    let hasAskedAtTurnEnd = false; // the server has been asked about it since it ran out

    const kr = (amount) => amount.toLocaleString('en-US') + ' kr';
    const playerOf = (seat) => game.players.find((player) => player.seat === seat) ?? null;
    const nameOf = (seat) => playerOf(seat)?.name ?? 'Someone';
    const me = () => (game.you !== null ? playerOf(game.you) : null);
    const isMyTurn = () => game.phase === 'playing' && game.turn?.seat === game.you;
    const isPlaying = () => game.phase === 'playing';

    async function request(options, query = '') {
        try {
            const response = await fetch(API + query, { headers: { Accept: 'application/json' }, ...options });
            const data = await response.json().catch(() => null);
            if (response.status === 404 && data?.error === 'stopped') {
                location.assign('/games/matador/?stopped=1'); // the host saved and stopped the game
                return null;
            }
            if (response.status === 404 && data?.error === 'notSeated') {
                location.assign('/games/matador/'); // left in another tab, or gone too long
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

    // A newer answer may already be shown (one from a click can overtake the check before it), so older ones are dropped.
    // The version starts with "revision-newest chat message-", which both only go up.
    function apply(data) {
        if (data.same) return;
        const [dataRevision, dataChat] = data.v.split('-').map((part) => parseInt(part, 10));
        if (dataRevision < revision || (dataRevision === revision && dataChat < shownChatCount)) return;
        version = data.v;
        revision = dataRevision;
        shownChatCount = dataChat;
        game = data.game;
        turnEndsAt = game.timeLeft !== null ? performance.now() + game.timeLeft : null;
        hasAskedAtTurnEnd = false;
        render();
    }

    async function poll() {
        if (isPolling) return;
        isPolling = true;
        clearTimeout(pollTimer);

        const query = new URLSearchParams({ room: roomId });
        if (version !== null) query.set('v', version);
        const data = await request({}, '?' + query);
        if (data !== null) apply(data);

        isPolling = false;
        pollTimer = setTimeout(poll, document.visibilityState === 'visible' ? POLL_MS : HIDDEN_POLL_MS);
    }

    function post(action, fields = {}) {
        return request({ method: 'POST', body: new URLSearchParams({ room: roomId, action, ...fields }) });
    }

    // Does something in the game. Its error (if any) is shown in `where`, the moves panel unless it's a dialog's.
    async function act(action, fields = {}, where = message) {
        if (isBusy) return null;
        isBusy = true;
        where.textContent = '';
        render();

        const data = await post(action, fields);
        isBusy = false;
        if (data === null) {
            where.textContent = 'Something went wrong. Please try again.';
        } else {
            if (data.error) where.textContent = data.error;
            apply(data);
        }
        render();
        return data;
    }

    // A move in the player's turn carries the turn id, so a double click only counts once
    const turnAct = (action, fields = {}) => act(action, { turn: String(game.turn.id), ...fields });

    function render() {
        if (game === null) return;
        renderHeader();
        renderBoard();
        renderCenter();
        renderPlayers();
        renderActions();
        renderTrades();
        renderLog();
        renderChat();
        if (deedField !== null) renderDeed();
        document.title = (isMyTurn() ? '● Your turn - ' : '') + baseTitle;

        // Pinged once when the turn starts (not again after a 6), and not for a turn that was already going on when the page opened
        const myTurn = isMyTurn() ? game.round + ':' + game.turn.seat : null;
        if (myTurn !== null && myTurn !== pingedTurn && !isFirstDraw) ping();
        pingedTurn = myTurn;
        isFirstDraw = false;
    }

    function renderHeader() {
        playerCount.textContent = String(game.room.players);
        roundText.textContent = isPlaying() || game.phase === 'resume' ? ' · Round ' + game.round : '';
        if (game.unsaved) {
            savedText.textContent = ' · Not being saved anymore';
        } else if (game.phase === 'playing' || game.phase === 'resume') {
            savedText.textContent = ' · Saved for ' + (game.room.isOwner ? 'you' : (game.room.owner ?? 'its owner'));
        } else {
            savedText.textContent = '';
        }
        inviteButton.hidden = !(game.isHost && game.phase === 'lobby');
        saveStopButton.hidden = !(game.isHost && (isPlaying() || game.phase === 'resume')) || game.unsaved;
    }

    // ----- The board

    function renderBoard() {
        const owners = ['mt-owner-1', 'mt-owner-2', 'mt-owner-3', 'mt-owner-4'];
        FIELDS.forEach((field, number) => {
            const property = game.fields[number];
            field.element.classList.remove(...owners);
            field.element.classList.toggle('is-mortgaged', Boolean(property?.mortgaged));
            if (property?.owner) field.element.classList.add('mt-owner-' + property.owner);

            const buildings = [];
            if (property?.houses === 5) {
                buildings.push(building('is-hotel'));
            } else {
                for (let i = 0; i < (property?.houses ?? 0); i++) buildings.push(building('is-house'));
            }
            field.houses.replaceChildren(...buildings);
            field.element.setAttribute('aria-label', fieldLabel(number));
        });
        placePieces();
    }

    function building(kind) {
        const element = document.createElement('span');
        element.className = 'mt-building ' + kind;
        return element;
    }

    function fieldLabel(number) {
        const field = FIELDS[number];
        const property = game.fields[number];
        const parts = [field.name];
        if (property) {
            parts.push(property.owner ? 'owned by ' + nameOf(property.owner) : 'for sale, ' + kr(field.price));
            if (property.houses === 5) parts.push('a hotel');
            else if (property.houses > 0) parts.push(property.houses + (property.houses === 1 ? ' house' : ' houses'));
            if (property.mortgaged) parts.push('mortgaged');
        }
        const here = game.players.filter((player) => !player.bankrupt && game.phase !== 'lobby' && player.position === number);
        if (here.length > 0) parts.push('here: ' + here.map((player) => player.name).join(', '));
        return parts.join(', ');
    }

    // Each piece walks field by field to where it is now, unless it jumped (a card, jail) or it's the first drawing
    function placePieces() {
        const pieces = game.phase === 'lobby' ? [] : game.players.filter((player) => !player.bankrupt);
        for (const player of pieces) {
            const from = shownAt[player.seat];
            const steps = from === undefined ? 0 : (player.position - from + FIELD_COUNT) % FIELD_COUNT;
            if (from === undefined || isFirstDraw || reduceMotion || steps > MAX_STEPPED) {
                clearInterval(walkers[player.seat]);
                delete walkers[player.seat];
                shownAt[player.seat] = player.position;
            } else if (steps > 0 && !walkers[player.seat]) {
                walkers[player.seat] = setInterval(() => walk(player.seat), STEP_MS);
            }
        }
        drawPieces(pieces);
    }

    function walk(seat) {
        const player = game.players.find((other) => other.seat === seat);
        if (!player || shownAt[seat] === player.position) {
            clearInterval(walkers[seat]);
            delete walkers[seat];
            return;
        }
        shownAt[seat] = (shownAt[seat] + 1) % FIELD_COUNT;
        drawPieces(game.players.filter((other) => !other.bankrupt));
    }

    function drawPieces(pieces) {
        FIELDS.forEach((field) => field.tokens.replaceChildren());
        for (const player of pieces) {
            const piece = document.createElement('span');
            piece.className = 'mt-token mt-seat-' + player.seat + (player.seat === game.turn?.seat ? ' is-turn' : '') + (player.here ? '' : ' is-empty');
            piece.title = player.name;
            FIELDS[shownAt[player.seat] ?? player.position].tokens.append(piece);
        }
    }

    function renderCenter() {
        const roll = game.lastRoll;
        die.hidden = game.phase === 'lobby';
        if (roll && roll.n !== shownRoll) {
            die.textContent = DIE_FACES[roll.value - 1];
            die.setAttribute('aria-label', nameOf(roll.seat) + ' rolled a ' + roll.value);
            if (!isFirstDraw && !reduceMotion) {
                die.classList.remove('is-rolling');
                void die.offsetWidth; // so the animation starts again
                die.classList.add('is-rolling');
            }
            shownRoll = roll.n;
        }

        statusText.textContent = statusFor();
        renderTimer();

        const drawn = game.lastCard;
        card.hidden = !drawn || game.phase === 'lobby';
        if (drawn && drawn.n !== shownCard) {
            cardText.textContent = drawn.text;
            cardWho.textContent = 'Drawn by ' + nameOf(drawn.seat);
            if (!isFirstDraw && !reduceMotion) {
                card.classList.remove('is-new');
                void card.offsetWidth;
                card.classList.add('is-new');
            }
            shownCard = drawn.n;
        }
    }

    function statusFor() {
        switch (game.phase) {
            case 'lobby':
                return 'Waiting for players (' + game.players.length + ' of 4)';
            case 'resume':
                return 'Waiting for the players to come back';
            case 'ended':
                return game.winner !== null ? nameOf(game.winner) + ' won the game!' : 'The game is over';
            default:
                return isMyTurn() ? 'Your turn' : nameOf(game.turn.seat) + '\'s turn';
        }
    }

    // The bar empties as the time runs out. Not read out (it changes every second): the moves panel says what to do.
    // Once it's out, the server is asked straight away, so the turn is passed on without waiting for the next check.
    // While the player whose turn it is owes money, the timer is paused (the server sends no time left).
    function renderTimer() {
        const isPaused = game !== null && isPlaying() && game.pending?.type === 'debt';
        timer.hidden = game === null || !isPlaying() || (turnEndsAt === null && !isPaused);
        if (timer.hidden) return;
        timer.classList.toggle('is-paused', isPaused);
        if (isPaused) {
            timer.classList.remove('is-low');
            timerFill.style.transform = 'scaleX(1)';
            timerText.textContent = 'Paused until ' + (isMyTurn() ? 'you pay' : nameOf(game.turn.seat) + ' pays');
            return;
        }
        const left = Math.max(0, turnEndsAt - performance.now());
        const seconds = Math.ceil(left / 1000);
        timer.classList.toggle('is-low', seconds <= TIMER_LOW_SECONDS);
        timerFill.style.transform = 'scaleX(' + Math.min(1, left / TURN_MS) + ')';
        timerText.textContent = seconds + ' s' + (isMyTurn() ? ' left of your turn' : '');
        if (left === 0 && !hasAskedAtTurnEnd) {
            hasAskedAtTurnEnd = true;
            poll();
        }
    }
    setInterval(() => {
        if (game !== null) renderTimer();
    }, 250);

    // ----- The players

    // Built with textContent, as names are typed in by users
    function renderPlayers() {
        playerList.replaceChildren(...game.players.map(playerItem));
        const bank = game.bank;
        bankText.textContent = bank ? 'The bank has ' + bank.houses + ' houses and ' + bank.hotels + ' hotels left.' : '';
    }

    function playerItem(player) {
        const item = document.createElement('li');
        item.className = 'mt-player';
        item.classList.toggle('is-turn', isPlaying() && game.turn.seat === player.seat);
        item.classList.toggle('is-you', player.isYou);
        item.classList.toggle('is-out', player.bankrupt || (!player.here && game.phase !== 'lobby'));

        const dot = document.createElement('span');
        dot.className = 'mt-token mt-seat-' + player.seat;
        dot.setAttribute('aria-hidden', 'true');

        const text = document.createElement('div');
        text.className = 'mt-player-text';
        const name = document.createElement('span');
        name.className = 'mt-player-name';
        name.textContent = player.name + (player.isYou ? ' (you)' : '');
        if (player.isHost) {
            const badge = document.createElement('span');
            badge.className = 'mt-badge';
            badge.textContent = 'Host';
            name.append(' ', badge);
        }
        const status = document.createElement('span');
        status.className = 'mt-player-status';
        status.textContent = playerStatus(player);
        text.append(name, status);

        const money = document.createElement('div');
        money.className = 'mt-player-money';
        if (game.phase !== 'lobby' && !player.bankrupt) {
            const cash = document.createElement('strong');
            cash.textContent = kr(player.cash);
            const worth = document.createElement('span');
            worth.textContent = 'worth ' + kr(player.worth);
            money.append(cash, worth);
        }

        item.append(dot, text, money);

        // The host can give an empty seat to someone else, who takes it over
        const canGive = game.isHost && !player.here && !player.bankrupt && (isPlaying() || game.phase === 'resume');
        if (canGive) {
            const give = document.createElement('button');
            give.type = 'button';
            give.className = 'casino-btn mt-small-btn';
            give.textContent = player.reservedFor ? 'Give to someone else' : 'Give seat';
            give.addEventListener('click', () => openInvite({ kind: 'giveSeat', seat: player.seat, name: player.name }));
            item.append(give);
        }
        return item;
    }

    function playerStatus(player) {
        const parts = [];
        if (player.bankrupt) return 'Bankrupt';
        if (!player.here && game.phase !== 'lobby') {
            parts.push(player.reservedFor ? 'Empty seat, given to ' + player.reservedFor : 'Empty seat, kept for them');
        } else if (player.away) {
            parts.push('Away');
        }
        if (game.phase !== 'lobby') {
            const owned = game.fields.filter((property) => property?.owner === player.seat).length;
            parts.push(owned + (owned === 1 ? ' property' : ' properties'));
            if (player.inJail) parts.push('In jail');
            if (player.cards > 0) parts.push(player.cards + ' "benådet" card' + (player.cards === 1 ? '' : 's'));
        }
        return parts.join(' · ');
    }

    // ----- What the player can do

    function button(text, onClick, { primary = false, disabled = false } = {}) {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = 'casino-btn' + (primary ? ' casino-btn-primary' : '');
        element.textContent = text;
        element.disabled = disabled || isBusy;
        element.addEventListener('click', onClick);
        return element;
    }

    function paragraph(text, className = 'mt-action-text') {
        const element = document.createElement('p');
        element.className = className;
        element.textContent = text;
        return element;
    }

    function buttons(...list) {
        const row = document.createElement('div');
        row.className = 'mt-action-buttons';
        row.append(...list);
        return row;
    }

    function renderActions() {
        actions.replaceChildren(...actionContent());
    }

    function actionContent() {
        const you = me();
        if (game.phase === 'lobby') {
            if (!game.isHost) return [paragraph('Waiting for the host to start the game.')];
            const enough = game.players.length >= 2;
            return [
                paragraph(enough ? 'Start when everyone is in. Up to 4 players.' : 'Invite people, or give them the code. 2 to 4 players.'),
                buttons(button('Start the game', () => act('start'), { primary: true, disabled: !enough })),
            ];
        }
        if (game.phase === 'resume') {
            if (!game.isHost) return [paragraph('Waiting for the host to go on with the game.')];
            const here = game.players.filter((player) => player.here && !player.bankrupt).length;
            return [
                paragraph('The others have been invited back to their seats. Give a seat to someone else from the list of players if they can\'t come. Empty seats are skipped until someone sits there.'),
                buttons(button('Go on with the game', () => act('start'), { primary: true, disabled: here < 2 })),
            ];
        }
        if (game.phase === 'ended') {
            return [
                paragraph(game.winner === game.you ? 'You won! Congratulations.' : nameOf(game.winner) + ' won the game.'),
                buttons(button('Back to Matador', leave, { primary: true })),
            ];
        }
        if (you?.bankrupt) {
            return [paragraph('You\'re bankrupt and out of the game. You can stay and watch, or leave.')];
        }
        return isMyTurn() ? myTurn(you) : othersTurn();
    }

    function myTurn(you) {
        const pending = game.pending;
        if (pending?.type === 'buy') {
            const field = FIELDS[pending.field];
            const content = [paragraph('Buy ' + field.name + ' for ' + kr(field.price) + '?')];
            if (you.cash < field.price) {
                content.push(paragraph('You have ' + kr(you.cash) + '. Mortgage or sell something first (click your properties on the board) to afford it.', 'mt-hint'));
            }
            content.push(buttons(
                button('Buy it', () => turnAct('buy'), { primary: true, disabled: you.cash < field.price }),
                button('Leave it with the bank', () => turnAct('decline')),
                button('See the card', () => openDeed(pending.field)),
            ));
            return content;
        }
        if (pending?.type === 'tax') {
            return [
                paragraph('Indkomstskat: pay ' + kr(pending.fixed) + ', or 10 % of everything you\'re worth (cash, properties and buildings)?'),
                buttons(
                    button('Pay ' + kr(pending.fixed), () => turnAct('tax', { choice: 'fixed' }), { primary: true }),
                    button('Pay 10 %', () => turnAct('tax', { choice: 'percent' })),
                ),
            ];
        }
        if (pending?.type === 'debt') {
            const to = pending.to !== null ? nameOf(pending.to) : 'the bank';
            return [
                paragraph('You owe ' + kr(pending.amount) + ' (' + pending.reason + '), and you have ' + kr(you.cash) + '.'),
                paragraph('Raise the money: click your properties on the board to sell houses or mortgage them, or offer a trade.', 'mt-hint'),
                buttons(
                    button('Pay ' + kr(pending.amount), () => turnAct('payDebt'), { primary: true, disabled: you.cash < pending.amount }),
                    button('Go bankrupt', () => confirmBankruptcy(to)),
                ),
            ];
        }
        if (game.turn.step === 'roll') {
            if (you.inJail) {
                return [
                    paragraph('You\'re in jail. Roll a 6 to get out (try ' + (you.jailTries + 1) + ' of 3), or pay to get out first.'),
                    buttons(
                        button('Roll', () => turnAct('roll'), { primary: true }),
                        button('Pay 1,000 kr', () => turnAct('payBail'), { disabled: you.cash < 1000 }),
                        ...(you.cards > 0 ? [button('Use your "benådet" card', () => turnAct('useCard'))] : []),
                    ),
                ];
            }
            const again = game.turn.next === 'roll';
            return [
                paragraph(again ? 'You rolled a 6. Roll again!' : 'Your turn. Roll the die.'),
                buttons(button(again ? 'Roll again' : 'Roll', () => turnAct('roll'), { primary: true })),
            ];
        }
        return [
            paragraph('Build, mortgage or trade if you like, then end your turn.', 'mt-hint'),
            buttons(button('End turn', () => turnAct('endTurn'), { primary: true })),
        ];
    }

    function othersTurn() {
        const name = nameOf(game.turn.seat);
        const pending = game.pending;
        const text = pending?.type === 'buy' ? name + ' is deciding whether to buy ' + FIELDS[pending.field].name + '.'
            : pending?.type === 'tax' ? name + ' is choosing how to pay Indkomstskat.'
            : pending?.type === 'debt' ? name + ' owes ' + kr(pending.amount) + ' and is raising the money.'
            : game.turn.step === 'roll' ? name + ' is about to roll.'
            : name + ' can build or trade before ending their turn.';
        return [paragraph(text), paragraph('You can build, mortgage and trade at any time: click your properties on the board.', 'mt-hint')];
    }

    async function confirmBankruptcy(to) {
        const ok = await confirmDialog('Go bankrupt?', 'Everything you have goes to ' + to + ', and you\'re out of the game.', 'Go bankrupt');
        if (ok) turnAct('bankrupt');
    }

    // ----- Trades

    function renderTrades() {
        tradesPanel.hidden = !isPlaying();
        if (!isPlaying()) return;
        const you = me();
        const hasOffer = game.trades.some((trade) => trade.from === game.you);
        tradeOpenButton.disabled = isBusy || !you || you.bankrupt || hasOffer || tradePartners().length === 0;
        tradeOpenButton.title = hasOffer ? 'Take back your offer first' : '';
        tradeList.replaceChildren(...game.trades.map(tradeItem));
        tradesEmpty.hidden = game.trades.length > 0;
    }

    function tradePartners() {
        return game.players.filter((player) => !player.bankrupt && player.here && !player.isYou);
    }

    function sideText(side) {
        const parts = side.fields.map((number) => FIELDS[number].name + (game.fields[number]?.mortgaged ? ' (mortgaged)' : ''));
        if (side.cash > 0) parts.push(kr(side.cash));
        if (side.cards > 0) parts.push(side.cards + ' "benådet" card' + (side.cards === 1 ? '' : 's'));
        return parts.length > 0 ? parts.join(', ') : 'nothing';
    }

    function tradeItem(trade) {
        const item = document.createElement('li');
        item.className = 'mt-trade-item';
        if (trade.to === game.you) {
            item.append(
                paragraph(nameOf(trade.from) + ' offers you ' + sideText(trade.give) + ', for ' + sideText(trade.get) + '.'),
                buttons(
                    button('Accept', () => act('answer', { trade: String(trade.id), answer: 'accept' }), { primary: true }),
                    button('Decline', () => act('answer', { trade: String(trade.id), answer: 'decline' })),
                ),
            );
        } else if (trade.from === game.you) {
            item.append(
                paragraph('You offered ' + nameOf(trade.to) + ' ' + sideText(trade.give) + ', for ' + sideText(trade.get) + '.'),
                buttons(button('Take it back', () => act('answer', { trade: String(trade.id), answer: 'cancel' }))),
            );
        } else {
            item.append(paragraph(nameOf(trade.from) + ' offered ' + nameOf(trade.to) + ' ' + sideText(trade.give) + ', for ' + sideText(trade.get) + '.'));
        }
        return item;
    }

    const tradeDialog = find('[data-mt-trade-dialog]');
    const tradeWith = find('[data-mt-trade-with]');
    const tradeGive = find('[data-mt-trade-give]');
    const tradeGet = find('[data-mt-trade-get]');
    const tradeGiveCash = find('[data-mt-trade-give-cash]');
    const tradeGetCash = find('[data-mt-trade-get-cash]');
    const tradeGiveCards = find('[data-mt-trade-give-cards]');
    const tradeGetCards = find('[data-mt-trade-get-cards]');
    const tradeMessage = find('[data-mt-trade-message]');

    // A property can be traded when nothing is built anywhere in its colour
    function tradable(seat) {
        return game.fields
            .map((property, number) => ({ property, number }))
            .filter(({ property, number }) => property?.owner === seat
                && GROUP_FIELDS[FIELDS[number].group].every((other) => (game.fields[other]?.houses ?? 0) === 0));
    }

    function fieldChoices(container, seat) {
        const list = tradable(seat);
        if (list.length === 0) {
            container.replaceChildren(paragraph('No properties that can be traded.', 'mt-hint'));
            return;
        }
        container.replaceChildren(...list.map(({ property, number }) => {
            const label = document.createElement('label');
            label.className = 'casino-choice';
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.value = String(number);
            const text = document.createElement('span');
            text.textContent = FIELDS[number].name + (property.mortgaged ? ' (mortgaged)' : '');
            label.append(box, text);
            return label;
        }));
    }

    function fillTradeSides() {
        const partner = playerOf(Number(tradeWith.value));
        const you = me();
        fieldChoices(tradeGive, game.you);
        fieldChoices(tradeGet, partner?.seat ?? -1);
        tradeGiveCash.max = String(you.cash);
        tradeGetCash.max = String(partner?.cash ?? 0);
        tradeGiveCards.max = String(you.cards);
        tradeGetCards.max = String(partner?.cards ?? 0);
        find('[data-mt-trade-give-cards-row]').hidden = you.cards === 0;
        find('[data-mt-trade-get-cards-row]').hidden = (partner?.cards ?? 0) === 0;
        [tradeGiveCash, tradeGetCash, tradeGiveCards, tradeGetCards].forEach((input) => (input.value = '0'));
    }

    tradeOpenButton.addEventListener('click', () => {
        const partners = tradePartners();
        tradeWith.replaceChildren(...partners.map((player) => {
            const option = document.createElement('option');
            option.value = String(player.seat);
            option.textContent = player.name;
            return option;
        }));
        tradeMessage.textContent = '';
        fillTradeSides();
        tradeDialog.showModal();
    });
    tradeWith.addEventListener('change', fillTradeSides);
    find('[data-mt-trade-cancel]').addEventListener('click', () => tradeDialog.close());

    const checked = (container) => [...container.querySelectorAll('input:checked')].map((box) => box.value).join(',');
    const whole = (input) => String(Math.max(0, Math.floor(Number(input.value) || 0)));

    find('[data-mt-trade-send]').addEventListener('click', async () => {
        const data = await act('offer', {
            to: tradeWith.value,
            giveFields: checked(tradeGive),
            giveCash: whole(tradeGiveCash),
            giveCards: whole(tradeGiveCards),
            getFields: checked(tradeGet),
            getCash: whole(tradeGetCash),
            getCards: whole(tradeGetCards),
        }, tradeMessage);
        if (data && !data.error) tradeDialog.close();
    });

    // ----- The log

    function renderLog() {
        logList.replaceChildren(...[...game.log].reverse().map((entry) => {
            const item = document.createElement('li');
            item.textContent = entry.text;
            return item;
        }));
    }

    // ----- The chat

    const timeFormat = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });

    // Only drawn again when there's a new message, so it doesn't jump while it's being read. It stays at the bottom when
    // it was there already (or it's the player's own message), otherwise where they scrolled to.
    function renderChat() {
        const messages = game.chat;
        const newest = messages.at(-1)?.id ?? 0;
        chatEmpty.hidden = messages.length > 0;
        if (newest === shownChat) return;

        const atBottom = chatList.scrollHeight - chatList.scrollTop - chatList.clientHeight < 40;
        chatList.replaceChildren(...messages.map(chatItem));
        if (atBottom || shownChat === null || messages.at(-1)?.isYou) chatList.scrollTop = chatList.scrollHeight;
        shownChat = newest;
    }

    // Built with textContent, as it's all typed in by users
    function chatItem(entry) {
        const item = document.createElement('li');
        item.className = 'mt-chat-item';

        const head = document.createElement('div');
        head.className = 'mt-chat-head';
        if (entry.seat !== null) {
            const dot = document.createElement('span');
            dot.className = 'mt-token mt-seat-' + entry.seat;
            dot.setAttribute('aria-hidden', 'true');
            head.append(dot);
        }
        const name = document.createElement('span');
        name.className = 'mt-chat-name';
        name.textContent = entry.name + (entry.isYou ? ' (you)' : '');
        const time = document.createElement('time');
        time.className = 'mt-chat-time';
        time.dateTime = entry.time;
        time.textContent = timeFormat.format(new Date(entry.time));
        head.append(name, time);

        const text = document.createElement('p');
        text.className = 'mt-chat-text';
        text.textContent = entry.text;

        item.append(head, text);
        return item;
    }

    let isSending = false;
    chatForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const text = chatText.value.trim();
        if (text === '' || isSending) return;
        isSending = true;
        chatMessage.textContent = '';

        const data = await post('chat', { text });
        isSending = false;
        if (data === null) {
            chatMessage.textContent = 'Could not send it. Please try again.';
            return;
        }
        if (data.error) {
            chatMessage.textContent = data.error;
        } else {
            chatText.value = '';
        }
        apply(data);
    });

    // ----- The ping when it's the player's turn, made here rather than loaded. Browsers only let a page play sound once
    // it's been clicked or typed in, so the first click on the page wakes it up.

    const SOUND_KEY = 'hbHubMatadorSound';
    let isSoundOn = true;
    try {
        isSoundOn = localStorage.getItem(SOUND_KEY) !== 'off';
    } catch {
        // no storage (a private window): the sound is just on
    }
    let audio = null;

    function wakeAudio() {
        try {
            audio ??= new AudioContext();
            if (audio.state === 'suspended') audio.resume();
        } catch {
            audio = null; // no Web Audio: no ping
        }
    }

    // Two short notes, a fifth apart
    function ping() {
        if (!isSoundOn) return;
        wakeAudio();
        if (audio === null) return;
        const now = audio.currentTime;
        [[880, 0], [1320, 0.13]].forEach(([frequency, after]) => {
            const tone = audio.createOscillator();
            const volume = audio.createGain();
            tone.type = 'sine';
            tone.frequency.value = frequency;
            volume.gain.setValueAtTime(0.0001, now + after);
            volume.gain.exponentialRampToValueAtTime(0.3, now + after + 0.01);
            volume.gain.exponentialRampToValueAtTime(0.0001, now + after + 0.4);
            tone.connect(volume).connect(audio.destination);
            tone.start(now + after);
            tone.stop(now + after + 0.45);
        });
    }

    // aria-pressed also picks the icon (matador.css)
    function renderSound() {
        soundButton.setAttribute('aria-pressed', String(isSoundOn));
        soundButton.title = isSoundOn ? 'Sound on: a ping when it\'s your turn' : 'Sound off';
    }

    soundButton.addEventListener('click', () => {
        isSoundOn = !isSoundOn;
        try {
            localStorage.setItem(SOUND_KEY, isSoundOn ? 'on' : 'off');
        } catch {
            // only kept until the page is closed
        }
        renderSound();
        ping(); // so they hear what it sounds like
    });
    renderSound();
    document.addEventListener('pointerdown', wakeAudio, { once: true });
    document.addEventListener('keydown', wakeAudio, { once: true });

    // ----- A property's card: what it costs and takes, and building and mortgaging it for its owner

    const deedDialog = find('[data-mt-deed]');
    const deedHead = find('[data-mt-deed-head]');
    const deedKind = find('[data-mt-deed-kind]');
    const deedTitle = find('[data-mt-deed-title]');
    const deedTable = find('[data-mt-deed-table]');
    const deedOwner = find('[data-mt-deed-owner]');
    const deedMessage = find('[data-mt-deed-message]');
    const deedActions = find('[data-mt-deed-actions]');

    function openDeed(number) {
        if (FIELDS[number].price === 0 || game === null) return; // only properties have a card
        deedField = number;
        deedMessage.textContent = '';
        renderDeed();
        if (!deedDialog.open) deedDialog.showModal();
    }

    function deedRows(field) {
        const mortgage = field.price / 2;
        if (field.type === 'street') {
            return [
                ['Rent', kr(field.rent[0])],
                ['With every street in the colour', kr(field.rent[0] * 2)],
                ['With 1 house', kr(field.rent[1])],
                ['With 2 houses', kr(field.rent[2])],
                ['With 3 houses', kr(field.rent[3])],
                ['With 4 houses', kr(field.rent[4])],
                ['With a hotel', kr(field.rent[5])],
                ['A house costs', kr(field.house)],
                ['A hotel costs', kr(field.house) + ' and 4 houses'],
                ['Mortgage value', kr(mortgage)],
            ];
        }
        if (field.type === 'ferry') {
            return [
                ['Rent with 1 ferry', kr(500)],
                ['With 2 ferries', kr(1000)],
                ['With 3 ferries', kr(2000)],
                ['With 4 ferries', kr(4000)],
                ['Mortgage value', kr(mortgage)],
            ];
        }
        return [
            ['Rent with 1 brewery', '100 × the roll'],
            ['With both breweries', '200 × the roll'],
            ['Mortgage value', kr(mortgage)],
        ];
    }

    function renderDeed() {
        const field = FIELDS[deedField];
        const property = game.fields[deedField];
        deedHead.className = 'mt-deed-head' + (field.type === 'street' ? ' mt-group-' + field.group : '');
        deedKind.textContent = KINDS[field.type] ?? '';
        deedTitle.textContent = field.name;
        deedTable.replaceChildren(...[['Price', kr(field.price)], ...deedRows(field)].map(([label, value]) => {
            const row = document.createElement('tr');
            const head = document.createElement('th');
            head.scope = 'row';
            head.textContent = label;
            const cell = document.createElement('td');
            cell.textContent = value;
            row.append(head, cell);
            return row;
        }));

        const owner = property?.owner ?? null;
        const parts = [owner !== null ? 'Owned by ' + nameOf(owner) : 'The bank owns it'];
        if (property?.houses === 5) parts.push('a hotel');
        else if (property?.houses > 0) parts.push(property.houses + (property.houses === 1 ? ' house' : ' houses'));
        if (property?.mortgaged) parts.push('mortgaged');
        deedOwner.textContent = parts.join(' · ');

        const list = [];
        if (owner !== null && owner === game.you && isPlaying() && !me()?.bankrupt) {
            const houses = property.houses;
            const groupBuilt = GROUP_FIELDS[field.group].some((other) => (game.fields[other]?.houses ?? 0) > 0);
            if (field.type === 'street') {
                const ownsAll = GROUP_FIELDS[field.group].every((other) => game.fields[other]?.owner === game.you);
                if (houses < 5) {
                    list.push(deedButton(houses === 4 ? 'Build a hotel (' + kr(field.house) + ')' : 'Build a house (' + kr(field.house) + ')', 'build', !ownsAll));
                }
                if (houses > 0) {
                    list.push(deedButton(houses === 5 ? 'Sell the hotel' : 'Sell a house (+' + kr(field.house / 2) + ')', 'sell'));
                }
            }
            if (property.mortgaged) {
                list.push(deedButton('Pay back the mortgage (' + kr(field.price / 2 + field.price / 20) + ')', 'unmortgage'));
            } else {
                list.push(deedButton('Mortgage it (+' + kr(field.price / 2) + ')', 'mortgage', groupBuilt));
            }
        }
        deedActions.replaceChildren(...list);
        deedActions.hidden = list.length === 0;
    }

    function deedButton(text, action, disabled = false) {
        return button(text, () => act(action, { field: String(deedField) }, deedMessage), { disabled });
    }

    FIELDS.forEach((field, number) => field.element.addEventListener('click', () => openDeed(number)));
    find('[data-mt-deed-close]').addEventListener('click', () => deedDialog.close());
    deedDialog.addEventListener('close', () => (deedField = null));

    // ----- Confirming

    const confirmBox = find('[data-mt-confirm]');
    function confirmDialog(title, text, okText) {
        find('[data-mt-confirm-title]').textContent = title;
        find('[data-mt-confirm-text]').textContent = text;
        find('[data-mt-confirm-ok]').textContent = okText;
        confirmBox.showModal();
        find('[data-mt-confirm-cancel]').focus(); // the safe choice is the one Enter picks
        return new Promise((resolve) => {
            const done = (answer) => {
                confirmBox.removeEventListener('close', onClose);
                resolve(answer);
            };
            const onClose = () => done(confirmBox.returnValue === 'ok');
            confirmBox.returnValue = '';
            confirmBox.addEventListener('close', onClose);
        });
    }
    find('[data-mt-confirm-cancel]').addEventListener('click', () => confirmBox.close('cancel'));
    find('[data-mt-confirm-ok]').addEventListener('click', () => confirmBox.close('ok'));

    // ----- Inviting, and giving an empty seat to someone: find people with the chat's user search

    const inviteDialog = find('[data-mt-invite-dialog]');
    const inviteSearch = find('[data-mt-invite-search]');
    const inviteList = find('[data-mt-invite-list]');
    const inviteStatus = find('[data-mt-invite-status]');
    let inviteMode = { kind: 'invite' };
    let searchTimer = null;
    let searchNumber = 0;

    function openInvite(mode) {
        inviteMode = mode;
        if (mode.kind === 'giveSeat') {
            find('[data-mt-invite-title]').textContent = 'Give ' + mode.name + '\'s seat to someone';
            find('[data-mt-invite-text]').textContent = 'They take over the seat with its money, properties and cards. Until they join, '
                + mode.name + ' can still come back to it.';
        } else {
            find('[data-mt-invite-title]').textContent = 'Invite people';
            find('[data-mt-invite-text]').textContent = (game.room.type === 'invite' ? 'Only people you invite can join this game. ' : '')
                + 'They get a notification, and join from the Matador page.';
        }
        inviteSearch.value = '';
        inviteStatus.textContent = '';
        inviteDialog.showModal();
        inviteSearch.focus();
        loadUsers();
    }

    async function loadUsers() {
        const number = ++searchNumber;
        let data = null;
        try {
            const response = await fetch('/chat/users?q=' + encodeURIComponent(inviteSearch.value.trim()), { headers: { Accept: 'application/json' } });
            data = response.ok ? await response.json() : null;
        } catch {
            data = null;
        }
        if (number !== searchNumber) return; // a newer search is on its way
        if (data === null) {
            inviteStatus.textContent = 'Could not search. Please try again.';
            return;
        }
        inviteList.replaceChildren(...data.users.map(userItem));
        inviteStatus.textContent = data.users.length === 0 ? 'Nobody found.' : '';
    }

    function userItem(user) {
        const item = document.createElement('li');
        item.className = 'mt-invite-user';
        const name = document.createElement('span');
        name.textContent = user.userName;
        const isIn = game.players.some((player) => player.here && player.name === user.userName);
        const choose = document.createElement('button');
        choose.type = 'button';
        choose.className = 'casino-btn mt-small-btn';
        choose.textContent = isIn ? 'In the game' : (inviteMode.kind === 'giveSeat' ? 'Give the seat' : 'Invite');
        choose.disabled = isIn;
        choose.addEventListener('click', () => invite(user, choose));
        item.append(name, choose);
        return item;
    }

    async function invite(user, choose) {
        choose.disabled = true;
        const data = inviteMode.kind === 'giveSeat'
            ? await post('giveSeat', { seat: String(inviteMode.seat), user: String(user.userId) })
            : await post('invite', { user: String(user.userId) });
        if (data === null) {
            inviteStatus.textContent = 'Something went wrong. Please try again.';
            choose.disabled = false;
            return;
        }
        apply(data);
        if (data.error) {
            inviteStatus.textContent = data.error;
            choose.disabled = false;
        } else if (inviteMode.kind === 'giveSeat') {
            inviteDialog.close();
            message.textContent = user.userName + ' can take the seat from their invites on the Matador page.';
        } else {
            inviteStatus.textContent = user.userName + ' is invited.';
            choose.textContent = 'Invited';
        }
    }

    inviteButton.addEventListener('click', () => openInvite({ kind: 'invite' }));
    inviteSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadUsers, 200);
    });
    find('[data-mt-invite-close]').addEventListener('click', () => inviteDialog.close());

    // ----- The join code: a click copies it, to send to others

    codeButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(codeButton.dataset.mtCode);
            codeCopied.textContent = 'Copied';
        } catch {
            codeCopied.textContent = ''; // no clipboard access: the code is right there to read
        }
        setTimeout(() => (codeCopied.textContent = ''), 2000);
    });

    // ----- Leaving, and saving and stopping (the host)

    async function leave() {
        const data = await post('leave');
        if (data?.left) {
            location.assign('/games/matador/');
        } else {
            message.textContent = 'Could not leave the game. Please try again.';
        }
    }

    leaveButton.addEventListener('click', async () => {
        const you = game ? me() : null;
        const keepsSeat = game !== null && (isPlaying() || game.phase === 'resume') && you && !you.bankrupt;
        if (keepsSeat) {
            const ok = await confirmDialog('Leave the game?',
                'Your seat is kept for you, and your turns are skipped while you\'re gone. You can come back from your invites on the Matador page.',
                'Leave');
            if (!ok) return;
        }
        leave();
    });

    saveStopButton.addEventListener('click', async () => {
        const owner = game.room.isOwner ? 'your' : (game.room.owner ?? 'its owner') + '\'s';
        const ok = await confirmDialog('Save and stop the game?',
            'The game is saved in ' + owner + ' save slot and stops for everyone. It can be continued later from the Matador page.',
            'Save & stop');
        if (ok) act('saveStop');
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') poll(); // catch up when coming back to the tab
    });
    poll();
})();
