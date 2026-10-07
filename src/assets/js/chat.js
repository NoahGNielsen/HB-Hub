// Chat: filters the chat list while typing, starts the open chat at the newest message
// and sends on Enter (Shift+Enter for a new line).
// Without JS the search submits ?q= and the server filters the list instead.
(() => {
    const searchForm = document.querySelector('.chat-search');
    const search = document.querySelector('#chat-search');
    const items = document.querySelectorAll('.chat-list [data-chat-title]');
    const emptyHidden = document.querySelector('[data-empty-hidden]');
    const emptySearch = document.querySelector('[data-empty-search]');

    // Chats the user hid only show up when searching for them.
    // A DM with a nickname is found by the nickname and by the real username.
    function filterChats() {
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
        emptyHidden.hidden = items.length === 0 || query !== '' || shown > 0;
        emptySearch.hidden = items.length === 0 || query === '' || shown > 0;
    }

    if (search) {
        search.addEventListener('input', filterChats);
        searchForm.addEventListener('submit', (event) => event.preventDefault());
        filterChats();
    }

    // At the newest message, unless that would scroll the "New messages" line out of sight above
    const messages = document.querySelector('[data-chat-messages]');
    if (messages) {
        messages.scrollTop = messages.scrollHeight;
        const unread = messages.querySelector('[data-unread-divider]');
        const offset = unread ? unread.getBoundingClientRect().top - messages.getBoundingClientRect().top : 0;
        if (offset < 0) messages.scrollTop += offset - 8;
    }

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
(() => {
    const dialog = document.querySelector('.chat-requests');
    if (!dialog) return;

    document.querySelector('[data-requests-open]')?.addEventListener('click', () => dialog.showModal());

    dialog.querySelector('[data-requests-close]').addEventListener('click', () => dialog.close());

    // Clicking the dimmed area around the popup closes it
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
})();

// Right-click menus, used by the chat menu and the message menu.
// Right-clicking an item in `list` (or the menu key / Shift+F10 on it) opens `menu` where the pointer is.
// Arrow keys, Home and End move through the menu. Escape and Tab close it, back on the item it was opened on.
// onOpen(item) hides the menu items that don't apply, and skip(event, item) can leave the browser's own menu be.
function contextMenu({ menu, list, items, focusItem, onOpen, skip = () => false }) {
    let current = null; // the item the menu was opened on

    function menuItems() {
        return [...menu.querySelectorAll('[role="menuitem"]')].filter((item) => !item.hidden);
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

// Chat menu: right-click a chat in the list (or use the menu key / Shift+F10 on it) to hide or leave it.
// In a DM the other person can be given a nickname. Group admins can also change the group's name or icon, or delete it.
// Show profile (DMs) and Change background (group admins) are coming later.
// Every action is a plain form post - the server checks who's allowed to do what.
(() => {
    const menu = document.querySelector('[data-chat-menu]');
    const list = document.querySelector('.chat-list');
    if (!menu || !list) return;

    const hideForm = document.querySelector('[data-chat-hide]');
    const confirmDialog = document.querySelector('.chat-confirm');
    const nicknameDialog = document.querySelector('.chat-nickname');
    const renameDialog = document.querySelector('.chat-rename');
    const iconDialog = document.querySelector('.chat-icon-edit');
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
            // Nobody to give a nickname in a DM the other person left
            if (!item.hasAttribute('data-chat-username')) menu.querySelector('[data-menu-action="nickname"]').hidden = true;
        },
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

    const actions = {
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
                    + (chat.hasAttribute('data-chat-admin') ? " If you're the only admin, the longest-standing member takes over." : ''),
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
    };

    menu.addEventListener('click', (event) => {
        const button = event.target.closest('[data-menu-action]');
        if (!button || button.hasAttribute('aria-disabled')) return; // Show profile and Change background aren't ready yet
        chatMenu.close(false);
        actions[button.dataset.menuAction]();
    });

    [confirmDialog, nicknameDialog, renameDialog, iconDialog].forEach((dialog) => {
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

// Message menu: right-click a message in the open chat (or use the menu key / Shift+F10 on it) to reply to it,
// or mark the chat unread from it on. Your own messages can also be edited or deleted.
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
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let message = null; // the message the menu was opened on

    function messageItems() {
        return [...messages.querySelectorAll('[data-message-id]')];
    }

    // The message as it was sent - the line breaks are still in the text after each <br>
    function messageText(item) {
        return item.querySelector('[data-message-text]')?.textContent ?? '';
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
            menu.querySelector('[data-menu-action="reply"]').hidden = isDeleted;
            menu.querySelector('[data-menu-action="edit"]').hidden = !isOwn || !item.querySelector('[data-message-text]');
            menu.querySelectorAll('[data-menu-delete]').forEach((menuItem) => { menuItem.hidden = !isOwn; });
        },
    });

    // Reply: a bar above the composer says what's being answered, until it's sent or cancelled (× or Escape)
    function startReply(item) {
        replyInput.value = item.dataset.messageId;
        replyBar.querySelector('[data-reply-sender]').textContent =
            item.hasAttribute('data-message-own') ? 'yourself' : item.dataset.messageSender;
        replyBar.querySelector('[data-reply-preview]').textContent = messageText(item) || 'Attachment';
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
        editText.value = messageText(item);
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
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        event.preventDefault();
        if (!editSave.disabled) editForm.requestSubmit();
    });

    function openDelete(item) {
        deleteDialog.querySelector('form').elements.message.value = item.dataset.messageId;
        deleteDialog.querySelector('[data-delete-preview]').textContent = messageText(item) || 'Attachment';
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

    // The quote above a reply scrolls to the message it answers, which lights up for a moment
    messages.addEventListener('click', (event) => {
        const link = event.target.closest('[data-reply-link]');
        const target = link && document.getElementById(link.getAttribute('href').slice(1));
        if (!target) return;
        event.preventDefault();
        target.scrollIntoView({ block: 'center', behavior: reducedMotion.matches ? 'auto' : 'smooth' });
        focusMessage(target, { preventScroll: true });
        target.classList.add('is-highlighted');
        setTimeout(() => target.classList.remove('is-highlighted'), 1500);
    });
})();
