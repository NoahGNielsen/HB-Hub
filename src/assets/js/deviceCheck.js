// The browser's fingerprint, for the admin panel's People (assets/php/fingerprint.php has the why and what's kept).
// It's called deviceCheck.js and posts to /device-check rather than anything with "fingerprint" in it, because ad and
// tracker blockers (uBlock Origin, Brave Shields, AdGuard, EasyPrivacy lists) block URLs with that word in them.
// header.php only loads this for a logged in user whose login hasn't sent one in the last day, so it runs about once
// a day per browser, a little after the page has loaded, while the browser has nothing else to do. It's never loaded
// for a browser that sends Global Privacy Control or Do Not Track, and does nothing if it somehow is
// (navigator.globalPrivacyControl / navigator.doNotTrack).
//
// It's a SHA-256 of things about the browser and computer that stay the same from day to day and don't depend on
// cookies, so they're the same in a private window or after clearing cookies:
// - canvas: how a bit of text, emoji and shapes are drawn (the graphics card, its driver and the fonts all change it)
// - WebGL: the graphics card's name and maker, its limits and which extensions it has
// - audio: what a short sound comes out as after a compressor (differs between audio stacks)
// - fonts: which of a list of common fonts are installed (by measuring text, nothing is read off the disk)
// - Math: the last digits of a few functions, which differ between JavaScript engines
// - the screen's size (either way round) and colour depth, the time zone, languages, platform, touch points,
//   processor cores and memory
// Left out on purpose: the browser version (changes with every update), the window size and devicePixelRatio
// (change with zoom and resizing) and light/dark mode. Brave makes up a new canvas, audio and core count every
// session, so those are left out there, or its fingerprint would change all the time.
//
// Only the hash is sent: POST /device-check (otherPages/deviceCheck.php), form-encoded action=save&fingerprint=<hex>.
// If any part fails, that part is just left empty; if it all fails or the post doesn't go through, nothing happens and
// it's tried again on the next page load (the server only stores one a day per login anyway).
(() => {
    const REPORT_URL = '/device-check';
    const START_DELAY_MS = 2000;
    const AUDIO_TIMEOUT_MS = 1000;
    // Brave randomises canvas, audio and hardwareConcurrency per session ("farbling")
    const isBrave = typeof navigator.brave === 'object' && navigator.brave !== null;
    // Fonts to look for. Windows, Mac, Linux, ChromeOS and Office ones, so school and home computers differ.
    const FONTS = [
        'Arial Black', 'Bahnschrift', 'Calibri', 'Cambria', 'Candara', 'Comic Sans MS', 'Consolas', 'Constantia',
        'Corbel', 'Franklin Gothic Medium', 'Gabriola', 'Georgia', 'Impact', 'Ink Free', 'Lucida Console',
        'Lucida Sans Unicode', 'Palatino Linotype', 'Segoe Print', 'Segoe Script', 'Segoe UI Emoji', 'Sitka Text',
        'Sylfaen', 'Trebuchet MS', 'Verdana', 'Aptos', 'Helvetica Neue', 'Menlo', 'Monaco', 'Optima', 'Avenir',
        'Futura', 'Gill Sans', 'Baskerville', 'Didot', 'American Typewriter', 'Ubuntu', 'DejaVu Sans',
        'Liberation Sans', 'Noto Sans', 'Cantarell', 'Roboto', 'Open Sans', 'Fira Sans', 'Source Sans Pro',
        'Century Gothic', 'Garamond', 'Book Antiqua', 'Rockwell', 'Tw Cen MT', 'Agency FB',
    ];

    /**
     * Runs fn and gives its answer, or '' when it throws (a browser that blocks or lacks that part).
     */
    const safely = (fn) => {
        try {
            return fn();
        } catch {
            return '';
        }
    };

    const hex = (buffer) => [...new Uint8Array(buffer)].map((b) => b.toString(16).padStart(2, '0')).join('');
    const sha256 = async (text) => hex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)));

    /**
     * The canvas part: a hash of the picture's data URL (it's long, so it's hashed on its own first).
     */
    const canvasPart = async () => {
        if (isBrave) return '';
        const canvas = document.createElement('canvas');
        canvas.width = 280;
        canvas.height = 60;
        const ctx = canvas.getContext('2d');
        if (!ctx) return '';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#f60';
        ctx.fillRect(100, 1, 62, 20);
        ctx.fillStyle = '#069';
        ctx.font = '15px "Times New Roman", serif';
        ctx.fillText('HB Hub, fingerprint \u{1F3B2}\u{1F600} 0.1', 2, 15);
        ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
        ctx.font = 'bold 18px Arial, sans-serif';
        ctx.fillText('Cwm fjordbank glyphs vext quiz', 4, 45);
        const gradient = ctx.createLinearGradient(0, 0, 280, 60);
        gradient.addColorStop(0, 'rgba(255, 0, 255, 0.5)');
        gradient.addColorStop(1, 'rgba(0, 255, 255, 0.5)');
        ctx.fillStyle = gradient;
        ctx.globalCompositeOperation = 'multiply';
        ctx.beginPath();
        ctx.arc(200, 30, 25, 0, Math.PI * 2);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(240, 30, 25, 0, Math.PI * 2);
        ctx.fill();
        return sha256(canvas.toDataURL());
    };

    /**
     * The WebGL part: the graphics card's (unmasked when the browser allows it) maker and name, limits and extensions.
     */
    const webglPart = () => {
        const canvas = document.createElement('canvas');
        const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        if (!gl) return '';
        const info = gl.getExtension('WEBGL_debug_renderer_info');
        const parts = [
            info ? gl.getParameter(info.UNMASKED_VENDOR_WEBGL) : gl.getParameter(gl.VENDOR),
            info ? gl.getParameter(info.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER),
            gl.getParameter(gl.SHADING_LANGUAGE_VERSION),
            gl.getParameter(gl.MAX_TEXTURE_SIZE),
            gl.getParameter(gl.MAX_RENDERBUFFER_SIZE),
            gl.getParameter(gl.MAX_VERTEX_ATTRIBS),
            gl.getParameter(gl.MAX_VERTEX_UNIFORM_VECTORS),
            gl.getParameter(gl.MAX_FRAGMENT_UNIFORM_VECTORS),
            [...(gl.getParameter(gl.MAX_VIEWPORT_DIMS) || [])].join('x'),
            (gl.getSupportedExtensions() || []).slice().sort().join(','),
        ];
        gl.getExtension('WEBGL_lose_context')?.loseContext(); // give the context back straight away
        return parts.join('|');
    };

    /**
     * The audio part: the sum of a stretch of a triangle wave run through a compressor, rendered offline (no sound
     * is played). Gives up after AUDIO_TIMEOUT_MS.
     */
    const audioPart = () => new Promise((resolve) => {
        const Offline = window.OfflineAudioContext || window.webkitOfflineAudioContext;
        if (isBrave || !Offline) {
            resolve('');
            return;
        }
        const timer = setTimeout(() => resolve(''), AUDIO_TIMEOUT_MS);
        try {
            const context = new Offline(1, 5000, 44100);
            const oscillator = context.createOscillator();
            oscillator.type = 'triangle';
            oscillator.frequency.value = 10000;
            const compressor = context.createDynamicsCompressor();
            compressor.threshold.value = -50;
            compressor.knee.value = 40;
            compressor.ratio.value = 12;
            compressor.attack.value = 0;
            compressor.release.value = 0.25;
            oscillator.connect(compressor);
            compressor.connect(context.destination);
            oscillator.start(0);
            context.oncomplete = (event) => {
                clearTimeout(timer);
                const samples = event.renderedBuffer.getChannelData(0);
                let sum = 0;
                for (let i = 4500; i < 5000; i++) sum += Math.abs(samples[i]);
                resolve(sum.toFixed(6));
            };
            context.startRendering();
        } catch {
            clearTimeout(timer);
            resolve('');
        }
    });

    /**
     * The fonts part: the fonts from FONTS whose text comes out a different width than the fallback fonts on their own.
     */
    const fontsPart = () => {
        const ctx = document.createElement('canvas').getContext('2d');
        if (!ctx) return '';
        const sample = 'mmmmmmmmmmlli WwQq 0123456789';
        const fallbacks = ['monospace', 'sans-serif', 'serif'];
        const width = (font) => {
            ctx.font = `72px ${font}`;
            return ctx.measureText(sample).width;
        };
        const base = fallbacks.map(width);
        return FONTS.filter((font) => fallbacks.some((fallback, i) => width(`"${font}", ${fallback}`) !== base[i])).join(',');
    };

    const mathPart = () => [
        Math.tan(-1e300), Math.acosh(1e300), Math.asinh(1), Math.atanh(0.5), Math.expm1(1), Math.log1p(10),
        Math.cbrt(100), Math.sinh(1), Math.cosh(10), Math.tanh(0.5), Math.atan2(0.5, 0.5), Math.exp(10),
    ].join(',');

    /**
     * Everything else the browser tells straight away. The screen's sides are sorted, so turning a tablet doesn't
     * change it.
     */
    const navigatorPart = () => [
        [screen.width, screen.height].sort((a, b) => a - b).join('x'),
        screen.colorDepth,
        safely(() => Intl.DateTimeFormat().resolvedOptions().timeZone),
        new Date(2026, 0, 1).getTimezoneOffset(),
        new Date(2026, 6, 1).getTimezoneOffset(),
        (navigator.languages || [navigator.language]).join(','),
        navigator.platform || '',
        navigator.maxTouchPoints || 0,
        isBrave ? '' : navigator.hardwareConcurrency || '',
        navigator.deviceMemory || '',
        navigator.pdfViewerEnabled ?? '',
    ].join('|');

    const send = async () => {
        if (!window.crypto?.subtle) return; // only over HTTPS, which the site always is
        // Global Privacy Control or the older Do Not Track: the server skips it too
        if (navigator.globalPrivacyControl === true || navigator.doNotTrack === '1' || window.doNotTrack === '1') return;
        const parts = [
            await canvasPart().catch(() => ''),
            safely(webglPart),
            await audioPart(),
            safely(fontsPart),
            safely(mathPart),
            safely(navigatorPart),
        ];
        if (parts.every((part) => part === '')) return;
        const fingerprint = await sha256(JSON.stringify(parts));
        try {
            await fetch(REPORT_URL, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ action: 'save', fingerprint }),
            });
        } catch {
            // Tried again on the next page load
        }
    };

    const start = () => {
        const run = () => send().catch(() => {});
        if ('requestIdleCallback' in window) {
            requestIdleCallback(run, { timeout: 10000 });
        } else {
            run();
        }
    };
    setTimeout(start, START_DELAY_MS);
})();
