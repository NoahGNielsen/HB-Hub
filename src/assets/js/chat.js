// Chat: filters the chat list while typing, starts the open chat at the newest message
// and sends on Enter (Shift+Enter for a new line).
// Without JS the search submits ?q= and the server filters the list instead.
(() => {
    const searchForm = document.querySelector('.chat-search');
    const search = document.querySelector('#chat-search');
    const list = document.querySelector('.chat-list');
    const emptyAll = document.querySelector('[data-empty-all]');
    const emptyHidden = document.querySelector('[data-empty-hidden]');
    const emptySearch = document.querySelector('[data-empty-search]');

    // Chats the user hid only show up when searching for them.
    // A DM with a nickname is found by the nickname and by the real username.
    // The chats are looked up every time, as polling swaps in a new list when something in it changed.
    function filterChats() {
        const items = list.querySelectorAll('[data-chat-title]');
        const query = search.value.trim().toLowerCase();
        let shown = 0;
        items.forEach((item) => {
            const matches = query === ''
                ? !item.hasAttribute('data-chat-hidden-by-user')
                : item.dataset.chatTitle.toLowerCase().includes(query)
                    || (item.hasAttribute('data-chat-nickname') && item.dataset.chatUsername.toLowerCase().includes(query));
            item.hidden = !matches;
            if (matches) shown++;
        });
        emptyAll.hidden = items.length > 0;
        emptyHidden.hidden = items.length === 0 || query !== '' || shown > 0;
        emptySearch.hidden = items.length === 0 || query === '' || shown > 0;
    }

    if (search) {
        search.addEventListener('input', filterChats);
        searchForm.addEventListener('submit', (event) => event.preventDefault());
        list.addEventListener('chat-list-update', filterChats);
        filterChats();
    }

    // At the newest message, unless that would scroll the "New messages" line out of sight above
    const messages = document.querySelector('[data-chat-messages]');
    if (messages) {
        chatShowBackground(messages, messages.dataset.background);
        messages.scrollTop = messages.scrollHeight;
        const unread = messages.querySelector('[data-unread-divider]');
        const offset = unread ? unread.getBoundingClientRect().top - messages.getBoundingClientRect().top : 0;
        if (offset < 0) messages.scrollTop += offset - 8;
    }

    const composer = document.querySelector('.chat-composer');
    if (!composer) return;
    const textarea = composer.querySelector('textarea');

    // Grow with the text, up to the max-height in chat.css, and only scroll once it can't grow any more.
    // scrollHeight leaves out the border, which border-box sizing counts, so it's added back.
    function resize() {
        textarea.style.height = 'auto';
        const border = textarea.offsetHeight - textarea.clientHeight;
        textarea.style.height = textarea.scrollHeight + border + 'px';
        textarea.style.overflowY = textarea.scrollHeight > textarea.clientHeight ? 'auto' : 'hidden';
    }
    textarea.addEventListener('input', resize);

    textarea.addEventListener('keydown', (event) => {
        if (chatFormatShortcut(event, textarea)) return;
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing || chatInCodeBlock(textarea)) return;
        event.preventDefault();
        if (textarea.value.trim() === '') return;
        composer.requestSubmit();
    });
})();

// Formatting while typing a message (the composer and Edit), from the formatting menu below or Ctrl+B, Ctrl+I and Ctrl+U.
// The formatting itself is worked out by the server when the message is shown (assets/php/chatFormat.php),
// this only types the ** and so on for the user:
//   bold, italic, underline, strike, code: around the selected text, or around the cursor so it can be typed in between
//   codeBlock: ``` around the selected text, each on a line of its own
//   heading, list, numberedList: "# ", "- " or "1. ", "2. " in front of every selected line (the cursor's line without a selection)
//   link: [the selected text](https://), with https:// selected to paste the address over - or the other way round
//         when the selected text is an address
// Nothing changes when the result would be longer than the box allows.
function chatFormatApply(textarea, format) {
    const { value, selectionStart: start, selectionEnd: end } = textarea;
    const selected = value.slice(start, end);
    const marks = { bold: '**', italic: '*', underline: '__', strike: '~~', code: '`' };
    let from = start;
    let to = end;
    let text;
    let selectFrom; // what's selected afterwards, from where the new text starts
    let selectTo;

    if (format in marks) {
        const mark = marks[format];
        text = mark + selected + mark;
        [selectFrom, selectTo] = [mark.length, mark.length + selected.length]; // the same text, now inside them
    } else if (format === 'codeBlock') {
        const before = start > 0 && value[start - 1] !== '\n' ? '\n' : '';
        const after = end < value.length && value[end] !== '\n' ? '\n' : '';
        text = before + '```\n' + selected + '\n```' + after;
        selectFrom = before.length + 4;
        selectTo = selectFrom + selected.length;
    } else if (format === 'link') {
        if (/^https?:\/\/\S+$/i.test(selected)) {
            text = `[](${selected})`;
            [selectFrom, selectTo] = [1, 1]; // the text to show goes between the [ ]
        } else {
            const label = selected || 'text';
            text = `[${label}](https://)`;
            [selectFrom, selectTo] = selected ? [label.length + 3, label.length + 11] : [1, 1 + label.length];
        }
    } else {
        // Whole lines: from the start of the first selected line to the end of the last one.
        // A selection ending right after a line break (three lines selected by dragging down) leaves the next line be.
        from = start > 0 ? value.lastIndexOf('\n', start - 1) + 1 : 0;
        to = value.indexOf('\n', end > start && value[end - 1] === '\n' ? end - 1 : end);
        if (to === -1) to = value.length;
        let number = 0;
        text = value.slice(from, to).split('\n').map((line) => {
            // Empty lines among the selected ones stay empty, and don't count in a numbered list.
            // The cursor's own line gets it even when empty, to start typing there.
            if (line.trim() === '' && start !== end) return line;
            number++;
            return (format === 'heading' ? '# ' : format === 'list' ? '- ' : `${number}. `) + line;
        }).join('\n');
        // With nothing selected the cursor stays where it was in the line, otherwise the lines stay selected
        const cursor = start - from + text.length - (to - from);
        [selectFrom, selectTo] = start === end ? [cursor, cursor] : [0, text.length];
    }

    if (textarea.maxLength > 0 && value.length - (to - from) + text.length > textarea.maxLength) return;
    textarea.setRangeText(text, from, to);
    textarea.setSelectionRange(from + selectFrom, from + selectTo);
    textarea.dispatchEvent(new Event('input', { bubbles: true })); // so it grows like when typing, and Edit's Save wakes up
}

// Ctrl+B, Ctrl+I and Ctrl+U in a message box. Returns whether the key was one of them.
function chatFormatShortcut(event, textarea) {
    const formats = { b: 'bold', i: 'italic', u: 'underline' };
    const format = formats[event.key.toLowerCase()];
    if (!format || !(event.ctrlKey || event.metaKey) || event.shiftKey || event.altKey) return false;
    event.preventDefault();
    chatFormatApply(textarea, format);
    return true;
}

// Formatting menu: right-clicking the message box (or Edit's) opens a menu with every kind of formatting,
// which goes around the selected text (chatFormatApply above). The browser's own menu is replaced,
// so Cut, Copy and Paste are in it too. Shift+right-click still opens the browser's menu (for spelling suggestions),
// and phones keep theirs, as it comes with the handles for selecting text.
// Edit is a modal popup, where nothing outside it can be clicked, so the menu moves into the popup it's opened in,
// and back to where it started for the message box (inside .chat, whose [hidden] rule is what hides it again).
// Clicking anywhere, an item (even a greyed-out one), or Enter, Delete or Backspace closes it again.
(() => {
    const menu = document.querySelector('[data-format-menu]');
    if (!menu) return;

    const home = menu.parentElement; // where it is on the page, for the message box
    const isTouch = window.matchMedia('(hover: none)');
    const needsSelection = menu.querySelectorAll('[data-format="cut"], [data-format="copy"]');
    let textarea = null; // the message box the menu was opened on

    // Firefox only lets websites read the clipboard from version 125 on
    menu.querySelector('[data-format="paste"]').hidden = !navigator.clipboard?.readText;

    const formatMenu = contextMenu({
        menu,
        list: document.body,
        items: '[data-format-target]',
        focusItem: (item) => item.focus(), // and the selection is still there, for the next formatting
        skip: (event) => (event.shiftKey && event.clientX !== 0) || isTouch.matches, // clientX is 0 from Shift+F10
        onOpen(item) {
            textarea = item;
            const host = item.closest('dialog') ?? home;
            if (menu.parentElement !== host) host.append(menu);
            const hasSelection = item.selectionStart !== item.selectionEnd;
            needsSelection.forEach((menuItem) => {
                if (hasSelection) menuItem.removeAttribute('aria-disabled');
                else menuItem.setAttribute('aria-disabled', 'true');
            });
        },
    });

    // Text put in from here goes where the browser's own paste would, cut short when the box would be too full
    function insert(text) {
        const { selectionStart: start, selectionEnd: end } = textarea;
        const room = textarea.maxLength > 0 ? textarea.maxLength - (textarea.value.length - (end - start)) : text.length;
        textarea.setRangeText(text.slice(0, Math.max(0, room)), start, end, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    menu.addEventListener('click', async (event) => {
        const item = event.target.closest('[data-format]');
        if (!item) return;
        formatMenu.close(true); // back in the message box first, which brings its selection back
        if (item.getAttribute('aria-disabled') === 'true') return;
        const format = item.dataset.format;
        const selected = textarea.value.slice(textarea.selectionStart, textarea.selectionEnd);

        try {
            if (format === 'copy') {
                await navigator.clipboard.writeText(selected);
            } else if (format === 'cut') {
                await navigator.clipboard.writeText(selected);
                insert('');
            } else if (format === 'paste') {
                insert(await navigator.clipboard.readText());
            } else {
                chatFormatApply(textarea, format);
            }
        } catch {
            // The browser said no to the clipboard (or the user did, when it asked), so nothing happens
        }
    });

    // Delete and Backspace close it, back in the message box (Enter is a click on the focused item, above)
    menu.addEventListener('keydown', (event) => {
        if (event.key !== 'Delete' && event.key !== 'Backspace') return;
        event.preventDefault();
        formatMenu.close(true);
    });
})();

// Whether the cursor is inside a ``` code block that hasn't been closed yet, where Enter starts a new line instead of sending
function chatInCodeBlock(textarea) {
    return textarea.value.slice(0, textarea.selectionStart).split('```').length % 2 === 0;
}

// User picker in a popup (New chat, Add to chat): searches all users and ticks the picked ones.
// The picks stay when the search changes, and go into the popup's form as hidden users[] inputs.
// Users already in the group show, but can't be picked. onChange(number picked) runs after every change.
function userPicker(dialog, onChange) {
    const search = dialog.querySelector('input[type="search"]');
    const pickedList = dialog.querySelector('[data-picked]');
    const userList = dialog.querySelector('[data-users]');
    const status = dialog.querySelector('[data-users-status]');

    const picked = new Map(); // userId -> userName, in the order they were picked
    let pickedMax = 0;
    let memberIds = new Set(); // userIds already in the group
    let shownUsers = [];
    let searchTimer = null;
    let searchRequest = null;

    // The user's profile picture, or the first letter of their name when they don't have one
    function avatar(user) {
        const span = document.createElement('span');
        span.className = 'chat-avatar';
        span.setAttribute('aria-hidden', 'true');
        if (user.avatar) {
            const img = document.createElement('img');
            img.src = user.avatar;
            img.alt = '';
            img.loading = 'lazy';
            span.append(img);
        } else {
            span.textContent = user.userName.charAt(0).toUpperCase();
        }
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
            label.append(checkbox, avatar(user), name);
            if (memberIds.has(user.userId)) {
                const note = document.createElement('span');
                note.className = 'chat-new-user-note';
                note.textContent = 'In the group';
                label.append(note);
            }

            const item = document.createElement('li');
            item.append(label);
            return item;
        }));
        syncUsers();
    }

    // Ticks the picked users (and the ones in the group already) and locks the rest once the group is full.
    // Updates the checkboxes in place, so keyboard focus stays where it is.
    function syncUsers() {
        userList.querySelectorAll('input').forEach((checkbox) => {
            const userId = Number(checkbox.dataset.userId);
            const isPicked = picked.has(userId);
            checkbox.checked = isPicked || memberIds.has(userId);
            checkbox.disabled = memberIds.has(userId) || (!isPicked && picked.size >= pickedMax);
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
        renderPicked();
        syncUsers();
        onChange(picked.size);
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

    // Enter in the search shouldn't send the form
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') event.preventDefault();
    });

    return {
        picked,
        // Opens the popup with nobody picked yet. max: how many can be picked, members: userIds already in the group
        open(max, members = []) {
            pickedMax = max;
            memberIds = new Set(members);
            picked.clear();
            shownUsers = [];
            renderUsers();
            search.value = '';
            update();
            dialog.showModal();
            search.focus();
            loadUsers();
        },
    };
}

// New chat popup: search all users and pick who to chat with.
// One picked user makes a DM, more make a group DM (up to data-members-max, yourself included).
(() => {
    const dialog = document.querySelector('[data-new-chat]');
    if (!dialog) return;

    const form = dialog.querySelector('form');
    const groupFields = dialog.querySelector('[data-group-fields]');
    const groupName = dialog.querySelector('#chat-new-name');
    const count = dialog.querySelector('[data-picked-count]');
    const submitBtn = dialog.querySelector('[data-new-chat-submit]');
    const pickedMax = Number(dialog.dataset.membersMax) - 1; // the creator takes one spot

    const picker = userPicker(dialog, (pickedCount) => {
        const isGroup = pickedCount > 1;
        groupFields.hidden = !isGroup;
        submitBtn.disabled = pickedCount === 0;
        submitBtn.textContent = isGroup ? 'Create group' : 'Start DM';

        if (pickedCount === 0) count.textContent = 'Pick someone to chat with';
        else if (!isGroup) count.textContent = 'DM';
        else if (pickedCount >= pickedMax) count.textContent = `Group full: ${pickedCount + 1}/${pickedMax + 1} members`;
        else count.textContent = `Group DM: ${pickedCount + 1}/${pickedMax + 1} members`;
    });

    document.querySelector('[data-new-chat-open]')?.addEventListener('click', () => {
        groupName.value = '';
        picker.open(pickedMax);
    });

    dialog.querySelectorAll('[data-new-chat-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    // Clicking the dimmed area around the popup closes it
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    form.addEventListener('submit', (event) => {
        if (picker.picked.size === 0) event.preventDefault();
        else submitBtn.disabled = true; // no double chats from a double click
    });
})();

// Add to chat popup (groups): any member can add people, until the group has data-members-max members (invites counted).
// The ones added get the group as a message request, like when it was made, and everyone sees a notice.
(() => {
    const dialog = document.querySelector('.chat-add');
    if (!dialog) return;

    const form = dialog.querySelector('form');
    const count = dialog.querySelector('[data-picked-count]');
    const submitBtn = dialog.querySelector('[data-add-submit]');
    const memberIds = dialog.dataset.memberIds.split(',').map(Number);
    const membersMax = Number(dialog.dataset.membersMax);
    const pickedMax = Math.max(0, membersMax - memberIds.length);

    const picker = userPicker(dialog, (pickedCount) => {
        const memberCount = memberIds.length + pickedCount;
        submitBtn.disabled = pickedCount === 0;
        if (memberCount >= membersMax) count.textContent = `Group full: ${memberCount}/${membersMax} members`;
        else if (pickedCount === 0) count.textContent = 'Pick who to add';
        else count.textContent = `Group: ${memberCount}/${membersMax} members`;
    });

    document.querySelector('[data-add-open]').addEventListener('click', () => picker.open(pickedMax, memberIds));

    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    // Clicking the dimmed area around the popup closes it
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    form.addEventListener('submit', (event) => {
        if (picker.picked.size === 0) event.preventDefault();
        else submitBtn.disabled = true; // no double adds from a double click
    });
})();

// Link warning popup: links in messages ask before they're opened.
// Without JS the links just open in a new tab.
(() => {
    const dialog = document.querySelector('.chat-link-warning');
    const messages = document.querySelector('[data-chat-messages]');
    if (!dialog || !messages) return;

    const url = dialog.querySelector('[data-link-warning-url]');
    const openLink = dialog.querySelector('[data-link-warning-open]');
    const stay = dialog.querySelector('.chat-btn[data-link-warning-close]');

    function warn(event) {
        const link = event.target.closest('[data-chat-link]');
        if (!link) return;
        event.preventDefault();
        url.textContent = link.href;
        openLink.href = link.href;
        dialog.showModal();
        stay.focus(); // the safe choice is the one Enter picks
    }

    messages.addEventListener('click', warn);
    // Middle click opens a new tab without a click event, so it's caught here too
    messages.addEventListener('auxclick', (event) => {
        if (event.button === 1) warn(event);
    });

    // The link opens itself (target="_blank"), the popup just gets out of the way
    openLink.addEventListener('click', () => dialog.close());

    dialog.querySelectorAll('[data-link-warning-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    // Clicking the dimmed area around the popup closes it
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
})();

// Message requests popup: chats the user was added to but hasn't accepted yet.
// Accept and Decline are plain form posts, the server does the rest.
// A message request notification links to /chat/?requests=1, which opens it straight away (when there still are any).
(() => {
    const dialog = document.querySelector('.chat-requests');
    if (!dialog) return;

    const openButton = document.querySelector('[data-requests-open]');
    openButton?.addEventListener('click', () => dialog.showModal());

    const params = new URLSearchParams(location.search);
    if (params.get('requests') === '1') {
        params.delete('requests'); // so a refresh doesn't open it again
        history.replaceState(null, '', location.pathname + (params.size > 0 ? '?' + params : ''));
        if (openButton && !openButton.hidden) dialog.showModal();
    }

    dialog.querySelector('[data-requests-close]').addEventListener('click', () => dialog.close());

    // Clicking the dimmed area around the popup closes it
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
})();

// Members popup: the person button in a group's header lists everyone in it.
// Group admins can make a member an admin or kick them, and the group owner can remove an admin,
// all asked about first in a popup on top of it.
(() => {
    const dialog = document.querySelector('.chat-members');
    if (!dialog) return;

    const confirmDialog = document.querySelector('.chat-member-confirm');
    let memberButton = null; // the Make admin, Remove admin or Kick button that opened the confirm popup

    document.querySelector('[data-members-open]').addEventListener('click', () => dialog.showModal());

    function confirmMember(button) {
        const name = button.dataset.memberName;
        const form = confirmDialog.querySelector('form');
        const submit = confirmDialog.querySelector('[data-member-confirm-submit]');
        const action = button.dataset.memberAction;
        memberButton = button;
        form.elements.member.value = button.dataset.memberId;
        confirmDialog.querySelector('[data-member-confirm-action]').value = action;

        if (action === 'makeAdmin') {
            confirmDialog.querySelector('[data-member-confirm-title]').textContent = `Make ${name} an admin?`;
            confirmDialog.querySelector('[data-member-confirm-text]').textContent =
                `${name} will be able to rename the group, change its icon and background, delete messages, make others admin, kick members and delete the group.`;
            submit.textContent = 'Make admin';
        } else if (action === 'removeAdmin') {
            confirmDialog.querySelector('[data-member-confirm-title]').textContent = `Remove ${name} as admin?`;
            confirmDialog.querySelector('[data-member-confirm-text]').textContent =
                `${name} will stay in the group as a normal member.`;
            submit.textContent = 'Remove admin';
        } else if (button.hasAttribute('data-member-invited')) {
            confirmDialog.querySelector('[data-member-confirm-title]').textContent = `Kick ${name}?`;
            confirmDialog.querySelector('[data-member-confirm-text]').textContent =
                `${name} hasn't accepted the group yet. Their invite will be taken back.`;
            submit.textContent = 'Kick';
        } else {
            confirmDialog.querySelector('[data-member-confirm-title]').textContent = `Kick ${name}?`;
            confirmDialog.querySelector('[data-member-confirm-text]').textContent =
                `${name} will be removed from the group and won't see its messages anymore.`;
            submit.textContent = 'Kick';
        }
        submit.classList.toggle('is-danger', action !== 'makeAdmin');

        confirmDialog.showModal();
        confirmDialog.querySelector('.chat-btn[data-dialog-close]').focus(); // the safe choice is the one Enter picks
    }

    dialog.addEventListener('click', (event) => {
        const button = event.target.closest('[data-member-action]');
        if (button) confirmMember(button);
    });

    [dialog, confirmDialog].forEach((popup) => {
        if (!popup) return; // the confirm popup is only there for group admins

        popup.querySelectorAll('[data-dialog-close]').forEach((button) => {
            button.addEventListener('click', () => popup.close());
        });

        // Clicking the dimmed area around the popup closes it
        popup.addEventListener('click', (event) => {
            if (event.target === popup) popup.close();
        });
    });

    // Back on the button it was opened from, in the Members popup still open below it
    confirmDialog?.addEventListener('close', () => memberButton?.focus());
})();

// Member menu, for site admins: right-click someone in the Members popup (or use the menu key / Shift+F10 on them)
// for Admin panel, which opens their admin panel (admin/user.php).
(() => {
    const dialog = document.querySelector('.chat-members');
    const menu = document.querySelector('[data-member-menu]');
    if (!dialog || !menu) return;

    const adminLink = menu.querySelector('[data-menu-site-admin]');
    const memberMenu = contextMenu({
        menu,
        list: dialog.querySelector('.chat-members-list'),
        items: '[data-member-user-id]',
        focusItem: (item) => item.querySelector('a, button')?.focus(),
        onOpen(item) {
            adminLink.href = '/admin/user?user=' + item.dataset.memberUserId;
        },
    });

    dialog.addEventListener('close', () => memberMenu.close(false));
})();

// Right-click menus, used by the chat menu and the message menu.
// Right-clicking an item in `list` (or the menu key / Shift+F10 on it) opens `menu` where the pointer is.
// Arrow keys, Home and End move through the menu. Escape and Tab close it, back on the item it was opened on.
// onOpen(item) hides the menu items that don't apply, and skip(event, item) can leave the browser's own menu be.
// A smaller menu inside it (like Mute chat's) handles its own keys.
function contextMenu({ menu, list, items, focusItem, onOpen, skip = () => false }) {
    let current = null; // the item the menu was opened on

    function menuItems() {
        return [...menu.querySelectorAll(':scope > [role="menuitem"]')].filter((item) => !item.hidden);
    }

    function open(item, x, y) {
        current = item;
        onOpen(item);

        // Where it was opened, but never past the edge of the window
        menu.hidden = false;
        const { width, height } = menu.getBoundingClientRect();
        menu.style.left = Math.max(8, Math.min(x, window.innerWidth - width - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(y, window.innerHeight - height - 8)) + 'px';
        menuItems().find((menuItem) => !menuItem.hasAttribute('aria-disabled'))?.focus();
    }

    function close(returnFocus) {
        if (menu.hidden) return;
        menu.hidden = true;
        if (returnFocus) focusItem(current);
    }

    list.addEventListener('contextmenu', (event) => {
        const item = event.target.closest(items);
        if (!item || skip(event, item)) return;
        event.preventDefault();
        // Opened from the keyboard there's no pointer position, so it goes under the item instead
        if (event.clientX === 0 && event.clientY === 0) {
            const rect = item.getBoundingClientRect();
            open(item, rect.left + 16, rect.bottom);
        } else {
            open(item, event.clientX, event.clientY);
        }
    });

    document.addEventListener('pointerdown', (event) => {
        if (!menu.contains(event.target)) close(false);
    });
    list.addEventListener('scroll', () => close(false));
    window.addEventListener('resize', () => close(false));
    window.addEventListener('blur', () => close(false));

    menu.addEventListener('keydown', (event) => {
        const menuItemList = menuItems();
        const index = menuItemList.indexOf(document.activeElement);
        const moves = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: menuItemList.length - 1 };
        if (event.key in moves) {
            event.preventDefault();
            menuItemList[(moves[event.key] + menuItemList.length) % menuItemList.length].focus();
        } else if (event.key === 'Escape' || event.key === 'Tab') {
            event.preventDefault();
            close(true);
        }
    });

    return { close };
}

// A chat's background gradient (Change background), from the server as "#a1b2c3 #d4e5f6 90" (two colors and the angle),
// or nothing for the default background. Set from here, as the site's content security policy doesn't allow style attributes.
function chatShowBackground(element, background) {
    const [color1, color2, angle] = (background ?? '').split(' ');
    const hasBackground = angle !== undefined;
    element.style.backgroundImage = hasBackground ? `linear-gradient(${angle}deg, ${color1}, ${color2})` : '';
    element.classList.toggle('has-background', hasBackground);
}

// Chat menu: right-click a chat in the list (or use the menu key / Shift+F10 on it) to mute, hide or leave it.
// In a DM the other person's profile can be opened (otherPages/profile.php) and they can be given a nickname.
// Group admins can also change the group's name or icon, or delete it.
// Group admins and both people in a DM can change the chat's background.
// Site admins also get Admin panel, which opens the other person's admin panel on a DM (admin/user.php)
// and the chat's own on a group, or on a DM the other person left (admin/chat.php).
// Every action is a plain form post - the server checks who's allowed to do what.
(() => {
    const menu = document.querySelector('[data-chat-menu]');
    const list = document.querySelector('.chat-list');
    if (!menu || !list) return;

    const siteAdminItems = menu.querySelectorAll('[data-menu-site-admin]'); // only on the page for site admins
    const profileItem = menu.querySelector('[data-menu-profile]');

    const hideForm = document.querySelector('[data-chat-hide]');
    const muteForm = document.querySelector('[data-chat-mute]');
    const muteItem = menu.querySelector('[data-menu-mute]');
    const muteMenu = menu.querySelector('[data-mute-menu]');
    const confirmDialog = document.querySelector('.chat-confirm');
    const nicknameDialog = document.querySelector('.chat-nickname');
    const renameDialog = document.querySelector('.chat-rename');
    const iconDialog = document.querySelector('.chat-icon-edit');
    const backgroundDialog = document.querySelector('.chat-background-edit');
    let chat = null; // the list item the menu was opened on

    const chatMenu = contextMenu({
        menu,
        list,
        items: '[data-chat-id]',
        focusItem: (item) => item.querySelector('a').focus(),
        onOpen(item) {
            chat = item;
            const isGroup = item.dataset.chatType === 'group';
            const isAdmin = item.hasAttribute('data-chat-admin');
            menu.querySelectorAll('[data-menu-dm]').forEach((menuItem) => { menuItem.hidden = isGroup; });
            menu.querySelectorAll('[data-menu-admin]').forEach((menuItem) => { menuItem.hidden = !isAdmin; });
            menu.querySelector('[data-menu-action="background"]').hidden = isGroup && !isAdmin;
            // Nobody to give a nickname in a DM the other person left
            if (!item.hasAttribute('data-chat-username')) menu.querySelector('[data-menu-action="nickname"]').hidden = true;
            const isMuted = item.hasAttribute('data-chat-muted');
            muteItem.hidden = isMuted;
            menu.querySelector('[data-menu-action="unmute"]').hidden = !isMuted;
            // Nobody's profile to open in a group, or a DM the other person left. Their admin panel is the chat's own then.
            const dmUserId = isGroup ? undefined : item.dataset.chatUserId;
            profileItem.hidden = dmUserId === undefined;
            if (dmUserId !== undefined) profileItem.href = '/otherPages/profile?user=' + dmUserId;
            siteAdminItems.forEach((menuItem) => {
                if (menuItem instanceof HTMLAnchorElement) {
                    menuItem.href = dmUserId !== undefined ? '/admin/user?user=' + dmUserId : '/admin/chat?chat=' + item.dataset.chatId;
                }
            });
            closeMuteMenu(false);
        },
    });

    // Mute chat: how long for is picked in a small menu next to it. Pointing at Mute chat opens it,
    // and a click, Enter, Space or the right arrow opens it and moves into it. The left arrow and Escape go back.
    const muteOptions = [...muteMenu.querySelectorAll('[role="menuitem"]')];

    function openMuteMenu(focusFirst) {
        muteMenu.hidden = false;
        muteItem.setAttribute('aria-expanded', 'true');

        // To the right of the chat menu, lined up with Mute chat - or on the left when there's no room on the right.
        // It's placed inside the chat menu, so these are from the chat menu's corner.
        const menuRect = menu.getBoundingClientRect();
        const { width, height } = muteMenu.getBoundingClientRect();
        const fitsRight = menuRect.right + 4 + width <= window.innerWidth - 8;
        muteMenu.style.left = (fitsRight ? menu.clientWidth + 4 : -width - 4) + 'px';
        const top = muteItem.offsetTop - 7; // the small menu's border and padding
        muteMenu.style.top = Math.max(8 - menuRect.top, Math.min(top, window.innerHeight - 8 - height - menuRect.top)) + 'px';

        if (focusFirst) muteOptions[0].focus();
    }

    function closeMuteMenu(returnFocus) {
        muteMenu.hidden = true;
        muteItem.setAttribute('aria-expanded', 'false');
        if (returnFocus) muteItem.focus();
    }

    muteItem.addEventListener('pointerenter', () => openMuteMenu(false));
    muteItem.addEventListener('click', () => openMuteMenu(true));
    muteItem.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowRight') return;
        event.preventDefault();
        openMuteMenu(true);
    });

    // Pointing at or moving to another item in the chat menu closes it
    ['pointerover', 'focusin'].forEach((type) => {
        menu.addEventListener(type, (event) => {
            const menuItem = event.target.closest('[role="menuitem"]');
            if (menuItem && menuItem.parentElement === menu && menuItem !== muteItem) closeMuteMenu(false);
        });
    });

    // Its own keys, so the chat menu doesn't move as well. Tab is left to the chat menu, which closes everything.
    muteMenu.addEventListener('keydown', (event) => {
        const index = muteOptions.indexOf(document.activeElement);
        const moves = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: muteOptions.length - 1 };
        if (event.key in moves) {
            muteOptions[(moves[event.key] + muteOptions.length) % muteOptions.length].focus();
        } else if (event.key === 'ArrowLeft' || event.key === 'Escape') {
            closeMuteMenu(true);
        } else {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
    });

    muteMenu.addEventListener('click', (event) => {
        const option = event.target.closest('[data-mute-minutes]');
        if (!option) return;
        chatMenu.close(false);
        muteForm.elements.action.value = 'mute';
        muteForm.elements.chat.value = chat.dataset.chatId;
        muteForm.elements.minutes.value = option.dataset.muteMinutes;
        muteForm.submit();
    });

    function confirmAction(action, title, text, submitLabel) {
        confirmDialog.querySelector('form').elements.chat.value = chat.dataset.chatId;
        confirmDialog.querySelector('[data-confirm-action]').value = action;
        confirmDialog.querySelector('[data-confirm-title]').textContent = title;
        confirmDialog.querySelector('[data-confirm-text]').textContent = text;
        confirmDialog.querySelector('[data-confirm-submit]').textContent = submitLabel;
        confirmDialog.showModal();
        confirmDialog.querySelector('.chat-btn[data-dialog-close]').focus(); // the safe choice is the one Enter picks
    }

    function openNickname() {
        const form = nicknameDialog.querySelector('form');
        const userName = chat.dataset.chatUsername;
        form.elements.chat.value = chat.dataset.chatId;
        form.elements.nickname.value = chat.dataset.chatNickname ?? '';
        nicknameDialog.querySelector('[data-nickname-label]').textContent = `Nickname for ${userName}`;
        nicknameDialog.querySelector('[data-nickname-hint]').textContent = `Shown instead of their username in this chat. Leave it empty to go back to ${userName}.`;
        nicknameDialog.showModal();
        form.elements.nickname.focus();
        form.elements.nickname.select();
    }

    function openRename() {
        const form = renameDialog.querySelector('form');
        form.elements.chat.value = chat.dataset.chatId;
        form.elements.name.value = chat.dataset.chatName;
        renameDialog.showModal();
        form.elements.name.focus();
        form.elements.name.select();
    }

    // Change group icon: shows the picked image before it's saved, and turns away files the server would refuse
    const iconForm = iconDialog.querySelector('form');
    const iconFile = iconDialog.querySelector('[data-icon-file]');
    const iconPreview = iconDialog.querySelector('[data-icon-preview]');
    const iconStatus = iconDialog.querySelector('[data-icon-status]');
    const iconSave = iconDialog.querySelector('[data-icon-save]');
    const iconRemove = iconDialog.querySelector('[data-icon-remove]');
    const iconTypes = iconDialog.dataset.types.split(',');
    const iconMaxBytes = Number(iconDialog.dataset.maxBytes);
    const iconHint = `PNG, JPEG, GIF or WebP, up to ${iconMaxBytes / 1024 / 1024} MB.`;

    function openIcon() {
        iconForm.reset();
        iconForm.elements.chat.value = chat.dataset.chatId;
        iconPreview.replaceChildren(chat.querySelector('.chat-avatar').cloneNode(true));
        iconRemove.hidden = !chat.hasAttribute('data-chat-icon');
        iconSave.disabled = true;
        iconStatus.textContent = iconHint;
        iconDialog.showModal();
    }

    iconFile.addEventListener('change', () => {
        const picked = iconFile.files[0];
        iconSave.disabled = true;
        if (!picked) {
            iconStatus.textContent = iconHint;
            return;
        }
        if (!iconTypes.includes(picked.type) || picked.size > iconMaxBytes) {
            iconFile.value = '';
            iconStatus.textContent = `That image can't be used. ${iconHint}`;
            return;
        }

        // A data: URL, as the site's content security policy doesn't allow blob: images
        const reader = new FileReader();
        reader.addEventListener('load', () => {
            const image = document.createElement('img');
            image.src = reader.result;
            image.alt = '';
            const avatar = document.createElement('span');
            avatar.className = 'chat-avatar';
            avatar.append(image);
            iconPreview.replaceChildren(avatar);
        });
        reader.readAsDataURL(picked);
        iconStatus.textContent = picked.name;
        iconSave.disabled = false;
    });

    // Change background: the preview shows the gradient as the colors and direction are picked.
    // A chat with the default background starts out at the site's orange going right into purple.
    const backgroundForm = backgroundDialog.querySelector('form');
    const backgroundPreview = backgroundDialog.querySelector('[data-background-preview]');

    function showBackgroundPreview() {
        const { color1, color2, angle } = backgroundForm.elements;
        chatShowBackground(backgroundPreview, `${color1.value} ${color2.value} ${angle.value}`);
    }

    function openBackground() {
        const [color1, color2, angle] = (chat.dataset.chatBackground ?? '#f2711c #6a4bd6 90').split(' ');
        backgroundForm.elements.chat.value = chat.dataset.chatId;
        backgroundForm.elements.color1.value = color1;
        backgroundForm.elements.color2.value = color2;
        backgroundForm.elements.angle.value = angle;
        backgroundDialog.querySelector('[data-background-remove]').hidden = !chat.hasAttribute('data-chat-background');
        showBackgroundPreview();
        backgroundDialog.showModal();
    }

    backgroundForm.addEventListener('input', showBackgroundPreview);

    const actions = {
        unmute() {
            muteForm.elements.action.value = 'unmute';
            muteForm.elements.chat.value = chat.dataset.chatId;
            muteForm.submit();
        },
        hide() {
            hideForm.elements.chat.value = chat.dataset.chatId;
            hideForm.submit();
        },
        leave() {
            const title = chat.dataset.chatTitle;
            if (chat.dataset.chatType === 'dm') {
                confirmAction('leave', 'Leave chat?',
                    `You'll leave your chat with ${title} and won't see its messages anymore. Starting a new DM won't bring them back.`,
                    'Leave chat');
                return;
            }
            confirmAction('leave', 'Leave group?',
                `You'll leave ${title} and won't see its messages anymore.`
                    + (chat.hasAttribute('data-chat-owner')
                        ? " You're the owner, so the longest-standing admin takes over (or member, if there are no admins)." : ''),
                'Leave group');
        },
        delete() {
            confirmAction('delete', 'Delete group?',
                `${chat.dataset.chatTitle} and all its messages will be gone for everyone in it. This can't be undone.`,
                'Delete group');
        },
        nickname: openNickname,
        rename: openRename,
        icon: openIcon,
        background: openBackground,
    };

    menu.addEventListener('click', (event) => {
        const button = event.target.closest('[data-menu-action]');
        if (!button) return;
        chatMenu.close(false);
        actions[button.dataset.menuAction]();
    });

    [confirmDialog, nicknameDialog, renameDialog, iconDialog, backgroundDialog].forEach((dialog) => {
        dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });

        // Clicking the dimmed area around the popup closes it
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });

        // The menu that opened it is gone, so focus goes back to the chat
        dialog.addEventListener('close', () => chat?.querySelector('a').focus());
    });
})();

// Group admins can change the group's name right in the open chat's header: clicking the name turns it into a text box.
// Enter saves it (a plain form post, like Change name in the chat menu), Escape or clicking away puts the name back.
// Left empty, the group is named after its members again.
(() => {
    const openButton = document.querySelector('[data-rename-open]');
    const form = document.querySelector('[data-rename-form]');
    if (!openButton || !form) return;

    const title = openButton.parentElement;
    const input = form.elements.name;
    const savedName = input.value;
    let isSaving = false;

    function close(returnFocus) {
        if (form.hidden) return;
        form.hidden = true;
        title.hidden = false;
        input.value = savedName;
        if (returnFocus) openButton.focus();
    }

    openButton.addEventListener('click', () => {
        title.hidden = true;
        form.hidden = false;
        input.focus();
        input.select();
    });

    form.addEventListener('submit', (event) => {
        // Pressing Enter twice doesn't send it twice, and the same name again isn't worth a reload
        if (isSaving || input.value.trim() === savedName) {
            event.preventDefault();
            if (!isSaving) close(true);
            return;
        }
        isSaving = true;
    });

    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        close(true);
    });

    input.addEventListener('blur', () => {
        if (!isSaving) close(false);
    });
})();

// Message menu: right-click a message in the open chat (or use the menu key / Shift+F10 on it) to reply to it,
// or mark the chat unread from it on. Your own messages can also be edited or deleted,
// and group admins can delete anyone's (shown as deleted by a group admin).
// Site admins also get Admin panel on other people's messages, which opens the sender's admin panel (admin/user.php).
// Links and selected text keep the browser's own menu, so they can still be copied.
// The arrow keys, Home and End move between the messages, so the menu can be reached without a mouse.
(() => {
    const menu = document.querySelector('[data-message-menu]');
    const messages = document.querySelector('[data-chat-messages]');
    const composer = document.querySelector('.chat-composer');
    if (!menu || !messages || !composer) return;

    const textarea = composer.querySelector('textarea');
    const replyInput = composer.querySelector('[data-reply-input]');
    const replyBar = composer.querySelector('[data-reply-bar]');
    const unreadForm = document.querySelector('[data-message-unread]');
    const editDialog = document.querySelector('.chat-message-edit');
    const editForm = editDialog.querySelector('form');
    const editText = editForm.elements.content;
    const editSave = editDialog.querySelector('[data-edit-save]');
    const deleteDialog = document.querySelector('.chat-message-delete');
    const isGroupAdmin = messages.hasAttribute('data-group-admin'); // can delete other members' messages too
    const siteAdminItems = menu.querySelectorAll('[data-menu-site-admin]'); // only on the page for site admins
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let message = null; // the message the menu was opened on

    function messageItems() {
        return [...messages.querySelectorAll('[data-message-id]')];
    }

    // The message as it's shown, without the formatting - the line breaks are still in the text after each <br>
    function messageText(item) {
        return item.querySelector('[data-message-text]')?.textContent.trim() ?? '';
    }

    // The message as it was typed, ** and all, which the user's own messages carry for Edit
    function messageSource(item) {
        return item.querySelector('[data-message-text]')?.dataset.messageSource ?? messageText(item);
    }

    // What the reply bar and the delete popup show for the message
    function messagePreview(item) {
        return messageText(item) || (item.hasAttribute('data-message-gif') ? 'GIF' : 'Attachment');
    }

    // Only one message can be tabbed to at a time: the one last focused, at first the newest
    function focusMessage(item, options) {
        messageItems().forEach((other) => { other.tabIndex = -1; });
        item.tabIndex = 0;
        item.focus(options);
    }

    const newest = messageItems().at(-1);
    if (newest) newest.tabIndex = 0;

    messages.addEventListener('focusin', (event) => {
        if (!event.target.matches('[data-message-id]')) return;
        messageItems().forEach((other) => { other.tabIndex = other === event.target ? 0 : -1; });
    });

    messages.addEventListener('keydown', (event) => {
        if (!event.target.matches('[data-message-id]')) return;
        const items = messageItems();
        const index = items.indexOf(event.target);
        const moves = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: items.length - 1 };
        if (!(event.key in moves)) return;
        event.preventDefault();
        focusMessage(items[Math.max(0, Math.min(moves[event.key], items.length - 1))]);
    });

    const messageMenu = contextMenu({
        menu,
        list: messages,
        items: '[data-message-id]',
        focusItem: (item) => focusMessage(item),
        skip(event, item) {
            const selection = window.getSelection();
            return event.target.closest('[data-chat-link]') !== null
                || (selection !== null && !selection.isCollapsed && item.contains(selection.anchorNode));
        },
        onOpen(item) {
            message = item;
            const isDeleted = item.hasAttribute('data-message-deleted');
            const isOwn = item.hasAttribute('data-message-own') && !isDeleted;
            const canDelete = isOwn || (isGroupAdmin && !isDeleted);
            menu.querySelector('[data-menu-action="reply"]').hidden = isDeleted;
            menu.querySelector('[data-menu-action="edit"]').hidden = !isOwn || !item.querySelector('[data-message-text]');
            menu.querySelectorAll('[data-menu-delete]').forEach((menuItem) => { menuItem.hidden = !canDelete; });
            const isFromOther = !item.hasAttribute('data-message-own');
            siteAdminItems.forEach((menuItem) => {
                menuItem.hidden = !isFromOther;
                if (menuItem instanceof HTMLAnchorElement) menuItem.href = '/admin/user?user=' + item.dataset.messageUserId;
            });
        },
    });

    // Reply: a bar above the composer says what's being answered, until it's sent or cancelled (× or Escape)
    function startReply(item) {
        replyInput.value = item.dataset.messageId;
        replyBar.querySelector('[data-reply-sender]').textContent =
            item.hasAttribute('data-message-own') ? 'yourself' : item.dataset.messageSender;
        replyBar.querySelector('[data-reply-preview]').textContent = messagePreview(item);
        replyBar.hidden = false;
        textarea.focus();
    }

    function cancelReply() {
        replyInput.value = '';
        replyBar.hidden = true;
    }

    replyBar.querySelector('[data-reply-cancel]').addEventListener('click', () => {
        cancelReply();
        textarea.focus();
    });

    textarea.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !replyBar.hidden) cancelReply();
    });

    // Edit: Enter saves like in the composer, and Save waits until something has changed
    function openEdit(item) {
        editForm.elements.message.value = item.dataset.messageId;
        editText.value = messageSource(item);
        editText.defaultValue = editText.value;
        editSave.disabled = true;
        editDialog.showModal();
        editText.focus();
        editText.setSelectionRange(editText.value.length, editText.value.length);
    }

    editText.addEventListener('input', () => {
        editSave.disabled = editText.value.trim() === '' || editText.value === editText.defaultValue;
    });

    editText.addEventListener('keydown', (event) => {
        if (chatFormatShortcut(event, editText)) return;
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing || chatInCodeBlock(editText)) return;
        event.preventDefault();
        if (!editSave.disabled) editForm.requestSubmit();
    });

    // A group admin deleting someone else's message is told it'll say so
    function openDelete(item) {
        deleteDialog.querySelector('form').elements.message.value = item.dataset.messageId;
        deleteDialog.querySelector('[data-delete-text]').textContent = item.hasAttribute('data-message-own')
            ? "Everyone in the chat will see it as deleted. This can't be undone."
            : `Everyone in the chat will see ${item.dataset.messageSender}'s message as deleted by a group admin. This can't be undone.`;
        deleteDialog.querySelector('[data-delete-preview]').textContent = messagePreview(item);
        deleteDialog.showModal();
        deleteDialog.querySelector('.chat-btn[data-dialog-close]').focus(); // the safe choice is the one Enter picks
    }

    const actions = {
        reply: startReply,
        edit: openEdit,
        delete: openDelete,
        unread(item) {
            unreadForm.elements.message.value = item.dataset.messageId;
            unreadForm.submit();
        },
    };

    menu.addEventListener('click', (event) => {
        const button = event.target.closest('[data-menu-action]');
        if (!button) return;
        messageMenu.close(false);
        actions[button.dataset.menuAction](message);
    });

    [editDialog, deleteDialog].forEach((dialog) => {
        dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });

        // Clicking the dimmed area around the popup closes it
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });

        // The menu that opened it is gone, so focus goes back to the message
        dialog.addEventListener('close', () => {
            if (message) focusMessage(message, { preventScroll: true });
        });
    });

    // The quote above a reply scrolls to the message it answers, which lights up for a moment.
    // When that message isn't loaded yet, the older messages part below loads back to it first (chat:load-until).
    function jumpTo(target) {
        target.scrollIntoView({ block: 'center', behavior: reducedMotion.matches ? 'auto' : 'smooth' });
        focusMessage(target, { preventScroll: true });
        target.classList.add('is-highlighted');
        setTimeout(() => target.classList.remove('is-highlighted'), 1500);
    }

    messages.addEventListener('click', (event) => {
        const link = event.target.closest('[data-reply-link]');
        if (!link) return;
        event.preventDefault();
        const targetId = 'message-' + link.dataset.replyId;
        const target = document.getElementById(targetId);
        if (target) {
            jumpTo(target);
            return;
        }
        messages.dispatchEvent(new CustomEvent('chat:load-until', {
            detail: {
                messageId: link.dataset.replyId,
                onDone() {
                    const loaded = document.getElementById(targetId);
                    if (loaded) jumpTo(loaded);
                },
            },
        }));
    });
})();

// Attach popup: the button left of the message box opens a small menu just above it.
// File and Voice message are coming soon - they're disabled, so picking one does nothing.
(() => {
    const button = document.querySelector('[data-attach-open]');
    const menu = document.querySelector('[data-attach-menu]');
    if (!button || !menu) return;

    const menuItems = [...menu.querySelectorAll('[role="menuitem"]')];

    function setOpen(isOpen, returnFocus) {
        menu.hidden = !isOpen;
        button.setAttribute('aria-expanded', String(isOpen));
        if (isOpen) menuItems[0].focus();
        else if (returnFocus) button.focus();
    }

    button.addEventListener('click', () => setOpen(menu.hidden, true));

    menu.addEventListener('click', (event) => {
        const menuItem = event.target.closest('[role="menuitem"]');
        if (menuItem && !menuItem.hasAttribute('aria-disabled')) setOpen(false, true);
    });

    document.addEventListener('pointerdown', (event) => {
        if (!menu.hidden && !menu.contains(event.target) && !button.contains(event.target)) setOpen(false, false);
    });

    // Arrow keys, Home and End move through the menu. Escape and Tab close it, back on the button.
    menu.addEventListener('keydown', (event) => {
        const index = menuItems.indexOf(document.activeElement);
        const moves = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: menuItems.length - 1 };
        if (event.key in moves) {
            event.preventDefault();
            menuItems[(moves[event.key] + menuItems.length) % menuItems.length].focus();
        } else if (event.key === 'Escape' || event.key === 'Tab') {
            event.preventDefault();
            setOpen(false, true);
        }
    });
})();

// Emoji picker: the smiley left of GIF opens a popup above it with the emoji by category, and the recently used ones first.
// Picking one puts it where the cursor is in the message box, and the popup stays open for more.
// chat.css hides it on phones and tablets, which have emoji on their keyboard.
(() => {
    const button = document.querySelector('[data-emoji-open]');
    const picker = document.querySelector('[data-emoji-picker]');
    const textarea = document.querySelector('.chat-composer textarea');
    if (!button || !picker || !textarea) return;

    const tabs = picker.querySelector('[data-emoji-tabs]');
    const list = picker.querySelector('[data-emoji-list]');
    const RECENT_KEY = 'hbhub-chat-emoji-recent';
    const RECENT_MAX = 16;

    // [name, tab icon, the emoji with spaces between]
    const categories = [
        ['Smileys', '😀', '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 🥲 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 🤥 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🤧 🥵 🥶 🥴 😵 🤯 🤠 🥳 🥸 😎 🤓 🧐 😕 😟 🙁 ☹️ 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 ☠️ 💩 🤡 👹 👺 👻 👽 👾 🤖 😺 😸 😹 😻 😼 😽 🙀 😿 😾 🙈 🙉 🙊'],
        ['People', '👋', '👋 🤚 🖐️ ✋ 🖖 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 🖕 👇 ☝️ 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💅 🤳 💪 🦵 🦶 👂 👃 🧠 👀 👁️ 👅 👄 💋 👶 🧒 👦 👧 🧑 👱 👨 🧔 👩 🧓 👴 👵 🙍 🙎 🙅 🙆 💁 🙋 🧏 🙇 🤦 🤷 👮 🕵️ 💂 👷 🤴 👸 🧙 🧚 🧛 🧜 🧝 🧞 🧟 🦸 🦹 🏃 💃 🕺 👯 🧘 👪 💑 💏'],
        ['Animals & nature', '🐶', '🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🐔 🐧 🐦 🐤 🦆 🦅 🦉 🦇 🐺 🐗 🐴 🦄 🐝 🐛 🦋 🐌 🐞 🐜 🕷️ 🦂 🐢 🐍 🦎 🦖 🦕 🐙 🦑 🦐 🦀 🐡 🐠 🐟 🐬 🐳 🐋 🦈 🐊 🐅 🐆 🦓 🦍 🐘 🦛 🦏 🐪 🦒 🦘 🐃 🐄 🐎 🐖 🐏 🐑 🐐 🦌 🐕 🐩 🐈 🐓 🦃 🦚 🦜 🦢 🦩 🕊️ 🐇 🦝 🦨 🦡 🦦 🦥 🐁 🐿️ 🦔 🌵 🎄 🌲 🌳 🌴 🌱 🌿 ☘️ 🍀 🍁 🍂 🍃 🍄 🌷 🌹 🥀 🌺 🌸 🌼 🌻 🌞 🌝 🌚 🌙 ⭐ 🌟 ✨ ⚡ 🔥 🌈 ☀️ ⛅ ☁️ 🌧️ ⛈️ 🌩️ ❄️ ☃️ ⛄ 💨 💧 💦 🌊'],
        ['Food & drink', '🍔', '🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🫐 🍈 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🍆 🥑 🥦 🥬 🥒 🌶️ 🌽 🥕 🧄 🧅 🥔 🍠 🥐 🥯 🍞 🥖 🥨 🧀 🥚 🍳 🧈 🥞 🧇 🥓 🥩 🍗 🍖 🌭 🍔 🍟 🍕 🥪 🥙 🧆 🌮 🌯 🥗 🥘 🍝 🍜 🍲 🍛 🍣 🍱 🥟 🍤 🍙 🍚 🍘 🍥 🥠 🍢 🍡 🍧 🍨 🍦 🥧 🧁 🍰 🎂 🍮 🍭 🍬 🍫 🍿 🍩 🍪 🌰 🥜 🍯 🥛 🍼 ☕ 🍵 🧃 🥤 🧋 🍶 🍺 🍻 🥂 🍷 🥃 🍸 🍹 🧉 🍾 🧊 🥄 🍴 🍽️'],
        ['Activities', '⚽', '⚽ 🏀 🏈 ⚾ 🥎 🎾 🏐 🏉 🥏 🎱 🪀 🏓 🏸 🏒 🏑 🥍 🏏 🥅 ⛳ 🪁 🏹 🎣 🤿 🥊 🥋 🎽 🛹 🛼 🛷 ⛸️ 🥌 🎿 ⛷️ 🏂 🏋️ 🤸 🤺 ⛹️ 🤾 🏌️ 🏇 🧗 🚴 🏊 🏄 🏆 🥇 🥈 🥉 🏅 🎖️ 🎗️ 🎫 🎟️ 🎪 🎭 🎨 🎬 🎤 🎧 🎼 🎹 🥁 🎷 🎺 🎸 🪕 🎻 🎲 ♟️ 🎯 🎳 🎮 🕹️ 🎰 🧩 🎉 🎊 🎈 🎁 🎀 🎃 🎄 🎆 🎇 🧨'],
        ['Travel & places', '🚗', '🚗 🚕 🚙 🚌 🚎 🏎️ 🚓 🚑 🚒 🚐 🛻 🚚 🚛 🚜 🛵 🏍️ 🛺 🚲 🛴 🚨 🚔 🚍 🚘 🚖 🚡 🚠 🚟 🚃 🚋 🚞 🚝 🚄 🚅 🚈 🚂 🚆 🚇 🚊 🚉 ✈️ 🛫 🛬 🛩️ 💺 🛰️ 🚀 🛸 🚁 🛶 ⛵ 🚤 🛥️ 🛳️ ⛴️ 🚢 ⚓ ⛽ 🚧 🚦 🚥 🗺️ 🗿 🗽 🗼 🏰 🏯 🏟️ 🎡 🎢 🎠 ⛲ ⛱️ 🏖️ 🏝️ 🏜️ 🌋 ⛰️ 🏔️ 🗻 🏕️ ⛺ 🏠 🏡 🏘️ 🏚️ 🏗️ 🏭 🏢 🏬 🏣 🏤 🏥 🏦 🏨 🏪 🏫 🏩 💒 🏛️ ⛪ 🕌 🕍 🛕 🕋 ⛩️ 🌅 🌄 🌠 🎑 🏙️ 🌃 🌌 🌉 🌁'],
        ['Objects', '💡', '⌚ 📱 💻 ⌨️ 🖥️ 🖨️ 🖱️ 🕹️ 💽 💾 💿 📀 📷 📸 📹 🎥 📞 ☎️ 📺 📻 🎙️ ⏰ ⌛ ⏳ 📡 🔋 🔌 💡 🔦 🕯️ 🧯 💸 💵 💰 💳 💎 ⚖️ 🧰 🔧 🔨 ⚒️ 🛠️ ⛏️ 🔩 ⚙️ 🧱 ⛓️ 🧲 🔫 💣 🧨 🪓 🔪 🗡️ ⚔️ 🛡️ 🔮 🧿 💈 ⚗️ 🔭 🔬 💊 💉 🩸 🧬 🦠 🧪 🌡️ 🧹 🧺 🧻 🚽 🚿 🛁 🧼 🧽 🔑 🗝️ 🚪 🛋️ 🛏️ 🧸 🖼️ 🛍️ 🛒 🎁 ✉️ 📩 📨 📧 📦 📮 📜 📃 📄 📑 📊 📈 📉 🗒️ 📆 📅 🗑️ 📁 📂 🗂️ 📰 📓 📔 📒 📕 📗 📘 📙 📚 📖 🔖 🔗 📎 🖇️ 📐 📏 📌 📍 ✂️ 🖊️ 🖋️ ✒️ 🖌️ 🖍️ 📝 ✏️ 🔍 🔎 🔏 🔐 🔒 🔓 👓 🕶️ 🥽 👔 👕 👖 🧣 🧤 🧥 🧦 👗 👘 👙 👚 👛 👜 👝 🎒 👞 👟 🥾 👠 👡 👢 👑 👒 🎩 🎓 🧢 ⛑️ 💄 💍 💼'],
        ['Symbols', '❤️', '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ☮️ ✝️ ☪️ 🕉️ ☸️ ✡️ 🔯 ☯️ ☦️ 🛐 ⛎ ♈ ♉ ♊ ♋ ♌ ♍ ♎ ♏ ♐ ♑ ♒ ♓ 🆔 ⚛️ ☢️ ☣️ 📴 📳 🆚 💮 🉐 🅰️ 🅱️ 🆎 🆑 🅾️ 🆘 ❌ ⭕ 🛑 ⛔ 📛 🚫 💯 💢 ♨️ 🚷 🚯 🚳 🚱 🔞 📵 🚭 ❗ ❕ ❓ ❔ ‼️ ⁉️ 🔅 🔆 ⚠️ 🚸 🔱 ⚜️ 🔰 ♻️ ✅ ❇️ ✳️ ❎ 🌐 💠 Ⓜ️ 🌀 💤 🏧 🚾 ♿ 🅿️ 🚹 🚺 🚼 🚻 🚮 🎦 📶 🆗 🆙 🆒 🆕 🆓 🔟 🔢 #️⃣ *️⃣ ▶️ ⏸️ ⏯️ ⏹️ ⏺️ ⏭️ ⏮️ ⏩ ⏪ 🔀 🔁 🔂 ◀️ 🔼 🔽 ⏫ ⏬ ➡️ ⬅️ ⬆️ ⬇️ ↗️ ↘️ ↙️ ↖️ ↕️ ↔️ ↪️ ↩️ ⤴️ ⤵️ 🔄 🔃 🎵 🎶 ➕ ➖ ➗ ✖️ ♾️ 💲 💱 ™️ ©️ ®️ 〰️ ➰ ➿ 🔚 🔙 🔛 🔝 🔜 ✔️ ☑️ 🔘 🔴 🟠 🟡 🟢 🔵 🟣 ⚫ ⚪ 🟤 🔺 🔻 🔸 🔹 🔶 🔷 🔳 🔲 ▪️ ▫️ ◾ ◽ ◼️ ◻️ 🟥 🟧 🟨 🟩 🟦 🟪 ⬛ ⬜ 🟫 🔈 🔇 🔉 🔊 🔔 🔕 📣 📢 💬 💭 🗯️ ♠️ ♣️ ♥️ ♦️ 🃏 🎴 🀄 🕐 🕑 🕒 🕓 🕔 🕕 🕖 🕗 🕘 🕙 🕚 🕛'],
    ];

    // Browser storage can be blocked (private windows), so the recent ones are just left out then
    function recentEmoji() {
        try {
            const recent = JSON.parse(localStorage.getItem(RECENT_KEY));
            return Array.isArray(recent) ? recent.filter((emoji) => typeof emoji === 'string').slice(0, RECENT_MAX) : [];
        } catch {
            return [];
        }
    }

    function rememberEmoji(emoji) {
        try {
            const recent = [emoji, ...recentEmoji().filter((other) => other !== emoji)].slice(0, RECENT_MAX);
            localStorage.setItem(RECENT_KEY, JSON.stringify(recent));
        } catch {
            // not remembered, that's fine
        }
    }

    // Built on every open, so the recently used ones are up to date (but don't move around while picking)
    function render() {
        const recent = recentEmoji();
        const sections = (recent.length > 0 ? [['Recently used', '🕘', recent]] : [])
            .concat(categories.map(([name, icon, emoji]) => [name, icon, emoji.split(' ')]));

        tabs.replaceChildren();
        list.replaceChildren();
        sections.forEach(([name, icon, emoji], index) => {
            const heading = document.createElement('h3');
            heading.className = 'chat-emoji-heading';
            heading.id = 'chat-emoji-section-' + index;
            heading.textContent = name;

            const grid = document.createElement('div');
            grid.className = 'chat-emoji-grid';
            grid.setAttribute('role', 'group');
            grid.setAttribute('aria-labelledby', heading.id);
            grid.append(...emoji.map((character) => {
                const pick = document.createElement('button');
                pick.type = 'button';
                pick.tabIndex = -1;
                pick.textContent = character;
                pick.dataset.emoji = character;
                return pick;
            }));

            const section = document.createElement('section');
            section.append(heading, grid);
            list.append(section);

            const tab = document.createElement('button');
            tab.type = 'button';
            tab.textContent = icon;
            tab.title = name;
            tab.setAttribute('aria-label', name);
            tab.addEventListener('click', () => {
                list.scrollTop = section.offsetTop - list.offsetTop;
                setActive(grid.querySelector('button'));
            });
            tabs.append(tab);
        });

        list.scrollTop = 0;
        markCurrentTab();
        setActive(list.querySelector('[data-emoji]'));
    }

    // Only one emoji is a Tab stop, and the arrow keys move between them
    let active = null;
    function setActive(pick, focus = true) {
        if (active) active.tabIndex = -1;
        active = pick;
        active.tabIndex = 0;
        if (focus) active.focus({ preventScroll: true });
        active.scrollIntoView({ block: 'nearest' });
    }

    // The tab for the section at the top of the list lights up
    function markCurrentTab() {
        const sections = [...list.children];
        let current = 0;
        sections.forEach((section, index) => {
            if (section.offsetTop - list.offsetTop <= list.scrollTop + 1) current = index;
        });
        [...tabs.children].forEach((tab, index) => tab.classList.toggle('is-current', index === current));
    }
    list.addEventListener('scroll', markCurrentTab, { passive: true });

    // Puts the emoji where the cursor is (or over the selected text), as long as it fits within maxlength
    function insert(emoji) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        if (textarea.maxLength > 0 && textarea.value.length - (end - start) + emoji.length > textarea.maxLength) return;
        textarea.setRangeText(emoji, start, end, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true })); // so it grows like when typing
        rememberEmoji(emoji);
    }

    function setOpen(isOpen, returnFocus) {
        picker.hidden = !isOpen;
        button.setAttribute('aria-expanded', String(isOpen));
        if (isOpen) render();
        else if (returnFocus) button.focus();
    }

    button.addEventListener('click', () => setOpen(picker.hidden, true));

    list.addEventListener('click', (event) => {
        const pick = event.target.closest('[data-emoji]');
        if (!pick) return;
        setActive(pick);
        insert(pick.dataset.emoji);
    });

    // The emoji in the next row up or down: the closest one sideways in the nearest row that way
    function inNextRow(from, direction) {
        const fromBox = from.getBoundingClientRect();
        let best = null;
        let bestScore = Infinity;
        list.querySelectorAll('[data-emoji]').forEach((pick) => {
            const box = pick.getBoundingClientRect();
            const rows = (box.top - fromBox.top) * direction;
            if (rows < 1) return;
            const score = rows * 1000 + Math.abs(box.left - fromBox.left);
            if (score < bestScore) {
                best = pick;
                bestScore = score;
            }
        });
        return best;
    }

    // Arrow keys move through the emoji, Home and End to the first and last. Escape closes, back on the button.
    picker.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false, true);
            return;
        }

        const pick = event.target.closest('[data-emoji]');
        if (!pick) return;
        if (event.key === 'Tab' && !event.shiftKey) {
            event.preventDefault();
            setOpen(false, true);
            return;
        }

        const picks = [...list.querySelectorAll('[data-emoji]')];
        const index = picks.indexOf(pick);
        const moves = {
            ArrowLeft: () => picks[index - 1],
            ArrowRight: () => picks[index + 1],
            ArrowUp: () => inNextRow(pick, -1),
            ArrowDown: () => inNextRow(pick, 1),
            Home: () => picks[0],
            End: () => picks[picks.length - 1],
        };
        if (!(event.key in moves)) return;
        event.preventDefault();
        const next = moves[event.key]();
        if (next) setActive(next);
    });

    document.addEventListener('pointerdown', (event) => {
        if (!picker.hidden && !picker.contains(event.target) && !button.contains(event.target)) setOpen(false, false);
    });
})();

// GIF picker: the GIF button next to Send searches Giphy through /chat/gifs (which holds the API key),
// with the trending GIFs shown before anything is typed. Picking one sends it straight away, together with
// whatever was typed in the message box (the server sends that first) and the reply, if there is one.
(() => {
    const dialog = document.querySelector('.chat-gif');
    const composer = document.querySelector('.chat-composer');
    if (!dialog || !composer) return;

    const textarea = composer.querySelector('textarea');
    const gifInput = composer.querySelector('[data-gif-input]');
    const search = dialog.querySelector('#chat-gif-search');
    const results = dialog.querySelector('[data-gif-results]');
    const status = dialog.querySelector('[data-gif-status]');
    const SEARCH_DELAY_MS = 350; // wait this long after the last key press, so typing "cats" is one search, not four
    let searchTimer = null;
    let searchRequest = null;
    let hasLoaded = false; // opening the picker again keeps the last search instead of asking Giphy again
    let isSending = false;

    function send(gifId) {
        if (isSending) return; // no double GIFs from a double click
        isSending = true;
        gifInput.value = gifId;
        dialog.close();
        composer.submit(); // not requestSubmit: the message box may be empty, which is fine with a GIF
    }

    function renderGifs(gifs) {
        results.replaceChildren(...gifs.map((gif) => {
            const image = document.createElement('img');
            image.src = gif.preview;
            image.alt = gif.title || 'GIF';
            if (gif.width > 0 && gif.height > 0) {
                // Room for it before it loads, so the grid doesn't jump around
                image.width = gif.width;
                image.height = gif.height;
            }
            image.loading = 'lazy';
            image.referrerPolicy = 'no-referrer';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'chat-gif-pick';
            button.append(image);
            button.addEventListener('click', () => send(gif.id));

            const item = document.createElement('li');
            item.append(button);
            return item;
        }));
        results.scrollTop = 0;
    }

    async function loadGifs() {
        clearTimeout(searchTimer);
        searchRequest?.abort();
        searchRequest = new AbortController();
        status.textContent = 'Searching…';

        try {
            const response = await fetch('/chat/gifs?q=' + encodeURIComponent(search.value.trim()), {
                signal: searchRequest.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(response.statusText);
            const gifs = (await response.json()).gifs;
            renderGifs(gifs);
            hasLoaded = true;
            status.textContent = gifs.length === 0 ? 'No GIFs found.' : '';
        } catch (error) {
            if (error.name === 'AbortError') return; // a newer search took over
            renderGifs([]);
            hasLoaded = false;
            status.textContent = "Couldn't load GIFs. Try again.";
        }
    }

    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadGifs, SEARCH_DELAY_MS);
    });

    // Enter searches right away instead of waiting
    search.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        loadGifs();
    });

    composer.querySelector('[data-gif-open]').addEventListener('click', () => {
        dialog.showModal();
        search.focus();
        search.select();
        if (!hasLoaded) loadGifs();
    });

    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    // Clicking the dimmed area around the popup closes it
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    dialog.addEventListener('close', () => {
        if (!isSending) textarea.focus();
    });
})();

// Older messages: a chat opens with only its newest messages, and while there are older ones the "Loading older messages..."
// line is at the top (data-messages-older). Scrolling up close to it asks the chat page for the ones before the oldest shown
// (?older=, answered as { messages: html, hasOlder }) and puts them in above, a batch at a time, until there are none left
// and the line goes. What's on screen stays where it is while they go in.
// Clicking a reply quote whose message isn't loaded yet sends chat:load-until (from the messages part above):
// then it's everything back to that message (?until=), and onDone is called once it's there (or can't be loaded).
(() => {
    const LOAD_WITHIN_PX = 300; // how close to the top scrolling has to get
    const messages = document.querySelector('[data-chat-messages]');
    if (!messages) return;
    let loading = null; // the request on its way, so only one is at a time

    // One batch above the oldest shown, or with untilId everything back to that message. Whether it got any.
    async function loadOlder(untilId = null) {
        const marker = messages.querySelector('[data-messages-older]');
        const oldest = messages.querySelector('[data-message-id], [data-notice-id]');
        if (!marker || !oldest) return false;

        try {
            const params = new URLSearchParams({
                chat: messages.dataset.chatMessages,
                older: oldest.dataset.messageId ?? oldest.dataset.noticeId,
            });
            if (untilId !== null) params.set('until', untilId);
            const response = await fetch('/chat/?' + params, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(response.statusText);
            const data = await response.json();

            // Kept the same distance from the bottom, so the messages being read don't jump down
            const fromBottom = messages.scrollHeight - messages.scrollTop;
            marker.insertAdjacentHTML('afterend', data.messages);
            if (!data.hasOlder) marker.remove();
            messages.scrollTop = messages.scrollHeight - fromBottom;
            return true;
        } catch {
            return false; // offline for a moment - scrolling or clicking again tries again
        }
    }

    // Still close to the top after a batch (a quick scroll, or a tall screen it didn't fill), so the next one too
    function loadIfNearTop() {
        if (loading || messages.scrollTop >= LOAD_WITHIN_PX) return;
        loading = loadOlder().then((isLoaded) => {
            loading = null;
            if (isLoaded) loadIfNearTop();
        });
    }

    messages.addEventListener('scroll', loadIfNearTop, { passive: true });
    loadIfNearTop();

    // Waits for a batch already on its way, and asks again while JUMP_MAX in chat/index.php wasn't enough
    messages.addEventListener('chat:load-until', async (event) => {
        const { messageId, onDone } = event.detail;
        while (!document.getElementById('message-' + messageId)) {
            if (loading) {
                await loading;
                continue;
            }
            loading = loadOlder(messageId);
            const isLoaded = await loading;
            loading = null;
            if (!isLoaded) break;
        }
        onDone();
    });
})();

// Live updates: every 2 seconds asks the chat page what's new (?poll=1), adds the open chat's new messages at the bottom
// and swaps in the chat list when something in it changed (new messages, unread counts, new chats).
// The open chat's background is kept up to date too, so a new one shows up without a reload.
// Message requests are swapped in the same way, and the requests button shows up when there are any.
// The number of unread chats is passed on to the red number on Chat in the side bar.
// Every poll also keeps the user's last seen time up to date, as session.php does that on every request.
// New messages are only marked read while the page is being looked at, and not at all after "Mark unread" (?unread=1).
(() => {
    const POLL_MS = 2000;
    const list = document.querySelector('.chat-list');
    const messages = document.querySelector('[data-chat-messages]');
    const chatMenu = document.querySelector('[data-chat-menu]');
    const requestsButton = document.querySelector('[data-requests-open]');
    const requestsCount = document.querySelector('[data-requests-count]');
    const requestsDialog = document.querySelector('.chat-requests');
    const requestsList = document.querySelector('[data-requests-list]');
    if (!list) return;

    const keepUnread = new URLSearchParams(location.search).get('unread') === '1';
    let shownChatList = null; // the chat list HTML last swapped in, so it's only swapped again when something changed
    let shownRequests = null; // the same for the message requests

    function messageItems() {
        return messages.querySelectorAll('[data-message-id]');
    }

    // Messages and notices (a new group name, someone kicked...): polling goes on after whichever is newest
    function shownItems() {
        return messages.querySelectorAll('[data-message-id], [data-notice-id]');
    }

    function itemId(item) {
        return item.dataset.messageId ?? item.dataset.noticeId;
    }

    function addMessages(html) {
        if (html.trim() === '') return;
        // Follows the new messages when already at the bottom, but doesn't pull away from older ones being read
        const isAtBottom = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 48;
        messages.querySelector('[data-messages-empty]')?.remove();
        messages.insertAdjacentHTML('beforeend', html);
        if (isAtBottom) messages.scrollTop = messages.scrollHeight;

        // In a chat that was empty there was no message to tab to yet, now the newest one is (only a notice may have come)
        const items = [...messageItems()];
        if (items.length > 0 && !items.some((item) => item.tabIndex === 0)) items.at(-1).tabIndex = 0;
    }

    function updateChatList(html) {
        if (html === shownChatList) return;
        // Not while the chat menu or a popup may be about a chat in the list - a later poll swaps it instead
        if (!chatMenu.hidden || document.querySelector('dialog[open]')) return;

        // Keyboard focus stays on the same chat
        const focusedChatId = list.contains(document.activeElement)
            ? document.activeElement.closest('[data-chat-id]')?.dataset.chatId ?? null
            : null;
        list.innerHTML = html;
        shownChatList = html;
        if (focusedChatId !== null) list.querySelector(`[data-chat-id="${focusedChatId}"] a`)?.focus();
        list.dispatchEvent(new Event('chat-list-update')); // the search filter runs again on the new list
    }

    function updateRequests(html, count) {
        if (html === shownRequests) return;
        // Not while the popup is open, so Accept and Decline don't move under the pointer - a later poll swaps it instead
        if (requestsDialog.open) return;

        requestsList.innerHTML = html;
        shownRequests = html;
        requestsButton.hidden = count === 0;
        requestsButton.setAttribute('aria-label', `Message requests (${count})`);
        requestsCount.textContent = count > 9 ? '9+' : String(count);
    }

    async function poll() {
        const params = new URLSearchParams({ poll: '1' });
        if (messages) {
            const items = shownItems();
            params.set('chat', messages.dataset.chatMessages);
            params.set('after', items.length > 0 ? itemId(items[items.length - 1]) : '0');
            if (document.visibilityState === 'visible' && !keepUnread) params.set('read', '1');
        }

        try {
            const response = await fetch('/chat/?' + params, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(response.statusText);
            const data = await response.json();

            if (messages && !data.openChat) {
                location.href = '/chat/'; // the open chat is gone: left, deleted, or removed from it
                return;
            }
            if (messages) {
                addMessages(data.messages);
                chatShowBackground(messages, data.background);
            }
            updateChatList(data.chatList);
            updateRequests(data.requests, data.requestCount);
            document.dispatchEvent(new CustomEvent('chat:unread', { detail: { unreadChats: data.unreadChats } })); // chatUnread.js
        } catch {
            // Offline for a moment, or logged out (the login page isn't JSON) - try again next time
        }

        // The next poll waits for this one, so a slow answer never has two on their way at once
        setTimeout(poll, POLL_MS);
    }

    setTimeout(poll, POLL_MS);
})();
