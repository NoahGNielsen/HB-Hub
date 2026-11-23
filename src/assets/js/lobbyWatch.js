// "Games to watch" in a multiplayer game's lobby (assets/php/games/lobbyWatch.php): the public games being played that
// their host lets people watch (spectator mode, assets/php/games/spectators.php). The lobby's own script (chessLobby.js,
// unoLobby.js, matadorLobby.js, doodleLobby.js) asks for the lobby's lists, and hands this the rooms to watch with the
// lobby:watch event: detail {rooms: [{id, code, name, players: [names], tags: [short texts]}], seat: roomId|null}. Each
// room is a row like the lobby's others (lobby.css), with a link to watch it at the game page in data-lobby-watch,
// ?watch=code. Someone sitting in a game of the same kind can't watch one, so the button is off for them ("In a
// game"), except on their own game, which links back into it (?room=id).
(() => {
    const section = document.querySelector('[data-lobby-watch]');
    if (!section) return;

    const gameUrl = section.dataset.lobbyWatch;
    const list = section.querySelector('[data-lobby-watch-rooms]');
    const empty = section.querySelector('[data-lobby-watch-empty]');
    const count = section.querySelector('[data-lobby-watch-count]');

    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    // Built with textContent, as the names are typed in by users
    function roomItem(room, seat) {
        const item = element('li', 'lobby-room');

        const text = element('div', 'lobby-room-text');
        text.append(element('span', 'lobby-room-name', room.name),
            element('span', 'lobby-room-meta', room.players.join(', ')));

        const info = element('div', 'lobby-room-info');
        info.append(...room.tags.map((tag) => element('span', 'lobby-tag', tag)));

        // Your own game is a way back into it; while you sit in another one, the button says why it's off (a title
        // alone never shows on a phone, so it looked like a button that just didn't work)
        let watch;
        if (room.id === seat) {
            watch = element('a', 'casino-btn casino-btn-primary', 'Your game');
            watch.href = gameUrl + '?' + new URLSearchParams({ room: String(room.id) });
        } else if (seat !== null) {
            watch = element('button', 'casino-btn', 'In a game');
            watch.type = 'button';
            watch.disabled = true;
            watch.title = 'Leave your own game first to watch this one';
        } else {
            watch = element('a', 'casino-btn', 'Watch');
            watch.href = gameUrl + '?' + new URLSearchParams({ watch: room.code });
        }

        item.append(text, info, watch);
        return item;
    }

    document.addEventListener('lobby:watch', (event) => {
        const { rooms } = event.detail;
        const seat = event.detail.seat ?? null; // the id of the room you sit in, if any
        list.replaceChildren(...rooms.map((room) => roomItem(room, seat)));
        empty.textContent = 'No games to watch right now. A public game shows up here while it\'s played, if its host turns on spectator mode.';
        empty.hidden = rooms.length > 0;
        count.textContent = rooms.length > 0 ? String(rooms.length) : '';
    });
})();
