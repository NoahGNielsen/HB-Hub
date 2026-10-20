// Message notifications: while any HB Hub page is open, asks for new messages along with navUpdates.js's request
// (every 15 seconds, also while the page is hidden: messages=1 and after= on nav:poll, the answer's "messages" on
// nav:update, see assets/php/messageNotifications.php). For each one it shows a browser notification, and plays one
// sound for the lot (message:sound, assets/js/messageSound.js), depending on what the user turned on in settings.
// navPanel.php only adds this script when the user turned on at least one of the two, and says which with
// data-notify="1" / data-sound="1" on the script tag. Each answer says it again, so a change in another tab counts too.
// The settings page asks the browser for permission to show notifications.
(() => {
    const script = document.currentScript;
    let wantsNotify = script?.dataset.notify === '1';
    let wantsSound = script?.dataset.sound === '1';

    function canNotify() {
        return wantsNotify && 'Notification' in window && Notification.permission === 'granted';
    }

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

    document.addEventListener('nav:poll', (event) => {
        // Nothing to do with the messages: only notifications are on and the browser doesn't allow them
        if (!wantsSound && !canNotify()) return;
        const after = readLastSeen();
        event.detail.params.set('messages', '1');
        if (after !== null) event.detail.params.set('after', String(after));
        event.detail.inBackground = true; // the other tabs and windows are just what this is for
    });

    document.addEventListener('nav:update', (event) => {
        const data = event.detail.messages;
        if (!data) return;
        wantsNotify = data.enabled && data.notify;
        wantsSound = data.enabled && data.sound;
        if (!data.enabled) return; // both turned off in another tab

        // Another tab may have announced some of these while this request was on its way
        const announced = readLastSeen();
        let hasNew = false;
        for (const message of data.messages) {
            if (announced !== null && message.id <= announced) continue;
            if (isShowingChat(message.chatId)) continue;
            hasNew = true;
            if (!canNotify()) continue;

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
        // One sound however many came in at once
        if (hasNew && wantsSound) document.dispatchEvent(new CustomEvent('message:sound'));

        if (announced === null || data.latest > announced) writeLastSeen(data.latest);
    });
})();
