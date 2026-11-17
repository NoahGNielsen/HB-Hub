// Snake (games/snake/): the whole game, in the browser. Loaded by games/snake/index.php, which has the bar (score, best,
// speed, pause), the board's [data-snake-canvas] and the cover over it ([data-snake-cover], for "ready", "paused" and
// "game over").
//
// The board is COLS x ROWS squares. The game moves on in steps (one square per step, every SPEEDS[speed] ms), and the
// drawing glides between them at the screen's frame rate. A step happens when the drawn head reaches the edge of its
// next square, so during a step the head glides from the middle of the edge it came in over, through the middle of its
// square, to the edge it'll leave by. That way a turn can start the moment it's pressed: before the head reaches the
// middle, it curves round right away, on a quarter circle that starts where the head is (so the earlier the press, the
// rounder the corner), instead of first finishing its slide into the next square. Pressed later, the turn waits for the
// next square and curves round as soon as the head gets there. The body and tail follow the same curves (each part keeps
// the way it came in, the way it left and its corner's size), so the snake bends smoothly like a tube.
//
// Nothing goes to the server. localStorage (hbHubSnake) keeps {"speed": "slow" | "normal" | "fast", "best": {speed: the
// most apples eaten in one game}}, so the best scores are only in this browser (it's listed in the privacy policy).
(() => {
  const root = document.querySelector('[data-snake]');
  if (!root) {
    return;
  }

  const COLS = 20;
  const ROWS = 12;
  const START_LENGTH = 4;
  const SPEEDS = { slow: 160, normal: 120, fast: 85 }; // ms per step
  // A turn pressed before the step is this far through (the head in the middle of its square) turns the head in the
  // square it's in, straight away; later than that it has passed the middle, so it turns as soon as it's in the next one
  const TURN_LATE = 0.5;
  const EATEN_FOR = 0.3; // steps the eaten apple stays drawn, until the snout has reached it
  const STORAGE_KEY = 'hbHubSnake';

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
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  /**
   * The saved speed and best scores. Wrapped in try/catch, as storage can be blocked (private windows etc.), and the
   * game works without it, it just forgets.
   */
  const load = () => {
    try {
      const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
      return {
        speed: SPEEDS[saved.speed] ? saved.speed : 'normal',
        best: { slow: 0, normal: 0, fast: 0, ...(saved.best || {}) },
      };
    } catch {
      return { speed: 'normal', best: { slow: 0, normal: 0, fast: 0 } };
    }
  };

  const save = () => {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
    } catch {
      // Storage is blocked or full: the best score is just not kept
    }
  };

  const saved = load();

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

  /**
   * A new snake, lying START_LENGTH squares long on the left of the middle row, looking right, and an apple ahead of it.
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
    progress = 0.5; // the head sits in the middle of its square until it starts
    eaten = null;
    apple = { x: COLS - 5, y: row };
    appleBorn = performance.now();
    showScore();
  };

  /**
   * Puts the apple on a random free square. Returns false when there isn't one left (the snake fills the board).
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
    const pick = free[Math.floor(Math.random() * free.length)];
    apple = { x: pick % COLS, y: Math.floor(pick / COLS) };
    appleBorn = performance.now();
    return true;
  };

  const showScore = () => {
    scoreOut.textContent = score;
    bestOut.textContent = Math.max(saved.best[saved.speed], score);
  };

  /**
   * Shows the cover over the board for the given state, or hides it while playing.
   */
  const showCover = () => {
    const best = saved.best[saved.speed];
    const texts = {
      ready: ['Ready?', 'Press an arrow key or WASD, or swipe, to start.', 'Play'],
      paused: ['Paused', 'Press P or Esc, or the button, to carry on.', 'Carry on'],
      over: ['Game over', `You ate ${score} ${score === 1 ? 'apple' : 'apples'}.` + (newBest ? ' That\'s a new best!' : ` Your best is ${best}.`), 'Play again'],
      won: ['You win!', `The snake fills the whole board, with ${score} apples eaten.`, 'Play again'],
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
    showCover();
  };

  /**
   * Starts moving: from "ready" with the snake as it lies, or after a game over with a new snake.
   */
  const start = () => {
    if (state === 'over' || state === 'won') {
      reset();
    }
    setState('playing');
  };

  /**
   * Turns the head in the square it's in, right now. The corner starts where the drawn head is (it has come `progress`
   * of the way from the edge), so its radius is however far the head still is from the square's middle.
   */
  const turnNow = (wanted) => {
    dir = wanted;
    turned = true;
    snake[0].out = wanted;
    snake[0].k = 0.5 - Math.min(progress, 0.5);
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
   * Ends the game ('over' or 'won'), saving the score if it's the best at this speed.
   */
  const finish = (how) => {
    newBest = score > saved.best[saved.speed];
    if (newBest) {
      saved.best[saved.speed] = score;
      save();
    }
    setState(how);
    showScore();
  };

  const pause = () => {
    if (state === 'playing') {
      setState('paused');
    } else if (state === 'paused') {
      setState('playing');
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
   *                   the line shares out what's left of the step
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

    const time = (t) => (t <= before ? t : before + ((t - before) / (1 - before)) * (length - before));

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
          // Past the middle it can't turn any more in this square, so the crash is certain: it happens right here,
          // with the head's nose against what it hit, rather than with half the head in the wall
          die();
        } else if (progress >= 1) {
          progress -= 1;
          step();
        } else {
          break;
        }
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

  document.addEventListener('keydown', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (event.ctrlKey || event.metaKey || event.altKey || target?.closest('input, select, textarea, dialog')) {
      return;
    }

    const name = KEYS[event.key];
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
    if (event.pointerType !== 'mouse') {
      swipeFrom = { x: event.clientX, y: event.clientY };
    }
  });
  board.addEventListener('pointermove', (event) => {
    if (!swipeFrom || state === 'paused') {
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

  // Away from the page: pause the game and stop drawing
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      if (state === 'playing') {
        setState('paused');
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
