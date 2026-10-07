// Message notifications: while any HB Hub page is open, asks /chat/notifications for new messages every
// 15 seconds and shows a browser notification for each. navPanel.php only adds this script when the user turned
// notifications on in settings, and the settings page asks the browser for permission.
(() => {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;

    const POLL_MS = 15000;
    // The newest message already checked, shared by every open tab so a message is only announced once
    const STORAGE_KEY = 'hbHubNotifiedMessageId';
    let lastSeen = null; // used when storage isn't available

    function readLastSeen() {
        try {
            const value = localStorage.getItem(STORAGE_KEY);
            if (value !== null && /^\d+$/.test(value)) return Number(value);
        } catch { /* storage blocked */ }
        return lastSeen;
    }

    function writeLastSeen(id) {
        lastSeen = id;
        try {
            localStorage.setItem(STORAGE_KEY, String(id));
        } catch { /* storage blocked */ }
    }

    // No point announcing a message in the chat the user is looking at
    function isShowingChat(chatId) {
        return document.visibilityState === 'visible'
            && location.pathname.replace(/\/$/, '') === '/chat'
            && new URLSearchParams(location.search).get('chat') === String(chatId);
    }

    let timer = null;

    async function check() {
        const after = readLastSeen();
        let data;
        try {
            const response = await fetch('/chat/notifications' + (after !== null ? '?after=' + after : ''), {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return;
            data = await response.json();
        } catch {
            return; // offline for a moment, try again next time
        }

        if (!data.enabled) {
            clearInterval(timer); // turned off in another tab
            return;
        }

        // Another tab may have announced some of these while this request was on its way
        const announced = readLastSeen();
        for (const message of data.messages) {
            if (announced !== null && message.id <= announced) continue;
            if (isShowingChat(message.chatId)) continue;

            // The tag makes a newer message in the same chat replace the older notification
            const notification = new Notification(message.title, {
                body: message.body,
                icon: message.icon,
                tag: 'hbhub-chat-' + message.chatId,
            });
            notification.addEventListener('click', () => {
                window.focus();
                location.href = '/chat/?chat=' + message.chatId;
                notification.close();
            });
        }

        if (announced === null || data.latest > announced) writeLastSeen(data.latest);
    }

    check();
    timer = setInterval(check, POLL_MS);
})();
