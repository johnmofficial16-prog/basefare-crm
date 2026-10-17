// Trio Mail for staff (phone-first): their conversations with HR, the
// announcements sent to them, and "Write to HR". For leave, attendance and
// letters the request is filed together with the message.

import { html, raw, mount, $, $$, icon, formData, showFieldErrors } from '../core/dom.js';
import { api } from '../core/api.js';
import { skeleton, errorView, toast, busy } from '../core/ui.js';
import * as f from '../core/fmt.js';
import { go } from '../app.js';
import { fileChips } from './mail.js';

const REQ_PILL = { pending: 'p-warn', approved: 'p-ok', partly_approved: 'p-ok', sent_back: 'p-bad', withdrawn: 'p-neutral' };

function stateOf(t) {
  if (t.request && t.request.status !== 'pending') return [REQ_PILL[t.request.status], t.request.status_label];
  if (t.last_from !== 'staff') return t.unread ? ['p-gold', 'New reply'] : ['p-ok', 'HR replied'];
  if (t.seen) return ['p-info', 'Seen by HR'];
  return ['p-neutral', 'Sent'];
}

// ---- the inbox -----------------------------------------------------------------

export async function list({ main, head }) {
  head({ title: 'Inbox' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get('/me/mail'); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <a class="bigbtn" href="#/me/mail/new">${icon('edit')}Write to HR</a>
    ${d.announcements.length ? html`<div class="tile tile--list"><div class="listhead"><b>Announcements</b></div>
      ${d.announcements.map((a) => html`<a class="listrow mrow-p ${a.unread ? 'is-unread' : ''}" href="#/me/mail/a${a.id}">
        <span class="dot dot--sm ${a.unread ? 'd-gold' : 'd-neutral'}">${icon(a.pinned ? 'flag' : 'speaker', 'i--sm')}</span>
        <div><b>${a.title}</b><small>${a.preview}</small></div><span class="r meta">${f.stamp(a.at) === 'Today' ? f.time(a.at) : f.day(a.at)}</span></a>`)}</div>` : ''}
    <div class="tile tile--list"><div class="listhead"><b>Your conversations with HR</b></div>
      ${d.threads.length ? d.threads.map((t) => {
        const [pill, label] = stateOf(t);
        return html`<a class="listrow mrow-p ${t.unread ? 'is-unread' : ''}" href="#/me/mail/${t.id}">
          <span class="dot dot--sm ${t.unread ? 'd-gold' : 'd-neutral'}">${icon(t.private ? 'lock' : 'mail', 'i--sm')}</span>
          <div><b>${t.subject}</b><small>${t.last_from === 'staff' ? 'You: ' : t.last_from === 'system' ? 'Trio HR: ' : 'HR: '}${t.preview}</small></div>
          <span class="r"><span class="meta">${f.stamp(t.last_at) === 'Today' ? f.time(t.last_at) : f.day(t.last_at)}</span><br><span class="pill pill--sm ${pill}">${label}</span></span></a>`; })
      : html`<div class="empty"><b>No messages yet</b><p>Ask HR anything: leave, your pay, a letter, a problem. Write in English, Hindi or Hinglish.</p></div>`}</div>`);
}

// ---- one conversation ------------------------------------------------------------

export async function thread({ main, head, params }) {
  head({ title: 'Message', back: '#/me/mail' });
  mount(main, skeleton('list'));
  let d;
  try { d = await api.get(`/me/mail/${params[0]}`); } catch (err) { mount(main, errorView(err)); return; }
  const t = d.thread;
  head({ title: t.topic_label, back: '#/me/mail' });
  const lastStaff = [...d.messages].reverse().find((m) => m.sender === 'staff');
  mount(main, html`
    <h1 class="p-title">${t.subject}</h1>
    ${t.private ? html`<div class="banner banner--info">${icon('lock')}<div>Private. Only HR admins can read this conversation.</div></div>` : ''}
    ${t.request ? html`<div class="banner ${t.request.status === 'pending' ? 'banner--warn' : t.request.status === 'sent_back' ? 'banner--bad' : 'banner--ok'}">${icon(t.request.status === 'pending' ? 'clock' : t.request.status === 'sent_back' ? 'back' : 'check')}
      <div><b>${t.request.type_label}: ${t.request.status === 'pending' ? 'waiting for approval' : f.lower(t.request.status_label)}</b>${t.request.summary}</div></div>` : ''}
    ${t.action ? html`<a class="bigbtn" href="${t.action.href}">${icon('clock')}${t.action.label}</a>` : ''}
    <ol class="msgs msgs--p" aria-label="Messages">${d.messages.map((m) => m.sender === 'system'
      ? html`<li class="msg msg--sys"><div class="msg__sys">${icon('check', 'i--sm')}<div><div class="msg__b">${m.body}</div><small>${f.day(m.at)}, ${f.time(m.at)}</small></div></div></li>`
      : html`<li class="msg ${m.sender === 'staff' ? 'msg--me' : 'msg--them'}">
          <div class="msg__h"><b>${m.sender === 'staff' ? 'You' : (m.author ? `${m.author} · HR` : 'HR')}</b><time>${f.day(m.at)}, ${f.time(m.at)}</time></div>
          <div class="msg__b">${m.body}</div>${fileChips(m.files)}
          ${m === lastStaff && t.seen_at ? html`<div class="seen">${icon('check', 'i--sm')}Seen by HR ${f.ago(t.seen_at)}</div>` : ''}</li>`)}</ol>
    ${t.can_reply ? html`<form class="tile form" id="rf" novalidate>
      <div class="field"><label for="rf-b">Reply</label><textarea class="textarea" id="rf-b" name="body" rows="3" placeholder="Write here, in any language"></textarea><div class="err" role="alert"></div></div>
      <div class="att" id="rf-files"></div>
      <div class="row"><label class="btn btn--sm btn--quiet filebtn">${icon('clip', 'i--sm')}Photo or PDF<input type="file" id="rf-file" accept="application/pdf,image/jpeg,image/png,image/webp" class="sr"></label>
        <button class="btn btn--pri push" type="submit">${icon('send')}Send</button></div>
    </form>` : ''}`);
  $('.msgs li:last-child', main)?.scrollIntoView({ block: 'nearest' });
  const form = $('#rf', main);
  if (!form) return;
  const files = attachments(form, '#rf-file', '#rf-files');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!form.body.value.trim()) { showFieldErrors(form, { body: 'Write your reply.' }); return; }
    busy($('[type="submit"]', form), async () => {
      try {
        const r = await api.post(`/me/mail/${t.id}/reply`, { body: form.body.value, files: files.ids() });
        toast(r.message);
        go(`#/me/mail/${t.id}`, { replace: true });
      } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
    });
  });
}

export async function announcement({ main, head, params }) {
  head({ title: 'Announcement', back: '#/me/mail' });
  mount(main, skeleton('list'));
  let a;
  try { a = await api.get(`/me/announcements/${params[0]}`); } catch (err) { mount(main, errorView(err)); return; }
  mount(main, html`
    <h1 class="p-title">${a.title}</h1>
    <p class="meta">From ${a.by || 'HR'} · ${f.day(a.at)}, ${f.time(a.at)}</p>
    <div class="tile"><div class="msg__b">${a.body}</div></div>`);
}

/** Attachment picking: uploads at once, shows chips, remembers the ids. */
function attachments(form, inputSel, listSel) {
  const files = [];
  const draw = () => mount($(listSel, form), html`${files.map((x, i) => html`<span class="attchip">${icon(x.mime === 'application/pdf' ? 'file' : 'image', 'i--sm')}<span>${x.name}</span><button type="button" class="iconbtn iconbtn--xs" data-rm="${i}" aria-label="Remove ${x.name}">${icon('x', 'i--sm')}</button></span>`)}`);
  $(listSel, form).addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { files.splice(Number(b.dataset.rm), 1); draw(); } });
  $(inputSel, form).addEventListener('change', async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    if (file.size > 8 * 1024 * 1024) { toast('That file is over 8 MB. Try a smaller photo.', { tone: 'bad' }); return; }
    if (files.length >= 5) { toast('Up to 5 files per message.', { tone: 'bad' }); return; }
    const label = $(inputSel, form).closest('label');
    label?.classList.add('is-busy');
    try { files.push(await api.upload('/mail/files', file)); draw(); }
    catch (err) { toast(err.message, { tone: 'bad' }); }
    finally { label?.classList.remove('is-busy'); }
  });
  return { ids: () => files.map((x) => x.id) };
}

// ---- write to HR ------------------------------------------------------------------

const PLACEHOLDER = {
  leave: "For example: My cousin's wedding in Ludhiana",
  attendance: 'For example: The internet was down, so I couldn\'t punch out',
  pay: 'For example: My September incentive looks lower than I expected',
  letter: 'Anything HR should know about the letter',
  documents: 'For example: Here is my new PAN card',
  complaint: 'Tell HR what happened. Only HR admins can read this.',
  other: 'Write in English, Hindi or Hinglish',
};

export async function compose({ main, head, query }) {
  head({ title: 'Write to HR', back: '#/me/mail' });
  mount(main, skeleton('list'));
  let m;
  try { m = await api.get('/me/mail/new'); } catch (err) { mount(main, errorView(err)); return; }
  const about = Number(query.get('about')) || null;
  const aboutReq = about ? m.requests.find((r) => r.id === about) : null;
  let topic = query.get('topic') && m.topics.some((t) => t.key === query.get('topic')) ? query.get('topic') : (aboutReq ? 'other' : '');
  const bal = Object.fromEntries(m.leave.balances.map((b) => [b.code, b.available - b.pending]));
  const first = m.leave.first_night;

  const extras = () => {
    if (aboutReq) return html`<div class="banner banner--info">${icon('link')}<div><b>About your request</b>${aboutReq.type_label}: ${aboutReq.summary} (${f.lower(aboutReq.status_label)})</div></div>`;
    if (topic === 'leave') return html`
      <div class="field"><span class="flabel">What kind of leave?</span>
        <div class="typegrid">${m.leave.types.map((t) => html`<button type="button" data-ltype="${t.code}" aria-pressed="false" ${t.needs_balance && bal[t.code] <= 0 ? raw('disabled') : ''}>
          <b>${t.name}</b><span>${t.needs_balance ? `${f.bal(Math.max(0, bal[t.code] ?? 0))} left` : 'Not paid'}${t.min_notice_days ? ` · ${t.min_notice_days === 1 ? "1 day's" : t.min_notice_days + " days'"} notice` : ''}</span></button>`)}</div>
        <input type="hidden" name="leave_type"><div class="err" role="alert"></div></div>
      <div class="form__grid">
        <div class="field"><label for="c-from">First day</label><input class="input" type="date" id="c-from" name="from" value="${first}" min="${first}"><div class="err" role="alert"></div></div>
        <div class="field"><label for="c-to">Last day</label><input class="input" type="date" id="c-to" name="to" value="${first}" min="${first}"><div class="err" role="alert"></div></div>
      </div>`;
    if (topic === 'attendance') return m.nights.length ? html`
      <div class="field"><span class="flabel">Which day is missing a punch-out? <span class="opt">(optional)</span></span>
        <div class="chips">${m.nights.map((n) => html`<button type="button" class="chip" data-night="${n.ref}" aria-pressed="false">${n.label}</button>`)}</div>
        <input type="hidden" name="night"><div class="err" role="alert"></div></div>
      <div class="field" id="c-timef" hidden><label for="c-time">When did you leave?</label><input class="input" type="time" id="c-time" name="time"><div class="err" role="alert"></div></div>`
      : html`<p class="meta">No missed punch-outs in the last few days. Just tell HR what looks wrong.</p>`;
    if (topic === 'letter') return html`
      <div class="field"><span class="flabel">Which letter?</span>
        <div class="typegrid">${m.letters.map((l) => html`<button type="button" data-tpl="${l.code}" aria-pressed="false"><b>${l.name}</b></button>`)}</div>
        <input type="hidden" name="template_code"><div class="err" role="alert"></div></div>
      <div class="field"><label for="c-p">What is it for?</label><input class="input" id="c-p" name="purpose" placeholder="For example: a bank loan, a visa"><div class="err" role="alert"></div></div>`;
    return '';
  };

  const draw = () => {
    mount(main, html`<form class="form" id="cf" novalidate>
      ${aboutReq ? '' : topic ? html`<div class="topicpick">${icon(m.topics.find((t) => t.key === topic).icon)}<div><span class="label">About</span><b>${m.topics.find((t) => t.key === topic).label}</b></div>
          <button type="button" class="btn btn--sm btn--quiet" id="retopic">Change</button><input type="hidden" name="topic" value="${topic}"></div>`
        : html`<div class="field"><span class="flabel">What is it about?</span>
        <div class="typegrid typegrid--topics">${m.topics.map((t) => html`<button type="button" data-topic="${t.key}" aria-pressed="false">${icon(t.icon)}<b>${t.label}</b><span>${t.hint}</span></button>`)}</div>
        <input type="hidden" name="topic" value=""><div class="err" role="alert"></div></div>`}
      ${topic || aboutReq ? html`
        ${extras()}
        <div class="field"><label for="c-s">Subject <span class="opt">(optional)</span></label><input class="input" id="c-s" name="subject" maxlength="160" placeholder="${topic === 'leave' || topic === 'attendance' || topic === 'letter' ? 'Filled in for you' : 'A few words'}"><div class="err" role="alert"></div></div>
        <div class="field"><label for="c-b">Message</label><textarea class="textarea" id="c-b" name="body" rows="5" placeholder="${PLACEHOLDER[topic] || PLACEHOLDER.other}"></textarea><div class="err" role="alert"></div></div>
        <div class="att" id="c-files"></div>
        <label class="btn btn--block btn--quiet filebtn">${icon('clip')}Add a photo or PDF<input type="file" id="c-file" accept="application/pdf,image/jpeg,image/png,image/webp" class="sr"></label>
        <button class="bigbtn" type="submit">${icon('send')}${topic === 'leave' ? 'Send and ask for leave' : topic === 'letter' ? 'Send and ask for the letter' : 'Send to HR'}</button>
        <p class="meta">${topic === 'complaint' ? 'Only HR admins can read this. Every time it is opened is recorded.' : 'HR usually replies within a day. You will see the reply here and in the CRM bell.'}</p>` : ''}
    </form>`);
    wire();
  };

  const wire = () => {
    const form = $('#cf', main);
    $$('[data-topic]', form).forEach((b) => b.addEventListener('click', () => { topic = b.dataset.topic; draw(); window.scrollTo(0, 0); ($('[data-ltype]:not([disabled]), [data-tpl], [data-night]', main) || $('#c-b', main))?.focus(); }));
    $('#retopic', form)?.addEventListener('click', () => { topic = ''; draw(); $('[data-topic]', main)?.focus(); });
    if (!topic && !aboutReq) return;
    const files = attachments(form, '#c-file', '#c-files');
    $$('[data-ltype]', form).forEach((b) => b.addEventListener('click', () => {
      $$('[data-ltype]', form).forEach((x) => x.setAttribute('aria-pressed', 'false'));
      b.setAttribute('aria-pressed', 'true');
      form.leave_type.value = b.dataset.ltype;
      form.from.min = b.dataset.ltype === 'sick' ? (m.leave.sick_from || '') : first;
      if (form.from.value < form.from.min) form.from.value = form.from.min;
      if (form.to.value < form.from.value) form.to.value = form.from.value;
    }));
    form.from?.addEventListener('change', () => { if (form.to.value < form.from.value) form.to.value = form.from.value; form.to.min = form.from.value; });
    $$('[data-night]', form).forEach((b) => b.addEventListener('click', () => {
      const on = b.getAttribute('aria-pressed') !== 'true';
      $$('[data-night]', form).forEach((x) => x.setAttribute('aria-pressed', 'false'));
      b.setAttribute('aria-pressed', String(on));
      form.night.value = on ? b.dataset.night : '';
      $('#c-timef', form).hidden = !on;
    }));
    $$('[data-tpl]', form).forEach((b) => b.addEventListener('click', () => {
      $$('[data-tpl]', form).forEach((x) => x.setAttribute('aria-pressed', 'false'));
      b.setAttribute('aria-pressed', 'true');
      form.template_code.value = b.dataset.tpl;
    }));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const data = formData(form);
      const errs = {};
      if (!data.body) errs.body = 'Write your message.';
      if (topic === 'leave' && !data.leave_type) errs.leave_type = 'Pick a kind of leave.';
      if (topic === 'letter' && !data.template_code) errs.template_code = 'Pick a letter.';
      if (topic === 'letter' && !data.purpose) errs.purpose = 'Say what it is for.';
      if (topic === 'attendance' && data.night && !data.time) errs.time = 'Pick the time you left.';
      if (showFieldErrors(form, errs)) return;
      const payload = { topic: aboutReq ? 'other' : topic, subject: data.subject || null, body: data.body, files: files.ids() };
      if (aboutReq) payload.about_request = aboutReq.id;
      if (topic === 'leave') Object.assign(payload, { leave_type: data.leave_type, from: data.from, to: data.to });
      if (topic === 'attendance' && data.night) Object.assign(payload, { night: data.night, time: data.time });
      if (topic === 'letter') Object.assign(payload, { template_code: data.template_code, purpose: data.purpose });
      busy($('[type="submit"]', form), async () => {
        try {
          const r = await api.post('/me/mail', payload);
          toast(r.message);
          go(`#/me/mail/${r.id}`, { replace: true });
        } catch (err) { if (!showFieldErrors(form, err.fields)) toast(err.message, { tone: 'bad' }); }
      });
    });
  };
  draw();
}
