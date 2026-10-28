// Wordle (games/wordle/): the board, the on-screen keyboard, the stats and the result. The page gives the game in
// data-game ({guesses: [{word, score: ['correct' | 'present' | 'absent', ...]}], result: 'playing' | 'won' | 'lost',
// answer: the word once it's over, else null}) and the stats in data-stats (played, wins, winRate, averageGuesses,
// distribution {1..6: wins}, currentStreak, longestStreak). Typing works with the keyboard or by tapping the keys;
// Enter posts the guess to /games/wordle/guess (data-api), which checks it and answers with the game and stats the
// same way. A new guess flips its tiles over one by one (not when the device asks for less motion).
//
// The guest Wordle at /play/wordle (data-guest, see assets/php/guestGames/guestGames.php) works the same, but a guest's
// game is only kept in this browser: each answer from /play/wordle/guess has the game as a sealed token, kept in
// localStorage (GUEST_KEY) and sent along with the next guess. The page starts on an empty board and loads the game
// from the tokens (action=view), with how far the guest is in each language for the tabs. Guests have no stats; a
// finished game asks them to sign up (the guest:prompt event, guestPrompt.js). On the members' page, tokens found from
// today are sent once to be brought over to the account (action=import), as that's where logging in or signing up
// from the guest page leads.
(() => {
    const root = document.querySelector('[data-wordle]');
    if (!root) return;

    const API = root.dataset.api;
    const IS_GUEST = 'guest' in root.dataset;
    const GUEST_KEY = 'hbHubGuestWordle'; // a guest's games of today: {date, en: token, da: token}
    const LANGUAGE = root.dataset.lang;
    const LANGUAGE_NAME = root.dataset.languageName;
    const DATE = root.dataset.date;
    const NEXT_WORD_AT = Number(root.dataset.next);
    const ALPHABET = [...root.dataset.alphabet];
    const LENGTH = Number(root.dataset.length);
    const FLIP_MS = 250; // between two tiles turning, as in wordle.css
    const STATUS_RANK = { absent: 1, present: 2, correct: 3 }; // a key shows the best a letter has done
    const STATUS_WORDS = { correct: 'right place', present: 'wrong place', absent: 'not in the word' };
    const RESULT_STATUS = { playing: ['playing', 'Playing'], won: ['won', 'Solved'], lost: ['lost', 'Missed'] };
    const STATUS_NAMES = { new: 'New word', playing: 'Playing', won: 'Solved', lost: 'Missed' }; // the tabs, like the page's WORDLE_STATUS_NAMES
    const WIN_WORDS = ['Genius!', 'Magnificent!', 'Impressive!', 'Splendid!', 'Great!', 'Phew!'];

    const rows = [...root.querySelectorAll('.wordle-row')];
    const keys = new Map([...root.querySelectorAll('[data-key]')].map((key) => [key.dataset.key, key]));
    const message = root.querySelector('[data-wordle-message]');
    const result = root.querySelector('[data-wordle-result]');
    const resultTitle = root.querySelector('[data-wordle-result-title]');
    const resultText = root.querySelector('[data-wordle-result-text]');
    const nextWord = root.querySelector('[data-wordle-next]');
    const shareButton = root.querySelector('[data-wordle-share]');
    const tabStatus = root.querySelector('[data-wordle-status]');
    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');

    let game = JSON.parse(root.dataset.game);
    let typed = []; // the letters of the guess being typed
    let isBusy = false; // a guess is on its way, or its tiles are still turning
    let messageTimer = 0;

    // A guest's tokens from today, {date, en: token, da: token}, or just the date when there are none (or only older ones)
    function guestTokens() {
        try {
            const saved = JSON.parse(localStorage.getItem(GUEST_KEY));
            if (saved?.date === DATE) return saved;
        } catch {
            // no storage, or something broken in it
        }
        return { date: DATE };
    }

    function keepGuestToken(token) {
        try {
            localStorage.setItem(GUEST_KEY, JSON.stringify({ ...guestTokens(), [LANGUAGE]: token }));
        } catch {
            // no storage: the game only lasts as long as the page
        }
    }

    function say(text, keepFor = 2000) {
        clearTimeout(messageTimer);
        message.textContent = text;
        if (keepFor) messageTimer = setTimeout(() => { message.textContent = ''; }, keepFor);
    }

    // One row of the board: a guess with its colours, the letters being typed, or empty
    function drawRow(index, guess = null, flip = false) {
        const row = rows[index];
        const letters = guess ? [...guess.word] : (index === game.guesses.length ? typed : []);
        [...row.children].forEach((tile, position) => {
            tile.textContent = letters[position] ?? '';
            tile.className = 'wordle-tile';
            if (letters[position]) tile.classList.add('is-filled');
            if (guess) tile.classList.add('is-' + guess.score[position]);
            if (guess && flip) tile.classList.add('is-flipping');
        });
        row.setAttribute('aria-label', 'Guess ' + (index + 1) + ': ' + (guess
            ? guess.word.toUpperCase() + ', ' + letters.map((letter, position) =>
                letter.toUpperCase() + ' ' + STATUS_WORDS[guess.score[position]]).join(', ')
            : letters.length ? letters.join('').toUpperCase() + ' (not sent yet)' : 'empty'));
    }

    function drawKeys() {
        const best = new Map();
        for (const guess of game.guesses) {
            [...guess.word].forEach((letter, position) => {
                const status = guess.score[position];
                if ((STATUS_RANK[status] ?? 0) > (STATUS_RANK[best.get(letter)] ?? 0)) best.set(letter, status);
            });
        }
        for (const [letter, key] of keys) {
            if (letter.length !== 1) continue; // Enter and Delete
            key.className = 'wordle-key' + (best.has(letter) ? ' is-' + best.get(letter) : '');
            key.setAttribute('aria-label', letter.toUpperCase() + (best.has(letter) ? ', ' + STATUS_WORDS[best.get(letter)] : ''));
        }
    }

    function drawBoard(flipRow = -1) {
        rows.forEach((row, index) => drawRow(index, game.guesses[index] ?? null, index === flipRow));
    }

    function drawStats(stats) {
        const set = (name, value) => { root.querySelector('[data-wordle-stat="' + name + '"]').textContent = value; };
        set('played', stats.played);
        set('winRate', stats.winRate === null ? '–' : Math.round(stats.winRate * 100));
        set('currentStreak', stats.currentStreak);
        set('longestStreak', stats.longestStreak);

        const most = Math.max(1, ...Object.values(stats.distribution));
        const lastTries = game.result === 'won' ? game.guesses.length : 0;
        root.querySelectorAll('[data-wordle-distribution] li').forEach((item, index) => {
            const count = stats.distribution[index + 1] ?? 0;
            item.querySelector('.wordle-distribution-bar').style.transform = 'scaleX(' + count / most + ')';
            item.querySelector('.wordle-distribution-count').textContent = count;
            item.classList.toggle('is-today', lastTries === index + 1);
        });
    }

    function drawResult() {
        const [statusClass, statusName] = RESULT_STATUS[game.result];
        if (tabStatus) {
            tabStatus.className = 'wordle-language-status is-' + statusClass;
            tabStatus.textContent = statusName;
        }
        result.hidden = game.result === 'playing';
        if (result.hidden) return;

        const answer = game.answer.toUpperCase();
        if (game.result === 'won') {
            resultTitle.textContent = WIN_WORDS[game.guesses.length - 1] ?? 'You got it!';
            resultText.textContent = 'You guessed ' + answer + ' in ' + game.guesses.length
                + (game.guesses.length === 1 ? ' try.' : ' tries.');
        } else {
            resultTitle.textContent = 'So close';
            resultText.textContent = 'The word was ' + answer + '. Better luck tomorrow!';
        }
        drawCountdown();
    }

    function drawCountdown() {
        if (result.hidden) return;
        const left = NEXT_WORD_AT - Date.now();
        if (left <= 0) {
            nextWord.replaceChildren('A new word is ready! ');
            const link = document.createElement('a');
            link.href = '?lang=' + LANGUAGE;
            link.textContent = 'Play it';
            nextWord.append(link);
            return;
        }
        const seconds = Math.floor(left / 1000);
        const parts = [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60];
        nextWord.textContent = 'Next word in ' + parts.map((part) => String(part).padStart(2, '0')).join(':');
    }

    function shake() {
        const row = rows[game.guesses.length];
        row.classList.remove('is-shaking');
        void row.offsetWidth; // so it shakes again when it's already shaking
        row.classList.add('is-shaking');
    }

    async function send() {
        if (typed.length < LENGTH) {
            shake();
            say('Not enough letters');
            return;
        }
        isBusy = true;
        const before = game.guesses.length;
        const body = new URLSearchParams({ action: 'guess', lang: LANGUAGE, date: DATE, turn: String(before), word: typed.join('') });
        if (IS_GUEST) body.set('token', guestTokens()[LANGUAGE] ?? '');
        let response;
        try {
            response = await fetch(API, { method: 'POST', headers: { Accept: 'application/json' }, body });
        } catch {
            isBusy = false;
            say('Couldn\'t send your guess. Check your connection and try again.');
            return;
        }

        if (response.status === 403) {
            location.reload(); // blocked in settings since: the page says so
            return;
        }
        const data = await response.json().catch(() => ({}));
        if (data.error === 'notWord') {
            isBusy = false;
            shake();
            say('Not in the word list');
            return;
        }
        if (data.error === 'newDay') {
            say('It\'s a new day! Getting the new word…', 0);
            setTimeout(() => location.reload(), 1500);
            return;
        }
        if (!response.ok || !data.game) {
            isBusy = false;
            say('Something went wrong. Please try again.');
            return;
        }

        game = data.game;
        typed = [];
        if (IS_GUEST && data.token) keepGuestToken(data.token);
        const isNewGuess = game.guesses.length === before + 1;
        const animate = isNewGuess && !reduceMotion.matches;
        drawBoard(animate ? before : -1);
        const done = () => {
            isBusy = false;
            drawKeys();
            if (data.stats) drawStats(data.stats);
            drawResult();
            if (!isNewGuess) say('Your game was updated from another tab.');
            else if (game.result !== 'playing') say(resultTitle.textContent + ' ' + resultText.textContent, 0);
            if (IS_GUEST && isNewGuess && game.result !== 'playing') document.dispatchEvent(new CustomEvent('guest:prompt'));
        };
        if (animate) setTimeout(done, FLIP_MS * (LENGTH - 1) + 500);
        else done();
    }

    function press(key) {
        if (isBusy || game.result !== 'playing') return;
        if (key === 'Enter') {
            send();
        } else if (key === 'Backspace') {
            typed.pop();
        } else if (typed.length < LENGTH && ALPHABET.includes(key)) {
            typed.push(key);
        } else {
            return;
        }
        drawRow(game.guesses.length);
    }

    root.querySelector('[data-wordle-keyboard]').addEventListener('click', (event) => {
        const key = event.target.closest('[data-key]');
        if (key) press(key.dataset.key);
    });

    document.addEventListener('keydown', (event) => {
        if (event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return;
        // Leave typing in fields, and Enter on links and other buttons, alone. On the on-screen keys Enter is handled
        // here instead, so it doesn't also click the key that has focus.
        const target = event.target;
        if (target.closest('input, textarea, select, [contenteditable="true"]')) return;
        if (target.closest('a, button') && !target.closest('[data-wordle-keyboard]')) return;

        const key = event.key === 'Enter' || event.key === 'Backspace' ? event.key : event.key.toLowerCase();
        if (key === 'Enter' || key === 'Backspace' || ALPHABET.includes(key)) {
            event.preventDefault();
            press(key);
        }
    });

    rows.forEach((row) => row.addEventListener('animationend', () => row.classList.remove('is-shaking')));

    // Share: the result as coloured squares (not the letters), so it doesn't give the word away
    shareButton.addEventListener('click', async () => {
        const squares = { correct: '🟩', present: '🟨', absent: '⬛' };
        const [year, month, day] = DATE.split('-');
        const text = 'HB Hub Wordle (' + LANGUAGE_NAME + ') ' + day + '/' + month + '/' + year + ' '
            + (game.result === 'won' ? game.guesses.length : 'X') + '/' + rows.length + '\n\n'
            + game.guesses.map((guess) => guess.score.map((status) => squares[status]).join('')).join('\n');
        try {
            await navigator.clipboard.writeText(text);
            say('Copied! Paste it in a chat to show the others.');
        } catch {
            say('Couldn\'t copy it. Your browser may not allow it here.');
        }
    });

    // The tabs' "Solved", "Missed" and so on, from the guest's tokens (members get them from the page)
    function drawStatuses(statuses) {
        root.querySelectorAll('[data-wordle-status-of]').forEach((status) => {
            const name = statuses[status.dataset.wordleStatusOf];
            if (!STATUS_NAMES[name]) return;
            status.className = 'wordle-language-status is-' + name;
            status.textContent = STATUS_NAMES[name];
        });
    }

    // A guest's game of today, from the tokens this browser keeps. Typing waits until it's here.
    async function loadGuestGame() {
        const tokens = guestTokens();
        if (Object.keys(tokens).length <= 1) return; // only the date: nothing played today
        isBusy = true;
        let data = null;
        try {
            const response = await fetch(API, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ action: 'view', lang: LANGUAGE, tokens: JSON.stringify(tokens) }),
            });
            if (response.status === 403) {
                location.reload(); // the games were closed: the page is gone
                return;
            }
            data = await response.json();
        } catch {
            say('Couldn\'t load your game. Check your connection and reload the page.', 0);
        }
        isBusy = false;
        if (!data?.game) return;
        game = data.game;
        drawBoard();
        drawKeys();
        drawResult();
        drawStatuses(data.statuses ?? {});
    }

    // On the members' page: the games played today as a guest before logging in or signing up, brought over to the
    // account. The tokens are only forgotten once the server has answered, and the page reloads when it took any.
    async function importGuestGames() {
        let saved = null;
        try {
            saved = localStorage.getItem(GUEST_KEY);
        } catch {
            return; // no storage, so no guest games either
        }
        if (saved === null) return;
        const tokens = guestTokens();
        let imported = [];
        if (Object.keys(tokens).length > 1) {
            try {
                const response = await fetch(API, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams({ action: 'import', tokens: JSON.stringify(tokens) }),
                });
                if (!response.ok) return; // tried again next time
                imported = (await response.json()).imported ?? [];
            } catch {
                return;
            }
        }
        try {
            localStorage.removeItem(GUEST_KEY);
        } catch {
            // no storage
        }
        if (imported.length > 0) location.reload();
    }

    drawBoard();
    drawKeys();
    const stats = JSON.parse(root.dataset.stats);
    if (stats) drawStats(stats);
    drawResult();
    setInterval(drawCountdown, 1000);
    if (IS_GUEST) loadGuestGame();
    else importGuestGames();
})();
