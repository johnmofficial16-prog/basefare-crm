// The one way the app talks to the server. JSON only; every change carries the
// session's CSRF token; failures become plain-English ApiErrors. A response
// that isn't our JSON almost always means the hosting firewall answered.

let csrf = null;
let signedOut = () => {};

/** When the server last heard from this person (background polls don't count). */
export const contact = { at: Date.now() };

export function setCsrf(token) { csrf = token; }
export function onSignedOut(fn) { signedOut = fn; }

export class ApiError extends Error {
  constructor(message, status = 0, reason = 'error', fields = null, ref = null) {
    super(message);
    this.status = status;
    this.reason = reason;
    this.fields = fields;
    this.ref = ref;
  }
}

async function call(method, path, body, opts = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET' && csrf) headers['X-CSRF-Token'] = csrf;
  if (opts.background) headers['X-Trio-Background'] = '1';

  let res;
  try {
    res = await fetch('api' + path, {
      method, headers, credentials: 'same-origin', cache: 'no-store',
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError("Can't reach Trio HR. Check the internet connection and try again.", 0, 'offline');
  }

  let data = null;
  if ((res.headers.get('Content-Type') || '').includes('application/json')) {
    try { data = await res.json(); } catch { data = null; }
  }
  if (!opts.background && res.status !== 401) contact.at = Date.now();
  if (res.ok && data) return data;
  if (!data) {
    if ([403, 406, 429, 503].includes(res.status)) {
      throw new ApiError(`The hosting firewall stopped that request before it reached Trio HR. Please try again in a minute, or from another network. (Reference WAF-${res.status})`, res.status, 'waf');
    }
    throw new ApiError(`Trio HR didn't answer properly (code ${res.status}). Please try again.`, res.status, 'bad_response');
  }
  const err = data.error || {};
  if (res.status === 401 && err.reason === 'signed_out') {
    signedOut(); // the app says it once and returns them here after signing in
    throw new ApiError('', 401, 'signed_out');
  }
  // Signed in again in another tab: this tab's token is old. Fetch the current one and retry once.
  if (res.status === 419 && err.reason === 'csrf' && !opts.retried) {
    try {
      const s = await call('GET', '/session', undefined, {});
      if (s && s.csrf) { csrf = s.csrf; return call(method, path, body, { ...opts, retried: true }); }
    } catch {}
  }
  const e = new ApiError(err.message || 'Something went wrong.', res.status, err.reason || 'error', err.fields || null, err.ref || null);
  if (err.detail) console.error('[Trio HR]', err.ref, err.detail); // local preview only
  throw e;
}

/**
 * Upload one file as the raw request body (no multipart form: the hosting
 * firewall tends to block those). Details travel in X-Doc-* headers.
 */
async function upload(path, file, details = {}) {
  const headers = {
    Accept: 'application/json',
    'Content-Type': file.type || 'application/octet-stream',
    'X-File-Name': encodeURIComponent(file.name || 'document'),
  };
  if (csrf) headers['X-CSRF-Token'] = csrf;
  for (const [k, v] of Object.entries(details)) if (v) headers[k] = encodeURIComponent(v);
  let res;
  try {
    res = await fetch('api' + path, { method: 'POST', headers, body: file, credentials: 'same-origin', cache: 'no-store' });
  } catch {
    throw new ApiError("Can't reach Trio HR. Check the internet connection and try again.", 0, 'offline');
  }
  let data = null;
  if ((res.headers.get('Content-Type') || '').includes('application/json')) {
    try { data = await res.json(); } catch { data = null; }
  }
  if (res.ok && data) return data;
  if (!data) throw new ApiError(`The hosting firewall stopped the upload before it reached Trio HR. Please try again, or from another network. (Reference WAF-${res.status})`, res.status, 'waf');
  const err = data.error || {};
  if (res.status === 401 && err.reason === 'signed_out') {
    signedOut();
    throw new ApiError('', 401, 'signed_out');
  }
  throw new ApiError(err.message || 'The upload failed.', res.status, err.reason || 'error', err.fields || null, err.ref || null);
}

export const api = {
  get: (path, opts) => call('GET', path, undefined, opts),
  post: (path, body = {}) => call('POST', path, body),
  patch: (path, body = {}) => call('PATCH', path, body),
  del: (path) => call('DELETE', path, {}),
  upload,
};
