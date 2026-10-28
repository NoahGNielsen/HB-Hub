// An UNO game (games/uno/game.php): draws the game as /games/uno/play says it is, and sends what the player does.
// Everything is decided on the server - which cards can be played, what they do, the turn timer. The server sends the
// player's own cards and which of them can be played right now (game.game.playable), and only how many cards everyone
// else has, so this only has to show them. A card is played by clicking it; a wild card asks for the colour first.
// When the player has other cards with the same number or symbol, the click picks it instead: they click the others
// they want to play with it (any colour), in the order they want them, and press Play. The last one decides the colour.
//
// Asks what's new every second while the page is in view, and every 4 seconds while it isn't (so the game doesn't count
// the player as away, which takes 10 seconds without hearing from them). The whole game only comes back when its
// version changed: a move, someone wrote in the chat, or someone joined, left, went away or came back.
//
// The turn timer counts down here, from the time the server sent (game.game.turn.timeLeft). The server plays the turn
// for the player once it runs out; this only shows it, and asks straight away when it does.
//
// UNO: on their turn with two cards, the player can press UNO! before playing (it's sent with the card), or press it
// after, until someone catches them (game.game.uno is the seat that can be caught). Everyone else gets a Catch button
// while someone can be caught.
//
// The game's settings (game.settings) are shown in their dialog, where the host changes them while no game is being
// played. A ping is played when it becomes the player's turn, and for the host when someone joins, unless they turned
// the sound off (remembered in this browser).
(() => {
    const root = document.querySelector('[data-uno]');
    if (!root) return;

    const API = '/games/uno/play';
    const POLL_MS = 1000;
    const HIDDEN_POLL_MS = 4000;
    const TICK_MS = 100;
    const TIMER_LOW_MS = 5000; // the timer turns red from here
    const COLOUR_NAMES = { r: 'red', y: 'yellow', g: 'green', b: 'blue' };
    const COLOUR_ORDER = ['r', 'y', 'g', 'b'];
    const VALUE_ORDER = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'S', 'R', 'D', 'W', 'F'];
    const SYMBOLS = { S: '⊘', R: '⇄', D: '+2', W: '', F: '+4' }; // what's on a card that isn't a number
    const roomId = root.dataset.room;
    const baseTitle = document.title;

    const find = (selector) => root.querySelector(selector);
    const statusText = find('[data-uno-status]');
    const message = find('[data-uno-message]');
    const actions = find('[data-uno-actions]');
    const playerList = find('[data-uno-players]');
    const table = find('[data-uno-table]');
    const direction = find('[data-uno-direction]');
    const deckButton = find('[data-uno-deck]');
    const deckCount = find('[data-uno-deck-count]');
    const discard = find('[data-uno-discard]');
    const tableText = find('[data-uno-table-text]');
    const timer = find('[data-uno-timer]');
    const timerBar = find('[data-uno-timer-bar]');
    const handBar = find('[data-uno-hand-bar]');
    const handTitle = find('[data-uno-hand-title]');
    const hand = find('[data-uno-hand]');
    const keepButton = find('[data-uno-keep]');
    const pickPlayButton = find('[data-uno-pick-play]');
    const pickCancelButton = find('[data-uno-pick-cancel]');
    const callButton = find('[data-uno-call]');
    const logList = find('[data-uno-log]');
    const logEmpty = find('[data-uno-log-empty]');
    const codeButton = find('[data-uno-code]');
    const codeCopied = find('[data-uno-code-copied]');
    const settingsName = find('[data-uno-settings-name]');
    const inviteButton = find('[data-uno-invite]');
    const settingsButton = find('[data-uno-settings]');
    const pauseButton = find('[data-uno-pause]');
    const soundButton = find('[data-uno-sound]');
    const leaveButton = find('[data-uno-leave]');
    const chatList = find('[data-uno-chat]');
    const chatEmpty = find('[data-uno-chat-empty]');
    const chatMessage = find('[data-uno-chat-message]');
    const chatForm = find('[data-uno-chat-form]');
    const chatText = find('[data-uno-chat-text]');

    let version = null; // the game's version that's shown
    let revision = -1; // the room's revision in it, to tell older answers from newer ones
    let shownChatCount = 0; // the newest chat message seen in an answer, so older answers don't take it away again
    let game = null;
    let isBusy = false; // something the player did is on its way
    let isPolling = false;
    let pollTimer = null;
    let isFirstDraw = true; // no ping or animation when the page opens
    let timerAt = 0; // when the time left in the turn was sent, on performance.now()'s clock
    let hasAskedAtZero = false; // the server has been asked since the turn ran out
    let pingedTurn = null; // the turn of the player's that's been pinged for, null when it isn't theirs
    let seatedCount = null; // how many sat in the room when it was last drawn, to ping the host when someone joins
    let shownLast = null; // the last thing that happened that's been shown (game.game.last.n)
    let shownLog = null; // the newest log line drawn
    let shownChat = null; // the newest chat message drawn (its id)
    let unoArmed = false; // the player pressed UNO! before playing their second-to-last card
    let armedTurn = null; // the turn they pressed it in
    let picked = []; // the cards picked to play together, as places in the sorted hand, in the order they were clicked
    let pickedIn = null; // the turn and hand they were picked in: when either changes, picking starts over

    const state = () => game?.game ?? null;
    const isPlaying = () => game !== null && game.phase === 'playing' && state() !== null;
    const isPaused = () => isPlaying() && state().paused;
    const isMyTurn = () => isPlaying() && !isPaused() && state().you !== null && state().turn.seat === state().you;
    const playerAt = (seat) => state()?.players.find((player) => player.seat === seat) ?? null;
    const nameAt = (seat) => {
        const player = playerAt(seat);
        return player === null ? 'Someone' : player.isYou ? 'You' : player.name;
    };
    const isWild = (card) => card === 'W' || card === 'F';
    const valueOf = (card) => (isWild(card) ? card : card.slice(1));

    // "red 7", "green Skip", "Wild Draw Four" - like the server's hbHubUnoCardName()
    function cardName(card) {
        const value = valueOf(card);
        if (value === 'W') return 'Wild';
        if (value === 'F') return 'Wild Draw Four';
        return COLOUR_NAMES[card[0]] + ' ' + ({ S: 'Skip', R: 'Reverse', D: 'Draw Two' }[value] ?? value);
    }

    async function request(options, query = '') {
        try {
            const response = await fetch(API + query, { headers: { Accept: 'application/json' }, ...options });
            const data = await response.json().catch(() => null);
            if (response.status === 404 && data?.error === 'notSeated') {
                location.assign('/games/uno/'); // left in another tab, or gone too long
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

    // A newer answer may already be shown (one from a move can overtake the check before it), so older ones are dropped.
    // The version starts with "revision-newest chat message-", which both only go up.
    function apply(data) {
        if (data.same) return;
        const [dataRevision, dataChat] = data.v.split('-').map((part) => parseInt(part, 10));
        if (dataRevision < revision || (dataRevision === revision && dataChat < shownChatCount)) return;
        version = data.v;
        revision = dataRevision;
        shownChatCount = dataChat;
        game = data.game;
        timerAt = performance.now();
        hasAskedAtZero = false;
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
        if (data !== null && !isBusy) apply(data); // a move on its way comes back with its own answer

        isPolling = false;
        pollTimer = setTimeout(poll, document.visibilityState === 'visible' ? POLL_MS : HIDDEN_POLL_MS);
    }

    function post(action, fields = {}) {
        return request({ method: 'POST', body: new URLSearchParams({ room: roomId, action, ...fields }) });
    }

    // Does something in the game. Its error (if any) is shown in the game panel, or in shownIn (a dialog's message).
    async function act(action, fields = {}, shownIn = message) {
        if (isBusy) return null;
        isBusy = true;
        shownIn.textContent = '';
        render();

        const data = await post(action, fields);
        isBusy = false;
        if (data === null) {
            shownIn.textContent = 'Something went wrong. Please try again.';
            version = null; // so the next check gets the whole game
        } else {
            if (data.error) shownIn.textContent = data.error;
            apply(data);
        }
        render();
        return data;
    }

    function render() {
        if (game === null) return;
        renderHeader();
        renderPlayers();
        renderTable();
        renderHand();
        renderStatus();
        renderLog();
        renderChat();
        if (settingsDialog.open) renderSettings();
        document.title = (isMyTurn() ? '● Your turn - ' : '') + baseTitle;

        const myTurn = isMyTurn() ? state().turn.id : null;
        if (myTurn !== null && pingedTurn === null && !isFirstDraw) ping();
        const hasJoined = game.isHost && game.phase !== 'playing' && seatedCount !== null && game.seated.length > seatedCount;
        if (hasJoined && !isFirstDraw) ping();
        pingedTurn = myTurn !== null ? (pingedTurn ?? myTurn) : null; // a card drawn in the turn is the same turn
        seatedCount = game.seated.length;
        isFirstDraw = false;
    }

    // aria-pressed also picks the pause button's icon (uno.css)
    function renderHeader() {
        settingsName.textContent = game.settingsText;
        inviteButton.hidden = !(game.isHost && game.seated.length < game.maxPlayers);
        pauseButton.hidden = !(game.isHost && isPlaying());
        pauseButton.disabled = isBusy;
        pauseButton.setAttribute('aria-pressed', String(isPaused()));
        pauseButton.title = isPaused() ? 'Paused: go on with the game' : 'Pause the game';
    }

    // ----- A card: its colour (data-colour, "w" for the wild cards) and what's on it, in the middle and two corners

    function cardElement(card, tag = 'span') {
        const element = document.createElement(tag);
        const value = valueOf(card);
        const text = SYMBOLS[value] ?? value;
        element.className = 'uno-card' + (isWild(card) ? ' is-wild' : '') + (value === '6' || value === '9' ? ' is-underlined' : '');
        element.dataset.colour = isWild(card) ? 'w' : card[0];
        element.dataset.value = value;

        const centre = document.createElement('span');
        centre.className = 'uno-card-centre';
        centre.setAttribute('aria-hidden', 'true');
        centre.textContent = text;
        const top = document.createElement('span');
        top.className = 'uno-card-corner is-top';
        top.setAttribute('aria-hidden', 'true');
        top.textContent = text || 'W';
        const bottom = top.cloneNode(true);
        bottom.className = 'uno-card-corner is-bottom';
        element.append(top, centre, bottom);
        if (tag === 'span') {
            element.setAttribute('role', 'img');
            element.setAttribute('aria-label', cardName(card));
        }
        return element;
    }

    // ----- Everyone at the table, in the order of play: picture, name, how many cards, and UNO!

    function renderPlayers() {
        const current = state();
        const people = game.phase !== 'lobby' && current !== null
            ? current.players
            : game.seated.map((person) => ({ ...person, cards: null, out: false, saidUno: false }));
        // In a game that's over, people who joined since are at the table too
        const newcomers = game.phase === 'ended' && current !== null
            ? game.seated.filter((person) => !current.players.some((player) => player.seat === person.seat && player.isYou === person.isYou && player.name === person.name))
                .map((person) => ({ ...person, cards: null, out: false, saidUno: false, isNew: true }))
            : [];

        playerList.replaceChildren(...[...people, ...newcomers].map((player) => {
            const item = document.createElement('li');
            const isTurn = isPlaying() && current.turn.seat === player.seat && !player.out && !player.isNew;
            const isWinner = game.phase === 'ended' && current?.result?.winner === player.seat && !player.isNew;
            item.className = 'uno-player' + (isTurn ? ' is-turn' : '') + (player.isYou ? ' is-you' : '')
                + (player.out || !player.here ? ' is-gone' : '') + (isWinner ? ' is-winner' : '');

            const avatar = document.createElement('span');
            avatar.className = 'uno-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            if (player.avatar) {
                const image = document.createElement('img');
                image.src = player.avatar;
                image.alt = '';
                avatar.append(image);
            } else {
                avatar.textContent = player.name.slice(0, 1).toUpperCase();
            }

            const text = document.createElement('span');
            text.className = 'uno-player-text';
            const name = document.createElement('span');
            name.className = 'uno-player-name';
            name.textContent = player.name + (player.isYou ? ' (you)' : '');
            const status = document.createElement('span');
            status.className = 'uno-player-status';
            const notes = [];
            if (player.isHost) notes.push('Host');
            if (player.out) notes.push('Left the game');
            else if (!player.here) notes.push('Left');
            else if (player.away) notes.push('Away');
            if (isWinner) notes.push('Won!');
            if (player.isNew) notes.push('Joined');
            status.textContent = notes.join(' · ');
            text.append(name, status);
            item.append(avatar, text);

            if (player.cards !== null && !player.out) {
                const count = document.createElement('span');
                count.className = 'uno-player-cards';
                count.setAttribute('aria-label', player.cards === 1 ? '1 card' : player.cards + ' cards');
                const back = document.createElement('span');
                back.className = 'uno-card is-back is-mini';
                back.setAttribute('aria-hidden', 'true');
                const number = document.createElement('span');
                number.setAttribute('aria-hidden', 'true');
                number.textContent = String(player.cards);
                count.append(back, number);
                item.append(count);
            }
            if (isPlaying() && player.saidUno && !player.out) {
                const badge = document.createElement('span');
                badge.className = 'uno-badge';
                badge.textContent = 'UNO!';
                item.append(badge);
            }
            if (isPlaying() && current.uno === player.seat && current.you !== null && current.you !== player.seat) {
                item.classList.add('is-catchable');
                item.append(button('Catch!', () => act('catch'), { primary: true, small: true }));
            }
            return item;
        }));
    }

    // ----- The table: the draw pile, the top card, the colour to play on and which way play goes

    function renderTable() {
        const current = state();
        table.hidden = current === null;
        if (current === null) {
            discard.replaceChildren();
            return;
        }
        table.dataset.colour = current.colour;
        direction.classList.toggle('is-reversed', current.direction === -1);
        direction.hidden = game.phase !== 'playing';

        const canDraw = isMyTurn() && !current.turn.hasDrawn && !isBusy;
        deckButton.disabled = !canDraw;
        deckButton.classList.toggle('is-ready', canDraw && current.playable.length === 0);
        deckButton.setAttribute('aria-label', canDraw
            ? (current.penalty > 0 ? 'Draw ' + current.penalty + ' cards' : 'Draw a card') + ', ' + current.deck + ' left'
            : 'Draw pile, ' + current.deck + ' cards');
        deckCount.textContent = String(current.deck);

        // The top card, falling onto the pile when it's just been played
        const last = current.last;
        const isNew = last !== null && last.n !== shownLast && !isFirstDraw;
        if (discard.dataset.card !== current.top || isNew) {
            const top = cardElement(current.top);
            top.classList.add('is-top');
            if (isWild(current.top)) top.dataset.chosen = current.colour; // the colour picked shows on the wild card
            if (isNew && last.type === 'play') top.classList.add('is-new');
            discard.replaceChildren(top);
            discard.dataset.card = current.top;
        }
        if (isNew && (last.type === 'uno' || last.type === 'catch')) shout(last);
        shownLast = last?.n ?? null;

        tableText.textContent = tableLine();
        tick();
    }

    // What's going on, under the piles
    function tableLine() {
        const current = state();
        if (game.phase === 'ended') return '';
        if (isPaused()) return 'Paused';
        const colour = 'Play on ' + COLOUR_NAMES[current.colour];
        if (current.penalty > 0) {
            return (isMyTurn() ? 'Stack or draw ' : nameAt(current.turn.seat) + ' has to stack or draw ') + current.penalty;
        }
        return (isMyTurn() ? 'Your turn' : nameAt(current.turn.seat) + '\'s turn') + ' · ' + colour;
    }

    // A big "UNO!" or "Caught!" over the table for a moment
    function shout(last) {
        const text = document.createElement('span');
        text.className = 'uno-shout' + (last.type === 'catch' ? ' is-catch' : '');
        text.setAttribute('aria-hidden', 'true');
        text.textContent = last.type === 'catch' ? 'Caught! +' + last.count : 'UNO!';
        table.append(text);
        text.addEventListener('animationend', () => text.remove());
        setTimeout(() => text.remove(), 2000); // without animations (reduced motion)
    }

    // ----- The turn timer: a bar that runs down, counted down here from the time the server sent

    function timeLeft() {
        const turn = state()?.turn;
        if (!turn || turn.timeLeft === null) return null;
        return Math.max(0, turn.timeLeft - (isPaused() ? 0 : performance.now() - timerAt));
    }

    function tick() {
        const left = isPlaying() ? timeLeft() : null;
        timer.hidden = left === null;
        if (left === null) return;
        const total = state().turn.seconds * 1000;
        timerBar.style.width = Math.min(100, (left / total) * 100) + '%';
        timer.classList.toggle('is-low', left < TIMER_LOW_MS && !isPaused());
        timer.classList.toggle('is-mine', isMyTurn());
        timer.setAttribute('aria-label', Math.ceil(left / 1000) + ' seconds left in the turn');
        if (left === 0 && !isPaused() && !hasAskedAtZero) {
            hasAskedAtZero = true;
            poll(); // the server plays the turn for them
        }
    }
    setInterval(tick, TICK_MS);

    // ----- The player's cards, sorted by colour and then what's on them. The ones that can be played now light up.

    function sortCards(cards) {
        const rank = (card) => (isWild(card) ? 4 : COLOUR_ORDER.indexOf(card[0])) * 100 + VALUE_ORDER.indexOf(valueOf(card));
        return [...cards].sort((a, b) => rank(a) - rank(b));
    }

    function renderHand() {
        const current = state();
        const cards = current !== null && current.you !== null && game.phase === 'playing' ? sortCards(current.hand) : [];
        const playable = isMyTurn() && !isBusy ? current.playable : [];
        const drawn = isMyTurn() ? current.turn.drawn : null;
        let isDrawnMarked = false;

        const pickingIn = isMyTurn() && drawn === null ? current.turn.id + ':' + cards.join(',') : null;
        if (pickingIn !== pickedIn) picked = [];
        pickedIn = pickingIn;
        const pickedValue = picked.length > 0 ? valueOf(cards[picked[0]]) : null;

        handBar.hidden = hand.hidden = game.phase !== 'playing' || current?.you == null;
        handTitle.textContent = 'Your cards (' + cards.length + ')';
        hand.classList.toggle('is-my-turn', isMyTurn());
        hand.style.setProperty('--uno-hand-count', String(cards.length));
        hand.replaceChildren(...cards.map((card, index) => {
            const element = cardElement(card, 'button');
            element.type = 'button';
            const canPlay = playable.includes(card);
            const canPick = !isBusy && pickedValue !== null && !isWild(card) && valueOf(card) === pickedValue;
            const pickedAt = picked.indexOf(index);
            element.classList.toggle('is-playable', canPlay || canPick);
            element.disabled = !canPlay && !canPick;
            if (card === drawn && !isDrawnMarked) {
                isDrawnMarked = true; // the same card can be there twice: only one of them was drawn
                element.classList.add('is-drawn');
            }
            if (pickedAt !== -1) {
                element.classList.add('is-picked');
                const number = document.createElement('span');
                number.className = 'uno-pick-number';
                number.setAttribute('aria-hidden', 'true');
                number.textContent = String(pickedAt + 1);
                element.append(number);
            }
            element.setAttribute('aria-label', cardName(card)
                + (pickedAt !== -1 ? ', picked ' + (pickedAt + 1) + ', unpick it' : canPick ? ', pick it too' : canPlay ? ', play it' : '')
                + (element.classList.contains('is-drawn') ? ', just drawn' : ''));
            element.addEventListener('click', () => choose(index));
            return element;
        }));

        keepButton.hidden = drawn === null;
        keepButton.disabled = isBusy;
        pickPlayButton.hidden = pickCancelButton.hidden = picked.length === 0;
        pickPlayButton.disabled = pickCancelButton.disabled = isBusy;
        pickPlayButton.textContent = picked.length > 1 ? 'Play ' + picked.length + ' cards' : 'Play it';

        // UNO!: before playing the second-to-last card (pressed in, and sent with the card), or after it, until caught.
        // With cards picked, it's when they'd leave one card.
        if (armedTurn !== (isMyTurn() ? current.turn.id : null)) unoArmed = false;
        const canArm = isMyTurn() && playable.length > 0 && cards.length - Math.max(1, picked.length) === 1;
        const canCall = isPlaying() && current.uno !== null && current.uno === current.you;
        callButton.hidden = !canArm && !canCall;
        callButton.disabled = isBusy;
        callButton.classList.toggle('is-urgent', canCall);
        callButton.setAttribute('aria-pressed', String(canArm && unoArmed));
        callButton.title = canCall ? 'Call UNO before someone catches you!'
            : unoArmed ? 'You\'ll call UNO with your next card' : 'Call UNO with your next card';
    }

    // A click on the card at that place in the sorted hand. It's played straight away when it's the only one of its
    // number or symbol (or a wild card, or the card just drawn). Otherwise it's picked, and the other cards like it can
    // be picked after it. A picked card is unpicked by clicking it again - the first one unpicks them all, as the
    // others may not go on the top card on their own.
    function choose(index) {
        const current = state();
        if (!isMyTurn() || isBusy) return;
        const cards = sortCards(current.hand);
        const card = cards[index];
        const at = picked.indexOf(index);
        if (at !== -1) {
            picked = at === 0 ? [] : picked.filter((place) => place !== index);
        } else if (picked.length > 0 && !isWild(card) && valueOf(card) === valueOf(cards[picked[0]])) {
            picked.push(index);
        } else if (current.playable.includes(card)) {
            const hasMore = !isWild(card) && current.turn.drawn === null
                && cards.some((other, place) => place !== index && !isWild(other) && valueOf(other) === valueOf(card));
            if (!hasMore) {
                picked = [];
                play([card]);
                return;
            }
            picked = [index];
        }
        renderHand();
        renderStatus();
    }

    // Plays the cards (one, or several picked together, the last on top)
    async function play(list) {
        const current = state();
        if (!isMyTurn() || isBusy || !current.playable.includes(list[0])) return;
        const fields = { card: list.join(','), turn: String(current.turn.id) };
        if (isWild(list[0])) {
            const colour = await chooseColour();
            if (colour === null) return;
            fields.colour = colour;
        }
        if (unoArmed && current.hand.length - list.length === 1) fields.uno = '1';
        unoArmed = false;
        picked = [];
        act('play', fields);
    }

    pickPlayButton.addEventListener('click', () => {
        const cards = sortCards(state()?.hand ?? []);
        if (picked.length > 0) play(picked.map((place) => cards[place]));
    });
    pickCancelButton.addEventListener('click', () => {
        picked = [];
        renderHand();
        renderStatus();
    });

    deckButton.addEventListener('click', () => {
        if (isMyTurn() && !state().turn.hasDrawn) act('draw', { turn: String(state().turn.id) });
    });
    keepButton.addEventListener('click', () => {
        if (isMyTurn()) act('keep', { turn: String(state().turn.id) });
    });
    callButton.addEventListener('click', () => {
        const current = state();
        if (current === null) return;
        if (current.uno !== null && current.uno === current.you) {
            act('uno');
            return;
        }
        unoArmed = !unoArmed;
        armedTurn = current.turn.id;
        renderHand();
    });

    // ----- Picking the colour for a wild card

    const colourDialog = find('[data-uno-colours]');

    function chooseColour() {
        colourDialog.returnValue = '';
        colourDialog.showModal();
        return new Promise((resolve) => {
            colourDialog.addEventListener('close', () => {
                const colour = colourDialog.returnValue;
                resolve(COLOUR_ORDER.includes(colour) ? colour : null);
            }, { once: true });
        });
    }
    root.querySelectorAll('[data-uno-colour-choice]').forEach((choice) => {
        choice.addEventListener('click', () => colourDialog.close(choice.dataset.unoColourChoice));
    });
    find('[data-uno-colours-cancel]').addEventListener('click', () => colourDialog.close(''));

    // ----- What's going on, and what the player can do

    function button(text, onClick, { primary = false, disabled = false, small = false } = {}) {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = 'casino-btn' + (primary ? ' casino-btn-primary' : '') + (small ? ' uno-small-btn' : '');
        element.textContent = text;
        element.disabled = disabled || isBusy;
        element.addEventListener('click', onClick);
        return element;
    }

    function row(...list) {
        const element = document.createElement('div');
        element.className = 'uno-action-buttons';
        element.append(...list);
        return element;
    }

    function paragraph(text) {
        const element = document.createElement('p');
        element.className = 'uno-action-text';
        element.textContent = text;
        return element;
    }

    function renderStatus() {
        const [status, list] = statusAndActions();
        statusText.textContent = status;
        actions.replaceChildren(...list);
    }

    function statusAndActions() {
        const current = state();
        const isHost = game.isHost;
        const code = codeButton.dataset.unoCode;
        const host = game.seated.find((person) => person.isHost) ?? null;
        const hostButtons = (startText) => (isHost ? [
            row(
                button(startText, () => act('start'), { primary: true, disabled: game.seated.length < game.minPlayers }),
                ...(game.seated.length < game.maxPlayers ? [button('Invite someone', openInvite)] : []),
                button('Game settings', openSettings),
            ),
        ] : []);
        const joinText = game.seated.length < game.maxPlayers
            ? paragraph('Give people the code ' + code + (game.room.type === 'public' ? ', or they can find the game on the UNO page.' : '.'))
            : paragraph('The game is full.');

        if (game.phase === 'lobby') {
            const count = game.seated.length;
            const lines = [];
            if (game.note) lines.push(game.note);
            lines.push(count < game.minPlayers ? 'Waiting for someone to join.'
                : isHost ? count + ' players are in. Start the game when everyone\'s here.'
                : 'Waiting for ' + (host?.name ?? 'the host') + ' to start the game.');
            return [lines.join(' '), [joinText, paragraph('Settings: ' + game.settingsText + '.'), ...hostButtons('Start the game')]];
        }

        if (game.phase === 'ended') {
            const lines = [resultText(current.result)];
            if (game.note) lines.push(game.note);
            const list = [scores()];
            if (game.seated.length < game.minPlayers) {
                list.push(paragraph('Waiting for someone to join for a new game.'), joinText);
            } else if (!isHost) {
                list.push(paragraph('Waiting for ' + (host?.name ?? 'the host') + ' to start a new game.'));
            }
            list.push(...hostButtons('New game'));
            return [lines.join(' '), list];
        }

        // Being played
        const list = [];
        const you = current.you;
        let status = isPaused() ? 'The game is paused.'
            : isMyTurn() ? (current.penalty > 0 ? 'Your turn. Stack or draw ' + current.penalty + ' cards!' : 'Your turn!')
            : 'Waiting for ' + nameAt(current.turn.seat) + '.';
        if (you === null) status = 'You\'re watching: you left this game. ' + status;

        if (isPaused()) {
            list.push(paragraph(isHost ? 'You paused the game. Nobody can play until you go on.' : 'The host paused the game. Nobody can play until they go on.'));
            if (isHost) list.push(row(button('Go on with the game', () => act('resume'), { primary: true })));
        }
        if (isMyTurn()) {
            if (current.turn.drawn !== null) {
                list.push(paragraph('You drew the ' + cardName(current.turn.drawn) + '. Play it, or keep it.'));
            } else if (picked.length > 0) {
                list.push(paragraph('Click your other cards with the same number or symbol to play them too, in the order you want: '
                    + 'the last one decides the colour. Then press ' + pickPlayButton.textContent + '.'));
            } else if (current.playable.length === 0) {
                list.push(paragraph(current.penalty > 0 ? 'You can\'t stack, so draw the cards.' : 'You have nothing to play. Draw a card.'));
            } else {
                list.push(paragraph(current.penalty > 0
                    ? 'Stack a card on it to pass them on, or draw them.'
                    : 'Play a ' + COLOUR_NAMES[current.colour] + ' card, one that matches the top card, or a wild card. Or draw.'));
            }
        }
        if (current.uno !== null && you !== null) {
            if (current.uno === you) {
                list.push(paragraph('You\'re down to one card. Call UNO before someone catches you!'));
                list.push(row(button('UNO!', () => act('uno'), { primary: true })));
            } else {
                list.push(paragraph(nameAt(current.uno) + ' has one card and didn\'t call UNO!'));
                list.push(row(button('Catch ' + nameAt(current.uno) + '!', () => act('catch'), { primary: true })));
            }
        }
        return [status, list];
    }

    function resultText(result) {
        if (result === null) return '';
        if (result.reason === 'stopped') return 'The game was stopped, as everyone had gone.';
        const winner = playerAt(result.winner);
        const won = winner?.isYou ? 'You won' : (winner?.name ?? 'Someone') + ' won';
        if (result.reason === 'left') return won + ', as everyone else left.';
        return won + (result.points > 0 ? ' with ' + result.points + (result.points === 1 ? ' point!' : ' points!') : '!');
    }

    // Once the game is over: the cards everyone was left with
    function scores() {
        const list = document.createElement('ul');
        list.className = 'uno-scores';
        for (const player of state().players) {
            if (player.out || player.hand === null || player.hand.length === 0) continue;
            const item = document.createElement('li');
            const name = document.createElement('span');
            name.className = 'uno-scores-name';
            name.textContent = player.isYou ? 'You' : player.name;
            const cards = document.createElement('span');
            cards.className = 'uno-scores-cards';
            cards.append(...sortCards(player.hand).map((card) => {
                const element = cardElement(card);
                element.classList.add('is-mini');
                return element;
            }));
            item.append(name, cards);
            list.append(item);
        }
        return list;
    }

    // ----- What happened, the newest last

    function renderLog() {
        const lines = state()?.log ?? [];
        logEmpty.hidden = lines.length > 0;
        const newest = lines.at(-1)?.n ?? null;
        if (newest === shownLog) return;
        shownLog = newest;
        logList.replaceChildren(...lines.map((line) => {
            const item = document.createElement('li');
            item.textContent = line.text;
            return item;
        }));
        logList.scrollTop = logList.scrollHeight;
    }

    // ----- The chat

    const timeFormat = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });

    // Only drawn again when there's a new message, so it doesn't jump while it's being read. It stays at the bottom when
    // it was there already (or it's the player's own message), otherwise where they scrolled to.
    function renderChat() {
        const messages = game.chat;
        const newest = messages.at(-1)?.id ?? 0;
        chatText.dataset.mentions = JSON.stringify(game.chatMentions ?? []); // who the @ list offers (mentions.js)
        chatEmpty.hidden = messages.length > 0;
        if (newest === shownChat) return;

        const atBottom = chatList.scrollHeight - chatList.scrollTop - chatList.clientHeight < 40;
        chatList.replaceChildren(...messages.map(chatItem));
        if (atBottom || shownChat === null || messages.at(-1)?.isYou) chatList.scrollTop = chatList.scrollHeight;
        shownChat = newest;
    }

    // Built with textContent, as it's all typed in by users. The text comes cut up into its @mentions (play.php's
    // parts), which get a span each, and someone else's message that mentions the user lights up.
    function chatItem(entry) {
        const parts = entry.parts ?? [{ text: entry.text }];
        const item = document.createElement('li');
        item.className = 'uno-chat-item' + (entry.isYou ? ' is-you' : '')
            + (!entry.isYou && parts.some((part) => part.isYou) ? ' is-mention' : '');

        const head = document.createElement('div');
        head.className = 'uno-chat-head';
        const name = document.createElement('span');
        name.className = 'uno-chat-name';
        name.textContent = entry.name + (entry.isYou ? ' (you)' : '');
        const time = document.createElement('time');
        time.className = 'uno-chat-time';
        time.dateTime = entry.time;
        time.textContent = timeFormat.format(new Date(entry.time));
        head.append(name, time);

        const text = document.createElement('p');
        text.className = 'uno-chat-text';
        text.append(...parts.map((part) => {
            if (!part.mention) return part.text;
            const mention = document.createElement('span');
            mention.className = 'chat-mention' + (part.isYou ? ' is-you' : '');
            mention.textContent = part.text;
            return mention;
        }));

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
        if (!isBusy) apply(data);
    });

    // ----- The ping when it's the player's turn, made here rather than loaded. Browsers only let a page play sound once
    // it's been clicked or typed in, so the first click on the page wakes it up.

    const SOUND_KEY = 'hbHubUnoSound';
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

    // Three quick notes going up, so it doesn't sound like the chess or Matador ping
    function ping() {
        if (!isSoundOn) return;
        wakeAudio();
        if (audio === null) return;
        const now = audio.currentTime;
        [[523, 0], [659, 0.08], [784, 0.16]].forEach(([frequency, after]) => {
            const tone = audio.createOscillator();
            const volume = audio.createGain();
            tone.type = 'triangle';
            tone.frequency.value = frequency;
            volume.gain.setValueAtTime(0.0001, now + after);
            volume.gain.exponentialRampToValueAtTime(0.25, now + after + 0.01);
            volume.gain.exponentialRampToValueAtTime(0.0001, now + after + 0.22);
            tone.connect(volume).connect(audio.destination);
            tone.start(now + after);
            tone.stop(now + after + 0.25);
        });
    }

    // aria-pressed also picks the icon (uno.css)
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

    // ----- Pausing the game (the host)

    pauseButton.addEventListener('click', () => {
        if (isPlaying()) act(isPaused() ? 'resume' : 'pause');
    });

    // ----- The game's settings: the host changes them while no game is being played, everyone else only sees them

    const settingsDialog = find('[data-uno-settings-dialog]');
    const settingsForm = find('[data-uno-settings-form]');
    const settingsMessage = find('[data-uno-settings-message]');
    const settingsSave = find('[data-uno-settings-save]');
    const canChangeSettings = () => game.isHost && game.phase !== 'playing';

    // The choices are only filled in from the game when it opens, or while they can't be changed (so a poll doesn't
    // undo what the host has just clicked)
    function fillSettings() {
        settingsForm.elements.turnSeconds.value = String(game.settings.turnSeconds);
        settingsForm.elements.stacking.value = game.settings.stacking ? 'on' : 'off';
        settingsForm.elements.stackMixing.value = game.settings.stackMixing ? 'on' : 'off';
        settingsForm.elements.drawUntil.value = game.settings.drawUntil ? 'on' : 'off';
    }

    function renderSettings() {
        const canChange = canChangeSettings();
        if (!canChange) fillSettings();
        settingsForm.querySelectorAll('[data-uno-settings-fields]').forEach((fields) => (fields.disabled = !canChange));
        settingsSave.hidden = !canChange;
        settingsSave.disabled = isBusy;
        find('[data-uno-settings-text]').textContent = canChange
            ? 'Choose how the next game is played.'
            : game.phase === 'playing' ? 'A game is being played, so these can\'t be changed until it\'s over.'
            : 'Only the host can change these.';
    }

    function openSettings() {
        if (game === null) return;
        settingsMessage.textContent = '';
        fillSettings();
        renderSettings();
        settingsDialog.showModal();
    }

    settingsButton.addEventListener('click', openSettings);
    find('[data-uno-settings-close]').addEventListener('click', () => settingsDialog.close());
    settingsForm.addEventListener('submit', async (event) => {
        event.preventDefault(); // it's closed once it's saved, not straight away
        const data = await act('settings', {
            turnSeconds: settingsForm.elements.turnSeconds.value,
            stacking: settingsForm.elements.stacking.value,
            stackMixing: settingsForm.elements.stackMixing.value,
            drawUntil: settingsForm.elements.drawUntil.value,
        }, settingsMessage);
        if (data && !data.error) settingsDialog.close();
    });

    // ----- Confirming

    const confirmBox = find('[data-uno-confirm]');
    function confirmDialog(title, text, okText) {
        find('[data-uno-confirm-title]').textContent = title;
        find('[data-uno-confirm-text]').textContent = text;
        find('[data-uno-confirm-ok]').textContent = okText;
        confirmBox.returnValue = '';
        confirmBox.showModal();
        find('[data-uno-confirm-cancel]').focus(); // the safe choice is the one Enter picks
        return new Promise((resolve) => {
            confirmBox.addEventListener('close', () => resolve(confirmBox.returnValue === 'ok'), { once: true });
        });
    }
    find('[data-uno-confirm-cancel]').addEventListener('click', () => confirmBox.close('cancel'));
    find('[data-uno-confirm-ok]').addEventListener('click', () => confirmBox.close('ok'));

    // ----- Inviting someone: find people with the chat's user search

    const inviteDialog = find('[data-uno-invite-dialog]');
    const inviteSearch = find('[data-uno-invite-search]');
    const inviteList = find('[data-uno-invite-list]');
    const inviteStatus = find('[data-uno-invite-status]');
    let searchTimer = null;
    let searchNumber = 0;

    function openInvite() {
        find('[data-uno-invite-text]').textContent = (game.room.type === 'invite' ? 'Only people you invite can join this game. ' : '')
            + 'They get a notification, and join from the UNO page while no game is being played.';
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
        item.className = 'uno-invite-user';
        const name = document.createElement('span');
        name.textContent = user.userName;
        const isIn = game.seated.some((person) => person.name === user.userName);
        const choose = document.createElement('button');
        choose.type = 'button';
        choose.className = 'casino-btn uno-small-btn';
        choose.textContent = isIn ? 'In the game' : 'Invite';
        choose.disabled = isIn;
        choose.addEventListener('click', () => invite(user, choose));
        item.append(name, choose);
        return item;
    }

    async function invite(user, choose) {
        choose.disabled = true;
        const data = await post('invite', { user: String(user.userId) });
        if (data === null) {
            inviteStatus.textContent = 'Something went wrong. Please try again.';
            choose.disabled = false;
            return;
        }
        if (!isBusy) apply(data);
        if (data.error) {
            inviteStatus.textContent = data.error;
            choose.disabled = false;
        } else {
            inviteStatus.textContent = user.userName + ' is invited.';
            choose.textContent = 'Invited';
        }
    }

    inviteButton.addEventListener('click', openInvite);
    inviteSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadUsers, 200);
    });
    find('[data-uno-invite-close]').addEventListener('click', () => inviteDialog.close());

    // ----- The join code: a click copies it, to send to others

    codeButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(codeButton.dataset.unoCode);
            codeCopied.textContent = 'Copied';
        } catch {
            codeCopied.textContent = ''; // no clipboard access: the code is right there to read
        }
        setTimeout(() => (codeCopied.textContent = ''), 2000);
    });

    // ----- Leaving: a game being played counts as a loss, unless the host has paused it

    leaveButton.addEventListener('click', async () => {
        if (isPlaying() && state().you !== null
            && !await confirmDialog('Leave the game?', isPaused()
                ? 'The game is paused, so leaving doesn\'t count as a loss. The others play on without you.'
                : 'It\'s being played, so leaving counts as a loss. The others play on without you.', 'Leave')) {
            return;
        }
        const data = await post('leave');
        if (data?.left) {
            location.assign('/games/uno/');
        } else {
            message.textContent = 'Could not leave the game. Please try again.';
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') poll(); // catch up when coming back to the tab
    });
    poll();
})();
