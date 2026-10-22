// The UNO lobby (games/uno/): the user's invites and the public games, from /games/uno/lobby, kept up to date every 5
// seconds while the page is in view. Joining is a normal form post to the lobby, which checks everything again and goes
// to the game. Also asks before the user leaves a game that's being played, as that counts as a loss.
(() => {
    const root = document.querySelector('[data-uno-lobby]');
    if (!root) return;

    const POLL_MS = 5000;
    const roomList = root.querySelector('[data-uno-rooms]');
    const roomsEmpty = root.querySelector('[data-uno-rooms-empty]');
    const inviteList = root.querySelector('[data-uno-invites]');
    const invitesEmpty = root.querySelector('[data-uno-invites-empty]');
    let isSeated = root.dataset.seated === '1';

    const TYPE_NAMES = { public: 'Public', code: 'With a code', invite: 'Invite-only' };
    const players = (room) => room.players + ' of ' + room.seats + ' players';

    async function load() {
        try {
            const response = await fetch('/games/uno/lobby', { headers: { Accept: 'application/json' } });
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
        meta.textContent = details.filter(Boolean).join(' · ');
        text.append(name, meta);

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
        button.className = 'casino-btn casino-btn-primary';
        button.textContent = 'Play';
        button.disabled = isSeated;
        if (isSeated) button.title = 'Leave your game first';
        form.append(action, roomId, button);

        item.append(text, form);
        return item;
    }

    function show(data) {
        isSeated = data.seat !== null;

        roomList.replaceChildren(...data.rooms.map((room) => roomItem(room, [
            room.host ? 'Host: ' + room.host : null,
            players(room),
            room.settings,
        ])));
        roomsEmpty.textContent = 'No public games waiting for players right now. Open one above!';
        roomsEmpty.hidden = data.rooms.length > 0;

        inviteList.replaceChildren(...data.invites.map((room) => roomItem(room, [
            room.invitedBy ? 'From ' + room.invitedBy : null,
            TYPE_NAMES[room.type] ?? 'Game',
            players(room),
            room.settings,
        ])));
        invitesEmpty.textContent = 'No invites right now. When someone invites you to a game, it shows up here.';
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

    // Leaving a game that's being played is asked first (the form only has data-uno-leave-form then)
    const leaveForm = root.querySelector('[data-uno-leave-form]');
    const leaveDialog = root.querySelector('[data-uno-leave-dialog]');
    if (leaveForm && leaveDialog) {
        leaveForm.addEventListener('submit', (event) => {
            if (leaveForm.dataset.confirmed) return;
            event.preventDefault();
            leaveDialog.showModal();
            leaveDialog.querySelector('[data-uno-leave-cancel]').focus(); // the safe choice is the one Enter picks
        });
        leaveDialog.querySelector('[data-uno-leave-cancel]').addEventListener('click', () => leaveDialog.close());
        leaveDialog.querySelector('[data-uno-leave-confirm]').addEventListener('click', () => {
            leaveDialog.close();
            leaveForm.dataset.confirmed = '1';
            leaveForm.requestSubmit();
        });
    }

    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', poll); // catch up when coming back to the tab
    poll();
})();
