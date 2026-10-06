# Session Handoff — 5 October 2026

A CRM-heavy session (29 Sep – 5 Oct) driven by client requests, plus three
one-off documents. `MEMORY.md` + `memory/` carry durable facts and load
automatically — **this document is the action list**. Previous handoff archived as
`HANDOFF-2026-08-31.md`.

**Production is at `ef8bbd5`** (John pulled 6 Oct; all migrations applied). Nothing
is committed-but-unpushed. The CRM never auto-deploys — John pulls by hand.

---

## 0. START HERE — waiting on John / the client

| # | Item | What's needed |
|---|---|---|
| 1 | **Unsigned exchange acceptances sent 4–5 Oct** | They carry the (now removed) voucher wording in clause 8 of their stored policy. Resend as fresh acceptances. |
| 2 | **Exchange vouchers already issued 4–5 Oct** | Rows in admin **Vouchers** (`travel_vouchers.source='exchange'`). Client decides: honour or mark void. |
| 3 | **Trio letterhead v3** | Sent 5 Oct; no verdict yet after two rejections. Phone/email not on it (none found publicly) — add if John sends them. |
| 4 | **Persad e-ticket PDF amount** | PDF shows "Fiji Airlines USD 133.32" (John's breakdown); Amadeus receipt says USD 132.90. Unconfirmed which is right. |
| 5 | **Fare-terms legal review** | Template wording (incl. the DOT refund line) should be checked by whoever handles merchant/legal. Editable at `/admin/fare-terms` (managers + admins). |
| 6 | **Live checks never done** | Real Gmail send of an email **with attachments**; an attachment upload **from JSR** (WAF risk, `memory/hosting-waf-constraint.md`); one real refundable acceptance signed on the live `/auth/` page. |

---

## 1. What shipped this session (all live)

| Commit | What |
|---|---|
| `9fbaf5d` | **Fare-type T&C** — picker (Non-ref / Refundable w/ penalty / Fully refundable / Custom fill-in-blanks) on acceptance + e-ticket; cabin defaults (Economy→non-ref, higher→penalty); server-rendered text; refund sentence stored per record (`refund_ack_text`). `memory/fare-terms-and-email-attachments.md` |
| `165a491` | **Email attachments** on Customer Emails compose + reply (10 files / 10 MB each / 20 MB total, private storage, shown on approval card, attached at send). |
| `22fc5a8` | Fix: missing `use FareTermsService` crashed `/etickets/create` in production — `memory/verify-class-imports.md`. |
| `0adda4b` | Fix: refund checkbox + Fare Rules added to the **real** customer page `public/auth/index.php` (see §3). |
| `2f2cff3` → `2f1ca5b` | **Exchange Future Travel Voucher** built, then **removed same week at client request** (clean revert; migration file kept because the columns exist live). Do not rebuild unless asked. |
| `f16a6ca` | **AI email drafting upgrade** — `gemini-3.5-flash` via new `EMAIL_AI_MODEL` knob (VERTEX_MODEL untouched → buddy/analytics unchanged), rewritten prompt, thread history + booking flights as context, injection-resistant. `memory/email-ai-quality-upgrade.md` |
| `ef8bbd5` | **Fix: "Record Transaction" silently did nothing** (6 Oct, user #21, 4× in Error Console at 22:55: `Cannot read properties of undefined (reading 'trim')`). Only two `.trim()` calls run on submit: imported split-card (`card_number` missing — acceptances store `card_last_four` only) and imported fare-line `label`. Both made null-safe, imported cards normalised ("ends 4242" hint), and `formAssembly.submit()` now shows a "nothing was saved" alert + rethrows to the Error Console. **Agent confirmed working after the fix.** Exact triggering data not identified: the agent's only recent acceptance (#1143) has no extra card and labelled fare lines and was already recorded (txn #887, 03:47 same day) — likely a re-import / other acceptance via link. Bug latent since the 8 Apr release. |

Also confirmed live: **Call Logs** (`9a82c4d`, 24 Sep) — earlier notes wrongly said uncommitted.

---

## 2. One-off deliverables (not in the repo)

- **Persad readable e-ticket** — `Desktop\PERSAD_SAMANTHA_E-Ticket_DSM76D.pdf` (3 pages, from the Amadeus email) + a manual customer email drafted in chat.
- **Trio Tours & Travels letterhead v3** — `Desktop\Trio_Tours_Letterhead_Premium.docx`; rebuild source in `Desktop\Trio Letterhead - source\` (README inside). Real MCA details: CIN `U63030PB2022PTC055278`, Office No. 51, Plot No. D-185, Prosperity Square, Phase 8, Industrial Area, Mohali 160062. `memory/design-deliverables-standard.md`
  - The **LOI maker** (`/letters`) still defaults to the placeholder Trio address — could now be filled with the real details above.
- Older letterhead drafts on the Desktop (`Trio_Tours_Letterhead.docx`, `Trio_Tours_and_Travels_Letterhead.docx`) were rejected — safe to delete.

---

## 3. Things that will bite the next session

- **The live customer signing page is `public/auth/index.php`**, not `app/Views/acceptance/public_auth.php` (that Slim view is never reached in production). E-ticket is the opposite: `public/eticket/index.php` includes `app/Views/eticket/public_eticket.php`. `memory/customer-pages-are-standalone.md`
- **`php -l` doesn't catch a missing `use` import.** Resolve every class reference before pushing (the e-ticket outage).
- **The Bash tool collapses `\\` in commands** — PHP namespaces / regex backslashes in sed or Python heredocs get mangled. Use the Edit tool, or a script file written with Write, and read back the result.
- **Local smoke-test kit** (isolated MySQL 8.4 from Laragon on :3306, fake STARTTLS SMTP, `php -S` + router, env overrides) — recipe in `memory/customer-pages-are-standalone.md`; the scripts lived in the session scratchpad and are gone. Gotchas: `public/index.php` ignores `DB_PORT`; MariaDB-only `ADD COLUMN IF NOT EXISTS` must be stripped for MySQL; seed `ip_whitelist` with 127.0.0.1; agents need an active `attendance_sessions` row; drop the `openssl.cafile` override when testing real Google API calls; make the test cert long-lived.
- **GCP billing** was disabled ~4 Oct (AI emails returned 403 "requires billing"); John re-enabled it. The project (#565252126618) belongs to a Google account **not** signed into the Chrome profile Claude in Chrome uses.
- **Client expectations on design are high** — two letterhead versions were rejected as amateur. Render and visually check every design before sending.

---

## 4. Known bugs / small follow-ups

0. **Check whether the split-card crash hit anyone earlier:** search Error Console for `reading 'trim'` before 6 Oct (anything pre-12 Aug was never logged). Agents may have re-keyed or abandoned those bookings.
1. **Transaction edit drops `data.fare_breakdown`** (pre-existing): `TransactionController::update()` never saves `fare_breakdown_json`. Views fall back to the acceptance, so mostly hidden. A task chip was raised for it.
2. `TransactionService` warns `Undefined array key "travel_date"/"departure_time"/"return_date"` when a create POST omits them (only seen with a hand-built test request; the real form sends them).
3. `scripts/buddy_redteam_admin.php` (untracked) has a PHP parse error — harmless, not deployed by git.

---

## 5. Carried over from 31 Aug (status not revisited this session)

1. **CVV storage** — `cvv_enc` still stored; blocks honest PCI attestation (TailoredPay etc.).
2. **AGC / Al Ghafia RFP** — question (31 Aug) and proposal (11 Sep) deadlines have passed; check with John whether it's dead.
3. **Performance hold** (1–9 Aug hidden from non-admins) — lift when the merchant releases.
4. **E-ticket 422** wrong-PNR correction resend — never actioned.
5. **b2b site film section** — was committed-not-pushed on 31 Aug; YouTube copy had a Content ID claim.
