// Ore Mining Inc.'s watchdog: a Web Worker spaceOreMiner.js starts (new Worker(data-watchdog)), so it runs on a thread
// of its own and keeps going when the game's page freezes. A frozen page can't write anything down itself, so this
// does it for the page: when the page stops answering for HANG_MS while it's being looked at, it saves a crash report
// in the browser's IndexedDB (the "hbHubMiner" database, "crashes" store, one record per freeze, keyed by id). It never
// sends anything anywhere: the next time the game is opened, the page finds the report there and asks the player if
// it may send it (see "Crash reports" in spaceOreMiner.js), and deletes it either way.
// What the page tells it (postMessage):
// - {type: 'step', name, at}: the page has started doing name ('frame', 'tick: autoBuy', 'save', 'click: ...'), or with
//   name null, it's done and waiting for the next thing. So when it freezes, the last one is what it was stuck in. at is
//   the page's performance.now(), which times the steps (they're kept in recent, the last RECENT_MAX).
// - {type: 'beat', visible, report}: every second, the page is fine. report is the crash report as it would be now
//   (crashReport() in spaceOreMiner.js), kept here in case the page freezes before the next one. visible false: the
//   page is hidden, where its timers slow right down, so it isn't watched until it's shown again.
// What it tells the page: {type: 'recovered', ms} when the page answers again after a freeze it saved, for its trail.
// The record: the report, with kind 'hang' and hang {step (null: between its tasks, in something it doesn't name),
// stepMs (how long it had been at it when the freeze was noticed), at (the last beat, Unix ms), lasted (how long it's
// been frozen: saved again every few seconds while it is, so a page that's killed in the end still says how long it was
// stuck), recovered (it came back), before (the last RECENT_MAX steps before it, oldest first, as [Unix ms the first
// started, name, times in a row, the longest of them in ms])}.

const HANG_MS = 5000; // without a beat while the page is shown: it's frozen (a beat is due every second)
const CHECK_MS = 500;
const UPDATE_MS = 5000; // how often a freeze that goes on has its 'lasted' saved again
const ASLEEP_MS = 5000; // this worker's own check came this late: the computer was asleep, so the page wasn't frozen
const RECENT_MAX = 50; // a tick of the game alone is 9 steps, so this is about the last second

let lastBeat = Date.now();
let isVisible = false;
let report = null; // from the last beat
let step = null;
let stepAt = Date.now();
let stepPageAt = 0; // the same, in the page's performance.now()
const recent = []; // the steps done, see before above (the same step several times in a row is one)

// Puts the step that just ended in recent
function remember(name, startedAt, ms) {
    const last = recent[recent.length - 1];
    if (last && last[1] === name) {
        last[2]++;
        last[3] = Math.max(last[3], ms);
        return;
    }
    recent.push([startedAt, name, 1, ms]);
    if (recent.length > RECENT_MAX) recent.shift();
}
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
        if (step !== null) remember(step, stepAt, Math.round((message.at - stepPageAt) * 10) / 10);
        step = message.name;
        stepAt = Date.now();
        stepPageAt = message.at;
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
            hang: {
                step, stepMs: step !== null ? now - stepAt : 0, at: lastBeat, lasted: now - lastBeat, recovered: false,
                before: recent.map((entry) => [...entry]),
            },
        };
        hang = { record, savedAt: now };
        put(record);
    } else if (hang && now - hang.savedAt >= UPDATE_MS) {
        hang.record.hang.lasted = now - hang.record.hang.at;
        hang.savedAt = now;
        put(hang.record);
    }
}, CHECK_MS);
