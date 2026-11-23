// Blockfall (games/blockfall/): the whole game, in the browser. HB Hub's falling-block puzzle, built on the ideas in
// larprad's tetris on GitHub (the modes, the speeds, a hold slot and a preview; credited on the credits page). Loaded
// by games/blockfall/index.php, which has the bar (score, lines, level, time, best, mode, pause), the well's
// [data-blockfall-canvas], the hold and next pieces' canvases, the cover over the well ([data-blockfall-cover], for
// "ready", "paused" and "game over") and the buttons for phones ([data-blockfall-pad]).
//
// THE RULES ARE ALSO IN assets/php/games/blockfall/blockfall.php, which plays every game along with this one for the
// leaderboard: the "Rules" part below is the same as its "Rules" part, function by function, and a change to one goes
// in the other, or every game ends as "bad moves". They're only whole numbers, so both sides always agree. The
// rules themselves are written out at the top of blockfall.php.
//
// The game moves on in ticks of TICK_MS (10 ms), however fast the screen draws: each frame plays the ticks the time
// since the last one covers (at most 250 ms of them, so a hidden or frozen tab doesn't race to catch up). A key press is
// made at once, on the tick the game is on, and logged with it ("120L"); a held key's repeats (DAS/ARR below, and the
// soft drop) are made at the start of a tick, before the tick itself, just as the server makes a tick's inputs first.
//
// The leaderboard (as Snake's, see snake.js): the page comes with the next game (data-blockfall-game: its id and the
// first pieces) and the bests (data-blockfall-bests). When the first piece comes in it sends "start" with the mode;
// then it reports the ticks played and the inputs since the last report, every REPORT_MS, sooner when it's running low
// on pieces, when it's paused or carries on, and with "finish" when it's over. Each answer brings the next pieces, and
// the answer to "finish" has the best and the next game. A game stops counting (unrank()) when the server says so, a
// request fails, the page runs out of pieces, or a key press or tap wasn't made by a person (event.isTrusted false).
// Then it's played on as normal, the pieces coming from Math.random, and [data-blockfall-note] says why.
//
// localStorage (hbHubBlockfall) only keeps {"mode": "enduro" | "rush" | "sprint"}, the last mode (in the privacy
// policy). The guest Blockfall (/play/blockfall, data-blockfall-guest) has no server game: its pieces come from
// Math.random and its bests are kept there too, as "guestBest" ({"enduro": score, "rush": score, "sprint": ticks}).
(() => {
  const root = document.querySelector('[data-blockfall]');
  if (!root) {
    return;
  }

  // Rules (as HBHUB_BLOCKFALL_* in blockfall.php) -------------------------------------------------------------------

  const COLS = 10;
  const ROWS = 22; // the top HIDDEN of them hidden
  const HIDDEN = 2;
  const TICK_MS = 10;
  const SPAWN_X = 3;
  const SPAWN_Y = 2;
  const LOCK_TICKS = 50;
  const MAX_RESETS = 15;
  const GRAVITY = [236, 146, 92, 59, 39, 26, 18, 12, 9, 6, 5, 4, 3, 2, 2]; // ticks a row at speed 1-15
  const LINE_POINTS = [0, 100, 300, 500, 800];
  const KICKS = [[0, 0], [-1, 0], [1, 0], [0, -1], [-1, -1], [1, -1], [-2, 0], [2, 0]];
  const SHAPES = {
    I: [[0, 1], [1, 1], [2, 1], [3, 1]],
    O: [[1, 0], [2, 0], [1, 1], [2, 1]],
    T: [[1, 0], [0, 1], [1, 1], [2, 1]],
    S: [[1, 0], [2, 0], [0, 1], [1, 1]],
    Z: [[0, 0], [1, 0], [1, 1], [2, 1]],
    J: [[0, 0], [0, 1], [1, 1], [2, 1]],
    L: [[2, 0], [0, 1], [1, 1], [2, 1]],
  };
  const BAG = 'IOTSZJL';
  const MODES = {
    enduro: { speed: 3, linesPerLevel: 10, ticks: 0, goal: 0, board: 'score' },
    rush: { speed: 6, linesPerLevel: 5, ticks: 6000, goal: 0, board: 'score' },
    sprint: { speed: 6, linesPerLevel: 5, ticks: 0, goal: 20, board: 'time' },
  };

  // The page's own (not rules: how held keys repeat into inputs)
  const DAS = 16; // ticks a move key is held before it repeats
  const ARR = 5; // ticks between its repeats
  const SOFT_EVERY = 3; // ticks between soft drops while Down is held
  const STORAGE_KEY = 'hbHubBlockfall';

  // The server's side (blockfall.php)
  const MOVE_URL = '/games/blockfall/move';
  const REPORT_MS = 4000;
  const LOW_PIECES = 16; // fewer pieces than this left ahead and it reports straight away (at most once a second)
  const MAX_PAUSES = 10; // HBHUB_BLOCKFALL_MAX_PAUSES
  const FINISHED = 1; // HBHUB_BLOCKFALL_FINISHED
  const NOT_COUNTED = 'This game doesn\'t count for the leaderboard';
  const WHY = {
    2: `${NOT_COUNTED}: a new game was started in another tab.`,
    3: `${NOT_COUNTED}: a key press or tap came from code, not from you, so it was ignored.`,
    4: `${NOT_COUNTED}: it didn't keep time with HB Hub's clock (was the game slowed down or sped up?).`,
    5: `${NOT_COUNTED}: HB Hub couldn't play the same game.`,
    6: `${NOT_COUNTED}: it was paused more than ${MAX_PAUSES} times.`,
    noGame: `${NOT_COUNTED}: HB Hub couldn't be reached in time when it started.`,
    connection: `${NOT_COUNTED}: the connection to HB Hub was lost.`,
    error: `${NOT_COUNTED}: something went wrong on HB Hub.`,
  };

  // Each piece's colour: the game's own, picked to sit well on the dark well
  const COLOUR = {
    I: '#ff6b6b', O: '#ffd166', T: '#06d6a0', S: '#4ea8de', Z: '#9b5de5', J: '#f15bb5', L: '#ff9f1c',
    well: '#1b1838',
    dot: 'rgba(255, 255, 255, 0.07)',
  };
  const CLEAR_NAMES = ['', 'Single', 'Double', 'Triple', 'Quad'];

  // Keys => inputs (see the top of blockfall.php). Space and W hard drop, Up and X turn clockwise, Z anticlockwise
  const KEYS = {
    ArrowLeft: 'L', a: 'L', A: 'L',
    ArrowRight: 'R', d: 'R', D: 'R',
    ArrowDown: 'D', s: 'D', S: 'D',
    ' ': 'F', w: 'F', W: 'F',
    ArrowUp: '+', x: '+', X: '+',
    z: '-', Z: '-',
    c: 'H', C: 'H', Shift: 'H',
  };

  const canvas = root.querySelector('[data-blockfall-canvas]');
  const ctx = canvas.getContext('2d');
  const holdCanvas = root.querySelector('[data-blockfall-hold]');
  const holdCtx = holdCanvas.getContext('2d');
  const nextCanvas = root.querySelector('[data-blockfall-next]');
  const nextCtx = nextCanvas.getContext('2d');
  const well = root.querySelector('[data-blockfall-well]');
  const cover = root.querySelector('[data-blockfall-cover]');
  const coverTitle = root.querySelector('[data-blockfall-cover-title]');
  const coverText = root.querySelector('[data-blockfall-cover-text]');
  const playButton = root.querySelector('[data-blockfall-play]');
  const pauseButton = root.querySelector('[data-blockfall-pause]');
  const modeSelect = root.querySelector('[data-blockfall-mode]');
  const scoreOut = root.querySelector('[data-blockfall-score]');
  const linesOut = root.querySelector('[data-blockfall-lines]');
  const levelOut = root.querySelector('[data-blockfall-level]');
  const timeOut = root.querySelector('[data-blockfall-time]');
  const bestOut = root.querySelector('[data-blockfall-best]');
  const callout = root.querySelector('[data-blockfall-callout]');
  const note = root.querySelector('[data-blockfall-note]');
  const pad = root.querySelector('[data-blockfall-pad]');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const guest = 'blockfallGuest' in root.dataset;

  const readJson = (text) => {
    try {
      return JSON.parse(text || 'null');
    } catch {
      return null;
    }
  };

  // Storage (in try/catch: it can be blocked, and the game works without it) ----------------------------------------

  const load = () => {
    try {
      const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
      const guestBest = {};
      Object.keys(MODES).forEach((key) => {
        const value = stored.guestBest?.[key];
        guestBest[key] = Number.isInteger(value) && value > 0 ? value : (MODES[key].board === 'time' ? null : 0);
      });
      return { mode: MODES[stored.mode] ? stored.mode : 'enduro', guestBest };
    } catch {
      return { mode: 'enduro', guestBest: {} };
    }
  };

  const save = () => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(guest ? { mode: saved.mode, guestBest: best } : { mode: saved.mode }));
    } catch {
      // Blocked or full: the mode (and a guest's bests) just isn't kept
    }
  };

  const saved = load();
  // The best in each mode: a score, or for Sprint the ticks for 20 lines (null before one). The server's, or a guest's
  const best = { enduro: 0, rush: 0, sprint: null, ...(guest ? saved.guestBest : readJson(root.dataset.blockfallBests) || {}) };

  // Rules (the same as blockfall.php's, function by function) --------------------------------------------------------

  // Every piece's squares at each of its four turns, worked out once: (x, y) => (size - 1 - y, x) per clockwise turn
  const CELLS = {};
  Object.entries(SHAPES).forEach(([piece, shape]) => {
    const size = piece === 'I' ? 4 : 3;
    CELLS[piece] = [shape];
    for (let rot = 1; rot < 4; rot++) {
      CELLS[piece].push(piece === 'O' ? shape : CELLS[piece][rot - 1].map(([x, y]) => [size - 1 - y, x]));
    }
  });

  const fits = (g, piece, rot, x, y) => CELLS[piece][rot].every(([cx, cy]) => {
    const col = x + cx;
    const row = y + cy;
    return col >= 0 && col < COLS && row >= 0 && row < ROWS && g.board[row * COLS + col] === '.';
  });

  const resting = (g) => !fits(g, g.piece, g.rot, g.x, g.y + 1);

  const level = (g, mode) => 1 + Math.floor(g.lines / mode.linesPerLevel);

  const gravity = (g, mode) => GRAVITY[Math.min(mode.speed + level(g, mode) - 1, GRAVITY.length) - 1];

  const spawn = (g, piece) => {
    g.piece = piece;
    g.rot = 0;
    g.x = SPAWN_X;
    g.y = SPAWN_Y;
    g.gravity = 0;
    g.lock = 0;
    g.resets = 0;
    g.lowest = SPAWN_Y;
    g.canHold = true;
    if (!fits(g, piece, 0, g.x, g.y)) {
      g.over = 'top';
    }
  };

  const draw = (g) => {
    const piece = takePiece(g.drawn);
    g.drawn++;
    spawn(g, piece);
  };

  const down = (g) => {
    if (resting(g)) {
      return false;
    }
    g.y++;
    if (g.y > g.lowest) {
      g.lowest = g.y;
      g.resets = 0;
    }
    return true;
  };

  const moved = (g) => {
    if (resting(g) && g.resets < MAX_RESETS) {
      g.lock = 0;
      g.resets++;
    }
  };

  const lock = (g, mode) => {
    let hiddenOnly = true;
    CELLS[g.piece][g.rot].forEach(([cx, cy]) => {
      const row = g.y + cy;
      g.board[row * COLS + g.x + cx] = g.piece;
      hiddenOnly = hiddenOnly && row < HIDDEN;
    });

    const kept = [];
    const clearedRows = [];
    for (let row = 0; row < ROWS; row++) {
      const cells = g.board.slice(row * COLS, (row + 1) * COLS);
      if (cells.includes('.')) {
        kept.push(...cells);
      } else {
        clearedRows.push(row);
      }
    }
    const cleared = clearedRows.length;
    const points = LINE_POINTS[cleared] * level(g, mode);
    if (cleared > 0) {
      g.board = [...Array(COLS * cleared).fill('.'), ...kept];
      g.score += points;
      g.lines += cleared;
    }
    onLock(clearedRows, points); // the page's own: the flash and the callout, nothing to do with the rules

    if (cleared === 0 && hiddenOnly) {
      g.over = 'top';
    } else if (mode.goal > 0 && g.lines >= mode.goal) {
      g.over = 'done';
    } else {
      draw(g);
    }
  };

  const input = (g, letter, mode) => {
    if (g.over) {
      return;
    }
    if (letter === 'L' || letter === 'R') {
      const dx = letter === 'L' ? -1 : 1;
      if (fits(g, g.piece, g.rot, g.x + dx, g.y)) {
        g.x += dx;
        moved(g);
      }
    } else if (letter === '+' || letter === '-') {
      if (g.piece === 'O') {
        return;
      }
      const rot = (g.rot + (letter === '+' ? 1 : 3)) % 4;
      for (const [dx, dy] of KICKS) {
        if (fits(g, g.piece, rot, g.x + dx, g.y + dy)) {
          g.rot = rot;
          g.x += dx;
          g.y += dy;
          if (g.y > g.lowest) {
            g.lowest = g.y;
            g.resets = 0;
          }
          moved(g);
          break;
        }
      }
    } else if (letter === 'D') {
      if (down(g)) {
        g.score += 1;
        g.gravity = 0;
      }
    } else if (letter === 'F') {
      let rows = 0;
      while (down(g)) {
        rows++;
      }
      g.score += 2 * rows;
      lock(g, mode);
    } else if (letter === 'H') {
      if (!g.canHold) {
        return;
      }
      if (g.hold === '') {
        g.hold = g.piece;
        draw(g);
      } else {
        const held = g.hold;
        g.hold = g.piece;
        spawn(g, held);
      }
      g.canHold = false;
    }
  };

  const advance = (g, mode) => {
    if (g.over) {
      return;
    }
    g.gravity++;
    if (g.gravity >= gravity(g, mode)) {
      g.gravity = 0;
      down(g);
    }
    if (resting(g)) {
      g.lock++;
      if (g.lock >= LOCK_TICKS) {
        lock(g, mode);
      }
    } else {
      g.lock = 0;
    }
    g.ticks++;
    if (mode.ticks > 0 && g.ticks >= mode.ticks && !g.over) {
      g.over = 'time';
    }
  };

  // An empty well, before its first piece (hbHubBlockfallFresh() without the draw, which start() makes)
  const fresh = () => ({
    board: Array(COLS * ROWS).fill('.'),
    piece: '', rot: 0, x: 0, y: 0, hold: '', canHold: true, drawn: 0,
    gravity: 0, lock: 0, resets: 0, lowest: 0, ticks: 0, score: 0, lines: 0, over: '',
  });

  // The game on the page -----------------------------------------------------------------------------------------------

  let state = 'ready'; // ready | playing | paused | over
  let g = fresh();
  let known = []; // the pieces by number: the server's while the game counts, otherwise (and after them) made here
  let acc = 0; // ms played but not ticked yet
  let lastFrame = 0;
  let frameId = 0;
  let flash = null; // {rows: [visible row], at}: the lines just cleared, drawn fading
  let newBest = false;
  const held = { dir: null, dirTicks: 0, soft: false, softTicks: 0, pressed: new Set() };

  // The leaderboard (as Snake's)
  let nextGame = readJson(root.dataset.blockfallGame); // {game, piecesFrom, pieces}, or null
  let ranked = null; // this round's server game while it counts: {game: id, started}
  let why = '';
  let counted = false;
  let finishing = false;
  let fetching = false;
  let inputs = []; // since the last report, like "120L"
  let pauses = 0;
  let lastReport = 0;
  let requests = Promise.resolve();

  const mode = () => MODES[saved.mode];

  /**
   * A shuffled bag of the seven pieces, for when the server's don't count (a guest, or a game that stopped counting).
   */
  const randomBag = () => {
    const order = BAG.split('');
    for (let i = 6; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [order[i], order[j]] = [order[j], order[i]];
    }
    return order;
  };

  /**
   * Piece number `index`. While the game counts it has to be one of the server's; when it isn't here (a slow
   * connection fell behind), the game stops counting and goes on with pieces made here.
   */
  const takePiece = (index) => {
    if (known[index] === undefined && ranked) {
      unrank(ranked, 'connection');
    }
    if (!ranked) {
      fillKnown(index + 3); // and the preview's three after it
    }
    known[index] ??= BAG[Math.floor(Math.random() * 7)]; // a gap a lost answer left
    return known[index];
  };

  // Bags made here, until piece number `index` is known
  const fillKnown = (index) => {
    while (known.length <= index) {
      known.push(...randomBag());
    }
  };

  // The server -------------------------------------------------------------------------------------------------------

  const post = async (action, fields, keepalive) => {
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
      throw Object.assign(new Error('server'), { code: data?.error || 'server' });
    }
    return data;
  };

  // One at a time, in order
  const send = (action, fields = {}, keepalive = false) => {
    const request = requests.then(() => post(action, fields, keepalive));
    requests = request.catch(() => {});
    return request;
  };

  const failure = (error) => ({ network: 'connection', gameOver: 2 })[error.code] || 'error';

  // The pieces in an answer ({piecesFrom, pieces}), while it's still this round's game
  const addPieces = (game, data) => {
    if (game === ranked && data && typeof data.pieces === 'string') {
      data.pieces.split('').forEach((piece, i) => {
        known[data.piecesFrom + i] = piece;
      });
    }
  };

  const showNote = () => {
    note.hidden = !why;
    note.textContent = why ? WHY[why] : '';
  };

  const unrank = (game, reason) => {
    if (!game || ranked !== game) {
      return;
    }
    ranked = null;
    why = reason in WHY ? reason : 'error';
    showNote();
    showStats();
    showCover();
  };

  /**
   * Takes the server's next game for this round, or plays this one without (and asks for one for the next).
   */
  const adopt = () => {
    ranked = null;
    why = '';
    known = [];
    if (!guest) {
      if (nextGame) {
        ranked = { game: nextGame.game, started: false };
        addPieces(ranked, nextGame);
        nextGame = null;
      } else {
        why = 'noGame';
        if (!finishing) {
          fetchNew();
        }
      }
    }
    if (!ranked) {
      fillKnown(3); // so the preview has its pieces before the first comes in
    }
    showNote();
  };

  // The next game is here: a round that hasn't started takes it, otherwise it waits for the next round
  const gotNext = (data) => {
    nextGame = data;
    if (!ranked && state === 'ready' && !finishing) {
      adopt();
    }
  };

  const fetchNew = () => {
    if (guest || nextGame || fetching) {
      return;
    }
    fetching = true;
    send('new')
      .then(gotNext, () => {})
      .finally(() => {
        fetching = false;
      });
  };

  /**
   * Reports the ticks played so far and the inputs since the last report ('tick', 'pause', 'resume' or 'finish').
   * Resolves with the answer, or {failed: a key of WHY}.
   */
  const report = (action, keepalive = false) => {
    const game = ranked;
    if (!game || !game.started) {
      return Promise.resolve({ failed: 'error' });
    }
    const fields = { game: game.game, ticks: g.ticks, inputs: inputs.join(',') };
    inputs = [];
    lastReport = performance.now();
    return send(action, fields, keepalive).then(
      (data) => {
        addPieces(game, data);
        if (action !== 'finish' && data.result !== 0 && data.result !== FINISHED) {
          unrank(game, data.result);
        }
        return data;
      },
      (error) => {
        unrank(game, failure(error));
        return { failed: failure(error) };
      },
    );
  };

  /**
   * An empty well for a new round, with the server's next game when there is one.
   */
  const reset = () => {
    g = fresh();
    acc = 0;
    inputs = [];
    pauses = 0;
    counted = false;
    flash = null;
    releaseAll();
    if (!ranked) {
      adopt();
    }
    showStats();
  };

  /**
   * Starts a round: the first piece comes in, and a game that counts tells the server, which starts its clock.
   */
  const start = () => {
    if (state === 'over') {
      reset();
    }
    draw(g);
    setState('playing');
    if (ranked && !ranked.started) {
      ranked.started = true;
      lastReport = performance.now();
      const game = ranked;
      send('start', { game: game.game, mode: saved.mode }).catch((error) => unrank(game, failure(error)));
    }
  };

  /**
   * The game is over (topped out, Rush's minute is up, or Sprint's 20 lines are cleared). A game that counts tells the
   * server, whose answer has the best in the mode and the next game.
   */
  const finish = () => {
    const game = ranked && ranked.started ? ranked : null;
    const modeKey = saved.mode;
    newBest = false;
    counted = false;
    if (guest) {
      counted = true;
      if (isBetter(modeKey, best[modeKey])) {
        best[modeKey] = mode().board === 'time' ? g.ticks : g.score;
        newBest = true;
        save();
      }
      document.dispatchEvent(new CustomEvent('guest:prompt'));
    }
    finishing = Boolean(game);
    const sent = report('finish');
    ranked = null;
    releaseAll();
    setState('over');
    showStats();
    if (!reducedMotion.matches && g.over === 'top') {
      well.classList.remove('is-hit');
      void well.offsetWidth;
      well.classList.add('is-hit');
    }
    if (!game) {
      fetchNew();
      return;
    }

    sent.then((data) => {
      finishing = false;
      if (data.result === FINISHED) {
        counted = true;
        best[modeKey] = data.best;
        newBest = data.isBest;
      } else {
        why = data.failed || (data.result in WHY ? data.result : 'error');
        showNote();
      }
      if (data.next) {
        nextGame = data.next;
      } else {
        fetchNew();
      }
      if (state === 'over') {
        showCover();
      }
      showStats();
    });
  };

  // Whether this round beats `previous` in the mode: a higher score, or Sprint's 20 lines in fewer ticks
  const isBetter = (modeKey, previous) => {
    if (MODES[modeKey].board === 'time') {
      return g.over === 'done' && (previous === null || g.ticks < previous);
    }
    return g.score > 0 && g.score > previous;
  };

  const pause = () => {
    if (state === 'playing') {
      pauses++;
      releaseAll();
      report('pause', true);
      setState('paused');
    } else if (state === 'paused') {
      setState('playing');
      report('resume');
    }
  };

  /**
   * One input, made now on the tick the game is on (and logged for the server while the game counts).
   */
  const act = (letter) => {
    if (state !== 'playing') {
      return;
    }
    if (ranked) {
      inputs.push(`${g.ticks}${letter}`);
    }
    input(g, letter, mode());
    if (g.over) {
      finish();
    }
  };

  /**
   * One tick: the held keys' repeats first, then the tick itself.
   */
  const tick = () => {
    if (held.dir) {
      held.dirTicks++;
      if (held.dirTicks >= DAS && (held.dirTicks - DAS) % ARR === 0) {
        act(held.dir);
      }
    }
    if (held.soft) {
      held.softTicks++;
      if (held.softTicks % SOFT_EVERY === 0) {
        act('D');
      }
    }
    if (state === 'playing') {
      advance(g, mode());
      if (g.over) {
        finish();
      }
    }
  };

  // Lines cleared: they flash, and the callout over the well says how many and what they scored
  const onLock = (rows, points) => {
    if (!rows.length) {
      return;
    }
    flash = { rows: rows.map((row) => row - HIDDEN), at: performance.now() };
    callout.textContent = `${CLEAR_NAMES[rows.length]} +${points.toLocaleString('en')}`;
    callout.classList.remove('is-shown');
    void callout.offsetWidth;
    callout.classList.add('is-shown');
  };

  // Keys and buttons -------------------------------------------------------------------------------------------------

  /**
   * A key or button went down: the input is made at once, and a move or soft drop keeps repeating while it's held.
   */
  const press = (letter) => {
    if (letter === 'L' || letter === 'R') {
      held.pressed.add(letter);
      held.dir = letter;
      held.dirTicks = 0;
    } else if (letter === 'D') {
      held.soft = true;
      held.softTicks = 0;
    }
    act(letter);
  };

  // ...and came back up. Letting go of one way while the other is still held goes that way again
  const release = (letter) => {
    if (letter === 'L' || letter === 'R') {
      held.pressed.delete(letter);
      if (held.dir === letter) {
        held.dir = held.pressed.size ? [...held.pressed][0] : null;
        held.dirTicks = 0;
      }
    } else if (letter === 'D') {
      held.soft = false;
    }
  };

  // Everything let go (a pause, the tab hidden, game over: the key-ups could be missed)
  function releaseAll() {
    held.dir = null;
    held.soft = false;
    held.pressed.clear();
  }

  /**
   * A key press or tap that wasn't made by a person: ignored, and the game stops counting.
   */
  const caught = () => {
    const game = ranked;
    if (game) {
      if (game.started) {
        send('scripted', { game: game.game }).catch(() => {});
      }
      unrank(game, 3);
    }
  };

  // Showing it -------------------------------------------------------------------------------------------------------

  // ms as "1:05.42" (with hundredths, for Sprint) or "1:05"
  const formatTime = (ms, hundredths) => {
    const whole = Math.max(0, ms);
    const minutes = Math.floor(whole / 60000);
    const seconds = Math.floor((whole % 60000) / 1000);
    const text = `${minutes}:${String(seconds).padStart(2, '0')}`;
    return hundredths ? `${text}.${String(Math.floor((whole % 1000) / 10)).padStart(2, '0')}` : text;
  };

  const bestText = (modeKey) => {
    const value = best[modeKey];
    if (MODES[modeKey].board === 'time') {
      return value === null ? '-' : formatTime(value * TICK_MS, true);
    }
    return Number(value || 0).toLocaleString('en');
  };

  // Rush counts down from its minute, Sprint shows hundredths, Enduro just the time
  const timeText = () => {
    const m = mode();
    if (m.ticks > 0) {
      return formatTime(Math.ceil(((m.ticks - g.ticks) * TICK_MS) / 1000) * 1000, false);
    }
    return formatTime(g.ticks * TICK_MS, m.board === 'time');
  };

  // Only what has changed is written, as this runs every frame
  const write = (out, text) => {
    const value = String(text);
    if (out.textContent !== value) {
      out.textContent = value;
    }
  };

  const showStats = () => {
    write(scoreOut, g.score.toLocaleString('en'));
    write(linesOut, mode().goal ? `${Math.min(g.lines, mode().goal)}/${mode().goal}` : g.lines);
    write(levelOut, level(g, mode()));
    write(timeOut, timeText());
    write(bestOut, bestText(saved.mode));
  };

  const showCover = () => {
    const left = MAX_PAUSES - pauses;
    let pauseText = '';
    if (ranked) {
      pauseText = left > 0
        ? ` You can pause ${left} more ${left === 1 ? 'time' : 'times'} and keep this game on the leaderboard.`
        : ' Pause again and this game won\'t count for the leaderboard.';
    }
    const m = mode();
    const aims = {
      enduro: 'Keep going until the blocks reach the top. It gets faster every 10 lines.',
      rush: 'Score as much as you can in 60 seconds.',
      sprint: `Clear ${m.goal} lines as fast as you can.`,
    };
    let title = 'Game over';
    let result = `You scored ${g.score.toLocaleString('en')} with ${g.lines} ${g.lines === 1 ? 'line' : 'lines'}.`;
    if (g.over === 'time') {
      title = 'Time\'s up!';
    } else if (g.over === 'done') {
      title = `${m.goal} lines!`;
      result = `You cleared ${m.goal} lines in ${formatTime(g.ticks * TICK_MS, true)}.`;
    } else if (m.board === 'time') {
      result = `You cleared ${g.lines} of ${m.goal} lines.`;
    }
    let bestLine = '';
    if (counted && newBest) {
      bestLine = ' That\'s a new best!';
    } else if (counted && best[saved.mode] !== null) {
      bestLine = ` Your best is ${bestText(saved.mode)}.`; // (a Sprint before one has reached the 20 lines has none)
    }
    const texts = {
      ready: ['Ready?', `${aims[saved.mode]} Press Space or Enter, or the button, to start.`, 'Play'],
      paused: ['Paused', 'Press P or Esc, or the button, to carry on.' + pauseText, 'Carry on'],
      over: [title, result + bestLine, 'Play again'],
    };

    cover.hidden = state === 'playing';
    cover.dataset.state = state;
    if (texts[state]) {
      [coverTitle.textContent, coverText.textContent, playButton.textContent] = texts[state];
    }
    pauseButton.disabled = state !== 'playing' && state !== 'paused';
    pauseButton.textContent = state === 'paused' ? 'Carry on' : 'Pause';
    modeSelect.disabled = state === 'playing' || state === 'paused';
  };

  const setState = (next) => {
    state = next;
    // The guest's sign-up popup (guestPrompt.js) waits while a game is going
    root.toggleAttribute('data-guest-prompt-wait', guest && state === 'playing');
    showCover();
  };

  // Drawing ----------------------------------------------------------------------------------------------------------

  const sharpen = (target, cols, rows) => {
    const ratio = window.devicePixelRatio || 1;
    const width = Math.max(1, Math.round(target.clientWidth * ratio));
    target.width = width;
    target.height = Math.round((width * rows) / cols);
  };

  const resize = () => {
    sharpen(canvas, COLS, ROWS - HIDDEN);
    sharpen(holdCanvas, 4, 3);
    sharpen(nextCanvas, 4, 9);
  };

  /**
   * One square of a piece at (x, y) in squares of `size`: a rounded tile in its colour, lighter at the top and darker
   * at the bottom, with a little shine. `alpha` fades it (the ghost uses an outline instead, see drawGhost()).
   */
  const drawTile = (c, x, y, size, piece, alpha = 1) => {
    const gap = size * 0.06;
    const left = x * size + gap;
    const top = y * size + gap;
    const side = size - gap * 2;
    const radius = side * 0.22;
    c.save();
    c.globalAlpha = alpha;
    c.beginPath();
    c.roundRect(left, top, side, side, radius);
    c.fillStyle = COLOUR[piece];
    c.fill();
    const shade = c.createLinearGradient(0, top, 0, top + side);
    shade.addColorStop(0, 'rgba(255, 255, 255, 0.35)');
    shade.addColorStop(0.5, 'rgba(255, 255, 255, 0)');
    shade.addColorStop(1, 'rgba(0, 0, 0, 0.25)');
    c.fillStyle = shade;
    c.fill();
    c.beginPath();
    c.ellipse(left + side * 0.3, top + side * 0.27, side * 0.13, side * 0.08, -0.5, 0, Math.PI * 2);
    c.fillStyle = 'rgba(255, 255, 255, 0.5)';
    c.fill();
    c.restore();
  };

  const drawWell = (size) => {
    ctx.fillStyle = COLOUR.well;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = COLOUR.dot;
    for (let y = 0; y <= ROWS - HIDDEN; y++) {
      for (let x = 0; x <= COLS; x++) {
        ctx.beginPath();
        ctx.arc(x * size, y * size, Math.max(1, size * 0.05), 0, Math.PI * 2);
        ctx.fill();
      }
    }
  };

  // Where the piece would land, as an outline
  const drawGhost = (size) => {
    let y = g.y;
    while (fits(g, g.piece, g.rot, g.x, y + 1)) {
      y++;
    }
    if (y === g.y) {
      return;
    }
    ctx.save();
    ctx.strokeStyle = COLOUR[g.piece];
    ctx.globalAlpha = 0.55;
    ctx.lineWidth = Math.max(1, size * 0.07);
    CELLS[g.piece][g.rot].forEach(([cx, cy]) => {
      const row = y + cy - HIDDEN;
      if (row >= 0) {
        const gap = size * 0.12;
        ctx.beginPath();
        ctx.roundRect((g.x + cx) * size + gap, row * size + gap, size - gap * 2, size - gap * 2, size * 0.18);
        ctx.stroke();
      }
    });
    ctx.restore();
  };

  const drawBoard = (now) => {
    const size = canvas.width / COLS;
    drawWell(size);
    for (let row = HIDDEN; row < ROWS; row++) {
      for (let col = 0; col < COLS; col++) {
        const piece = g.board[row * COLS + col];
        if (piece !== '.') {
          drawTile(ctx, col, row - HIDDEN, size, piece);
        }
      }
    }
    if (g.piece && !g.over && state !== 'ready') {
      drawGhost(size);
      // The lock delay shows as the piece dimming a little as it's about to lock
      const fading = resting(g) && !reducedMotion.matches ? 1 - (g.lock / LOCK_TICKS) * 0.35 : 1;
      CELLS[g.piece][g.rot].forEach(([cx, cy]) => {
        if (g.y + cy >= HIDDEN) {
          drawTile(ctx, g.x + cx, g.y + cy - HIDDEN, size, g.piece, fading);
        }
      });
    }
    if (flash) {
      const age = (now - flash.at) / 260;
      if (age >= 1 || reducedMotion.matches) {
        flash = null;
      } else {
        ctx.fillStyle = `rgba(255, 255, 255, ${0.75 * (1 - age)})`;
        flash.rows.forEach((row) => {
          if (row >= 0) {
            ctx.fillRect(0, row * size, canvas.width, size);
          }
        });
      }
    }
  };

  /**
   * A piece on its own, in the middle of the box (x, y, 4 by 3 squares) of a side canvas.
   */
  const drawLone = (c, piece, top, size, dim) => {
    const cells = CELLS[piece][0];
    const xs = cells.map(([x]) => x);
    const ys = cells.map(([, y]) => y);
    const width = Math.max(...xs) - Math.min(...xs) + 1;
    const height = Math.max(...ys) - Math.min(...ys) + 1;
    const offsetX = (4 - width) / 2 - Math.min(...xs);
    const offsetY = top + (3 - height) / 2 - Math.min(...ys);
    cells.forEach(([x, y]) => drawTile(c, x + offsetX, y + offsetY, size, piece, dim ? 0.4 : 1));
  };

  const drawSides = () => {
    const holdSize = holdCanvas.width / 4;
    holdCtx.clearRect(0, 0, holdCanvas.width, holdCanvas.height);
    if (g.hold) {
      drawLone(holdCtx, g.hold, 0, holdSize, !g.canHold);
    }
    const nextSize = nextCanvas.width / 4;
    nextCtx.clearRect(0, 0, nextCanvas.width, nextCanvas.height);
    for (let i = 0; i < 3; i++) {
      // Before the first piece is in, the next ones are numbers 0-2; after that, the ones after the one in play
      const piece = known[g.drawn + i];
      if (piece) {
        drawLone(nextCtx, piece, i * 3, nextSize, false);
      }
    }
  };

  const render = (now) => {
    drawBoard(now);
    drawSides();
    showStats();
  };

  /**
   * One frame: plays the ticks the time since the last frame covers, reports when it's time, and draws.
   */
  const frame = (now) => {
    frameId = requestAnimationFrame(frame);
    const elapsed = Math.min(now - (lastFrame || now), 250);
    lastFrame = now;
    if (state === 'playing') {
      acc += elapsed;
      while (state === 'playing' && acc >= TICK_MS) {
        acc -= TICK_MS;
        tick();
      }
      const low = known.length - g.drawn < LOW_PIECES && now - lastReport >= 1000;
      if (state === 'playing' && ranked && ranked.started && (now - lastReport >= REPORT_MS || low)) {
        report('tick');
      }
    }
    render(now);
  };

  const run = () => {
    if (!frameId) {
      lastFrame = 0;
      frameId = requestAnimationFrame(frame);
    }
  };

  const stop = () => {
    cancelAnimationFrame(frameId);
    frameId = 0;
  };

  // Listeners --------------------------------------------------------------------------------------------------------

  document.addEventListener('keydown', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (event.ctrlKey || event.metaKey || event.altKey || target?.closest('input, select, textarea, dialog')) {
      return;
    }
    const letter = KEYS[event.key];
    const isPause = event.key === 'p' || event.key === 'P' || event.key === 'Escape';
    if (!event.isTrusted && (letter || isPause || event.key === 'Enter')) {
      caught();
      return;
    }
    const onButton = target?.closest('button, a');

    if ((state === 'ready' || state === 'over') && (event.key === ' ' || event.key === 'Enter') && !onButton) {
      event.preventDefault();
      if (!event.repeat) {
        start();
      }
      return;
    }
    if (isPause) {
      pause();
      return;
    }
    if (letter && state === 'playing') {
      event.preventDefault(); // no scrolling on the arrows or Space
      if (!event.repeat) {
        press(letter); // a held key's repeats are the game's own (DAS/ARR), not the keyboard's
      }
    }
  });

  document.addEventListener('keyup', (event) => {
    const letter = KEYS[event.key];
    if (letter) {
      release(letter);
    }
  });

  // The buttons for phones: pressed on pointerdown (a tap shouldn't wait for the finger to lift), held to repeat
  pad.addEventListener('pointerdown', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-blockfall-key]') : null;
    if (!button) {
      return;
    }
    event.preventDefault();
    if (!event.isTrusted) {
      caught();
      return;
    }
    button.setPointerCapture?.(event.pointerId);
    if (state === 'ready' || state === 'over') {
      start();
      return;
    }
    press(button.dataset.blockfallKey);
  });
  ['pointerup', 'pointercancel', 'lostpointercapture'].forEach((type) => {
    pad.addEventListener(type, (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-blockfall-key]') : null;
      if (button) {
        release(button.dataset.blockfallKey);
      }
    });
  });
  pad.addEventListener('contextmenu', (event) => event.preventDefault()); // a long press would open it

  playButton.addEventListener('click', () => {
    if (state === 'paused') {
      pause();
    } else {
      start();
    }
  });

  pauseButton.addEventListener('click', pause);

  modeSelect.addEventListener('change', () => {
    if (MODES[modeSelect.value]) {
      saved.mode = modeSelect.value;
      save();
      if (state === 'over') {
        reset();
        setState('ready');
      }
      showStats();
      showCover();
    }
  });

  well.addEventListener('animationend', () => well.classList.remove('is-hit'));
  callout.addEventListener('animationend', () => callout.classList.remove('is-shown'));

  // Away from the page: pause (which counts as one of its pauses) and stop drawing. Leaving the window without hiding
  // it only lets go of the keys, as their key-ups go elsewhere.
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      if (state === 'playing') {
        pause();
      }
      stop();
    } else {
      run();
    }
  });
  window.addEventListener('blur', releaseAll);

  new ResizeObserver(() => {
    resize();
    render(performance.now());
  }).observe(canvas);

  modeSelect.value = saved.mode;
  reset();
  resize();
  showCover();
  if (!document.hidden) {
    run();
  }
})();
