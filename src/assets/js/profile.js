// Profile page (otherPages/profile.php): paints the banner's colors, from data-profile-gradient as "#a1b2c3 #d4e5f6 90"
// (two colors and the angle, like a chat's background in chat.js). Set from here, as the site's content security policy
// doesn't allow style attributes. Without JS the banner keeps the site's own colors from profile.css.
// On your own profile it also opens the status popup ([data-status-dialog]) from your status ([data-status-open]),
// and straight away when it has data-open (saving it went wrong). Without JS the status is a link to settings.
(() => {
    const banner = document.querySelector('[data-profile-gradient]');
    if (banner) {
        const [color1, color2, angle] = banner.dataset.profileGradient.split(' ');
        banner.style.backgroundImage = `linear-gradient(${angle}deg, ${color1}, ${color2})`;
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
