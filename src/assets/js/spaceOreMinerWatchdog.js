// Space Ore Miner's watchdog: a Web Worker spaceOreMiner.js starts (new Worker(data-watchdog)), so it runs on a thread
// of its own and keeps going when the game's page freezes. A frozen page can't write anything down itself, so this
// does it for the page: when the page stops answering for HANG_MS while it's being looked at, it saves a crash report
// in the browser's IndexedDB (the "hbHubMiner" database, "crashes" store, one record per freeze, keyed by id). It never
// sends anything anywhere: the next time the game is opened, the page finds the report there and asks the player if
// it may send it (see "Crash reports" in spaceOreMiner.js), and deletes it either way.
// What the page tells it (postMessage):
// - {type: 'step', name}: the page has started doing name ('frame', 'tick: autoBuy', 'save', 'click: ...'), or with
//   name null, it's done and waiting for the next thing. So when it freezes, the last one is what it was stuck in.
// - {type: 'beat', visible, report}: every second, the page is fine. report is the crash report as it would be now
//   (crashReport() in spaceOreMiner.js), kept here in case the page freezes before the next one. visible false: the
//   page is hidden, where its timers slow right down, so it isn't watched until it's shown again.
// What it tells the page: {type: 'recovered', ms} when the page answers again after a freeze it saved, for its trail.
// The record: the report, with kind 'hang' and hang {step (null: between its tasks, in something it doesn't name),
// stepMs (how long it had been at it when the freeze was noticed), at (the last beat, Unix ms), lasted (how long it's
// been frozen: saved again every few seconds while it is, so a page that's killed in the end still says how long it was
// stuck), recovered (it came back)}.

const HANG_MS = 5000; // without a beat while the page is shown: it's frozen (a beat is due every second)
const CHECK_MS = 500;
const UPDATE_MS = 5000; // how often a freeze that goes on has its 'lasted' saved again
const ASLEEP_MS = 5000; // this worker's own check came this late: the computer was asleep, so the page wasn't frozen

let lastBeat = Date.now();
let isVisible = false;
let report = null; // from the last beat
let step = null;
let stepAt = Date.now();
let hang = null; // the freeze going on: {id, record, savedAt}
let lastCheck = Date.now();

// ----- IndexedDB

let database = null;

function openDatabase() {
    database ??= new Promise((resolve, reject) => {
        const request = indexedDB.open('hbHubMiner', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('crashes', { keyPath: 'id' });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
    return database;
}

// Writes the record, replacing the one with the same id. Never throws: with no IndexedDB (a private window in some
// browsers) there's just no report.
async function put(record) {
    try {
        const db = await openDatabase();
        await new Promise((resolve, reject) => {
            const transaction = db.transaction('crashes', 'readwrite');
            transaction.objectStore('crashes').put(record);
            transaction.oncomplete = resolve;
            transaction.onerror = () => reject(transaction.error);
        });
    } catch {
        // nothing to be done
    }
}

// ----- Watching

self.addEventListener('message', (event) => {
    const message = event.data;
    if (message.type === 'step') {
        step = message.name;
        stepAt = Date.now();
    } else if (message.type === 'beat') {
        const now = Date.now();
        if (hang) { // it answers again
            hang.record.hang.lasted = now - hang.record.hang.at;
            hang.record.hang.recovered = true;
            put(hang.record);
            self.postMessage({ type: 'recovered', ms: hang.record.hang.lasted });
            hang = null;
        }
        lastBeat = now;
        isVisible = message.visible;
        report = message.report;
    }
});

setInterval(() => {
    const now = Date.now();
    const late = now - lastCheck;
    lastCheck = now;
    if (late > ASLEEP_MS) { // asleep, not frozen: start counting again
        lastBeat = now;
        return;
    }
    if (!isVisible || !report) return;

    if (!hang && now - lastBeat > HANG_MS) {
        const record = {
            ...report,
            id: report.page.tab + ':' + lastBeat,
            kind: 'hang',
            hang: { step, stepMs: step !== null ? now - stepAt : 0, at: lastBeat, lasted: now - lastBeat, recovered: false },
        };
        hang = { record, savedAt: now };
        put(record);
    } else if (hang && now - hang.savedAt >= UPDATE_MS) {
        hang.record.hang.lasted = now - hang.record.hang.at;
        hang.savedAt = now;
        put(hang.record);
    }
}, CHECK_MS);
