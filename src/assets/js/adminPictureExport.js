// Admin panel's picture export (admin/pictureExport.php): the button starts moving the pictures that are still in the
// database to disk, and this keeps it going. Each POST (action=export) does one batch and answers with what it did
// (the shape is at the top of pictureExport.php); this posts the next one, starting after the last ids of the one
// before, until the answer says it reached the end, and shows how far it is: rows on disk and left, and for this run
// how many were exported, how many failed (listed with why) and how much was written.
// One batch at a time, never two at once, so it only ever takes one of the hosting's few PHP requests. If a request
// fails it stops and says so, and the button starts it again from the beginning: rows that are already done are
// passed over by the server, so nothing is exported twice.
(() => {
    const card = document.querySelector('[data-picture-export]');
    const button = card?.querySelector('[data-export-start]');
    if (!card || !button) return;

    const status = card.querySelector('[data-export-status]');
    const run = card.querySelector('[data-export-run]');
    const rowsText = card.querySelector('[data-export-rows]');
    const failedText = card.querySelector('[data-export-failed-count]');
    const bytesText = card.querySelector('[data-export-bytes]');
    const failures = card.querySelector('[data-export-failures]');
    const failedList = card.querySelector('[data-export-failed-list]');
    const tableNames = { pictures: 'Picture', bannedPictures: 'Banned picture' };

    let isRunning = false;

    // "820 KB", "4.3 MB", "1.2 GB"
    const sizeLabel = (bytes) => {
        if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
        if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
        return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`;
    };

    // The table's numbers, and how many are left in all
    const showTotals = (totals) => {
        let left = 0;
        for (const [table, { done, left: tableLeft }] of Object.entries(totals)) {
            const doneCell = card.querySelector(`[data-export-done="${table}"]`);
            const leftCell = card.querySelector(`[data-export-left="${table}"]`);
            if (doneCell) doneCell.textContent = done.toLocaleString('en-GB');
            if (leftCell) leftCell.textContent = tableLeft.toLocaleString('en-GB');
            left += tableLeft;
        }
        return left;
    };

    const exportAll = async () => {
        if (isRunning) return; // a double click only counts once
        isRunning = true;
        button.disabled = true;
        run.hidden = false;
        failures.hidden = true;
        failedList.replaceChildren();

        let afterPictures = 0;
        let afterBanned = 0;
        let rows = 0;
        let failed = 0;
        let bytes = 0;
        let left = null;
        const showRun = () => {
            rowsText.textContent = rows.toLocaleString('en-GB');
            failedText.textContent = failed.toLocaleString('en-GB');
            bytesText.textContent = sizeLabel(bytes);
        };
        showRun();
        status.textContent = 'Exporting...';

        try {
            for (;;) {
                const response = await fetch('/admin/pictureExport', {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams({ action: 'export', afterPictures, afterBanned }),
                });
                if (!response.ok) throw new Error(`The server answered ${response.status}`);
                const batch = await response.json();

                afterPictures = batch.afterPictures;
                afterBanned = batch.afterBanned;
                rows += batch.rows.length;
                failed += batch.failed.length;
                bytes += batch.bytes;
                left = showTotals(batch.totals);
                showRun();
                for (const { table, id, error } of batch.failed) {
                    const item = document.createElement('li');
                    item.textContent = `${tableNames[table] ?? table} ${id}: ${error}`;
                    failedList.append(item);
                }
                failures.hidden = failed === 0;

                if (batch.isFinished) break;
                status.textContent = `Exporting... ${left.toLocaleString('en-GB')} left.`;
            }
            status.textContent = left === 0
                ? 'Done. Every picture is on disk. 0 left.'
                : `Stopped at the end with ${left.toLocaleString('en-GB')} left: ${failed > 0 ? 'the ones that failed' : 'new ones since it started'}. Press the button to try again.`;
        } catch (error) {
            status.textContent = `It stopped: ${error.message}. Nothing is lost - press the button to carry on.`;
        }

        isRunning = false;
        button.disabled = left === 0;
    };

    button.addEventListener('click', exportAll);
})();
