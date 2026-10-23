// The Baccarat lobby (gambling/baccarat/): the user's invites and the public tables, from /gambling/baccarat/lobby,
// kept up to date every 5 seconds while the page is in view. Joining is a normal form post to the lobby, which checks
// everything again and goes to the table. Each table is a row (lobby.css): its name and host, its seats as dots, the
// minimum bet, whether a hand is being played, and the button.
(() => {
    const root = document.querySelector('[data-bac-lobby]');
    if (!root) return;

    const POLL_MS = 5000;
    const roomList = root.querySelector('[data-bac-rooms]');
    const roomsEmpty = root.querySelector('[data-bac-rooms-empty]');
    const roomsCount = root.querySelector('[data-bac-rooms-count]');
    const inviteList = root.querySelector('[data-bac-invites]');
    const invitesEmpty = root.querySelector('[data-bac-invites-empty]');
    const invitesCount = root.querySelector('[data-bac-invites-count]');
    const inviteSection = inviteList.closest('.lobby-invites');
    let isSeated = root.dataset.seated === '1';
    let isFirstShow = true;

    const formatChips = (amount) => amount.toLocaleString('en-US');
    const TYPE_NAMES = { public: 'Public', code: 'With a code', invite: 'Invite-only' };

    async function load() {
        try {
            const response = await fetch('/gambling/baccarat/lobby', { headers: { Accept: 'application/json' } });
            if (response.status === 403) {
                location.reload(); // blocked in settings since: the page says so
                return null;
            }
            return response.ok ? await response.json() : null;
        } catch {
            return null; // offline for a moment
        }
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    // The seats as dots, filled for the ones taken, then "3/5"
    function seats(room) {
        const wrap = element('span', 'lobby-seats');
        wrap.title = room.players + ' of ' + room.seats + ' seats taken';
        const dots = element('span', 'lobby-seat-dots');
        dots.setAttribute('aria-hidden', 'true');
        for (let seat = 0; seat < room.seats; seat++) {
            dots.append(element('span', 'lobby-seat' + (seat < room.players ? ' is-taken' : '')));
        }
        wrap.append(dots, element('span', '', room.players + '/' + room.seats + ' players'));
        return wrap;
    }

    function minBet(room) {
        const tag = element('span', 'lobby-tag', 'Min bet ' + formatChips(room.minBet));
        const chip = document.createElement('img');
        chip.src = '/assets/images/icons/chip.svg';
        chip.alt = '';
        tag.prepend(chip);
        return tag;
    }

    // Built with textContent, as the names are typed in by users. details is the line under the name, status
    // {text, isBusy} or null
    function roomItem(room, details, status, isInvite) {
        const isFull = room.players >= room.seats;
        const item = element('li', 'lobby-room' + (isInvite ? ' is-invite' : '') + (isFull ? ' is-full' : ''));

        const text = element('div', 'lobby-room-text');
        text.append(element('span', 'lobby-room-name', room.name), element('span', 'lobby-room-meta', details.join(' · ')));

        const info = element('div', 'lobby-room-info');
        info.append(seats(room), minBet(room));
        if (status) info.append(element('span', 'lobby-status' + (status.isBusy ? ' is-busy' : ''), status.text));

        const form = document.createElement('form');
        form.method = 'post';
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'join';
        const roomId = document.createElement('input');
        roomId.type = 'hidden';
        roomId.name = 'room';
        roomId.value = String(room.id);
        const button = element('button', 'casino-btn' + (isFull ? '' : ' casino-btn-primary'), isFull ? 'Full' : 'Join');
        button.type = 'submit';
        button.disabled = isFull || isSeated;
        if (isSeated && !isFull) button.title = 'Leave your table first';
        form.append(action, roomId, button);

        item.append(text, info, form);
        return item;
    }

    function show(data) {
        isSeated = data.seat !== null;

        roomList.replaceChildren(...data.rooms.map((room) => roomItem(room,
            room.host ? ['Host: ' + room.host] : [],
            room.inHand ? { text: 'In a hand', isBusy: true } : { text: 'Between hands', isBusy: false },
            false)));
        roomsEmpty.textContent = 'No public tables right now. Open one, and it shows up here for everyone.';
        roomsEmpty.hidden = data.rooms.length > 0;
        roomsCount.textContent = data.rooms.length > 0 ? String(data.rooms.length) : '';

        inviteList.replaceChildren(...data.invites.map((room) => roomItem(room, [
            ...(room.invitedBy ? ['From ' + room.invitedBy] : []),
            TYPE_NAMES[room.type] ?? 'Table',
        ], null, true)));
        invitesEmpty.textContent = 'No invites right now. When the host of a table invites you, it shows up here.';
        invitesEmpty.hidden = data.invites.length > 0;
        invitesCount.textContent = data.invites.length > 0 ? String(data.invites.length) : '';
        inviteSection.classList.toggle('is-empty', data.invites.length === 0); // goes under the public tables

        // Coming from an invite's notification (#invites): the invites have just moved above the tables
        if (isFirstShow && location.hash === '#invites') inviteSection.scrollIntoView();
        isFirstShow = false;
    }

    async function poll() {
        if (document.visibilityState !== 'visible') return;
        const data = await load();
        if (data !== null) show(data);
    }

    // The join code is shown in capitals, the way it's written at the table
    const codeInput = root.querySelector('#lobby-code');
    codeInput?.addEventListener('input', () => {
        const position = codeInput.selectionStart;
        codeInput.value = codeInput.value.toUpperCase();
        codeInput.setSelectionRange(position, position);
    });

    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', poll); // catch up when coming back to the tab
    poll();
})();
