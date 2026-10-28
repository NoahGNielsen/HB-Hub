// WebM with VP9 for videos sent in the chat, in a module worker so the page doesn't freeze while it works. chat.js
// starts it (chatWorker()) the first time a video is picked to send. Every video is stored as WebM + VP9, so they all
// play in every browser that has a player for them, and VP9 takes much less space than a phone's H.264.
// The decoding and encoding is done by the browser's own codecs (WebCodecs). Mediabunny (assets/js/vendor/mediabunny/,
// see its LICENSE file) reads the original (MP4, MOV, WebM, MKV), drives the codecs and writes the WebM. It's only
// loaded the first time it's needed. A video that's already WebM + VP9 + Opus, and small enough, is copied over
// without being encoded again, so it's quick and loses nothing.
//
// Messages in:  { id, type: 'encode', file }   a video (File or Blob) to turn into WebM: VP9 video and Opus sound,
//                                              made smaller first when a side is longer than MAX_SIDE
// Messages out: { id, progress }               how far it has got, 0 to 1, now and then while it works
//               { id, buffer }                 the .webm file
//               { id, error }                  what went wrong: noVideo (no picture in it, or not a kind it can read),
//                                              noEncoder (this browser can't make VP9) or cantConvert, or the
//                                              codec's own message
// A module worker has a scope of its own, so there's no IIFE around it.

// Neither side longer than this (1080p, either way up). Bigger ones take ages to encode and aren't worth their tickets
// on a phone or a laptop screen.
const MAX_SIDE = 1920;
// Mediabunny's quality levels pick the encoder's settings for the size and codec. 'high' looks like the original on a
// screen, at a good deal less than a phone's own video takes.
const QUALITY = 'high';

let mediabunny = null;

// Codecs want sides that can be halved
function even(value) {
    return Math.max(2, Math.round(value / 2) * 2);
}

async function encode(id, file) {
    mediabunny ??= await import('./vendor/mediabunny/mediabunny.js');
    const { Input, BlobSource, ALL_FORMATS, Output, BufferTarget, WebMOutputFormat, Conversion, Quality, canEncodeVideo } = mediabunny;

    const input = new Input({ source: new BlobSource(file), formats: ALL_FORMATS });
    let video;
    try {
        video = await input.getPrimaryVideoTrack();
    } catch {
        throw new Error('noVideo'); // not a kind of file Mediabunny can read
    }
    if (!video) throw new Error('noVideo');
    if (!await canEncodeVideo('vp9')) throw new Error('noEncoder');

    const quality = new Quality(QUALITY);
    const target = new BufferTarget();
    const output = new Output({ format: new WebMOutputFormat(), target });
    const conversion = await Conversion.init({
        input,
        output,
        tracks: 'primary', // the video and its sound, not a phone's extra tracks
        showWarnings: false, // a discarded track is handled below
        tags: {}, // none of the original's details (like where a phone filmed it) go with it
        video: async (track) => {
            const width = await track.getDisplayWidth();
            const height = await track.getDisplayHeight();
            const scale = Math.min(1, MAX_SIDE / Math.max(width, height));
            // WebM can't say "turn this", so a phone video filmed upright is turned in the pictures themselves
            const options = { codec: 'vp9', quality, allowTransformationMetadata: false };
            // Only the longer side is given, and the other one follows from it
            if (scale < 1 && width >= height) options.width = even(width * scale);
            else if (scale < 1) options.height = even(height * scale);
            return options;
        },
        audio: { codec: 'opus', quality },
    });
    // A track it can't decode or encode would be left out: better to send the original than a video without sound
    if (!conversion.isValid || conversion.discardedTracks.length > 0) throw new Error('cantConvert');

    let reported = 0;
    conversion.onProgress = (progress) => {
        if (progress - reported < 0.01) return; // a message per percent is plenty
        reported = progress;
        self.postMessage({ id, progress });
    };
    await conversion.execute();
    return target.buffer;
}

self.addEventListener('message', async ({ data }) => {
    try {
        if (data.type !== 'encode') throw new Error('unknownType');
        const buffer = await encode(data.id, data.file);
        self.postMessage({ id: data.id, buffer }, [buffer]);
    } catch (error) {
        self.postMessage({ id: data.id, error: error?.message || 'cantConvert' });
    }
});
