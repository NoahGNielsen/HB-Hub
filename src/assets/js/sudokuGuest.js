// The free Sudoku for guests (/play/sudoku, guestGames/sudoku/index.php): makes the puzzles, draws the board, and
// handles the number pad, the tools, the keyboard and the clock. It all happens in the browser; nothing is sent to the
// server. It's the members' Sudoku as it was before that moved to the server for its leaderboard (assets/js/sudoku.js),
// kept apart so the two can change on their own, with its own STORAGE_KEY so the two never read each other's game.
//
// A board is 81 squares, row by row (index = row * 9 + column), each 0 (empty) or 1-9. A new puzzle starts from a
// random full board (solve() on an empty one, trying the numbers in a random order), then takes out squares in
// mirrored pairs, putting a pair back whenever the puzzle would get a second answer (countSolutions()) or, on Easy and
// Medium, would need more than singles to solve (solvesWithSingles()). DIFFICULTIES has how far each level goes.
//
// The game and the best times are kept in localStorage under STORAGE_KEY:
//   {game: {level, puzzle: '81 digits', solution: '81 digits', values: '81 digits' (0 = empty), notes: [81 bit masks,
//    bit n = note n], ms: time played, hints, solved}, best: {level: ms}, wins: {level: count}}
// so a game can be closed and picked up later. Undo only lasts as long as the page.
(() => {
    const root = document.querySelector('[data-sudoku]');
    if (!root) return;

    const STORAGE_KEY = 'hbHubSudokuGuest';
    const ALL = 0x3fe; // bits 1-9: every number
    // clues: the fewest numbers a puzzle may start with (fewer is harder). It often ends a few above that, as squares
    // only come out while the answer stays unique (Hard usually ends at 28-30, Expert at 22-25).
    // singles: Easy and Medium have to be solvable with only naked singles ('naked') or naked and hidden singles
    // ('hidden'); Expert must not be ('none': it makes EXPERT_TRIES boards and keeps the one with the fewest numbers)
    const DIFFICULTIES = {
        easy: { name: 'Easy', clues: 38, singles: 'naked' },
        medium: { name: 'Medium', clues: 32, singles: 'hidden' },
        hard: { name: 'Hard', clues: 27, singles: null },
        expert: { name: 'Expert', clues: 22, singles: 'none' },
    };
    const EXPERT_TRIES = 12;
    const SAVE_EVERY_MS = 5000;

    const board = root.querySelector('[data-sudoku-board]');
    const cover = root.querySelector('[data-sudoku-cover]');
    const coverText = root.querySelector('[data-sudoku-cover-text]');
    const timeText = root.querySelector('[data-sudoku-time]');
    const levelText = root.querySelector('[data-sudoku-level]');
    const pauseButton = root.querySelector('[data-sudoku-pause]');
    const message = root.querySelector('[data-sudoku-message]');
    const result = root.querySelector('[data-sudoku-result]');
    const resultText = root.querySelector('[data-sudoku-result-text]');
    const notesState = root.querySelector('[data-sudoku-notes-state]');
    const notesButton = root.querySelector('[data-sudoku-tool="notes"]');
    const confirmDialog = root.querySelector('[data-sudoku-confirm]');
    const confirmText = root.querySelector('[data-sudoku-confirm-text]');
    const padKeys = [...root.querySelectorAll('[data-sudoku-digit]')];

    const rowOf = (i) => Math.floor(i / 9);
    const colOf = (i) => i % 9;
    const boxOf = (i) => Math.floor(rowOf(i) / 3) * 3 + Math.floor(colOf(i) / 3);
    const bitCount = (mask) => { let n = 0; while (mask) { mask &= mask - 1; n++; } return n; };
    const lowestBit = (mask) => 31 - Math.clz32(mask & -mask); // the number of a mask with one bit

    // Every square's 20 neighbours (same row, column or box), worked out once
    const PEERS = Array.from({ length: 81 }, (_, i) => {
        const peers = [];
        for (let j = 0; j < 81; j++) {
            if (j !== i && (rowOf(j) === rowOf(i) || colOf(j) === colOf(i) || boxOf(j) === boxOf(i))) peers.push(j);
        }
        return peers;
    });
    // The 27 rows, columns and boxes, as lists of squares
    const UNITS = [];
    for (let n = 0; n < 9; n++) {
        UNITS.push([...Array(9)].map((_, k) => n * 9 + k));
        UNITS.push([...Array(9)].map((_, k) => k * 9 + n));
        UNITS.push([...Array(9)].map((_, k) => (Math.floor(n / 3) * 3 + Math.floor(k / 3)) * 9 + (n % 3) * 3 + (k % 3)));
    }

    function shuffle(list) {
        for (let i = list.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [list[i], list[j]] = [list[j], list[i]];
        }
        return list;
    }

    /**
     * Backtracking search on a board (an array of 81, changed while it runs, left as it was). Always fills the square
     * with the fewest options next. Counts the answers up to `limit`, and copies the first into `first` if given.
     */
    function search(grid, limit, first = null, random = false) {
        const rows = new Array(9).fill(0);
        const cols = new Array(9).fill(0);
        const boxes = new Array(9).fill(0);
        for (let i = 0; i < 81; i++) {
            if (!grid[i]) continue;
            const bit = 1 << grid[i];
            if ((rows[rowOf(i)] | cols[colOf(i)] | boxes[boxOf(i)]) & bit) return 0; // breaks the rules already
            rows[rowOf(i)] |= bit;
            cols[colOf(i)] |= bit;
            boxes[boxOf(i)] |= bit;
        }

        let count = 0;
        const step = () => {
            let best = -1;
            let bestMask = 0;
            let bestCount = 10;
            for (let i = 0; i < 81; i++) {
                if (grid[i]) continue;
                const mask = ALL & ~(rows[rowOf(i)] | cols[colOf(i)] | boxes[boxOf(i)]);
                const n = bitCount(mask);
                if (n < bestCount) {
                    best = i;
                    bestMask = mask;
                    bestCount = n;
                    if (n <= 1) break;
                }
            }
            if (best === -1) { // full
                count++;
                if (first && count === 1) first.splice(0, 81, ...grid);
                return;
            }
            const digits = [];
            for (let d = 1; d <= 9; d++) if (bestMask & (1 << d)) digits.push(d);
            if (random) shuffle(digits);
            const r = rowOf(best), c = colOf(best), b = boxOf(best);
            for (const d of digits) {
                const bit = 1 << d;
                grid[best] = d;
                rows[r] |= bit; cols[c] |= bit; boxes[b] |= bit;
                step();
                rows[r] &= ~bit; cols[c] &= ~bit; boxes[b] &= ~bit;
                grid[best] = 0;
                if (count >= limit) return;
            }
        };
        step();
        return count;
    }

    const countSolutions = (grid, limit = 2) => search(grid, limit);

    /**
     * Whether a puzzle can be solved by singles alone: a square with only one number left (naked single), and with
     * `hidden`, a number with only one square left in a row, column or box (hidden single).
     */
    function solvesWithSingles(puzzle, hidden) {
        const grid = puzzle.slice();
        const options = (i) => {
            let used = 0;
            for (const p of PEERS[i]) used |= 1 << grid[p];
            return ALL & ~used;
        };
        let progress = true;
        while (progress) {
            progress = false;
            for (let i = 0; i < 81; i++) {
                if (grid[i]) continue;
                const mask = options(i);
                if (!mask) return false;
                if (bitCount(mask) === 1) { grid[i] = lowestBit(mask); progress = true; }
            }
            if (progress || !hidden) continue;
            for (const unit of UNITS) {
                for (let d = 1; d <= 9; d++) {
                    if (unit.some((i) => grid[i] === d)) continue;
                    const spots = unit.filter((i) => !grid[i] && options(i) & (1 << d));
                    if (spots.length === 1) { grid[spots[0]] = d; progress = true; }
                }
            }
        }
        return grid.every(Boolean);
    }

    /**
     * A new puzzle for a level: {puzzle, solution}, both arrays of 81.
     */
    function makePuzzle(level) {
        const { clues, singles } = DIFFICULTIES[level];
        const isExpert = singles === 'none';
        let best = null; // Expert keeps the board with the fewest numbers that singles alone can't solve
        for (let attempt = 0; attempt < (isExpert ? EXPERT_TRIES : 1); attempt++) {
            const solution = new Array(81).fill(0);
            search(new Array(81).fill(0), 1, solution, true);
            const puzzle = solution.slice();
            let left = 81;
            // Try taking a group out; it stays out if the puzzle still has one answer (and is still easy enough)
            const tryRemove = (group) => {
                if (left - group.length < clues || group.some((j) => !puzzle[j])) return;
                group.forEach((j) => { puzzle[j] = 0; });
                const ok = countSolutions(puzzle) === 1
                    && (singles !== 'naked' && singles !== 'hidden' || solvesWithSingles(puzzle, singles === 'hidden'));
                if (ok) left -= group.length;
                else group.forEach((j) => { puzzle[j] = solution[j]; });
            };
            // Mirrored pairs first, for the classic look (0-40, each with 80 - i; 40 is the middle)...
            for (const i of shuffle([...Array(41).keys()])) tryRemove(i === 40 ? [40] : [i, 80 - i]);
            // ...then, on Expert, single squares, to get as low as it can
            if (isExpert) for (const i of shuffle([...Array(81).keys()])) tryRemove([i]);

            const hard = !isExpert || !solvesWithSingles(puzzle, true);
            if (!best || (hard && (!best.hard || left < best.left))) best = { puzzle, solution, left, hard };
            if (!isExpert) break;
        }
        return { puzzle: best.puzzle, solution: best.solution };
    }

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

    let store = load();
    store.best = store.best && typeof store.best === 'object' ? store.best : {};
    store.wins = store.wins && typeof store.wins === 'object' ? store.wins : {};

    function save() {
        if (game) store.game = { ...game, puzzle: game.puzzle.join(''), solution: game.solution.join(''), values: game.values.join('') };
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(store));
        } catch {
            // no storage: the game only lasts as long as the page
        }
    }

    // A saved game, turned back into arrays, or null when it's missing or broken
    function savedGame(saved) {
        const digits = (text) => (typeof text === 'string' && /^[0-9]{81}$/.test(text) ? [...text].map(Number) : null);
        if (!saved || !DIFFICULTIES[saved.level]) return null;
        const puzzle = digits(saved.puzzle);
        const solution = digits(saved.solution);
        const values = digits(saved.values);
        if (!puzzle || !solution || !values || solution.includes(0)) return null;
        const notes = Array.isArray(saved.notes) && saved.notes.length === 81 ? saved.notes.map((n) => Number(n) & ALL) : new Array(81).fill(0);
        return {
            level: saved.level, puzzle, solution, values, notes,
            ms: Math.max(0, Number(saved.ms) || 0), hints: Math.max(0, Number(saved.hints) || 0), solved: saved.solved === true,
        };
    }

    // ---- The game ----

    let game = null;
    let selected = -1;
    let notesMode = false;
    let paused = false;
    let undo = []; // [{i, value, notes: [81]}] before each move
    let runningSince = 0; // performance.now() when the clock last started, 0 while stopped
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

    // The clock runs while there's an unsolved game, the page is on screen and it isn't paused
    function updateClock() {
        const shouldRun = game && !game.solved && !paused && document.visibilityState === 'visible';
        if (shouldRun && !runningSince) runningSince = performance.now();
        if (!shouldRun && runningSince) {
            game.ms = playedMs();
            runningSince = 0;
            save();
        }
        timeText.textContent = formatTime(game ? playedMs() : 0);
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
        padKeys.forEach((key) => {
            const d = Number(key.dataset.sudokuDigit);
            const left = Math.max(0, 9 - placed[d]);
            key.querySelector('[data-sudoku-left]').textContent = left || '';
            key.classList.toggle('is-done', left === 0);
            key.setAttribute('aria-label', `${d}${left ? `, ${left} left` : ', all placed'}`);
        });

        levelText.textContent = DIFFICULTIES[game.level].name;
        notesState.textContent = notesMode ? 'on' : 'off';
        notesButton.setAttribute('aria-pressed', String(notesMode));
        notesButton.classList.toggle('is-on', notesMode);
        root.querySelectorAll('[data-sudoku-tool]').forEach((button) => { button.disabled = game.solved || paused; });
        padKeys.forEach((key) => { key.disabled = game.solved || paused; });
        pauseButton.disabled = game.solved;
        pauseButton.textContent = paused ? 'Carry on' : 'Pause';
        pauseButton.setAttribute('aria-pressed', String(paused));
        cover.hidden = !paused;
        board.classList.toggle('is-paused', paused);
        board.classList.toggle('is-solved', game.solved);
        result.hidden = !game.solved;
        drawBest();
    }

    function drawBest() {
        root.querySelectorAll('[data-sudoku-best]').forEach((span) => {
            const level = span.dataset.sudokuBest;
            span.textContent = store.best[level] ? `Best ${formatTime(store.best[level])}` : 'No wins yet';
            span.closest('.sudoku-level').classList.toggle('is-active', game?.level === level && !game.solved);
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

    // Puts a number in the selected square (or a note, in notes mode)
    function enter(digit) {
        if (!game || game.solved || paused) return;
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
        if (!game || game.solved || paused || selected < 0 || game.puzzle[selected]) return;
        if (!game.values[selected] && !game.notes[selected]) return;
        remember(selected);
        game.values[selected] = 0;
        game.notes[selected] = 0;
        changed();
    }

    function takeBack() {
        if (!game || game.solved || paused) return;
        const last = undo.pop();
        if (!last) { say('Nothing to undo.'); return; }
        game.values[last.i] = last.value;
        game.notes = last.notes;
        selected = last.i;
        changed(false);
        cells[last.i].focus();
    }

    // Fills in the selected square with the right number, or a random empty (or wrong) one if that's done already
    function hint() {
        if (!game || game.solved || paused) return;
        const wrong = (i) => game.values[i] !== game.solution[i];
        let i = selected >= 0 && wrong(selected) ? selected : -1;
        if (i < 0) {
            const open = [...Array(81).keys()].filter(wrong);
            if (!open.length) return;
            i = open[Math.floor(Math.random() * open.length)];
        }
        remember(i);
        const digit = game.solution[i];
        game.values[i] = digit;
        game.notes[i] = 0;
        for (const p of PEERS[i]) game.notes[p] &= ~(1 << digit);
        game.hints++;
        selected = i;
        changed();
        cells[i].focus();
        say(`Hint: row ${rowOf(i) + 1}, column ${colOf(i) + 1} is ${digit}.`);
    }

    // After every move: is it solved? Then draw and save
    function changed(checkWin = true) {
        if (checkWin && game.values.every(Boolean) && conflicts().size === 0) {
            game.ms = playedMs();
            runningSince = 0;
            game.solved = true;
            undo = [];
            const level = game.level;
            const isBest = !store.best[level] || game.ms < store.best[level];
            if (isBest) store.best[level] = Math.round(game.ms);
            store.wins[level] = (Number(store.wins[level]) || 0) + 1;
            const hints = game.hints ? ` with ${game.hints} hint${game.hints === 1 ? '' : 's'}` : ' without a hint';
            resultText.textContent = `You solved the ${DIFFICULTIES[level].name} puzzle in ${formatTime(game.ms)}${hints}. `
                + (isBest ? 'That\'s your best time on this level!' : `Your best is ${formatTime(store.best[level])}.`)
                + ` You've solved ${store.wins[level]} on ${DIFFICULTIES[level].name}.`;
            say('Solved!', 0);
        } else if (checkWin && game.values.every(Boolean)) {
            say('The board is full, but some numbers clash.', 4000);
        }
        draw();
        save();
        updateClock();
        if (game.solved) result.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
    }

    function startGame(level) {
        coverText.textContent = 'Making a puzzle…';
        cover.querySelector('[data-sudoku-resume]').hidden = true;
        cover.hidden = false;
        board.classList.add('is-paused');
        // Let the page show the cover before the work starts (Expert can take a moment on a slow phone)
        setTimeout(() => {
            const { puzzle, solution } = makePuzzle(level);
            game = { level, puzzle, solution, values: puzzle.slice(), notes: new Array(81).fill(0), ms: 0, hints: 0, solved: false };
            runningSince = 0;
            undo = [];
            paused = false;
            notesMode = false;
            selected = -1;
            coverText.textContent = 'Paused';
            cover.querySelector('[data-sudoku-resume]').hidden = false;
            message.textContent = '';
            draw();
            save();
            updateClock();
            const firstOpen = game.values.indexOf(0);
            select(firstOpen, false);
        }, 30);
    }

    // A new game, asking first if one with moves in it is being played
    function askNewGame(level) {
        const inProgress = game && !game.solved && game.values.some((v, i) => v && !game.puzzle[i]);
        if (!inProgress || typeof confirmDialog.showModal !== 'function') {
            startGame(level);
            return;
        }
        confirmText.textContent = `Your ${DIFFICULTIES[game.level].name} game will be gone, and a new ${DIFFICULTIES[level].name} one starts.`;
        confirmDialog.returnValue = '';
        confirmDialog.addEventListener('close', () => {
            if (confirmDialog.returnValue === 'yes') startGame(level);
        }, { once: true });
        confirmDialog.showModal();
    }

    function setPaused(on) {
        if (!game || game.solved) return;
        paused = on;
        draw();
        updateClock();
        if (!on && selected >= 0) cells[selected].focus();
    }

    // ---- Input ----

    board.addEventListener('click', (event) => {
        const cell = event.target.closest('.sudoku-cell');
        if (cell && game && !paused) select(Number(cell.dataset.index));
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
    cover.querySelector('[data-sudoku-resume]').addEventListener('click', () => setPaused(false));

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
        if (event.key in moves && !paused) {
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

    // ---- Start: the saved game, or a new Medium one ----

    game = savedGame(store.game);
    if (game) {
        if (game.solved) {
            resultText.textContent = `You solved this ${DIFFICULTIES[game.level].name} puzzle in ${formatTime(game.ms)}. Pick a level for a new one.`;
        } else if (game.values.some((v, i) => v && !game.puzzle[i])) {
            say('Your game from last time. Pick a level above for a new one.', 4000);
        }
        draw();
        updateClock();
        if (!game.solved) select(game.values.indexOf(0), false);
    } else {
        startGame('medium');
    }
})();
