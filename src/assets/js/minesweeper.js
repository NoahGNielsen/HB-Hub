// Minesweeper's page (games/minesweeper/): makes the frame with the game in it (data-minesweeper-frame, frame.php)
// exactly as tall as the game, so the page scrolls instead of the frame. It measures the frame's body (frame.php is on
// the same site, so its page can be read from here) when it loads and every time its size changes, like when a
// different difficulty is picked. The game itself is in assets/minesweeper/js/.
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
})();
