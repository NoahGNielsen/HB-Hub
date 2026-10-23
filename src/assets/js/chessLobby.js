// The chess lobby (games/chess/): the user's invites and the public games, from /games/chess/lobby, kept up to date
// every 5 seconds while the page is in view. Joining is a normal form post to the lobby, which checks everything again
// and goes to the game. Also asks before the user leaves a game that's being played, as that loses it. Each game is a
// row (lobby.css): its name and who it's against, the clock and the colour the user would play, and the button.
(() => {
    const root = document.querySelector('[data-ch-lobby]');
    if (!root) return;

    const POLL_MS = 5000;
    const roomList = root.querySelector('[data-ch-rooms]');
    const roomsEmpty = root.querySelector('[data-ch-rooms-empty]');
    const roomsCount = root.querySelector('[data-ch-rooms-count]');
    const inviteList = root.querySelector('[data-ch-invites]');
    const invitesEmpty = root.querySelector('[data-ch-invites-empty]');
    const invitesCount = root.querySelector('[data-ch-invites-count]');
    const inviteSection = inviteList.closest('.lobby-invites');
    let isSeated = root.dataset.seated === '1';
    let isFirstShow = true;

    const TYPE_NAMES = { public: 'Public', code: 'With a code', invite: 'Invite-only' };
    // What the one joining plays, from the colour the host chose
    const YOU_PLAY = { random: 'Random colours', white: 'You play Black', black: 'You play White' };

    async function load() {
        try {
            const response = await fetch('/games/chess/lobby', { headers: { Accept: 'application/json' } });
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

    // Built with textContent, as the names are typed in by users. details is the line under the name, tags the clock
    // and the colours
    function roomItem(room, details, isInvite) {
        const item = element('li', 'lobby-room' + (isInvite ? ' is-invite' : ''));

        const text = element('div', 'lobby-room-text');
        text.append(element('span', 'lobby-room-name', room.name),
            element('span', 'lobby-room-meta', details.filter(Boolean).join(' · ')));

        const info = element('div', 'lobby-room-info');
        info.append(...[room.clock, YOU_PLAY[room.colour]].filter(Boolean).map((tag) => element('span', 'lobby-tag', tag)));

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
        const button = element('button', 'casino-btn casino-btn-primary', 'Play');
        button.type = 'submit';
        button.disabled = isSeated;
        if (isSeated) button.title = 'Leave your game first';
        form.append(action, roomId, button);

        item.append(text, info, form);
        return item;
    }

    function show(data) {
        isSeated = data.seat !== null;

        roomList.replaceChildren(...data.rooms.map((room) => roomItem(room, [
            room.host ? 'Against ' + room.host : null,
        ], false)));
        roomsEmpty.textContent = 'No public games waiting for a player right now. Open one, and it shows up here for everyone.';
        roomsEmpty.hidden = data.rooms.length > 0;
        roomsCount.textContent = data.rooms.length > 0 ? String(data.rooms.length) : '';

        inviteList.replaceChildren(...data.invites.map((room) => roomItem(room, [
            room.invitedBy ? 'From ' + room.invitedBy : null,
            TYPE_NAMES[room.type] ?? 'Game',
        ], true)));
        invitesEmpty.textContent = 'No invites right now. When someone invites you to a game, it shows up here.';
        invitesEmpty.hidden = data.invites.length > 0;
        invitesCount.textContent = data.invites.length > 0 ? String(data.invites.length) : '';
        inviteSection.classList.toggle('is-empty', data.invites.length === 0); // goes under the public games

        // Coming from an invite's notification (#invites): the invites have just moved above the games
        if (isFirstShow && location.hash === '#invites') inviteSection.scrollIntoView();
        isFirstShow = false;
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

    // Leaving a game that's being played is asked first (the form only has data-ch-leave-form then)
    const leaveForm = root.querySelector('[data-ch-leave-form]');
    const leaveDialog = root.querySelector('[data-ch-leave-dialog]');
    if (leaveForm && leaveDialog) {
        leaveForm.addEventListener('submit', (event) => {
            if (leaveForm.dataset.confirmed) return;
            event.preventDefault();
            leaveDialog.showModal();
            leaveDialog.querySelector('[data-ch-leave-cancel]').focus(); // the safe choice is the one Enter picks
        });
        leaveDialog.querySelector('[data-ch-leave-cancel]').addEventListener('click', () => leaveDialog.close());
        leaveDialog.querySelector('[data-ch-leave-confirm]').addEventListener('click', () => {
            leaveDialog.close();
            leaveForm.dataset.confirmed = '1';
            leaveForm.requestSubmit();
        });
    }

    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', poll); // catch up when coming back to the tab
    poll();
})();
