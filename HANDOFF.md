# Session Handoff — 19 August 2026 (early hours)

Written at the end of a very long session. Everything below is current and
verified unless it says otherwise. `MEMORY.md` + `memory/ai-buddy-status.md`
carry the deep history; this is what the NEXT session needs to act.

---

## 0. START HERE — the three things that are broken

Aisha (the AI buddy) is feature-complete and live. But the **greeting
experience is bad**, and the client said so plainly. Fixing this is the whole
job of the next session. Do not add features until these three are fixed.

### Bug 1 — the greeting is an essay, not a greeting

This is what she actually said on a fresh open (verbatim, production):

> Hey TJ, so good to see you! Chalo, let's get you set for a fantastic day.
>
> You're off to a fresh start today with zero sales and revenue so far. This
> month's numbers are still at zero too, but don't you worry, that's because
> performance scoring for this month only starts from August 10th. So you've
> got a clean slate and a whole lot of opportunity ahead!
>
> Let's make today about setting up those first sales for the scoring period,
> what do you say?
>
> And on a totally different note, I was wondering, what do you enjoy selling
> the most, TJ? Is there a particular type of trip or destination that really
> gets you excited?

Client's verdict: *"absolute shit of a greeting... is this how greetings are
supposed to work."* He is right. Problems, in order of severity:

1. **Far too long.** Four paragraphs. A greeting is one or two sentences.
2. **It recites internal policy.** "performance scoring for this month only
   starts from August 10th" is the PerformanceHold notice leaking verbatim into
   a hello. That is jargon from `PerformanceHold::notice()`, meaningful to
   management, meaningless and cold to an agent walking in.
3. **The get-to-know-you question is bolted on**, not woven in — "And on a
   totally different note, I was wondering..." reads like a form.
4. **It narrates zeroes.** Telling someone who just arrived that they have zero
   sales, zero revenue and zero month is deflating and pointless.

**Root cause** is in `BuddyService::agentGreeting()`. The prompt stacks four
competing instructions — greet warmly, give a 3–5 sentence read on the digest,
end with a concrete focus, plus (conditionally) a week recap AND a knowledge-gap
question. The model dutifully does all four, and four paragraphs is the correct
output for that prompt. **The prompt is the bug, not the model.**

**The fix direction:** a greeting is a *hello*, not a briefing. Target:

> "Morning TJ! Fresh page today — let's get one on the board. What kind of
> trips do you like selling most?"

Concretely: rewrite the greeting prompt to demand ONE short paragraph, max ~35
words, no statistics unless something is genuinely notable (a personal best, a
streak, something urgent today), never the hold notice, and the gap question
folded in as natural conversation rather than appended. Consider dropping the
digest from the greeting prompt entirely and letting her pull numbers only when
asked — the numbers are always one question away.

### Bug 2 — the voice is robotic and emotionless

Client: *"just the accent is different, no emotions, nothing... it's like the
agent is reading a paragraph, not talking."*

He is right, and there are three compounding causes:

1. **The text is a paragraph.** Long declarative sentences with no contractions
   read flat on ANY engine. Fixing Bug 1 fixes much of Bug 2 for free.
2. **We send plain text, zero SSML.** No prosody, no pauses, no emphasis. See
   `TtsService::synthesize()` — payload is `['input' => ['text' => $text]]`.
   Switching to `ssml` with `<break>`, `<emphasis>` and prosody would add life.
3. **`en-IN-Neural2-D` is a standard neural voice.** Google now has
   **Chirp3-HD** and **Studio** voice families that are dramatically more
   natural/emotive. These were NOT probed — we chose from a 4-voice flight of
   Neural2 options only.

**The fix direction:** extend `scripts/gemini_model_probe.php`'s pattern to a
**voice probe** — enumerate what the live TTS key actually offers
(`GET https://texttospeech.googleapis.com/v1/voices`), synthesize the same warm
line across Chirp3-HD / Studio / Neural2 candidates, and let the client pick by
ear again. That flight process worked well; reuse it. Voice is already fully
env-tunable (`TTS_VOICE`, `TTS_RATE`, `TTS_PITCH`) so switching costs nothing.

### Bug 3 — the voice starts 10–15 seconds AFTER the text appears

Client: *"when I opened the window the chat appeared and then after 10-15
seconds the robotic voice started reading the paragraph.. wtf"*

Sequence today (all in `buddy-widget.js`):

1. `POST /buddy/greeting` returns → text renders **immediately**
2. `speak()` is called → but audio is blocked until the first user gesture, so
   it parks in `speechQueue` / `sessionStorage`
3. User clicks somewhere → `markInteracted()` fires
4. ONLY THEN `POST /buddy/tts` → Google synthesizes a 4-paragraph block →
   several seconds → audio finally plays

So the delay is: autoplay wait + synthesis of a long text. Both are real.

**The fix direction:** synthesize server-side **at greeting generation time** and
return the audio URL alongside the text, so the widget has the MP3 in hand the
moment it renders the bubble. Then either play immediately (if already
interacted) or play the instant the gesture arrives — no synthesis round-trip in
the middle. Shorter greeting text (Bug 1) also cuts synthesis time sharply.
Consider rendering the text only as playback starts, so voice and text land
together like a person talking.

---

## 1. Deploy — read this before anything

**There is NO auto-deploy.** John pulls manually on SSH, every time. Do not
assume code is live because it was pushed. Do not tell him a pull is optional.

```bash
cd ~/domains/base-fare.com/public_html/crm && git pull origin dev && php hostinger_migrate.php
```

- Branch is **`dev`**, never `main` (main is ~240 commits behind).
- Migrator is ledger-based and self-backs-up; safe every deploy.
- Server: `u501549865@us-phx-web1355`, path
  `/home/u501549865/domains/base-fare.com/public_html/crm`.
- `crm.base-fare.com` docroot is **`crm/public/`**, so the repo-root
  `.htaccess` does NOT run for that subdomain (discovered live: `/scripts/`
  returns 404, not 403).

**Doctrine (the user made this explicit):** *"Assumption without confirming
facts is a key to downfall."* See `memory/no-assumptions-doctrine.md`. Verify
before asserting — probe scripts beat blog posts, live headers beat theory.

---

## 2. Current `.env` state (as of end of session)

```
VERTEX_MODEL=gemini-2.5-flash      # FAST lane (small talk, ~3s)
BUDDY_MODEL_THINKING=              # unset → defaults to gemini-3.5-flash (~8s)
BUDDY_PRICE_IN=1.50
BUDDY_PRICE_OUT=9.00
GOOGLE_TTS_API_KEY=<set>           # real voice is LIVE
TTS_VOICE=en-IN-Neural2-D
TTS_RATE=0.96
TTS_PITCH=-1
```

Model routing is automatic (`BuddyService::isHardQuestion()`): small talk →
fast lane, analytical questions → thinking lane. `BUDDY_SMART_ROUTING=false`
disables it.

**GCP:** billing card is attached. Budget "Aisha AI spend - 2000 INR monthly"
exists, alerts-only at 50/90/100% + 95% forecast, scoped to project
`johns-project-496821`. Total spend to date ≈ ₹2. Note: TTS is NOT eligible for
hard spend caps; only Gemini/Vertex/Cloud Run are.

---

## 3. What IS working (verified live, don't re-litigate)

- Gemini brain, tool calling, grounded answers — `ai:true`, no hallucinated numbers
- Real TTS voice plays (`/buddy/tts` → MP3, cached by content hash)
- Proactive nudges: 10 trigger rules, cron running every 15 min
- Feed delivery, toasts, pacing (cooldown + daily ceiling)
- Goals: set / track pace / celebrate / **clear**
- Patterns coaching + week recap (3.5-flash selects the tool; 2.5 did not)
- Personalization: `set_my_name`, facts, computed knowledge gaps
- Admin: team stats, confirm-gate on actions (park → cancel → confirm-fails ✓)
- Learning loop: nudge outcomes, 👍/👎 feedback → weekly consolidation
- Self-monitoring: every cron writes an unconditional heartbeat
- **All 7 crons registered and firing** (verified via heartbeat, not assumed)
- `php scripts/buddy_feed_verify.php` → **253 checks, all green** (SQLite, no
  network, no API cost). Run it after every change.

---

## 4. Known-broken / blocked (not the greeting bugs above)

- **Admin voice INPUT (mic) does not work.** Hostinger's CDN edge injects
  `Permissions-Policy: camera=(), microphone=(), geolocation=()` on every
  dynamic response. Origin `.htaccess` cannot override it (and doesn't even run
  on that subdomain — see §1). **Fix requires a Hostinger support ticket** or a
  hPanel CDN/security-headers toggle. John explicitly deferred this — he was
  burned before by a WAF change that silently broke transaction saves, and does
  not want config roulette on a live subdomain. Ask support:
  *"Your CDN injects Permissions-Policy microphone=() on crm.base-fare.com —
  can you allow microphone=(self) or exclude this subdomain?"*
  Voice OUTPUT is unaffected and works.
- Conversation mode (turn-taking, barge-in, thinking-out-loud filler) is built
  and tested offline but **never field-tested**, because it needs the mic.
- `gemini-3.7-flash` times out with our full payload — do not use.
- Attendance coaching in the buddy stays OFF (overnight date-split +
  dead break tracking unfixed; needs client sign-off).

---

## 5. Testing setup that exists

- **Test agent:** `test@basefare.com`, role agent, clocked in, preferred name
  saved as "TJ", monthly goal 5 sales. Logged into a Chrome window named
  *"skyteam search console"*.
- Claude can drive Chrome via the browser MCP — `list_connected_browsers`, then
  `switch_browser` and click Connect in the right window. Multiple Chrome
  profiles are connected; always confirm which one.
- Cannot enter passwords (hard rule) — John must log accounts in.
- Useful in-page probe (run in the test-agent tab):
  `fetch('/buddy/greeting',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:'{"fresh":1}'})`
- `php scripts/gemini_model_probe.php` — model × thinking-level matrix WITH
  latency, using the real registry and a forced tool call. `--bare` for minimal.

---

## 6. Landmines

- **hPanel cron form has a FIXED PREFIX** `/usr/bin/php /home/u501549865/`.
  Paste only the tail (`domains/base-fare.com/...`). Pasting a full path
  produces `//usr/bin/php` and the job silently never runs. hPanel has no edit —
  delete and recreate. Minute box is multi-select. A blank Weekday makes a
  "weekly" job run daily.
- **Browser cache lies.** Fetching `/assets/js/buddy-widget.js` without the
  `?v=` cache-buster returns a stale copy — this made a current deploy look
  months behind. Always fetch the versioned URL with `cache:'no-store'`.
- **Gemini 3.x** signs function calls with `thoughtSignature` that MUST be
  echoed back, and bills thinking as output tokens. Handled in
  `BuddyGeminiClient`, but don't strip unknown parts from echoed turns.
- `.gitignore` contains `test_*.php` — a test script with that name is silently
  ignored. Name verification scripts `*_verify.php`.
- Stage files explicitly; `git add -A` sweeps in stray root files.

---

## 7. Suggested order for the next session

1. **Fix Bug 1 (greeting prompt).** Cheapest, biggest perceived win. One prompt
   rewrite in `BuddyService::agentGreeting()`, plus suppress the hold notice in
   greetings. Same treatment for `adminGreeting()`.
2. **Fix Bug 3 (audio/text desync).** Pre-synthesize server-side, return the URL
   with the greeting, land text and voice together.
3. **Fix Bug 2 (voice quality).** Probe the live TTS voice list, build a
   Chirp3-HD / Studio flight, let John pick by ear, add SSML prosody.
4. Re-test the whole arrival experience end-to-end with the test agent.
5. Only then: the client showcase artifact may need updating —
   `https://claude.ai/code/artifact/cd9c9535-719d-4f54-ac02-636c391738d2`
   (source also at `Desktop\aisha-showcase.html`).

**Tone note for whoever picks this up:** John moves fast, tests everything in
production himself, and is rightly allergic to hand-waving. Give him measured
facts and one-line commands. He has said several times he wants brutal honesty
over reassurance — when something isn't verified, say so.
