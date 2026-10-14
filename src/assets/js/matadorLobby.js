// The Matador lobby (games/matador/): the user's invites and the public games, from /games/matador/lobby, kept up to
// date every 5 seconds while the page is in view. Joining is a normal form post to the lobby, which checks everything
// again and goes to the game. Also asks before the saved game is deleted.
(() => {
    const root = document.querySelector('[data-mt-lobby]');
    if (!root) return;

    const POLL_MS = 5000;
    const roomList = root.querySelector('[data-mt-rooms]');
    const roomsEmpty = root.querySelector('[data-mt-rooms-empty]');
    const inviteList = root.querySelector('[data-mt-invites]');
    const invitesEmpty = root.querySelector('[data-mt-invites-empty]');
    let isSeated = root.dataset.seated === '1';

    const TYPE_NAMES = { public: 'Public', code: 'With a code', invite: 'Invite-only' };

    async function load() {
        try {
            const response = await fetch('/games/matador/lobby', { headers: { Accept: 'application/json' } });
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
    function roomItem(room, details, buttonText) {
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

        const isFull = room.seat === null && room.players >= room.seats;
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
        button.textContent = isFull ? 'Full' : buttonText;
        button.disabled = isFull || isSeated;
        if (isSeated && !isFull) button.title = 'Leave your game first';
        form.append(action, roomId, button);

        item.append(text, form);
        return item;
    }

    function players(room) {
        return room.players + '/' + room.seats + ' players';
    }

    function show(data) {
        isSeated = data.seat !== null;

        roomList.replaceChildren(...data.rooms.map((room) => roomItem({ ...room, seat: null }, [
            players(room),
            ...(room.host ? ['Host: ' + room.host] : []),
        ], 'Join')));
        roomsEmpty.textContent = 'No public games waiting for players right now. Open one above!';
        roomsEmpty.hidden = data.rooms.length > 0;

        inviteList.replaceChildren(...data.invites.map((room) => roomItem(room, [
            ...(room.seat !== null ? [room.invitedBy ? 'A seat from ' + room.invitedBy : 'Your seat is kept for you'] : []),
            ...(room.seat === null && room.invitedBy ? ['From ' + room.invitedBy] : []),
            room.phase === 'lobby' ? (TYPE_NAMES[room.type] ?? 'Game') : 'Being played',
            players(room),
        ], room.seat !== null ? 'Take the seat' : 'Join')));
        invitesEmpty.textContent = 'No invites right now. When the host of a game invites you, it shows up here.';
        invitesEmpty.hidden = data.invites.length > 0;
    }

    async function poll() {
        if (document.visibilityState !== 'visible') return;
        const data = await load();
        if (data !== null) show(data);
    }

    // The join code is shown in capitals, the way it's written in the game
    const codeInput = root.querySelector('#lobby-code');
    codeInput?.addEventListener('input', () => {
        const position = codeInput.selectionStart;
        codeInput.value = codeInput.value.toUpperCase();
        codeInput.setSelectionRange(position, position);
    });

    // Deleting the saved game is asked first
    const deleteForm = root.querySelector('[data-mt-delete-save]');
    const deleteDialog = root.querySelector('[data-mt-delete-dialog]');
    if (deleteForm && deleteDialog) {
        deleteForm.addEventListener('submit', (event) => {
            if (deleteForm.dataset.confirmed) return;
            event.preventDefault();
            deleteDialog.showModal();
            deleteDialog.querySelector('[data-mt-delete-cancel]').focus(); // the safe choice is the one Enter picks
        });
        deleteDialog.querySelector('[data-mt-delete-cancel]').addEventListener('click', () => deleteDialog.close());
        deleteDialog.querySelector('[data-mt-delete-confirm]').addEventListener('click', () => {
            deleteDialog.close();
            deleteForm.dataset.confirmed = '1';
            deleteForm.requestSubmit();
        });
    }

    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', poll); // catch up when coming back to the tab
    poll();
})();
