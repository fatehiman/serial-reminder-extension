/**
 * Draws the extension icons and writes them as PNG files.
 * Run with:  node tools/make-icons.js
 * No image library needed — the PNG is built by hand with node's zlib.
 */
const zlib = require('zlib');
const fs = require('fs');
const path = require('path');

const BG     = [0x1b, 0x1d, 0x24];
const SCREEN = [0x0e, 0x0f, 0x13];
const GOLD   = [0xe8, 0xb6, 0x4c];
const RED    = [0xf0, 0x5a, 0x5a];

function crc32(buf) {
  let c, table = [];
  for (let n = 0; n < 256; n++) {
    c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
  }
  let crc = 0xffffffff;
  for (const b of buf) crc = table[(crc ^ b) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([len, body, crc]);
}

function png(width, height, rgba) {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8;    // bit depth
  ihdr[9] = 6;    // colour type: RGBA
  const raw = Buffer.alloc((width * 4 + 1) * height);
  for (let y = 0; y < height; y++) {
    raw[y * (width * 4 + 1)] = 0;   // filter: none
    rgba.copy(raw, y * (width * 4 + 1) + 1, y * width * 4, (y + 1) * width * 4);
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

/** 4x supersampling so the curves and the triangle stay smooth. */
function draw(size) {
  const S = 4, W = size * S;
  const acc = new Float64Array(size * size * 4);

  const r = W * 0.22;                       // corner radius
  const scr = { x0: W * 0.17, y0: W * 0.26, x1: W * 0.83, y1: W * 0.72, r: W * 0.05 };
  const dot = { cx: W * 0.79, cy: W * 0.25, r: W * 0.13 };
  const tri = { x0: W * 0.40, y0: W * 0.38, x1: W * 0.40, y1: W * 0.60, tipX: W * 0.63 };

  const inRounded = (x, y, x0, y0, x1, y1, rad) => {
    const cx = Math.min(Math.max(x, x0 + rad), x1 - rad);
    const cy = Math.min(Math.max(y, y0 + rad), y1 - rad);
    if (x < x0 || x > x1 || y < y0 || y > y1) return false;
    const dx = x < x0 + rad || x > x1 - rad ? x - cx : 0;
    const dy = y < y0 + rad || y > y1 - rad ? y - cy : 0;
    return dx * dx + dy * dy <= rad * rad;
  };

  for (let y = 0; y < W; y++) {
    for (let x = 0; x < W; x++) {
      let col = null;

      if (inRounded(x, y, 0, 0, W - 1, W - 1, r)) col = BG;
      if (col && inRounded(x, y, scr.x0, scr.y0, scr.x1, scr.y1, scr.r)) col = SCREEN;

      // play triangle
      if (col === SCREEN) {
        const t = (y - tri.y0) / (tri.y1 - tri.y0);
        if (t >= 0 && t <= 1) {
          const half = Math.abs(t - 0.5) * 2;
          if (x >= tri.x0 && x <= tri.x0 + (tri.tipX - tri.x0) * (1 - half)) col = GOLD;
        }
      }

      // "new episode" dot
      const dd = (x - dot.cx) ** 2 + (y - dot.cy) ** 2;
      if (dd <= dot.r * dot.r) col = RED;

      const i = (Math.floor(y / S) * size + Math.floor(x / S)) * 4;
      if (col) { acc[i] += col[0]; acc[i + 1] += col[1]; acc[i + 2] += col[2]; acc[i + 3] += 255; }
    }
  }

  const out = Buffer.alloc(size * size * 4);
  const n = S * S;
  for (let i = 0; i < size * size; i++) {
    const a = acc[i * 4 + 3] / n;
    // un-premultiply so the edges do not go dark
    const w = acc[i * 4 + 3] || 1;
    out[i * 4]     = Math.round((acc[i * 4]     / w) * 255);
    out[i * 4 + 1] = Math.round((acc[i * 4 + 1] / w) * 255);
    out[i * 4 + 2] = Math.round((acc[i * 4 + 2] / w) * 255);
    out[i * 4 + 3] = Math.round(a);
  }
  return png(size, size, out);
}

const dir = path.join(__dirname, '..', 'extension', 'icons');
fs.mkdirSync(dir, { recursive: true });
for (const size of [16, 32, 48, 128]) {
  const file = path.join(dir, `icon${size}.png`);
  fs.writeFileSync(file, draw(size));
  console.log('wrote', file);
}
