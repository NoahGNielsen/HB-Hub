// Snake (games/snake/): the whole game, in the browser. Loaded by games/snake/index.php, which has the bar (score, best,
// speed, pause), the board's [data-snake-canvas] and the cover over it ([data-snake-cover], for "ready", "paused" and
// "game over").
//
// The board is COLS x ROWS squares. The game moves on in steps (one square per step, every SPEEDS[speed] ms), and the
// drawing glides between them at the screen's frame rate. A step happens when the drawn head reaches the edge of its
// next square, so during a step the head glides from the middle of the edge it came in over, through the middle of its
// square, to the edge it'll leave by. That way a turn can start the moment it's pressed: before the head reaches the
// middle, it curves round right away, on a quarter circle that starts where the head is (so the earlier the press, the
// rounder the corner), instead of first finishing its slide into the next square. Pressed after the middle but before
// TURN_LATE (85% of the way), it still turns in this square: the corner is sharp, in the middle, and the head darts back
// to it and round, catching up by the end of the step. Pressed later than that, the turn waits for the next square and
// curves round as soon as the head gets there. The body and tail follow the same curves (each part keeps
// the way it came in, the way it left and its corner's size), so the snake bends smoothly like a tube.
//
// The leaderboard: the server plays every game along with this one (assets/php/games/snake/snake.php, which has the
// anticheat), through games/snake/move. The page comes with the next game (data-snake-game: its id and the first
// "rolls", which say where the apples go) and the best scores (data-snake-bests, the server's). When the snake starts
// moving it sends "start" with the speed; then it reports the steps made and the turns since the last report
// (report(): "12u,15l" - on step 12 it went up), every REPORT_MS, each time it eats, when it's paused or carries on, and
// with "finish" when it crashes. Each answer brings the rolls for the next apples, and the answer to "finish" has the
// server's score, the best and the next game. Steps are counted in step(), one per square: a turn is logged with the
// step it's made on, whichever way it was pressed (at once or queued), which is all the server needs.
// A game stops counting (unrank()) when the server says so, a request fails, the page runs out of rolls, or a key press
// or swipe wasn't made by a person (event.isTrusted false: made by code, like from the console - it's ignored, too).
// Then it's played on as normal, the apples coming from Math.random, and [data-snake-note] says why it won't count.
//
// localStorage (hbHubSnake) only keeps {"speed": "slow" | "normal" | "fast"}, the last speed chosen (it's listed in the
// privacy policy). Older versions kept "best" there too, which is now ignored.
//
// The guest Snake (/play/snake, [data-snake] has data-snake-guest) is the same game without the server: no server
// game, no reports, the apples from Math.random, and no red line. Its best score at each speed is kept in localStorage
// instead, as "guestBest" next to the speed ({"speed": ..., "guestBest": {"slow": apples, ...}}), and never comes over
// to an account. Each game over asks them to make an account (the guest:prompt event, guestPrompt.js), and while the
// snake is moving [data-snake] has data-guest-prompt-wait, so the popup's timer never opens it in the middle of a game.
(() => {
  const root = document.querySelector('[data-snake]');
  if (!root) {
    return;
  }

  const COLS = 20;
  const ROWS = 12;
  const START_LENGTH = 4;
  const SPEEDS = { slow: 160, normal: 120, fast: 85 }; // ms per step
  // A turn pressed before the step is this far through turns the head in the square it's in, straight away (past the
  // middle, on a sharp corner it darts back to - see turnNow()); later than that it turns as soon as it's in the next one
  const TURN_LATE = 0.85;
  const EATEN_FOR = 0.3; // steps the eaten apple stays drawn, until the snout has reached it
  const STORAGE_KEY = 'hbHubSnake';

  // The server's side (assets/php/games/snake/snake.php: the board and speeds above must match its HBHUB_SNAKE_*)
  const MOVE_URL = '/games/snake/move';
  const REPORT_MS = 4000; // how often the game reports while it's going, when it hasn't eaten
  const MAX_PAUSES = 10; // HBHUB_SNAKE_MAX_PAUSES: one more and the game stops counting
  const LATE_MS = 1000; // a round that started before its server game came can take it this long after (see gotNext())
  const FINISHED = 1; // HBHUB_SNAKE_FINISHED, the result of a game that counts (0 is still going)
  // Why a game doesn't count: the server's results (HBHUB_SNAKE_*) and the page's own reasons
  const NOT_COUNTED = 'This game doesn\'t count for the leaderboard';
  const WHY = {
    2: `${NOT_COUNTED}: a new game was started in another tab.`,
    3: `${NOT_COUNTED}: a key press or swipe came from code, not from you, so it was ignored.`,
    4: `${NOT_COUNTED}: it didn't keep time with HB Hub's clock (was the game slowed down or sped up?).`,
    5: `${NOT_COUNTED}: HB Hub couldn't make the same moves.`,
    6: `${NOT_COUNTED}: it was paused more than ${MAX_PAUSES} times.`,
    noGame: `${NOT_COUNTED}: HB Hub couldn't be reached in time when it started.`,
    connection: `${NOT_COUNTED}: the connection to HB Hub was lost.`,
    error: `${NOT_COUNTED}: something went wrong on HB Hub.`,
  };

  // The colours, picked to match the look of the board in the CSS
  const COLOUR = {
    light: '#ebc464', // the checkered squares
    dark: '#e8b263',
    snakeEdge: '#4565d6',
    snake: '#5a7de8',
    snakeShine: '#7f9cf0',
    shadow: 'rgba(130, 70, 20, 0.35)',
    eyeRing: '#4a6ae0',
    pupil: '#141a3a',
  };

  const DIRECTIONS = {
    up: { x: 0, y: -1 },
    down: { x: 0, y: 1 },
    left: { x: -1, y: 0 },
    right: { x: 1, y: 0 },
  };

  const KEYS = {
    ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right',
    w: 'up', s: 'down', a: 'left', d: 'right',
    W: 'up', S: 'down', A: 'left', D: 'right',
  };

  const canvas = root.querySelector('[data-snake-canvas]');
  const ctx = canvas.getContext('2d');
  const board = root.querySelector('[data-snake-board]');
  const cover = root.querySelector('[data-snake-cover]');
  const coverTitle = root.querySelector('[data-snake-cover-title]');
  const coverText = root.querySelector('[data-snake-cover-text]');
  const playButton = root.querySelector('[data-snake-play]');
  const pauseButton = root.querySelector('[data-snake-pause]');
  const speedSelect = root.querySelector('[data-snake-speed]');
  const scoreOut = root.querySelector('[data-snake-score]');
  const bestOut = root.querySelector('[data-snake-best]');
  const note = root.querySelector('[data-snake-note]');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const guest = 'snakeGuest' in root.dataset; // the guest Snake: no server (see the top)

  const readJson = (text) => {
    try {
      return JSON.parse(text || 'null');
    } catch {
      return null;
    }
  };

  /**
   * The saved speed. Wrapped in try/catch, as storage can be blocked (private windows etc.), and the game works
   * without it, it just forgets.
   */
  const load = () => {
    try {
      const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
      const guestBest = {};
      Object.keys(SPEEDS).forEach((speed) => {
        const score = saved.guestBest?.[speed];
        guestBest[speed] = Number.isInteger(score) && score > 0 ? score : 0;
      });
      return { speed: SPEEDS[saved.speed] ? saved.speed : 'normal', guestBest };
    } catch {
      return { speed: 'normal', guestBest: {} };
    }
  };

  const save = () => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(guest ? { speed: saved.speed, guestBest: best } : { speed: saved.speed }));
    } catch {
      // Storage is blocked or full: the speed (and a guest's best) is just not kept
    }
  };

  const saved = load();
  // The best score at each speed, from the games on the leaderboard (the server's), or a guest's from this browser
  const best = { slow: 0, normal: 0, fast: 0, ...(guest ? saved.guestBest : readJson(root.dataset.snakeBests) || {}) };

  // The game. snake[0] is the head; each part is {x, y, in, out, k}: its square, the way the snake came into it and
  // left it (DIRECTIONS entries; for the head, `out` is the way it's going to leave), and, where those differ, k: the
  // radius of the corner, in squares (0.5 is the roundest, 0 a sharp corner).
  let state = 'ready'; // ready | playing | paused | over | won
  let snake = [];
  let grew = false; // the snake ate on the last step, so the tail stays put this step
  let dir = DIRECTIONS.right; // the way the head leaves its square (already the new way once it has turned there)
  let turned = false; // the head has turned in the square it's in, so the next turn has to wait for the next square
  let queue = []; // turns pressed but not made yet, so two quick presses both count
  let apple = { x: 0, y: 0 };
  let appleBorn = 0; // when the apple appeared, for its pop-in
  let eaten = null; // {x, y, at}: the apple just eaten, still drawn until the head has slid onto it
  let score = 0;
  let newBest = false; // the game that just ended beat the best at its speed
  let progress = 0; // how far the current step has got, 0-1 (at 1 the head reaches its next square)
  let lastFrame = 0;
  let frameId = 0;
  let nextBlink = performance.now() + 2500; // when the eyes next close, for 140 ms
  let size = 0; // one square, in canvas pixels

  // The leaderboard (see the top)
  let nextGame = readJson(root.dataset.snakeGame); // the server's game for the next round, {game, rollsFrom, rolls}, or null
  let ranked = null; // this round's server game while it counts, {game: id, rolls: [index => roll], started}, or null
  let why = ''; // why this round doesn't count (a key of WHY), or ''
  let counted = false; // the round that just ended is on the leaderboard (the server said so)
  let finishing = false; // the answer to "finish" is on its way
  let fetching = false; // asking for a new game
  let steps = 0; // squares the snake has moved this round
  let logged = DIRECTIONS.right; // the way the last step went, so each turn is logged once
  let turns = []; // the turns since the last report, like "12u"
  let pauses = 0;
  let lastReport = 0; // performance.now() of the last report
  let requests = Promise.resolve(); // the requests go one at a time, in order
  const LETTER = new Map(Object.entries(DIRECTIONS).map(([name, way]) => [way, name[0]])); // up => 'u', ...

  // The server -----------------------------------------------------------------------------------------------------

  /**
   * One request to games/snake/move. Resolves with the answer, or throws an error with .code: the server's error code,
   * or 'network'.
   */
  const post = async (action, fields, keepalive) => {
    let response;
    try {
      response = await fetch(MOVE_URL, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new URLSearchParams({ action, ...fields }),
        keepalive, // a pause is often sent as the page is being hidden or closed
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

  // In line after the ones before it, so the server gets the start, the reports and the pauses in the order they happened
  const send = (action, fields = {}, keepalive = false) => {
    const request = requests.then(() => post(action, fields, keepalive));
    requests = request.catch(() => {});
    return request;
  };

  // A failed request as a key of WHY: "gameOver" mostly means another tab has made a new game since
  const failure = (error) => ({ network: 'connection', gameOver: 2 })[error.code] || 'error';

  // The rolls in an answer ({rollsFrom, rolls}) go into the game's list
  const addRolls = (game, data) => {
    if (data && Array.isArray(data.rolls)) {
      data.rolls.forEach((roll, i) => {
        game.rolls[data.rollsFrom + i] = roll;
      });
    }
  };

  const showNote = () => {
    note.hidden = !why;
    note.textContent = why ? WHY[why] : '';
  };

  /**
   * This round stops counting for the leaderboard, for `reason` (a key of WHY). It's played on as normal.
   */
  const unrank = (game, reason) => {
    if (!game || ranked !== game) {
      return;
    }
    ranked = null;
    why = reason in WHY ? reason : 'error';
    showNote();
    showScore();
    showCover();
  };

  /**
   * Takes the server's next game for this round, or, when there isn't one (the server couldn't be reached), plays this
   * round without and asks for one, which this round takes if it's back before the snake starts.
   */
  const adopt = () => {
    ranked = null;
    why = '';
    if (guest) {
      return; // a guest's game is only in the browser
    }
    if (nextGame) {
      ranked = { game: nextGame.game, rolls: [], started: false };
      addRolls(ranked, nextGame);
      nextGame = null;
    } else {
      why = 'noGame';
      if (!finishing) {
        fetchNew(); // otherwise the answer to "finish" brings one (asking twice would throw that one away)
      }
    }
    showNote();
  };

  /**
   * The server's next game is here. A round that's waiting takes it. So does one that started without it (Play again
   * pressed before the answer to the last game came back), while it's still in its first LATE_MS and hasn't eaten: the
   * server can still make those steps from the turns (they're logged either way, and the first apple is always in the
   * same place), and they're within the clock's leeway.
   */
  const gotNext = (data) => {
    nextGame = data;
    if (ranked) {
      return;
    }
    if (state === 'ready') {
      adopt();
    } else if (state === 'playing' && why === 'noGame' && score === 0 && steps * SPEEDS[saved.speed] < LATE_MS) {
      adopt();
      startOnServer(ranked);
    }
  };

  // Tells the server the snake has started moving, which starts its clock
  const startOnServer = (game) => {
    game.started = true;
    lastReport = performance.now();
    send('start', { game: game.game, speed: saved.speed }).catch((error) => unrank(game, failure(error)));
  };

  // Asks for the next game, when there isn't one already (or on its way)
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
   * Reports how far the snake has got to the server ('tick', 'pause', 'resume' or 'finish'): the steps so far, and the
   * turns since the last report. Resolves with the answer, or {failed: a key of WHY} when the request failed. When the
   * server says the game is over (or it failed), anything but 'finish' stops this round counting.
   */
  const report = (action, keepalive = false) => {
    const game = ranked;
    if (!game || !game.started) {
      return Promise.resolve({ failed: 'error' });
    }
    const fields = { game: game.game, steps, turns: turns.join(',') };
    turns = [];
    lastReport = performance.now();
    return send(action, fields, keepalive).then(
      (data) => {
        addRolls(game, data);
        if (action !== 'finish' && data.result !== 0) {
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
   * A new snake, lying START_LENGTH squares long on the left of the middle row, looking right, and an apple ahead of it
   * (the server's hbHubSnakeNew() starts the same way). It takes the server's next game, unless it has one already.
   */
  const reset = () => {
    const row = Math.floor(ROWS / 2);
    snake = [];
    for (let i = 0; i < START_LENGTH; i++) {
      snake.push({ x: START_LENGTH + 1 - i, y: row, in: DIRECTIONS.right, out: DIRECTIONS.right });
    }
    grew = false;
    dir = DIRECTIONS.right;
    turned = false;
    queue = [];
    score = 0;
    steps = 0;
    logged = DIRECTIONS.right;
    turns = [];
    pauses = 0;
    counted = false;
    if (!ranked) {
      adopt();
    }
    progress = 0.5; // the head sits in the middle of its square until it starts
    eaten = null;
    apple = { x: COLS - 5, y: row };
    appleBorn = performance.now();
    showScore();
  };

  /**
   * Puts the apple on a free square: free[roll % free.length], the roll being the server's for this apple while the
   * game counts (hbHubSnakeStep() picks the same one), or a random one. Returns false when there isn't a free square
   * left (the snake fills the board).
   */
  const placeApple = () => {
    const taken = new Set(snake.map((part) => part.y * COLS + part.x));
    const free = [];
    for (let i = 0; i < COLS * ROWS; i++) {
      if (!taken.has(i)) {
        free.push(i);
      }
    }
    if (!free.length) {
      return false;
    }
    let roll = Math.floor(Math.random() * 2 ** 28);
    if (ranked) {
      // The rolls come ahead of time, but a slow connection can fall behind: then it can't count any more
      if (ranked.rolls[score - 1] === undefined) {
        unrank(ranked, 'connection');
      } else {
        roll = ranked.rolls[score - 1];
      }
    }
    const pick = free[roll % free.length];
    apple = { x: pick % COLS, y: Math.floor(pick / COLS) };
    appleBorn = performance.now();
    return true;
  };

  // The score, and the best at this speed: the server's, or this round's score once it's more and the round counts
  // (a guest's always counts for their own best)
  const showScore = () => {
    scoreOut.textContent = score;
    bestOut.textContent = Math.max(best[saved.speed], ranked || finishing || guest ? score : 0);
  };

  /**
   * Shows the cover over the board for the given state, or hides it while playing.
   */
  const showCover = () => {
    const left = MAX_PAUSES - pauses;
    let pauseText = '';
    if (ranked) {
      pauseText = left > 0
        ? ` You can pause ${left} more ${left === 1 ? 'time' : 'times'} and keep this game on the leaderboard.`
        : ' Pause again and this game won\'t count for the leaderboard.';
    }
    // The best only once the server has answered, and only for a game that counts
    let bestText = '';
    if (counted) {
      bestText = newBest ? ' That\'s a new best!' : ` Your best is ${best[saved.speed]}.`;
    }
    const texts = {
      ready: ['Ready?', 'Press an arrow key or WASD, or swipe, to start.', 'Play'],
      paused: ['Paused', 'Press P or Esc, or the button, to carry on.' + pauseText, 'Carry on'],
      over: ['Game over', `You ate ${score} ${score === 1 ? 'apple' : 'apples'}.` + bestText, 'Play again'],
      won: ['You win!', `The snake fills the whole board, with ${score} apples eaten.` + bestText, 'Play again'],
    };

    cover.hidden = state === 'playing';
    cover.dataset.state = state;
    if (texts[state]) {
      [coverTitle.textContent, coverText.textContent, playButton.textContent] = texts[state];
    }
    pauseButton.disabled = state !== 'playing' && state !== 'paused';
    pauseButton.textContent = state === 'paused' ? 'Carry on' : 'Pause';
    speedSelect.disabled = state === 'playing' || state === 'paused';
  };

  const setState = (next) => {
    state = next;
    // The guest's sign-up popup (guestPrompt.js) waits while the snake is moving, so it never crashes it
    root.toggleAttribute('data-guest-prompt-wait', guest && state === 'playing');
    showCover();
  };

  /**
   * Starts moving: from "ready" with the snake as it lies, or after a game over with a new snake. A game that counts
   * tells the server, which starts its clock.
   */
  const start = () => {
    if (state === 'over' || state === 'won') {
      reset();
    }
    setState('playing');
    if (ranked && !ranked.started) {
      startOnServer(ranked);
    }
  };

  /**
   * Turns the head in the square it's in, right now. The corner starts where the drawn head is (it has come `progress`
   * of the way from the edge), so its radius is however far the head still is from the square's middle. When the head
   * has already passed the middle, the corner is sharp, in the middle, and `late` keeps how far the step had got, so
   * the head goes on from the middle and makes up the distance by the end of the step (see squareLine()'s time()).
   */
  const turnNow = (wanted) => {
    dir = wanted;
    turned = true;
    snake[0].out = wanted;
    snake[0].k = 0.5 - Math.min(progress, 0.5);
    snake[0].late = progress > 0.5 ? progress : null;
  };

  /**
   * Turns the snake (when the game is going or about to start). A turn back into itself, or the way it's going already,
   * is ignored. A turn happens straight away when it can (see TURN_LATE); otherwise it queues up, up to 3, so a quick
   * up-then-left makes both turns, one per square.
   */
  const turn = (name) => {
    if (state !== 'ready' && state !== 'playing') {
      return; // after a game over it's Space, Enter or the button, so a key still held from the crash doesn't restart
    }
    const wanted = DIRECTIONS[name];
    const last = queue.length ? queue[queue.length - 1] : dir;
    const back = wanted.x === -last.x && wanted.y === -last.y;

    if (state === 'ready') {
      if (!back) {
        start(); // any way but back into itself starts it
        if (wanted !== dir) {
          turnNow(wanted);
        }
      }
      return;
    }
    if (wanted === last || back) {
      return;
    }
    if (!queue.length && !turned && progress < TURN_LATE) {
      turnNow(wanted);
    } else if (queue.length < 3) {
      queue.push(wanted);
    }
  };

  // The square the head moves into on the next step
  const nextSquare = () => ({ x: snake[0].x + dir.x, y: snake[0].y + dir.y });

  /**
   * Whether the next step crashes, into a wall or the snake itself. The tail moves out of the way that step, unless
   * the snake is growing, so heading into it is fine.
   */
  const blocked = () => {
    const next = nextSquare();
    const eating = next.x === apple.x && next.y === apple.y;
    const body = eating ? snake : snake.slice(0, -1);
    const hitsWall = next.x < 0 || next.y < 0 || next.x >= COLS || next.y >= ROWS;
    return hitsWall || body.some((part) => part.x === next.x && part.y === next.y);
  };

  /**
   * One step: moves the head one square, eats, grows or wins, then makes the next queued turn in the new square.
   * (Crashing is checked before this, as soon as the head reaches the middle of its square - see frame().)
   */
  const step = () => {
    if (blocked()) {
      die();
      return;
    }
    const next = { ...nextSquare(), in: dir, out: dir };
    const eating = next.x === apple.x && next.y === apple.y;
    // For the server: the step this one is, and the way it goes when that's new (however the turn was made)
    if (dir !== logged) {
      turns.push(`${steps}${LETTER.get(dir)}`);
      logged = dir;
    }
    steps++;

    snake.unshift(next);
    turned = false;
    grew = eating;
    if (eating) {
      score++;
      eaten = { ...apple, at: performance.now() };
      showScore();
      if (!placeApple()) {
        finish('won');
      }
    } else {
      snake.pop();
    }
    if (queue.length && state === 'playing') {
      turnNow(queue.shift());
    }
    if (eating && ranked && state === 'playing') {
      report('tick'); // straight away, for the next apple's roll
    }
  };

  const die = () => {
    progress = 0.5; // the snake stops with its head in the middle of its square, its nose against what it hit
    finish('over');
    if (!reducedMotion.matches) {
      board.classList.remove('is-hit');
      void board.offsetWidth; // restarts the shake if it's still going
      board.classList.add('is-hit');
    }
  };

  /**
   * Ends the game ('over' or 'won'). A game that counts tells the server, whose answer has the score as it counted it,
   * the best at this speed, and the next game; until then the cover only has the score.
   */
  const finish = (how) => {
    const game = ranked && ranked.started ? ranked : null;
    const speed = saved.speed;
    newBest = false;
    counted = false;
    if (guest) {
      // A guest's best is this browser's own, so it's settled right here
      counted = true;
      if (score > best[speed]) {
        best[speed] = score;
        newBest = true;
        save();
      }
      // A game just ended is a good moment to ask them to make an account (guestPrompt.js, not too often)
      document.dispatchEvent(new CustomEvent('guest:prompt'));
    }
    finishing = Boolean(game);
    const sent = report('finish'); // before ranked goes, as it sends ranked's game
    ranked = null;
    setState(how);
    showScore();
    if (!game) {
      fetchNew();
      return;
    }

    sent.then((data) => {
      finishing = false;
      if (data.result === FINISHED) {
        counted = true;
        best[speed] = data.best;
        newBest = data.isBest;
      } else {
        why = data.failed || (data.result in WHY ? data.result : 'error');
        showNote();
      }
      if (data.next) {
        gotNext(data.next);
      } else {
        fetchNew();
      }
      if (state === 'over' || state === 'won') {
        showCover();
      }
      showScore();
    });
  };

  // Pause, or carry on. Both go to the server, which stops its clock while paused (and counts the pauses).
  const pause = () => {
    if (state === 'playing') {
      pauses++;
      report('pause', true);
      setState('paused');
    } else if (state === 'paused') {
      setState('playing');
      report('resume');
    }
  };

  // Drawing ---------------------------------------------------------------------------------------------------------

  /**
   * Makes the canvas as sharp as the screen (its CSS size times the pixel ratio), and works out one square's size.
   */
  const resize = () => {
    const ratio = window.devicePixelRatio || 1;
    const width = Math.max(1, Math.round(canvas.clientWidth * ratio));
    canvas.width = width;
    canvas.height = Math.round((width * ROWS) / COLS);
    size = width / COLS;
  };

  // The middle of a square, in canvas pixels
  const px = (point) => ({ x: (point.x + 0.5) * size, y: (point.y + 0.5) * size });

  const drawBoard = () => {
    for (let y = 0; y < ROWS; y++) {
      for (let x = 0; x < COLS; x++) {
        ctx.fillStyle = (x + y) % 2 === 0 ? COLOUR.light : COLOUR.dark;
        // A hair bigger than the square, so no seams show between them when the size isn't a whole number
        ctx.fillRect(Math.floor(x * size), Math.floor(y * size), Math.ceil(size) + 1, Math.ceil(size) + 1);
      }
    }
  };

  /**
   * The apple, centred on a square, scaled by `scale` (for its pop-in). Drawn in "square" units, so 1 is a square wide.
   */
  const drawApple = (point, scale) => {
    if (scale <= 0) {
      return;
    }
    const centre = px(point);
    ctx.save();
    ctx.translate(centre.x, centre.y + size * 0.06);
    ctx.scale(size * scale, size * scale);

    // The body: two round lobes with a dip at the top and a little one at the bottom
    ctx.beginPath();
    ctx.moveTo(0, -0.24);
    ctx.bezierCurveTo(0.14, -0.36, 0.44, -0.34, 0.44, -0.02);
    ctx.bezierCurveTo(0.44, 0.26, 0.26, 0.46, 0.12, 0.44);
    ctx.bezierCurveTo(0.06, 0.43, 0.03, 0.4, 0, 0.4);
    ctx.bezierCurveTo(-0.03, 0.4, -0.06, 0.43, -0.12, 0.44);
    ctx.bezierCurveTo(-0.26, 0.46, -0.44, 0.26, -0.44, -0.02);
    ctx.bezierCurveTo(-0.44, -0.34, -0.14, -0.36, 0, -0.24);
    ctx.closePath();
    const red = ctx.createRadialGradient(-0.12, -0.1, 0.05, 0, 0.05, 0.55);
    red.addColorStop(0, '#ec5b4c');
    red.addColorStop(1, '#bf2c26');
    ctx.fillStyle = red;
    ctx.shadowColor = COLOUR.shadow;
    ctx.shadowBlur = size * 0.12;
    ctx.shadowOffsetY = size * 0.08;
    ctx.fill();
    ctx.shadowColor = 'transparent';

    // The shine on its left
    ctx.beginPath();
    ctx.ellipse(-0.24, -0.06, 0.06, 0.11, 0.35, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255, 255, 255, 0.45)';
    ctx.fill();

    // The stem and the leaf
    ctx.beginPath();
    ctx.moveTo(0, -0.22);
    ctx.quadraticCurveTo(0, -0.38, 0.06, -0.46);
    ctx.strokeStyle = '#6b3e1e';
    ctx.lineWidth = 0.06;
    ctx.lineCap = 'round';
    ctx.stroke();

    ctx.beginPath();
    ctx.moveTo(0.05, -0.38);
    ctx.quadraticCurveTo(0.13, -0.56, 0.34, -0.52);
    ctx.quadraticCurveTo(0.25, -0.36, 0.05, -0.38);
    ctx.fillStyle = '#6cb043';
    ctx.fill();
    ctx.restore();
  };

  /**
   * The line the snake runs along through one part's square, from the middle of the edge it came in over to the middle
   * of the edge it leaves by: straight, or round the corner on a quarter circle of radius k, with a straight bit of
   * 0.5 - k before and after it. Distances along it are in squares. Gives:
   *   length          how long it is
   *   at(s)           the point s along it
   *   angle(s)        the way it's heading there, in radians
   *   time(t)         how far along the head (or tail) is when the step has got t (0-1) of the way: the straight bit
   *                   before the corner goes at the usual speed (the head was on it before it turned), and the rest of
   *                   the line shares out what's left of the step. After a late turn (part.late, made past the middle)
   *                   the head is put back to the corner in the middle and the rest shares out what's left after
   *                   part.late; the tail does the same when it gets there, so the snake keeps its length
   *   points(from, to)  the line between two distances, as points to draw through
   */
  const squareLine = (part) => {
    const inD = part.in;
    const outD = part.out;
    const k = inD === outD ? 0 : part.k;
    const before = 0.5 - k;
    const arc = (Math.PI / 2) * k;
    const length = before * 2 + arc;
    // The middle of the corner's circle: k back from the square's middle, and k over toward the way out
    const centre = { x: part.x - inD.x * k + outD.x * k, y: part.y - inD.y * k + outD.y * k };

    const at = (s) => {
      if (s <= before) {
        return { x: part.x + inD.x * (s - 0.5), y: part.y + inD.y * (s - 0.5) };
      }
      if (s >= before + arc) {
        const d = s - before - arc + k;
        return { x: part.x + outD.x * d, y: part.y + outD.y * d };
      }
      const a = (s - before) / k;
      return {
        x: centre.x - outD.x * k * Math.cos(a) + inD.x * k * Math.sin(a),
        y: centre.y - outD.y * k * Math.cos(a) + inD.y * k * Math.sin(a),
      };
    };

    const angleIn = Math.atan2(inD.y, inD.x);
    const turnBy = Math.atan2(inD.x * outD.y - inD.y * outD.x, inD.x * outD.x + inD.y * outD.y); // 0 or +-90 degrees
    const angle = (s) => {
      const round = arc ? Math.min(Math.max((s - before) / arc, 0), 1) : (s > before ? 1 : 0);
      return angleIn + turnBy * round;
    };

    const from = inD === outD || part.late == null ? before : part.late; // how far the step is when it starts the corner
    const time = (t) => (t <= from ? Math.min(t, before) : before + ((t - from) / (1 - from)) * (length - before));

    // Where the line bends: the ends of the corner, and points along it close enough together to look round
    const bends = [before];
    for (let i = 1; i < 8; i++) {
      bends.push(before + (arc * i) / 8);
    }
    bends.push(before + arc);
    const points = (from, to) => [at(from), ...bends.filter((s) => s > from && s < to).map(at), at(to)];

    return { length, at, angle, time, points };
  };

  /**
   * The line the whole snake's body runs along, in canvas pixels from the head to the tail, and the way the head is
   * facing. The head has come `progress` of the way through its square and the tail has gone that far through its
   * own (unless the snake just ate, when the tail waits), so the snake stays the same length as it glides.
   */
  const snakeLine = () => {
    const last = snake.length - 1;
    const tail = squareLine(snake[last]);
    const points = tail.points(grew ? tail.length : tail.time(progress), tail.length);
    for (let i = last - 1; i > 0; i--) {
      const line = squareLine(snake[i]);
      points.push(...line.points(0, line.length));
    }
    const head = squareLine(snake[0]);
    const s = head.time(progress);
    points.push(...head.points(0, s));
    return { points: points.reverse().map(px), facing: head.angle(s) };
  };

  const strokeLine = (points, width, colour) => {
    ctx.beginPath();
    ctx.moveTo(points[0].x, points[0].y);
    for (let i = 1; i < points.length; i++) {
      ctx.lineTo(points[i].x, points[i].y);
    }
    ctx.lineWidth = width;
    ctx.strokeStyle = colour;
    ctx.stroke();
  };

  /**
   * The snake: a round tube (a dark edge, the body, and a lighter stripe down the middle), then its head - a snout with
   * two nostrils sticking out ahead and two big googly eyes, looking at the apple. When it's crashed, the eyes are Xs.
   */
  const drawSnake = (now) => {
    const { points, facing } = snakeLine();
    ctx.save();
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    ctx.shadowColor = COLOUR.shadow;
    ctx.shadowBlur = size * 0.14;
    ctx.shadowOffsetY = size * 0.1;
    strokeLine(points, size * 0.8, COLOUR.snakeEdge);
    ctx.shadowColor = 'transparent';
    strokeLine(points, size * 0.6, COLOUR.snake);
    strokeLine(points, size * 0.22, COLOUR.snakeShine);

    // The head, pointing the way it's going
    const head = points[0];
    const forward = { x: Math.cos(facing), y: Math.sin(facing) };
    const side = { x: -forward.y, y: forward.x };
    const at = (ahead, across) => ({
      x: head.x + (forward.x * ahead + side.x * across) * size,
      y: head.y + (forward.y * ahead + side.y * across) * size,
    });

    // Snout and nostrils
    const snout = at(0.3, 0);
    ctx.beginPath();
    ctx.arc(snout.x, snout.y, size * 0.3, 0, Math.PI * 2);
    ctx.fillStyle = COLOUR.snake;
    ctx.fill();
    ctx.fillStyle = COLOUR.pupil;
    [-0.07, 0.07].forEach((across) => {
      const nostril = at(0.5, across);
      ctx.beginPath();
      ctx.arc(nostril.x, nostril.y, size * 0.025, 0, Math.PI * 2);
      ctx.fill();
    });

    // Eyes: they look toward the apple, and blink now and then
    if (now > nextBlink + 140) {
      nextBlink = now + 2500 + Math.random() * 3500;
    }
    const blinking = now > nextBlink && state !== 'over';
    const appleAt = px(apple);
    [-0.3, 0.3].forEach((across) => {
      const eye = at(-0.02, across);
      ctx.beginPath();
      ctx.arc(eye.x, eye.y, size * 0.29, 0, Math.PI * 2);
      ctx.fillStyle = COLOUR.eyeRing;
      ctx.shadowColor = 'rgba(0, 0, 0, 0.2)';
      ctx.shadowBlur = size * 0.06;
      ctx.fill();
      ctx.shadowColor = 'transparent';

      if (blinking) {
        ctx.beginPath();
        ctx.moveTo(eye.x - side.x * size * 0.15, eye.y - side.y * size * 0.15);
        ctx.lineTo(eye.x + side.x * size * 0.15, eye.y + side.y * size * 0.15);
        ctx.strokeStyle = COLOUR.pupil;
        ctx.lineWidth = size * 0.04;
        ctx.stroke();
        return;
      }

      ctx.beginPath();
      ctx.arc(eye.x, eye.y, size * 0.235, 0, Math.PI * 2);
      ctx.fillStyle = '#fff';
      ctx.fill();

      if (state === 'over') {
        const r = size * 0.08;
        ctx.beginPath();
        ctx.moveTo(eye.x - r, eye.y - r);
        ctx.lineTo(eye.x + r, eye.y + r);
        ctx.moveTo(eye.x + r, eye.y - r);
        ctx.lineTo(eye.x - r, eye.y + r);
        ctx.strokeStyle = COLOUR.pupil;
        ctx.lineWidth = size * 0.04;
        ctx.stroke();
        return;
      }

      const look = Math.hypot(appleAt.x - eye.x, appleAt.y - eye.y) || 1;
      const pupil = {
        x: eye.x + ((appleAt.x - eye.x) / look) * size * 0.08,
        y: eye.y + ((appleAt.y - eye.y) / look) * size * 0.08,
      };
      ctx.beginPath();
      ctx.arc(pupil.x, pupil.y, size * 0.115, 0, Math.PI * 2);
      ctx.fillStyle = COLOUR.pupil;
      ctx.fill();
      ctx.beginPath();
      ctx.arc(pupil.x + size * 0.03, pupil.y - size * 0.03, size * 0.03, 0, Math.PI * 2);
      ctx.fillStyle = '#fff';
      ctx.fill();
    });
    ctx.restore();
  };

  const draw = (now) => {
    const stepMs = SPEEDS[saved.speed];

    drawBoard();

    // The apple just eaten stays until the snout has reached it; the new one pops in after that
    if (eaten && now - eaten.at < stepMs * EATEN_FOR) {
      drawApple(eaten, 1);
    } else {
      eaten = null;
    }
    if (state !== 'won') {
      const age = now - appleBorn - (eaten ? stepMs * EATEN_FOR : 0);
      const pop = reducedMotion.matches ? 1 : Math.min(Math.max(age / 220, 0), 1);
      const bounce = pop < 1 ? Math.sin(pop * Math.PI * 0.75) / Math.sin(Math.PI * 0.75) : 1;
      const breathe = reducedMotion.matches ? 0 : Math.sin(now / 400) * 0.03;
      drawApple(apple, (pop < 1 ? bounce : 1) + breathe);
    }

    drawSnake(now);
  };

  /**
   * One frame: moves the game on by however many steps the time since the last frame covers, and draws it. Only runs
   * while the page is on screen.
   */
  const frame = (now) => {
    frameId = requestAnimationFrame(frame);
    const elapsed = Math.min(now - (lastFrame || now), 250);
    lastFrame = now;
    if (state === 'playing') {
      progress += elapsed / SPEEDS[saved.speed];
      while (state === 'playing') {
        if (progress >= TURN_LATE && blocked()) {
          // Too late to turn any more in this square, so the crash is certain: it happens right here (die() puts the
          // head back in the middle, its nose against what it hit) rather than with half the head in the wall
          die();
        } else if (progress >= 1) {
          progress -= 1;
          step();
        } else {
          break;
        }
      }
      if (state === 'playing' && ranked && now - lastReport >= REPORT_MS) {
        report('tick');
      }
    }
    draw(now);
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

  // Controls --------------------------------------------------------------------------------------------------------

  /**
   * A key press or swipe that wasn't made by a person (made by code, like from the console): it's ignored, and the
   * game stops counting (the server ends it as scripted).
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

  document.addEventListener('keydown', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (event.ctrlKey || event.metaKey || event.altKey || target?.closest('input, select, textarea, dialog')) {
      return;
    }

    const name = KEYS[event.key];
    if (!event.isTrusted && (name || event.key === 'p' || event.key === 'P' || event.key === 'Escape')) {
      caught();
      return;
    }
    if (name) {
      if (event.key.startsWith('Arrow')) {
        event.preventDefault(); // don't scroll the page
      }
      if (state !== 'paused') {
        turn(name);
      }
      return;
    }

    if (event.key === 'p' || event.key === 'P' || event.key === 'Escape') {
      pause();
    } else if ((event.key === ' ' || event.key === 'Enter') && (state === 'over' || state === 'won') && !target?.closest('button, a')) {
      event.preventDefault();
      start();
    }
  });

  // Swipes on the board: a finger moved 24 px turns that way, and keeps turning as it carries on, so one swipe can
  // make a few turns
  let swipeFrom = null;
  board.addEventListener('pointerdown', (event) => {
    if (event.pointerType !== 'mouse' && event.isTrusted) {
      swipeFrom = { x: event.clientX, y: event.clientY };
    }
  });
  board.addEventListener('pointermove', (event) => {
    if (!swipeFrom || state === 'paused') {
      return;
    }
    if (!event.isTrusted) {
      caught();
      return;
    }
    const dx = event.clientX - swipeFrom.x;
    const dy = event.clientY - swipeFrom.y;
    if (Math.max(Math.abs(dx), Math.abs(dy)) < 24) {
      return;
    }
    turn(Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up'));
    swipeFrom = { x: event.clientX, y: event.clientY };
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach((type) => {
    board.addEventListener(type, () => {
      swipeFrom = null;
    });
  });

  playButton.addEventListener('click', () => {
    if (state === 'paused') {
      pause();
    } else {
      start();
    }
  });

  pauseButton.addEventListener('click', pause);

  speedSelect.addEventListener('change', () => {
    if (SPEEDS[speedSelect.value]) {
      saved.speed = speedSelect.value;
      save();
      showScore();
      if (state === 'over' || state === 'won') {
        reset();
        setState('ready');
      }
    }
  });

  board.addEventListener('animationend', () => board.classList.remove('is-hit'));

  // Away from the page: pause the game (which counts as one of its pauses) and stop drawing
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

  new ResizeObserver(() => {
    resize();
    draw(performance.now());
  }).observe(canvas);

  speedSelect.value = saved.speed;
  reset();
  resize();
  showCover();
  if (!document.hidden) {
    run();
  }
})();
