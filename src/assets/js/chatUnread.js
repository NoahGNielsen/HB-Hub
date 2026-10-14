// The red number on Chat in the side bar (navPanel.php): how many chats have unread messages, 9+ for more than 9.
// Asks /chat/unread every 20 seconds while the page is in view. The chat page, which checks every 2 seconds anyway,
// passes on its own count straight away with
//     document.dispatchEvent(new CustomEvent('chat:unread', { detail: { unreadChats: 3 } }));
(() => {
    const count = document.querySelector('[data-nav-count="chat"]');
    if (!count) return;

    const POLL_MS = 20000;
    const link = count.closest('a');
    const label = link.querySelector('.nav-side-label').textContent;

    function show(unreadChats) {
        if (typeof unreadChats !== 'number') return;
        count.hidden = unreadChats === 0;
        count.textContent = unreadChats > 9 ? '9+' : String(unreadChats);
        if (unreadChats > 0) {
            link.setAttribute('aria-label', label + ' (' + unreadChats + ' unread)');
        } else {
            link.removeAttribute('aria-label');
        }
    }

    async function poll() {
        if (document.visibilityState !== 'visible') return;
        try {
            const response = await fetch('/chat/unread', { headers: { Accept: 'application/json' } });
            if (response.ok) show((await response.json()).unreadChats);
        } catch {
            // Offline for a moment, or logged out in another tab (the login page isn't JSON)
        }
    }

    document.addEventListener('chat:unread', (event) => show(event.detail.unreadChats));

    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', poll); // catch up when coming back to the tab
    // A page the browser kept for its Back button comes back with the old number, from before reading the chat
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) poll();
    });
})();
