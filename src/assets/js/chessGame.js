// A chess game (games/chess/game.php): draws the game as /games/chess/play says it is, and sends what the player does.
// Everything is decided on the server - which moves are legal, check, mate, the clocks. The server sends the legal
// moves of the player whose move it is, so this only has to show them. A piece moves by clicking it and then where it
// goes, or by dragging it there (or with the keyboard: Enter on the piece, then on the square).
//
// Asks what's new every second while the page is in view, and every 4 seconds while it isn't (so the game doesn't count
// the player as away, which takes 10 seconds without hearing from them). The whole game only comes back when its
// version changed: a move, someone wrote in the chat, or someone joined, left, went away or came back.
//
// The clocks count down here, from the time the server sent (the server's and the player's clocks don't have to
// agree). The server ends the game once a clock runs out; this only shows it, and asks straight away when it does.
// The host can pause the game (the button in the header): the server then sends no running clock and no legal moves.
//
// The game's settings (game.clock and game.colour: the clock, and the colour the host plays) are shown in their dialog,
// where the host changes them while no game is being played. Everyone else only sees them.
//
// Once a second player has joined, the host starts the game (the "start" action), so the settings can still change.
// The host gets a ping when someone joins.
//
// A pawn can only become a piece its player has lost (game.game.promotions, from the server), so the choice when one
// reaches the last rank only has those. With none, it goes there as a pawn and waits. Once one of the player's pieces
// is taken, the server sends becoming a piece as a move from the pawn's square to the same square, and the choice opens
// by itself at the start of their move (or when they click the pawn, if they closed it).
//
// A ping is played when it becomes the player's move, unless they turned the sound off (remembered in this browser).
//
// Like on chess.com, the right mouse button draws on the board: dragged from one square to another it draws an arrow,
// and clicked on a square it colours the square in. Shift, Ctrl and Alt pick other colours, and drawing the same one
// again takes it away. Only the player sees them (they're never sent anywhere), and they go away with a left click on
// the board or when a move is played.
(() => {
    const root = document.querySelector('[data-chess]');
    if (!root) return;

    const API = '/games/chess/play';
    const POLL_MS = 1000;
    const HIDDEN_POLL_MS = 4000;
    const TICK_MS = 100;
    const CLOCK_LOW_MS = 20000; // the clock turns red from here
    const DRAG_PX = 4; // a press that moves further than this drags the piece
    const START_BOARD = 'RNBQKBNRPPPPPPPP' + '.'.repeat(32) + 'pppppppprnbqkbnr';
    const FILES = 'abcdefgh';
    const PIECE_NAMES = { p: 'pawn', n: 'knight', b: 'bishop', r: 'rook', q: 'queen', k: 'king' };
    const VALUES = { p: 1, n: 3, b: 3, r: 5, q: 9, k: 0 };
    const COLOUR_NAMES = { w: 'White', b: 'Black' };
    const PROMOTIONS = ['q', 'r', 'b', 'n'];
    const SVG = 'http://www.w3.org/2000/svg';
    const roomId = root.dataset.room;
    const baseTitle = document.title;

    const find = (selector) => root.querySelector(selector);
    const boardElement = find('[data-ch-board]');
    const boardView = find('[data-ch-board-view]');
    const statusText = find('[data-ch-status]');
    const message = find('[data-ch-message]');
    const actions = find('[data-ch-actions]');
    const moveList = find('[data-ch-moves]');
    const movesEmpty = find('[data-ch-moves-empty]');
    const codeButton = find('[data-ch-code]');
    const codeCopied = find('[data-ch-code-copied]');
    const clockName = find('[data-ch-clock-name]');
    const inviteButton = find('[data-ch-invite]');
    const settingsButton = find('[data-ch-settings]');
    const pauseButton = find('[data-ch-pause]');
    const flipButton = find('[data-ch-flip]');
    const soundButton = find('[data-ch-sound]');
    const leaveButton = find('[data-ch-leave]');
    const chatList = find('[data-ch-chat]');
    const chatEmpty = find('[data-ch-chat-empty]');
    const chatMessage = find('[data-ch-chat-message]');
    const chatForm = find('[data-ch-chat-form]');
    const chatText = find('[data-ch-chat-text]');
    const bars = Object.fromEntries([...root.querySelectorAll('[data-ch-bar]')].map((bar) => [bar.dataset.chBar, {
        element: bar,
        avatar: bar.querySelector('[data-ch-avatar]'),
        name: bar.querySelector('[data-ch-name]'),
        state: bar.querySelector('[data-ch-state]'),
        captured: bar.querySelector('[data-ch-captured]'),
        clock: bar.querySelector('[data-ch-clock]'),
    }]));

    let version = null; // the game's version that's shown
    let revision = -1; // the room's revision in it, to tell older answers from newer ones
    let shownChatCount = 0; // the newest chat message seen in an answer, so older answers don't take it away again
    let game = null;
    let isBusy = false; // something the player did is on its way
    let isPolling = false;
    let pollTimer = null;
    let isFirstDraw = true; // no ping when the page opens
    let selected = null; // the square of the piece picked up, like "e2"
    let isFlipped = false; // the player turned the board around themselves
    let clockAt = 0; // when the clocks in the game were sent, on performance.now()'s clock
    let hasAskedAtZero = false; // the server has been asked since a clock ran out
    let claimAt = null; // when the player can claim the win from an opponent who's gone, on performance.now()'s clock
    let pingedPly = null; // the move of the player's that's been pinged for, null when it isn't theirs
    let promptedPly = null; // the move the choice for a waiting pawn opened by itself on
    let seatedCount = null; // how many sat in the room when it was last drawn, to ping the host when someone joins
    let shownChat = null; // the newest chat message drawn (its id)
    let shownMoves = -1; // how many moves the move list has

    const isPlaying = () => game !== null && game.phase === 'playing' && game.game !== null;
    const isPaused = () => isPlaying() && game.game.paused;
    const isMyMove = () => isPlaying() && !isPaused() && game.you !== null && game.game.turn === game.you;
    const other = (colour) => (colour === 'w' ? 'b' : 'w');
    const playerOf = (colour) => game.game[colour === 'w' ? 'white' : 'black'];
    const nameOf = (colour) => (playerOf(colour).isYou ? 'You' : playerOf(colour).name);
    const colourOf = (piece) => (piece === '.' ? null : piece === piece.toUpperCase() ? 'w' : 'b');
    const indexOf = (square) => (Number(square[1]) - 1) * 8 + FILES.indexOf(square[0]);
    const boardNow = () => (game?.game ? game.game.board : START_BOARD);
    const pieceAt = (square) => boardNow()[indexOf(square)];
    const targetsOf = (square) => (isMyMove() && !isBusy ? game.game.legal[square] ?? [] : []);
    // A pawn on the last rank that can become a piece now: its move goes to its own square
    const isWaitingPawn = (square) => targetsOf(square).includes(square);
    const waitingPawn = () => Object.keys(squares).find(isWaitingPawn) ?? null;
    const pieceClass = (colour, type) => 'ch-piece is-' + (colour === 'w' ? 'white' : 'black') + ' is-' + type; // chess.css draws it

    async function request(options, query = '') {
        try {
            const response = await fetch(API + query, { headers: { Accept: 'application/json' }, ...options });
            const data = await response.json().catch(() => null);
            if (response.status === 404 && data?.error === 'notSeated') {
                location.assign('/games/chess/'); // left in another tab, or gone too long
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
        clockAt = performance.now();
        hasAskedAtZero = false;
        const opponent = game.game && game.you !== null ? playerOf(other(game.you)) : null;
        claimAt = isPlaying() && opponent?.claimIn != null ? clockAt + opponent.claimIn * 1000 : null;
        if (selected !== null && targetsOf(selected).length === 0) selected = null;
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
        if (data !== null && !isBusy) apply(data); // a move on its way is shown already, and its answer comes next

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
            version = null; // so the next check gets the whole game, in case a move was shown that didn't happen
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
        renderBoard();
        renderBars();
        renderStatus();
        renderMoves();
        renderChat();
        if (settingsDialog.open) renderSettings();
        document.title = (isMyMove() ? '● Your move - ' : '') + baseTitle;
        boardView.classList.toggle('is-my-turn', isMyMove()); // the board glows (chess.css)

        const myPly = isMyMove() ? game.game.ply : null;
        if (myPly !== null && myPly !== pingedPly && !isFirstDraw) ping();
        const hasJoined = game.isHost && game.phase === 'lobby' && seatedCount !== null && game.seated.length > seatedCount;
        if (hasJoined && !isFirstDraw) ping();
        pingedPly = myPly;
        seatedCount = game.seated.length;
        isFirstDraw = false;

        // One of the player's pieces was just taken while a pawn waits on the last rank: the choice opens by itself
        const pawn = myPly !== null && !isBusy ? waitingPawn() : null;
        if (pawn !== null && promptedPly !== myPly && !promotionDialog.open) {
            promptedPly = myPly;
            move(pawn, pawn);
        }
    }

    // aria-pressed also picks the pause button's icon (chess.css)
    function renderHeader() {
        clockName.textContent = game.clockName;
        inviteButton.hidden = !(game.isHost && game.phase === 'lobby' && game.seated.length < 2);
        pauseButton.hidden = !(game.isHost && isPlaying());
        pauseButton.disabled = isBusy;
        pauseButton.setAttribute('aria-pressed', String(isPaused()));
        pauseButton.title = isPaused() ? 'Paused: go on with the game' : 'Pause the game';
    }

    // ----- The board: 64 buttons, a8 first as it's read (or h1 first when it's turned around)

    const squares = {};
    for (let rank = 8; rank >= 1; rank--) {
        for (const file of FILES) {
            const name = file + rank;
            const element = document.createElement('button');
            element.type = 'button';
            element.className = 'ch-square ' + ((FILES.indexOf(file) + rank) % 2 === 0 ? 'is-light' : 'is-dark');
            element.dataset.square = name;
            const piece = document.createElement('span');
            piece.className = 'ch-piece';
            piece.setAttribute('aria-hidden', 'true');
            const rankLabel = document.createElement('span');
            rankLabel.className = 'ch-coord ch-coord-rank';
            rankLabel.setAttribute('aria-hidden', 'true');
            rankLabel.textContent = String(rank);
            const fileLabel = document.createElement('span');
            fileLabel.className = 'ch-coord ch-coord-file';
            fileLabel.setAttribute('aria-hidden', 'true');
            fileLabel.textContent = file;
            element.append(piece, rankLabel, fileLabel);
            squares[name] = { element, piece, rankLabel, fileLabel };
        }
    }

    // White at the bottom, unless the player plays Black (or turned it around)
    const isBlackBelow = () => (game?.you === 'b' || (game?.you == null && game?.colour === 'black' && game?.isHost)) !== isFlipped;
    let shownBlackBelow = null;

    function placeSquares() {
        const blackBelow = isBlackBelow();
        if (blackBelow === shownBlackBelow) return;
        shownBlackBelow = blackBelow;
        const names = Object.keys(squares); // a8 ... h1
        boardElement.replaceChildren(...(blackBelow ? names.reverse() : names).map((name) => squares[name].element), arrowLayer);
        for (const [name, square] of Object.entries(squares)) {
            square.rankLabel.hidden = name[0] !== (blackBelow ? 'h' : 'a'); // on the left edge
            square.fileLabel.hidden = name[1] !== (blackBelow ? '8' : '1'); // on the bottom edge
        }
    }

    function renderBoard() {
        placeSquares();
        const state = game.game;
        const board = boardNow();
        const targets = selected !== null ? targetsOf(selected) : [];
        boardElement.classList.toggle('is-waiting', state === null);

        for (const [name, square] of Object.entries(squares)) {
            const piece = board[indexOf(name)];
            const colour = colourOf(piece);
            square.piece.className = colour !== null ? pieceClass(colour, piece.toLowerCase()) : 'ch-piece';
            const element = square.element;
            element.classList.toggle('is-last', state?.lastMove?.includes(name) ?? false);
            element.classList.toggle('is-check', state?.check === name);
            element.classList.toggle('is-selected', selected === name);
            element.classList.toggle('is-target', targets.includes(name));
            element.classList.toggle('is-capture', targets.includes(name) && colour !== null);
            element.classList.toggle('is-movable', targetsOf(name).length > 0);
            element.setAttribute('aria-label', name + (colour !== null ? ', ' + COLOUR_NAMES[colour].toLowerCase() + ' ' + PIECE_NAMES[piece.toLowerCase()] : '')
                + (selected === name ? ', picked up' : '') + (targets.includes(name) ? ', you can move here' : ''));
        }
        renderDrawings();
    }

    // ----- Drawing on the board with the right mouse button: arrows, and squares coloured in. An SVG on top of the
    // squares (one unit a square) has the arrows; the coloured squares are a class on the square, so the piece stays on top.

    const DRAW_COLOURS = ['orange', 'red', 'green', 'blue']; // chess.css has them
    const arrowLayer = document.createElementNS(SVG, 'svg');
    arrowLayer.classList.add('ch-arrows');
    arrowLayer.setAttribute('viewBox', '0 0 8 8');
    arrowLayer.setAttribute('aria-hidden', 'true');

    let arrows = []; // { from, to, colour }, like { from: 'e2', to: 'e4', colour: 'orange' }
    let marks = {}; // square => colour
    let drawing = null; // the right button is held down: { from, pointerId, to, colour }
    let drawnPly = null; // the move the drawings are for: they go when it's played

    // Shift, Ctrl and Alt pick the colour; an arrow is orange and a square red without them
    const drawColour = (event, isArrow) => (event.altKey ? 'blue' : event.shiftKey ? 'green' : event.ctrlKey ? 'red' : isArrow ? 'orange' : 'red');

    // The middle of a square on the board as it's shown, in squares from the top left
    function centreOf(square) {
        const file = FILES.indexOf(square[0]);
        const rank = Number(square[1]);
        return isBlackBelow() ? { x: 7 - file + 0.5, y: rank - 0.5 } : { x: file + 0.5, y: 8 - rank + 0.5 };
    }

    // An arrow from the middle of one square to the other. A knight's move bends: the long way first, then the short.
    function arrowShape(from, to, colour) {
        const HEAD_LENGTH = 0.45;
        const HEAD_WIDTH = 0.5;
        const START_GAP = 0.25; // it starts a little way out from the middle
        const a = centreOf(from);
        const b = centreOf(to);
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const isKnight = (Math.abs(dx) === 1 && Math.abs(dy) === 2) || (Math.abs(dx) === 2 && Math.abs(dy) === 1);
        const corner = isKnight ? (Math.abs(dx) > Math.abs(dy) ? { x: b.x, y: a.y } : { x: a.x, y: b.y }) : null;

        const unit = (p, q) => {
            const length = Math.hypot(q.x - p.x, q.y - p.y);
            return { x: (q.x - p.x) / length, y: (q.y - p.y) / length };
        };
        const first = unit(a, corner ?? b);
        const last = unit(corner ?? a, b);
        const start = { x: a.x + first.x * START_GAP, y: a.y + first.y * START_GAP };
        const neck = { x: b.x - last.x * HEAD_LENGTH, y: b.y - last.y * HEAD_LENGTH }; // where the head starts
        const side = { x: -last.y * HEAD_WIDTH / 2, y: last.x * HEAD_WIDTH / 2 };
        const point = (p) => p.x.toFixed(3) + ',' + p.y.toFixed(3);

        // A group, so its see-through colour doesn't get darker where the line and the head overlap (chess.css)
        const group = document.createElementNS(SVG, 'g');
        group.setAttribute('class', 'ch-arrow is-draw-' + colour);
        const line = document.createElementNS(SVG, 'polyline');
        line.setAttribute('points', [start, ...(corner ? [corner] : []), neck].map(point).join(' '));
        const head = document.createElementNS(SVG, 'polygon');
        head.setAttribute('points', [b, { x: neck.x + side.x, y: neck.y + side.y }, { x: neck.x - side.x, y: neck.y - side.y }].map(point).join(' '));
        group.append(line, head);
        return group;
    }

    function renderDrawings() {
        const ply = game?.game?.ply ?? null;
        if (ply !== drawnPly) {
            drawnPly = ply;
            arrows = [];
            marks = {};
        }
        for (const [name, square] of Object.entries(squares)) {
            for (const colour of DRAW_COLOURS) square.element.classList.toggle('is-draw-' + colour, marks[name] === colour);
        }
        const shown = arrows.filter((arrow) => drawing === null || arrow.from !== drawing.from || arrow.to !== drawing.to);
        if (drawing !== null && drawing.to !== null && drawing.to !== drawing.from) shown.push(drawing); // the one being drawn
        arrowLayer.replaceChildren(...shown.map((arrow) => arrowShape(arrow.from, arrow.to, arrow.colour)));
    }

    function clearDrawings() {
        if (arrows.length === 0 && Object.keys(marks).length === 0) return;
        arrows = [];
        marks = {};
        renderDrawings();
    }

    const squareAt = (event) => document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-square]')?.dataset.square ?? null;

    boardElement.addEventListener('contextmenu', (event) => event.preventDefault());

    boardElement.addEventListener('pointerdown', (event) => {
        const element = event.target.closest('[data-square]');
        if (!element || event.button !== 2 || drag !== null) return;
        event.preventDefault();
        boardElement.setPointerCapture(event.pointerId);
        drawing = { from: element.dataset.square, to: element.dataset.square, pointerId: event.pointerId, colour: drawColour(event, false) };
    });

    boardElement.addEventListener('pointermove', (event) => {
        if (drawing === null || event.pointerId !== drawing.pointerId) return;
        const to = squareAt(event);
        const colour = drawColour(event, to !== drawing.from);
        if (to === drawing.to && colour === drawing.colour) return;
        drawing.to = to;
        drawing.colour = colour;
        renderDrawings();
    });

    // Let go on the square it started on: that square is coloured in. On another: an arrow to it. Off the board: nothing.
    // The same colour again takes it away, another colour swaps it.
    function endDrawing(event, isDropped) {
        if (drawing === null || event.pointerId !== drawing.pointerId) return;
        const { from } = drawing;
        const to = isDropped ? squareAt(event) : null;
        drawing = null;
        if (to === from) {
            const colour = drawColour(event, false);
            if (marks[from] === colour) delete marks[from];
            else marks[from] = colour;
        } else if (to !== null) {
            const colour = drawColour(event, true);
            const old = arrows.find((arrow) => arrow.from === from && arrow.to === to);
            arrows = arrows.filter((arrow) => arrow !== old);
            if (old?.colour !== colour) arrows.push({ from, to, colour });
        }
        renderDrawings();
    }
    boardElement.addEventListener('pointerup', (event) => endDrawing(event, true));
    boardElement.addEventListener('pointercancel', (event) => endDrawing(event, false));

    // ----- The players, above and below the board

    function renderBars() {
        const below = isBlackBelow() ? 'b' : 'w';
        if (game.game !== null && game.phase !== 'lobby') {
            drawBar(bars.top, other(below));
            drawBar(bars.bottom, below);
        } else {
            // Waiting for a game to start: the player below, and whoever else is here (maybe nobody yet) above
            const you = game.seated.find((person) => person.isYou) ?? null;
            const others = game.seated.filter((person) => !person.isYou);
            drawPerson(bars.bottom, you, '');
            drawPerson(bars.top, others[0] ?? null, others[0] ? '' : 'Waiting for someone to join…');
            bars.top.clock.hidden = bars.bottom.clock.hidden = true;
            bars.top.element.classList.remove('is-turn');
            bars.bottom.element.classList.remove('is-turn');
            bars.top.captured.replaceChildren();
            bars.bottom.captured.replaceChildren();
        }
        tick();
    }

    function drawPerson(bar, person, emptyText) {
        bar.element.classList.toggle('is-empty', person === null);
        bar.name.textContent = person ? person.name + (person.isYou ? ' (you)' : '') : emptyText;
        bar.avatar.replaceChildren();
        if (person?.avatar) {
            const image = document.createElement('img');
            image.src = person.avatar;
            image.alt = '';
            bar.avatar.append(image);
        } else if (person) {
            bar.avatar.textContent = person.name.slice(0, 1).toUpperCase();
        }
        bar.state.textContent = person && !person.here ? 'Left' : person?.away ? 'Away' : '';
    }

    function drawBar(bar, colour) {
        const state = game.game;
        const person = playerOf(colour);
        drawPerson(bar, person, '');
        bar.element.classList.toggle('is-turn', isPlaying() && state.turn === colour);
        bar.element.dataset.colour = colour;
        const status = [COLOUR_NAMES[colour]];
        if (!person.here) status.push('left');
        else if (person.away) status.push('away');
        if (person.wantsRematch && game.phase === 'ended') status.push('wants a rematch');
        bar.state.textContent = status.join(' · ');

        // The pieces this player has taken, and how much more that's worth than what they've lost
        const taken = state.captured[other(colour)];
        const advantage = [...taken].reduce((sum, type) => sum + VALUES[type], 0)
            - [...state.captured[colour]].reduce((sum, type) => sum + VALUES[type], 0);
        bar.captured.replaceChildren(...[...taken].map((type) => {
            const piece = document.createElement('span');
            piece.className = pieceClass(other(colour), type);
            return piece;
        }));
        if (advantage > 0) {
            const plus = document.createElement('span');
            plus.className = 'ch-advantage';
            plus.textContent = '+' + advantage;
            bar.captured.append(plus);
        }
        bar.captured.setAttribute('aria-label', taken.length > 0
            ? 'Has taken ' + [...taken].map((type) => PIECE_NAMES[type]).join(', ') : '');

        bar.clock.hidden = state.clocks === null;
    }

    // ----- The clocks, and the countdown until the player can claim the win from an opponent who's gone

    function timeLeft(colour) {
        const state = game.game;
        const running = state.running === colour ? performance.now() - clockAt : 0;
        return Math.max(0, state.clocks[colour] - running);
    }

    function clockText(ms) {
        const seconds = ms < 10000 ? Math.ceil(ms / 100) / 10 : Math.ceil(ms / 1000);
        const minutes = Math.floor(seconds / 60);
        const rest = seconds - minutes * 60;
        return minutes + ':' + (ms < 10000 ? rest.toFixed(1).padStart(4, '0') : String(rest).padStart(2, '0'));
    }

    function tick() {
        if (game?.game?.clocks) {
            for (const bar of Object.values(bars)) {
                const colour = bar.element.dataset.colour;
                if (!colour) continue;
                const ms = timeLeft(colour);
                bar.clock.textContent = clockText(ms);
                bar.clock.classList.toggle('is-running', game.game.running === colour);
                bar.clock.classList.toggle('is-low', ms < CLOCK_LOW_MS);
            }
            const running = game.game.running;
            if (running !== null && timeLeft(running) === 0 && !hasAskedAtZero) {
                hasAskedAtZero = true;
                poll(); // the server ends the game
            }
        }

        const claim = find('[data-ch-claim-in]');
        if (claim && claimAt !== null) {
            const seconds = Math.ceil((claimAt - performance.now()) / 1000);
            if (seconds <= 0) {
                renderStatus(); // the claim button instead of the countdown
            } else {
                claim.textContent = seconds + (seconds === 1 ? ' second' : ' seconds');
            }
        }
    }
    setInterval(tick, TICK_MS);

    // ----- What's going on, and what the player can do

    function button(text, onClick, { primary = false, disabled = false } = {}) {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = 'casino-btn' + (primary ? ' casino-btn-primary' : '');
        element.textContent = text;
        element.disabled = disabled || isBusy;
        element.addEventListener('click', onClick);
        return element;
    }

    function row(...list) {
        const element = document.createElement('div');
        element.className = 'ch-action-buttons';
        element.append(...list);
        return element;
    }

    function paragraph(text) {
        const element = document.createElement('p');
        element.className = 'ch-action-text';
        element.textContent = text;
        return element;
    }

    function renderStatus() {
        const [status, list] = statusAndActions();
        statusText.textContent = status;
        actions.replaceChildren(...list);
    }

    function statusAndActions() {
        const state = game.game;
        const isHost = game.isHost;
        const code = codeButton.dataset.chCode;

        if (game.phase === 'lobby') {
            const lines = [];
            if (game.note) lines.push(game.note);
            if (state?.result) lines.push('Last game: ' + resultText(state.result));
            const guest = game.seated.find((person) => !person.isYou) ?? null;
            const host = game.seated.find((person) => person.isHost) ?? null;

            // Both are here: the host starts it
            if (game.seated.length >= 2) {
                return [
                    [...lines, isHost ? guest.name + ' has joined.' : 'Waiting for ' + (host?.name ?? 'the host') + ' to start the game.'].join(' '),
                    [
                        paragraph('Settings: ' + settingsText() + '.'),
                        ...(isHost ? [
                            paragraph('Change the settings if you want to, and start the game when you\'re ready.'),
                            row(button('Start the game', () => act('start'), { primary: true }), button('Game settings', openSettings)),
                        ] : []),
                    ],
                ];
            }

            const hostButtons = isHost ? [button('Invite someone', () => openInvite(), { primary: true }), button('Game settings', openSettings)] : [];
            return [
                [...lines, 'Waiting for someone to join.'].join(' '),
                [
                    paragraph('Give them the code ' + code + (game.room.type === 'public' ? ', or they can find the game on the chess page.' : '.')),
                    paragraph('Settings: ' + settingsText() + '.'),
                    row(...hostButtons),
                ],
            ];
        }

        if (game.phase === 'ended') {
            const you = game.you;
            const opponent = you !== null ? playerOf(other(you)) : null;
            const list = [];
            if (you !== null && opponent.here) {
                const wants = playerOf(you).wantsRematch;
                list.push(paragraph(wants ? 'You asked for a rematch. Waiting for ' + opponent.name + '…'
                    : opponent.wantsRematch ? opponent.name + ' wants a rematch.' : 'Play again? You swap colours.'));
                list.push(row(wants
                    ? button('Take it back', () => act('cancelRematch'))
                    : button(opponent.wantsRematch ? 'Accept the rematch' : 'Rematch', () => act('rematch'), { primary: true })));
            }
            return [resultText(state.result), list];
        }

        // Being played
        const you = game.you;
        const opponent = playerOf(other(you));
        const list = [];
        let status = isPaused() ? 'The game is paused.'
            : isMyMove() ? (state.check !== null ? 'Your move. You\'re in check!' : 'Your move.')
            : 'Waiting for ' + opponent.name + ' to move.';

        if (isPaused()) {
            if (isHost) {
                list.push(paragraph('You paused the game. The clocks are stopped, and nobody can move until you go on.'));
                list.push(row(button('Go on with the game', () => act('resume'), { primary: true })));
            } else {
                list.push(paragraph(opponent.name + ' paused the game. The clocks are stopped, and nobody can move until they go on.'));
            }
        }

        const pawn = isMyMove() ? waitingPawn() : null;
        if (pawn !== null) {
            list.push(paragraph('Your pawn on ' + pawn + ' can become a piece you\'ve lost now. Click it to choose, or make another move.'));
        }

        if (state.drawOffer === other(you)) {
            list.push(paragraph(opponent.name + ' offers a draw.'));
            list.push(row(
                button('Accept the draw', () => act('answerDraw', { answer: 'accept' }), { primary: true }),
                button('Decline', () => act('answerDraw', { answer: 'decline' })),
            ));
        } else if (state.drawOffer === you) {
            list.push(paragraph('You offered a draw. Waiting for ' + opponent.name + ' to answer.'));
        }

        if (opponent.away && claimAt !== null) {
            const seconds = Math.ceil((claimAt - performance.now()) / 1000);
            if (seconds > 0) {
                const text = paragraph(opponent.name + '\'s game is closed. If they don\'t come back, you can claim the win in ');
                const count = document.createElement('span');
                count.dataset.chClaimIn = '';
                count.textContent = seconds + (seconds === 1 ? ' second' : ' seconds');
                text.append(count, '.');
                list.push(text);
            } else {
                list.push(paragraph(opponent.name + '\'s game has been closed for a while.'));
                list.push(row(button('Claim the win', () => act('claim'), { primary: true })));
            }
        }

        if (state.canAbort) {
            list.push(row(button('Abort the game', async () => {
                if (await confirmDialog('Abort the game?', 'It ends without a result, and doesn\'t count for anyone.', 'Abort')) act('abort');
            })));
        } else {
            list.push(row(
                button('Offer a draw', () => act('offerDraw'), { disabled: !state.canOfferDraw }),
                button('Resign', async () => {
                    if (await confirmDialog('Resign?', opponent.name + ' wins the game.', 'Resign')) act('resign');
                }),
            ));
        }
        return [status, list];
    }

    function resultText(result) {
        const { winner, reason } = result;
        if (reason === 'aborted') return 'The game was aborted.';
        if (reason === 'stopped') return 'The game was stopped, as both players had gone.';
        if (winner === null) {
            const loser = playerOf(game.game.turn); // whose clock ran out
            return {
                stalemate: 'Draw by stalemate.',
                repetition: 'Draw: the same position came up three times.',
                fiftyMoves: 'Draw: 50 moves each without a capture or a pawn move.',
                material: 'Draw: there aren\'t enough pieces left for anyone to mate.',
                agreement: 'Draw by agreement.',
                time: (loser.isYou ? 'You' : loser.name) + ' ran out of time, but there isn\'t enough left to mate with, so it\'s a draw.',
            }[reason] ?? 'Draw.';
        }
        const won = playerOf(winner).isYou ? 'You won!' : playerOf(winner).name + ' won.';
        const loser = nameOf(other(winner));
        return {
            checkmate: 'Checkmate. ',
            resign: loser + ' resigned. ',
            time: loser + ' ran out of time. ',
            abandoned: loser + ' left the game. ',
            left: loser + ' left the game. ',
        }[reason] + won;
    }

    // ----- The moves, two to a line

    function renderMoves() {
        const moves = game.game?.moves ?? [];
        movesEmpty.hidden = moves.length > 0;
        if (moves.length === shownMoves) return;
        shownMoves = moves.length;

        const lines = [];
        for (let i = 0; i < moves.length; i += 2) {
            const line = document.createElement('li');
            line.className = 'ch-move-line';
            const number = document.createElement('span');
            number.className = 'ch-move-number';
            number.textContent = i / 2 + 1 + '.';
            line.append(number);
            for (const j of [i, i + 1]) {
                const move = document.createElement('span');
                move.className = 'ch-move' + (j === moves.length - 1 ? ' is-last' : '');
                move.textContent = moves[j] ?? '';
                line.append(move);
            }
            lines.push(line);
        }
        moveList.replaceChildren(...lines);
        moveList.scrollTop = moveList.scrollHeight;
    }

    // ----- Moving: click (or Enter on) a piece and then where it goes, or drag it there (with the left button; the right
    // one draws, see above)

    function choose(square) {
        if (selected !== null && targetsOf(selected).includes(square)) {
            move(selected, square);
            return;
        }
        if (isWaitingPawn(square)) {
            move(square, square); // straight to the choice of what it becomes
            return;
        }
        selected = targetsOf(square).length > 0 && selected !== square ? square : null;
        renderBoard();
    }

    async function move(from, to) {
        selected = null;
        const piece = pieceAt(from);
        let promotion = null;
        // Without a piece to become (game.game.promotions is empty), it stays a pawn on the last rank
        if (piece.toLowerCase() === 'p' && (to[1] === '8' || to[1] === '1') && game.game.promotions.length > 0) {
            renderBoard();
            promotion = await choosePromotion(colourOf(piece));
            if (promotion === null) return;
        }

        // Shown straight away; the answer brings the board as it really is (castling's rook, en passant, check)
        const state = game.game;
        const board = [...state.board];
        board[indexOf(from)] = '.'; // first, as a waiting pawn becomes its piece where it stands
        board[indexOf(to)] = promotion !== null ? (colourOf(piece) === 'w' ? promotion.toUpperCase() : promotion) : piece;
        const ply = String(state.ply);
        game = { ...game, game: { ...state, board: board.join(''), lastMove: [from, to], check: null, turn: other(state.turn), legal: {} } };
        act('move', { from, to, ply, ...(promotion !== null ? { promotion } : {}) });
    }

    // Pointer presses: picked up on the press, so it can be dragged. A press on the piece that's already picked up,
    // without dragging it, puts it down again.
    let drag = null;

    boardElement.addEventListener('pointerdown', (event) => {
        const element = event.target.closest('[data-square]');
        if (!element || event.button !== 0) return;
        clearDrawings(); // a left click takes the arrows away, like on chess.com
        const square = element.dataset.square;
        const wasSelected = selected === square;
        if ((selected !== null && targetsOf(selected).includes(square)) || isWaitingPawn(square)) {
            choose(square);
            return;
        }
        selected = targetsOf(square).length > 0 ? square : null;
        renderBoard();
        if (selected === null) return;

        event.preventDefault(); // no text selection while dragging
        boardElement.setPointerCapture(event.pointerId);
        drag = { square, wasSelected, pointerId: event.pointerId, x: event.clientX, y: event.clientY, ghost: null };
    });

    boardElement.addEventListener('pointermove', (event) => {
        if (drag === null || event.pointerId !== drag.pointerId) return;
        if (drag.ghost === null) {
            if (Math.hypot(event.clientX - drag.x, event.clientY - drag.y) < DRAG_PX) return;
            const from = squares[drag.square];
            const size = from.element.getBoundingClientRect().width;
            drag.ghost = document.createElement('div');
            drag.ghost.className = 'ch-ghost';
            drag.ghost.style.setProperty('--ch-ghost-size', size + 'px');
            drag.ghost.append(from.piece.cloneNode());
            boardElement.append(drag.ghost);
            from.element.classList.add('is-dragged');
        }
        const box = boardElement.getBoundingClientRect();
        drag.ghost.style.setProperty('--ch-ghost-x', event.clientX - box.left + 'px');
        drag.ghost.style.setProperty('--ch-ghost-y', event.clientY - box.top + 'px');
    });

    function endDrag(event, isDropped) {
        if (drag === null || event.pointerId !== drag.pointerId) return;
        const { square, wasSelected, ghost } = drag;
        drag = null;
        squares[square].element.classList.remove('is-dragged');
        if (ghost === null) {
            if (wasSelected && isDropped) {
                selected = null; // a second click on it
                renderBoard();
            }
            return;
        }
        ghost.remove();
        const target = isDropped ? document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-square]') : null;
        if (target && targetsOf(square).includes(target.dataset.square)) {
            move(square, target.dataset.square);
        } else {
            renderBoard();
        }
    }
    boardElement.addEventListener('pointerup', (event) => endDrag(event, true));
    boardElement.addEventListener('pointercancel', (event) => endDrag(event, false));

    // The keyboard (Enter or Space on a square) clicks without a pointer: detail is 0 then
    boardElement.addEventListener('click', (event) => {
        const element = event.target.closest('[data-square]');
        if (element && event.detail === 0) choose(element.dataset.square);
    });

    // ----- Promoting a pawn: the pieces the player has lost (the server only allows those), queen first

    const promotionDialog = find('[data-ch-promotion]');
    const promotionChoices = find('[data-ch-promotion-choices]');

    function choosePromotion(colour) {
        const allowed = PROMOTIONS.filter((type) => game.game.promotions.includes(type));
        promotionChoices.replaceChildren(...allowed.map((type) => {
            const choice = document.createElement('button');
            choice.type = 'button';
            choice.className = 'ch-promotion-choice';
            choice.setAttribute('aria-label', PIECE_NAMES[type]);
            choice.title = PIECE_NAMES[type][0].toUpperCase() + PIECE_NAMES[type].slice(1);
            const piece = document.createElement('span');
            piece.className = pieceClass(colour, type);
            piece.setAttribute('aria-hidden', 'true');
            choice.append(piece);
            choice.addEventListener('click', () => promotionDialog.close(type));
            return choice;
        }));
        promotionDialog.returnValue = '';
        promotionDialog.showModal();
        return new Promise((resolve) => {
            promotionDialog.addEventListener('close', () => {
                const type = promotionDialog.returnValue;
                resolve(PROMOTIONS.includes(type) ? type : null);
                if (!PROMOTIONS.includes(type)) renderBoard();
            }, { once: true });
        });
    }
    find('[data-ch-promotion-cancel]').addEventListener('click', () => promotionDialog.close(''));

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
    // parts), which get a span each, and the other player's message that mentions the user lights up.
    function chatItem(entry) {
        const parts = entry.parts ?? [{ text: entry.text }];
        const item = document.createElement('li');
        item.className = 'ch-chat-item' + (entry.isYou ? ' is-you' : '')
            + (!entry.isYou && parts.some((part) => part.isYou) ? ' is-mention' : '');

        const head = document.createElement('div');
        head.className = 'ch-chat-head';
        const name = document.createElement('span');
        name.className = 'ch-chat-name';
        name.textContent = entry.name + (entry.isYou ? ' (you)' : '');
        const time = document.createElement('time');
        time.className = 'ch-chat-time';
        time.dateTime = entry.time;
        time.textContent = timeFormat.format(new Date(entry.time));
        head.append(name, time);

        const text = document.createElement('p');
        text.className = 'ch-chat-text';
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

    // ----- The ping when it's the player's move, made here rather than loaded. Browsers only let a page play sound once
    // it's been clicked or typed in, so the first click on the page wakes it up.

    const SOUND_KEY = 'hbHubChessSound';
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
        [[660, 0], [990, 0.1]].forEach(([frequency, after]) => {
            const tone = audio.createOscillator();
            const volume = audio.createGain();
            tone.type = 'sine';
            tone.frequency.value = frequency;
            volume.gain.setValueAtTime(0.0001, now + after);
            volume.gain.exponentialRampToValueAtTime(0.25, now + after + 0.01);
            volume.gain.exponentialRampToValueAtTime(0.0001, now + after + 0.3);
            tone.connect(volume).connect(audio.destination);
            tone.start(now + after);
            tone.stop(now + after + 0.35);
        });
    }

    // aria-pressed also picks the icon (chess.css)
    function renderSound() {
        soundButton.setAttribute('aria-pressed', String(isSoundOn));
        soundButton.title = isSoundOn ? 'Sound on: a ping when it\'s your move' : 'Sound off';
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

    const settingsDialog = find('[data-ch-settings-dialog]');
    const settingsForm = find('[data-ch-settings-form]');
    const settingsMessage = find('[data-ch-settings-message]');
    const settingsSave = find('[data-ch-settings-save]');
    const canChangeSettings = () => game.isHost && game.phase !== 'playing';
    const settingsText = () => game.clockName + ', ' + {
        random: 'random colours',
        white: (game.isHost ? 'you play' : 'the host plays') + ' White',
        black: (game.isHost ? 'you play' : 'the host plays') + ' Black',
    }[game.colour];

    // The choices are only filled in from the game when it opens, or while it can't be changed (so a poll doesn't
    // undo what the host has just clicked)
    function fillSettings() {
        settingsForm.elements.clock.value = game.clock;
        settingsForm.elements.colour.value = game.colour;
    }

    function renderSettings() {
        const canChange = canChangeSettings();
        if (!canChange) fillSettings();
        settingsForm.querySelectorAll('[data-ch-settings-fields]').forEach((fields) => (fields.disabled = !canChange));
        settingsSave.hidden = !canChange;
        settingsSave.disabled = isBusy;
        find('[data-ch-settings-text]').textContent = canChange
            ? 'Choose how the next game is played. The colours only count against someone new: a rematch swaps them.'
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
    find('[data-ch-settings-close]').addEventListener('click', () => settingsDialog.close());
    settingsForm.addEventListener('submit', async (event) => {
        event.preventDefault(); // it's closed once it's saved, not straight away
        const data = await act('settings', {
            clock: settingsForm.elements.clock.value,
            colour: settingsForm.elements.colour.value,
        }, settingsMessage);
        if (data && !data.error) settingsDialog.close();
    });

    // ----- Turning the board around

    flipButton.addEventListener('click', () => {
        isFlipped = !isFlipped;
        flipButton.setAttribute('aria-pressed', String(isFlipped));
        if (game !== null) render();
    });

    // ----- Confirming

    const confirmBox = find('[data-ch-confirm]');
    function confirmDialog(title, text, okText) {
        find('[data-ch-confirm-title]').textContent = title;
        find('[data-ch-confirm-text]').textContent = text;
        find('[data-ch-confirm-ok]').textContent = okText;
        confirmBox.returnValue = '';
        confirmBox.showModal();
        find('[data-ch-confirm-cancel]').focus(); // the safe choice is the one Enter picks
        return new Promise((resolve) => {
            confirmBox.addEventListener('close', () => resolve(confirmBox.returnValue === 'ok'), { once: true });
        });
    }
    find('[data-ch-confirm-cancel]').addEventListener('click', () => confirmBox.close('cancel'));
    find('[data-ch-confirm-ok]').addEventListener('click', () => confirmBox.close('ok'));

    // ----- Inviting someone: find people with the chat's user search

    const inviteDialog = find('[data-ch-invite-dialog]');
    const inviteSearch = find('[data-ch-invite-search]');
    const inviteList = find('[data-ch-invite-list]');
    const inviteStatus = find('[data-ch-invite-status]');
    let searchTimer = null;
    let searchNumber = 0;

    function openInvite() {
        find('[data-ch-invite-text]').textContent = (game.room.type === 'invite' ? 'Only people you invite can join this game. ' : '')
            + 'They get a notification, and join from the chess page.';
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
        item.className = 'ch-invite-user';
        const name = document.createElement('span');
        name.textContent = user.userName;
        const isIn = game.seated.some((person) => person.name === user.userName);
        const choose = document.createElement('button');
        choose.type = 'button';
        choose.className = 'casino-btn ch-small-btn';
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
    find('[data-ch-invite-close]').addEventListener('click', () => inviteDialog.close());

    // ----- The join code: a click copies it, to send to others

    codeButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(codeButton.dataset.chCode);
            codeCopied.textContent = 'Copied';
        } catch {
            codeCopied.textContent = ''; // no clipboard access: the code is right there to read
        }
        setTimeout(() => (codeCopied.textContent = ''), 2000);
    });

    // ----- Leaving: a game being played is lost (or aborted, before both players have moved)

    leaveButton.addEventListener('click', async () => {
        if (isPlaying() && game.you !== null) {
            const ok = game.game.canAbort
                ? await confirmDialog('Leave the game?', 'Nobody has lost yet, so the game is just aborted.', 'Leave')
                : await confirmDialog('Leave the game?', 'Leaving loses the game.', 'Leave and lose');
            if (!ok) return;
        }
        const data = await post('leave');
        if (data?.left) {
            location.assign('/games/chess/');
        } else {
            message.textContent = 'Could not leave the game. Please try again.';
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') poll(); // catch up when coming back to the tab
    });
    poll();
})();
