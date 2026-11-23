// "Games to watch" in a multiplayer game's lobby (assets/php/games/lobbyWatch.php): the public games being played that
// their host lets people watch (spectator mode, assets/php/games/spectators.php). The lobby's own script (chessLobby.js,
// unoLobby.js, matadorLobby.js, doodleLobby.js) asks for the lobby's lists, and hands this the rooms to watch with the
// lobby:watch event: detail {rooms: [{code, name, players: [names], tags: [short texts]}], isSeated}. Each room is a row
// like the lobby's others (lobby.css), with a link to watch it at the game page in data-lobby-watch, ?watch=code.
// Someone sitting in a game of the same kind can't watch one, so the button is off for them.
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
    function roomItem(room, isSeated) {
        const item = element('li', 'lobby-room');

        const text = element('div', 'lobby-room-text');
        text.append(element('span', 'lobby-room-name', room.name),
            element('span', 'lobby-room-meta', room.players.join(', ')));

        const info = element('div', 'lobby-room-info');
        info.append(...room.tags.map((tag) => element('span', 'lobby-tag', tag)));

        let watch;
        if (isSeated) {
            watch = element('button', 'casino-btn', 'Watch');
            watch.type = 'button';
            watch.disabled = true;
            watch.title = 'Leave your game first';
        } else {
            watch = element('a', 'casino-btn', 'Watch');
            watch.href = gameUrl + '?' + new URLSearchParams({ watch: room.code });
        }

        item.append(text, info, watch);
        return item;
    }

    document.addEventListener('lobby:watch', (event) => {
        const { rooms, isSeated } = event.detail;
        list.replaceChildren(...rooms.map((room) => roomItem(room, isSeated)));
        empty.textContent = 'No games to watch right now. A public game shows up here while it\'s played, if its host turns on spectator mode.';
        empty.hidden = rooms.length > 0;
        count.textContent = rooms.length > 0 ? String(rooms.length) : '';
    });
})();
