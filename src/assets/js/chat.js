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

// New chat popup: search all users and pick who to chat with.
// One picked user makes a DM, more make a group DM (up to data-members-max, yourself included).
// The picks stay when the search changes, and go into the form as hidden users[] inputs.
(() => {
    const dialog = document.querySelector('.chat-new');
    if (!dialog) return;

    const form = dialog.querySelector('form');
    const search = dialog.querySelector('#chat-new-search');
    const pickedList = dialog.querySelector('[data-picked]');
    const userList = dialog.querySelector('[data-users]');
    const status = dialog.querySelector('[data-users-status]');
    const groupFields = dialog.querySelector('[data-group-fields]');
    const groupName = dialog.querySelector('#chat-new-name');
    const count = dialog.querySelector('[data-picked-count]');
    const submitBtn = dialog.querySelector('[data-new-chat-submit]');
    const pickedMax = Number(dialog.dataset.membersMax) - 1; // the creator takes one spot

    const picked = new Map(); // userId -> userName, in the order they were picked
    let shownUsers = [];
    let searchTimer = null;
    let searchRequest = null;

    function avatar(name) {
        const span = document.createElement('span');
        span.className = 'chat-avatar';
        span.setAttribute('aria-hidden', 'true');
        span.textContent = name.charAt(0).toUpperCase();
        return span;
    }

    function renderUsers() {
        userList.replaceChildren(...shownUsers.map((user) => {
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.dataset.userId = user.userId;
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) picked.set(user.userId, user.userName);
                else picked.delete(user.userId);
                update();
            });

            const name = document.createElement('span');
            name.className = 'chat-new-user-name';
            name.textContent = user.userName;

            const label = document.createElement('label');
            label.className = 'chat-new-user';
            label.append(checkbox, avatar(user.userName), name);

            const item = document.createElement('li');
            item.append(label);
            return item;
        }));
        syncUsers();
    }

    // Ticks the picked users and locks the rest once the group is full.
    // Updates the checkboxes in place, so keyboard focus stays where it is.
    function syncUsers() {
        userList.querySelectorAll('input').forEach((checkbox) => {
            const isPicked = picked.has(Number(checkbox.dataset.userId));
            checkbox.checked = isPicked;
            checkbox.disabled = !isPicked && picked.size >= pickedMax;
        });
    }

    function renderPicked() {
        pickedList.replaceChildren(...[...picked].map(([userId, userName]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'users[]';
            input.value = userId;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.textContent = '×';
            remove.setAttribute('aria-label', `Remove ${userName}`);
            remove.addEventListener('click', () => {
                picked.delete(userId);
                update();
                search.focus();
            });

            const chip = document.createElement('li');
            chip.className = 'chat-new-chip';
            chip.append(input, userName, remove);
            return chip;
        }));
    }

    function update() {
        const isGroup = picked.size > 1;
        renderPicked();
        syncUsers();

        groupFields.hidden = !isGroup;
        submitBtn.disabled = picked.size === 0;
        submitBtn.textContent = isGroup ? 'Create group' : 'Start DM';

        if (picked.size === 0) count.textContent = 'Pick someone to chat with';
        else if (!isGroup) count.textContent = 'DM';
        else if (picked.size >= pickedMax) count.textContent = `Group full: ${picked.size + 1}/${pickedMax + 1} members`;
        else count.textContent = `Group DM: ${picked.size + 1}/${pickedMax + 1} members`;
    }

    async function loadUsers() {
        searchRequest?.abort();
        searchRequest = new AbortController();
        status.textContent = 'Searching…';

        try {
            const response = await fetch('/chat/users?q=' + encodeURIComponent(search.value.trim()), {
                signal: searchRequest.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(response.statusText);
            shownUsers = (await response.json()).users;
            status.textContent = shownUsers.length === 0 ? 'No users found.' : '';
        } catch (error) {
            if (error.name === 'AbortError') return; // a newer search took over
            shownUsers = [];
            status.textContent = "Couldn't load users. Try again.";
        }
        renderUsers();
    }

    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadUsers, 200);
    });

    // Enter in the search shouldn't create the chat
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') event.preventDefault();
    });

    document.querySelector('[data-new-chat-open]')?.addEventListener('click', () => {
        picked.clear();
        shownUsers = [];
        renderUsers();
        search.value = '';
        groupName.value = '';
        update();
        dialog.showModal();
        search.focus();
        loadUsers();
    });

    dialog.querySelectorAll('[data-new-chat-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    // Clicking the dimmed area around the popup closes it
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    form.addEventListener('submit', (event) => {
        if (picked.size === 0) event.preventDefault();
        else submitBtn.disabled = true; // no double chats from a double click
    });
})();
