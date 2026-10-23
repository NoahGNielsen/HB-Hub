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
//
// Pages that ask their own server every few seconds anyway (the chat, the game tables and the lobbies) take this
// request along with theirs, so there it doesn't cost a PHP request of its own. Before each of theirs they send
//     const ride = { params: null };
//     document.dispatchEvent(new CustomEvent('nav:ride', { detail: ride }));
// and when this one is due, ride.params comes back as the query string to send as their nav= field (hbHubNavRide() in
// assets/php/navUpdates.php). They pass the "nav" in their answer on as nav:update. This one's own timer then finds it
// was asked a moment ago and waits, and takes over again once they stop (the chat doesn't poll while it's hidden).
//
// While the tabs are hidden, only one of them has to ask for the browser notifications' messages (the newest one
// announced is shared between them, notifications.js). Every tab notes in localStorage when it last asked for them
// (hbHubNavPolledAt), and a hidden one doesn't ask while another tab did a moment ago.
(() => {
    const POLL_MS = 15000;
    const DUE_MS = POLL_MS - 1000; // a timer that fires a little early still counts
    const RIDE_MS = POLL_MS - 4000; // a page's own polling takes it along a bit earlier, so this timer doesn't beat it to it
    const SHARED_KEY = 'hbHubNavPolledAt';
    let isPolling = false;
    // Date.now() when this tab last asked, on its own or along with the page's polling. The page came with the numbers.
    let lastPolledAt = Date.now();

    function sharedPolledAt() {
        try {
            const value = Number(localStorage.getItem(SHARED_KEY));
            return Number.isFinite(value) ? value : 0;
        } catch {
            return 0; // storage blocked: every hidden tab asks for itself, like before
        }
    }

    function setSharedPolledAt(at) {
        try {
            localStorage.setItem(SHARED_KEY, String(at));
        } catch { /* storage blocked */ }
    }

    // The query string to send when it's time to ask (it may be empty), or null when it isn't
    function due(afterMs) {
        const now = Date.now();
        if (isPolling || now - lastPolledAt < afterMs) return null;

        const detail = { params: new URLSearchParams(), inBackground: false };
        document.dispatchEvent(new CustomEvent('nav:poll', { detail }));
        if (document.visibilityState !== 'visible') {
            if (!detail.inBackground) return null;
            // The messages are all a hidden tab asks for, and another tab just did. Not when that time is ahead of
            // this one (the clock was turned back), or the tabs would wait for it.
            const sinceShared = now - sharedPolledAt();
            if (sinceShared >= 0 && sinceShared < DUE_MS) return null;
        }

        lastPolledAt = now;
        if (detail.params.has('messages')) setSharedPolledAt(now);
        return detail.params.toString();
    }

    async function poll(afterMs = DUE_MS) {
        const query = due(afterMs);
        if (query === null) return;

        isPolling = true;
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

    document.addEventListener('nav:ride', (event) => {
        const query = due(RIDE_MS);
        if (query !== null) event.detail.params = query;
    });

    setInterval(poll, POLL_MS);
    // Catch up when coming back to the tab. A moment later, so a page that polls straight away too takes it along.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') setTimeout(poll);
    });
    // A page the browser kept for its Back button comes back with the old numbers, from before reading the chat
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) poll(0);
    });
})();
