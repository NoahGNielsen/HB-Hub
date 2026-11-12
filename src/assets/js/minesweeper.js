// Minesweeper's page (games/minesweeper/): makes the frame with the game in it (data-minesweeper-frame, frame.php)
// exactly as tall as the game, so the page scrolls instead of the frame. It measures the frame's body (frame.php is on
// the same site, so its page can be read from here) when it loads and every time its size changes, like when a
// different difficulty is picked. The game itself is in assets/minesweeper/js/.
// It also listens for the game's minesweeper:* events on this page's document (main.js sends them from inside the
// frame): "new" (a new board, detail {size}), "won" ({size, ms, best, isBest}, the server's time and the player's
// best), "lost" and "error" ({error}, the server's code). A win or an error goes in the line above the frame
// (data-minesweeper-result), and the Leaderboard button (data-minesweeper-leaderboard) goes to the board being played.
(() => {
  const frame = document.querySelector('[data-minesweeper-frame]');
  if (!frame) {
    return;
  }

  /**
   * Sets the frame's height to the height of the page inside it.
   */
  const fit = () => {
    const body = frame.contentDocument?.body;
    if (body) {
      frame.style.height = `${Math.ceil(body.getBoundingClientRect().height)}px`;
    }
  };

  let observer = null;
  frame.addEventListener('load', () => {
    observer?.disconnect(); // from the page before, if the frame loaded again (like after the login)
    const body = frame.contentDocument?.body;
    if (!body) {
      return;
    }
    fit();
    observer = new ResizeObserver(fit);
    observer.observe(body);
  });

  const SIZES = { 9: ['easy', 'Easy'], 16: ['medium', 'Medium'], 30: ['hard', 'Hard'] }; // HBHUB_MINESWEEPER_SIZES
  const ERRORS = {
    gameOver: 'This game was ended because a new one was started in another tab. Click the face for a new game.',
    blocked: "Games are blocked for you right now, so this game can't go on.",
    closed: "Games aren't open right now, so this game can't go on.",
    offline: "Couldn't reach HB Hub. Check your connection and click the square again.",
  };
  const result = document.querySelector('[data-minesweeper-result]');
  const leaderboard = document.querySelector('[data-minesweeper-leaderboard]');

  /**
   * A time in ms like the leaderboard shows it: "12.34 s", or "1:02.50" from a minute (hbHubMinesweeperTime()).
   */
  const formatTime = (ms) => {
    const seconds = Math.floor(ms / 1000);
    const hundredths = String(Math.floor((ms % 1000) / 10)).padStart(2, '0');
    return seconds >= 60
      ? `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}.${hundredths}`
      : `${seconds}.${hundredths} s`;
  };

  /**
   * Shows a message above the frame (null hides it). link is [text, address] for a link after it.
   */
  const show = (text, isError = false, link = null) => {
    if (!result) {
      return;
    }
    result.hidden = text === null;
    result.classList.toggle('is-error', isError);
    result.textContent = text ?? '';
    if (link) {
      const a = document.createElement('a');
      a.textContent = link[0];
      a.href = link[1];
      result.append(' ', a);
    }
  };

  const boardUrl = (size) => `/games/minesweeper/leaderboard?size=${SIZES[size]?.[0] ?? 'medium'}`;

  document.addEventListener('minesweeper:new', (event) => {
    show(null);
    leaderboard?.setAttribute('href', boardUrl(event.detail?.size));
  });

  document.addEventListener('minesweeper:lost', () => show(null));

  document.addEventListener('minesweeper:won', (event) => {
    const { size, ms, best, isBest } = event.detail ?? {};
    const name = SIZES[size]?.[1] ?? '';
    show(
      `You won ${name} in ${formatTime(ms)}. ` + (isBest ? "That's your best yet!" : `Your best is ${formatTime(best)}.`),
      false,
      ['See the leaderboard', boardUrl(size)],
    );
  });

  document.addEventListener('minesweeper:error', (event) => {
    show(ERRORS[event.detail?.error] ?? 'Something went wrong. Click the face for a new game.', true);
  });
})();
