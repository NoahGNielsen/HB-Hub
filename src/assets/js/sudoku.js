// Sudoku (games/sudoku/): draws the board, and handles the number pad, the tools, the keyboard and the clock. The
// puzzles are made and checked on HB Hub's server (assets/php/games/sudoku/sudoku.php), so the leaderboard can trust
// them: this script never knows the answer. It talks to games/sudoku/move (form-encoded POSTs with action=):
//   new (a new game on a level), hint (the right number for a square), pause / resume (the server's clock, so the
//   leaderboard's time leaves out pauses and time away from the page, like the clock here) and solve (the full board,
//   checked once there are no clashes).
// The page hands it what the server has in data-sudoku-state: {current: {game, level, puzzle, hints, ms} or null,
// best: {level: ms}}.
//
// A board is 81 squares, row by row (index = row * 9 + column), each 0 (empty) or 1-9. The numbers and notes you put
// in are kept in localStorage under STORAGE_KEY, so a game can be closed and picked up later in the same browser:
//   {game: {id, level, puzzle: '81 digits', values: '81 digits' (0 = empty), notes: [81 bit masks, bit n = note n],
//    ms: time played, hints, solved, resultText}}
// Undo only lasts as long as the page.
(() => {
    const root = document.querySelector('[data-sudoku]');
    if (!root) return;

    const STORAGE_KEY = 'hbHubSudoku';
    const MOVE_URL = '/games/sudoku/move';
    const BOARD_URL = '/games/sudoku/leaderboard';
    const ALL = 0x3fe; // bits 1-9: every number
    const LEVEL_NAMES = { easy: 'Easy', medium: 'Medium', hard: 'Hard', expert: 'Expert' }; // HBHUB_SUDOKU_LEVELS
    const RESULT = { solved: 1, hinted: 2, tooFast: 4 }; // gameResult in HBHub-SudokuGames
    const SAVE_EVERY_MS = 5000;
    const SOLVE_RETRIES = 5;

    const board = root.querySelector('[data-sudoku-board]');
    const cover = root.querySelector('[data-sudoku-cover]');
    const coverText = root.querySelector('[data-sudoku-cover-text]');
    const resumeButton = root.querySelector('[data-sudoku-resume]');
    const timeText = root.querySelector('[data-sudoku-time]');
    const levelText = root.querySelector('[data-sudoku-level]');
    const pauseButton = root.querySelector('[data-sudoku-pause]');
    const message = root.querySelector('[data-sudoku-message]');
    const result = root.querySelector('[data-sudoku-result]');
    const resultText = root.querySelector('[data-sudoku-result-text]');
    const resultBoard = root.querySelector('[data-sudoku-result-board]');
    const leaderboardButton = root.querySelector('[data-sudoku-leaderboard]');
    const notesState = root.querySelector('[data-sudoku-notes-state]');
    const notesButton = root.querySelector('[data-sudoku-tool="notes"]');
    const confirmDialog = root.querySelector('[data-sudoku-confirm]');
    const confirmText = root.querySelector('[data-sudoku-confirm-text]');
    const padKeys = [...root.querySelectorAll('[data-sudoku-digit]')];

    let serverState = {};
    try {
        serverState = JSON.parse(root.dataset.sudokuState || '{}') || {};
    } catch {
        // nothing from the server: a new game is started below
    }
    const best = serverState.best && typeof serverState.best === 'object' ? serverState.best : {};

    const rowOf = (i) => Math.floor(i / 9);
    const colOf = (i) => i % 9;
    const boxOf = (i) => Math.floor(rowOf(i) / 3) * 3 + Math.floor(colOf(i) / 3);

    // Every square's 20 neighbours (same row, column or box), worked out once
    const PEERS = Array.from({ length: 81 }, (_, i) => {
        const peers = [];
        for (let j = 0; j < 81; j++) {
            if (j !== i && (rowOf(j) === rowOf(i) || colOf(j) === colOf(i) || boxOf(j) === boxOf(i))) peers.push(j);
        }
        return peers;
    });

    const digitsOf = (text) => (typeof text === 'string' && /^[0-9]{81}$/.test(text) ? [...text].map(Number) : null);
    const boardUrl = (level) => `${BOARD_URL}?level=${encodeURIComponent(level)}`;

    // ---- The server ----

    /**
     * One POST to games/sudoku/move. Resolves with the answer, or rejects with an Error whose code is the server's
     * error ('gameOver', 'blocked', ...) or 'network'.
     */
    async function post(action, fields = {}, keepalive = false) {
        let response;
        try {
            response = await fetch(MOVE_URL, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ action, ...fields }),
                keepalive,
            });
        } catch {
            throw Object.assign(new Error('network'), { code: 'network' });
        }
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.error) {
            const code = data?.error || 'network';
            throw Object.assign(new Error(code), { code });
        }
        return data;
    }

    // Requests go one at a time, in order, so a pause and the carry on after it can't arrive the wrong way round
    let queue = Promise.resolve();
    const send = (action, fields, keepalive) => {
        const request = queue.catch(() => {}).then(() => post(action, fields, keepalive));
        queue = request;
        return request;
    };

    // ---- The saved game ----

    function load() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
            if (saved && typeof saved === 'object') return saved;
        } catch {
            // no storage, or something broken in it
        }
        return {};
    }

    const store = { game: load().game ?? null }; // (best times and wins used to be kept here; they're on the server now)

    function save() {
        if (game) store.game = { ...game, puzzle: game.puzzle.join(''), values: game.values.join('') };
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(store));
        } catch {
            // no storage: the numbers only last as long as the page
        }
    }

    /**
     * The game to play: the server's game being played, with the numbers and notes from this browser when it has the
     * same game saved. Or the last solved game saved here, to show (when nothing is being played). Null for neither.
     */
    function startingGame() {
        const saved = store.game && typeof store.game === 'object' ? store.game : null;
        const current = serverState.current;
        if (current && LEVEL_NAMES[current.level]) {
            const puzzle = digitsOf(current.puzzle);
            if (puzzle) {
                const same = saved && saved.id === current.game && saved.puzzle === current.puzzle;
                let values = same ? digitsOf(saved.values) : null;
                if (!values || values.some((v, i) => puzzle[i] && v !== puzzle[i])) values = puzzle.slice();
                const notes = same && Array.isArray(saved.notes) && saved.notes.length === 81
                    ? saved.notes.map((n) => Number(n) & ALL) : new Array(81).fill(0);
                return {
                    id: current.game, level: current.level, puzzle, values, notes,
                    ms: Math.max(0, Number(current.ms) || 0), hints: Math.max(0, Number(current.hints) || 0),
                    solved: false, resultText: '',
                };
            }
        }
        if (saved && saved.solved === true && LEVEL_NAMES[saved.level]) {
            const puzzle = digitsOf(saved.puzzle);
            const values = digitsOf(saved.values);
            if (puzzle && values) {
                return {
                    id: saved.id, level: saved.level, puzzle, values, notes: new Array(81).fill(0),
                    ms: Math.max(0, Number(saved.ms) || 0), hints: Math.max(0, Number(saved.hints) || 0),
                    solved: true, resultText: typeof saved.resultText === 'string' ? saved.resultText : '',
                };
            }
        }
        return null;
    }

    // ---- The game ----

    let game = null;
    let selected = -1;
    let notesMode = false;
    let paused = false;
    let busy = false; // waiting on the server for a new game, a hint or the check, so nothing else can be done
    let ended = ''; // why the game can't go on (ended in another tab, games closed), shown on the cover
    let undo = []; // [{i, value, notes: [81]}] before each move
    let runningSince = 0; // performance.now() when the clock last started, 0 while stopped
    let serverRunning = null; // whether the server's clock was last told to run (null: not known yet)
    let lastSave = 0;
    let messageTimer = 0;

    const cells = [];
    for (let i = 0; i < 81; i++) {
        if (i % 9 === 0) {
            const row = document.createElement('div');
            row.className = 'sudoku-row';
            row.setAttribute('role', 'row');
            board.append(row);
        }
        const cell = document.createElement('button');
        cell.type = 'button';
        cell.className = 'sudoku-cell';
        cell.setAttribute('role', 'gridcell');
        cell.tabIndex = -1;
        cell.dataset.index = i;
        board.lastElementChild.append(cell);
        cells.push(cell);
    }

    function say(text, keepFor = 2500) {
        clearTimeout(messageTimer);
        message.textContent = text;
        if (keepFor) messageTimer = setTimeout(() => { message.textContent = ''; }, keepFor);
    }

    const formatTime = (ms) => {
        const seconds = Math.floor(ms / 1000);
        const h = Math.floor(seconds / 3600);
        const m = Math.floor(seconds / 60) % 60;
        const s = String(seconds % 60).padStart(2, '0');
        return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
    };

    const playedMs = () => game.ms + (runningSince ? performance.now() - runningSince : 0);
    const isPlaying = () => game && !game.solved && !ended;

    /**
     * What to say when the server says no: the game was ended elsewhere, or the games are closed to the user. Returns
     * whether it was one of those (otherwise it's a hiccup to try again).
     */
    function endedBy(error) {
        const reasons = {
            gameOver: 'This game was ended, maybe by a new one in another tab. Pick a level above for a new one.',
            notFound: 'This game is gone. Pick a level above for a new one.',
            blocked: 'Games are blocked for you right now.',
            closed: 'Games aren\'t open right now.',
        };
        if (!reasons[error?.code]) return false;
        ended = reasons[error.code];
        if (game && runningSince) game.ms = playedMs();
        runningSince = 0;
        serverRunning = false;
        busy = false;
        store.game = null; // nothing to pick up later
        try { localStorage.removeItem(STORAGE_KEY); } catch { /* no storage */ }
        draw();
        return true;
    }

    // The clock runs while a game is being played, the page is on screen and it isn't paused. The server's clock is
    // told to stop and start with it (the stop with keepalive, as it's often sent as the page is being closed).
    function updateClock() {
        const shouldRun = isPlaying() && !busy && !paused && document.visibilityState === 'visible';
        if (shouldRun && !runningSince) runningSince = performance.now();
        if (!shouldRun && runningSince) {
            game.ms = playedMs();
            runningSince = 0;
            save();
        }
        timeText.textContent = formatTime(game ? playedMs() : 0);

        // A check that's under way stops the server's clock by itself, so it isn't paused for that
        if (!isPlaying() || busy || serverRunning === shouldRun) return;
        serverRunning = shouldRun;
        const id = game.id;
        send(shouldRun ? 'resume' : 'pause', { game: id }, !shouldRun)
            .then((answer) => {
                // The server's time is the one that counts, so the clock here follows it
                if (game?.id !== id || !Number.isFinite(answer.ms)) return;
                game.ms = answer.ms;
                if (runningSince) runningSince = performance.now();
                timeText.textContent = formatTime(playedMs());
            })
            .catch((error) => {
                if (game?.id !== id || endedBy(error)) return;
                serverRunning = null; // try again with the next change
            });
    }

    // Which squares break the rules: the same number twice in a row, column or box
    function conflicts() {
        const bad = new Set();
        for (let i = 0; i < 81; i++) {
            const v = game.values[i];
            if (v && PEERS[i].some((p) => game.values[p] === v)) bad.add(i);
        }
        return bad;
    }

    function draw() {
        drawBest();
        if (!game) {
            cover.hidden = !ended;
            if (ended) { coverText.textContent = ended; resumeButton.hidden = true; }
            return;
        }
        const bad = conflicts();
        const pickedValue = selected >= 0 ? game.values[selected] : 0;
        cells.forEach((cell, i) => {
            const value = game.values[i];
            const given = game.puzzle[i] !== 0;
            const related = selected >= 0 && i !== selected && PEERS[selected].includes(i);
            cell.classList.toggle('is-given', given);
            cell.classList.toggle('is-selected', i === selected);
            cell.classList.toggle('is-related', related);
            cell.classList.toggle('is-same', !!pickedValue && value === pickedValue && i !== selected);
            cell.classList.toggle('is-conflict', bad.has(i) && !given);
            cell.classList.toggle('is-conflict-given', bad.has(i) && given);
            cell.tabIndex = i === (selected >= 0 ? selected : 0) ? 0 : -1;

            const where = `Row ${rowOf(i) + 1}, column ${colOf(i) + 1}`;
            if (value) {
                cell.replaceChildren(String(value));
                cell.setAttribute('aria-label', `${where}: ${value}${given ? ', given' : ''}${bad.has(i) ? ', clashes' : ''}`);
            } else if (game.notes[i]) {
                const notes = document.createElement('span');
                notes.className = 'sudoku-notes';
                const list = [];
                for (let d = 1; d <= 9; d++) {
                    const note = document.createElement('span');
                    const on = !!(game.notes[i] & (1 << d));
                    note.textContent = on ? d : '';
                    note.classList.toggle('is-same', on && d === pickedValue);
                    notes.append(note);
                    if (on) list.push(d);
                }
                cell.replaceChildren(notes);
                cell.setAttribute('aria-label', `${where}: empty, notes ${list.join(' ')}`);
            } else {
                cell.replaceChildren();
                cell.setAttribute('aria-label', `${where}: empty`);
            }
        });

        // How many of each number are still to go, on the pad
        const placed = new Array(10).fill(0);
        game.values.forEach((v) => { placed[v]++; });
        const locked = !isPlaying() || paused || busy;
        padKeys.forEach((key) => {
            const d = Number(key.dataset.sudokuDigit);
            const left = Math.max(0, 9 - placed[d]);
            key.querySelector('[data-sudoku-left]').textContent = left || '';
            key.classList.toggle('is-done', left === 0);
            key.setAttribute('aria-label', `${d}${left ? `, ${left} left` : ', all placed'}`);
            key.disabled = locked;
        });

        levelText.textContent = LEVEL_NAMES[game.level];
        notesState.textContent = notesMode ? 'on' : 'off';
        notesButton.setAttribute('aria-pressed', String(notesMode));
        notesButton.classList.toggle('is-on', notesMode);
        root.querySelectorAll('[data-sudoku-tool]').forEach((button) => { button.disabled = locked; });
        pauseButton.disabled = !isPlaying() || busy;
        pauseButton.textContent = paused ? 'Carry on' : 'Pause';
        pauseButton.setAttribute('aria-pressed', String(paused));
        if (ended) {
            coverText.textContent = ended;
            resumeButton.hidden = true;
        }
        cover.hidden = !paused && !ended;
        board.classList.toggle('is-paused', paused || !!ended);
        board.classList.toggle('is-solved', game.solved);
        result.hidden = !game.solved;
        if (game.solved) {
            resultText.textContent = game.resultText || `You solved this ${LEVEL_NAMES[game.level]} puzzle in ${formatTime(game.ms)}.`;
            resultBoard.href = boardUrl(game.level);
        }
        leaderboardButton?.setAttribute('href', boardUrl(game.level));
    }

    function drawBest() {
        root.querySelectorAll('[data-sudoku-best]').forEach((span) => {
            const level = span.dataset.sudokuBest;
            span.textContent = best[level] ? `Best ${formatTime(best[level])}` : 'No wins yet';
            span.closest('.sudoku-level').classList.toggle('is-active', game?.level === level && isPlaying());
        });
    }

    function select(i, focus = true) {
        selected = i;
        draw();
        if (focus && i >= 0) cells[i].focus();
    }

    function remember(i) {
        undo.push({ i, value: game.values[i], notes: game.notes.slice() });
        if (undo.length > 500) undo.shift();
    }

    const canPlay = () => isPlaying() && !paused && !busy;

    // Puts a number in the selected square (or a note, in notes mode)
    function enter(digit) {
        if (!canPlay()) return;
        if (selected < 0) { say('Pick a square first.'); return; }
        if (game.puzzle[selected]) { say('That number was given, it can\'t be changed.'); return; }
        const i = selected;
        if (notesMode) {
            if (game.values[i]) { say('Erase the number first to write notes there.'); return; }
            remember(i);
            game.notes[i] ^= 1 << digit;
        } else {
            if (game.values[i] === digit) return;
            remember(i);
            game.values[i] = digit;
            game.notes[i] = 0;
            for (const p of PEERS[i]) game.notes[p] &= ~(1 << digit); // it can't be in the notes around it any more
        }
        changed();
    }

    function erase() {
        if (!canPlay() || selected < 0 || game.puzzle[selected]) return;
        if (!game.values[selected] && !game.notes[selected]) return;
        remember(selected);
        game.values[selected] = 0;
        game.notes[selected] = 0;
        changed();
    }

    function takeBack() {
        if (!canPlay()) return;
        const last = undo.pop();
        if (!last) { say('Nothing to undo.'); return; }
        game.values[last.i] = last.value;
        game.notes = last.notes;
        selected = last.i;
        changed(false);
        cells[last.i].focus();
    }

    // Asks the server for the right number in the selected square (or a random empty or wrong one if that's right)
    async function hint() {
        if (!canPlay()) return;
        if (!game.values.includes(0) && conflicts().size === 0) return; // being checked already
        busy = true;
        draw();
        updateClock();
        const id = game.id;
        try {
            const answer = await send('hint', { game: id, square: selected, values: game.values.join('') });
            if (game?.id !== id) return;
            const i = answer.square;
            const digit = answer.digit;
            busy = false;
            remember(i);
            game.values[i] = digit;
            game.notes[i] = 0;
            for (const p of PEERS[i]) game.notes[p] &= ~(1 << digit);
            game.hints = answer.hints;
            selected = i;
            changed();
            cells[i].focus();
            say(`Hint: row ${rowOf(i) + 1}, column ${colOf(i) + 1} is ${digit}. A game with a hint doesn't go on the leaderboard.`, 4000);
        } catch (error) {
            busy = false;
            if (game?.id === id && !endedBy(error)) {
                say('Couldn\'t get a hint from HB Hub. Try again in a moment.', 4000);
                draw();
            }
        } finally {
            if (game?.id === id) updateClock();
        }
    }

    // The board is full without clashes: the server checks it and ends the game (tries again on a hiccup)
    async function solve(tries = 0) {
        busy = true;
        updateClock(); // stops the clock here; the server stops its own when it checks
        draw();
        const id = game.id;
        const values = game.values.join('');
        try {
            const answer = await send('solve', { game: id, values });
            if (game?.id !== id) return;
            busy = false;
            game.solved = true;
            game.ms = answer.ms;
            game.hints = answer.hints;
            undo = [];
            if (answer.best) best[game.level] = answer.best;
            const name = LEVEL_NAMES[game.level];
            const time = formatTime(answer.ms);
            if (answer.result === RESULT.tooFast) {
                game.resultText = `You solved it in ${time}, but that's faster than a person can fill in the board, so it doesn't count on the leaderboard. No solvers or scripts, please.`;
            } else if (answer.result === RESULT.hinted) {
                game.resultText = `You solved the ${name} puzzle in ${time} with ${answer.hints} hint${answer.hints === 1 ? '' : 's'}. `
                    + 'A game with a hint doesn\'t go on the leaderboard, so try the next one without.';
            } else {
                game.resultText = `You solved the ${name} puzzle in ${time} without a hint. `
                    + (answer.isBest ? 'That\'s your best time on this level!' : `Your best is ${formatTime(answer.best)}.`)
                    + ` You've solved ${answer.solved} on ${name}.`;
            }
            say('Solved!', 0);
            draw();
            save();
            updateClock();
            result.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
        } catch (error) {
            if (game?.id !== id) return;
            busy = false;
            if (endedBy(error)) return;
            if (error.code === 'notSolved' || error.code === 'badRequest') {
                say('That isn\'t the answer. Some numbers must be wrong.', 4000);
            } else if (tries < SOLVE_RETRIES) {
                say('Couldn\'t reach HB Hub to check your board. Trying again…', 0);
                busy = true; // still waiting for the check (the board can't change meanwhile)
                setTimeout(() => { if (game?.id === id && !game.solved && !ended) solve(tries + 1); }, 3000);
                return;
            } else {
                say('Couldn\'t reach HB Hub to check your board. Change a square and put it back to try again.', 0);
            }
            draw();
            updateClock();
        }
    }

    // After every move: is the board full without clashes? Then it's checked. Draw and save either way
    function changed(checkWin = true) {
        const full = game.values.every(Boolean);
        const clean = full && conflicts().size === 0;
        draw();
        save();
        if (checkWin && clean) {
            solve();
            return;
        }
        if (checkWin && full) say('The board is full, but some numbers clash.', 4000);
        updateClock();
    }

    function setGame(answer) {
        game = {
            id: answer.game, level: answer.level, puzzle: digitsOf(answer.puzzle), values: digitsOf(answer.puzzle),
            notes: new Array(81).fill(0), ms: answer.ms || 0, hints: answer.hints || 0, solved: false, resultText: '',
        };
        runningSince = 0;
        undo = [];
        paused = false;
        notesMode = false;
        ended = '';
        selected = -1;
    }

    async function startGame(level) {
        if (busy && game) return;
        busy = true;
        coverText.textContent = 'Making a puzzle…';
        resumeButton.hidden = true;
        cover.hidden = false;
        board.classList.add('is-paused');
        if (runningSince) { game.ms = playedMs(); runningSince = 0; }
        try {
            const answer = await send('new', { level });
            busy = false;
            setGame(answer);
            serverRunning = true; // a new game's clock starts running on the server
            coverText.textContent = 'Paused';
            resumeButton.hidden = false;
            message.textContent = '';
            draw();
            save();
            updateClock();
            select(game.values.indexOf(0), false);
        } catch (error) {
            busy = false;
            if (endedBy(error)) return;
            coverText.textContent = 'Paused';
            resumeButton.hidden = false;
            if (game) { // the game from before carries on
                draw();
                updateClock();
                say('Couldn\'t start a new game, HB Hub didn\'t answer. Try again in a moment.', 5000);
            } else {
                coverText.textContent = 'Couldn\'t start a game, HB Hub didn\'t answer. Pick a level above to try again.';
                cover.hidden = false;
            }
        }
    }

    // A new game, asking first if one with moves in it is being played
    function askNewGame(level) {
        const inProgress = isPlaying() && game.values.some((v, i) => v && !game.puzzle[i]);
        if (!inProgress || typeof confirmDialog.showModal !== 'function') {
            startGame(level);
            return;
        }
        confirmText.textContent = `Your ${LEVEL_NAMES[game.level]} game will be gone, and a new ${LEVEL_NAMES[level]} one starts.`;
        confirmDialog.returnValue = '';
        confirmDialog.addEventListener('close', () => {
            if (confirmDialog.returnValue === 'yes') startGame(level);
        }, { once: true });
        confirmDialog.showModal();
    }

    function setPaused(on) {
        if (!isPlaying() || busy) return;
        paused = on;
        draw();
        updateClock();
        if (!on && selected >= 0) cells[selected].focus();
    }

    // ---- Input ----

    board.addEventListener('click', (event) => {
        const cell = event.target.closest('.sudoku-cell');
        if (cell && game && !paused && !ended) select(Number(cell.dataset.index));
    });

    root.addEventListener('click', (event) => {
        const digit = event.target.closest('[data-sudoku-digit]');
        if (digit) { enter(Number(digit.dataset.sudokuDigit)); return; }
        const tool = event.target.closest('[data-sudoku-tool]');
        if (tool) {
            const name = tool.dataset.sudokuTool;
            if (name === 'undo') takeBack();
            if (name === 'erase') erase();
            if (name === 'hint') hint();
            if (name === 'notes') { notesMode = !notesMode; draw(); }
            return;
        }
        const newGame = event.target.closest('[data-sudoku-new]');
        if (newGame) askNewGame(newGame.dataset.sudokuNew);
    });

    pauseButton.addEventListener('click', () => setPaused(!paused));
    resumeButton.addEventListener('click', () => setPaused(false));

    document.addEventListener('keydown', (event) => {
        if (!game || event.altKey || event.metaKey) return;
        if (event.target instanceof Element && event.target.closest('input, textarea, select, [contenteditable], dialog')) return;
        if (confirmDialog.open) return;
        if (event.ctrlKey) {
            if (event.key.toLowerCase() === 'z') { event.preventDefault(); takeBack(); }
            return;
        }
        // Typing only counts while the board is in use: focus on the board or the pad, or nothing focused
        const active = document.activeElement;
        const onGame = !active || active === document.body || root.querySelector('.sudoku-play').contains(active);
        if (!onGame) return;

        if (/^[1-9]$/.test(event.key)) { event.preventDefault(); enter(Number(event.key)); return; }
        if (['Backspace', 'Delete', '0'].includes(event.key)) { event.preventDefault(); erase(); return; }
        if (event.key.toLowerCase() === 'n') { notesMode = !notesMode; draw(); return; }
        const moves = { ArrowUp: -9, ArrowDown: 9, ArrowLeft: -1, ArrowRight: 1 };
        if (event.key in moves && !paused && !ended) {
            event.preventDefault();
            const from = selected >= 0 ? selected : 0;
            let row = rowOf(from), col = colOf(from);
            if (event.key === 'ArrowUp') row = (row + 8) % 9;
            if (event.key === 'ArrowDown') row = (row + 1) % 9;
            if (event.key === 'ArrowLeft') col = (col + 8) % 9;
            if (event.key === 'ArrowRight') col = (col + 1) % 9;
            select(selected >= 0 ? row * 9 + col : 0);
        }
    });

    document.addEventListener('visibilitychange', updateClock);
    window.addEventListener('pagehide', () => {
        if (game && runningSince) { game.ms = playedMs(); runningSince = performance.now(); }
        save();
    });

    setInterval(() => {
        if (!game) return;
        timeText.textContent = formatTime(playedMs());
        if (runningSince && performance.now() - lastSave > SAVE_EVERY_MS) {
            lastSave = performance.now();
            game.ms = playedMs();
            runningSince = performance.now();
            save();
        }
    }, 250);

    // ---- Start: the game being played, the last one solved, or a new Medium one ----

    game = startingGame();
    if (game) {
        if (!game.solved && game.values.some((v, i) => v && !game.puzzle[i])) {
            say('Your game from last time. Pick a level above for a new one.', 4000);
        }
        draw();
        save();
        updateClock(); // tells the server's clock to carry on (it was paused when the page was left)
        if (!game.solved) select(game.values.indexOf(0), false);
        // A board that was full and right when the page closed, before its check got through
        if (isPlaying() && game.values.every(Boolean) && conflicts().size === 0) solve();
    } else {
        draw();
        startGame('medium');
    }
})();
