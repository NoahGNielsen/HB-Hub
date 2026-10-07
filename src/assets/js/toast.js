// Toast: the CSS animation shows it, runs the timer bar down and hides it again.
// This only clears the cookie so it isn't shown twice, removes the toast once it's gone,
// and lets a click send it away right now (the same animation, just without waiting for the timer).
(() => {
    document.cookie = 'hbHubToast=; Max-Age=0; path=/; secure; samesite=lax';

    document.querySelectorAll('.toast').forEach((toast) => {
        toast.addEventListener('animationend', (event) => {
            if (event.animationName === 'toast-out') toast.remove();
        });
        toast.addEventListener('click', () => toast.classList.add('is-dismissed'));
    });
})();
