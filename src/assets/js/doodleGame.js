// A Doodle Chain game (games/doodle/game.php): shows the game as /games/doodle/play says it is, and sends what the
// player hands in. Everything is decided on the server - whose chain the player has, when a step ends, what's shown at
// the end. The page shows one of three things in the stage, by game.phase:
// - 'lobby': who's in, and for the host Start game and Game settings.
// - 'playing' (game.turn): the step's timer, what the player is handed (game.turn.prompt: a text to draw, or a drawing
//   to describe; null in the first step or when there's nothing), and a box to write in or the canvas to draw on. Done
//   hands it in; when the timer runs out, whatever is there is handed in by itself (the server waits a few seconds for
//   it). Then it waits for the others.
// - 'reveal' (game.reveal): the chain being shown, as far as the host has shown it, with the host's Next button. Once
//   every chain has been shown the host can show any of them again, or start a new game.
//
// Drawings are lists of strokes, [colour, size, x, y, x, y, ...], with colour and size places in config.colours and
// config.sizes and the points on a config.width x config.height canvas - the same as the server checks them
// (assets/php/games/doodle/game.php). The canvas is drawn at that size and scaled by CSS. Drawings already loaded are
// kept by entry id (cache) and named in ?have=, so the server doesn't send their strokes again.
//
// Asks what's new every second while the page is in view, and every 4 seconds while it isn't (so the player doesn't
// count as away). The whole game only comes back when its version changed.
//
// The game's settings (game.settings: the time to draw, and how many drawings a chain has before the last guess) are
// shown in their dialog, where the host changes them while no game is being played; everyone else only sees them.
(() => {
    const root = document.querySelector('[data-doodle]');
    if (!root) return;

    const API = '/games/doodle/play';
    const POLL_MS = 1000;
    const HIDDEN_POLL_MS = 4000;
    const TICK_MS = 200;
    const TIMER_LOW_MS = 10000; // the timer turns red from here
    const MIN_MOVE = 2; // canvas pixels the pen has to move before another point is added
    const CACHE_MAX = 40; // drawings kept, and named in ?have= (HBHUB_DOODLE_HAVE_MAX)
    const config = JSON.parse(root.dataset.config);
    const roomId = root.dataset.room;
    const baseTitle = document.title;

    const find = (selector) => root.querySelector(selector);
    const message = find('[data-doodle-message]');
    const settingsName = find('[data-doodle-settings-name]');
    const playerList = find('[data-doodle-players]');
    const waiting = find('[data-doodle-waiting]');
    const waitingTitle = waiting.querySelector('.doodle-panel-title');
    const waitingText = find('[data-doodle-waiting-text]');
    const hostControls = find('[data-doodle-host-controls]');
    const startButton = find('[data-doodle-start]');
    const turnPanel = find('[data-doodle-turn]');
    const turnTitle = find('[data-doodle-turn-title]');
    const stepText = find('[data-doodle-step]');
    const timer = find('[data-doodle-timer]');
    const timerBar = find('[data-doodle-timer-bar]');
    const prompt = find('[data-doodle-prompt]');
    const promptLabel = find('[data-doodle-prompt-label]');
    const promptText = find('[data-doodle-prompt-text]');
    const promptCanvas = find('[data-doodle-prompt-canvas]');
    const writeForm = find('[data-doodle-write]');
    const textInput = find('[data-doodle-text]');
    const writeDone = find('[data-doodle-write-done]');
    const drawArea = find('[data-doodle-draw]');
    const pad = find('[data-doodle-pad]');
    const colourList = find('[data-doodle-colours]');
    const sizeList = find('[data-doodle-sizes]');
    const undoButton = find('[data-doodle-undo]');
    const clearButton = find('[data-doodle-clear]');
    const drawDone = find('[data-doodle-draw-done]');
    const doneText = find('[data-doodle-done]');
    const revealPanel = find('[data-doodle-reveal]');
    const revealTitle = find('[data-doodle-reveal-title]');
    const revealCount = find('[data-doodle-reveal-count]');
    const chainList = find('[data-doodle-chain]');
    const revealActions = find('[data-doodle-reveal-actions]');
    const codeButton = find('[data-doodle-code]');
    const codeCopied = find('[data-doodle-code-copied]');
    const leaveButton = find('[data-doodle-leave]');
    const confirmBox = find('[data-doodle-confirm]');

    let version = null; // the game's version that's shown
    let revision = -1; // the room's revision in it, to tell older answers from newer ones
    let game = null;
    let isBusy = false; // something the player did is on its way
    let isPolling = false;
    let pollTimer = null;
    let timerAt = 0; // when the time left was sent, on performance.now()'s clock
    let turnKey = null; // "game:step" of the step the pad and the text box are for
    let autoSent = null; // the turnKey that's been handed in by itself when the time ran out
    let shownChain = null; // "chain:shown" of what's drawn in the chain list
    const cache = new Map(); // entry id => strokes

    // ----- Talking to the server

    async function request(options, query = '') {
        try {
            const response = await fetch(API + query, { headers: { Accept: 'application/json' }, ...options });
            const data = await response.json().catch(() => null);
            if (response.status === 404 && data?.error === 'notSeated') {
                location.assign('/games/doodle/'); // left in another tab, or gone too long
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

    const haveList = () => [...cache.keys()].join(',');

    // A newer answer may already be shown (one from a hand-in can overtake the check before it), so older ones are
    // dropped. The version starts with "revision-", which only goes up.
    function apply(data) {
        if (data.same) return;
        const dataRevision = parseInt(data.v.split('-')[0], 10);
        if (dataRevision < revision) return;
        version = data.v;
        revision = dataRevision;
        game = data.game;
        timerAt = performance.now();
        render();
    }

    async function poll() {
        if (isPolling) return;
        isPolling = true;
        clearTimeout(pollTimer);

        const query = new URLSearchParams({ room: roomId });
        if (version !== null) query.set('v', version);
        if (cache.size > 0) query.set('have', haveList());
        const ride = { params: null };
        document.dispatchEvent(new CustomEvent('nav:ride', { detail: ride })); // the nav bar's request comes along (navUpdates.js)
        if (ride.params !== null) query.set('nav', ride.params);
        const data = await request({}, '?' + query);
        if (data?.nav) document.dispatchEvent(new CustomEvent('nav:update', { detail: data.nav }));
        if (data !== null && !isBusy) apply(data); // something on its way comes back with its own answer

        isPolling = false;
        pollTimer = setTimeout(poll, document.visibilityState === 'visible' ? POLL_MS : HIDDEN_POLL_MS);
    }

    function post(action, fields = {}) {
        return request({ method: 'POST', body: new URLSearchParams({ room: roomId, action, have: haveList(), ...fields }) });
    }

    // Does something in the game, and shows its error (if any) in shownIn: the stage's message, or the settings dialog's
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

    // ----- Drawings

    function remember(id, strokes) {
        cache.delete(id);
        cache.set(id, strokes);
        while (cache.size > CACHE_MAX) cache.delete(cache.keys().next().value);
    }

    // An entry's strokes: from the answer, or from the cache when the server left them out (cached: true)
    function strokesOf(entry) {
        if (entry.drawing) {
            try {
                const strokes = JSON.parse(entry.drawing);
                remember(entry.id, strokes);
                return strokes;
            } catch {
                return [];
            }
        }
        return cache.get(entry.id) ?? [];
    }

    function styleFor(context, stroke) {
        context.strokeStyle = context.fillStyle = config.colours[stroke[0]] ?? '#000';
        context.lineWidth = config.sizes[stroke[1]] ?? 3;
        context.lineCap = 'round';
        context.lineJoin = 'round';
    }

    function drawStroke(context, stroke) {
        styleFor(context, stroke);
        if (stroke.length === 4) {
            context.beginPath();
            context.arc(stroke[2], stroke[3], context.lineWidth / 2, 0, Math.PI * 2);
            context.fill();
            return;
        }
        context.beginPath();
        context.moveTo(stroke[2], stroke[3]);
        for (let i = 4; i < stroke.length; i += 2) context.lineTo(stroke[i], stroke[i + 1]);
        context.stroke();
    }

    function drawAll(canvas, strokes) {
        const context = canvas.getContext('2d');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        for (const stroke of strokes) drawStroke(context, stroke);
    }

    function viewCanvas(strokes, label) {
        const canvas = document.createElement('canvas');
        canvas.className = 'doodle-canvas is-view';
        canvas.width = config.width;
        canvas.height = config.height;
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', label);
        drawAll(canvas, strokes);
        return canvas;
    }

    // ----- The drawing pad

    let strokes = [];
    let current = null; // the stroke being drawn
    let numbers = 0; // all the numbers in strokes, to stay under config.maxNumbers
    let colour = 0;
    let size = 1;
    const padContext = pad.getContext('2d');

    const canDraw = () => game?.phase === 'playing' && game.turn?.kind === 'draw' && game.turn.inGame && !game.turn.done && !isBusy;

    function pointFrom(event) {
        const rect = pad.getBoundingClientRect();
        const x = Math.round((event.clientX - rect.left) / rect.width * config.width);
        const y = Math.round((event.clientY - rect.top) / rect.height * config.height);
        return [Math.min(config.width, Math.max(0, x)), Math.min(config.height, Math.max(0, y))];
    }

    function resetPad() {
        strokes = [];
        current = null;
        numbers = 0;
        drawAll(pad, strokes);
        renderPadButtons();
    }

    function renderPadButtons() {
        const isEmpty = strokes.length === 0;
        undoButton.disabled = isEmpty || !canDraw();
        clearButton.disabled = isEmpty || !canDraw();
        drawDone.disabled = isEmpty || !canDraw();
    }

    pad.addEventListener('pointerdown', (event) => {
        if (!canDraw() || event.button > 0 || strokes.length >= config.maxStrokes || numbers + 4 > config.maxNumbers) return;
        event.preventDefault();
        pad.setPointerCapture(event.pointerId);
        current = [colour, size, ...pointFrom(event)];
        strokes.push(current);
        numbers += 4;
        drawStroke(padContext, current);
        renderPadButtons();
    });

    pad.addEventListener('pointermove', (event) => {
        if (current === null) return;
        const coalesced = event.getCoalescedEvents?.() ?? []; // the points between frames, for smoother lines (can be empty)
        for (const each of coalesced.length > 0 ? coalesced : [event]) {
            const [x, y] = pointFrom(each);
            const lastX = current[current.length - 2];
            const lastY = current[current.length - 1];
            if (Math.hypot(x - lastX, y - lastY) < MIN_MOVE || numbers + 2 > config.maxNumbers) continue;
            current.push(x, y);
            numbers += 2;
            styleFor(padContext, current);
            padContext.beginPath();
            padContext.moveTo(lastX, lastY);
            padContext.lineTo(x, y);
            padContext.stroke();
        }
    });

    const endStroke = () => (current = null);
    pad.addEventListener('pointerup', endStroke);
    pad.addEventListener('pointercancel', endStroke);

    function undo() {
        const stroke = strokes.pop();
        if (stroke) numbers -= stroke.length;
        current = null;
        drawAll(pad, strokes);
        renderPadButtons();
    }

    undoButton.addEventListener('click', undo);
    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z' && canDraw() && !drawArea.hidden) {
            event.preventDefault();
            undo();
        }
    });

    clearButton.addEventListener('click', async () => {
        if (await confirmDialog('Clear the drawing?', 'This takes away everything you\'ve drawn.', 'Clear')) resetPad();
    });

    // The colours and pen sizes, as radio-like buttons
    function toolButton(list, className, isPicked, label, onPick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.setAttribute('role', 'radio');
        button.setAttribute('aria-checked', String(isPicked));
        button.setAttribute('aria-label', label);
        button.title = label;
        button.addEventListener('click', () => {
            for (const other of list.children) other.setAttribute('aria-checked', 'false');
            button.setAttribute('aria-checked', 'true');
            onPick();
        });
        list.append(button);
        return button;
    }

    config.colours.forEach((hex, index) => {
        const button = toolButton(colourList, 'doodle-colour', index === colour, index === 3 ? 'White (rubber)' : 'Colour ' + hex,
            () => (colour = index));
        button.style.setProperty('--doodle-colour', hex);
    });
    config.sizes.forEach((width, index) => {
        const button = toolButton(sizeList, 'doodle-size', index === size, 'Pen size ' + width, () => (size = index));
        const dot = document.createElement('span');
        dot.className = 'doodle-size-dot';
        dot.style.setProperty('--doodle-size', Math.max(4, Math.min(28, width)) + 'px');
        button.append(dot);
    });

    // ----- Handing in

    async function handIn(isAuto) {
        const turn = game?.turn;
        if (!turn || !turn.inGame || turn.done) return;
        const fields = { step: String(turn.step) };
        if (turn.kind === 'write') {
            const text = textInput.value.trim();
            if (text === '' && !isAuto) return;
            fields.text = text;
        } else {
            if (strokes.length === 0 && !isAuto) return;
            fields.drawing = JSON.stringify(strokes);
        }
        await act('hand', fields);
    }

    writeForm.addEventListener('submit', (event) => {
        event.preventDefault();
        handIn(false);
    });
    drawDone.addEventListener('click', () => handIn(false));
    textInput.addEventListener('input', () => (writeDone.disabled = textInput.value.trim() === '' || isBusy));

    // ----- The timer: counts down from what the server sent, and hands in by itself at zero

    function timeLeft() {
        return game?.turn ? Math.max(0, game.turn.timeLeftMs - (performance.now() - timerAt)) : 0;
    }

    function tick() {
        const turn = game?.phase === 'playing' ? game.turn : null;
        if (turn === null) return;
        const left = timeLeft();
        timerBar.style.width = (100 * left / turn.timeTotalMs) + '%';
        timer.classList.toggle('is-low', left <= TIMER_LOW_MS);
        timer.setAttribute('aria-valuetext', Math.ceil(left / 1000) + ' seconds left');
        if (left <= 0 && turn.inGame && !turn.done && autoSent !== turnKey && !isBusy) {
            autoSent = turnKey;
            handIn(true);
        }
    }
    setInterval(tick, TICK_MS);

    // ----- Drawing the page

    function render() {
        if (game === null) return;
        settingsName.textContent = game.settingsText;
        renderPlayers();
        renderWaiting();
        renderTurn();
        renderReveal();
        if (settingsDialog.open) renderSettings();
        const isMyTurn = game.phase === 'playing' && game.turn.inGame && !game.turn.done;
        document.title = (isMyTurn ? '● Your turn - ' : '') + baseTitle;
    }

    function renderPlayers() {
        playerList.replaceChildren(...game.players.map((player) => {
            const item = document.createElement('li');
            item.className = 'doodle-player' + (player.isYou ? ' is-you' : '') + (player.away ? ' is-away' : '')
                + (player.done ? ' is-done' : '');

            const avatar = document.createElement('span');
            avatar.className = 'doodle-avatar';
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
            text.className = 'doodle-player-text';
            const name = document.createElement('span');
            name.className = 'doodle-player-name';
            name.textContent = player.name + (player.isYou ? ' (you)' : '');
            const status = document.createElement('span');
            status.className = 'doodle-player-status';
            const notes = [];
            if (player.isHost) notes.push('Host');
            if (player.away) notes.push('Away');
            if (game.phase === 'playing' && !player.inGame) notes.push('Watching');
            else if (game.phase === 'playing') notes.push(player.done ? 'Done' : game.turn.kind === 'draw' ? 'Drawing…' : 'Writing…');
            status.textContent = notes.join(' · ');
            text.append(name, status);

            item.append(avatar, text);
            if (player.done) {
                const tick = document.createElement('span');
                tick.className = 'doodle-player-tick';
                tick.setAttribute('aria-hidden', 'true');
                tick.textContent = '✓';
                item.append(tick);
            }
            return item;
        }));
    }

    // The waiting room, and "Play again?" under the chains once they've all been shown
    function renderWaiting() {
        const isFinished = game.phase === 'reveal' && game.reveal.finished;
        waiting.hidden = !(game.phase === 'lobby' || isFinished);
        if (waiting.hidden) return;

        const count = game.players.length;
        const missing = game.minPlayers - count;
        waitingTitle.textContent = isFinished ? 'Play again?' : 'Waiting for players';
        const lines = [count + ' of ' + game.maxPlayers + ' players.'];
        if (missing > 0) lines.push('You need ' + missing + ' more to start. Give your friends the code at the top.');
        if (!game.isHost) lines.push('The host starts the game.');
        if (game.note) lines.unshift(game.note);
        waitingText.textContent = lines.join(' ');

        hostControls.hidden = !game.isHost;
        startButton.textContent = isFinished ? 'New game' : 'Start game';
        startButton.disabled = isBusy || missing > 0;
    }

    startButton.addEventListener('click', () => act('start'));

    // ----- The game's settings: the host changes them while no game is being played, everyone else only sees them

    const settingsDialog = find('[data-doodle-settings-dialog]');
    const settingsForm = find('[data-doodle-settings-form]');
    const settingsMessage = find('[data-doodle-settings-message]');
    const settingsSave = find('[data-doodle-settings-save]');
    const canChangeSettings = () => game.isHost && game.phase !== 'playing';

    // The choices are only filled in from the game when it opens, or while they can't be changed (so a poll doesn't
    // undo what the host has just clicked)
    function fillSettings() {
        settingsForm.elements.drawSeconds.value = String(game.settings.drawSeconds);
        settingsForm.elements.drawings.value = String(game.settings.drawings);
    }

    function renderSettings() {
        const canChange = canChangeSettings();
        if (!canChange) fillSettings();
        settingsForm.querySelectorAll('[data-doodle-settings-fields]').forEach((fields) => (fields.disabled = !canChange));
        settingsSave.hidden = !canChange;
        settingsSave.disabled = isBusy;
        find('[data-doodle-settings-text]').textContent = canChange
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

    root.querySelectorAll('[data-doodle-settings]').forEach((opener) => opener.addEventListener('click', openSettings));
    find('[data-doodle-settings-close]').addEventListener('click', () => settingsDialog.close());
    settingsForm.addEventListener('submit', async (event) => {
        event.preventDefault(); // it's closed once it's saved, not straight away
        const data = await act('settings', {
            drawSeconds: settingsForm.elements.drawSeconds.value,
            drawings: settingsForm.elements.drawings.value,
        }, settingsMessage);
        if (data && !data.error) settingsDialog.close();
    });

    function renderTurn() {
        const turn = game.phase === 'playing' ? game.turn : null;
        turnPanel.hidden = turn === null;
        if (turn === null) {
            turnKey = null;
            return;
        }

        // A new step: a clean pad and an empty box
        const key = turn.game + ':' + turn.step;
        if (key !== turnKey) {
            turnKey = key;
            textInput.value = '';
            resetPad();
            if (turn.inGame && !turn.done && turn.kind === 'write') setTimeout(() => textInput.focus(), 0);
        }

        const isWrite = turn.kind === 'write';
        stepText.textContent = 'Round ' + (turn.step + 1) + ' of ' + turn.steps;
        turnTitle.textContent = turn.step === 0 ? 'Write something to draw'
            : !isWrite ? 'Draw this'
            : turn.step === turn.steps - 1 ? 'Last guess: what is this?' : 'What is this?';

        const isWorking = turn.inGame && !turn.done;
        doneText.hidden = isWorking;
        if (!turn.inGame) {
            doneText.textContent = 'You joined after this game started, so you\'re watching. You\'ll be in the next one.';
        } else if (turn.done) {
            doneText.textContent = 'Handed in! Waiting for the others (' + turn.doneCount + ' of ' + turn.playingCount + ' done)…';
        }

        renderPrompt(turn, isWorking);
        writeForm.hidden = !(isWorking && isWrite);
        textInput.placeholder = turn.step === 0 ? 'Like "a cat riding a skateboard"' : 'Describe the drawing';
        textInput.disabled = isBusy;
        writeDone.disabled = isBusy || textInput.value.trim() === '';
        drawArea.hidden = !(isWorking && !isWrite);
        renderPadButtons();
        tick();
    }

    function renderPrompt(turn, isWorking) {
        const entry = turn.prompt;
        prompt.hidden = !isWorking || turn.step === 0;
        if (prompt.hidden) return;

        promptText.hidden = !(entry && entry.kind === 'text');
        promptCanvas.hidden = !(entry && entry.kind === 'drawing');
        if (entry === null) {
            promptLabel.textContent = turn.kind === 'draw'
                ? 'Nobody wrote anything for this chain, so draw whatever you like.'
                : 'There\'s no drawing to describe this time, so write anything for the next player to draw.';
        } else if (entry.kind === 'text') {
            promptLabel.textContent = 'Draw:';
            promptText.textContent = entry.text;
        } else {
            promptLabel.textContent = 'Describe this drawing:';
            drawAll(promptCanvas, strokesOf(entry));
        }
    }

    function renderReveal() {
        const reveal = game.phase === 'reveal' ? game.reveal : null;
        revealPanel.hidden = reveal === null;
        if (reveal === null) {
            shownChain = null;
            return;
        }

        revealTitle.textContent = 'Chain ' + (reveal.chain + 1) + ': started by ' + reveal.startedBy[reveal.chain];
        revealCount.textContent = reveal.chain + 1 + ' of ' + reveal.chains;

        // Only drawn again when something new is shown, so the page doesn't jump while it's being looked at
        const key = reveal.chain + ':' + reveal.shown;
        if (key !== shownChain) {
            const isNewChain = shownChain === null || shownChain.split(':')[0] !== String(reveal.chain);
            shownChain = key;
            chainList.replaceChildren(...reveal.entries.map(chainItem));
            if (!isNewChain) chainList.lastElementChild?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        revealActions.replaceChildren();
        if (!game.isHost) {
            if (!reveal.finished) revealActions.append(paragraph('The host shows the next one.'));
            return;
        }
        if (!reveal.finished) {
            const isLastInChain = reveal.shown >= reveal.steps;
            const isLastChain = reveal.chain >= reveal.chains - 1;
            revealActions.append(button(isLastInChain ? (isLastChain ? 'Finish' : 'Next chain') : 'Show next', () => act('next'), true));
            return;
        }
        // Finished: any chain can be shown again
        const picker = document.createElement('div');
        picker.className = 'doodle-chain-picker';
        picker.append(paragraph('Show a chain again:'));
        reveal.startedBy.forEach((name, index) => {
            const choice = button(name, () => act('show', { chain: String(index) }), index === reveal.chain);
            choice.setAttribute('aria-pressed', String(index === reveal.chain));
            picker.append(choice);
        });
        revealActions.append(picker);
    }

    function chainItem(entry) {
        const item = document.createElement('li');
        item.className = 'doodle-chain-item is-' + (entry.kind ?? 'missing');
        const by = document.createElement('p');
        by.className = 'doodle-chain-by';
        by.textContent = entry.by + ' ' + ({
            drawing: 'drew:',
            text: entry.step === 0 ? 'wrote:' : 'guessed:',
        }[entry.kind] ?? (entry.step % 2 === 0 ? 'didn\'t write anything.' : 'didn\'t draw anything.'));
        item.append(by);

        if (entry.kind === 'text') {
            const text = document.createElement('p');
            text.className = 'doodle-chain-text';
            text.textContent = entry.text;
            item.append(text);
        } else if (entry.kind === 'drawing') {
            item.append(viewCanvas(strokesOf(entry), 'A drawing by ' + entry.by));
        }
        return item;
    }

    function button(text, onClick, primary = false) {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = 'casino-btn' + (primary ? ' casino-btn-primary' : '');
        element.textContent = text;
        element.disabled = isBusy;
        element.addEventListener('click', onClick);
        return element;
    }

    function paragraph(text) {
        const element = document.createElement('p');
        element.className = 'doodle-hint';
        element.textContent = text;
        return element;
    }

    // ----- Asking first

    function confirmDialog(title, text, okText) {
        find('[data-doodle-confirm-title]').textContent = title;
        find('[data-doodle-confirm-text]').textContent = text;
        find('[data-doodle-confirm-ok]').textContent = okText;
        confirmBox.returnValue = '';
        confirmBox.showModal();
        find('[data-doodle-confirm-cancel]').focus(); // the safe choice is the one Enter picks
        return new Promise((resolve) => {
            confirmBox.addEventListener('close', () => resolve(confirmBox.returnValue === 'ok'), { once: true });
        });
    }
    find('[data-doodle-confirm-cancel]').addEventListener('click', () => confirmBox.close('cancel'));
    find('[data-doodle-confirm-ok]').addEventListener('click', () => confirmBox.close('ok'));

    // ----- The join code: a click copies it, to send to others

    codeButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(codeButton.dataset.doodleCode);
            codeCopied.textContent = 'Copied';
        } catch {
            codeCopied.textContent = ''; // no clipboard access: the code is right there to read
        }
        setTimeout(() => (codeCopied.textContent = ''), 2000);
    });

    // ----- Leaving: the others play on, so it's only asked during a game the player is in

    leaveButton.addEventListener('click', async () => {
        if (game?.phase === 'playing' && game.turn.inGame
            && !await confirmDialog('Leave the game?', 'The others play on without you.', 'Leave')) {
            return;
        }
        const data = await post('leave');
        if (data?.left) {
            location.assign('/games/doodle/');
        } else {
            message.textContent = 'Could not leave the game. Please try again.';
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') poll(); // catch up when coming back to the tab
    });
    drawAll(pad, strokes);
    poll();
})();
