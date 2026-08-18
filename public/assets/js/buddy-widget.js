/**
 * buddy-widget.js — the embedded AI buddy chat, on every CRM page.
 *
 * Self-contained: builds its own DOM + injects its own CSS (no Tailwind
 * dependency, no framework). Loaded with `defer` after tailwind.js on every
 * authed view (same mass-include pattern as error-beacon.js).
 *
 * FAIL-INVISIBLE CONTRACT: the widget renders NOTHING unless GET /buddy/boot
 * returns valid JSON with ok:true. Logged-out pages, customer-facing pages,
 * the TV board (PIN session, no user), or any server hiccup → no orb, no
 * errors, no broken page. The widget can only ever add itself on top of a
 * working page.
 *
 * Endpoints (all same-origin, CSRF via header from /buddy/boot):
 *   GET  /buddy/boot      → {ok, csrf, name, admin, nudges}
 *   GET  /buddy/history   → {messages, nudges}
 *   POST /buddy/greeting  → once-per-business-day greeting (server decides)
 *   POST /buddy/chat      → {reply, ai}
 *   POST /buddy/feed      → {messages, pending_left} — P5: Aisha initiates.
 *                           (POST because the drain mutates nudge state, so it
 *                            must sit behind CSRF validation.)
 *
 * P5 delivery model: the greeting fires on PAGE LOAD (not orb click), and the
 * feed is polled every ~75s while the tab is visible. New Aisha-initiated
 * messages land in the open panel, or as a toast bubble by the orb + badge
 * when it's closed — spoken aloud when voice is on. Browser autoplay policy
 * blocks speech before the first user gesture, so early speech is queued and
 * released on the first click/keypress.
 */
(function () {
  'use strict';
  if (window.__buddyWidget) return;   // idempotent under double-include
  window.__buddyWidget = true;

  var CSRF = null;
  var opened = false;

  // ── boot: decide whether this page gets a buddy at all ──────────────────
  fetch('/buddy/boot', { headers: { 'Accept': 'application/json' } })
    .then(function (r) {
      if (!r.ok) throw 0;
      var ct = r.headers.get('content-type') || '';
      if (ct.indexOf('json') === -1) throw 0;   // gate redirect → HTML → hide
      return r.json();
    })
    .then(function (d) {
      if (!d || !d.ok || !d.csrf) return;
      CSRF = d.csrf;
      mount(d);
    })
    .catch(function () { /* no session / gated / error → stay invisible */ });

  function mount(boot) {
    injectCss();

    // ── Mode: admins get the Super Buddy (cross-team tools + voice), everyone
    //    else the personal agent buddy. Server decides via /buddy/boot.
    var MODE = boot.mode === 'admin' ? 'admin' : 'agent';
    var EP = MODE === 'admin'
      ? { history: '/buddy/admin/history', chat: '/buddy/admin/chat',
          greeting: '/buddy/admin/greeting', feed: null,
          confirm: '/buddy/admin/confirm-action', cancel: '/buddy/admin/cancel-action' }
      : { history: '/buddy/history', chat: '/buddy/chat', greeting: '/buddy/greeting',
          feed: '/buddy/feed', confirm: null, cancel: null };

    // Web Speech — BOTH halves feature-detected; absence degrades silently
    // (the liveboard TV taught us what unguarded speech APIs do in production).
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
    var ttsOk = ('speechSynthesis' in window) && typeof window.SpeechSynthesisUtterance === 'function';
    // Voice defaults ON (client: agents wear headphones, Aisha greets aloud).
    // Toggle persists; first visit = on.
    var voiceOn = true;
    try {
      var stored = localStorage.getItem('bwVoiceOn');
      if (stored !== null) voiceOn = stored === '1';
    } catch (e) { /* private mode */ }
    var serverVoice = null;   // null = unprobed, true/false after first attempt

    // ── Orb ────────────────────────────────────────────────────────────────
    var orb = el('button', 'bw-orb');
    orb.type = 'button';
    orb.setAttribute('aria-label', 'Open your AI buddy');
    orb.innerHTML =
      '<span class="bw-orb-core"></span>' +
      '<svg class="bw-orb-icon" viewBox="0 0 24 24" fill="none">' +
      '<path d="M12 3a7 7 0 0 1 7 7v1.5a6.5 6.5 0 0 1-6.5 6.5H12l-3.6 2.7c-.6.45-1.4-.06-1.4-.8V17A7 7 0 0 1 12 3Z" stroke="currentColor" stroke-width="1.6"/>' +
      '<circle cx="9.2" cy="10.6" r="1.1" fill="currentColor"/><circle cx="14.8" cy="10.6" r="1.1" fill="currentColor"/></svg>';
    var badge = el('span', 'bw-badge');
    var nudgeCount = 0;
    function setBadge(n) {
      nudgeCount = n;
      if (n > 0) {
        badge.textContent = n > 9 ? '9+' : String(n);
        if (!badge.parentNode) orb.appendChild(badge);
      } else if (badge.parentNode) {
        badge.parentNode.removeChild(badge);
      }
    }
    setBadge(boot.nudges | 0);
    document.body.appendChild(orb);

    // ── Panel ──────────────────────────────────────────────────────────────
    var panel = el('div', 'bw-panel');
    var title = MODE === 'admin' ? 'Aisha · Super' : 'Aisha';
    var sub   = MODE === 'admin'
      ? 'your personal assistant · sees the whole team'
      : 'your work buddy · knows your numbers, never your customers’ data';
    panel.innerHTML =
      '<div class="bw-head">' +
        '<div class="bw-ava"><span class="bw-ava-ring"></span><span class="bw-ava-dot"></span></div>' +
        '<div class="bw-head-txt"><div class="bw-title">' + title + '</div>' +
        '<div class="bw-sub"><span class="bw-live"></span>' + sub + '</div></div>' +
        '<button type="button" class="bw-voice" title="Aisha voice on/off">' + (voiceOn ? '🔊' : '🔇') + '</button>' +
        (boot.admin ? '<a class="bw-maint" href="/buddy/maintenance" title="Maintenance buddy">SYS</a>' : '') +
        '<button type="button" class="bw-close" aria-label="Close">×</button>' +
      '</div>' +
      '<div class="bw-chips"></div>' +
      '<div class="bw-log"></div>' +
      '<form class="bw-form">' +
        (MODE === 'admin' && SR ? '<button type="button" class="bw-mic" title="Hold to talk" aria-label="Voice input">' +
          '<svg viewBox="0 0 24 24" fill="none"><rect x="9" y="3" width="6" height="11" rx="3" stroke="currentColor" stroke-width="1.6"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>' +
          '</button>' : '') +
        '<input class="bw-in" maxlength="500" autocomplete="off" ' +
        'placeholder="' + (MODE === 'admin' ? 'Ask about anyone, or press the mic…' : 'Ask about your day, month, bookings…') + '">' +
        '<button type="submit" class="bw-send" aria-label="Send">' +
        '<svg viewBox="0 0 24 24" fill="none"><path d="M4 12 20 4l-4 8 4 8-16-8Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>' +
        '</button></form>';
    document.body.appendChild(panel);

    var log   = panel.querySelector('.bw-log');
    var chips = panel.querySelector('.bw-chips');
    var input = panel.querySelector('.bw-in');
    var send  = panel.querySelector('.bw-send');

    var NUDGE_LABELS = {
      sale_praise_t1: '👏 Nice sale', sale_praise_t2: '🎉 Big sale',
      eticket_lag: '🎫 E-ticket pending', acceptance_lag: '⏳ Acceptance open',
      departure_24h: '✈️ Departs soon', dry_spell: '💪 Check-in',
      admin_message: '💬 From admin',
      goal_hit: '🏆 Goal reached', goal_pace: '🎯 Goal check-in',
      first_sale_today: '🙌 On the board', shift_wrap: '🌙 Day wrap'
    };

    orb.addEventListener('click', function () {
      opened = !opened;
      panel.classList.toggle('bw-open', opened);
      orb.classList.toggle('bw-orb-hidden', opened);
      if (opened && !panel.__loaded) { panel.__loaded = true; load(); }
      // Opening the panel is the clearest "tell me now" there is — drain any
      // pending nudges straight away instead of leaving them to the backoff.
      if (opened) { setBadge(0); hideToast(); feedNow(); input.focus(); }
    });
    panel.querySelector('.bw-close').addEventListener('click', function () {
      opened = false;
      panel.classList.remove('bw-open');
      orb.classList.remove('bw-orb-hidden');
    });

    // Voice toggle + speech (admin): pick a female English voice, per the
    // client spec, with graceful fallback to any English voice.
    var voiceBtn = panel.querySelector('.bw-voice');
    var chosenVoice = null;
    function pickVoice() {
      try {
        var v = window.speechSynthesis.getVoices() || [];
        chosenVoice =
          v.find(function (x) { return /female|zira|aria|jenny|samantha|google uk english female/i.test(x.name) && x.lang.indexOf('en') === 0; }) ||
          v.find(function (x) { return x.lang.indexOf('en') === 0; }) || v[0] || null;
      } catch (e) { chosenVoice = null; }
    }
    if (ttsOk) {
      pickVoice();
      if ('onvoiceschanged' in window.speechSynthesis) window.speechSynthesis.onvoiceschanged = pickVoice;
    }
    // ── Speech lifecycle ────────────────────────────────────────────────────
    // A conversation needs to know when Aisha STOPS talking — that's the cue
    // to reopen the mic. `speaking` is true from the moment we commit to
    // saying something until the audio actually ends, across every path
    // (cloud MP3, browser fallback, fallback-of-fallback).
    var speaking = false;
    var onAishaDoneSpeaking = null;   // assigned by the conversation loop below

    function speechDone() {
      speaking = false;
      if (typeof onAishaDoneSpeaking === 'function') onAishaDoneSpeaking();
    }

    /** Barge-in: cut her off mid-sentence, instantly, on every engine. */
    function stopSpeaking() {
      try { if (currentAudio) { currentAudio.onended = null; currentAudio.pause(); } } catch (e) {}
      try { if (ttsOk) window.speechSynthesis.cancel(); } catch (e) {}
      speaking = false;
    }

    function browserSpeak(text) {
      if (!ttsOk) { speechDone(); return; }   // silent path still ends the turn
      try {
        window.speechSynthesis.cancel();
        var u = new SpeechSynthesisUtterance(String(text).slice(0, 600));
        if (chosenVoice) u.voice = chosenVoice;
        u.rate = 1.04;
        u.onend = u.onerror = speechDone;
        speaking = true;
        window.speechSynthesis.speak(u);
      } catch (e) { speechDone(); /* never break chat over audio */ }
    }

    var currentAudio = null;
    /**
     * Aisha speaks: Cloud TTS first (warm, human — server caches the MP3),
     * falling back to browser speech, falling back to silence. `kind`:
     *   'greeting' — spoken for EVERYONE with voice on (the client's spec:
     *                Aisha greets the agents aloud, they reply in chat)
     *   'reply'    — spoken for the admin only (full conversation mode)
     */
    // Autoplay unlock: browsers block audio/speech before the first user
    // gesture on the page. Anything Aisha wants to say before that is queued
    // and the FIRST queued line (the greeting) is released on first gesture —
    // the rest stay visible as toast/panel text rather than a speech barrage.
    var interacted = false, speechQueue = [];

    // An unspoken line must survive navigation. The queue used to die with the
    // page: greeting lands on the dashboard, agent clicks through to
    // Transactions before touching anything, and the one greeting of the day
    // is silently lost — which is exactly how "I didn't hear her greet me"
    // happens. Pending speech is parked in sessionStorage and re-queued on the
    // next page, where the first click releases it. 10-minute staleness cap:
    // a greeting from an hour ago should stay unspoken.
    function parkSpeech(text, kind) {
      try { sessionStorage.setItem('bwPendingSpeech', JSON.stringify({ t: text, k: kind, at: Date.now() })); } catch (e) {}
    }
    function unparkSpeech() {
      try {
        var raw = sessionStorage.getItem('bwPendingSpeech');
        if (!raw) return;
        var p = JSON.parse(raw);
        if (p && p.t && Date.now() - (p.at || 0) < 600000) speechQueue.push([p.t, p.k]);
        else sessionStorage.removeItem('bwPendingSpeech');
      } catch (e) {}
    }
    function clearParkedSpeech() {
      try { sessionStorage.removeItem('bwPendingSpeech'); } catch (e) {}
    }
    unparkSpeech();

    function markInteracted() {
      if (interacted) return;
      interacted = true;
      if (speechQueue.length) {
        var q = speechQueue[0];
        speechQueue = [];
        clearParkedSpeech();
        speak(q[0], q[1]);
      }
    }
    document.addEventListener('pointerdown', markInteracted, true);
    document.addEventListener('keydown', markInteracted, true);

    /** @returns true when she WILL speak (so the convo loop waits for the end
     *  cue), false when this text stays silent (loop resumes immediately). */
    function speak(text, kind) {
      if (!voiceOn) return false;
      if (kind !== 'greeting' && MODE !== 'admin') return false;
      // Cap the queue: if the agent never clicks, only the first line matters
      // and an unbounded backlog would be a slow leak on a long-lived tab.
      if (!interacted) {
        if (speechQueue.length < 3) speechQueue.push([text, kind]);
        if (speechQueue.length === 1) parkSpeech(text, kind);   // survive navigation
        return false;
      }
      clearParkedSpeech();
      stopFiller();   // her real voice replaces the thinking line, never overlaps it
      // Strip markdown before it reaches any voice engine, or she reads the
      // syntax out loud.
      var payload = plain(text).slice(0, 600);

      // Committed to speaking from here — the flag goes up BEFORE the fetch,
      // or the conversation loop would open the mic into the silent gap while
      // the MP3 is still being fetched and catch the start of her own line.
      speaking = true;

      if (serverVoice === false) { browserSpeak(payload); return true; }
      fetch('/buddy/tts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ text: payload }),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok && d.url) {
            serverVoice = true;
            try {
              if (currentAudio) { currentAudio.onended = null; currentAudio.pause(); }
              currentAudio = new Audio(d.url);
              // onerror too: an audio element that dies mid-play without it
              // leaves `speaking` stuck true — which silently blocks the mic
              // from ever arming again. Found while debugging "mic does
              // nothing" in admin mode.
              currentAudio.onended = currentAudio.onerror = speechDone;
              currentAudio.play().catch(function () { browserSpeak(payload); });
            } catch (e) { browserSpeak(payload); }
          } else {
            serverVoice = false;               // not configured yet — remember
            browserSpeak(payload);
          }
        })
        .catch(function () { browserSpeak(payload); });
      return true;
    }
    if (voiceBtn) {
      voiceBtn.addEventListener('click', function () {
        voiceOn = !voiceOn;
        voiceBtn.textContent = voiceOn ? '🔊' : '🔇';
        try { localStorage.setItem('bwVoiceOn', voiceOn ? '1' : '0'); } catch (e) {}
        if (!voiceOn) { stopSpeaking(); speechDone(); }   // muting mid-sentence ends her turn cleanly
      });
    }

    // ── Conversation mode (admin) ───────────────────────────────────────────
    // One mic click starts a CONVERSATION, not one utterance: listen → send →
    // she answers aloud → mic reopens by itself → repeat. Click again to end.
    //
    // Turn-taking rules that make it feel like two people:
    //  - The mic is CLOSED while she talks. Speakers + open mic = she hears
    //    herself and answers herself; browser echo cancellation is not to be
    //    trusted with this. Barge-in is therefore a click (mic or send), which
    //    cuts her off instantly and listens — deliberate, reliable interruption
    //    rather than a feedback loop.
    //  - The mic reopens ~0.4s after her last word — the beat a person leaves.
    //  - Silence isn't a hang-up: recognition timeouts quietly re-arm. But two
    //    minutes with nothing heard ends the conversation — nobody wants a
    //    forgotten hot mic.
    //  - A denied mic permission ends it immediately and for good.
    var micBtn = panel.querySelector('.bw-mic');
    var convo = false, listening = false, rec = null, restartTimer = null;
    var lastHeard = 0, convoStartedAt = 0;

    function setMicUi() {
      if (!micBtn) return;
      micBtn.classList.toggle('bw-mic-on', listening);
      micBtn.classList.toggle('bw-mic-convo', convo && !listening);
      micBtn.title = convo ? 'Conversation on — click to end' : 'Click to start talking';
    }
    function stopRec() {
      listening = false;
      try { if (rec) { rec.onresult = rec.onend = rec.onerror = null; rec.stop(); } } catch (e) {}
      setMicUi();
    }
    function convoOff() {
      convo = false;
      clearTimeout(restartTimer);
      stopRec();
    }
    function startListening() {
      if (!convo || listening || speaking || send.disabled) return;
      try {
        rec = new SR();
        rec.lang = 'en-IN';
        rec.interimResults = false;
        rec.maxAlternatives = 1;
        rec.onresult = function (ev) {
          lastHeard = Date.now();
          var text = (((ev.results || [])[0] || [])[0] || {}).transcript || '';
          stopRec();
          if (text.trim()) { input.value = text.trim(); sendCurrent(); }
          else if (convo) { restartTimer = setTimeout(startListening, 300); }
        };
        rec.onerror = function (ev) {
          listening = false;
          setMicUi();
          var code = ev && ev.error ? ev.error : 'unknown';
          try { console.warn('[buddy] mic error:', code); } catch (e) {}
          if (code === 'not-allowed' || code === 'service-not-allowed') {
            convoOff();
            // Silent failure here looked like a dead button. Say what's wrong
            // and how to fix it, in the chat where the click just happened.
            addMsg('ai', "I can't hear you — the browser has microphone access blocked for this site. "
              + 'Click the lock/tune icon next to the address bar → Site settings → allow Microphone, '
              + 'then reload and tap the mic again.');
          }
          if (code === 'audio-capture') {
            convoOff();
            addMsg('ai', "I can't find a microphone on this device — plug one in or check your input settings, then tap the mic again.");
          }
          // no-speech / network blips fall through to onend, which re-arms
        };
        rec.onend = function () {
          listening = false;
          setMicUi();
          if (!convo || speaking || send.disabled) return;   // her turn / reply in flight
          if (Date.now() - Math.max(lastHeard, convoStartedAt) > 120000) { convoOff(); return; }
          restartTimer = setTimeout(startListening, 350);
        };
        rec.start();
        listening = true;
        setMicUi();
        try { console.log('[buddy] listening'); } catch (e) {}
      } catch (e) {
        listening = false;
        setMicUi();
        convoOff();
        try { console.warn('[buddy] mic start failed:', e); } catch (e2) {}
        addMsg('ai', 'Voice input failed to start in this browser — you can keep typing, and tell John: "mic start failed".');
      }
    }
    // ── Thinking out loud ──────────────────────────────────────────────────
    // Humans buy processing time by narrating it ("hmm, give me a second…").
    // In conversation mode, if her real answer hasn't arrived within 2.5s,
    // she says a short filler — pre-synthesized so it plays instantly — and
    // the silence reads as thought instead of a dropped call. Deliberately
    // OUTSIDE the speak() state machine: a filler must never trigger the
    // "she finished talking, reopen the mic" cue.
    var FILLERS = [
      'Hmm, good question — give me a few seconds while I think on that.',
      'Let me look into that for a moment.',
      'One second, checking that for you.',
    ];
    var fillerUrls = [], fillerAudio = null, fillerTimer = null;
    function prefetchFillers() {
      if (fillerUrls.length || !voiceOn) return;
      FILLERS.forEach(function (line) {
        fetch('/buddy/tts', { method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
          body: JSON.stringify({ text: line }) })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (d && d.ok && d.url) fillerUrls.push(d.url); })
          .catch(function () {});
      });
    }
    function playFiller() {
      if (!convo || !voiceOn || speaking) return;
      try {
        if (fillerUrls.length) {
          fillerAudio = new Audio(fillerUrls[Math.floor(Math.random() * fillerUrls.length)]);
          fillerAudio.play().catch(function () {});
        } else if (ttsOk) {
          var u = new SpeechSynthesisUtterance(FILLERS[0]);
          if (chosenVoice) u.voice = chosenVoice;
          window.speechSynthesis.speak(u);
        }
      } catch (e) {}
    }
    function stopFiller() {
      clearTimeout(fillerTimer);
      fillerTimer = null;
      try { if (fillerAudio) { fillerAudio.pause(); fillerAudio = null; } } catch (e) {}
    }
    function armFiller() {
      if (!convo || !voiceOn) return;
      stopFiller();
      fillerTimer = setTimeout(playFiller, 2500);
    }

    // Her side of the turn-taking: the moment she finishes a sentence, the
    // floor is yours again.
    onAishaDoneSpeaking = function () {
      if (convo) { clearTimeout(restartTimer); restartTimer = setTimeout(startListening, 400); }
    };
    if (micBtn && SR) {
      micBtn.addEventListener('click', function () {
        if (convo) { convoOff(); stopFiller(); return; }
        convo = true;
        convoStartedAt = Date.now();
        lastHeard = 0;
        stopSpeaking();       // barge-in: the click cuts her off mid-word
        prefetchFillers();    // thinking-lines ready before they're needed
        startListening();
        setMicUi();
      });
    }

    // ── P5: Aisha initiates ────────────────────────────────────────────────
    // Toast bubble by the orb for messages that arrive while the panel is
    // closed. One element, latest message wins, click-through opens the chat.
    var toast = null, toastTimer = null;
    function showToast(text) {
      if (opened) return;
      if (!toast) {
        toast = el('div', 'bw-toast');
        toast.addEventListener('click', function () { hideToast(); orb.click(); });
        document.body.appendChild(toast);
      }
      toast.textContent = plain(text).slice(0, 140);
      toast.classList.add('bw-toast-show');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(hideToast, 14000);
    }
    function hideToast() {
      if (toast) toast.classList.remove('bw-toast-show');
      clearTimeout(toastTimer);
    }

    // Guard against the greeting race: if the panel's history fetch already
    // rendered this exact message, don't append it a second time.
    function alreadyShown(text) {
      var msgs = log.querySelectorAll('.bw-msg-ai');
      for (var i = Math.max(0, msgs.length - 5); i < msgs.length; i++) {
        if (msgs[i].textContent === text) return true;
      }
      return false;
    }

    /**
     * One Aisha-initiated message reaches the agent: panel open → appended to
     * the log; panel closed → toast + badge (and appended quietly if the log
     * is already built, so it's there on open). Spoken via the 'greeting'
     * voice class — everyone with voice on hears Aisha, per the client spec.
     */
    function deliver(text, speakIt, degraded) {
      var dup = panel.__loaded && alreadyShown(text);
      if (panel.__loaded && !dup) addMsg('ai', text, degraded);
      if (!opened) { showToast(text); setBadge(nudgeCount + 1); }
      if (speakIt && !dup) speak(text, 'greeting');
    }

    // Greeting fires on PAGE LOAD, not on orb click — Aisha greets them by
    // name the moment they arrive. The server decides once-per-business-day.
    // Adaptive interval. The trigger cron only mints nudges every 15 minutes,
    // so a fixed 75s poll spends ~12 authenticated PHP requests to discover
    // nothing — per user, per open tab, forever. Quiet periods back off toward
    // 5 min; anything that suggests news (a delivery, a backlog, the agent
    // coming back to the tab or opening the panel) snaps straight back to 75s.
    var FEED_MIN_MS = 75000, FEED_MAX_MS = 300000;
    var feedMs = FEED_MIN_MS, feedTimer = null, pollBusy = false;

    function scheduleFeed(ms) {
      if (!EP.feed) return;
      clearTimeout(feedTimer);
      feedTimer = setTimeout(pollFeed, ms);
    }
    function backOff() {
      feedMs = Math.min(Math.round(feedMs * 1.6), FEED_MAX_MS);
    }
    function feedNow() {          // "something changed — look again promptly"
      feedMs = FEED_MIN_MS;
      scheduleFeed(1500);
    }

    function pollFeed() {
      if (!EP.feed) return;
      if (document.hidden || pollBusy) { scheduleFeed(feedMs); return; }
      pollBusy = true;
      // POSTed with CSRF: the drain mutates nudge state one-way, so it must not
      // be reachable cross-site. `open` tells the server these land in front of
      // an open panel, so they count as read rather than returning as a badge.
      fetch(EP.feed, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ open: opened ? 1 : 0 }),
      })
        .then(function (r) {
          if (!r.ok) throw 0;
          var ct = r.headers.get('content-type') || '';
          if (ct.indexOf('json') === -1) throw 0;   // session died → HTML login
          return r.json();
        })
        .then(function (d) {
          if (!d || !d.ok) { backOff(); return; }
          var msgs = d.messages || [];
          msgs.forEach(function (m, i) { deliver(m.content, i === 0); });
          if (d.hold_seconds) {
            // Server is pacing her deliberately. Wait it out rather than
            // polling into a gate that will refuse us every 75 seconds.
            feedMs = Math.max(FEED_MIN_MS, d.hold_seconds * 1000);
          } else if (msgs.length || d.pending_left) {
            // A backlog means more is queued behind the batch cap, so stay
            // fast until she has said everything she has to say.
            feedMs = FEED_MIN_MS;
          } else {
            backOff();
          }
        })
        .catch(function () { backOff(); })   // don't hammer a broken endpoint
        .finally(function () { pollBusy = false; scheduleFeed(feedMs); });
    }
    if (EP.greeting) {
      fetch(EP.greeting, { method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: '{}' })
        .then(function (r) { return r.json(); })
        .then(function (g) { if (g.greeted && g.reply) deliver(g.reply, true, g.ai === false); })
        .catch(function () { /* greeting is a bonus, never an error */ })
        .finally(function () {
          // First feed drain a beat after the greeting so Aisha doesn't talk
          // over herself; then steady polling while the tab is visible.
          if (EP.feed) setTimeout(pollFeed, 8000);
        });
    }
    if (EP.feed) {
      scheduleFeed(feedMs);
      // Back at the tab after a while? Check promptly — that's exactly when an
      // agent expects to find out what happened while they were elsewhere.
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) feedNow();
      });
    }

    // Confirm gate UI: the model can only PARK an action; these buttons are the
    // human click that executes or discards it.
    function renderConfirm(summary) {
      var row = el('div', 'bw-row bw-row-ai');
      var box = el('div', 'bw-msg bw-msg-ai');
      var txt = el('div', '');
      txt.textContent = '⚡ Pending action: ' + summary;
      var btns = el('div', 'bw-confirm-btns');
      var yes = el('button', 'bw-btn bw-btn-yes'); yes.type = 'button'; yes.textContent = 'Confirm & send';
      var no  = el('button', 'bw-btn bw-btn-no');  no.type = 'button';  no.textContent = 'Cancel';
      btns.appendChild(yes); btns.appendChild(no);
      box.appendChild(txt); box.appendChild(btns);
      row.appendChild(box); log.appendChild(row); log.scrollTop = log.scrollHeight;

      function finish(url) {
        yes.disabled = no.disabled = true;
        fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: '{}' })
          .then(function (r) { return r.json(); })
          .then(function (d) { box.removeChild(btns); addMsg('ai', d.detail || d.error || 'Done.'); })
          .catch(function () { box.removeChild(btns); addMsg('ai', 'Action request failed — ask again.'); });
      }
      yes.addEventListener('click', function () { finish(EP.confirm); });
      no.addEventListener('click', function () { finish(EP.cancel); });
    }

    function load() {
      fetch(EP.history)
        .then(function (r) { return r.json(); })
        .then(function (h) {
          (h.messages || []).forEach(function (m) { addMsg(m.role === 'user' ? 'user' : 'ai', m.content); });
          (h.nudges || []).forEach(function (n) {
            var c = el('button', 'bw-chip');
            c.type = 'button';
            c.textContent = NUDGE_LABELS[n.type] || n.type;
            c.addEventListener('click', function () {
              input.value = 'Tell me about: ' + (NUDGE_LABELS[n.type] || n.type);
              input.focus();
            });
            chips.appendChild(c);
          });
          // Greeting is handled at page load (P5); here we only cover the
          // very first ever open with an empty history.
          if (MODE !== 'admin' && (h.messages || []).length === 0 && !log.querySelector('.bw-msg')) {
            addMsg('ai', "Hey! I'm Aisha — your work buddy. I keep an eye on your sales, your open bookings and your wins, and I'm always up for a chat about your numbers. What's on your mind?");
          } else if (MODE === 'admin' && (h.messages || []).length === 0) {
            addMsg('ai', "Aisha here — your assistant. Ask me about anyone on the team: stats, dry spells, e-ticket lag, or what an agent's buddy has been hearing. I can also send nudges — you confirm every one.");
          }
        })
        .catch(function () { addMsg('ai', 'Could not load our chat — try reopening.'); });
    }

    function sendCurrent() {
      var text = input.value.trim();
      if (!text || send.disabled) return;
      // Sending is a barge-in: whatever she was saying, the human moved on.
      stopSpeaking();
      stopRec();
      armFiller();   // in convo mode: "hmm, let me think…" if the answer takes long
      addMsg('user', text);
      input.value = '';
      send.disabled = true;
      var t = typing();
      fetch(EP.chat, { method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ message: text }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          t.remove();
          stopFiller();
          if (d.success) {
            addMsg('ai', d.reply, d.ai === false);
            var willSpeak = speak(d.reply, 'reply');
            // Silent reply (voice muted / no TTS): her turn ends the moment
            // the text lands, so hand the floor straight back to the mic.
            if (!willSpeak && typeof onAishaDoneSpeaking === 'function') onAishaDoneSpeaking();
            if (d.pending_action && EP.confirm) renderConfirm(d.pending_action);
          } else {
            addMsg('ai', d.error || 'Something went wrong — try again.');
            if (typeof onAishaDoneSpeaking === 'function') onAishaDoneSpeaking();
          }
        })
        .catch(function () {
          t.remove();
          stopFiller();
          addMsg('ai', 'Network hiccup — try again.');
          if (typeof onAishaDoneSpeaking === 'function') onAishaDoneSpeaking();
        })
        .finally(function () { send.disabled = false; input.focus(); });
    }

    panel.querySelector('.bw-form').addEventListener('submit', function (ev) {
      ev.preventDefault();
      sendCurrent();
    });

    function addMsg(kind, text, degraded) {
      var row = el('div', 'bw-row bw-row-' + kind);
      if (kind === 'ai') {
        var av = el('div', 'bw-msg-ava');
        av.innerHTML = '<span></span>';
        row.appendChild(av);
      }
      var b = el('div', 'bw-msg bw-msg-' + kind);
      // Aisha's side gets markdown rendered; the agent's own text stays literal.
      if (kind === 'ai') { b.innerHTML = rich(text); } else { b.textContent = text; }
      // Feedback thumbs — the learning signal. Rating always applies to her
      // NEWEST message, which is the one the thumbs sit under.
      if (kind === 'ai') {
        var fb = el('div', 'bw-fb');
        ['👍', '👎'].forEach(function (glyph, gi) {
          var btn = el('button', 'bw-fb-btn');
          btn.type = 'button';
          btn.textContent = glyph;
          btn.addEventListener('click', function () {
            if (fb.__done) return;
            fb.__done = true;
            fb.querySelectorAll('.bw-fb-btn').forEach(function (x) { x.classList.remove('bw-fb-sel'); });
            btn.classList.add('bw-fb-sel');
            fetch('/buddy/feedback', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
              body: JSON.stringify({ value: gi === 0 ? 1 : -1, kind: MODE === 'admin' ? 'admin' : 'agent' }),
            }).catch(function () {});
            setTimeout(function () { fb.__done = false; }, 400);   // allow changing your mind
          });
          fb.appendChild(btn);
        });
        b.appendChild(fb);
      }
      if (degraded) {
        var tag = el('div', 'bw-degraded');
        tag.textContent = 'AI offline — raw numbers';
        b.appendChild(tag);
      }
      row.appendChild(b);
      log.appendChild(row);
      log.scrollTop = log.scrollHeight;
      return row;
    }

    function typing() {
      var row = el('div', 'bw-row bw-row-ai');
      row.innerHTML = '<div class="bw-msg-ava bw-think"><span></span></div>' +
        '<div class="bw-msg bw-msg-ai bw-typing"><i></i><i></i><i></i></div>';
      log.appendChild(row);
      log.scrollTop = log.scrollHeight;
      return row;
    }
  }

  function el(tag, cls) { var e = document.createElement(tag); e.className = cls; return e; }

  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * Gemini answers in markdown — the admin persona explicitly asks for bullets
   * — but the bubble was plain text, so real replies showed "### Top 3 by Net
   * MCO", "**Cyrus**" and "\$14,568.73" exactly like that. Found by looking at
   * production; no offline test would ever have noticed.
   *
   * HTML is escaped FIRST and only a tiny whitelist is reintroduced. That
   * ordering is not optional: admin messages are relayed verbatim, so this
   * would otherwise be an HTML injection point straight into another user's
   * page.
   */
  function rich(text) {
    var s = escHtml(text);
    s = s.replace(/\\([\\$*_`#\[\]()~>+\-.!])/g, '$1');       // undo md escapes
    s = s.replace(/^\s{0,3}#{1,6}\s*(.+)$/gm, '<b>$1</b>');    // headings
    s = s.replace(/^\s*[\*\-•]\s+/gm, '• ');         // bullets
    s = s.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');            // bold
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');          // inline code
    return s;
  }

  /** Speech must never pronounce syntax ("hash hash hash", "asterisk"). */
  function plain(text) {
    return String(text)
      .replace(/\\([\\$*_`#])/g, '$1')
      .replace(/^\s{0,3}#{1,6}\s*/gm, '')
      .replace(/\*\*([^*]+)\*\*/g, '$1')
      .replace(/`([^`\n]+)`/g, '$1')
      .replace(/^\s*[\*\-•]\s+/gm, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function injectCss() {
    var css =
'.bw-orb{position:fixed;right:22px;bottom:22px;z-index:9990;width:58px;height:58px;border-radius:50%;border:0;cursor:pointer;background:transparent;padding:0;transition:transform .25s ease,opacity .2s}' +
'.bw-orb:hover{transform:scale(1.08)}' +
'.bw-orb-hidden{opacity:0;pointer-events:none;transform:scale(.6)}' +
'.bw-orb-core{position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 180deg,#163274,#5b8cff,#8b5cf6,#163274);animation:bw-spin 6s linear infinite;filter:saturate(1.2)}' +
'.bw-orb-core::after{content:"";position:absolute;inset:-6px;border-radius:50%;background:inherit;filter:blur(14px);opacity:.55;animation:bw-pulse 2.6s ease-in-out infinite}' +
'.bw-orb-icon{position:absolute;inset:0;margin:auto;width:26px;height:26px;color:#fff;z-index:1}' +
'.bw-badge{position:absolute;top:-3px;right:-3px;z-index:2;min-width:20px;height:20px;border-radius:10px;background:#ef4444;color:#fff;font:700 11px/20px Inter,sans-serif;text-align:center;padding:0 5px;box-shadow:0 0 0 2px #fff}' +
'@keyframes bw-spin{to{transform:rotate(360deg)}}' +
'@keyframes bw-pulse{0%,100%{opacity:.35}50%{opacity:.7}}' +
'.bw-panel{position:fixed;right:22px;bottom:22px;z-index:9991;width:min(390px,calc(100vw - 32px));height:min(560px,calc(100vh - 60px));display:flex;flex-direction:column;border-radius:20px;overflow:hidden;opacity:0;pointer-events:none;transform:translateY(24px) scale(.97);transition:all .28s cubic-bezier(.2,.9,.3,1.2);background:linear-gradient(160deg,rgba(13,20,44,.97),rgba(22,50,116,.95));backdrop-filter:blur(18px);box-shadow:0 24px 70px rgba(10,16,40,.55),0 0 0 1px rgba(120,150,255,.18),0 0 40px rgba(91,140,255,.12);font-family:Inter,sans-serif}' +
'.bw-open{opacity:1;pointer-events:auto;transform:translateY(0) scale(1)}' +
'.bw-head{display:flex;align-items:center;gap:11px;padding:14px 16px;border-bottom:1px solid rgba(120,150,255,.15)}' +
'.bw-ava{position:relative;width:38px;height:38px;flex:0 0 auto}' +
'.bw-ava-ring{position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 0deg,#5b8cff,#8b5cf6,#22d3ee,#5b8cff);animation:bw-spin 4s linear infinite}' +
'.bw-ava-dot{position:absolute;inset:4px;border-radius:50%;background:#0d142c}' +
'.bw-ava::after{content:"";position:absolute;inset:12px;border-radius:50%;background:radial-gradient(circle,#7fa4ff 0%,#5b8cff 60%,transparent 70%);animation:bw-pulse 2s ease-in-out infinite}' +
'.bw-head-txt{flex:1;min-width:0}' +
'.bw-title{color:#fff;font-weight:800;font-size:15px;letter-spacing:.3px;font-family:Manrope,Inter,sans-serif}' +
'.bw-sub{color:#93a5d9;font-size:10.5px;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
'.bw-live{width:6px;height:6px;border-radius:50%;background:#34d399;box-shadow:0 0 6px #34d399;animation:bw-pulse 2s infinite;flex:0 0 auto}' +
'.bw-maint{color:#8fb0ff;font:700 10px/1 Inter;letter-spacing:.12em;text-decoration:none;border:1px solid rgba(143,176,255,.4);border-radius:7px;padding:5px 7px;margin-right:2px}' +
'.bw-maint:hover{background:rgba(143,176,255,.15)}' +
'.bw-close{background:none;border:0;color:#93a5d9;font-size:22px;cursor:pointer;line-height:1;padding:2px 6px}' +
'.bw-close:hover{color:#fff}' +
'.bw-chips{display:flex;gap:6px;flex-wrap:wrap;padding:8px 14px 0}' +
'.bw-chips:empty{display:none}' +
'.bw-chip{background:rgba(91,140,255,.14);border:1px solid rgba(120,150,255,.3);color:#c8d6ff;border-radius:999px;font:600 11px/1 Inter;padding:7px 11px;cursor:pointer;transition:all .15s}' +
'.bw-chip:hover{background:rgba(91,140,255,.3);color:#fff}' +
'.bw-log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth}' +
'.bw-log::-webkit-scrollbar{width:5px}.bw-log::-webkit-scrollbar-thumb{background:rgba(120,150,255,.3);border-radius:3px}' +
'.bw-row{display:flex;gap:8px;align-items:flex-end;animation:bw-in .25s ease}' +
'.bw-row-user{justify-content:flex-end}' +
'@keyframes bw-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}' +
'.bw-msg-ava{width:24px;height:24px;border-radius:50%;flex:0 0 auto;background:conic-gradient(from 0deg,#5b8cff,#8b5cf6,#22d3ee,#5b8cff);position:relative}' +
'.bw-msg-ava span{position:absolute;inset:2px;border-radius:50%;background:#0d142c}' +
'.bw-msg-ava::after{content:"";position:absolute;inset:7px;border-radius:50%;background:#7fa4ff}' +
'.bw-think{animation:bw-spin 1.2s linear infinite}' +
'.bw-msg{max-width:78%;padding:10px 13px;border-radius:15px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}' +
'.bw-msg-ai{background:rgba(255,255,255,.07);color:#e7ecff;border:1px solid rgba(120,150,255,.14);border-bottom-left-radius:5px}' +
'.bw-msg-user{background:linear-gradient(135deg,#3b62c4,#5b8cff);color:#fff;border-bottom-right-radius:5px;box-shadow:0 4px 14px rgba(91,140,255,.3)}' +
'.bw-degraded{margin-top:7px;font-size:9.5px;color:#93a5d9;letter-spacing:.04em}' +
'.bw-fb{display:flex;gap:2px;margin-top:6px;opacity:0;transition:opacity .15s}' +
'.bw-msg-ai:hover .bw-fb{opacity:.85}' +
'.bw-fb-btn{background:none;border:0;cursor:pointer;font-size:12px;padding:1px 4px;border-radius:6px;filter:grayscale(1);opacity:.6}' +
'.bw-fb-btn:hover{filter:none;opacity:1;background:rgba(255,255,255,.08)}' +
'.bw-fb-sel{filter:none;opacity:1;background:rgba(91,140,255,.25)}' +
'.bw-msg-ai b{color:#fff;font-weight:700}' +
'.bw-msg-ai code{background:rgba(255,255,255,.1);border-radius:4px;padding:1px 5px;font:600 11.5px/1.4 ui-monospace,Menlo,Consolas,monospace}' +
'.bw-typing{display:flex;gap:4px;align-items:center;padding:13px 15px}' +
'.bw-typing i{width:6px;height:6px;border-radius:50%;background:#8fb0ff;animation:bw-dot 1.2s infinite}' +
'.bw-typing i:nth-child(2){animation-delay:.15s}.bw-typing i:nth-child(3){animation-delay:.3s}' +
'@keyframes bw-dot{0%,60%,100%{transform:translateY(0);opacity:.4}30%{transform:translateY(-5px);opacity:1}}' +
'.bw-form{display:flex;gap:8px;padding:12px 14px;border-top:1px solid rgba(120,150,255,.15)}' +
'.bw-in{flex:1;background:rgba(255,255,255,.06);border:1px solid rgba(120,150,255,.22);border-radius:12px;color:#fff;font:400 13px/1.4 Inter;padding:10px 13px;outline:none;transition:border .15s}' +
'.bw-in::placeholder{color:#7487bd}' +
'.bw-in:focus{border-color:#5b8cff;box-shadow:0 0 0 3px rgba(91,140,255,.18)}' +
'.bw-send{width:40px;height:40px;flex:0 0 auto;border:0;border-radius:12px;cursor:pointer;background:linear-gradient(135deg,#3b62c4,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;transition:all .15s}' +
'.bw-send svg{width:18px;height:18px}' +
'.bw-send:hover{filter:brightness(1.15)}' +
'.bw-send:disabled{opacity:.5;cursor:default}' +
'.bw-voice{background:none;border:0;font-size:16px;cursor:pointer;padding:2px 4px;opacity:.85}' +
'.bw-voice:hover{opacity:1}' +
'.bw-mic{width:40px;height:40px;flex:0 0 auto;border:1px solid rgba(120,150,255,.3);border-radius:12px;cursor:pointer;background:rgba(91,140,255,.12);color:#c8d6ff;display:flex;align-items:center;justify-content:center;transition:all .15s}' +
'.bw-mic svg{width:18px;height:18px}' +
'.bw-mic:hover{background:rgba(91,140,255,.25)}' +
'.bw-mic-on{background:linear-gradient(135deg,#ef4444,#f97316);color:#fff;border-color:transparent;animation:bw-pulse 1s infinite}' +
'.bw-mic-convo{background:rgba(52,211,153,.18);border-color:rgba(52,211,153,.55);color:#6ee7b7}' +
'.bw-confirm-btns{display:flex;gap:8px;margin-top:10px}' +
'.bw-btn{border:0;border-radius:9px;font:700 12px/1 Inter;padding:9px 13px;cursor:pointer;transition:filter .15s}' +
'.bw-btn:hover{filter:brightness(1.15)}' +
'.bw-btn:disabled{opacity:.5;cursor:default}' +
'.bw-btn-yes{background:linear-gradient(135deg,#059669,#34d399);color:#fff}' +
'.bw-btn-no{background:rgba(255,255,255,.12);color:#c8d6ff;border:1px solid rgba(120,150,255,.3)}' +
'.bw-toast{position:fixed;right:22px;bottom:92px;z-index:9990;max-width:300px;background:linear-gradient(160deg,rgba(13,20,44,.97),rgba(22,50,116,.95));color:#e7ecff;border:1px solid rgba(120,150,255,.28);border-radius:16px 16px 4px 16px;padding:12px 15px;font:400 12.5px/1.5 Inter,sans-serif;box-shadow:0 12px 40px rgba(10,16,40,.5),0 0 24px rgba(91,140,255,.16);cursor:pointer;opacity:0;pointer-events:none;transform:translateY(10px) scale(.97);transition:all .3s ease;white-space:pre-wrap;word-break:break-word}' +
'.bw-toast::after{content:"Aisha · tap to reply";display:block;margin-top:7px;font:700 9.5px/1 Inter;letter-spacing:.1em;color:#8fb0ff;text-transform:uppercase}' +
'.bw-toast-show{opacity:1;pointer-events:auto;transform:none}' +
'@media (max-width:480px){.bw-panel{right:8px;bottom:8px;border-radius:16px}.bw-toast{right:8px;max-width:calc(100vw - 90px)}}';
    var s = document.createElement('style');
    s.textContent = css;
    document.head.appendChild(s);
  }
})();
