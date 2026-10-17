// The Baccarat lobby (gambling/baccarat/): the user's invites and the public tables, from /gambling/baccarat/lobby,
// kept up to date every 5 seconds while the page is in view. Joining is a normal form post to the lobby, which checks
// everything again and goes to the table.
(() => {
    const root = document.querySelector('[data-bac-lobby]');
    if (!root) return;

    const POLL_MS = 5000;
    const roomList = root.querySelector('[data-bac-rooms]');
    const roomsEmpty = root.querySelector('[data-bac-rooms-empty]');
    const inviteList = root.querySelector('[data-bac-invites]');
    const invitesEmpty = root.querySelector('[data-bac-invites-empty]');
    let isSeated = root.dataset.seated === '1';

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

    // Built with textContent, as the names are typed in by users
    function roomItem(room, details) {
        const item = document.createElement('li');
        item.className = 'casino-room';

        const text = document.createElement('div');
        text.className = 'casino-room-text';
        const name = document.createElement('span');
        name.className = 'casino-room-name';
        name.textContent = room.name;
        const meta = document.createElement('span');
        meta.className = 'casino-room-meta';
        meta.textContent = details.join(' · ');
        text.append(name, meta);

        const isFull = room.players >= room.seats;
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
        const button = document.createElement('button');
        button.type = 'submit';
        button.className = 'casino-btn' + (isFull ? '' : ' casino-btn-primary');
        button.textContent = isFull ? 'Full' : 'Join';
        button.disabled = isFull || isSeated;
        if (isSeated && !isFull) button.title = 'Leave your table first';
        form.append(action, roomId, button);

        item.append(text, form);
        return item;
    }

    function players(room) {
        return room.players + '/' + room.seats + ' players';
    }

    function show(data) {
        isSeated = data.seat !== null;

        roomList.replaceChildren(...data.rooms.map((room) => roomItem(room, [
            players(room),
            'Min bet ' + formatChips(room.minBet),
            room.inHand ? 'In a hand' : 'Between hands',
            ...(room.host ? ['Host: ' + room.host] : []),
        ])));
        roomsEmpty.textContent = 'No public tables right now. Open one above!';
        roomsEmpty.hidden = data.rooms.length > 0;

        inviteList.replaceChildren(...data.invites.map((room) => roomItem(room, [
            ...(room.invitedBy ? ['From ' + room.invitedBy] : []),
            TYPE_NAMES[room.type] ?? 'Table',
            players(room),
            'Min bet ' + formatChips(room.minBet),
        ])));
        invitesEmpty.textContent = 'No invites right now. When the host of a table invites you, it shows up here.';
        invitesEmpty.hidden = data.invites.length > 0;
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
