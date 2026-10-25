// The HB Hub logo in the top bar (navPanel.php). On the website it's a plain link to the frontpage, but in the installed
// app (manifest.json, "display": "standalone") the frontpage is rarely what you want: one tap goes to the chats, and a
// double tap to the frontpage. A single tap has to wait a moment to see whether a second one follows.
(() => {
    const brand = document.querySelector('[data-nav-brand]');
    if (!brand) return;

    const isApp = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true; // the second is iOS
    if (!isApp) return;

    const DOUBLE_TAP_MS = 300;
    let timer = null;

    brand.addEventListener('click', (event) => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();

        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
            window.location.href = '/chat/';
            return;
        }
        timer = setTimeout(() => {
            timer = null;
            window.location.href = '/';
        }, DOUBLE_TAP_MS);
    });
})();
