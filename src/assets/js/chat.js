// Chat: filters the chat list while typing, keeps the list where it was scrolled to when opening another chat,
// starts the open chat at the newest message
// and sends on Enter (Shift+Enter for a new line).
// Without JS the search submits ?q= and the server filters the list instead.
//
// The page is never loaded again while it's open: opening another chat, sending a message and the chat's other forms
// swap in a new <main> instead (Switching chats, at the bottom of this file). So everything that sets itself up on the
// page is a chatPart(setUp), and setUp(signal, isSwap) runs on the first load and again after every swap (isSwap true).
// signal is aborted just before a swap: whatever a part hangs on document or window, or keeps running (timers,
// workers), has to stop with it. Listeners on elements inside <main> go away with the old <main> by themselves.
const chatParts = [];
function chatPart(setUp) {
    chatParts.push(setUp);
}

chatPart((signal, isSwap) => {
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

    // A swap keeps the chat list where it was scrolled to (chatLoad), but leaving the page and coming back (a refresh,
    // or the chat in the side bar) would start it back at the top. So how far down it was is kept in sessionStorage
    // (this tab only) when leaving and put back here, and the open chat is scrolled into view if it's still out of sight.
    // Without sessionStorage (blocked, private mode) it's only the scrolling into view.
    const LIST_SCROLL_KEY = 'hbHubChatListScroll';
    if (list) {
        if (!isSwap) {
            try {
                list.scrollTop = Number(sessionStorage.getItem(LIST_SCROLL_KEY)) || 0;
            } catch {}
        }
        const active = list.querySelector('.chat-list-item.active');
        if (active) {
            // By hand, as scrollIntoView() could move the page around it too
            const listBox = list.getBoundingClientRect();
            const activeBox = active.getBoundingClientRect();
            if (activeBox.top < listBox.top) list.scrollTop += activeBox.top - listBox.top;
            else if (activeBox.bottom > listBox.bottom) list.scrollTop += activeBox.bottom - listBox.bottom;
        }
        addEventListener('pagehide', () => {
            try {
                sessionStorage.setItem(LIST_SCROLL_KEY, String(Math.round(list.scrollTop)));
            } catch {}
        }, { signal });
    }

    // At the newest message, unless that would scroll the "New messages" line out of sight above
    const messages = document.querySelector('[data-chat-messages]');
    if (messages) {
        chatShowBackground(messages, messages.dataset.background);
        chatGroupMessages(messages);
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
        // An attachment can be sent without any text (data-has-attachment is set by the attachments part below)
        if (textarea.value.trim() === '' && !composer.hasAttribute('data-has-attachment')) return;
        composer.requestSubmit();
    });

    // A blacklisted link stops it before anything is sent, the attachments part below included, as this listener
    // comes first. The text stays in the box, so the link can be taken out.
    composer.addEventListener('submit', (event) => {
        if (!chatBlockedLink(textarea.value)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
    });
});

// Formatting while typing a message (the composer and Edit), from the formatting menu below or Ctrl+B, Ctrl+I and Ctrl+U.
// The formatting itself is worked out by the server when the message is shown (assets/php/chatFormat.php),
// this only types the ** and so on for the user:
//   bold, italic, underline, strike, code: around the selected text, or around the cursor so it can be typed in between
//   codeBlock: ``` around the selected text, each on a line of its own
//   heading, list, numberedList: "# ", "- " or "1. ", "2. " in front of every selected line (the cursor's line without a selection)
// (Link has a popup of its own, chatLinkPopup below.)
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

// Link popup: the formatting menu's Link opens it with a Text and a Link box, and Done puts [text](address) in the
// message box (or Edit's) where the selection was - or just the address when Text is left empty.
// What was selected starts in Link when it's an address and in Text otherwise, and a selected [text](address) fills both.
// The address gets https:// in front when it has nothing there, and the characters a link in a message can't hold
// (spaces, brackets, < > and ") are %-encoded, so chatFormat.php still sees all of it.
// Returns { open(textarea) }, or null when the page has no popup.
function chatLinkPopup() {
    const dialog = document.querySelector('.chat-link-edit');
    if (!dialog) return null;

    const form = dialog.querySelector('form');
    const textInput = dialog.querySelector('[data-link-text]');
    const urlInput = dialog.querySelector('[data-link-url]');
    const error = dialog.querySelector('[data-link-error]');
    let textarea = null; // the message box it was opened on
    let start = 0; // the selection there, which Done replaces
    let end = 0;

    function showError(message, input) {
        error.textContent = message;
        error.hidden = message === '';
        input?.focus();
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        let address = urlInput.value.trim();
        if (address === '') return showError('Put in where the link goes.', urlInput);
        if (!/^https?:\/\//i.test(address)) {
            // mailto:, javascript: and the like aren't links in a message ("example.com:8080" is just missing https://)
            if (/^[a-z][a-z\d+.-]*:(?!\d)/i.test(address)) return showError('Links have to start with http:// or https://.', urlInput);
            address = 'https://' + address;
        }
        address = address.replace(/[\s<>"()]/g, (char) => '%' + char.charCodeAt(0).toString(16).toUpperCase().padStart(2, '0'));
        let host = '';
        try {
            host = new URL(address).hostname;
        } catch {
            // Not an address at all, caught just below
        }
        if (!/^https?:\/\/[^/?#]/i.test(address) || !host.includes('.')) return showError("That doesn't look like a link.", urlInput);

        // [ and ] would end the text early, and a \ at the end would hide the ] after it
        const label = textInput.value.replace(/\[/g, '(').replace(/]/g, ')').trim().replace(/\\+$/, '');
        const text = label === '' ? address : `[${label}](${address})`;
        if (chatBlockedLink(text)) return; // the popup stays open behind it, to put in another link
        if (textarea.maxLength > 0 && textarea.value.length - (end - start) + text.length > textarea.maxLength) {
            return showError("That's too long to fit in the message.", textInput);
        }

        dialog.close();
        textarea.setRangeText(text, start, end, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true })); // so it grows like when typing, and Edit's Save wakes up
    });

    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    // Clicking the dimmed area around the popup closes it
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    // Back in the message box, to keep typing
    dialog.addEventListener('close', () => textarea?.focus());

    return {
        open(target) {
            textarea = target;
            ({ selectionStart: start, selectionEnd: end } = target);
            // Spaces around the selection (double-clicking a word takes the space after it) stay where they are
            const raw = target.value.slice(start, end);
            if (raw.trim() !== '') {
                start += raw.length - raw.trimStart().length;
                end -= raw.length - raw.trimEnd().length;
            }
            const selected = raw.trim();
            const masked = selected.match(/^\[([^\[\]\n]+)\]\((\S+)\)$/);

            textInput.value = '';
            urlInput.value = '';
            if (masked) [, textInput.value, urlInput.value] = masked;
            else if (/^(https?:\/\/|www\.)\S+$/i.test(selected)) urlInput.value = selected;
            else textInput.value = selected.replace(/\s*\n\s*/g, ' ');
            showError('');

            dialog.showModal();
            // Straight to the box that's still empty
            (textInput.value !== '' && urlInput.value === '' ? urlInput : textInput).focus();
        },
    };
}

// Formatting menu: right-clicking the message box (or Edit's) opens a menu with every kind of formatting,
// which goes around the selected text (chatFormatApply above). The browser's own menu is replaced,
// so Cut, Copy and Paste are in it too. Shift+right-click still opens the browser's menu (for spelling suggestions),
// and phones keep theirs, as it comes with the handles for selecting text.
// Edit is a modal popup, where nothing outside it can be clicked, so the menu moves into the popup it's opened in,
// and back to where it started for the message box (inside .chat, whose [hidden] rule is what hides it again).
// Clicking anywhere, an item (even a greyed-out one), or Enter, Delete or Backspace closes it again.
chatPart((signal) => {
    const menu = document.querySelector('[data-format-menu]');
    if (!menu) return;

    const home = menu.parentElement; // where it is on the page, for the message box
    const isTouch = window.matchMedia('(hover: none)');
    const needsSelection = menu.querySelectorAll('[data-format="cut"], [data-format="copy"]');
    const linkPopup = chatLinkPopup();
    let textarea = null; // the message box the menu was opened on

    // Firefox only lets websites read the clipboard from version 125 on
    menu.querySelector('[data-format="paste"]').hidden = !navigator.clipboard?.readText;

    const formatMenu = contextMenu({
        menu,
        list: document.body,
        items: '[data-format-target]',
        focusItem: (item) => item.focus(), // and the selection is still there, for the next formatting
        skip: (event) => (event.shiftKey && event.clientX !== 0) || isTouch.matches, // clientX is 0 from Shift+F10
        signal,
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
            } else if (format === 'link') {
                linkPopup.open(textarea);
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
});

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
            img.dataset.queuedPicture = user.avatar; // pictureQueue.js loads it, two at a time
            img.alt = '';
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
chatPart(() => {
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
});

// Add to chat popup (groups): any member can add people, until the group has data-members-max members (invites counted).
// The ones added get the group as a message request, like when it was made, and everyone sees a notice.
chatPart(() => {
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
});

// Link warning popup: links in messages ask before they're opened.
// Without JS the links just open in a new tab.
chatPart(() => {
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
});

// The URL blacklist (assets/php/urlBlacklist.php): whether the text has a blacklisted domain (or a subdomain of one)
// anywhere in it, in which case the blocked link popup opens to say it can't be sent. Checked before sending, saving an
// edit and adding a link, so nothing is lost; the server checks again anyway. Works out the same as the server does,
// %-encoding, other full stops and invisible characters included, from the list in data-url-blacklist: every run of
// dotted names in the text is looked up in it, and so is each domain it's under (a.grabify.link -> grabify.link -> link).
let chatUrlBlacklist = null; // a Set, read from data-url-blacklist the first time it's needed
function chatBlockedLink(text) {
    const dialog = document.querySelector('.chat-link-blocked');
    chatUrlBlacklist ??= new Set(dialog ? JSON.parse(dialog.dataset.urlBlacklist || '[]') : []);
    if (chatUrlBlacklist.size === 0) return false;

    const plain = text
        .replace(/%([0-9a-f]{2})/gi, (match, hex) => String.fromCharCode(parseInt(hex, 16)))
        .replace(/[。．｡]/g, '.')
        .replace(/\p{Cf}+/gu, '');
    const names = plain.match(/(?<![\p{L}\p{N}_.-])[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)+/gu) || [];
    const blocked = names.some((name) => {
        const labels = name.toLowerCase().split('.');
        return labels.slice(0, -1).some((label, i) => chatUrlBlacklist.has(labels.slice(i).join('.')));
    });
    if (!blocked) return false;

    dialog.showModal();
    dialog.querySelector('.chat-new-btn[data-dialog-close]').focus();
    return true;
}

// Blocked link popup: Okay, × or the dimmed area closes it. It opens by itself when the server stopped a message
// (?linkBlocked=1), which is then taken out of the address so a refresh doesn't open it again.
chatPart(() => {
    const dialog = document.querySelector('.chat-link-blocked');
    if (!dialog) return;

    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    if (dialog.hasAttribute('data-link-blocked-open')) {
        const url = new URL(location.href);
        url.searchParams.delete('linkBlocked');
        history.replaceState(null, '', url);
        dialog.showModal();
    }
});

// Slow down popup: only on the page when the server didn't send a message because the user sent too many too fast
// (?tooFast=1). It opens straight away, and ?tooFast=1 is taken out of the address so a refresh doesn't open it again.
chatPart(() => {
    const dialog = document.querySelector('.chat-too-fast');
    if (!dialog) return;

    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    const url = new URL(location.href);
    url.searchParams.delete('tooFast');
    history.replaceState(null, '', url);
    dialog.showModal();
});

// Nickname not saved popup: only on the page when the server didn't give a nickname because of what's in it
// (?nicknameRefused=latin or =link). It opens straight away, and the ?nicknameRefused= is taken out of the address
// so a refresh doesn't open it again.
chatPart(() => {
    const dialog = document.querySelector('.chat-nickname-refused');
    if (!dialog) return;

    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    const url = new URL(location.href);
    url.searchParams.delete('nicknameRefused');
    history.replaceState(null, '', url);
    dialog.showModal();
});

// Message requests popup: chats the user was added to but hasn't accepted yet.
// Accept and Decline are plain form posts, the server does the rest.
// A message request notification links to /chat/?requests=1, which opens it straight away (when there still are any).
chatPart(() => {
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
});

// Members popup: the person button in a group's header lists everyone in it.
// Group admins can make a member an admin or kick them, and the group owner can remove an admin,
// all asked about first in a popup on top of it.
// Everyone can give anyone a nickname, in the Nickname popup on top of it.
chatPart(() => {
    const dialog = document.querySelector('.chat-members');
    if (!dialog) return;

    const confirmDialog = document.querySelector('.chat-member-confirm');
    const nicknameDialog = document.querySelector('.chat-member-nickname');
    let memberButton = null; // the Nickname, Make admin, Remove admin or Kick button that opened the popup on top

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
                `${name} will be able to rename the group, change its icon and background, delete messages, unpin anyone's pins, make others admin, kick members and delete the group.`;
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

    // Filled in with who it's for and the nickname they have now, which can be changed or emptied to take it away
    function openNickname(button) {
        const userName = button.dataset.memberUsername;
        const form = nicknameDialog.querySelector('form');
        memberButton = button;
        form.elements.member.value = button.dataset.memberId;
        form.elements.nickname.value = button.dataset.memberNickname;
        nicknameDialog.querySelector('[data-member-nickname-label]').textContent = `Nickname for ${userName}`;
        nicknameDialog.querySelector('[data-member-nickname-hint]').textContent =
            `Everyone in the group sees it instead of their username. Leave it empty to go back to ${userName}.`;
        nicknameDialog.showModal();
        form.elements.nickname.focus();
        form.elements.nickname.select();
    }

    dialog.addEventListener('click', (event) => {
        const nicknameButton = event.target.closest('[data-member-nickname-open]');
        if (nicknameButton) openNickname(nicknameButton);
        const button = event.target.closest('[data-member-action]');
        if (button) confirmMember(button);
    });

    [dialog, confirmDialog, nicknameDialog].forEach((popup) => {
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
    nicknameDialog.addEventListener('close', () => memberButton?.focus());
});

// Member menu, for site admins: right-click someone in the Members popup (or use the menu key / Shift+F10 on them)
// for Admin panel, which opens their admin panel (admin/user.php).
chatPart((signal) => {
    const dialog = document.querySelector('.chat-members');
    const menu = document.querySelector('[data-member-menu]');
    if (!dialog || !menu) return;

    const adminLink = menu.querySelector('[data-menu-site-admin]');
    const memberMenu = contextMenu({
        menu,
        list: dialog.querySelector('.chat-members-list'),
        items: '[data-member-user-id]',
        focusItem: (item) => item.querySelector('a, button')?.focus(),
        signal,
        onOpen(item) {
            adminLink.href = '/admin/user?user=' + item.dataset.memberUserId;
        },
    });

    dialog.addEventListener('close', () => memberMenu.close(false));
});

// Right-click menus, used by the chat menu and the message menu.
// Right-clicking an item in `list` (or the menu key / Shift+F10 on it) opens `menu` where the pointer is.
// Arrow keys, Home and End move through the menu. Escape and Tab close it, back on the item it was opened on.
// onOpen(item) hides the menu items that don't apply, and skip(event, item) can leave the browser's own menu be.
// A smaller menu inside it (like Mute chat's) handles its own keys.
// Items side by side in a row (data-menu-row, like the message menu's reactions) are one stop for the up and down keys,
// and the left and right keys move along the row.
// signal is the part's (chatPart at the top), which takes the listeners on document and window away again.
function contextMenu({ menu, list, items, focusItem, onOpen, skip = () => false, signal }) {
    let current = null; // the item the menu was opened on

    function menuItems() {
        return [...menu.querySelectorAll(':scope > [role="menuitem"], :scope > [data-menu-row]:not([hidden]) > [role="menuitem"]')]
            .filter((item) => !item.hidden);
    }

    // The first item of the row a menu item is in, or the item itself when it isn't in one
    function stopOf(menuItem, menuItemList) {
        const row = menuItem?.closest('[data-menu-row]');
        return row ? menuItemList.find((other) => row.contains(other)) : menuItem;
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
    }, { signal });
    list.addEventListener('scroll', () => close(false));
    window.addEventListener('resize', () => close(false), { signal });
    window.addEventListener('blur', () => close(false), { signal });

    menu.addEventListener('keydown', (event) => {
        const menuItemList = menuItems();
        const row = document.activeElement.closest('[data-menu-row]');
        if (row && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
            event.preventDefault();
            const rowItems = menuItemList.filter((menuItem) => row.contains(menuItem));
            const index = rowItems.indexOf(document.activeElement) + (event.key === 'ArrowRight' ? 1 : -1);
            rowItems[(index + rowItems.length) % rowItems.length].focus();
            return;
        }

        const stops = menuItemList.filter((menuItem) => stopOf(menuItem, menuItemList) === menuItem);
        const index = stops.indexOf(stopOf(document.activeElement, menuItemList));
        const moves = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: stops.length - 1 };
        if (event.key in moves) {
            event.preventDefault();
            stops[(moves[event.key] + stops.length) % stops.length].focus();
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

// Messages in a row from the same person, each sent within 10 minutes of the one before it, go together as a group:
// the ones after the first are is-grouped (closer together, without the sender's name again), and the ones before the
// last are has-next (their time only shows when pointed at, chat.css). A notice, the "New messages" line or an attachment
// still being sent in between ends a group. Run again whenever messages go in, before scrolling, as it changes heights.
function chatGroupMessages(messages) {
    const GROUP_SECONDS = 10 * 60;
    let previous = null; // the message right above, or null when something else is
    for (const item of messages.children) {
        const isMessage = item.hasAttribute('data-message-id');
        const isGrouped = isMessage && previous !== null
            && item.dataset.messageUserId === previous.dataset.messageUserId
            && item.dataset.messageTime - previous.dataset.messageTime <= GROUP_SECONDS;
        if (isMessage) item.classList.toggle('is-grouped', isGrouped);
        previous?.classList.toggle('has-next', isGrouped);
        previous = isMessage ? item : null;
    }
    previous?.classList.remove('has-next');
}

// Chat menu: right-click a chat in the list (or use the menu key / Shift+F10 on it) to see some info about it (Chat info,
// from chat/info.php), or to mute, hide or leave it (not a class chat, data-chat-class).
// In a DM the other person's profile can be opened (otherPages/profile.php) and they can be given a nickname.
// Group admins can also change the group's name or icon, or delete it.
// Group admins and both people in a DM can change the chat's background.
// Site admins also get Admin panel, which opens the other person's admin panel on a DM (admin/user.php)
// and the chat's own on a group, or on a DM the other person left (admin/chat.php).
// Every action is a plain form post - the server checks who's allowed to do what.
chatPart((signal) => {
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
    const infoDialog = document.querySelector('.chat-info');
    let chat = null; // the list item the menu was opened on

    const chatMenu = contextMenu({
        menu,
        list,
        items: '[data-chat-id]',
        focusItem: (item) => item.querySelector('a').focus(),
        signal,
        onOpen(item) {
            chat = item;
            const isGroup = item.dataset.chatType === 'group';
            const isAdmin = item.hasAttribute('data-chat-admin');
            menu.querySelectorAll('[data-menu-dm]').forEach((menuItem) => { menuItem.hidden = isGroup; });
            menu.querySelectorAll('[data-menu-admin]').forEach((menuItem) => { menuItem.hidden = !isAdmin; });
            menu.querySelector('[data-menu-action="background"]').hidden = isGroup && !isAdmin;
            // Everyone at HTX is in their class chat, so it can be hidden but not left
            menu.querySelector('[data-menu-action="leave"]').hidden = item.hasAttribute('data-chat-class');
            // Nobody to give a nickname in a DM the other person left
            if (!item.hasAttribute('data-chat-username')) menu.querySelector('[data-menu-action="nickname"]').hidden = true;
            const isMuted = item.hasAttribute('data-chat-muted');
            muteItem.hidden = isMuted;
            menu.querySelector('[data-menu-action="unmute"]').hidden = !isMuted;
            // Nobody's profile to open in a group, or a DM the other person left. Their admin panel is the chat's own then.
            const dmUserId = isGroup ? undefined : item.dataset.chatUserId;
            profileItem.hidden = dmUserId === undefined;
            if (dmUserId !== undefined) profileItem.href = '/profile?user=' + dmUserId;
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
        chatSubmit(muteForm);
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

    // Chat info: what kind of chat it is, when it was made and by who, how many are in it and how many messages it has,
    // and when the user joined (and their role, in a group). Asked for each time, as the counts change.
    const infoTitle = infoDialog.querySelector('[data-info-title]');
    const infoStatus = infoDialog.querySelector('[data-info-status]');
    const infoList = infoDialog.querySelector('[data-info-list]');
    const infoTypes = { dm: 'Direct message', group: 'Group', class: 'Class chat' };
    const infoRoles = { owner: 'Owner', admin: 'Admin', member: 'Member' };
    let infoRequest = null;

    async function openInfo() {
        infoRequest?.abort();
        infoRequest = new AbortController();
        infoTitle.textContent = chat.dataset.chatTitle;
        infoStatus.textContent = 'Loading…';
        infoStatus.hidden = false;
        infoList.hidden = true;
        infoDialog.showModal();

        let info;
        try {
            const response = await fetch('/chat/info?chat=' + chat.dataset.chatId, {
                signal: infoRequest.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(response.statusText);
            info = await response.json();
        } catch (error) {
            if (error.name === 'AbortError') return; // closed before it came back
            infoStatus.textContent = "Couldn't load the chat's info. Try again.";
            return;
        }

        const rows = [
            ['Type', infoTypes[info.type]],
            ['Created', `${info.created} by ${info.createdBy}`],
            ['Members', info.members.toLocaleString()],
            ['Messages', info.messages.toLocaleString()],
            ['You joined', info.joined],
        ];
        if (info.role !== null) rows.push(['Your role', infoRoles[info.role]]);
        infoList.replaceChildren(...rows.flatMap(([label, value]) => {
            const term = document.createElement('dt');
            term.textContent = label;
            const detail = document.createElement('dd');
            detail.textContent = value;
            return [term, detail];
        }));
        infoStatus.hidden = true;
        infoList.hidden = false;
    }

    infoDialog.addEventListener('close', () => infoRequest?.abort());

    const actions = {
        unmute() {
            muteForm.elements.action.value = 'unmute';
            muteForm.elements.chat.value = chat.dataset.chatId;
            chatSubmit(muteForm);
        },
        hide() {
            hideForm.elements.chat.value = chat.dataset.chatId;
            chatSubmit(hideForm);
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
        info: openInfo,
    };

    menu.addEventListener('click', (event) => {
        const button = event.target.closest('[data-menu-action]');
        if (!button) return;
        chatMenu.close(false);
        actions[button.dataset.menuAction]();
    });

    [confirmDialog, nicknameDialog, renameDialog, iconDialog, backgroundDialog, infoDialog].forEach((dialog) => {
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
});

// Group admins can change the group's name right in the open chat's header: clicking the name turns it into a text box.
// Enter saves it (a plain form post, like Change name in the chat menu), Escape or clicking away puts the name back.
// Left empty, the group is named after its members again.
chatPart(() => {
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
});

// Message menu: right-click a message in the open chat (or use the menu key / Shift+F10 on it) to react to it, reply to
// it, pin it, or mark the chat unread from it on. Your own messages can also be edited or deleted,
// and group admins can delete anyone's (shown as deleted by a group admin).
// Pinned messages get Unpin message instead, when the Pinned messages popup's list says the user can (data-pin-unpinnable:
// their own pin, a group admin, or a DM). Pinning isn't there while muted, like reactions.
// Along the top are the emoji used most recently (chatQuickReactions()) and + for all of them, which the reactions part
// below handles (chat:react and chat:react-more). An emoji the user already reacted with is lit up, and takes it back.
// Site admins also get Admin panel on other people's messages, which opens the sender's admin panel (admin/user.php).
// Links and selected text keep the browser's own menu, so they can still be copied.
// The arrow keys, Home and End move between the messages, so the menu can be reached without a mouse.
chatPart((signal) => {
    const menu = document.querySelector('[data-message-menu]');
    const messages = document.querySelector('[data-chat-messages]');
    const composer = document.querySelector('.chat-composer');
    if (!menu || !messages || !composer) return;

    const textarea = composer.querySelector('textarea');
    const replyInput = composer.querySelector('[data-reply-input]');
    const replyBar = composer.querySelector('[data-reply-bar]');
    const unreadForm = document.querySelector('[data-message-unread]');
    const pinForm = document.querySelector('[data-message-pin]');
    const pinsList = document.querySelector('[data-pins-list]');
    const editDialog = document.querySelector('.chat-message-edit');
    const editForm = editDialog.querySelector('form');
    const editText = editForm.elements.content;
    const editSave = editDialog.querySelector('[data-edit-save]');
    const deleteDialog = document.querySelector('.chat-message-delete');
    const isGroupAdmin = messages.hasAttribute('data-group-admin'); // can delete other members' messages too
    const isMuted = messages.hasAttribute('data-muted'); // can't react, like they can't send
    const siteAdminItems = menu.querySelectorAll('[data-menu-site-admin]'); // only on the page for site admins
    const reactionRow = menu.querySelector('[data-menu-reactions].chat-menu-reactions');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let message = null; // the message the menu was opened on

    // The reaction row, made again on every open, as the recently used emoji and the message's reactions change
    function fillReactions(item) {
        const mine = chatOwnReactions(item);
        const reactItems = chatQuickReactions().map((emoji) => {
            const reactItem = document.createElement('button');
            reactItem.type = 'button';
            reactItem.setAttribute('role', 'menuitem');
            reactItem.dataset.react = emoji;
            reactItem.textContent = emoji;
            reactItem.classList.toggle('is-mine', mine.has(emoji));
            reactItem.title = mine.has(emoji) ? `Take back your ${emoji}` : `React with ${emoji}`;
            reactItem.setAttribute('aria-label', reactItem.title);
            return reactItem;
        });
        const moreItem = document.createElement('button');
        moreItem.type = 'button';
        moreItem.className = 'chat-menu-reactions-more';
        moreItem.setAttribute('role', 'menuitem');
        moreItem.setAttribute('aria-haspopup', 'dialog');
        moreItem.dataset.reactMore = '';
        moreItem.textContent = '+';
        moreItem.title = 'More reactions';
        moreItem.setAttribute('aria-label', moreItem.title);
        reactionRow.replaceChildren(...reactItems, moreItem);
    }

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

    // What the reply bar and the delete popup show for the message: its text, or else what it sends
    function messagePreview(item) {
        return messageText(item) || (item.hasAttribute('data-message-gif') ? 'GIF' : item.dataset.messageAttachment ?? 'Attachment');
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
        signal,
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
            const canReact = !isDeleted && !isMuted;
            menu.querySelectorAll('[data-menu-reactions]').forEach((menuItem) => { menuItem.hidden = !canReact; });
            if (canReact) fillReactions(item);
            menu.querySelector('[data-menu-action="reply"]').hidden = isDeleted;
            menu.querySelector('[data-menu-action="edit"]').hidden = !isOwn || !item.querySelector('[data-message-text]');
            const isPinned = item.hasAttribute('data-message-pinned');
            const pin = pinsList.querySelector(`[data-pin-id="${item.dataset.messageId}"]`);
            menu.querySelector('[data-menu-action="pin"]').hidden = !canReact || isPinned;
            menu.querySelector('[data-menu-action="unpin"]').hidden = isDeleted || !isPinned || !pin?.hasAttribute('data-pin-unpinnable');
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

    // A blacklisted link isn't saved, and Edit stays open behind the blocked link popup to take it out
    editForm.addEventListener('submit', (event) => {
        if (chatBlockedLink(editText.value)) event.preventDefault();
    });

    // A group admin deleting someone else's message is told it'll say so. Attachments in it are deleted for good too.
    function openDelete(item) {
        const attachments = Number(item.dataset.messageAttachments ?? 0);
        const its = attachments > 1 ? 'its attachments are' : 'its attachment is';
        deleteDialog.querySelector('form').elements.message.value = item.dataset.messageId;
        deleteDialog.querySelector('[data-delete-text]').textContent = item.hasAttribute('data-message-own')
            ? `Everyone in the chat will see it as deleted${attachments > 0 ? `, and ${its} deleted, which gives you ${attachments > 1 ? 'their' : 'its'} tickets back` : ''}. This can't be undone.`
            : `Everyone in the chat will see ${item.dataset.messageSender}'s message as deleted by a group admin${attachments > 0 ? `, and ${its} deleted` : ''}. This can't be undone.`;
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
            chatSubmit(unreadForm);
        },
        pin(item) {
            pinForm.elements.action.value = 'pin';
            pinForm.elements.message.value = item.dataset.messageId;
            chatSubmit(pinForm);
        },
        unpin(item) {
            pinForm.elements.action.value = 'unpin';
            pinForm.elements.message.value = item.dataset.messageId;
            chatSubmit(pinForm);
        },
    };

    menu.addEventListener('click', (event) => {
        const reactItem = event.target.closest('[data-react]');
        if (reactItem) {
            messageMenu.close(true);
            messages.dispatchEvent(new CustomEvent('chat:react', { detail: { item: message, emoji: reactItem.dataset.react } }));
            return;
        }
        if (event.target.closest('[data-react-more]')) {
            // The picker opens where the menu was, so it's measured before the menu is hidden
            const { left, top } = menu.getBoundingClientRect();
            messageMenu.close(false);
            messages.dispatchEvent(new CustomEvent('chat:react-more', { detail: { item: message, x: left, y: top } }));
            return;
        }

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

    function jumpToId(messageId) {
        const targetId = 'message-' + messageId;
        const target = document.getElementById(targetId);
        if (target) {
            jumpTo(target);
            return;
        }
        messages.dispatchEvent(new CustomEvent('chat:load-until', {
            detail: {
                messageId,
                onDone() {
                    const loaded = document.getElementById(targetId);
                    if (loaded) jumpTo(loaded);
                },
            },
        }));
    }

    // Pin and unpin notices link to their message the same way
    messages.addEventListener('click', (event) => {
        const link = event.target.closest('[data-reply-link]');
        if (!link) return;
        event.preventDefault();
        jumpToId(link.dataset.replyId);
    });

    // Jump in the Pinned messages popup (the pins part below)
    messages.addEventListener('chat:jump', (event) => jumpToId(event.detail.messageId));

    // A mention notification links to its message (#message-id), which is jumped to the same way once the page is up.
    // After the rest of this file has run, as the part that loads older messages comes further down.
    const linked = /^#message-(\d+)$/.exec(location.hash);
    if (linked) setTimeout(() => jumpToId(linked[1]));
});

// Pinned messages popup: the pin in the chat's header lists the messages pinned in the open chat, the newest pin first
// (Pin message in the message menu above pins one). Jump closes it and scrolls to the message, loading back to it when
// it isn't on the page yet (chat:jump, the message menu part). Unpin is a plain form post that opens it again (?pins=1),
// and a pin that didn't fit opens it with ?pins=full, which shows the line saying why.
// Polling swaps in the list when it changed (chat:pins-update), and the messages shown are marked pinned or not from it
// (data-message-pinned, chat.css). The ones that come in later are marked by the server already.
chatPart(() => {
    const dialog = document.querySelector('.chat-pins');
    const messages = document.querySelector('[data-chat-messages]');
    if (!dialog || !messages) return;

    const openButton = document.querySelector('[data-pins-open]');
    const list = dialog.querySelector('[data-pins-list]');
    const count = dialog.querySelector('[data-pins-count]');
    const empty = dialog.querySelector('[data-pins-empty]');
    const full = dialog.querySelector('[data-pins-full]');

    // The count, the empty line and the marks on the messages, from the list
    function sync() {
        const pinnedIds = new Set([...list.querySelectorAll('[data-pin-id]')].map((pin) => pin.dataset.pinId));
        count.textContent = String(pinnedIds.size);
        empty.hidden = pinnedIds.size > 0;
        openButton.setAttribute('aria-label', `Pinned messages (${pinnedIds.size})`);
        messages.querySelectorAll('[data-message-id]').forEach((item) => {
            item.toggleAttribute('data-message-pinned', pinnedIds.has(item.dataset.messageId));
        });
    }

    list.addEventListener('chat:pins-update', sync);

    openButton.addEventListener('click', () => {
        full.hidden = true;
        dialog.showModal();
    });

    const params = new URLSearchParams(location.search);
    const opened = params.get('pins');
    if (opened !== null) {
        params.delete('pins'); // so a refresh doesn't open it again
        history.replaceState(null, '', location.pathname + (params.size > 0 ? '?' + params : ''));
        full.hidden = opened !== 'full';
        dialog.showModal();
    }

    dialog.addEventListener('click', (event) => {
        // Clicking the dimmed area around the popup closes it
        if (event.target === dialog) {
            dialog.close();
            return;
        }
        const jump = event.target.closest('[data-pin-jump]');
        if (!jump) return;
        event.preventDefault(); // not the link to the chat page itself (Switching chats, at the bottom of this file)
        dialog.close();
        messages.dispatchEvent(new CustomEvent('chat:jump', { detail: { messageId: jump.dataset.pinJump } }));
    });

    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });
});

// Attach popup: the button left of the message box opens a small menu just above it.
// File and Your attachments are handled by the attachments part below. Greyed-out items (aria-disabled, like Voice
// message, which is coming soon) do nothing.
chatPart((signal) => {
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
    }, { signal });

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
});

// A JPEG XL worker (chatJxlWorker.js, its address is data-jxl-worker on the page), started the first time it's used.
// The composer and the messages each have their own, so cancelling a conversion never stops a picture being drawn.
function chatJxlWorker() {
    return chatWorker('data-jxl-worker');
}

// A worker whose address is in the page's attribute named attribute (like 'data-jxl-worker'), started the first
// time it's used. run(type, data, transfer, onProgress) sends it a job and gives back a promise of its
// answer, and onProgress gets the worker's { progress } messages on the way. cancel() stops the worker and everything
// it was doing (those promises fail with an AbortError), and the next job starts a new one.
function chatWorker(attribute) {
    let worker = null;
    let nextId = 0;
    const jobs = new Map(); // id -> { resolve, reject, onProgress }

    function stop(error) {
        worker?.terminate();
        worker = null;
        jobs.forEach((job) => job.reject(error));
        jobs.clear();
    }

    function start() {
        worker = new Worker(document.querySelector(`[${attribute}]`).getAttribute(attribute), { type: 'module' });
        worker.addEventListener('message', ({ data }) => {
            const job = jobs.get(data.id);
            if (!job) return;
            if ('progress' in data) {
                job.onProgress?.(data.progress);
                return;
            }
            jobs.delete(data.id);
            if (data.error) job.reject(new Error(data.error));
            else job.resolve(data);
        });
        // The worker itself couldn't load (a browser without module workers, say)
        worker.addEventListener('error', () => stop(new Error('workerFailed')));
    }

    return {
        run(type, data, transfer = [], onProgress = null) {
            if (!worker) start();
            const id = ++nextId;
            return new Promise((resolve, reject) => {
                jobs.set(id, { resolve, reject, onProgress });
                worker.postMessage({ id, type, ...data }, transfer);
            });
        },
        cancel() {
            stop(new DOMException('Cancelled', 'AbortError'));
        },
    };
}

// JPEG XL pictures in the chat: not every browser can show them yet. When one can't, the <img> fails to load
// (data-attachment-jxl is on those), so the file is fetched and drawn into a <canvas> in its place by the JPEG XL
// worker instead. Both are 200px tall in chat.css, so nothing moves when the canvas takes over.
chatPart((signal) => {
    const messages = document.querySelector('[data-chat-messages]');
    if (!messages) return;
    const jxl = chatJxlWorker();
    signal.addEventListener('abort', () => jxl.cancel()); // stops the worker, if it was started

    async function draw(image) {
        if (image.dataset.jxlDrawing) return; // only tried once
        image.dataset.jxlDrawing = '1';
        try {
            const response = await fetch(image.src);
            if (!response.ok) return; // gone (deleted or expired since the page loaded), so it stays a broken image
            const buffer = await response.arrayBuffer();
            const { pixels, width, height } = await jxl.run('decode', { buffer }, [buffer]);

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            canvas.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(pixels), width, height), 0, 0);
            canvas.className = image.className;
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', image.alt);
            image.replaceWith(canvas);
        } catch {
            // Couldn't draw it either - it stays as it is
        }
    }

    // Error events don't bubble, so they're caught on the way down. New messages from polling are covered too.
    messages.addEventListener('error', (event) => {
        if (event.target instanceof HTMLImageElement && event.target.hasAttribute('data-attachment-jxl')) draw(event.target);
    }, true);

    // Any that failed before this script ran
    messages.querySelectorAll('img[data-attachment-jxl]').forEach((image) => {
        if (image.complete && image.naturalWidth === 0) draw(image);
    });
});

// Videos in the chat: when the browser can't play one (a codec it doesn't know, like an iPhone's HEVC in some browsers,
// or the file is gone since the page loaded), its figure gets is-unplayable, which hides the broken player in chat.css
// and leaves its name, which downloads it.
chatPart(() => {
    const messages = document.querySelector('[data-chat-messages]');
    if (!messages) return;
    const unplayable = (video) => video.closest('.chat-attachment')?.classList.add('is-unplayable');

    // Error events don't bubble, so they're caught on the way down, like the JPEG XL ones above
    messages.addEventListener('error', (event) => {
        if (event.target instanceof HTMLVideoElement && event.target.classList.contains('chat-attachment-video')) unplayable(event.target);
    }, true);
    messages.querySelectorAll('video.chat-attachment-video').forEach((video) => {
        if (video.error) unplayable(video);
    });
});

// Picture viewer: clicking a picture in the open chat shows it big in a popup (.chat-viewer in chat/index.php), with zoom,
// Info, Download and × along the top. Clicking anywhere that isn't the picture, a button or the Info panel closes it,
// and so does Escape. A message with several pictures (a gallery) gets arrows on each side to go through them, and the
// arrow keys do the same while it isn't zoomed in.
// Zoom goes in steps of the size that fits the screen: − and +, the - + and 0 keys, Ctrl + the mouse wheel (or pinching
// a trackpad), and double-clicking the picture to go in and back out. Zoomed in, the picture is dragged around with the
// mouse, or scrolled. Info lists its name, type, size in pixels and MB, who sent it, when, and when it expires, and
// stays open or closed from one picture to the next.
// The picture is the one in the message: an <img> comes from the browser's cache, and a JPEG XL one drawn into a <canvas>
// (the JPEG XL part above) is copied. The faded message still being sent has no data-message-id, so it doesn't open.
chatPart((signal) => {
    const messages = document.querySelector('[data-chat-messages]');
    const dialog = document.querySelector('.chat-viewer');
    if (!messages || !dialog) return;

    const STEPS = [1, 1.5, 2, 3, 4, 6, 8]; // times the size that fits the screen
    const DOUBLE_CLICK_STEP = 2; // where double-clicking zooms to
    const WHEEL_EVERY_MS = 120; // a trackpad pinch sends lots of wheel events, so only one step this often
    const TYPES = {
        'image/png': 'PNG', 'image/jpeg': 'JPEG', 'image/gif': 'GIF', 'image/webp': 'WebP',
        'image/avif': 'AVIF', 'image/bmp': 'BMP', 'image/jxl': 'JPEG XL',
    };
    const name = dialog.querySelector('[data-viewer-name]');
    const stage = dialog.querySelector('[data-viewer-stage]');
    const info = dialog.querySelector('[data-viewer-info]');
    const infoButton = dialog.querySelector('[data-viewer-info-open]');
    const download = dialog.querySelector('[data-viewer-download]');
    const zoomOutButton = dialog.querySelector('[data-viewer-zoom-out]');
    const zoomInButton = dialog.querySelector('[data-viewer-zoom-in]');
    const zoomLevel = dialog.querySelector('[data-viewer-zoom-level]');
    const count = dialog.querySelector('[data-viewer-count]');
    const prevButton = dialog.querySelector('[data-viewer-prev]');
    const nextButton = dialog.querySelector('[data-viewer-next]');

    let shown = null; // what's open: { figure, item } from the message
    let picture = null; // the <img> or <canvas> in the viewer
    let size = null; // its real size in pixels, { width, height }, once it's loaded
    let step = 0;
    let drag = null; // dragging the picture around: { x, y, left, top } where it started, active, and whether it moved
    let isInfoOpen = false;
    let lastWheel = 0;

    // dd/mm/yyyy • hh.mm, like the times on the messages
    function dateTime(seconds) {
        const date = new Date(seconds * 1000);
        const pad = (number) => String(number).padStart(2, '0');
        return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} • ${pad(date.getHours())}.${pad(date.getMinutes())}`;
    }

    function renderInfo() {
        const type = shown.figure.dataset.attachmentType;
        const rows = [
            ['Name', name.textContent],
            ['Type', TYPES[type] ? `${TYPES[type]} (${type})` : type],
            ['Dimensions', size ? `${size.width} × ${size.height} px` : '…'],
            ['File size', shown.figure.dataset.attachmentSize],
            ['Sent by', shown.item.dataset.messageSender],
            ['Sent', dateTime(Number(shown.item.dataset.messageTime))],
            ['Expires', dateTime(Number(shown.figure.dataset.attachmentExpires))],
        ];
        info.replaceChildren(...rows.flatMap(([term, value]) => {
            const dt = document.createElement('dt');
            dt.textContent = term;
            const dd = document.createElement('dd');
            dd.textContent = value;
            return [dt, dd];
        }));
        info.hidden = !isInfoOpen;
        infoButton.setAttribute('aria-expanded', String(isInfoOpen));
    }

    // How much the picture is shrunk to fit the screen at the first step. It's never made bigger than it really is there.
    function fitScale() {
        const style = getComputedStyle(stage);
        const width = stage.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        const height = stage.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);
        return Math.max(0.01, Math.min(1, width / size.width, height / size.height));
    }

    /**
     * Sizes the picture for the zoom step. The spot on the picture at the pointer (at: an event with clientX/clientY),
     * or in the middle of the screen without one, stays where it is on the screen.
     */
    function render(at = null) {
        zoomOutButton.disabled = !size || step === 0;
        zoomInButton.disabled = !size || step === STEPS.length - 1;
        dialog.classList.toggle('is-zoomed', step > 0);
        if (!size) {
            zoomLevel.textContent = '';
            return;
        }

        const box = stage.getBoundingClientRect();
        const screenX = at ? at.clientX - box.left : stage.clientWidth / 2;
        const screenY = at ? at.clientY - box.top : stage.clientHeight / 2;
        // Where that is on the picture, as a fraction of its width and height (stage is position: relative in chat.css)
        const spot = (scroll, screen, offset, length) => length > 0 ? Math.min(1, Math.max(0, (scroll + screen - offset) / length)) : 0.5;
        const spotX = spot(stage.scrollLeft, screenX, picture.offsetLeft, picture.offsetWidth);
        const spotY = spot(stage.scrollTop, screenY, picture.offsetTop, picture.offsetHeight);

        const scale = fitScale() * STEPS[step];
        picture.style.width = `${size.width * scale}px`;
        picture.style.height = `${size.height * scale}px`;
        zoomLevel.textContent = `${Math.round(scale * 100)}%`;
        stage.scrollLeft = picture.offsetLeft + spotX * picture.offsetWidth - screenX;
        stage.scrollTop = picture.offsetTop + spotY * picture.offsetHeight - screenY;
    }

    function zoomTo(newStep, at = null) {
        newStep = Math.min(STEPS.length - 1, Math.max(0, newStep));
        if (newStep === step || !size) return;
        step = newStep;
        render(at);
    }

    function loaded(element, width, height) {
        if (element !== picture) return; // closed, or another picture opened, while it loaded
        size = { width, height };
        picture.classList.remove('is-loading');
        render();
        renderInfo();
    }

    // The message's pictures, looked up again each time, as a JPEG XL one may have been swapped for a <canvas> since
    function pictures() {
        return [...shown.item.querySelectorAll('.chat-attachment-image')];
    }

    // Shows the picture (an <img> or <canvas> in a message), opening the viewer if it isn't already
    function open(source) {
        const figure = source.closest('.chat-attachment');
        shown = { figure, item: source.closest('[data-message-id]') };
        name.textContent = figure.dataset.attachmentName ?? '';
        download.href = figure.dataset.attachmentDownload;
        download.download = figure.dataset.attachmentName ?? '';
        size = null;
        step = 0;

        const all = pictures();
        const hasMore = all.length > 1;
        count.hidden = !hasMore;
        prevButton.hidden = !hasMore;
        nextButton.hidden = !hasMore;
        count.textContent = `${all.indexOf(source) + 1} of ${all.length}`;

        if (source instanceof HTMLCanvasElement) {
            picture = document.createElement('canvas');
            picture.width = source.width;
            picture.height = source.height;
            picture.getContext('2d').drawImage(source, 0, 0);
            picture.setAttribute('role', 'img');
            picture.setAttribute('aria-label', name.textContent);
        } else {
            picture = document.createElement('img');
            picture.alt = name.textContent;
            picture.draggable = false;
            const image = picture;
            image.addEventListener('load', () => loaded(image, image.naturalWidth, image.naturalHeight), { once: true });
            // Deleted or expired since the page loaded
            image.addEventListener('error', () => {
                if (image !== picture) return;
                const gone = document.createElement('p');
                gone.className = 'chat-viewer-gone';
                gone.textContent = "This picture can't be shown anymore.";
                image.replaceWith(gone);
            }, { once: true });
            image.src = source.currentSrc || source.src;
        }
        picture.className = 'chat-viewer-picture is-loading'; // hidden until it's sized to fit
        stage.replaceChildren(picture);
        renderInfo();
        render();
        if (!dialog.open) dialog.showModal();
        if (picture instanceof HTMLCanvasElement) loaded(picture, picture.width, picture.height);
    }

    // The picture before (-1) or after (1) the one shown in its message, round to the other end
    function go(direction) {
        if (!shown) return;
        const all = pictures();
        const index = all.findIndex((other) => other.closest('.chat-attachment') === shown.figure);
        if (all.length < 2 || index === -1) return;
        open(all[(index + direction + all.length) % all.length]);
    }

    prevButton.addEventListener('click', () => go(-1));
    nextButton.addEventListener('click', () => go(1));

    messages.addEventListener('click', (event) => {
        const source = event.target.closest('.chat-attachment-image');
        if (!source || !source.closest('[data-message-id]')) return;
        if (source instanceof HTMLImageElement && source.complete && source.naturalWidth === 0) return; // broken
        open(source);
    });

    zoomOutButton.addEventListener('click', () => zoomTo(step - 1));
    zoomInButton.addEventListener('click', () => zoomTo(step + 1));

    infoButton.addEventListener('click', () => {
        isInfoOpen = !isInfoOpen;
        renderInfo();
    });

    dialog.querySelector('[data-viewer-close]').addEventListener('click', () => dialog.close());

    // Clicking anywhere but the picture, a button or the Info panel closes it - but not letting go after dragging
    dialog.addEventListener('click', (event) => {
        if (drag?.moved) {
            drag = null;
            return;
        }
        if (!event.target.closest('button, a, .chat-viewer-picture, .chat-viewer-zoom, [data-viewer-info]')) dialog.close();
    });

    dialog.addEventListener('keydown', (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey) return;
        if (event.key === '+' || event.key === '=') zoomTo(step + 1);
        else if (event.key === '-') zoomTo(step - 1);
        else if (event.key === '0') zoomTo(0);
        else if (event.key === 'ArrowLeft' && step === 0 && !prevButton.hidden) go(-1); // zoomed in they scroll it
        else if (event.key === 'ArrowRight' && step === 0 && !nextButton.hidden) go(1);
        else return;
        event.preventDefault();
    });

    stage.addEventListener('wheel', (event) => {
        if (!event.ctrlKey) return; // without Ctrl it scrolls the zoomed-in picture
        event.preventDefault(); // instead of zooming the whole page
        if (event.timeStamp - lastWheel < WHEEL_EVERY_MS) return;
        lastWheel = event.timeStamp;
        zoomTo(step + (event.deltaY < 0 ? 1 : -1), event);
    }, { passive: false });

    stage.addEventListener('dblclick', (event) => {
        if (event.target === picture) zoomTo(step === 0 ? DOUBLE_CLICK_STEP : 0, event);
    });

    // Dragging the zoomed-in picture with the mouse moves it around (touch screens scroll it by themselves)
    dialog.addEventListener('pointerdown', () => {
        drag = null;
    }, true);
    stage.addEventListener('pointerdown', (event) => {
        if (step === 0 || event.button !== 0 || event.pointerType !== 'mouse' || event.target !== picture) return;
        event.preventDefault(); // no selecting, or picking the picture up to drop it somewhere
        drag = { x: event.clientX, y: event.clientY, left: stage.scrollLeft, top: stage.scrollTop, active: true, moved: false };
        stage.setPointerCapture(event.pointerId);
        dialog.classList.add('is-dragging');
    });
    stage.addEventListener('pointermove', (event) => {
        if (!drag?.active) return;
        const x = event.clientX - drag.x;
        const y = event.clientY - drag.y;
        if (Math.abs(x) + Math.abs(y) > 3) drag.moved = true;
        stage.scrollLeft = drag.left - x;
        stage.scrollTop = drag.top - y;
    });
    const stopDrag = () => {
        if (drag) drag.active = false;
        dialog.classList.remove('is-dragging');
    };
    stage.addEventListener('pointerup', stopDrag);
    stage.addEventListener('pointercancel', stopDrag);

    window.addEventListener('resize', () => {
        if (dialog.open) render();
    }, { signal });

    dialog.addEventListener('close', () => {
        stage.replaceChildren();
        shown = null;
        picture = null;
        size = null;
        drag = null;
        dialog.classList.remove('is-zoomed', 'is-dragging');
    });
});

// Attachments: Files in the Attach menu (or pasting files into the message box, or dropping them anywhere on the open
// chat) picks files and pictures to send, up to data-attach-max-files in one message. They wait as tiles in a tray above
// the message box until Send, and go with whatever is typed, as one message. Each tile shows the picture (or the file's
// extension on a little document), its name, its size and a ticket with how many tickets it takes; × takes it out
// again and + adds more. The line under the tiles says what they come to together, or what's stopping them.
// Pictures without loss to squeeze out (PNG, BMP, lossless WebP) are turned into lossless JPEG XL by the JPEG XL worker
// straight away, one after another, unless "Keep original pictures" is ticked, and JPEGs into a JPEG XL that looks the
// same (chatJxlWorker.js). The original is sent instead when the JPEG XL isn't smaller. Other pictures that are already
// compressed with loss (AVIF, lossy WebP) are sent as they are, and so are animated ones, which would lose their
// animation. Videos (MP4, MOV, WebM, MKV) are turned into WebM + VP9 by the video worker (chatVideoWorker.js), in the
// same queue, unless "Keep original videos" is ticked (its own box, whatever "Keep original pictures" says); only when
// this browser can't do that is the original sent anyway. Anything else is zipped by the server.
// All of them go to the chat page in one upload (action=upload with files[] and converted[], answered as JSON), and then
// the chat is loaded again with the message in it (chatLoad, at the bottom). From Send until then, the message is shown
// faded at the bottom of the chat with "Sending…", so it's clear Send worked while pictures are still being converted and
// uploaded, and the bar along the top of the tray fills up as they go.
// Attachments are paid for with tickets. When the files need more than the user has free, the server says so, and the
// Your attachments popup opens with how many they need, so attachments can be deleted to make room and the files sent.
// The popup can also be opened from the Attach menu, and lists the attachments still taking tickets (/chat/attachments).
chatPart((signal) => {
    const composer = document.querySelector('.chat-composer');
    const input = document.querySelector('[data-attach-input]');
    const ticketsDialog = document.querySelector('.chat-tickets');
    const messages = document.querySelector('[data-chat-messages]');
    if (!composer || !input || !ticketsDialog || !messages) return;

    const textarea = composer.querySelector('textarea');
    const replyInput = composer.querySelector('[data-reply-input]');
    const sendButton = composer.querySelector('.chat-send-btn');
    const tray = composer.querySelector('[data-attach-tray]');
    const list = tray.querySelector('[data-attach-list]');
    const status = tray.querySelector('[data-attach-status]');
    const keepLabel = tray.querySelector('[data-attach-keep-label]');
    const keep = tray.querySelector('[data-attach-keep]');
    const keepVideoLabel = tray.querySelector('[data-attach-keep-video-label]');
    const keepVideo = tray.querySelector('[data-attach-keep-video]');
    const clearButton = tray.querySelector('[data-attach-clear]');
    const progress = tray.querySelector('[data-attach-progress]');
    const progressFill = tray.querySelector('[data-attach-progress-fill]');
    const fileItem = document.querySelector('[data-attach-file]'); // not there while attachments can't be sent
    const dropZone = document.querySelector('[data-attach-drop]'); // nor is this
    const maxBytes = Number(composer.dataset.attachMaxBytes); // all of a message's files together
    const maxFiles = Number(composer.dataset.attachMaxFiles);
    const ticketBytes = Number(composer.dataset.ticketBytes);
    const maxMb = maxBytes / 1024 / 1024;
    const jxl = chatJxlWorker();
    const video = chatWorker('data-video-worker');
    // What went wrong with one file: short on its tile, and the rest of a sentence about it under the tiles.
    // The keys are the server's error codes too, which say which file with file (its place in files[]).
    const FILE_ERRORS = {
        empty: { short: 'Empty', text: 'is empty' },
        tooBig: { short: 'Too big', text: `is over ${maxMb} MB` },
        uploadFailed: { short: 'Failed', text: "didn't get there" },
        storageError: { short: 'Failed', text: "couldn't be saved" },
    };
    // What went wrong with all of them (tooBig without a file is the whole upload being more than the server takes)
    const ERRORS = {
        tooBig: `Together they're too big. One message can have up to ${maxMb} MB of attachments.`,
        tooBigTogether: `Together they're too big. One message can have up to ${maxMb} MB of attachments.`,
        tooManyFiles: `One message can have up to ${maxFiles} attachments.`,
        muted: "You're muted in this chat, so you can't send anything.",
        messageTooLong: 'The message is too long.',
        linkBlocked: "The message has a blocked link in it. Take it out and try again.", // chatBlockedLink() normally catches it first
        storageFull: "HB Hub's storage is full. Attachments can be sent again once some expire or are deleted.",
        disabled: 'Attachments are turned off for now.',
        busy: 'Lots of attachments are being sent right now. Try again in a moment.',
        tooFast: "You're sending messages too fast. Wait a moment and try again.",
    };

    // The files waiting to be sent, in the order they're shown and sent: { file, name, kind, ready, result, failed,
    // progress, serverError, url, previewFailed, tile }
    // kind is null until ready (the promise of working it out) says: 'convert' (a picture to turn into JPEG XL),
    // 'image' (a picture sent as it is), 'video' (a video to turn into WebM + VP9) or 'file'. result is the JPEG XL or
    // WebM once made, kept so unticking "Keep original pictures/videos" again doesn't make it twice, and failed says why
    // there isn't one when it couldn't be made. progress is how far a video's conversion has got, 0 to 1.
    // serverError is what the server said was wrong with it (a FILE_ERRORS key). url is the picture for its preview.
    let jobs = [];
    let converting = null; // the picture or video being converted: { job, promise }, the rest wait their turn
    let notice = null; // a note under the tiles until something changes: { text, isError }
    let isSending = false; // from Send until the answer, waiting for conversions included
    let isUploading = false; // only while the files are on their way, when they can't be taken back any more
    let uploadText = ''; // "Sending… 40%"
    let sendToken = 0; // which Send is going on, so one called off by taking a file out stops where it is
    let sending = null; // the faded message shown while it's being sent: { item, meta, sizes: [{ job, size }] }

    // The + tile at the end of the row, for adding more
    const addTile = document.createElement('li');
    addTile.className = 'chat-tray-add-item';
    const addButton = document.createElement('button');
    addButton.type = 'button';
    addButton.className = 'chat-tray-add';
    addButton.setAttribute('aria-label', 'Add more files');
    addButton.title = 'Add more files';
    addButton.textContent = '+';
    addTile.append(addButton);
    list.append(addTile);

    function sizeLabel(bytes) {
        return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1).replace(/\.0$/, '')} MB`;
    }

    function ticketsFor(bytes) {
        return Math.max(1, Math.ceil(bytes / ticketBytes));
    }

    function ticketsLabel(count) {
        return `${count} ${count === 1 ? 'ticket' : 'tickets'}`;
    }

    // Like chatAttachmentExtension() in chat/index.php
    function extensionOf(name) {
        const extension = /\.([^.]+)$/.exec(name)?.[1].toUpperCase() ?? '';
        return /^[A-Z0-9]{1,5}$/.test(extension) ? extension : 'FILE';
    }

    // What kind of attachment the file is, by its first bytes rather than its name. Pictures worth converting are the
    // ones stored without loss, PNG and BMP, and WebP when it's the lossless kind (a VP8L chunk), plus JPEG. Animated
    // ones (an acTL chunk before the PNG's image data, an ANIM chunk in a WebP) are left alone. Other pictures the
    // server shows as pictures. Videos are told apart like hbHubChatAttachmentVideoType() in chatAttachments.php does:
    // MP4 and MOV by a video brand in their ftyp box, WebM and MKV by the Matroska header.
    async function kindOf(file) {
        // One character per byte, in pieces, as too many arguments at once is too many for fromCharCode
        const bytes = new Uint8Array(await file.slice(0, 64 * 1024).arrayBuffer());
        let text = '';
        for (let i = 0; i < bytes.length; i += 8192) text += String.fromCharCode(...bytes.subarray(i, i + 8192));
        if (text.slice(4, 8) === 'ftyp') {
            const brand = text.slice(8, 12);
            const brands = ['qt  ', 'iso', 'mp41', 'mp42', 'avc1', 'M4V', 'dash', 'mmp4', 'MSNV', 'XAVC'];
            if (brands.some((videoBrand) => brand.startsWith(videoBrand))) return 'video';
        }
        if (text.startsWith('\x1A\x45\xDF\xA3') && /webm|matroska/.test(text.slice(0, 64))) return 'video';
        if (text.startsWith('\x89PNG')) {
            const animation = text.indexOf('acTL');
            const imageData = text.indexOf('IDAT');
            return animation !== -1 && (imageData === -1 || animation < imageData) ? 'image' : 'convert';
        }
        if (text.startsWith('BM') || text.startsWith('\xFF\xD8\xFF')) return 'convert';
        if (text.startsWith('RIFF') && text.slice(8, 12) === 'WEBP') {
            return text.includes('VP8L') && !text.includes('ANIM') ? 'convert' : 'image';
        }
        const isImage = text.startsWith('GIF8') || text.slice(4, 12).startsWith('ftypavi')
            || text.startsWith('\xFF\x0A') || text.startsWith('\x00\x00\x00\x0CJXL ');
        return isImage ? 'image' : 'file';
    }

    // Still to be turned into JPEG XL or WebM before it can go (being converted now, or waiting its turn)
    function isWaiting(job) {
        const isConverted = (job.kind === 'video' && !keepVideo.checked) || (job.kind === 'convert' && !keep.checked);
        return isConverted && !job.result && !job.failed;
    }

    // What's sent: a video's WebM when there is one and the originals aren't kept (bigger or not, so videos play in the
    // chat), a picture's JPEG XL when there is one, it's smaller and the originals aren't kept, otherwise the file itself
    function toSend(job) {
        if (job.kind === 'video') return keepVideo.checked ? job.file : job.result ?? job.file;
        return job.kind === 'convert' && !keep.checked && job.result && job.result.size < job.file.size ? job.result : job.file;
    }

    function uploadName(job) {
        if (toSend(job) !== job.result) return job.file.name || 'picture.png';
        const base = (job.file.name || (job.kind === 'video' ? 'video' : 'picture')).replace(/\.[^.]*$/, '');
        return base + (job.kind === 'video' ? '.webm' : '.jxl');
    }

    // Why it can't be sent (a FILE_ERRORS key), or null. One that's too big may still fit once it's converted.
    function problem(job) {
        if (job.serverError) return job.serverError;
        if (job.file.size === 0) return 'empty';
        return job.kind !== null && !isWaiting(job) && toSend(job).size > maxBytes ? 'tooBig' : null;
    }

    // The next picture or video waiting to be converted, one at a time so they don't fight over the computer.
    // A video says how far it's got on its tile as it goes (they can take a while).
    function convertNext() {
        if (converting) return;
        const job = jobs.find(isWaiting);
        if (!job) return;
        const isVideo = job.kind === 'video';
        const onProgress = (progress) => {
            job.progress = progress;
            if (converting?.job === job) renderTile(job);
        };
        const run = isVideo ? video.run('encode', { file: job.file }, [], onProgress) : jxl.run('encode', { file: job.file });
        const promise = run
            .then(({ buffer }) => {
                job.result = new Blob([buffer], { type: isVideo ? 'video/webm' : 'image/jxl' });
            })
            .catch((error) => {
                if (error.name === 'AbortError') return;
                job.failed = error.message === 'noEncoder'
                    ? "Sent as it is, as this browser can't make WebM videos"
                    : "Sent as it is, as it couldn't be converted";
            })
            .finally(() => {
                if (converting?.job !== job) return; // stopped (taken out, or Keep ticked) and moved on from already
                converting = null;
                convertNext();
                render();
            });
        converting = { job, promise };
    }

    function stopConverting() {
        if (!converting) return;
        const { job } = converting;
        converting = null;
        job.progress = 0;
        // The promise above settles with an AbortError, which is left quiet
        if (job.kind === 'video') video.cancel();
        else jxl.cancel();
    }

    // A conversion on its way stops when another chat opens, and the previews' pictures are let go of
    signal.addEventListener('abort', () => {
        jxl.cancel();
        video.cancel();
        jobs.forEach((job) => job.url && URL.revokeObjectURL(job.url));
    });

    function element(tag, className, text = null) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== null) node.textContent = text;
        return node;
    }

    // The little document with the extension on it, like a file card's in a message
    function extensionBadge(job) {
        const badge = element('span', 'chat-attachment-ext', extensionOf(job.name));
        badge.setAttribute('aria-hidden', 'true');
        return badge;
    }

    function makeTile(job) {
        const item = element('li', 'chat-tray-item');
        const preview = element('div', 'chat-tray-preview');
        preview.append(extensionBadge(job));
        const ticket = element('span', 'chat-tray-ticket');
        preview.append(ticket);
        const name = element('span', 'chat-tray-name', job.name);
        const info = element('span', 'chat-tray-info');
        const remove = element('button', 'chat-tray-remove', '×');
        remove.type = 'button';
        remove.setAttribute('aria-label', `Remove ${job.name}`);
        remove.title = 'Remove';
        remove.addEventListener('click', () => {
            // Focus goes on to the next tile's ×, or back to the message box after the last one
            const next = item.nextElementSibling?.querySelector('.chat-tray-remove') ?? item.previousElementSibling?.querySelector('.chat-tray-remove');
            removeJob(job);
            (next ?? textarea).focus();
        });
        item.append(preview, name, info, remove);
        job.tile = { item, preview, ticket, info, remove };
    }

    // A picture's tile shows the picture itself, straight from the user's device. One this browser can't show
    // (JPEG XL in most, say) keeps its document.
    function showPreview(job) {
        job.url = URL.createObjectURL(job.file);
        const image = document.createElement('img');
        image.alt = '';
        image.addEventListener('load', () => {
            job.tile.preview.querySelector('.chat-attachment-ext')?.remove();
            job.tile.preview.prepend(image);
        }, { once: true });
        image.addEventListener('error', () => {
            job.previewFailed = true; // so the faded message while it's sent shows it as a file card too
        }, { once: true });
        image.src = job.url;
    }

    function renderTile(job) {
        const { item, ticket, info, remove } = job.tile;
        const error = problem(job);
        const isBusy = job.kind === null || converting?.job === job;
        item.classList.toggle('is-error', error !== null);
        item.classList.toggle('is-busy', isBusy);
        remove.disabled = isUploading;

        const file = toSend(job);
        const tickets = ticketsFor(file.size);
        ticket.hidden = error !== null || job.kind === null || isWaiting(job);
        ticket.textContent = tickets;
        ticket.classList.toggle('is-short', tickets > 5);

        // The whole story when pointed at
        const details = [job.name];
        if (error) {
            info.textContent = FILE_ERRORS[error].short;
            details.push(`It ${FILE_ERRORS[error].text}, so it can't be sent.`);
        } else if (job.kind === null) {
            info.textContent = 'Checking…';
        } else if (isWaiting(job)) {
            const percent = job.kind === 'video' && job.progress > 0 ? ` ${Math.floor(job.progress * 100)}%` : '';
            info.textContent = isBusy ? `Converting…${percent}` : 'Waiting to convert';
            details.push(job.kind === 'video'
                ? 'Being turned into a WebM video (VP9), so it plays in the chat and takes less space.'
                : 'Being turned into JPEG XL, which makes it smaller without losing anything.');
        } else {
            const format = job.kind === 'video' ? 'WebM' : 'JXL';
            info.textContent = sizeLabel(file.size) + (file === job.result ? ` · ${format}` : '');
            if (file === job.result) {
                details.push(`${job.kind === 'video' ? 'WebM (VP9)' : 'JPEG XL'}, ${sizeLabel(file.size)} (was ${sizeLabel(job.file.size)})`);
            } else {
                details.push(sizeLabel(file.size));
            }
            // The server zips other files first, so they may take fewer
            details.push(job.kind === 'file' ? `Up to ${ticketsLabel(tickets)}` : ticketsLabel(tickets));
            if (tickets > 5) details.push('Kept for 3 days, as it takes more than 5 tickets');
            if (job.kind === 'video' && !keepVideo.checked && job.failed) details.push(job.failed);
            if (job.kind === 'convert' && !keep.checked) {
                if (job.failed) details.push(job.failed);
                else if (file !== job.result) details.push("Sent as it is, as JPEG XL wasn't smaller");
            }
        }
        item.title = details.join('\n');
    }

    // The line under the tiles: what they come to together, or what's stopping them
    function renderStatus() {
        const bad = jobs.find((job) => problem(job) !== null);
        const waiting = jobs.filter(isWaiting).length;
        const totalBytes = jobs.reduce((sum, job) => sum + toSend(job).size, 0);
        let text;
        let isError = true;
        if (isUploading) {
            text = uploadText;
            isError = false;
        } else if (bad) {
            text = `${bad.name} ${FILE_ERRORS[problem(bad)].text}, so it can't be sent.${jobs.length > 1 ? ' Take it out to send the rest.' : ''}`;
        } else if (notice?.isError) {
            text = notice.text;
        } else if (waiting === 0 && totalBytes > maxBytes) {
            text = ERRORS.tooBigTogether;
        } else {
            isError = false;
            const parts = [`${jobs.length} ${jobs.length === 1 ? 'attachment' : 'attachments'}`];
            if (waiting > 0) {
                const videos = jobs.filter((job) => isWaiting(job) && job.kind === 'video').length;
                const pictures = waiting - videos;
                const what = [];
                if (pictures > 0) what.push(`${pictures === 1 ? 'a picture' : `${pictures} pictures`} to JPEG XL`);
                if (videos > 0) what.push(`${videos === 1 ? 'a video' : `${videos} videos`} to WebM`);
                parts.push(`converting ${what.join(' and ')}… You can send already, they go once it's done`);
            } else {
                const tickets = jobs.reduce((sum, job) => sum + ticketsFor(toSend(job).size), 0);
                parts.push(sizeLabel(totalBytes));
                parts.push((jobs.some((job) => job.kind === 'file') ? 'up to ' : '') + ticketsLabel(tickets));
            }
            if (notice) parts.push(notice.text);
            text = parts.join(' · ');
        }
        status.textContent = text;
        status.classList.toggle('is-error', isError);
    }

    function render() {
        const hasJobs = jobs.length > 0;
        tray.hidden = !hasJobs;
        // Without text they can still be sent, so the message box stops insisting on some
        textarea.required = !hasJobs;
        composer.toggleAttribute('data-has-attachment', hasJobs);
        keepLabel.hidden = !jobs.some((job) => job.kind === 'convert');
        keep.disabled = isUploading;
        keepVideoLabel.hidden = !jobs.some((job) => job.kind === 'video');
        keepVideo.disabled = isUploading;
        clearButton.disabled = isUploading;
        addTile.hidden = jobs.length >= maxFiles || isSending;
        progress.hidden = !isUploading;
        if (!isUploading) progressFill.style.transform = 'scaleX(0)';
        jobs.forEach(renderTile);
        renderStatus();
    }

    function pick(files) {
        if (isSending || files.length === 0) return;
        const room = Math.max(0, maxFiles - jobs.length);
        const left = files.length - room;
        notice = left > 0
            ? { text: `Only ${maxFiles} fit in one message, so ${left === 1 ? 'one was' : `${left} were`} left out`, isError: false }
            : null;
        files.slice(0, room).forEach((file) => {
            const job = {
                file, name: file.name || 'Pasted picture', kind: null, ready: null, result: null, failed: null,
                progress: 0, serverError: null, url: null, previewFailed: false, tile: null,
            };
            jobs.push(job);
            makeTile(job);
            list.insertBefore(job.tile.item, addTile);
            job.ready = kindOf(file).catch(() => 'file').then((kind) => {
                job.kind = kind;
                if (!jobs.includes(job)) return; // taken out in the meantime
                if (kind === 'convert' || kind === 'image') showPreview(job);
                convertNext();
                render();
            });
        });
        render();
        list.scrollLeft = list.scrollWidth; // the new ones are in view
    }

    function removeJob(job) {
        if (isUploading || !jobs.includes(job)) return;
        if (isSending) cancelSend(); // the faded message was made with it in
        if (converting?.job === job) stopConverting();
        jobs = jobs.filter((other) => other !== job);
        if (job.url) URL.revokeObjectURL(job.url);
        job.tile.item.remove();
        notice = null;
        convertNext();
        render();
    }

    function clear() {
        [...jobs].forEach(removeJob);
        input.value = '';
    }

    fileItem?.addEventListener('click', () => input.click());
    addButton.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        pick([...input.files]);
        input.value = ''; // so the same file can be picked again after taking it out
        textarea.focus();
    });

    clearButton.addEventListener('click', () => {
        clear();
        textarea.focus();
    });

    // Files pasted into the message box (a screenshot, say) or dropped anywhere on the open chat are picked like ones from Files
    if (fileItem) {
        textarea.addEventListener('paste', (event) => {
            const files = [...(event.clipboardData?.files ?? [])];
            if (files.length === 0) return;
            event.preventDefault();
            pick(files);
        });

        // "Drop to attach" covers the chat while files are dragged over it. Entering and leaving the things inside it
        // fire too, so it counts how deep in it the pointer is.
        const chatWindow = composer.closest('.chat-window');
        const hasFiles = (event) => event.dataTransfer?.types.includes('Files');
        let depth = 0;
        chatWindow.addEventListener('dragenter', (event) => {
            if (!hasFiles(event)) return;
            event.preventDefault();
            depth++;
            if (dropZone && !isSending) dropZone.hidden = false;
        });
        chatWindow.addEventListener('dragover', (event) => {
            if (!hasFiles(event)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = isSending ? 'none' : 'copy';
        });
        chatWindow.addEventListener('dragleave', (event) => {
            if (!hasFiles(event)) return;
            depth = Math.max(0, depth - 1);
            if (depth === 0 && dropZone) dropZone.hidden = true;
        });
        chatWindow.addEventListener('drop', (event) => {
            if (!hasFiles(event)) return;
            event.preventDefault();
            depth = 0;
            if (dropZone) dropZone.hidden = true;
            pick([...event.dataTransfer.files]);
            textarea.focus();
        });
    }

    // Keeping the original pictures stops a picture's conversion on its way (a video's goes on), and keeping the
    // original videos a video's. Unticking one again uses the JPEG XLs or WebMs already made, and goes on making the rest.
    const onKeepChange = (box, kind) => () => {
        notice = null;
        if (box.checked && converting?.job.kind === kind) stopConverting();
        convertNext(); // the other kind still goes on
        render();
    };
    keep.addEventListener('change', onKeepChange(keep, 'convert'));
    keepVideo.addEventListener('change', onKeepChange(keepVideo, 'video'));

    // A file card like the server's (chatAttachmentsHtml in chat/index.php), without the download
    function fileCard(job) {
        const card = element('div', 'chat-attachment is-file');
        const text = element('span', 'chat-attachment-text');
        const size = element('span', 'chat-attachment-size');
        text.append(element('span', 'chat-attachment-name', job.name), size);
        card.append(extensionBadge(job), text);
        return { card, size };
    }

    // A picture like the server's, the one from the user's device
    function pictureFigure(job) {
        const figure = element('figure', 'chat-attachment is-image');
        const image = element('img', 'chat-attachment-image');
        image.alt = job.name;
        image.height = 200;
        image.src = job.url;
        const caption = element('figcaption', 'chat-attachment-caption');
        const size = element('span', 'chat-attachment-size');
        caption.append(element('span', 'chat-attachment-download', job.name), size);
        figure.append(image, caption);
        return { figure, size };
    }

    // The message as it'll look, faded at the bottom of the chat until the page reloads with the real one: the pictures
    // (or file cards, also for pictures the browser can't show), and the text as typed. It has no data-message-id, so
    // polling and the message menu leave it alone, and polling adds new messages above it (addMessages below).
    function showSending() {
        const item = element('li', 'chat-message is-own is-sending');
        item.setAttribute('data-message-sending', '');
        const bubble = element('div', 'chat-message-bubble has-attachment');
        const box = element('div', 'chat-attachments');
        const sizes = [];
        const pictures = jobs.filter((job) => job.url && !job.previewFailed);
        const files = jobs.filter((job) => !pictures.includes(job));

        if (pictures.length === 1) {
            const { figure, size } = pictureFigure(pictures[0]);
            box.append(figure);
            sizes.push({ job: pictures[0], size });
        } else if (pictures.length > 1) {
            const gallery = element('div', `chat-attachment-gallery is-cols-${[2, 4].includes(pictures.length) ? 2 : 3}`);
            pictures.forEach((job) => gallery.append(pictureFigure(job).figure));
            box.append(gallery);
        }
        if (files.length > 0) {
            const cards = element('div', 'chat-attachment-files');
            files.forEach((job) => {
                const { card, size } = fileCard(job);
                cards.append(card);
                sizes.push({ job, size });
            });
            box.append(cards);
        }
        bubble.append(box);
        if (textarea.value.trim() !== '') bubble.append(element('div', 'chat-message-text', textarea.value));
        const meta = element('span', 'chat-message-meta', 'Sending…');
        meta.setAttribute('role', 'status');
        item.append(bubble, meta);

        const empty = messages.querySelector('[data-messages-empty]');
        if (empty) empty.hidden = true;
        messages.append(item);
        messages.scrollTop = messages.scrollHeight;
        sending = { item, meta, sizes };
        sendingSizes();
    }

    // How big they are: a picture's size changes once it's converted
    function sendingSizes() {
        sending?.sizes.forEach(({ job, size }) => {
            size.textContent = sizeLabel(toSend(job).size);
        });
    }

    // Taken away again when they weren't sent after all (an error, not enough tickets, or a file taken out while waiting)
    function hideSending() {
        if (!sending) return;
        sending.item.remove();
        sending = null;
        const empty = messages.querySelector('[data-messages-empty]');
        if (empty) empty.hidden = false;
    }

    function finishSending() {
        hideSending();
        isSending = false;
        isUploading = false;
        sendButton.disabled = false;
        render();
    }

    // Taking a file out while Send waits for conversions calls that Send off - Send again sends the rest
    function cancelSend() {
        sendToken++;
        finishSending();
    }

    // XMLHttpRequest rather than fetch, as only it says how far an upload has got
    function upload(body) {
        return new Promise((resolve, reject) => {
            const request = new XMLHttpRequest();
            request.open('POST', '/chat/');
            request.setRequestHeader('Accept', 'application/json');
            request.responseType = 'json';
            request.upload.addEventListener('progress', (event) => {
                if (!event.lengthComputable) return;
                const done = event.loaded / event.total;
                uploadText = `Sending… ${Math.round(done * 100)}%`;
                status.textContent = uploadText;
                progressFill.style.transform = `scaleX(${done})`;
                if (sending) sending.meta.textContent = uploadText;
            });
            request.addEventListener('load', () => resolve({ status: request.status, data: request.response ?? {} }));
            request.addEventListener('error', reject);
            request.send(body);
        });
    }

    async function send() {
        if (isSending || jobs.length === 0) return;
        if (jobs.some((job) => problem(job) !== null)) return; // the tray says what's wrong
        const token = ++sendToken;
        const isCurrent = () => token === sendToken && !signal.aborted;
        isSending = true;
        sendButton.disabled = true;
        notice = null;
        render();
        let isSent = false;
        try {
            // They only go once it's known which pictures to convert, and the conversions are done.
            // Ticking "Keep original pictures" or "Keep original videos" meanwhile stops waiting and sends those originals.
            await Promise.all(jobs.map((job) => job.ready));
            if (!isCurrent()) return;
            showSending();
            convertNext();
            while (converting) await converting.promise;
            if (!isCurrent()) return;
            sendingSizes();
            const totalBytes = jobs.reduce((sum, job) => sum + toSend(job).size, 0);
            if (jobs.some((job) => problem(job) !== null) || totalBytes > maxBytes) return; // the tray says what's wrong

            const sent = [...jobs];
            const body = new FormData();
            body.set('action', 'upload');
            body.set('chat', composer.elements.chat.value);
            body.set('message', textarea.value);
            body.set('reply', replyInput.value);
            sent.forEach((job) => {
                body.append('files[]', toSend(job), uploadName(job));
                body.append('converted[]', toSend(job) === job.result ? '1' : '0');
            });

            isUploading = true;
            uploadText = 'Sending…';
            render(); // × and the box can't be used any more
            const { status: httpStatus, data } = await upload(body);
            if (httpStatus === 200 && data.chatId) {
                isSent = true;
                // Sent, but another chat was opened in the meantime - that one stays
                if (!signal.aborted) chatLoad('/chat/?chat=' + data.chatId, { sent: body.get('message') });
                return;
            }
            if (data.error === 'notEnoughTickets') {
                openTickets(data.ticketsNeeded);
                return;
            }
            const job = Number.isInteger(data.file) ? sent[data.file] : null;
            if (job) job.serverError = data.error in FILE_ERRORS ? data.error : 'uploadFailed';
            else notice = { text: ERRORS[data.error] ?? "Couldn't send them. Try again.", isError: true };
        } catch {
            notice = { text: "Couldn't send them. Check your connection and try again.", isError: true };
        } finally {
            if (!isSent && token === sendToken) finishSending();
        }
    }

    // With attachments waiting, Send (or Enter) sends them this way instead of the normal form post
    composer.addEventListener('submit', (event) => {
        if (jobs.length === 0) return;
        event.preventDefault();
        send();
    });

    // Your attachments popup
    const ticketsTitle = ticketsDialog.querySelector('[data-tickets-title]');
    const ticketsNeededText = ticketsDialog.querySelector('[data-tickets-needed]');
    const ticketsCount = ticketsDialog.querySelector('[data-tickets-count]');
    const ticketsMeter = ticketsDialog.querySelector('[data-tickets-meter]');
    const ticketsList = ticketsDialog.querySelector('[data-tickets-list]');
    const ticketsStatus = ticketsDialog.querySelector('[data-tickets-status]');
    const retryButton = ticketsDialog.querySelector('[data-tickets-retry]');
    const ticketsRules = ticketsDialog.querySelector('[data-tickets-rules]');
    let ticketsNeeded = null; // how many the files waiting to be sent need, when that's why the popup is open

    // Like chatAttachmentsGoneHtml() in chat/index.php
    function goneText(deleted, expired) {
        const parts = [];
        if (deleted > 0) parts.push(`${deleted === 1 ? 'Attachment' : `${deleted} attachments`} deleted`);
        if (expired > 0) parts.push(`${expired === 1 ? 'Attachment' : `${expired} attachments`} expired`);
        return parts.join(' · ');
    }

    // An attachment deleted from the popup shows as deleted in the messages on the page too: it's taken out of its
    // message, which counts it on its line of gone ones, and is named after what's left of its attachments
    function markDeleted(attachmentId) {
        document.querySelectorAll(`[data-chat-messages] [href^="/chat/attachment?id=${attachmentId}&"]`).forEach((link) => {
            const message = link.closest('[data-message-id]');
            const box = link.closest('[data-attachments]');
            const attachment = link.closest('.chat-attachment');
            if (!message || !box || !attachment) return;
            const group = attachment.parentElement; // the gallery or the file cards, or the box itself for one picture
            attachment.remove();
            if (group !== box && group.children.length === 0) group.remove();

            let gone = box.querySelector('.chat-attachment-gone');
            if (!gone) {
                gone = element('p', 'chat-message-deleted chat-attachment-gone');
                box.append(gone);
            }
            const deleted = Number(gone.dataset.attachmentsDeleted ?? 0) + 1;
            const expired = Number(gone.dataset.attachmentsExpired ?? 0);
            gone.dataset.attachmentsDeleted = deleted;
            gone.dataset.attachmentsExpired = expired;
            gone.textContent = goneText(deleted, expired);

            const left = box.querySelectorAll('.chat-attachment');
            if (left.length === 0) {
                message.removeAttribute('data-message-attachment');
                message.removeAttribute('data-message-attachments');
            } else {
                message.dataset.messageAttachments = left.length;
                message.dataset.messageAttachment = left.length === 1
                    ? left[0].dataset.attachmentName ?? left[0].querySelector('.chat-attachment-name')?.textContent ?? 'Attachment'
                    : `${left.length} attachments`;
            }
        });
    }

    // The meter under the count: one little ticket each (used ones dimmed), or a single bar for people with lots of
    // extra tickets, where that many would be too thin to see
    function renderTicketsMeter(ticketsTotal, ticketsFree) {
        const free = Math.min(Math.max(ticketsFree, 0), ticketsTotal);
        ticketsMeter.classList.toggle('is-bar', ticketsTotal > 20);
        if (ticketsTotal > 20) {
            const fill = element('span', 'chat-tickets-meter-fill');
            fill.style.transform = 'scaleX(' + free / ticketsTotal + ')';
            ticketsMeter.replaceChildren(fill);
            return;
        }
        ticketsMeter.replaceChildren(...Array.from({ length: ticketsTotal }, (_, index) => (
            element('span', 'chat-tickets-pip' + (index < free ? '' : ' is-used'))
        )));
    }

    function renderTickets({ ticketsTotal, ticketsFree, attachments }) {
        const freeCount = element('strong', '', String(ticketsFree));
        ticketsCount.replaceChildren(freeCount, ` of ${ticketsTotal} tickets free`);
        ticketsCount.classList.toggle('is-empty', ticketsFree <= 0);
        renderTicketsMeter(ticketsTotal, ticketsFree);
        if (ticketsNeeded !== null) {
            const isEnough = ticketsFree >= ticketsNeeded;
            ticketsNeededText.textContent = isEnough
                ? `You have enough free tickets now. Send now sends your attachments (${ticketsLabel(ticketsNeeded)}).`
                : `Your attachments need ${ticketsLabel(ticketsNeeded)} together, but you only have ${ticketsFree} free. Delete some of the ones you've sent below to make room, or take some out of this message.`;
            retryButton.disabled = !isEnough;
        }

        ticketsList.replaceChildren(...attachments.map((attachment) => {
            const text = element('span', 'chat-ticket-text');
            text.append(
                element('span', 'chat-ticket-name', attachment.name),
                // A profile banner isn't in a chat and never runs out (chat and expires are null)
                element('span', 'chat-ticket-info', attachment.chat === null
                    ? `Your profile banner · ${attachment.size} · until you change it`
                    : `In ${attachment.chat} · ${attachment.size} · until ${attachment.expires}`),
            );

            // The ticket stub on the left: how many tickets it takes
            const stub = element('span', 'chat-ticket-stub');
            stub.setAttribute('aria-label', ticketsLabel(attachment.tickets));
            stub.append(element('strong', '', String(attachment.tickets)), element('span', '', attachment.tickets === 1 ? 'ticket' : 'tickets'));

            // Asks once more on the button itself before it's gone for good
            const remove = element('button', 'chat-btn is-danger', 'Delete');
            remove.type = 'button';
            remove.setAttribute('aria-label', `Delete ${attachment.name}`);
            remove.addEventListener('click', () => {
                if (!remove.dataset.confirm) {
                    remove.dataset.confirm = '1';
                    remove.textContent = 'Delete for good?';
                    return;
                }
                remove.disabled = true;
                loadTickets(attachment.id);
            });

            const item = element('li', 'chat-ticket');
            item.append(stub, text, remove);
            return item;
        }));
        ticketsStatus.textContent = attachments.length === 0 ? "None of your attachments are using tickets right now." : '';
    }

    // The list as it is now, or after deleting deleteId first
    async function loadTickets(deleteId = null) {
        ticketsStatus.textContent = deleteId === null ? 'Loading…' : 'Deleting…';
        try {
            const options = { headers: { Accept: 'application/json' } };
            if (deleteId !== null) {
                options.method = 'POST';
                options.body = new URLSearchParams({ action: 'delete', attachment: deleteId });
            }
            const response = await fetch('/chat/attachments', options);
            if (!response.ok) throw new Error(response.statusText);
            renderTickets(await response.json());
            if (deleteId !== null) markDeleted(deleteId);
        } catch {
            ticketsStatus.textContent = "Couldn't load your attachments. Try again.";
            ticketsList.querySelectorAll('button:disabled').forEach((button) => { button.disabled = false; });
        }
    }

    // needed: how many tickets the waiting attachments need together, when there weren't enough
    function openTickets(needed = null) {
        ticketsNeeded = needed;
        ticketsTitle.textContent = needed === null ? 'Your attachments' : 'Not enough tickets';
        ticketsNeededText.hidden = needed === null;
        retryButton.hidden = needed === null;
        retryButton.disabled = true;
        ticketsList.replaceChildren();
        ticketsCount.replaceChildren();
        ticketsMeter.replaceChildren();
        ticketsRules.open = false; // the fine print starts folded away under Read more each time
        ticketsDialog.showModal();
        loadTickets();
    }

    document.querySelector('[data-attach-tickets]')?.addEventListener('click', () => openTickets());

    retryButton.addEventListener('click', () => {
        ticketsDialog.close();
        send();
    });

    ticketsDialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => ticketsDialog.close());
    });

    // Clicking the dimmed area around the popup closes it
    ticketsDialog.addEventListener('click', (event) => {
        if (event.target === ticketsDialog) ticketsDialog.close();
    });

    ticketsDialog.addEventListener('close', () => textarea.focus());
});

// The emoji the user typed or reacted with most recently, newest first, kept in browser storage.
// Storage can be blocked (private windows), so there are just none then, and nothing is remembered.
const CHAT_EMOJI_RECENT_KEY = 'hbhub-chat-emoji-recent';
const CHAT_EMOJI_RECENT_MAX = 16;

function chatRecentEmoji() {
    try {
        const recent = JSON.parse(localStorage.getItem(CHAT_EMOJI_RECENT_KEY));
        return Array.isArray(recent) ? recent.filter((emoji) => typeof emoji === 'string').slice(0, CHAT_EMOJI_RECENT_MAX) : [];
    } catch {
        return [];
    }
}

function chatRememberEmoji(emoji) {
    try {
        const recent = [emoji, ...chatRecentEmoji().filter((other) => other !== emoji)].slice(0, CHAT_EMOJI_RECENT_MAX);
        localStorage.setItem(CHAT_EMOJI_RECENT_KEY, JSON.stringify(recent));
    } catch {
        // not remembered, that's fine
    }
}

// The emoji along the top of the message menu, to react with: the 6 used most recently, topped up with common ones
function chatQuickReactions() {
    return [...new Set([...chatRecentEmoji(), '👍', '❤️', '😂', '😮', '😢', '🔥'])].slice(0, 6);
}

// The emoji the user reacted with on a message (the lit up ones under it, chat/index.php)
function chatOwnReactions(item) {
    return new Set([...item.querySelectorAll('[data-reactions] .is-mine')].map((reaction) => reaction.dataset.reaction));
}

// One section of an emoji picker: its heading and a grid of a button per emoji. The tab's name and icon go along in
// data-tab-name/data-tab-icon, and the heading gets its id (for the grid's aria-labelledby) once it's in a picker.
function chatEmojiSection(name, icon, emoji) {
    const heading = document.createElement('h3');
    heading.className = 'chat-emoji-heading';
    heading.textContent = name;

    const grid = document.createElement('div');
    grid.className = 'chat-emoji-grid';
    grid.setAttribute('role', 'group');
    grid.append(...emoji.map((character) => {
        const pick = document.createElement('button');
        pick.type = 'button';
        pick.tabIndex = -1;
        pick.textContent = character;
        pick.dataset.emoji = character;
        return pick;
    }));

    const section = document.createElement('section');
    section.dataset.tabName = name;
    section.dataset.tabIcon = icon;
    section.append(heading, grid);
    return section;
}

// The category sections are made once per page load and copied into each picker, as making the ~1,200 buttons is what
// made opening it slow. The chat page swapping in the next chat keeps this, so its new pickers are just a copy away.
let chatEmojiCategoriesMade = null;
let chatEmojiPickerCount = 0; // so each picker's heading ids are its own

// fn(deadline) when the browser has a moment: deadline.timeRemaining() is how many ms it has left (0 without
// requestIdleCallback, so the caller does a little and asks again)
function chatEmojiWhenIdle(fn) {
    if ('requestIdleCallback' in window) requestIdleCallback(fn);
    else setTimeout(() => fn({ timeRemaining: () => 0 }));
}

// The browser is slow to measure each emoji the first time it meets one after the page loads (~0.6 ms each, so most of
// a second for the whole picker), and quick after that, even in another picker or after swapping chats. So, a few at a
// time while it has nothing else to do, they're measured in a hidden box (.chat-emoji-warm, chat.css) in the picker's
// font, and the first open is quick too. Once per page load.
let chatEmojiWarmed = false;
function chatEmojiWarmUp(emoji) {
    if (chatEmojiWarmed) return;
    chatEmojiWarmed = true;
    const box = document.createElement('div');
    box.className = 'chat-emoji-warm';
    box.setAttribute('aria-hidden', 'true');
    document.body.append(box);
    let next = 0;
    const step = (deadline) => {
        do {
            box.textContent = emoji.slice(next, next += 10).join(' ');
            void box.offsetWidth; // measures them
        } while (next < emoji.length && deadline.timeRemaining() > 5);
        if (next < emoji.length) chatEmojiWhenIdle(step);
        else box.remove();
    };
    chatEmojiWhenIdle(step);
}

// An emoji picker (the message box's and Add reaction's): category tabs along the top, and below them the emoji by
// category, with the recently used ones first. The categories are put in once (when the browser has a moment after the
// page loads, or on the first open if that's sooner) and kept, and render() on each open only redoes Recently used when
// it changed, so the recent ones are up to date (but don't move around while picking). onPick(emoji) is called when one
// is picked, and onClose(returnFocus) when Escape or Tab should close it. The arrow keys, Home and End move through the emoji.
function chatEmojiPicker(picker, { onPick, onClose }) {
    const tabs = picker.querySelector('[data-emoji-tabs]');
    const list = picker.querySelector('[data-emoji-list]');
    const idPrefix = 'chat-emoji-' + chatEmojiPickerCount++ + '-section-';

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

    // Puts the categories in, the first time only
    let isBuilt = false;
    function build() {
        if (isBuilt) return;
        isBuilt = true;
        if (!chatEmojiCategoriesMade) {
            chatEmojiCategoriesMade = document.createDocumentFragment();
            chatEmojiCategoriesMade.append(...categories.map(([name, icon, emoji]) => chatEmojiSection(name, icon, emoji.split(' '))));
        }
        list.append(chatEmojiCategoriesMade.cloneNode(true));
    }
    chatEmojiWhenIdle(build);
    chatEmojiWarmUp(categories.flatMap(([, , emoji]) => emoji.split(' ')));

    // Recently used and the tabs, made again only when the recent ones changed since the last open
    let shownRecent = null;
    function render() {
        build();
        const recent = chatRecentEmoji();
        if (recent.join(' ') !== shownRecent) {
            shownRecent = recent.join(' ');
            list.querySelector('[data-emoji-recent]')?.remove();
            if (recent.length > 0) {
                const section = chatEmojiSection('Recently used', '🕘', recent);
                section.dataset.emojiRecent = '';
                list.prepend(section);
            }

            tabs.replaceChildren(...[...list.children].map((section, index) => {
                const heading = section.querySelector('.chat-emoji-heading');
                heading.id = idPrefix + index;
                section.querySelector('.chat-emoji-grid').setAttribute('aria-labelledby', heading.id);

                const tab = document.createElement('button');
                tab.type = 'button';
                tab.textContent = section.dataset.tabIcon;
                tab.title = section.dataset.tabName;
                tab.setAttribute('aria-label', section.dataset.tabName);
                tab.addEventListener('click', () => {
                    list.scrollTop = section.offsetTop - list.offsetTop;
                    setActive(section.querySelector('[data-emoji]'));
                });
                return tab;
            }));
        }

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

    list.addEventListener('click', (event) => {
        const pick = event.target.closest('[data-emoji]');
        if (!pick) return;
        setActive(pick);
        onPick(pick.dataset.emoji);
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

    // Arrow keys move through the emoji, Home and End to the first and last. Escape closes, back where it was opened from.
    picker.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            onClose(true);
            return;
        }

        const pick = event.target.closest('[data-emoji]');
        if (!pick) return;
        if (event.key === 'Tab' && !event.shiftKey) {
            event.preventDefault();
            onClose(true);
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

    return { render };
}

// Emoji picker: the smiley left of GIF opens a popup above it with the emoji by category, and the recently used ones first.
// Picking one puts it where the cursor is in the message box, and the popup stays open for more.
// chat.css hides it on phones and tablets, which have emoji on their keyboard.
chatPart((signal) => {
    const button = document.querySelector('[data-emoji-open]');
    const picker = document.querySelector('[data-emoji-picker]');
    const textarea = document.querySelector('.chat-composer textarea');
    if (!button || !picker || !textarea) return;

    const emojiPicker = chatEmojiPicker(picker, { onPick: insert, onClose: (returnFocus) => setOpen(false, returnFocus) });

    // Puts the emoji where the cursor is (or over the selected text), as long as it fits within maxlength
    function insert(emoji) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        if (textarea.maxLength > 0 && textarea.value.length - (end - start) + emoji.length > textarea.maxLength) return;
        textarea.setRangeText(emoji, start, end, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true })); // so it grows like when typing
        chatRememberEmoji(emoji);
    }

    function setOpen(isOpen, returnFocus) {
        picker.hidden = !isOpen;
        button.setAttribute('aria-expanded', String(isOpen));
        if (isOpen) emojiPicker.render();
        else if (returnFocus) button.focus();
    }

    button.addEventListener('click', () => setOpen(picker.hidden, true));

    document.addEventListener('pointerdown', (event) => {
        if (!picker.hidden && !picker.contains(event.target) && !button.contains(event.target)) setOpen(false, false);
    }, { signal });
});

// Reactions: under a message is a button for each emoji someone reacted with and how many did (chat/index.php), lit up
// when the user is one of them. Clicking one adds the user's own, or takes it back when it's lit up.
// The message menu reacts too (chat:react), and its + (chat:react-more) opens the Add reaction picker where the menu was,
// with every emoji. The ones used to react with are remembered with the recently used emoji, so they're first next time.
// Each one is sent to the chat page (action=react), whose answer is the message's reactions as HTML, which go in in place
// of the old ones. Polling brings everyone else's (chat:reactions, from the live updates part below).
// A muted user can't react (data-muted), like they can't send messages - the server checks that too.
chatPart((signal) => {
    const messages = document.querySelector('[data-chat-messages]');
    const picker = document.querySelector('[data-reaction-picker]');
    if (!messages || !picker) return;

    const isMuted = messages.hasAttribute('data-muted');
    const sending = new Set(); // "messageId emoji" on its way, so a double click only sends it once
    const reactedAt = new Map(); // messageId -> when a reaction to it was last sent (performance.now())
    let pickerFor = null; // the message Add reaction is open for

    // The message's reactions, from the server as HTML. Left alone when nothing changed, so the pointer and keyboard
    // focus aren't disturbed, and otherwise focus stays on the same emoji.
    function showReactions(item, html) {
        const shown = item.querySelector('[data-reactions]');
        if (!shown) return; // a deleted message
        const fresh = shown.cloneNode(false);
        fresh.innerHTML = html;
        if (fresh.isEqualNode(shown)) return;
        const focused = shown.contains(document.activeElement) ? document.activeElement.dataset.reaction : undefined;
        shown.replaceWith(fresh);
        if (focused !== undefined) [...fresh.children].find((reaction) => reaction.dataset.reaction === focused)?.focus();
    }

    async function react(item, emoji) {
        const key = item.dataset.messageId + ' ' + emoji;
        if (isMuted || sending.has(key)) return;
        const isRemoving = chatOwnReactions(item).has(emoji);
        if (!isRemoving) chatRememberEmoji(emoji);
        sending.add(key);
        reactedAt.set(item.dataset.messageId, performance.now());

        try {
            const body = new URLSearchParams({ action: 'react', chat: messages.dataset.chatMessages, message: item.dataset.messageId, emoji });
            if (isRemoving) body.set('remove', '1');
            const response = await fetch('/chat/', { method: 'POST', body, headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (response.ok) showReactions(item, data.reactions);
        } catch {
            // Offline for a moment - nothing changed, so it can just be clicked again
        } finally {
            sending.delete(key);
        }
    }

    messages.addEventListener('click', (event) => {
        const reaction = event.target.closest('[data-reaction]');
        const item = reaction?.closest('[data-message-id]');
        if (item) react(item, reaction.dataset.reaction);
    });

    messages.addEventListener('chat:react', (event) => react(event.detail.item, event.detail.emoji));

    // Add reaction: where the message menu was, but never past the edge of the window
    const emojiPicker = chatEmojiPicker(picker, {
        onPick(emoji) {
            const item = pickerFor;
            close(true);
            react(item, emoji);
        },
        onClose: (returnFocus) => close(returnFocus),
    });

    function close(returnFocus) {
        if (picker.hidden) return;
        picker.hidden = true;
        if (returnFocus) pickerFor.focus({ preventScroll: true }); // back on the message, as the menu is gone
    }

    messages.addEventListener('chat:react-more', (event) => {
        const { item, x, y } = event.detail;
        pickerFor = item;
        picker.hidden = false;
        // Its size is set in chat.css, so it's in place before render() scrolls to the first emoji
        const { width, height } = picker.getBoundingClientRect();
        picker.style.left = Math.max(8, Math.min(x, window.innerWidth - width - 8)) + 'px';
        picker.style.top = Math.max(8, Math.min(y, window.innerHeight - height - 8)) + 'px';
        emojiPicker.render();
    });

    document.addEventListener('pointerdown', (event) => {
        if (!picker.contains(event.target)) close(false);
    }, { signal });
    window.addEventListener('resize', () => close(false), { signal });

    // Polling: { reactions: messageId -> HTML for the ones that have any, from, to, sentAt }. Every message from `from` to
    // `to` (the ones shown when the poll was sent) gets its own, and the ones not in it have none any more. A message the
    // user reacted to after the poll was sent is left alone, as the answer may be from before that.
    messages.addEventListener('chat:reactions', (event) => {
        const { reactions, from, to, sentAt } = event.detail;
        messages.querySelectorAll('[data-message-id]').forEach((item) => {
            const id = Number(item.dataset.messageId);
            if (id < from || id > to || (reactedAt.get(item.dataset.messageId) ?? -Infinity) > sentAt) return;
            showReactions(item, reactions[id] ?? '');
        });
    });
});

// GIF picker: the GIF button next to Send searches Giphy through /chat/gifs (which holds the API key),
// with the trending GIFs shown before anything is typed. Picking one sends it straight away, together with
// whatever was typed in the message box (the server sends that first) and the reply, if there is one.
chatPart((signal) => {
    const dialog = document.querySelector('.chat-gif');
    const composer = document.querySelector('.chat-composer');
    if (!dialog || !composer) return;

    const textarea = composer.querySelector('textarea');
    const gifInput = composer.querySelector('[data-gif-input]');
    const search = dialog.querySelector('#chat-gif-search');
    const results = dialog.querySelector('[data-gif-results]');
    const status = dialog.querySelector('[data-gif-status]');
    const SEARCH_DELAY_MS = 350; // wait this long after the last key press, so typing "cats" is one search, not four
    const COLUMN_MIN_WIDTH = 130; // px; as many columns as fit
    const GAP = 8; // px between the GIFs, both ways
    let searchTimer = null;
    let searchRequest = null;
    let hasLoaded = false; // opening the picker again keeps the last search instead of asking Giphy again
    let isSending = false;

    function send(gifId) {
        if (isSending) return; // no double GIFs from a double click
        dialog.close();
        if (chatBlockedLink(textarea.value)) return; // the text in the box goes with it, so a blacklisted link stops the GIF too
        isSending = true;
        gifInput.value = gifId;
        chatSubmit(composer); // not requestSubmit: the message box may be empty, which is fine with a GIF
    }

    /**
     * Lays the GIFs out in columns, in order across the rows (1, 2, 3 on top, then 4, 5, 6), each one going in the
     * shortest column so far. The grid has 1px rows and every GIF spans as many as it is tall, worked out from its
     * size so nothing has to load first. Called again whenever the picker changes width.
     */
    function layoutGifs() {
        const style = getComputedStyle(results);
        const width = results.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        if (width <= 0) return; // closed or empty; the ResizeObserver lays it out once it shows

        const columnCount = Math.max(1, Math.floor((width + GAP) / (COLUMN_MIN_WIDTH + GAP)));
        const columnWidth = (width - GAP * (columnCount - 1)) / columnCount;
        const columnHeights = new Array(columnCount).fill(0);
        results.style.gridTemplateColumns = `repeat(${columnCount}, 1fr)`;

        for (const item of results.children) {
            const column = columnHeights.indexOf(Math.min(...columnHeights));
            const rows = Math.ceil(columnWidth * Number(item.dataset.gifRatio)) + GAP;
            item.style.gridColumn = String(column + 1);
            item.style.gridRow = `${columnHeights[column] + 1} / span ${rows}`;
            columnHeights[column] += rows;
        }
    }

    function renderGifs(gifs) {
        results.replaceChildren(...gifs.map((gif) => {
            const hasSize = gif.width > 0 && gif.height > 0;
            const image = document.createElement('img');
            image.src = gif.preview;
            image.alt = gif.title || 'GIF';
            if (hasSize) {
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
            item.dataset.gifRatio = hasSize ? gif.height / gif.width : 1; // height per px of width; square if unknown
            item.append(button);
            return item;
        }));
        layoutGifs();
        results.scrollTop = 0;
    }

    // Opening the picker or resizing the window changes how many columns fit
    const resizeObserver = new ResizeObserver(layoutGifs);
    resizeObserver.observe(results);
    signal.addEventListener('abort', () => resizeObserver.disconnect());

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
});

// Older messages: a chat opens with only its newest messages, and while there are older ones the "Loading older messages..."
// line is at the top (data-messages-older). Scrolling up close to it asks the chat page for the ones before the oldest shown
// (?older=, answered as { messages: html, hasOlder }) and puts them in above, a batch at a time, until there are none left
// and the line goes. What's on screen stays where it is while they go in.
// Clicking a reply quote whose message isn't loaded yet sends chat:load-until (from the messages part above):
// then it's everything back to that message (?until=), and onDone is called once it's there (or can't be loaded).
chatPart(() => {
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
            chatGroupMessages(messages);
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
});

// Live updates: every 2 seconds asks the chat page what's new (?poll=1), adds the open chat's new messages at the bottom
// and swaps in the chat list when something in it changed (new messages, unread counts, new chats).
// The open chat's background is kept up to date too, so a new one shows up without a reload, and so are the reactions
// on the messages already shown (?from= the oldest one), which change on old messages as well.
// Message requests are swapped in the same way, and the requests button shows up when there are any. So is the list in
// the Pinned messages popup, as messages are pinned and unpinned (which tells the pins part above, chat:pins-update).
// All three come with a version (chatListV, requestsV, pinsV), sent back as ?listV=, ?requestsV= and ?pinsV=: while
// it's still the one shown, the server leaves the HTML out, as most polls nothing in them changed.
// The number of unread chats is passed on to the red number on Chat in the side bar.
// The nav bar's request (navUpdates.js) comes along about every 15 seconds (nav:ride, ?nav=), so the chat page doesn't
// send that one on its own as well. Its answer is "nav", passed on as nav:update.
// Polling keeps the user's last seen time up to date too, like any other request (session.php).
// While the page is hidden (another tab, minimised) it doesn't poll at all: each poll is one of the few PHP requests the
// hosting runs at once, for the whole site. Coming back to the page polls straight away.
// New messages are only marked read while the page is being looked at, and not at all after "Mark unread" (?unread=1).
// Typing: while something is typed in the message box and a key was pressed in the last few seconds, polls say so
// (?typing=1), and the first one after they stop says that (?typing=0). Starting to type, or emptying the box, polls
// straight away so it shows up quickly. Everyone else typing in the open chat comes back as "typing" (a list of names),
// shown as "Alice is typing..." just above the message box.
chatPart((signal, isSwap) => {
    const POLL_MS = 2000;
    const TYPING_IDLE_MS = 4000; // no key pressed for this long, and they've stopped typing
    const TYPING_SHOWN_MAX = 3; // names in "Alice, Bob and Carl are typing...", more is "Several people are typing..."
    const list = document.querySelector('.chat-list');
    const messages = document.querySelector('[data-chat-messages]');
    const textarea = document.querySelector('.chat-composer textarea');
    const typingLine = document.querySelector('[data-chat-typing]');
    const typingNames = document.querySelector('[data-chat-typing-names]');
    const chatMenu = document.querySelector('[data-chat-menu]');
    const requestsButton = document.querySelector('[data-requests-open]');
    const requestsCount = document.querySelector('[data-requests-count]');
    const requestsDialog = document.querySelector('.chat-requests');
    const requestsList = document.querySelector('[data-requests-list]');
    const pinsList = document.querySelector('[data-pins-list]');
    if (!list) return;

    const keepUnread = new URLSearchParams(location.search).get('unread') === '1';
    let shownChatListV = null; // the version of the chat list last swapped in, so it's only sent again when something changed
    let shownRequestsV = null; // the same for the message requests
    let shownPinsV = null; // and the pinned messages

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
        // Above an attachment still being sent (the faded message from the attachments part), which stays at the bottom
        const sending = messages.querySelector('[data-message-sending]');
        if (sending) sending.insertAdjacentHTML('beforebegin', html);
        else messages.insertAdjacentHTML('beforeend', html);
        chatGroupMessages(messages);
        if (isAtBottom) messages.scrollTop = messages.scrollHeight;

        // In a chat that was empty there was no message to tab to yet, now the newest one is (only a notice may have come)
        const items = [...messageItems()];
        if (items.length > 0 && !items.some((item) => item.tabIndex === 0)) items.at(-1).tabIndex = 0;
    }

    // html is undefined when it's still the one shown
    function updateChatList(html, version) {
        if (html === undefined || version === shownChatListV) return;
        // Not while the chat menu or a popup may be about a chat in the list - a later poll swaps it instead
        if (!chatMenu.hidden || document.querySelector('dialog[open]:not(.chat-viewer)')) return;

        // Keyboard focus stays on the same chat
        const focusedChatId = list.contains(document.activeElement)
            ? document.activeElement.closest('[data-chat-id]')?.dataset.chatId ?? null
            : null;
        list.innerHTML = html;
        shownChatListV = version;
        if (focusedChatId !== null) list.querySelector(`[data-chat-id="${focusedChatId}"] a`)?.focus();
        list.dispatchEvent(new Event('chat-list-update')); // the search filter runs again on the new list
    }

    function updateRequests(html, version, count) {
        if (html === undefined || version === shownRequestsV) return;
        // Not while the popup is open, so Accept and Decline don't move under the pointer - a later poll swaps it instead
        if (requestsDialog.open) return;

        requestsList.innerHTML = html;
        shownRequestsV = version;
        requestsButton.hidden = count === 0;
        requestsButton.setAttribute('aria-label', `Message requests (${count})`);
        requestsCount.textContent = count > 9 ? '9+' : String(count);
    }

    function updatePins(html, version) {
        if (html === undefined || version === shownPinsV || !pinsList) return;
        // Not while the popup is open, so Jump and Unpin don't move under the pointer - a later poll swaps it instead
        if (pinsList.closest('dialog').open) return;

        pinsList.innerHTML = html;
        shownPinsV = version;
        pinsList.dispatchEvent(new Event('chat:pins-update'));
    }

    let typedAt = null; // when a key was last pressed in the message box, null while it's empty
    // The server was told they're typing, and not yet that they stopped. After a swap it may have been: sending a message
    // stops it, but a poll that went just before can land after that, so the first poll after a swap says they stopped.
    let isTypingSaved = isSwap;

    function isTyping() {
        return typedAt !== null && performance.now() - typedAt < TYPING_IDLE_MS;
    }

    // "Alice is typing...", "Alice and Bob are typing..." or "Alice, Bob and Carl are typing..."
    function showTyping(names) {
        typingLine.hidden = names.length === 0;
        if (names.length === 0) return;
        let text;
        if (names.length === 1) text = `${names[0]} is typing...`;
        else if (names.length <= TYPING_SHOWN_MAX) text = `${names.slice(0, -1).join(', ')} and ${names.at(-1)} are typing...`;
        else text = 'Several people are typing...';
        if (typingNames.textContent !== text) typingNames.textContent = text; // the same text isn't read out again
    }

    let pollTimer = null; // the next poll, null while the page is hidden or a poll is on its way
    let isPolling = false;
    let polledAt = 0;

    async function poll() {
        pollTimer = null;
        if (document.visibilityState !== 'visible') return; // goes on once the page is in view again
        isPolling = true;

        const params = new URLSearchParams({ poll: '1' });
        const sentAt = performance.now();
        polledAt = sentAt;
        if (messages) {
            const items = shownItems();
            params.set('chat', messages.dataset.chatMessages);
            params.set('after', items.length > 0 ? itemId(items[items.length - 1]) : '0');
            // The reactions on every message shown, from the oldest one on
            if (items.length > 0) params.set('from', itemId(items[0]));
            if (document.visibilityState === 'visible' && !keepUnread) params.set('read', '1');
            // Looking at the chat, so being @mentioned in it doesn't send a notification. The window has to have focus
            // too: one that's only showing behind or beside another window still counts as visible.
            if (document.visibilityState === 'visible' && document.hasFocus()) params.set('looking', '1');
            if (isTyping() && !chatIsPosting) params.set('typing', '1'); // not while the message is being sent
            else if (isTypingSaved) params.set('typing', '0');
            if (shownPinsV !== null) params.set('pinsV', shownPinsV);
        }
        if (shownChatListV !== null) params.set('listV', shownChatListV);
        if (shownRequestsV !== null) params.set('requestsV', shownRequestsV);
        const ride = { params: null };
        document.dispatchEvent(new CustomEvent('nav:ride', { detail: ride })); // navUpdates.js
        if (ride.params !== null) params.set('nav', ride.params);

        try {
            const response = await fetch('/chat/?' + params, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(response.statusText);
            const data = await response.json();
            if (params.has('typing')) isTypingSaved = params.get('typing') === '1';
            if (data.nav) document.dispatchEvent(new CustomEvent('nav:update', { detail: data.nav }));
            if (signal.aborted) return; // another chat was opened while it was on its way, and polls for itself

            if (messages && !data.openChat) {
                chatLoad('/chat/', { replace: true }); // the open chat is gone: left, deleted, or removed from it
                return;
            }
            if (messages) {
                // Before the new messages go in, which came with their reactions already
                if (params.has('from')) {
                    messages.dispatchEvent(new CustomEvent('chat:reactions', { // the reactions part above
                        detail: { reactions: data.reactions ?? {}, from: Number(params.get('from')), to: Number(params.get('after')), sentAt },
                    }));
                }
                addMessages(data.messages);
                updatePins(data.pins, data.pinsV); // after the new messages, so they're marked too
                chatShowBackground(messages, data.background);
                showTyping(data.typing ?? []);
            }
            updateChatList(data.chatList, data.chatListV);
            updateRequests(data.requests, data.requestsV, data.requestCount);
            document.dispatchEvent(new CustomEvent('chat:unread', { detail: { unreadChats: data.unreadChats } })); // chatUnread.js
        } catch {
            // Offline for a moment, or logged out (the login page isn't JSON) - try again next time
        }

        // The next poll waits for this one, so a slow answer never has two on their way at once
        isPolling = false;
        if (!signal.aborted) pollTimer = setTimeout(poll, POLL_MS);
    }

    pollTimer = setTimeout(poll, POLL_MS);
    signal.addEventListener('abort', () => clearTimeout(pollTimer));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && pollTimer === null && !isPolling) poll();
    }, { signal });

    // Formatting, emoji and @mentions put text in the box with an input event too, so they count as typing
    textarea?.addEventListener('input', () => {
        const wasTyping = isTyping();
        typedAt = textarea.value.trim() !== '' ? performance.now() : null;
        // Started or stopped: the next poll goes now instead of in up to 2 seconds - but not more than once a second
        if (isTyping() !== wasTyping && pollTimer !== null && performance.now() - polledAt > 1000) {
            clearTimeout(pollTimer);
            poll();
        }
    }, { signal });
});

// Switching chats: the chat page is never loaded again while it's open. Opening a chat (the chat list, ← on phones,
// Back and Forward) fetches the page that would have loaded, and every form on it (sending a message, the chat menu,
// the popups) is posted with fetch, which follows the server's redirect back to the chat. Then that page's <main> takes
// the old one's place and the parts above are set up again on it (chatPart at the top). Everything outside <main> stays,
// and pictures that have loaded once show straight away (pictureQueue.js), so nothing flashes or loads again.
// The address and the history follow along, so a refresh still opens the same chat. Anything that doesn't come back as
// the chat page (logged out, the maintenance page, an error) is opened the normal way instead.
// Without JS (or before this has run) the links and forms just load the page like they always did.
let chatSwap = null; // aborts the parts set up on the <main> that's shown
let chatRequest = null; // aborts the page on its way, as a newer one takes over
let chatIsPosting = false; // a form is being sent: another isn't, so Enter twice doesn't send a message twice
let chatShownAddress = ''; // the path and query of the chat shown, so Back to just another #message is left alone

function chatStart(isSwap) {
    chatSwap = new AbortController();
    chatParts.forEach((setUp) => setUp(chatSwap.signal, isSwap));
    chatShownAddress = location.pathname + location.search; // after the parts, which take ?linkBlocked=1 and such out
}

/**
 * Opens the chat page at url in place of the one shown. With body it's posted there instead (a form), and the page is
 * the one the server redirects to. fromHistory: Back or Forward, which already changed the address. replace: the history
 * entry is replaced instead of adding one (it is anyway when it's the same address again, like after sending a message).
 * sent: the message that was just sent, so the box starts empty again - unless more was typed meanwhile, which stays.
 * Anything typed in the message box stays when the same chat comes back (after muting it, say), like a draft.
 */
async function chatLoad(url, { body = null, fromHistory = false, replace = false, sent = null } = {}) {
    const main = document.querySelector('main.chat');
    chatRequest?.abort();
    const request = new AbortController();
    chatRequest = request;
    main.setAttribute('aria-busy', 'true');

    let response;
    let page;
    try {
        response = await fetch(url, { method: body ? 'POST' : 'GET', body, signal: request.signal, headers: { Accept: 'text/html' } });
        page = new DOMParser().parseFromString(await response.text(), 'text/html');
    } catch (error) {
        if (error.name === 'AbortError') return; // a newer one took over
        chatRequest = null;
        main.removeAttribute('aria-busy');
        // Offline for a moment. A chat is opened the normal way, which shows the browser's own page for that.
        // A form isn't sent again, as it may have got there - what was typed is still in the box to try again.
        if (!body) location.href = url;
        return;
    } finally {
        if (body) chatIsPosting = false;
    }
    chatRequest = null;

    const newMain = page.querySelector('main.chat');
    const address = new URL(response.url);
    if (!response.ok || !newMain || address.origin !== location.origin || !/^\/chat\/?$/.test(address.pathname)) {
        location.href = response.url;
        return;
    }

    // What carries over: where the chat list was scrolled to, the search, keyboard focus on a chat in the list,
    // and what's typed in the message box when it's the same chat
    const list = main.querySelector('.chat-list');
    const search = main.querySelector('#chat-search');
    const textarea = main.querySelector('.chat-composer textarea');
    const chatId = main.querySelector('[data-chat-messages]')?.dataset.chatMessages;
    const focusedChatId = list?.contains(document.activeElement) ? document.activeElement.closest('[data-chat-id]')?.dataset.chatId : undefined;
    // Not sent after all (a blocked link, or sending too fast): the message stays in the box to try again
    const isRefused = address.searchParams.get('linkBlocked') === '1' || address.searchParams.get('tooFast') === '1';
    const draft = textarea && (textarea.value !== sent || isRefused) ? textarea.value : '';

    // Copies of the pictures for browsers without JS. Parsed here they're real <img>s, which would all load at once.
    newMain.querySelectorAll('noscript').forEach((noscript) => noscript.remove());

    // The address first, as the parts read it (?unread=1, ?linkBlocked=1, ?tooFast=1, ?nicknameRefused=, ?requests=1, ?pins=, #message-)
    const path = address.pathname + address.search;
    if (fromHistory) history.replaceState(null, '', path + location.hash);
    else if (replace || path === location.pathname + location.search) history.replaceState(null, '', path);
    else history.pushState(null, '', path);
    document.title = page.title;

    chatSwap.abort();
    main.replaceWith(newMain);

    const newList = newMain.querySelector('.chat-list');
    const newSearch = newMain.querySelector('#chat-search');
    const newTextarea = newMain.querySelector('.chat-composer textarea');
    if (newList && list) newList.scrollTop = list.scrollTop;
    if (newSearch && search) newSearch.value = search.value;
    if (newTextarea && draft !== '' && newMain.querySelector('[data-chat-messages]')?.dataset.chatMessages === chatId) {
        newTextarea.value = draft;
    }

    chatStart(true);

    if (newTextarea && draft !== '') newTextarea.dispatchEvent(new Event('input', { bubbles: true })); // grows to fit it
    // Like autofocus does on a page load: straight into the message box - but not on phones and tablets, where that
    // would pull up the keyboard. Keyboard focus that was on a chat in the list stays there.
    const focusedChat = focusedChatId !== undefined ? newList?.querySelector(`[data-chat-id="${focusedChatId}"] a`) : null;
    if (focusedChat && !newTextarea) focusedChat.focus({ preventScroll: true });
    else if (newTextarea && !window.matchMedia('(hover: none)').matches) newTextarea.focus({ preventScroll: true });
    else focusedChat?.focus({ preventScroll: true });
}

/**
 * Sends a form on the chat page with chatLoad. submitter: the button it was sent with, whose name and value go along.
 * For forms sent from the code too (form.submit() would load the page).
 */
function chatSubmit(form, submitter = null) {
    if (chatIsPosting) return;
    chatIsPosting = true;
    const body = new FormData(form);
    if (submitter?.name) body.append(submitter.name, submitter.value);
    const sent = form.matches('.chat-composer') ? form.querySelector('textarea').value : null;
    chatLoad(chatFormAction(form), { body, sent });
}

// Where the form goes. Not form.action, as the chat's forms have an <input name="action">, which that gives instead.
function chatFormAction(form) {
    return new URL(form.getAttribute('action') ?? '', location.href);
}

(() => {
    if (!document.querySelector('main.chat')) return;

    // A link to the chat page (/chat/ or /chat/?chat=id) inside it, clicked without anything that opens a new tab
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (!link.closest('main.chat') || link.target !== '' || link.hasAttribute('download')) return;
        const url = new URL(link.href);
        if (url.origin !== location.origin || !/^\/chat\/?$/.test(url.pathname) || [...url.searchParams.keys()].some((key) => key !== 'chat')) return;
        event.preventDefault();

        // Lit up straight away, so it's clear it worked while the chat loads
        if (link.matches('.chat-list-item')) {
            link.closest('.chat-list')?.querySelectorAll('.chat-list-item.active').forEach((other) => {
                other.classList.remove('active');
                other.removeAttribute('aria-current');
            });
            link.classList.add('active');
            link.setAttribute('aria-current', 'page');
        }
        chatLoad(url.pathname + url.search);
    });

    // Every form on the chat page posts to /chat/. Last in line, so the parts above can still stop one (an empty
    // message, a blocked link) or send it their own way (an attachment).
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || !form.closest('main.chat') || form.getAttribute('method')?.toLowerCase() !== 'post') return;
        const action = chatFormAction(form);
        if (action.origin !== location.origin || !/^\/chat\/?$/.test(action.pathname)) return;
        event.preventDefault();
        chatSubmit(form, event.submitter);
    });

    addEventListener('popstate', () => {
        if (location.pathname + location.search !== chatShownAddress) chatLoad(location.href, { fromHistory: true });
    });

    chatStart(false);
})();
