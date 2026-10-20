// The bell in the nav bar: clicking it opens the list of notifications (from /otherPages/notifications), and opening
// it marks them read. The red number on it is how many are unread, kept up to date by navUpdates.js (nav:update).
// The new ones stay highlighted in the list until it's opened again.
(() => {
    const root = document.querySelector('[data-notifications]');
    if (!root) return;

    const bell = root.querySelector('[data-notifications-bell]');
    const icon = root.querySelector('[data-notifications-icon]');
    const count = root.querySelector('[data-notifications-count]');
    const panel = root.querySelector('[data-notifications-panel]');
    const list = root.querySelector('[data-notifications-list]');
    const empty = root.querySelector('[data-notifications-empty]');

    function showUnread(unread) {
        count.hidden = unread === 0;
        count.textContent = unread > 9 ? '9+' : String(unread);
        icon.src = '/assets/images/icons/' + (unread > 0 ? 'notification.png' : 'no-notification.png');
        bell.setAttribute('aria-label', 'Notifications' + (unread > 0 ? ' (' + unread + ' new)' : ''));
    }

    async function request(url, options) {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, ...options });
            return response.ok ? await response.json() : null;
        } catch {
            return null; // offline for a moment, or logged out in another tab
        }
    }

    function image(src, className) {
        const img = document.createElement('img');
        img.src = src;
        img.alt = '';
        img.loading = 'lazy';
        if (className) img.className = className;
        return img;
    }

    // Built with textContent, as the names and group names are typed in by users.
    // Who did it is shown with their picture (or first letter) and name. A notification nobody in particular sent,
    // like a game result, gets the icon of the part of the site it's from and just its text.
    function notificationItem(notification) {
        const item = document.createElement('li');
        const row = document.createElement(notification.link !== null ? 'a' : 'div');
        row.className = 'nav-notification' + (notification.isUnread ? ' is-unread' : '');
        if (notification.link !== null) row.href = notification.link;

        const avatar = document.createElement('span');
        avatar.className = 'nav-notification-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        if (notification.by === null) {
            avatar.append(image(notification.icon, 'nav-notification-icon'));
        } else if (notification.byAvatar !== null) {
            avatar.append(image(notification.byAvatar));
        } else {
            avatar.textContent = notification.byInitial;
        }

        const text = document.createElement('span');
        text.className = 'nav-notification-text';
        if (notification.by !== null) {
            const by = document.createElement('strong');
            by.textContent = notification.by;
            text.append(by, ' ');
        }
        const time = document.createElement('span');
        time.className = 'nav-notification-time';
        time.textContent = notification.time;
        text.append(notification.text, time);

        row.append(avatar, text);
        item.append(row);
        return item;
    }

    async function load() {
        const data = await request('/otherPages/notifications');
        if (data === null) {
            if (list.children.length === 0) empty.textContent = 'Could not load notifications';
            return;
        }

        list.replaceChildren(...data.notifications.map(notificationItem));
        empty.textContent = 'No notifications yet';
        empty.hidden = data.notifications.length > 0;

        // Only up to the newest one loaded, so one that came in meanwhile still counts as new
        if (data.unread > 0 && data.newestId !== null) {
            const read = await request('/otherPages/notifications', {
                method: 'POST',
                body: new URLSearchParams({ action: 'read', upTo: String(data.newestId) }),
            });
            showUnread(read?.unread ?? 0);
        } else {
            showUnread(data.unread);
        }
    }

    function open() {
        panel.hidden = false;
        bell.setAttribute('aria-expanded', 'true');
        load();
    }

    function close() {
        panel.hidden = true;
        bell.setAttribute('aria-expanded', 'false');
    }

    bell.addEventListener('click', () => (panel.hidden ? open() : close()));

    // Clicking anywhere else or pressing Escape closes it
    document.addEventListener('click', (event) => {
        if (!panel.hidden && !root.contains(event.target)) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            close();
            bell.focus();
        }
    });

    document.addEventListener('nav:update', (event) => {
        const { unread } = event.detail;
        if (typeof unread !== 'number') return;
        // While the list is open, new ones are shown in it straight away
        if (!panel.hidden && unread > 0) {
            load();
        } else {
            showUnread(unread);
        }
    });
})();
