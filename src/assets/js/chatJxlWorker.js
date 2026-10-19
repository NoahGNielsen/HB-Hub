// JPEG XL for the chat, in a module worker so the page doesn't freeze while it works. chat.js starts it (chatJxlWorker()).
// The codec is libjxl compiled to WebAssembly, from jSquash (assets/js/vendor/jxl/, see its LICENSE files). Its encoder
// and decoder are only loaded the first time they're needed. The site's content security policy allows WebAssembly
// with 'wasm-unsafe-eval' (src/.htaccess).
//
// Messages in: { id, type: 'encode', file }    a picture (File or Blob) to turn into lossless JPEG XL
//              { id, type: 'decode', buffer }  a JPEG XL file (ArrayBuffer) to turn into pixels, for browsers that can't show it
// Messages out: { id, buffer }                         encode: the .jxl file
//               { id, pixels, width, height }          decode: RGBA pixels, 4 bytes each
//               { id, error }                          what went wrong
// A module worker has a scope of its own, so there's no IIFE around it.

// Lossless, as small as this build of libjxl can make it: effort 9 is the slowest and smallest it has.
// quality 100 without lossyModular is what makes it lossless (the option names are the encoder's own).
const ENCODE_OPTIONS = {
    effort: 9,
    quality: 100,
    progressive: false,
    epf: -1,
    lossyPalette: false,
    decodingSpeedTier: 0,
    photonNoiseIso: 0,
    lossyModular: false,
};
const MAX_PIXELS = 50_000_000; // bigger pictures would need more memory than the encoder can have

let encoder = null;
let decoder = null;

// Starts the codec's WebAssembly (it's fetched from next to the codec's .js file)
function load(path) {
    return import(path).then((codec) => codec.default({ noInitialRun: true }));
}

async function encode(file) {
    // premultiplyAlpha 'none' keeps half see-through pixels exactly as they are
    const bitmap = await createImageBitmap(file, { premultiplyAlpha: 'none' });
    const { width, height } = bitmap;
    if (width * height > MAX_PIXELS) {
        bitmap.close();
        throw new Error('tooManyPixels');
    }
    const canvas = new OffscreenCanvas(width, height);
    const context = canvas.getContext('2d');
    context.drawImage(bitmap, 0, 0);
    bitmap.close();
    const pixels = context.getImageData(0, 0, width, height).data;

    encoder ??= load('./vendor/jxl/jxl_enc.js');
    const module = await encoder;
    const result = module.encode(pixels, width, height, ENCODE_OPTIONS);
    if (!result) throw new Error('encodeFailed');
    // Only the bytes of the result, even if it's a view into a bigger buffer
    return result.byteLength === result.buffer.byteLength ? result.buffer : result.slice().buffer;
}

async function decode(buffer) {
    decoder ??= load('./vendor/jxl/jxl_dec.js');
    const module = await decoder;
    const image = module.decode(buffer);
    if (!image) throw new Error('decodeFailed');
    return image;
}

self.addEventListener('message', async ({ data }) => {
    const { id, type } = data;
    try {
        if (type === 'encode') {
            const buffer = await encode(data.file);
            self.postMessage({ id, buffer }, [buffer]);
        } else if (type === 'decode') {
            const image = await decode(data.buffer);
            const pixels = image.data.buffer;
            self.postMessage({ id, pixels, width: image.width, height: image.height }, [pixels]);
        }
    } catch (error) {
        // WebAssembly that ran out of memory can't be trusted afterwards, and one that failed to start should get
        // another try, so either way it's started fresh next time
        if (type === 'encode') encoder = null;
        else decoder = null;
        self.postMessage({ id, error: String(error?.message ?? error) });
    }
});
