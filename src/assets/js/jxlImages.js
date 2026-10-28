// JPEG XL pictures outside the chat (profile banners, assets/php/profile.php): not every browser can show them yet.
// When one can't, the <img data-jxl> fails to load, so the file is fetched and drawn into a <canvas> in its place by the
// JPEG XL worker (chatJxlWorker.js, its address is data-jxl-worker on the <img>), like chat.js does for the chat's.
// The canvas gets the picture's classes, so the CSS that sizes it (object-fit: cover) carries on working.
// Loaded by the profile and settings pages.
(() => {
    let worker = null;
    let nextId = 0;
    const jobs = new Map(); // id -> { resolve, reject }

    // Sends the worker a job and gives back a promise of its answer, starting it the first time
    function run(url, data, transfer) {
        if (!worker) {
            worker = new Worker(url, { type: 'module' });
            worker.addEventListener('message', ({ data: answer }) => {
                const job = jobs.get(answer.id);
                if (!job) return;
                jobs.delete(answer.id);
                if (answer.error) job.reject(new Error(answer.error));
                else job.resolve(answer);
            });
            // The worker itself couldn't load (a browser without module workers, say)
            worker.addEventListener('error', () => {
                jobs.forEach((job) => job.reject(new Error('workerFailed')));
                jobs.clear();
            });
        }
        const id = ++nextId;
        return new Promise((resolve, reject) => {
            jobs.set(id, { resolve, reject });
            worker.postMessage({ id, type: 'decode', ...data }, transfer);
        });
    }

    async function draw(image) {
        if (image.dataset.jxlDrawing) return; // only tried once
        image.dataset.jxlDrawing = '1';
        try {
            const response = await fetch(image.currentSrc || image.src);
            if (!response.ok) return;
            const buffer = await response.arrayBuffer();
            const { pixels, width, height } = await run(image.dataset.jxlWorker, { buffer }, [buffer]);
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            canvas.className = image.className;
            canvas.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(pixels), width, height), 0, 0);
            // Anything else that points at the picture (settings.js) finds the canvas by the same attributes
            for (const attribute of image.getAttributeNames()) {
                if (attribute.startsWith('data-') && attribute !== 'data-jxl' && attribute !== 'data-jxl-drawing') {
                    canvas.setAttribute(attribute, image.getAttribute(attribute));
                }
            }
            image.replaceWith(canvas);
        } catch {
            // Left as the broken picture, the CSS behind it still shows
        }
    }

    for (const image of document.querySelectorAll('img[data-jxl]')) {
        // It may have failed already, before this script ran
        if (image.complete && image.naturalWidth === 0) draw(image);
        else image.addEventListener('error', () => draw(image), { once: true });
    }
})();
