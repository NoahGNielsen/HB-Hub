// The HB Hub logo in the top bar on the chat page (navPanel.php gives it data-nav-brand-home there). Logged in users'
// logo always goes to the chat, and the frontpage sends them on to it, so on the chat a double click on the logo is the
// way to the frontpage: data-nav-brand-home="/?home". A single click still goes to the chat (its href), just after a
// short wait to see whether a second click follows. Clicks that open a new tab are left to the browser.
(() => {
    const DOUBLE_CLICK_MS = 300;
    let waiting = null; // the timer of a first click waiting for a second one

    document.addEventListener('click', (event) => {
        const brand = event.target.closest('[data-nav-brand-home]');
        if (!brand || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (waiting !== null) {
            clearTimeout(waiting);
            waiting = null;
            location.href = brand.dataset.navBrandHome;
            return;
        }
        waiting = setTimeout(() => {
            waiting = null;
            location.href = brand.href;
        }, DOUBLE_CLICK_MS);
    });
})();
