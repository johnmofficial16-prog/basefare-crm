// A letter on Trio's letterhead (v3 design: navy and gold bands, logo | gold
// divider | Garamond wordmark, registered office and CIN, faint diagonal
// watermark). Used by the admin preview, the staff view and the print page.

import { html, raw } from './dom.js';
import { qrSvg } from './qr.js';
import { day } from './fmt.js';

function addressLines(address) {
  const parts = String(address || '').split(/,\s*/).filter(Boolean);
  const lines = [];
  for (let i = 0; i < parts.length; i += 2) lines.push(parts.slice(i, i + 2).join(', '));
  return lines;
}

/**
 * @param {object} d  { letter, person, letterhead } as returned by the API
 * @param {object} o  { qr: show the verification QR (issued letters) }
 */
export function letterSheet(d, o = {}) {
  const l = d.letter;
  const lh = d.letterhead;
  const issued = ['issued', 'acknowledged'].includes(l.status);
  const verifyHost = l.verify_url ? l.verify_url.replace(/^https?:\/\//, '').replace(/\/#\/verify\/.*$/, '') : '';
  return html`
  <article class="ltr ${issued ? '' : 'ltr--draft'} ${l.status === 'void' ? 'ltr--void' : ''}" aria-label="${l.subject}">
    <div class="ltr__band ltr__band--top" aria-hidden="true"></div>
    <div class="ltr__mark" aria-hidden="true"><img src="assets/img/trio-logo.png" alt=""></div>
    <svg class="ltr__arcs" viewBox="0 0 100 100" aria-hidden="true" focusable="false">${raw([22, 26.2, 30.4, 34.6, 38.8]
      .map((r, i) => `<circle cx="0" cy="100" r="${r}" fill="none" stroke="${i % 2 ? '#0B2353' : '#A8842C'}" stroke-opacity="${[0.32, 0.14, 0.18, 0.09, 0.1][i]}" stroke-width="${i % 2 ? 0.25 : 0.4}"/>`).join(''))}</svg>
    ${!issued ? html`<div class="ltr__stamp" aria-hidden="true">${l.status === 'void' ? 'CANCELLED' : 'DRAFT'}</div>` : ''}
    <table class="ltr__flow" role="presentation">
    <thead><tr><td><div class="ltr__gap ltr__gap--top"></div></td></tr></thead>
    <tfoot><tr><td><div class="ltr__gap ltr__gap--bottom"></div></td></tr></tfoot>
    <tbody><tr><td>
    <header class="ltr__head">
      <img class="ltr__logo" src="assets/img/trio-logo.png" alt="Trio Tours & Travels">
      <div class="ltr__name"><b>TRIO TOURS &amp; TRAVELS</b><span>PRIVATE LIMITED</span></div>
      <div class="ltr__office">
        <span class="ltr__k">Registered office</span>
        ${addressLines(lh.address).map((x) => html`<span>${x}</span>`)}
        ${lh.cin ? html`<span class="ltr__cin"><b>CIN</b> ${lh.cin}</span>` : ''}
      </div>
    </header>
    <div class="ltr__rule" aria-hidden="true"></div>
    <div class="ltr__meta"><span><b>Ref. No.:</b> ${l.number || '—'}</span><span><b>Date:</b> ${l.date}</span></div>
    <h2 class="ltr__subject">${l.subject}</h2>
    <div class="doc ltr__body">${o.body || raw(l.html)}</div>
    <div class="ltr__close">
      ${o.unsigned ? html`<div class="ltr__sign"><span>${o.unsigned}</span></div>` : html`<div class="ltr__sign">
        <span>For ${lh.legal_name || 'Trio Tours & Travels Private Limited'}</span>
        <i class="ltr__sigspace" aria-hidden="true"></i>
        <b>${lh.signatory_name || 'Authorised signatory'}</b>
        <span>${lh.signatory_title || ''}</span>
      </div>`}
      ${o.qr && issued && l.verify_url ? html`<aside class="ltr__verify">
        <div class="ltr__qr">${raw(qrSvg(l.verify_url, { ecc: 'M', border: 2, label: `Scan to check this ${o.kind || 'letter'} is genuine` }))}</div>
        <p><span class="ltr__k">Verify this ${o.kind || 'letter'}</span>Scan the code, or go to<br><b class="nowrap">${verifyHost}/verify</b><br>and enter <b class="tnum nowrap">${l.verify_code}</b>.</p>
      </aside>` : ''}
    </div>
    ${l.ack_at ? html`<p class="ltr__ack">Read and accepted by ${l.ack_name} in Trio HR on ${day(l.ack_at)}.</p>` : ''}
    </td></tr></tbody>
    </table>
    <div class="ltr__foot" aria-hidden="true"><span>TRIO TOURS &amp; TRAVELS PRIVATE LIMITED</span><i>◆</i><span>MOHALI, PUNJAB, INDIA</span></div>
    <div class="ltr__band ltr__band--bottom" aria-hidden="true"></div>
  </article>`;
}
