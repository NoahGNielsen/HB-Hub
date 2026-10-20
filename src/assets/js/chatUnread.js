// The red number on Chat in the side bar (navPanel.php): how many chats have unread messages, 9+ for more than 9.
// Kept up to date by navUpdates.js (nav:update, every 15 seconds while the page is in view). The chat page, which
// checks every 2 seconds anyway, passes on its own count straight away with
//     document.dispatchEvent(new CustomEvent('chat:unread', { detail: { unreadChats: 3 } }));
(() => {
    const count = document.querySelector('[data-nav-count="chat"]');
    if (!count) return;

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

    document.addEventListener('chat:unread', (event) => show(event.detail.unreadChats));
    document.addEventListener('nav:update', (event) => show(event.detail.unreadChats));
})();
