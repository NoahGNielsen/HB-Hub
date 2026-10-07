// Toast: the CSS animation shows it, runs the timer bar down and hides it again.
// This only clears the cookie so it isn't shown twice, and removes the toast once it's gone.
(() => {
    document.cookie = 'hbHubToast=; Max-Age=0; path=/; secure; samesite=lax';

    document.querySelectorAll('.toast').forEach((toast) => {
        toast.addEventListener('animationend', (event) => {
            if (event.animationName === 'toast-out') toast.remove();
        });
    });
})();
