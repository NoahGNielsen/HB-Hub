// Profile page (otherPages/profile.php): paints the banner's colors, from data-profile-gradient as "#a1b2c3 #d4e5f6 90"
// (two colors and the angle, like a chat's background in chat.js). Set from here, as the site's content security policy
// doesn't allow style attributes. Without JS the banner keeps the site's own colors from profile.css.
// Clicking the profile picture or the banner picture shows it big in a popup ([data-profile-viewer]).
// On your own profile it also opens the status popup ([data-status-dialog]) from your status ([data-status-open]),
// and straight away when it has data-open (saving it went wrong). Without JS the status is a link to settings.
(() => {
    const banner = document.querySelector('[data-profile-gradient]');
    if (banner) {
        const [color1, color2, angle] = banner.dataset.profileGradient.split(' ');
        banner.style.backgroundImage = `linear-gradient(${angle}deg, ${color1}, ${color2})`;
    }

    // The picture viewer: clicking the profile picture or banner ([data-profile-view], links to the file) shows a copy
    // of it big in [data-profile-viewer]. A banner jxlImages.js drew into a <canvas> is copied pixel for pixel, as the
    // browser can't show the file itself.
    const viewer = document.querySelector('[data-profile-viewer]');
    if (viewer) {
        const viewerPicture = viewer.querySelector('[data-profile-viewer-picture]');
        for (const link of document.querySelectorAll('[data-profile-view]')) {
            link.addEventListener('click', (event) => {
                const shown = link.querySelector('img, canvas');
                if (!shown) return;
                event.preventDefault();
                let copy;
                if (shown instanceof HTMLCanvasElement) {
                    copy = document.createElement('canvas');
                    copy.width = shown.width;
                    copy.height = shown.height;
                    copy.getContext('2d').drawImage(shown, 0, 0);
                } else {
                    copy = new Image();
                    copy.src = shown.currentSrc || shown.src;
                    copy.alt = shown.alt;
                }
                viewerPicture.replaceChildren(copy);
                viewer.setAttribute('aria-label', link.getAttribute('aria-label').replace(/^View /, ''));
                viewer.showModal();
            });
        }
        // Any click closes it: the picture, the × or the dark backdrop around it
        viewer.addEventListener('click', () => viewer.close());
        viewer.addEventListener('close', () => viewerPicture.replaceChildren());
    }

    const dialog = document.querySelector('[data-status-dialog]');
    if (!dialog) return;
    document.querySelector('[data-status-open]')?.addEventListener('click', (event) => {
        event.preventDefault();
        dialog.showModal();
    });
    for (const close of dialog.querySelectorAll('[data-status-close]')) {
        close.addEventListener('click', () => dialog.close());
    }
    // A click on the dark backdrop around it closes it too
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    if (dialog.hasAttribute('data-open')) dialog.showModal();
})();
