# Session Handoff — 19 August 2026 (greeting rebuild)

Written at the end of a very long session. Everything below is current and
verified unless it says otherwise. `MEMORY.md` + `memory/ai-buddy-status.md`
carry the deep history; this is what the NEXT session needs to act.

---

## 0. START HERE — the arrival experience (rebuilt 19 Aug; one thing left)

The greeting was rebuilt this session after the client's verdict. Bugs 1 and 3
are **fixed and verified**. Bug 2 is **half done** — the code side is in, the
voice pick needs John's ear and one command on the server.

**The model was never the problem.** Greetings ran on `gemini-2.5-flash` the
whole time and still do: `agentGreeting()` calls `$this->client->chat()`, the
default client, and the smart router (`clientFor()`) is only wired into agent
and admin *chat*. There was nothing to revert. The prompt was the bug.

### What she actually says now

Measured live with `php scripts/buddy_greeting_probe.php` (real Gemini, four
situations, in-memory SQLite, hold deliberately left ON):

> Hey Priya, so good to see you! Chalo, let's clear that e-ticket for the
> booking that departs soon.  — **18 words**

> Hey TJ! So glad you're here! Ready for a great day? I was wondering, have you
> ever thought about setting a personal goal for your monthly sales?
> — **27 words**

> The floor is quiet so far, John. I'm curious, what are the key numbers you
> look for first when you start your day?  — **24 words, admin**

Four paragraphs became one, 18–39 words, ~2s.

### Bug 1 — the essay — FIXED

`BuddyService::agentGreeting()` and `adminGreeting()`:

- The prompt now demands ONE paragraph, 35 words max (admin 40), no
  statistics, no policy, and forbids narrating a zero.
- **The digest is gone from the greeting entirely.** In its place
  `greetingHighlight()` computes at most ONE notable thing in PHP, ranked:
  naked departure inside 72h → unread flagged item → sale awaiting e-ticket →
  sales already on the board today → nothing. It returns `['text', 'urgent']`.
- **The hold notice can no longer reach a greeting.**
  `renderAgentFallback($userId, $role, $forGreeting = true)` drops it. It is
  still in the chat digest, where an agent asking about their month needs it.
- **The candidates compete, they never stack.** This mattered: the first cut
  still stacked highlight + week nod + get-to-know-you question, and at 35
  words the model quietly dropped the *urgent* one — a flight departing inside
  72h lost its place to "what a busy week you just had". Now an urgent
  highlight suppresses both the week nod and the question.
- The get-to-know-you question is **woven in and rotated by day**
  (`$gaps[date('z') % count($gaps)]`), not always `$gaps[0]`. At 35 words it is
  a third of the greeting, and an agent who never answers the goal question
  would otherwise be asked it every single morning.
- **No tools on a greeting.** An empty `BuddyToolRegistry` sends no
  functionDeclarations, so a hello cannot become a research project — it
  removes every tool hop (seconds) and makes the numbers structurally
  unreachable rather than merely discouraged.
- The degraded fallback is a hello too, not the old "Here's where you stand"
  digest dump.

### Bug 3 — voice 10–15s behind the text — FIXED

`prewarmVoice()` synthesizes the MP3 **while the greeting is being generated**
and returns `audio_url` alongside `reply`. The widget plays it directly —
`speak(text, kind, audioUrl)` skips the `/buddy/tts` round-trip entirely, and
the URL is parked in sessionStorage so it survives a navigation.

`TtsService::speakable()` is the server-side mirror of the widget's `plain()`,
so the pre-warm produces the *same cache key* the widget would have — one MP3,
billed once. The verifier checks that pair against each other.

What is left is only the browser's autoplay gesture, which nobody can remove.

### Bug 2 — robotic voice — DONE (voice adopted 19 Aug)

In code:
- `TtsService` sends **SSML** (`<speak>` + a beat after the name, a longer one
  between sentences) instead of plain text. Minimal on purpose — heavy
  `<emphasis>` lands theatrical, which is a different wrong from robotic.
- The cache hash covers the markup decision, so text and SSML never collide.
- `supportsSsml()` and the pitch gate are **measured, not assumed** — see below.

**The probe corrected the code.** `scripts/tts_voice_probe.php` ran against the
live key on 19 Aug and disproved the rule the first cut shipped with. Results,
en-IN, 49 voices across 5 families:

| Family | Voices | SSML | Pitch | Synthesis |
|---|---|---|---|---|
| **Chirp3-HD** | 30 | **HTTP 200** | rejected | 1.1–2.4s, ~24–31kB |
| Chirp-HD | 3 | **HTTP 400** | rejected | ~1.0s, ~24kB |
| Neural2 | 4 | 200 | ok | 0.5–1.0s, ~54–59kB |
| Wavenet | 6 | 200 | ok | 0.5–0.8s |
| Standard | 6 | 200 | ok | 0.5–0.8s |

The first `supportsSsml()` excluded anything matching "chirp" and so denied
SSML to all thirty Chirp3-HD voices — precisely the ones most likely to fix
"no emotions". **The split is Chirp3 versus the older Chirp, not the word
"Chirp".** Fixed and locked into the verifier. The *pitch* gate really is all
Chirp, and was already right.

Neither Studio nor Chirp3-HD-with-pitch exists for en-IN. `TTS_PITCH=-1` will
simply be ignored if a Chirp3-HD voice is adopted.

**Adopted: `en-IN-Chirp3-HD-Aoede`**, chosen by ear from the flight against the
incumbent `en-IN-Neural2-D`. Live since 19 Aug — a `.env` edit, no deploy, no
pull (`.env` is read per request). Confirmed with
`php scripts/tts_voice_probe.php --list`, which prints the running voice and
spends nothing.

Consequences of that choice, all measured:
- **`TTS_PITCH=-1` is now inert.** Chirp3-HD rejects pitch, so the code omits
  it. The line is still in `.env` and does nothing; leave it or don't.
- **Synthesis is ~2x slower** than Neural2 (1.1–2.4s vs 0.5–1.0s), so a
  greeting costs roughly 4s server-side instead of 3s. Deliberate trade for
  the voice quality.
- The old Neural2-D MP3s are not reused — the cache key includes the voice.

To change it again: re-run the probe for fresh clips, listen, edit `.env`.
Rollback is the same edit, or the `.env.bak.<timestamp>` written at the time.

A future family (Chirp4?) is unknown territory: run the probe rather than
extending the pattern on a hunch, which is the exact mistake this section
records.

### Verification

- `php scripts/buddy_feed_verify.php` → **288 checks, all green** (was 253;
  F30 is the new section). Offline, no API cost. Run it after every change.
- `php scripts/buddy_greeting_probe.php` → the ear test the verifier
  structurally cannot be. Four live calls, cents.
- **Confirmed on production, 19 Aug.** The same probe run on the server reports
  `audio:pre-synthesized` on all four greetings — the pre-warm reaches the real
  Google TTS key and hands the widget a playable URL alongside the text.
  End-to-end cost **2.8-3.1s per greeting including synthesis**, against
  1.9-2.4s text-only on a dev box. So pre-synthesizing buys ~0.5-1s and deletes
  the 10-15s gap it replaced.
- The dev machine has no `GOOGLE_TTS_API_KEY` and the Vertex key returns
  `HTTP 401: API keys are not supported by this API`, so the pre-warm fails
  soft there. Offline the wiring is covered by seeding the cache file
  (synthesize short-circuits on a hit before curl) — but any voice work still
  needs a probe run on the SERVER before it is believed.

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
TTS_VOICE=en-IN-Chirp3-HD-Aoede    # adopted 19 Aug, by ear — see §0 Bug 2
TTS_RATE=0.96
TTS_PITCH=-1                       # ignored for Chirp voices (they self-intone)
TTS_SSML=                          # unset → auto: SSML on, except Chirp
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
- `php scripts/buddy_greeting_probe.php` — what Aisha ACTUALLY says on arrival,
  across four situations, with word counts and latency. `--repeat=N` for
  variance. Four live calls.
- `php scripts/tts_voice_probe.php` — live voice list + a Chirp3-HD/Studio/
  Neural2 flight of the same line, with per-voice SSML and pitch support.
  `--list` enumerates without spending.

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

All three greeting bugs are closed and verified server-side. What remains is
the one thing no script can see:

1. **Watch a real arrival in the browser**, logged in as the test agent. The
   probe proves the SERVER emits text and a playable URL together; it cannot
   see whether the autoplay gesture actually releases the MP3 on first click,
   whether the parked URL survives navigating to another page, or whether
   Aoede sounds right saying a real greeting rather than the probe's fixed
   line. That is the last unverified link in the chain.
2. Only then: the client showcase artifact may need updating —
   `https://claude.ai/code/artifact/cd9c9535-719d-4f54-ac02-636c391738d2`
   (source also at `Desktop\aisha-showcase.html`).

**Tone note for whoever picks this up:** John moves fast, tests everything in
production himself, and is rightly allergic to hand-waving. Give him measured
facts and one-line commands. He has said several times he wants brutal honesty
over reassurance — when something isn't verified, say so.
