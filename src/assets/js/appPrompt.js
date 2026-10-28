// The "Put HB Hub on your home screen" popup (footer.php renders it hidden for anyone who's logged in). On a phone or
// tablet, it shows up once on the first page after logging in, and then never again in that browser: that it's been
// shown is kept in localStorage (hbHubAppPrompt = 'seen'). It doesn't show if HB Hub is already opened from the home
// screen, or if there's no storage to remember it in (then it would come back on every page).
// Chrome and the like hand us their own install popup (the beforeinstallprompt event), so there it gets a button that
// opens that; Safari has none, so iPhones and iPads get told where Add to Home Screen is, and other browsers where
// their menu usually has it.
(() => {
    const dialog = document.querySelector('[data-app-prompt]');
    if (!dialog) return;

    const STORE_KEY = 'hbHubAppPrompt';
    const DELAY_MS = 2000;
    const RETRY_MS = 5000;

    try {
        if (localStorage.getItem(STORE_KEY) === 'seen') return;
    } catch {
        return; // nowhere to remember it, so it would show on every page
    }

    function remember() {
        try {
            localStorage.setItem(STORE_KEY, 'seen');
        } catch {
            // the check above worked, so this normally does too
        }
    }

    // Already opened from the home screen (navigator.standalone is Safari's own way of saying so)
    if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true) {
        remember();
        return;
    }

    // iPadOS asks for desktop pages and says it's a Mac, but Macs don't have touch screens
    const isIos = /iPhone|iPad|iPod/.test(navigator.userAgent)
        || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
    const isMobile = isIos || navigator.userAgentData?.mobile === true || /Android|Mobile/i.test(navigator.userAgent);
    if (!isMobile) return;

    const closeBtn = dialog.querySelector('[data-app-prompt-close]');
    const installBtn = dialog.querySelector('[data-app-prompt-install]');
    const howIos = dialog.querySelector('[data-app-prompt-how="ios"]');
    const howOther = dialog.querySelector('[data-app-prompt-how="other"]');

    let installEvent = null;

    // With the browser's own install popup there's a button for it; without it, the steps to do it by hand
    function showWay() {
        installBtn.hidden = installEvent === null;
        howIos.hidden = installEvent !== null || !isIos;
        howOther.hidden = installEvent !== null || isIos;
        closeBtn.textContent = installEvent === null ? 'Got it' : 'Not now';
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault(); // our popup instead of the browser's banner
        installEvent = event;
        showWay();
    });
    window.addEventListener('appinstalled', () => {
        remember();
        dialog.close();
    });

    // Never over another popup (a game's, or the chat's): then it tries again a bit later
    function show() {
        if (document.visibilityState !== 'visible' || document.querySelector('dialog[open]')) {
            setTimeout(show, RETRY_MS);
            return;
        }
        showWay();
        dialog.showModal();
        remember();
    }
    setTimeout(show, DELAY_MS);

    installBtn.addEventListener('click', async () => {
        if (!installEvent) return;
        const event = installEvent;
        installEvent = null; // it can only be used once
        dialog.close();
        await event.prompt();
    });
    closeBtn.addEventListener('click', () => dialog.close());
    // A click on the dark backdrop around it closes it too (the click lands on the dialog itself, not its contents)
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
})();
