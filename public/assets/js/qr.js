// Copy of public_hr/assets/js/core/qr.js (Trio HR), used by the CRM admin two-step
// setup screen to draw the authenticator QR code. Keep the two in step.

// QR codes (Model 2, ISO/IEC 18004) rendered as CSP-safe SVG. No dependencies.
//
// Text is encoded as UTF-8 in byte mode. The smallest version (1-40) that holds
// it at the requested error-correction level is used, and the mask (0-7) with
// the lowest standard penalty score is applied. The SVG uses presentation
// attributes only (no style attributes, no <style>), so it renders under the
// portal's strict Content-Security-Policy. It has no width/height: size it with CSS.
//
//   import { qrSvg } from './core/qr.js';
//   html`<div class="qr">${raw(qrSvg(verifyUrl, { label: 'Scan to check this letter is genuine' }))}</div>`

// ---------------------------------------------------------------------------
// Standard tables (ISO/IEC 18004, Table 9). Indexed by version; index 0 unused.
// ---------------------------------------------------------------------------

/** Error-correction codewords in each block. */
const ECC_PER_BLOCK = {
  L: [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
  M: [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
  Q: [0, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
  H: [0, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
};

/** Number of error-correction blocks (both block groups together). */
const BLOCK_COUNT = {
  L: [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
  M: [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
  Q: [0, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
  H: [0, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
};

/** Two-bit error-correction level indicator used in the format information. */
const LEVEL_BITS = { L: 0b01, M: 0b00, Q: 0b11, H: 0b10 };

// ---------------------------------------------------------------------------
// Reed-Solomon error correction over GF(256), primitive polynomial 0x11D.
// ---------------------------------------------------------------------------

const EXP = new Uint8Array(510); // EXP[i] = α^i, stored twice so EXP[LOG[a] + LOG[b]] never wraps
const LOG = new Uint8Array(256);
for (let i = 0, v = 1; i < 255; i++) {
  EXP[i] = EXP[i + 255] = v;
  LOG[v] = i;
  v = (v << 1) ^ (v & 0x80 ? 0x11d : 0);
}

const gfMul = (a, b) => (a && b ? EXP[LOG[a] + LOG[b]] : 0);

const generators = new Map();

/** Generator polynomial (x - α^0)(x - α^1)...(x - α^(n-1)), highest power first. */
const generator = (n) => {
  if (!generators.has(n)) {
    let poly = [1];
    for (let i = 0; i < n; i++) {
      const next = new Array(poly.length + 1).fill(0);
      poly.forEach((c, j) => {
        next[j] ^= c; // c·x
        next[j + 1] ^= gfMul(c, EXP[i]); // c·α^i (subtraction is XOR in GF(2^8))
      });
      poly = next;
    }
    generators.set(n, poly);
  }
  return generators.get(n);
};

/** The n ECC codewords of one block: data(x)·x^n mod generator(x). */
const rsRemainder = (data, n) => {
  const gen = generator(n);
  const rem = new Array(n).fill(0);
  for (const byte of data) {
    const factor = byte ^ rem.shift();
    rem.push(0);
    for (let i = 0; i < n; i++) rem[i] ^= gfMul(gen[i + 1], factor);
  }
  return rem;
};

// ---------------------------------------------------------------------------
// Version geometry and capacity.
// ---------------------------------------------------------------------------

const sizeOf = (version) => version * 4 + 17;

/**
 * Row/column centres of the alignment patterns (ISO/IEC 18004 Annex E): 6, then
 * evenly spaced up to size - 7 with an even step, the first gap taking the slack.
 * Version 32 is the one entry of the standard's table this rule misses (26, not 28).
 */
const alignmentCentres = (version) => {
  if (version === 1) return [];
  const count = Math.floor(version / 7) + 2;
  const last = sizeOf(version) - 7;
  const step = version === 32 ? 26 : Math.ceil((last - 6) / (count - 1) / 2) * 2;
  return [6, ...Array.from({ length: count - 1 }, (_, i) => last - (count - 2 - i) * step)];
};

/** Modules left for data + ECC bits once every function pattern is reserved. */
const dataModules = (version) => {
  const size = sizeOf(version);
  const n = version === 1 ? 0 : Math.floor(version / 7) + 2; // alignment centres per axis
  let count = size * size
    - 3 * 64 // three finder patterns with their separators, 8×8 each
    - 2 * (size - 16) // the two timing patterns between the separators
    - 2 * 15 - 1; // two copies of the format information, plus the dark module
  if (n) count -= 25 * (n * n - 3) - 10 * (n - 2); // alignment patterns, less their overlap with timing
  if (version >= 7) count -= 2 * 18; // two copies of the version information
  return count;
};

const totalCodewords = (version) => Math.floor(dataModules(version) / 8);

const dataCapacity = (version, level) =>
  totalCodewords(version) - ECC_PER_BLOCK[level][version] * BLOCK_COUNT[level][version];

// ---------------------------------------------------------------------------
// Data encoding: byte-mode segment, terminator and padding, then RS blocks.
// ---------------------------------------------------------------------------

const pickVersion = (byteLength, level) => {
  for (let version = 1; version <= 40; version++) {
    const bitsNeeded = 4 + (version < 10 ? 8 : 16) + byteLength * 8;
    if (bitsNeeded <= dataCapacity(version, level) * 8) return version;
  }
  const max = dataCapacity(40, level) - 3; // the 20 header bits cost 3 codewords
  throw new Error(`QR code: text is too long (${byteLength} bytes as UTF-8; level ${level} holds at most ${max}).`);
};

/** Data codewords: mode + count header, the bytes, terminator, then 0xEC/0x11 padding. */
const encodeData = (bytes, version, capacity) => {
  const bits = [];
  const put = (value, length) => {
    for (let i = length - 1; i >= 0; i--) bits.push((value >>> i) & 1);
  };
  put(0b0100, 4); // mode indicator: byte
  put(bytes.length, version < 10 ? 8 : 16); // character count indicator
  bytes.forEach((byte) => put(byte, 8));
  put(0, Math.min(4, capacity * 8 - bits.length)); // terminator, cut short if the symbol is full
  put(0, (8 - (bits.length % 8)) % 8); // zero-fill to a codeword boundary
  const codewords = [];
  for (let i = 0; i < bits.length; i += 8) {
    codewords.push(bits.slice(i, i + 8).reduce((acc, bit) => (acc << 1) | bit, 0));
  }
  for (let pad = 0xec; codewords.length < capacity; pad ^= 0xec ^ 0x11) codewords.push(pad);
  return codewords;
};

/** Splits the data into RS blocks, appends each block's ECC, and interleaves them all. */
const addErrorCorrection = (data, version, level) => {
  const blockCount = BLOCK_COUNT[level][version];
  const eccLength = ECC_PER_BLOCK[level][version];
  const total = totalCodewords(version);
  const longBlocks = total % blockCount; // the last blocks carry one extra data codeword
  const shortLength = Math.floor(total / blockCount) - eccLength;
  const blocks = [];
  for (let b = 0, at = 0; b < blockCount; b++) {
    const length = shortLength + (b >= blockCount - longBlocks ? 1 : 0);
    blocks.push(data.slice(at, at + length));
    at += length;
  }
  const eccBlocks = blocks.map((block) => rsRemainder(block, eccLength));
  const out = [];
  for (let i = 0; i <= shortLength; i++) blocks.forEach((block) => i < block.length && out.push(block[i]));
  for (let i = 0; i < eccLength; i++) eccBlocks.forEach((block) => out.push(block[i]));
  return out;
};

// ---------------------------------------------------------------------------
// Module matrix: function patterns, codeword placement, masking.
// ---------------------------------------------------------------------------

/** A blank symbol: `dark` holds module colours, `fixed` marks function modules. */
const blankSymbol = (size) => {
  const grid = () => Array.from({ length: size }, () => new Array(size).fill(false));
  return { size, dark: grid(), fixed: grid() };
};

const setFunction = (sym, x, y, isDark) => {
  sym.dark[y][x] = isDark;
  sym.fixed[y][x] = true;
};

/** Writes both copies of the 15-bit format information (level + mask, BCH-protected). */
const drawFormat = (sym, level, mask) => {
  const { size } = sym;
  const data = (LEVEL_BITS[level] << 3) | mask;
  let rem = data;
  for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537); // BCH(15,5) remainder
  const bits = ((data << 10) | rem) ^ 0x5412;
  for (let i = 0; i < 15; i++) {
    const bit = ((bits >>> i) & 1) === 1;
    // Copy 1 wraps the top-left finder: bits 0-7 down column 8, bits 8-14 leftwards
    // along row 8, both skipping the timing module at index 6.
    if (i < 6) setFunction(sym, 8, i, bit);
    else if (i < 8) setFunction(sym, 8, i + 1, bit);
    else if (i === 8) setFunction(sym, 7, 8, bit);
    else setFunction(sym, 14 - i, 8, bit);
    // Copy 2 is split: bits 0-7 right-to-left on row 8 under the top-right finder,
    // bits 8-14 downwards in column 8 beside the bottom-left finder.
    if (i < 8) setFunction(sym, size - 1 - i, 8, bit);
    else setFunction(sym, 8, size - 15 + i, bit);
  }
};

/** Finder, separator, timing, alignment and version patterns; reserves the format areas. */
const drawFunctionPatterns = (sym, version) => {
  const { size } = sym;
  for (let i = 0; i < size; i++) {
    // Timing patterns along row 6 and column 6 (the finders overwrite their ends).
    setFunction(sym, 6, i, i % 2 === 0);
    setFunction(sym, i, 6, i % 2 === 0);
  }
  for (const [cx, cy] of [[3, 3], [size - 4, 3], [3, size - 4]]) {
    // Finder: dark 3×3 core, light ring, dark ring, then the light separator ring.
    for (let dy = -4; dy <= 4; dy++) {
      for (let dx = -4; dx <= 4; dx++) {
        const x = cx + dx;
        const y = cy + dy;
        const ring = Math.max(Math.abs(dx), Math.abs(dy));
        if (x >= 0 && y >= 0 && x < size && y < size) setFunction(sym, x, y, ring !== 2 && ring !== 4);
      }
    }
  }
  const centres = alignmentCentres(version);
  const last = centres.length - 1;
  centres.forEach((cy, i) => centres.forEach((cx, j) => {
    // Alignment pattern (5×5), except at the three corners the finders occupy.
    if ((i === 0 && (j === 0 || j === last)) || (i === last && j === 0)) return;
    for (let dy = -2; dy <= 2; dy++) {
      for (let dx = -2; dx <= 2; dx++) {
        setFunction(sym, cx + dx, cy + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
      }
    }
  }));
  if (version >= 7) {
    // Version information: 6 bits + 12 BCH bits, as two transposed 6×3 blocks.
    let rem = version;
    for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
    const bits = (version << 12) | rem;
    for (let i = 0; i < 18; i++) {
      const bit = ((bits >>> i) & 1) === 1;
      const a = size - 11 + (i % 3);
      const b = Math.floor(i / 3);
      setFunction(sym, a, b, bit); // top right
      setFunction(sym, b, a, bit); // bottom left
    }
  }
  drawFormat(sym, 'M', 0); // placeholder: rewritten for every mask tried
  setFunction(sym, 8, size - 8, true); // the always-dark module
};

/** Places codeword bits (MSB first) in the two-column zig-zag, skipping function modules. */
const placeCodewords = (sym, codewords) => {
  const { size, dark, fixed } = sym;
  const totalBits = codewords.length * 8;
  let n = 0;
  let upward = true;
  for (let right = size - 1; right > 0; right -= 2) {
    if (right === 6) right = 5; // column 6 holds the vertical timing pattern
    for (let i = 0; i < size; i++) {
      const y = upward ? size - 1 - i : i;
      for (const x of [right, right - 1]) {
        if (fixed[y][x]) continue;
        // Any leftover remainder bits (n >= totalBits) stay light before masking.
        dark[y][x] = n < totalBits && ((codewords[n >>> 3] >>> (7 - (n & 7))) & 1) === 1;
        n++;
      }
    }
    upward = !upward;
  }
};

/** The eight data masks: a module is inverted where the condition holds (x = column, y = row). */
const MASKS = [
  (x, y) => (x + y) % 2 === 0,
  (x, y) => y % 2 === 0,
  (x) => x % 3 === 0,
  (x, y) => (x + y) % 3 === 0,
  (x, y) => (Math.floor(y / 2) + Math.floor(x / 3)) % 2 === 0,
  (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
  (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0,
  (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
];

/** XORs a mask over the non-function modules, so applying it twice undoes it. */
const applyMask = (sym, mask) => {
  const inverted = MASKS[mask];
  for (let y = 0; y < sym.size; y++) {
    for (let x = 0; x < sym.size; x++) {
      if (!sym.fixed[y][x] && inverted(x, y)) sym.dark[y][x] = !sym.dark[y][x];
    }
  }
};

/** Penalty score of a finished symbol (ISO/IEC 18004 §7.8.3); lower is better. */
const penalty = (dark) => {
  const size = dark.length;
  let score = 0;
  const lines = [];
  for (let i = 0; i < size; i++) lines.push(dark[i], dark.map((row) => row[i]));
  for (const line of lines) {
    // Rule 1: a run of 5+ same-coloured modules scores 3, plus 1 per module beyond 5.
    for (let start = 0, end = 1; end <= size; end++) {
      if (end < size && line[end] === line[start]) continue;
      if (end - start >= 5) score += end - start - 2;
      start = end;
    }
    // Rule 3: a finder-like 1:1:3:1:1 run with 4 light modules on a side scores 40
    // per side (the quiet zone beyond the edge counts as light).
    const s = `0000${line.map((m) => (m ? 1 : 0)).join('')}0000`;
    for (let i = s.indexOf('1011101'); i !== -1; i = s.indexOf('1011101', i + 1)) {
      if (s.startsWith('0000', i - 4)) score += 40;
      if (s.startsWith('0000', i + 7)) score += 40;
    }
  }
  let darkCount = 0;
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      if (dark[y][x]) darkCount++;
      // Rule 2: each 2×2 block of one colour scores 3.
      const c = dark[y][x];
      if (x && y && c === dark[y - 1][x] && c === dark[y][x - 1] && c === dark[y - 1][x - 1]) score += 3;
    }
  }
  // Rule 4: 10 points for every full 5% the share of dark modules strays from 50%.
  score += 10 * Math.floor(Math.abs((darkCount * 100) / (size * size) - 50) / 5);
  return score;
};

// ---------------------------------------------------------------------------
// Public API.
// ---------------------------------------------------------------------------

/**
 * Module matrix of the QR code for `text` (UTF-8, byte mode) at error-correction
 * level 'L' (~7%), 'M' (~15%), 'Q' (~25%) or 'H' (~30%). Throws if it doesn't fit.
 * @returns {{ size: number, modules: boolean[][] }} `size` rows of `size` modules,
 *   true = dark, quiet zone not included.
 */
export const qrMatrix = (text, ecc = 'M') => {
  const level = String(ecc).toUpperCase();
  if (!['L', 'M', 'Q', 'H'].includes(level)) {
    throw new Error(`QR code: unknown error-correction level "${ecc}" (use L, M, Q or H).`);
  }
  const bytes = new TextEncoder().encode(String(text ?? ''));
  const version = pickVersion(bytes.length, level);
  const sym = blankSymbol(sizeOf(version));
  drawFunctionPatterns(sym, version);
  const data = encodeData(bytes, version, dataCapacity(version, level));
  placeCodewords(sym, addErrorCorrection(data, version, level));
  // Try every mask and keep the one with the lowest penalty.
  let best = 0;
  let bestScore = Infinity;
  for (let mask = 0; mask < 8; mask++) {
    applyMask(sym, mask);
    drawFormat(sym, level, mask);
    const score = penalty(sym.dark);
    if (score < bestScore) [best, bestScore] = [mask, score];
    applyMask(sym, mask); // undo
  }
  applyMask(sym, best);
  drawFormat(sym, level, best);
  return { size: sym.size, modules: sym.dark };
};

const XML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const xml = (value) => String(value).replace(/[&<>"']/g, (c) => XML_ESCAPES[c]);

/**
 * The QR code for `text` as SVG markup: a `light` background rect and a single
 * `dark` path, with a quiet zone of `border` modules on every side. There is no
 * width/height, so the page sizes it with CSS; `label` becomes its accessible name.
 */
export const qrSvg = (text, { ecc = 'M', border = 4, dark = '#0B2353', light = '#FFFFFF', label = 'QR code' } = {}) => {
  const { size, modules } = qrMatrix(text, ecc);
  const quiet = Number.isFinite(border) && border >= 0 ? Math.floor(border) : 4;
  const extent = size + quiet * 2;
  let d = '';
  modules.forEach((row, y) => {
    for (let x = 0; x < size;) {
      if (!row[x]) {
        x++;
        continue;
      }
      const start = x; // one rectangle per horizontal run of dark modules
      while (x < size && row[x]) x++;
      d += `M${start + quiet},${y + quiet}h${x - start}v1h-${x - start}z`;
    }
  });
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${extent} ${extent}" role="img" aria-label="${xml(label)}">`
    + `<rect width="${extent}" height="${extent}" fill="${xml(light)}"/>`
    + `<path d="${d}" fill="${xml(dark)}" shape-rendering="crispEdges"/></svg>`;
};
