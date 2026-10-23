// Keeps the nav bar up to date on every page for logged in users, with one request to /nav-updates every
// 15 seconds. The hosting only runs about 10 PHP requests at once for the whole site, so the bell, the number on Chat,
// the casino chips and the browser notifications all share this one instead of each asking on their own.
// The other scripts take part through two events on document:
// - nav:poll, just before each request, with detail { params, inBackground }: params is the URLSearchParams it sends,
//   for adding what they want (casino.js adds wallet=1, notifications.js messages=1 and after=). While the page is
//   hidden nothing is sent, unless a script sets inBackground to true - notifications.js does, as browser notifications
//   are for when the user isn't looking at the site.
// - nav:update, with the answer as detail: { unread, unreadChats, wallet?, messages? }
//   (notificationBell.js, chatUnread.js, casino.js, notifications.js)
(() => {
    const POLL_MS = 15000;
    let isPolling = false;

    async function poll() {
        if (isPolling) return;

        const detail = { params: new URLSearchParams(), inBackground: false };
        document.dispatchEvent(new CustomEvent('nav:poll', { detail }));
        if (document.visibilityState !== 'visible' && !detail.inBackground) return;

        isPolling = true;
        const query = detail.params.toString();
        try {
            const response = await fetch('/nav-updates' + (query !== '' ? '?' + query : ''), {
                headers: { Accept: 'application/json' },
            });
            if (response.ok) document.dispatchEvent(new CustomEvent('nav:update', { detail: await response.json() }));
        } catch {
            // Offline for a moment, or logged out in another tab (the login page isn't JSON)
        }
        isPolling = false;
    }

    setInterval(poll, POLL_MS);
    // Catch up when coming back to the tab
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') poll();
    });
    // A page the browser kept for its Back button comes back with the old numbers, from before reading the chat
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) poll();
    });
})();
