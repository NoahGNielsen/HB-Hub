// The Doodle Chain lobby (games/doodle/): the public games, from /games/doodle/lobby, kept up to date every 5 seconds
// while the page is in view. Joining is a normal form post to the lobby, which checks everything again and goes to the
// game. Each game is a row (lobby.css): its name and host, its seats as dots, the time to draw, and the button.
(() => {
    const root = document.querySelector('[data-doodle-lobby]');
    if (!root) return;

    const POLL_MS = 5000;
    const roomList = root.querySelector('[data-doodle-rooms]');
    const roomsEmpty = root.querySelector('[data-doodle-rooms-empty]');
    const roomsCount = root.querySelector('[data-doodle-rooms-count]');
    let isSeated = root.dataset.seated === '1';

    async function load() {
        const ride = { params: null };
        document.dispatchEvent(new CustomEvent('nav:ride', { detail: ride })); // the nav bar's request comes along (navUpdates.js)
        const query = ride.params !== null ? '?' + new URLSearchParams({ nav: ride.params }) : '';
        try {
            const response = await fetch('/games/doodle/lobby' + query, { headers: { Accept: 'application/json' } });
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

    // The seats as dots, filled for the ones taken, then "3/10"
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

    // Built with textContent, as the names are typed in by users
    function roomItem(room) {
        const item = element('li', 'lobby-room');

        const text = element('div', 'lobby-room-text');
        text.append(element('span', 'lobby-room-name', room.name),
            element('span', 'lobby-room-meta', room.host ? 'Host: ' + room.host : ''));

        const info = element('div', 'lobby-room-info');
        info.append(seats(room));
        if (room.settings) info.append(element('span', 'lobby-tag', room.settings));

        const form = document.createElement('form');
        form.method = 'post';
        for (const [name, value] of [['action', 'join'], ['room', String(room.id)]]) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.append(input);
        }
        const button = element('button', 'casino-btn casino-btn-primary', 'Play');
        button.type = 'submit';
        button.disabled = isSeated;
        if (isSeated) button.title = 'Leave your game first';
        form.append(button);

        item.append(text, info, form);
        return item;
    }

    function show(data) {
        isSeated = data.seat !== null;
        roomList.replaceChildren(...data.rooms.map(roomItem));
        roomsEmpty.textContent = 'No public games waiting for players right now. Open one, and it shows up here for everyone.';
        roomsEmpty.hidden = data.rooms.length > 0;
        roomsCount.textContent = data.rooms.length > 0 ? String(data.rooms.length) : '';
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

    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', poll); // catch up when coming back to the tab
    poll();
})();
