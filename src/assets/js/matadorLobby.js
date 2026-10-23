// The Matador lobby (games/matador/): the user's invites and the public games, from /games/matador/lobby, kept up to
// date every 5 seconds while the page is in view. Joining is a normal form post to the lobby, which checks everything
// again and goes to the game. Also asks before the saved game is deleted. Each game is a row (lobby.css): its name and
// host, its seats as dots, whether it's being played, and the button.
(() => {
    const root = document.querySelector('[data-mt-lobby]');
    if (!root) return;

    const POLL_MS = 5000;
    const roomList = root.querySelector('[data-mt-rooms]');
    const roomsEmpty = root.querySelector('[data-mt-rooms-empty]');
    const roomsCount = root.querySelector('[data-mt-rooms-count]');
    const inviteList = root.querySelector('[data-mt-invites]');
    const invitesEmpty = root.querySelector('[data-mt-invites-empty]');
    const invitesCount = root.querySelector('[data-mt-invites-count]');
    const inviteSection = inviteList.closest('.lobby-invites');
    let isSeated = root.dataset.seated === '1';
    let isFirstShow = true;

    const TYPE_NAMES = { public: 'Public', code: 'With a code', invite: 'Invite-only' };

    async function load() {
        const ride = { params: null };
        document.dispatchEvent(new CustomEvent('nav:ride', { detail: ride })); // the nav bar's request comes along (navUpdates.js)
        const query = ride.params !== null ? '?' + new URLSearchParams({ nav: ride.params }) : '';
        try {
            const response = await fetch('/games/matador/lobby' + query, { headers: { Accept: 'application/json' } });
            if (response.status === 403) {
                location.reload(); // blocked in settings since: the page says so
                return null;
            }
            if (!response.ok) return null;
            const data = await response.json();
            if (data.nav) document.dispatchEvent(new CustomEvent('nav:update', { detail: data.nav }));
            return data;
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

    // The seats as dots, filled for the ones taken, then "3/4"
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

    // Built with textContent, as the names are typed in by users. details is the line under the name, status
    // {text, isBusy} or null. A seat kept for the user (room.seat) can be taken even when the game looks full.
    function roomItem(room, details, status, buttonText, isInvite) {
        const isFull = room.seat === null && room.players >= room.seats;
        const item = element('li', 'lobby-room' + (isInvite ? ' is-invite' : '') + (isFull ? ' is-full' : ''));

        const text = element('div', 'lobby-room-text');
        text.append(element('span', 'lobby-room-name', room.name), element('span', 'lobby-room-meta', details.join(' · ')));

        const info = element('div', 'lobby-room-info');
        info.append(seats(room));
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
        const button = element('button', 'casino-btn' + (isFull ? '' : ' casino-btn-primary'), isFull ? 'Full' : buttonText);
        button.type = 'submit';
        button.disabled = isFull || isSeated;
        if (isSeated && !isFull) button.title = 'Leave your game first';
        form.append(action, roomId, button);

        item.append(text, info, form);
        return item;
    }

    function show(data) {
        isSeated = data.seat !== null;

        roomList.replaceChildren(...data.rooms.map((room) => roomItem({ ...room, seat: null },
            room.host ? ['Host: ' + room.host] : [],
            null, 'Join', false)));
        roomsEmpty.textContent = 'No public games waiting for players right now. Open one, and it shows up here for everyone.';
        roomsEmpty.hidden = data.rooms.length > 0;
        roomsCount.textContent = data.rooms.length > 0 ? String(data.rooms.length) : '';

        inviteList.replaceChildren(...data.invites.map((room) => roomItem(room, [
            ...(room.seat !== null ? [room.invitedBy ? 'A seat from ' + room.invitedBy : 'Your seat is kept for you'] : []),
            ...(room.seat === null && room.invitedBy ? ['From ' + room.invitedBy] : []),
            ...(room.phase === 'lobby' ? [TYPE_NAMES[room.type] ?? 'Game'] : []),
        ], room.phase === 'lobby' ? { text: 'Waiting for players', isBusy: false } : { text: 'Being played', isBusy: true },
        room.seat !== null ? 'Take the seat' : 'Join', true)));
        invitesEmpty.textContent = 'No invites right now. When the host of a game invites you, it shows up here.';
        invitesEmpty.hidden = data.invites.length > 0;
        invitesCount.textContent = data.invites.length > 0 ? String(data.invites.length) : '';
        inviteSection.classList.toggle('is-empty', data.invites.length === 0); // goes under the public games

        // Coming from an invite's notification (#invites): the invites have just moved above the games
        if (isFirstShow && location.hash === '#invites') inviteSection.scrollIntoView();
        isFirstShow = false;
    }

    let isLoading = false; // one at a time, so a slow answer doesn't pile up more requests behind it

    async function poll() {
        if (document.visibilityState !== 'visible' || isLoading) return;
        isLoading = true;
        const data = await load();
        isLoading = false;
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
