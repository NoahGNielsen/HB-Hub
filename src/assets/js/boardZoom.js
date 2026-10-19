// The zoom buttons under a game's board (chess and Matador's game pages). + and − make the board bigger or smaller
// inside its frame, which stays the same size and scrolls, so the rest of the page doesn't move.
//
// The frame is [data-board-zoom], its value the localStorage key the zoom is remembered under (so each game keeps its
// own). It gets the zoom as --board-zoom (1 is the board as it is without zooming), and the game's CSS sizes the board
// from that. The buttons are in [data-board-zoom-controls]: [data-zoom-out], [data-zoom-in] and the [data-zoom-level]
// text between them.
(() => {
    const view = document.querySelector('[data-board-zoom]');
    const controls = document.querySelector('[data-board-zoom-controls]');
    if (!view || !controls) return;

    const STEPS = [1, 1.25, 1.5, 1.75, 2, 2.5, 3];
    const KEY = view.dataset.boardZoom;
    const zoomOutButton = controls.querySelector('[data-zoom-out]');
    const zoomInButton = controls.querySelector('[data-zoom-in]');
    const level = controls.querySelector('[data-zoom-level]');

    let step = 0;
    try {
        step = Math.max(0, STEPS.indexOf(Number(localStorage.getItem(KEY))));
    } catch {
        // no storage (a private window): not zoomed
    }

    /**
     * Sets the zoom to STEPS[step], with the point in the middle of the frame staying in the middle.
     */
    function render() {
        const middleX = (view.scrollLeft + view.clientWidth / 2) / view.scrollWidth;
        const middleY = (view.scrollTop + view.clientHeight / 2) / view.scrollHeight;
        const zoom = STEPS[step];
        view.style.setProperty('--board-zoom', String(zoom));
        view.classList.toggle('is-zoomed', zoom > 1);
        view.scrollLeft = middleX * view.scrollWidth - view.clientWidth / 2;
        view.scrollTop = middleY * view.scrollHeight - view.clientHeight / 2;

        level.textContent = Math.round(zoom * 100) + '%';
        zoomOutButton.disabled = step === 0;
        zoomInButton.disabled = step === STEPS.length - 1;
    }

    function zoomTo(newStep) {
        step = Math.min(STEPS.length - 1, Math.max(0, newStep));
        try {
            localStorage.setItem(KEY, String(STEPS[step]));
        } catch {
            // only kept until the page is closed
        }
        render();
    }

    zoomOutButton.addEventListener('click', () => zoomTo(step - 1));
    zoomInButton.addEventListener('click', () => zoomTo(step + 1));
    render();
})();
