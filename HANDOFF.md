# Session Handoff — 31 August 2026

A long session that ran almost entirely on **marketing and brand work**, not the
CRM. Two things shipped: a hiring campaign for Trio Tours, and **Brand Film II
("From Booking to Boarding") rebuilt end to end** after client feedback.

`MEMORY.md` + the `memory/` files carry durable facts and load automatically next
session — **this document is the action list**. Previous handoff archived as
`HANDOFF-2026-08-28.md`.

---

## 0. START HERE — what is waiting on a decision

| # | Item | State |
|---|---|---|
| 1 | **b2b site: film section is committed but NOT pushed** | One `git push` deploys it live. John said "yeah do that" — but asked for SEO work to be considered first, so it may be worth bundling into one deploy. |
| 2 | **YouTube copy has a Content ID claim** | Private, unlisted so far. Claim is on the Zimmer music. Must be checked before going public. |
| 3 | **AGC bidder questions** | Deadline was **31 Aug** — that is today/passed. Never sent. See `memory/agc-rfp-lead.md`. |
| 4 | **CVV/PCI cleanup** | Still not started. Blocks any merchant application. |

---

## 1. Brand Film II — "From Booking to Boarding" v2

**Delivered:** `Desktop\Base Fare Work\Base Fare - From Booking to Boarding - v2.mp4`
(88s, 1080p, full mix). Client has **approved it**.

> ⚠️ **All Base Fare video files now live in `Desktop\Base Fare Work\`.**
> John reorganised the Desktop mid-session — older paths in previous handoffs are dead.

### Asset folders (all on Desktop)
- `Base Fare Work\` — masters (Machinery v1 + v2, Booking-to-Boarding v1 + v2)
- `Base Fare - Film II Stills\` — the 8 approved stills, 2752×1536+
- `Base Fare - Style Board\` — the 8 casting looks; **client chose 08 (pinstripe + tie bar)**

### The locked structure (client-approved treatment)
Artifact: https://claude.ai/code/artifact/00fd216e-0646-48a0-bc35-baf1dd7c8187

The big idea: **Film I ("The Machinery") is nested inside Film II.** It plays on the
protagonist's TV, its command-centre/globe footage becomes the finale, and its
clock + light-tunnel (reversed) build the rewind. Two films, one universe.

**Client's one structural change:** the command centre + globe moved to play
*after* the Gates walk and *before* the Seat — turning the machinery from
explanation into crescendo. He was right; it is the strongest cut in the film.

**Client's locked look:** the backlit gates walk — *"walk aisa hi rehna chahiye
jaise light pichey se aa raha hai."* Do not relight that shot.

### Production numbers (for estimating future films)
- Kling 3.0 `kling3_0`, mode=pro, sound=off: **8.75cr / 5s**. sound=on: **12.5cr**.
- Runway `nano-banana-pro`: **1K and 2K both cost 20cr** — 1K is 1376×768 (**sub-HD, unusable**),
  2K is 2752×1536. **Always pass `imageSize: "2K"`.** 4K costs 40cr for no gain
  (Kling renders ~1928×1076 regardless).
- VO via Higgsfield `seed_audio`, Fraser preset `6705e465-7b52-5915-a1d8-b1222885e01d`: 0.4cr/line.
- Total v2 spend: ~96cr Higgsfield, ~140cr Runway.

### Balances at wrap
- **Higgsfield: ~25 credits** (Pro plan). Enough for ~2 retakes only.
- **Runway: ~110 credits** (~5 stills). John topped up 250 mid-session.
- **No unlimited available.** Verified by testing, not by reading docs: the unlim
  flag is rejected for this account, `trial_status.eligible: false`. The "365-day
  unlimited" bundles are **web-only, not MCP**, and their purchase window said
  "buy until Aug 26". See `memory/basefare-brand-film.md`.

---

## 2. The b2b website — film section (COMMITTED, NOT PUSHED)

Repo: `Desktop\basefare b2b` · commit **`0be637a`** on `main`, unpushed.

> ⚠️ **This repo DOES auto-deploy** — push to `main` → GitHub Actions → `npm run build`
> → rsync to Hostinger. **Unlike the CRM**, which John pulls by hand. Do not confuse them.
> The deploy is `rsync --delete`, so anything not in `dist/` gets wiped from the server —
> which is why the videos had to go into `public/`, not be uploaded separately.

**What was added:**
- `#brand-film` section between the hero and platform teaser, using the site's own
  tokens (`--bg-dark`, `.section-tag`, `.section-title`) so it reads as native.
- `public/video/`: 1080p (21MB) + 720p (9.8MB), both `+faststart`, plus a poster.
- Responsive `<source media>` — verified: 1440px viewport pulls 1080p, narrow pulls 720p.
- Poster-click player, fires a `brand_film_play` GA event.
- **Self-hosted deliberately, not a YouTube embed** — the YouTube copy is private and
  claimed; an embed would show visitors an unavailable video.

**Verified before commit:** `npm run build` passes, assets land in `dist/video/`,
all serve 200, playback + audio + source-switching confirmed in a real browser.

**Left untouched on purpose:** the hero's "See How It Works" button has a play icon
but jumps to a text section. It is the natural link to the film now — but it sits in
the live Google Ads conversion path, so it needs John's explicit OK.

---

## 3. SEO opportunities found (John asked — these are the answers)

Quick audit of `basefare b2b`, nothing implemented yet:

1. **Sitemap is missing 12 pages.** `public/sitemap.xml` has 20 `<url>` entries;
   the repo has 32 HTML pages. The carrier-desk landing pages (Qatar/BA/Lufthansa/
   AirFrance, added in `6db0683`) appear to be among the missing. **Highest-value,
   lowest-effort fix on the site.**
2. **No `VideoObject` schema** anywhere. The new film is a free rich-result
   opportunity — Google can surface video thumbnails in search. Should be added to
   `index.html` alongside the existing Organization schema.
3. **No `og:video` / `twitter:player` tags.** Sharing the homepage on social shows a
   static image when it could show the film.
4. Existing schema is decent — FAQPage on 5 pages, Service, Article, Organization.
   The gap is specifically video + sitemap coverage.

**Recommendation:** do 1–3 in the same commit as the film, then one deploy instead of two.

---

## 4. Trio Tours hiring campaign

Playbook artifact: https://claude.ai/code/artifact/1344b711-f0be-42f2-b40e-d09cee1e4d3d
Full detail in `memory/mohali-hiring-drive.md`.

**Live:** one Indeed post — *Travel Advisor – US Voice Process*, Mohali, ₹40–60k,
6 openings, **free tier, ₹0 spent**, status was "Pending → live in a few hours".
Account: `Param@triotoursandtravels.in`.

**The finding worth keeping:** every listicle says apna and WorkIndia are free.
**They are not** — their own pricing pages show paid-only. Genuinely free:
Job Hai, Indeed, Freshersworld (5 contacts only), Naukri (1 job/7 days), LinkedIn
(1 post, ~10–30 application cap), PGRKAM, Google for Jobs.

**Parked, needs John:**
- **Dimapur, Nagaland** post — Trio has a second branch; agreed at **₹30–40k**.
  Never posted. Indeed allows ~3 free jobs/month so it costs nothing.
- **Naukri** — registration is mobile + OTP. John's number `8178774775` is
  **account-creation only, never to be published to candidates.** That constraint
  is why Job Hai was parked (it is phone-first by design and demands company KYC docs).
- **Trio Tours has almost no online footprint** — no company page, no reviews.
  For a night-shift role where families weigh in, this is the biggest conversion
  risk. Free fixes, in payoff order: Google Business Profile → AmbitionBox claim.

---

## 5. Things that will bite the next session

- **Two repos, two deploy models.** `basefare b2b` auto-deploys on push to `main`.
  The **CRM does not** — John pulls by hand, every time (`memory/no-assumptions-doctrine.md`).
- **Runway `nano-banana-pro` defaults to 1K = 1376×768, below HD, at the same price
  as 2K.** Always pass `imageSize: "2K"`.
- **Frontal faces drift.** A ¾-view reference does not hold a front-on generation —
  the beard thinned and the face narrowed. Fix: pass **two** references (approved ¾ +
  frontal) and run a face-zoom comparison against the anchor before animating.
- **Verify staging and eyelines at the STILL stage.** The client caught a living-room
  shot where the TV was behind the protagonist — he could not have been watching it.
  Cost a reshoot that a 10-second geometry check would have prevented.
- **Motion direction only reveals itself in playback.** The client caught the closing
  aerial flying backwards; frame-level QC had missed it. **John is the motion QC** —
  always ask him to play the master end to end.
- **Kling intercepts batch submissions with preset recommendations** ("IN THE DARK").
  Resubmit with `declined_preset_id`.
- **Kling supports `start_image` + `end_image`** — the clean way to get an eyeline
  move (looking out a window → down at a phone) in a single shot.
- `ffmpeg`'s `gblur` will not take a conditional `sigma` expression; `eq` will take
  conditional `brightness`/`saturation`. Arial has no ✈ or ✓ glyph — draw shapes
  or use `seguisym.ttf`.
- **Higgsfield is at ~25 credits.** Any further video work needs a top-up first.

---

## 6. Everything else (unchanged from 28 Aug, still open)

1. **CVV storage** — `cvv_enc` on `payment_cards`. Blocks an honest PCI attestation
   on any merchant application. Not started.
2. **AGC / Al Ghafia** — bidder questions were due 31 Aug and were never sent;
   proposal due 11 Sep with unfilled fields. Serious unresolved due-diligence red
   flags. See `memory/agc-rfp-lead.md`.
3. **Performance hold** — the 1–9 Aug window is still hidden from non-admins and
   must be lifted when the merchant releases.
4. **E-ticket 422** — emailed to the customer on 7 Aug with the wrong PNR, never
   corrected. Correction resend still not actioned.
5. **Newsletter images** — commit `ba42046` may still be unpulled on the server.
