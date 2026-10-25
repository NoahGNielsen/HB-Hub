// Profile page (otherPages/profile.php): paints the banner's colors, from data-profile-gradient as "#a1b2c3 #d4e5f6 90"
// (two colors and the angle, like a chat's background in chat.js). Set from here, as the site's content security policy
// doesn't allow style attributes. Without JS the banner keeps the site's own colors from profile.css.
(() => {
    const banner = document.querySelector('[data-profile-gradient]');
    if (!banner) return;
    const [color1, color2, angle] = banner.dataset.profileGradient.split(' ');
    banner.style.backgroundImage = `linear-gradient(${angle}deg, ${color1}, ${color2})`;
})();
