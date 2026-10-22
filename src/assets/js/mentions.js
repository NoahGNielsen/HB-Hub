// @mentions while typing: in the chat's message box and Edit (chat/index.php), and in the games' chats (chess, Matador
// and UNO). Typing @ at the start of a word opens a list of the people who can be mentioned there, narrowed down by
// what's typed after it (the start of their name, or of any word in it). Up and Down move through it, Enter or Tab puts
// "@name " in, Escape closes it, and clicking a name works too.
// Who can be mentioned is the box's data-mentions, as JSON: [{"name": "the name shown", "userName": "what goes in"}].
// It's read again every time, so the games can change it as people come and go (chessGame.js, matadorGame.js and
// unoGame.js).
// What counts as a mention, and who hears about it, is all worked out on the server (assets/php/mentions.php) - this
// only helps type them.
// The listeners are on the whole document rather than on each box, so boxes put in later work too: the chat swaps in a
// new message box every time another chat opens (Switching chats in chat.js).
(() => {
    const SHOWN = 8; // names in the list at most
    const NAME_MAX = 35; // userName: typed further than this after the @, it can't be a name anymore

    // One list for every box. A popover, so it's above everything else on the page, Edit's dialog included.
    const list = document.createElement('ul');
    list.className = 'mentions-list';
    list.id = 'mentions-list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'People to mention');
    list.popover = 'manual';
    document.body.append(list);

    let box = null; // the box the list is open for
    let at = 0; // where the @ being typed is in it
    let choices = []; // the people in the list
    let active = 0; // the one Enter picks

    function people(target) {
        try {
            const value = JSON.parse(target.dataset.mentions);
            return Array.isArray(value) ? value : [];
        } catch {
            return [];
        }
    }

    // The @ right before the caret and what's typed after it, or null when there isn't one being typed.
    // Names can have spaces in them, so it's everything back to the @ - but not past a new line or another @.
    function typing(target) {
        if (target.selectionStart !== target.selectionEnd) return null;
        const before = target.value.slice(0, target.selectionStart);
        const match = /(?:^|[^\p{L}\p{N}_])@([^@\n]*)$/u.exec(before);
        if (!match || match[1].length > NAME_MAX) return null;
        return { at: before.length - match[1].length - 1, query: match[1].toLowerCase() };
    }

    function matches(person, query) {
        return [person.name, person.userName].some((name) => {
            const lower = name.toLowerCase();
            return lower.startsWith(query) || lower.includes(' ' + query);
        });
    }

    function close() {
        if (!box) return;
        box.setAttribute('aria-expanded', 'false');
        box.removeAttribute('aria-activedescendant');
        box = null;
        if (list.matches(':popover-open')) list.hidePopover(); // not when it went with a page the chat swapped out
    }

    function showActive() {
        list.querySelectorAll('[role="option"]').forEach((option, i) => {
            option.setAttribute('aria-selected', String(i === active));
            if (i === active) {
                box.setAttribute('aria-activedescendant', option.id);
                option.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    // Above the box, or below it when there isn't room above (Edit near the top of a small screen).
    // Inside Edit's dialog while it's for that box, as everything outside an open modal dialog can't be clicked.
    function place(target) {
        const home = target.closest('dialog') ?? document.body;
        if (list.parentElement !== home) home.append(list);
        if (!list.matches(':popover-open')) list.showPopover();

        const rect = target.getBoundingClientRect();
        list.style.left = Math.max(8, Math.min(rect.left, innerWidth - list.offsetWidth - 8)) + 'px';
        if (rect.top >= list.offsetHeight + 12) {
            list.style.top = '';
            list.style.bottom = innerHeight - rect.top + 6 + 'px';
        } else {
            list.style.bottom = '';
            list.style.top = rect.bottom + 6 + 'px';
        }
    }

    function update(target) {
        const found = typing(target);
        choices = found ? people(target).filter((person) => matches(person, found.query)).slice(0, SHOWN) : [];
        if (choices.length === 0) {
            if (box === target) close();
            return;
        }

        if (box !== target) close();
        box = target;
        at = found.at;
        active = Math.min(active, choices.length - 1);
        list.replaceChildren(...choices.map((person, i) => {
            const option = document.createElement('li');
            option.className = 'mentions-option';
            option.id = 'mentions-option-' + i;
            option.setAttribute('role', 'option');
            option.dataset.mentionsIndex = String(i);
            const name = document.createElement('span');
            name.className = 'mentions-name';
            name.textContent = person.name;
            option.append(name);
            // A nickname shows who it really is
            if (person.userName !== person.name) {
                const userName = document.createElement('span');
                userName.className = 'mentions-username';
                userName.textContent = person.userName;
                option.append(userName);
            }
            return option;
        }));
        box.setAttribute('aria-expanded', 'true');
        place(box);
        showActive();
    }

    // "@userName " in place of what was typed, if it fits in the box
    function pick(person) {
        const target = box;
        const end = target.selectionStart;
        const text = '@' + person.userName + ' ';
        close();
        if (target.maxLength > 0 && target.value.length - (end - at) + text.length > target.maxLength) return;
        target.setRangeText(text, at, end, 'end');
        target.dispatchEvent(new Event('input', { bubbles: true })); // so the box grows like when typing, and Edit's Save wakes up
        close(); // the input event looked again, and "@name " could still start someone else's name
        target.focus();
    }

    // The box an event is for, or null when it isn't one
    function boxOf(event) {
        return event.target instanceof Element && event.target.matches('[data-mentions]') ? event.target : null;
    }

    // For screen readers: the box has a list of suggestions. Once per box, on the page or when it first gets focus.
    function setUp(target) {
        if (target.hasAttribute('aria-autocomplete')) return;
        target.setAttribute('aria-autocomplete', 'list');
        target.setAttribute('aria-controls', list.id);
        target.setAttribute('aria-expanded', 'false');
    }
    document.querySelectorAll('[data-mentions]').forEach(setUp);
    document.addEventListener('focusin', (event) => {
        const target = boxOf(event);
        if (target) setUp(target);
    });

    document.addEventListener('input', (event) => {
        const target = boxOf(event);
        if (!target) return;
        active = 0;
        update(target);
    });
    document.addEventListener('click', (event) => {
        const target = boxOf(event);
        if (target) update(target);
    });
    document.addEventListener('keyup', (event) => {
        const target = boxOf(event);
        if (target && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) update(target);
    });
    document.addEventListener('focusout', (event) => {
        const target = boxOf(event);
        if (target && box === target) close();
    });

    // Capturing, so it comes before the box's own Enter (sending the message) and Escape (cancelling a reply or Edit)
    document.addEventListener('keydown', (event) => {
        const target = boxOf(event);
        if (!target || box !== target || event.isComposing || event.ctrlKey || event.altKey || event.metaKey) return;
        if (event.key === 'ArrowDown') {
            active = (active + 1) % choices.length;
            showActive();
        } else if (event.key === 'ArrowUp') {
            active = (active - 1 + choices.length) % choices.length;
            showActive();
        } else if ((event.key === 'Enter' && !event.shiftKey) || (event.key === 'Tab' && !event.shiftKey)) {
            pick(choices[active]);
        } else if (event.key === 'Escape') {
            close();
        } else {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);

    // Pressing on the list doesn't take the focus out of the box, so it stays open for the click
    list.addEventListener('pointerdown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
        const option = event.target.closest('[data-mentions-index]');
        if (option && box) pick(choices[Number(option.dataset.mentionsIndex)]);
    });

    // It's placed by the box, so it follows when the page moves (the chat scrolling down to a new message, say)
    const follow = () => {
        if (box) place(box);
    };
    addEventListener('resize', follow);
    document.addEventListener('scroll', follow, { capture: true, passive: true });
})();
