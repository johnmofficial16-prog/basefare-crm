# Session Handoff — 28 August 2026

Written at the end of a long session that ran across the CRM, a live sales bid,
marketing collateral, and the brand film. Everything below is **current and
verified** unless it says otherwise.

`MEMORY.md` + the `memory/` files carry the durable facts and load automatically
next session — this document is the **action list**. The previous handoff is
archived as `HANDOFF-2026-08-19.md` (AI-buddy / greeting work; still valid but
older).

---

## 0. START HERE — three things with clocks on them

| # | Item | When | State |
|---|---|---|---|
| 1 | **AGC bidder questions** | **31 Aug** (3 days) | Not sent |
| 2 | **AGC proposal submission** | **11 Sep, 6pm GST** | Drafted, has unfilled fields |
| 3 | **CVV/PCI cleanup before any merchant application** | Before applying | Not started |

Nothing else in this document is time-critical.

---

## 1. Deploy state

Branch `dev` (production tracks `dev`, **not** `main` — see
`memory/deploy-branch-is-dev.md`). **Everything is pushed**; working tree has
only pre-existing untracked scratch files that were there before this session
(`show_tables.php`, `database/test_miles.sql`, `database/update_enum.sql`,
`scripts/buddy_redteam_admin.php`, the Trio JPEGs, and two loose PNGs).

Commits this session:

```
ba42046  assets(newsletter): September 2026 issue images
b305399  fix(emails): cache-bust the signature logo URL
1791cad  fix(emails): admin-only signature, and use the real Base Fare logo
1da86d1  feat(emails): per-agent signature on customer email
```

**Server pull status: UNKNOWN for `ba42046`.** John pulled through `b305399`
during the session (there is no auto-deploy — see `memory/no-assumptions-doctrine.md`).
The newsletter images in `ba42046` were still 404 on the server when last
checked. If the hosted newsletter is ever used, the server needs:

```bash
cd ~/domains/base-fare.com/public_html/crm && git pull origin dev
curl -sI https://crm.base-fare.com/assets/img/newsletter/hero-terminal.jpg | head -1   # expect 200
```

---

## 2. Shipped this session

### Email signature (done, live)
Per-agent signature on customer email. One JSON column `users.email_signature`,
rendered by `app/Services/EmailSignature.php`, admin-only, edited on
`/users/{id}/edit`. Migration `2026_08_24_user_email_signature.sql` **has been
applied on production**. Details in `memory/email-signature-module.md`.

### PNR correction (done)
`YA7RN9` → `YA7RM9` across `transactions` #555, `acceptance_requests` #711,
`etickets` #422 and the `performance.hold_exempt_refs` config. Verified.
**E-ticket 422 had already been emailed to `miloradcavic@yahoo.com` on 7 Aug
with the wrong PNR and was never acknowledged** — a correction resend was
offered and never actioned. Worth closing out.

Hard lesson recorded in `memory/mysql-idle-drops-transactions.md`: the prod DB
drops idle connections and silently rolls back open transactions. Use guarded,
id-pinned autocommit statements for manual fixes, never an interactive
`START TRANSACTION`.

---

## 3. AGC / Al Ghafia — the live bid (read `memory/agc-rfp-lead.md` first)

A $6M/yr corporate travel RFP that arrived through the website. **The
due-diligence findings are serious and unresolved** — `ghafia.com` was
registered 63 days before the RFP while the company claims 50 years of trading;
their company profile PDF predates their own domain by a day. A real firm of
that name exists in UAE directories, which makes impersonation the live
hypothesis rather than a resolved one.

John's decision: **proceed, but no credit for 12 months — prepaid Advance
Travel Account, wire transfer only.** The proposal is built entirely on that and
sells it as a benefit (~USD 248k of year-one value to AGC).

**To do next:**
1. **Send bidder questions by 31 Aug** — trade licence number, TRN, Chamber
   membership number, audited accounts, two trade references. These are normal
   procurement asks and will flush out a fake quickly. Draft was never written.
2. **Call the real Al Ghafia on a number found independently** (directory, not
   the RFP) and ask whether they issued a travel RFP and whether Meezan Dar
   works there. One call likely settles it.
3. **Fill the proposal's open fields** — submission date, Account Director,
   Account Manager, senior exec, insurance provider (all click-to-edit in the
   HTML), plus the §2.5 attachments: trade licence, incorporation certificate,
   IATA/IATAN cert, sample invoice. Also §3.1c (construction/EPC client
   experience — a *scored* criterion) and §3.2b (accounts managed).
4. Regenerate the PDF from the HTML once filled (toolbar → Save as PDF).

Files: `Desktop\Base Fare - AGC Corporate Travel Proposal.html` / `.pdf`.

---

## 4. Marketing collateral (all delivered, on Desktop)

| Asset | File | Note |
|---|---|---|
| Newsletter (send-ready) | `Base Fare Newsletter - September 2026.html` | hosted images — needs the server pull |
| Newsletter (self-contained) | `… - MASTER.html` | images embedded; best for browser/paste |
| Newsletter (client copy) | `… September 2026.pdf` | 9pp; **use this to send** — mail clients strip images from HTML attachments |
| Raksha Bandhan, square | `TrioTours_RakshaBandhan_Square.jpg` | 1080×1080 |
| Raksha Bandhan, status | `TrioTours_RakshaBandhan_Status.jpg` | 1080×1920 |
| TailoredPay assessment | `TailoredPay Assessment.html` | pure CSS, no JS, mobile-verified |

Trio banners use the **globe-and-plane mark** (`salary slip logo.jpeg`) —
correct for Trio. Base Fare uses `logo-v4` from the b2b repo. **The two are
routinely confused; the repo-root file named `basefare_logo_*` is actually
Trio's.**

Newsletter is "Issue #01", so a #02 is implied. Bulk sending should go through
Brevo/Mailchimp (legal unsubscribe) with images re-pointed at their CDN rather
than `crm.base-fare.com`.

---

## 5. Brand film — "The Machinery"

**Master v2 delivered**: `Desktop\Base Fare - The Machinery - Master v2.mp4`,
69s, 1080p. Client approved the first 30s; v1's flat end cards were rejected and
have been replaced. Full production grammar in `memory/basefare-brand-film.md`
and the bible artifact.

Higgsfield MCP, Pro plan, **~209 credits left**.

Not built yet, all offered and none started:
- 30s cutdown for paid placements
- 9:16 crops for Reels/Shorts
- The Family/Tourist chapters (shelved when the client pivoted to B2B)

Cheap pipeline established for anything further: cast stills at ~2 cr, animate
from `start_image` with Kling 3.0 pro at ~2.5 cr/sec, edit/score/overlay locally
for free. Seedance 1080p (9 cr/sec) only when a reference must hold *mid-shot*.

---

## 6. Open items, ranked

1. **CVV storage** — `cvv_enc` on `payment_cards` (`database/schema.sql:201`).
   PCI-DSS prohibits retaining CVV after authorisation; encryption does not cure
   it. Blocks an honest PCI attestation on any merchant application, and is
   grounds for termination if found later. Fix = drop the column + purge values.
   **Not started.**
2. **AGC actions** — §3 above.
3. **Performance hold** — `memory/performance-hold-august-2026.md` says the
   1–9 Aug window is still hidden from non-admins and **must be lifted when the
   merchant releases**. Not revisited this session; still worth checking.
4. **E-ticket 422 correction resend** — §2 above.
5. **Newsletter images** — server pull for `ba42046`.
6. **Film cutdowns** — §5 above.

---

## 7. Things that will bite the next session

- **No auto-deploy exists.** John pulls manually, every time. Never state a
  change is live because it was pushed.
- **Prod MariaDB kills idle connections** and silently rolls back open
  transactions — see §2.
- **`ffmpeg`'s `reverse` filter silently drops frames** past ~480; reverse long
  clips in segments and re-concat.
- **HTML sent as an email attachment gets its images stripped** by Gmail/Outlook
  previews. Send PDFs to clients.
- **Browser/CDN caching hides asset swaps for up to 7 days.** Cache-bust asset
  URLs (`?v=mtime`) rather than debugging a "broken" deploy.
- **Headless-Chrome screenshots at a set `--window-size` do not reliably set the
  CSS viewport.** Verify responsive layout with real device emulation, not a
  cropped screenshot — it produced a false "broken table" report this session.
