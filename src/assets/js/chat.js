// Chat: filters the chat list while typing, starts the open chat at the newest message
// and sends on Enter (Shift+Enter for a new line).
// Without JS the search submits ?q= and the server filters the list instead.
(() => {
    const searchForm = document.querySelector('.chat-search');
    const search = document.querySelector('#chat-search');
    const items = document.querySelectorAll('.chat-list [data-chat-title]');
    const emptySearch = document.querySelector('[data-empty-search]');

    function filterChats() {
        const query = search.value.trim().toLowerCase();
        let shown = 0;
        items.forEach((item) => {
            const matches = item.dataset.chatTitle.toLowerCase().includes(query);
            item.hidden = !matches;
            if (matches) shown++;
        });
        emptySearch.hidden = items.length === 0 || shown > 0;
    }

    if (search) {
        search.addEventListener('input', filterChats);
        searchForm.addEventListener('submit', (event) => event.preventDefault());
        filterChats();
    }

    const messages = document.querySelector('[data-chat-messages]');
    if (messages) messages.scrollTop = messages.scrollHeight;

    const composer = document.querySelector('.chat-composer');
    if (!composer) return;
    const textarea = composer.querySelector('textarea');

    // Grow with the text, up to the max-height in chat.css
    function resize() {
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    }
    textarea.addEventListener('input', resize);

    textarea.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        event.preventDefault();
        if (textarea.value.trim() === '') return;
        composer.requestSubmit();
    });
})();
