// Snake (games/snake/): the whole game, in the browser. Loaded by games/snake/index.php, which has the bar (score, best,
// speed, pause), the board's [data-snake-canvas] and the cover over it ([data-snake-cover], for "ready", "paused" and
// "game over").
//
// The board is COLS x ROWS squares. The game moves on in steps (one square per step, every SPEEDS[speed] ms), and the
// drawing glides between them at the screen's frame rate: the head slides from the square it was on into its new one,
// and the tail from its old square into its new one, so the snake moves smoothly instead of jumping.
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

  // The game. snake[0] is the head; each part is {x, y} in squares.
  let state = 'ready'; // ready | playing | paused | over | won
  let snake = [];
  let prevTail = null; // where the tail was before the last step, to glide it from there
  let dir = DIRECTIONS.right;
  let queue = []; // turns pressed but not made yet, so two quick presses both count
  let apple = { x: 0, y: 0 };
  let appleBorn = 0; // when the apple appeared, for its pop-in
  let eaten = null; // {x, y, at}: the apple just eaten, still drawn until the head has slid onto it
  let score = 0;
  let newBest = false; // the game that just ended beat the best at its speed
  let acc = 0; // ms since the last step
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
      snake.push({ x: START_LENGTH + 1 - i, y: row });
    }
    prevTail = { ...snake[snake.length - 1] };
    dir = DIRECTIONS.right;
    queue = [];
    score = 0;
    acc = 0;
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
    acc = 0;
    setState('playing');
  };

  /**
   * Turns the snake (when the game is going or about to start). A turn back into itself, or the way it's going already,
   * is ignored. Turns queue up, up to 3, so a quick up-then-left makes both turns, one per step.
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
        if (wanted !== last) {
          queue = [wanted];
        }
        start(); // any way but back into itself starts it
      }
      return;
    }
    if (wanted !== last && !back && queue.length < 3) {
      queue.push(wanted);
    }
  };

  /**
   * One step: makes the next queued turn, moves the head one square, and eats, grows, dies or wins.
   */
  const step = () => {
    if (queue.length) {
      dir = queue.shift();
    }

    const head = snake[0];
    const next = { x: head.x + dir.x, y: head.y + dir.y };
    const eating = next.x === apple.x && next.y === apple.y;
    // The tail moves out of the way this step, unless the snake is growing, so heading into it is fine
    const body = eating ? snake : snake.slice(0, -1);
    const hitsWall = next.x < 0 || next.y < 0 || next.x >= COLS || next.y >= ROWS;
    if (hitsWall || body.some((part) => part.x === next.x && part.y === next.y)) {
      die();
      return;
    }

    prevTail = { ...snake[snake.length - 1] };
    snake.unshift(next);
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
  };

  const die = () => {
    acc = SPEEDS[saved.speed]; // the snake stops where it is, fully in its square
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
    if (how === 'won') {
      acc = SPEEDS[saved.speed];
    }
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

  const lerp = (a, b, t) => ({ x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t });

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
   * The points the snake's body runs through: the gliding head, every square of the body, and the gliding tail.
   * t is how far the current step has got (0-1).
   */
  const snakePoints = (t) => {
    const points = [lerp(snake[1], snake[0], t)];
    for (let i = 1; i < snake.length; i++) {
      points.push(snake[i]);
    }
    points.push(lerp(prevTail, snake[snake.length - 1], t));
    return points.map(px);
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
  const drawSnake = (t, now) => {
    const points = snakePoints(t);
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
    const forward = { x: snake[0].x - snake[1].x, y: snake[0].y - snake[1].y };
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
    const t = state === 'ready' ? 1 : Math.min(acc / stepMs, 1);

    drawBoard();

    // The apple just eaten stays until the head has slid onto it; the new one pops in after that
    if (eaten && now - eaten.at < stepMs * 0.6) {
      drawApple(eaten, 1);
    } else {
      eaten = null;
    }
    if (state !== 'won') {
      const age = now - appleBorn - (eaten ? stepMs * 0.6 : 0);
      const pop = reducedMotion.matches ? 1 : Math.min(Math.max(age / 220, 0), 1);
      const bounce = pop < 1 ? Math.sin(pop * Math.PI * 0.75) / Math.sin(Math.PI * 0.75) : 1;
      const breathe = reducedMotion.matches ? 0 : Math.sin(now / 400) * 0.03;
      drawApple(apple, (pop < 1 ? bounce : 1) + breathe);
    }

    drawSnake(t, now);
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
      acc += elapsed;
      const stepMs = SPEEDS[saved.speed];
      while (acc >= stepMs && state === 'playing') {
        acc -= stepMs;
        step();
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
