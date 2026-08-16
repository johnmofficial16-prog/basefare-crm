# CRM Bug Audit — Findings

Generated 12 Aug 2026 by 7-dimension multi-agent audit with adversarial verification. 44 raw findings -> 42 deduped -> 40 CONFIRMED, 2 rejected. All line numbers are pre-fix.

**By severity:** 2 critical, 14 high, 13 medium, 10 low

## CRITICAL (2)

### 1. Typing anything in the transactions search box removes ALL role scoping - any agent can browse every agent's transactions company-wide
`app/Controllers/TransactionController.php:70`

**Scenario:** A logged-in agent opens /transactions?search=a (or '@', or a single letter). Line 70 sets $agentFilter = null because search is non-empty, and $agentIds stays null, so TransactionService::list() runs unrestricted. The agent pages through every transaction in the company - customer names, phones, emails, PNRs, charged amounts, MCO - for all agents and centres. Managers (line 60-62) and supervisors (line 66-68) similarly escape their team scope whenever a search term is present. The per-record view() endpoint blocks non-owners, proving cross-agent visibility is NOT intended, yet the list rows already leak all customer PII and money figures.

### 2. Partial refund is a non-atomic read-modify-write with no idempotency guard — double-click records the refund twice; concurrent admins lose refunds
`app/Services/TransactionService.php:433`

**Scenario:** Admin opens the refund modal (app/Views/transactions/view.php:1165 — a plain POST form, no submit-button disable, no one-time token; the CSRF token is static per session per CsrfMiddleware.php:26 so it does not prevent resubmission) and double-clicks 'Confirm Refund' for a $100 partial refund on a $500 approved sale. Both POSTs pass every validation (second runs after the first thanks to PHP session-file serialization, sees remaining=$400, still > 0) so refunded_amount becomes $200 for a single real-world $100 refund; refund_mco_impact is recomputed for the doubled fraction, driving Net MCO deeply negative and penalizing the agent's performance numbers. Conversely, two DIFFERENT admins refunding concurrently (separate PHP sessions, so truly parallel) both read refunded_amount=0 into $txn before either commits; each writes newRefunded = 0 + own amount, so the second UPDATE overwrites the first and $100 of actually-refunded money vanishes from the ledger.

## HIGH (15)

### Supervisor can EDIT any transaction company-wide (money/MCO tampering, incl. approved)
`app/Controllers/TransactionController.php:406`

**Scenario:** The edit ownership guard at line 406 only checks `ROLE_AGENT` and `ROLE_MANAGER`; a supervisor falls through and may POST edits to any transaction ID in the company — altering amounts, MCO, and payment status on other teams' bookings, including already-approved ones. Sibling of the supervisor VIEW IDOR but worse: this writes, not just reads.

**Fix:** Add `ROLE_SUPERVISOR` to the scoped branch at line 406 with `getTeamAgentIds()` team-membership check, denying edits outside their team.


### 3. Supervisor can view any acceptance record and receipt by ID despite own-only list (PII IDOR)
`app/Controllers/AcceptanceController.php:274`

**Scenario:** In index() a supervisor falls into the final else and gets $agentFilter = $userId (own acceptances only, lines 62-65). But view() only blocks ROLE_AGENT viewing others (line 274 `elseif ($userRole === User::ROLE_AGENT && ...)`) and receipt() does the same (line 428) - supervisors and CSA fall through to full access. A supervisor walks /acceptance/{id} and /acceptance/{id}/receipt for every id, reading customer name/email/phone/PNR/amount for records outside any team they own.

**Fix:** In both view() and receipt(), add a supervisor branch mirroring the manager one but using the supervisor's team scope (User::find($userId)->getTeamAgentIds() plus self, matching how index() would scope them — or, to match index() exactly, own records only). Also audit the sibling per-id endpoints in the same group (addNote, resend, cancel, downloadEvidence, revealCC) for the same missing supervisor scoping, especially revealCC given stored card data.

### 4. Overnight sessions disappear from 'today' views after midnight: dashboard hours card zeros mid-shift and the live board marks a finished overnight agent absent
`app/Controllers/DashboardController.php:43`

**Scenario:** This is the concrete blast radius of attendance_sessions.date being set at clock-in (known bug 3) — call sites the known bug implies. (a) DashboardController.php:42-45 fetches todaySession with forDate(date('Y-m-d')): an agent clocked in Aug 13 18:00 who loads the dashboard at 00:30 Aug 14 is mid-shift with an ACTIVE session dated Aug 13, so $todaySession is null and the 'today' stats card shows 0 work minutes, no clock-in time, status 'none' while they are clocked in (the clock-in state itself stays correct because getCurrentState ignores date). (b) AttendanceService::getLiveBoardData (line 911-913) fetches sessions where date = today OR status = active: an agent who clocked out of the overnight shift at 03:00 has a COMPLETED session dated yesterday, which matches neither condition, so from 03:00 to 18:00 the admin board lists them in 'absent' instead of 'completed' — an admin doing a morning attendance check sees a false absence. (c) my_attendance.php:22-32 matches only date === today, so during the after-midnight half of a shift the 'Today's Shift' card disappears entirely (and its progress bar at lines 79-84 rebuilds the schedule on today's date, showing 0%).

**Fix:** Root fix is the known underlying bug: assign attendance_sessions.date as the shift/business day (e.g. if clock-in time is before the 09:00 rollover boundary, use the previous calendar date) at AttendanceService.php:1055, consistent with the existing business-day rollover convention. Then make readers shift-day aware: DashboardController.php:42-45 and my_attendance.php:22-32 should resolve 'today' via the same shift-day helper (or fall back to the latest active/recently-completed session), and getLiveBoardData (AttendanceService.php:911-913) should include sessions dated yesterday whose scheduled_end crosses midnight (or simply query by shift day once the date column is fixed).

### 5. Supervisor can view any transaction by ID though their list is team-scoped (customer PII IDOR)
`app/Controllers/TransactionController.php:268`

**Scenario:** index() scopes a supervisor to their own team's agent_ids (lines 63-68, $agentIds = teamIds), so the list only shows their team. But view() sets $isAdmin = in_array(role,[ADMIN,MANAGER,SUPERVISOR]) at line 251, and the ownership guard at line 268 only fires for non-admins that aren't CSA; supervisors are inside the $isAdmin set so the guard never runs. A supervisor enumerates /transactions/1..N and reads every customer's name, email, phone, PNR, amounts, passengers and card-last-four across all teams.

**Fix:** In TransactionController::view(), remove SUPERVISOR from the $isAdmin array and add a supervisor branch mirroring the manager one at lines 262-267 that denies access unless $txn->agent_id is in User::find($userId)->getTeamAgentIds() (also audit acceptanceData/editForm for the same pattern).

### 6. IP-allowlist office-network restriction bypassable with a forged X-Forwarded-For / CF-Connecting-IP request header
`app/Middleware/IpRestrictionMiddleware.php:36`

**Scenario:** IP whitelisting is enabled and a non-admin (agent/manager/supervisor/csa) is off the office network - e.g. a terminated agent whose credentials still work, or an attacker with stolen credentials, or any agent working from home. The middleware computes $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR']. Both HTTP_CF_CONNECTING_IP and HTTP_X_FORWARDED_FOR are fully client-controlled request headers. The attacker sends `X-Forwarded-For: <a whitelisted office IP>` (or CF-Connecting-IP), isIpAllowed() matches it, and they are granted full CRM access - card data, payroll, refunds - exactly what the allowlist exists to prevent. The check that is supposed to confine login to the office network is defeated by one header, from any location.

**Fix:** Derive $clientIp from REMOTE_ADDR by default and only honor CF-Connecting-IP/X-Forwarded-For when REMOTE_ADDR is a known trusted proxy (e.g. Cloudflare's published IP ranges), taking the last untrusted hop rather than the client-supplied first entry.

### 7. Card encryption failure during acceptance creation is swallowed — record saved without the card, agent told success
`app/Services/AcceptanceService.php:81`

**Scenario:** Agent creates an acceptance and keys in the customer's full card number, expiry and CVV. EncryptionService::__construct throws (ENCRYPTION_KEY_A missing from a mangled .env, or ENCRYPTION_KEY_FILE unreadable after the July 2026 key rotation — exactly the failure mode this shop has already had). The catch block only error_log()s and continues: the acceptance is created with card_number_enc/card_expiry_enc/card_cvv_enc all NULL, the agent sees the normal success flash, and the customer signs. At charge time, 'Import from acceptance' returns an empty card_number (TransactionService::getAcceptanceAutofill) and /acceptance reveal returns 422 'No full card details stored' (AcceptanceController.php:370-371). The card data the agent typed is unrecoverable — the charge can't be keyed without embarrassingly re-contacting the customer, and nothing ever surfaced the failure.

**Fix:** In AcceptanceService::create, let the encryption Throwable propagate (or return a typed failure) so AcceptanceController::store aborts with a flash_error telling the agent card details could not be secured, instead of silently saving the record with null *_enc columns.

### 8. Chargeback forensic evidence IP (and e-ticket acknowledgment IP) is taken from client-controlled headers, so it can be forged by the signer
`app/Services/AcceptanceService.php:451`

**Scenario:** A customer authorizing a card charge (or later disputing it) is the one making the HTTP request that records the forensic evidence. collectForensicData() reads $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR']. The customer sets `X-Forwarded-For: 8.8.8.8` (or any value) and the stored forensic IP - plus the ip-api.com geolocation derived from it - becomes attacker-chosen. This is the record used to defend chargebacks (memory: chargeback-defense is the whole purpose of the CCH-signs flow), so the evidence a merchant relies on in a dispute is fabricable by the disputing party. The stored $ip is returned/persisted even when it is a private or spoofed value (the public-IP filter at line 467 only gates the geolocation lookup, not what is saved).

**Fix:** Use $_SERVER['REMOTE_ADDR'] as the authoritative IP; only honor forwarding headers when REMOTE_ADDR matches a configured trusted-proxy list, and then take the right-most untrusted hop (not the first element). Apply the same fix in ETicketService::collectForensicData and public/eticket/index.php recordAcknowledgment. Optionally store both REMOTE_ADDR and the raw header for audit.

### 9. Post-midnight clock-in and override resolve against the wrong calendar day's shift, blocking agents or binding tomorrow's schedule
`app/Services/AttendanceService.php:150`

**Scenario:** Agent's shift row is Aug 13 21:00-06:00. They are blocked at 23:50 (past grace) and an admin approves an override at 00:10 Aug 14. attemptClockIn now runs with $today = '2026-08-14': the shift lookup (line 151) returns Aug 14's row, so the gate compares 00:15 against Aug 14 21:00 and answers 'Too early. You can clock in from 8:30 PM' — the override (checked only against shift_date = Aug 14, lines 155-157/199-201) is never consulted and the agent cannot work the rest of the night; their hours for that shift are lost. If no Aug 14 row exists yet, the override path at line 160 opens a session with default 09:00-18:00 scheduled times, so early-departure detection (line 274) and cron/auto_clockout.php's getScheduledEndTimestamp compute against the wrong window. adminClockIn (line 559) has the identical date('Y-m-d') lookup and stamps the post-midnight session with the NEXT night's 18:00-03:00 schedule, so if that agent forgets to clock out, auto_clockout waits until the following day (scheduled end +24h in the future), hits the >24h-stale branch, and computes no pay. Additionally the live board's pending-override bucket (line 953) filters failed attempts by created_at >= today 00:00:00, so the 23:50 blocked attempt vanishes from the admin queue at midnight while the agent is still waiting.

**Fix:** In attemptClockIn/adminClockIn (and the live-board pending bucket), resolve the effective shift date with the existing business-day rollover (ShiftService::businessDayBounds / businessDayStartHour) instead of raw date('Y-m-d') — i.e. before the rollover hour, look up the previous calendar day's shift row and anchor shift_start, override shift_date, and failed-attempt filters to that date, falling back to today's row only if yesterday has none or its window (end + grace) has passed.

### 10. Shift-update auto-unlock lets an agent open a second session on the same date, which the monthly report then silently drops
`app/Services/AttendanceService.php:102`

**Scenario:** This is the production trigger for known bug 1's data loss. Agent works 09:00-14:00 as scheduled and clocks out (session A, 300 work mins). A manager then edits any field of that agent's shift row for today (ShiftService::updateCell/publishWeek run updateOrCreate, bumping updated_at) — even just correcting the template or re-publishing the whole week unchanged. getCurrentState sees shift.updated_at > clock_out and flips the state from CLOCKED_OUT back to NOT_CLOCKED_IN (lines 100-104), so the agent clocks in again for the evening (session B, same `date`). getMonthlyReport keys $sessionMap[user][date] (line 829), so session A's 300 paid minutes are overwritten by session B in both the admin monthly grid and the payroll CSV export (AttendanceController::exportMonthlyCsv reads the same map). A routine week re-publish thus silently deletes hours from the payroll report for every agent who already completed a session that day.

**Fix:** In getMonthlyReport, store sessions per date as a list ($sessionMap[$uid][$date][] = $s), sum work/break/late across all sessions per date in the summary, and emit one CSV row per session (or merge them per date). Separately, tighten the auto-unlock to compare the shift's actual times against the completed session's scheduled_start/end instead of updated_at.

### 11. Transaction creation has no idempotency key and no unique constraint on acceptance_id — a retried POST or two tabs create duplicate bookings that double revenue and MCO
`app/Services/TransactionService.php:46`

**Scenario:** An agent submits the transaction-create form; the request stalls (this host's shared WAF is known to interfere with POSTs) and the browser retries, or the agent hits back and resubmits, or two agents each pull up the same approved acceptance (the doesntHave('transaction') filter in getAutofillOptions line 697 only shapes the dropdown — the posted acceptance_id is never re-validated). Each POST creates a full new Transaction with the same PNR, acceptance_id, and profit_mco. Both rows immediately count in dashboard money KPIs — DashboardController lines 152-176 sum total_amount/profit_mco for status != voided, which includes pending_review — so today's revenue and the agent's MCO are doubled until someone notices and voids one.

**Fix:** Add a UNIQUE index on transactions.acceptance_id (nullable, so free-standing transactions still allow multiple NULLs) and, inside TransactionService::create's DB transaction, reject/return-existing when a non-voided transaction already references that acceptance_id; optionally add a one-time per-form idempotency token to also stop duplicate non-acceptance submissions.

### 12. Post-refund edits never recompute refund_mco_impact, breaking the Net MCO refund-loss invariant in every report
`app/Services/TransactionService.php:182`

**Scenario:** Admin fully refunds a booking (total 1000, profit_mco 200, gross MCO 250, fee 50): refund() stores refund_mco_impact = 200+250+50 = 500 so netMco() = -300 = -(gross+fee), per the refund-loss rule. Afterwards the owning agent (allowed - isEditable() only blocks voided records, and money fields are explicitly agent-editable per the 2026-06-24 policy) corrects profit_mco to 300. update() writes the new profit_mco but leaves refund_mco_impact at 500, so netMco() = 300-500 = -200 instead of the rule-correct -(new gross+fee). Every consumer of profit_mco - refund_mco_impact (DashboardController KPIs/leaderboards, PerformanceController scores and CSV, AnalyticsService centre metrics, MobileAdminController) now reports a wrong refund loss. Editing total_amount below refunded_amount similarly makes refundRemaining() negative with no guard.

**Fix:** In TransactionService::update(), after saving, if $txn->isRefunded() recompute refund_mco_impact with the same formula as refund() — round(profit_mco + min(1, refunded_amount/total_amount) * actualMco() + merchantFee(), 2) (fraction 1.0 when fully refunded) — and reject total_amount values below refunded_amount.

### 13. Edit form payment_status options don't match the DB enum - saving any edit silently flips 'paid'/'partial'/'credited' bookings back to 'pending', and two offered values are invalid
`app/Views/transactions/edit.php:291`

**Scenario:** Agent edits an approved booking whose payment_status='paid' (e.g. to fix a phone number - post-approval edits are policy-allowed). The edit form's dropdown only offers ['pending','captured','refunded','failed']; 'paid' matches nothing so the browser preselects the first option, 'pending'. On save, TransactionService::update() passes it straight through, and the paid booking is silently reset to payment-pending, corrupting payment-state reporting. If the user instead picks 'captured' or 'failed', MySQL rejects it (strict mode: 'Data truncated for column payment_status', the whole edit 500s) since the column is ENUM('pending','paid','partial','refunded','credited'). Bookings marked 'partial' by the refund flow flip to 'pending' the same way.

### 14. Edit page calls undefined calcMco(), aborting the init script - passenger list never renders and the submit handler never attaches
`app/Views/transactions/edit.php:497`

**Scenario:** Any user opens /transactions/{id}/edit. The init IIFE throws 'ReferenceError: calcMco is not defined' at line 497 (the function was deleted - line 414 comment says 'auto-calc removed' - but the call remained). The uncaught exception aborts the rest of the script block: paxMgr._render()/add() (line 498) never runs, so the Passengers section renders permanently empty - existing passengers are invisible and cannot be edited - and the 'submit' listener (lines 501-510) is never registered, so paxMgr._sync() never fills the passengers_json hidden input. The listener is also unrunnable anyway: it references a #type_specific_data_json element that does not exist in this form (only csrf_token, field_type, passengers_json hidden inputs exist).

**Fix:** Delete the `calcMco();` call at edit.php:497. Also fix the submit handler: either add a `<input type="hidden" id="type_specific_data_json" name="type_specific_data_json">` to the form or guard the getElementById at line 509, and consider calling paxMgr._sync() from the per-field onchange handlers so late edits are captured.

### 15. Post-midnight clock-in on an overnight shift computes scheduled end a day late; forgotten clock-out always lands in the no-pay branch
`cron/auto_clockout.php:59`

**Scenario:** Agent on the 18:00-09:00 shift arrives late and clocks in at 00:30 on Aug 14 (attendance_sessions.date is set at clock-in, so date='2026-08-14'). getScheduledEndTimestamp builds start=Aug 14 18:00 / end=Aug 14 09:00, sees end<=start and adds 24h, yielding Aug 15 09:00 — a full day after the true scheduled end (Aug 14 09:00). If the agent forgets to clock out: the cron skips the session until the cutoff Aug 15 10:00 (it stays 'active' on live boards for ~34 hours), and when it finally fires, staleHours = now - clock_in >= 33.5 > 24, so it takes the >24h branch — status auto_closed with clock_out left NULL and zero work minutes computed. Had the date been correct it would have closed at Aug 14 10:00 with ~8.5 payable hours. Payroll for that shift is silently zero pending manual admin reconstruction.

**Fix:** In getScheduledEndTimestamp, anchor the schedule to the clock-in timestamp instead of session->date: compute endTs from the clock-in's calendar date plus scheduled_end and add 86400 only if endTs <= clockInTs, so a post-midnight clock-in resolves to the same-morning scheduled end.

### 16. E-ticket reply cron marks tickets acknowledged from ANY email sent by the customer's address
`cron/check_email_replies.php:190`

**Scenario:** Customer jane@x.com has a sent e-ticket. She later sends any unrelated email to the mailbox — an out-of-office auto-reply, a new fare inquiry, or a reply to a Customer Emails module thread (both crons poll the same inbox and neither respects the other's match). No PNR is in the subject, so the primary match fails, and the fallback matches her latest sent e-ticket by sender address alone. ETicketService::processEmailReplyAcknowledgment (app/Services/ETicketService.php:368-375) flips the ticket to STATUS_ACKNOWLEDGED with ack_type='email_reply' and acknowledged_at=now. The acknowledgment record — the business's evidence that the customer confirmed the itinerary/charge — is fabricated from an email that confirmed nothing, and a real later dispute finds a false ack in the audit trail.

**Fix:** In the fallback, only acknowledge when the inbound mail is a genuine reply to the outbound e-ticket email — match In-Reply-To/References against the stored outbound Message-ID (or require the PNR in the subject) and skip messages with Auto-Submitted/auto-reply headers, storing anything else as a reply record without flipping status.

## MEDIUM (13)

### 17. Acceptance resend reports success and marks the record EMAIL_RESENT even when the SMTP send failed
`app/Controllers/AcceptanceController.php:479`

**Scenario:** Agent clicks 'Resend Link' on a pending acceptance while Gmail SMTP is rejecting (rate limit, rotated app password, WAF/network blip). AcceptanceEmailService::send() returns ['success' => false, 'error' => ...], but the controller ignores $result['success'], unconditionally sets email_status = EMAIL_RESENT (line 482) and returns {'success': true}. The view's doResend() (app/Views/acceptance/view.php:1253) sees success and reloads, showing the RESENT badge. The customer never receives the payment-authorization link, the agent believes it was delivered and waits, the 12-hour expiry (already reset at line 475 before the send) lapses, and the sale stalls — with the audit trail falsely recording a successful resend.

**Fix:** In AcceptanceController::resend(), branch on $result['success']: only set EMAIL_RESENT and return success:true when the send succeeded, otherwise return jsonResponse(['success' => false, 'error' => $result['error'] ?? 'Email delivery failed — copy the link and send manually.'], 502) so doResend() alerts the agent, mirroring the create path at lines 240-244.

### 18. Manager/supervisor can read and CSV-export any agent's attendance, not just their team
`app/Controllers/AttendanceController.php:420`

**Scenario:** adminMonthly()/exportMonthlyCsv()/adminBoardData()/adminPanel() all scope managers and supervisors to their team (e.g. lines 452-457, 480-484). But adminHistory() (line 420) and the daily exportCsv() (line 531) call AttendanceService::getHistoricalData($date, $agentId) with no team scoping (service line 782-793 applies no team filter). A supervisor requests /attendance/admin/history?date=2026-08-13 or /attendance/admin/export?date=... and receives clock-in/out, work minutes and lateness for EVERY agent in the company, or targets any single agent via ?agent_id=X.

**Fix:** In adminHistory() and exportCsv(), replicate the scoping block used by adminMonthly(): for ROLE_MANAGER/ROLE_SUPERVISOR compute getTeamAgentIds() (defaulting to [-1] when empty), reject or ignore an agent_id outside that set, and extend getHistoricalData() to accept an optional array of allowed agent IDs (whereIn) applied to the query. Also filter the $agents dropdown list in adminHistory() to the team.

### 19. TV liveboard converts the query window to UTC while transactions are stored in IST, shifting the leaderboard window by 5.5 hours
`app/Controllers/LiveBoardController.php:112`

**Scenario:** Transactions/acceptances are written by Eloquent using PHP time (public/index.php:46 sets Asia/Kolkata), so created_at/approved_at strings in MySQL are IST wall-clock. feed() builds the 18:00→17:59:59 IST shift window but then converts both bounds to UTC (lines 112-113) before whereBetween, so the SQL window is effectively 12:30 IST → 12:29:59 IST next day. At 20:00 on Aug 14 the leaderboard, event feed, and total_profit include every approved transaction from 12:30-18:00 that afternoon — sales belonging to the PREVIOUS business day — double-counting them on the TV against the previous day and inflating tonight's per-agent profit totals. Line 177 compounds it: `Carbon::parse($t->updated_at, 'UTC')->setTimezone('Asia/Kolkata')` re-interprets an IST timestamp as UTC and adds 5:30, so a 20:00 booking displays as 01:30. No other module (Dashboard, Performance, TransactionController) does this conversion, so the TV numbers never tie out with the dashboard for the same shift.

**Fix:** Delete the UTC conversion in LiveBoardController::feed() — use $shiftStart/$shiftEnd directly in the whereBetween calls (matching DashboardController/ShiftService) and change line 177 to parse updated_at as Asia/Kolkata (drop the 'UTC' argument and the setTimezone call).

### 20. Admin transactions CSV exports gross Profit/MCO with no refund adjustment or refund columns - finance sheets overstate profit on refunded bookings
`app/Controllers/TransactionController.php:921`

**Scenario:** Admin exports /transactions/export for a month containing a fully refunded booking (profit_mco 200, refund_mco_impact 500, real Net MCO -300 shown in-app). The CSV row shows Profit/MCO = 200.00 and carries no refund_status, refunded_amount, or Net MCO column at all, so anyone reconciling from the export books +200 where the company actually lost 300. The codebase already acknowledges this exact failure mode: PerformanceController's export was amended because 'refunds were invisible to anyone analysing the CSV' (PerformanceController.php:248-251), but the transactions export was never given the same fix.

**Fix:** Add 'Refund Status', 'Refund MCO Impact', and 'Net MCO (After Refunds)' columns to the headers at TransactionController.php:904-908 and emit `$t->refund_status`, `$t->refund_mco_impact`, and `$t->netMco()` in the row mapper, mirroring PerformanceController.php:251-273.

### 21. Proof-of-sale files are physically deleted before the edit is validated or written — a failed edit destroys evidence the DB still references
`app/Controllers/TransactionController.php:469`

**Scenario:** Admin opens the edit form on a transaction, ticks an old proof for removal AND attaches a replacement PDF that trips validation (16MB, or wrong MIME). The removal block runs first and unlinks the old file from storage/proofs (lines 468-471); then saveProofFiles() rejects the new file and the request aborts with a redirect at lines 492-494 — service->update() never runs. The DB row still lists the deleted file, but it is gone from disk: viewProof now returns 'File not found on server' and the proof-of-sale evidence for a real card charge is permanently destroyed while the admin only saw a validation error. The same ordering bites if service->update() throws (e.g. the transaction was voided by another admin after the form was opened — isEditable() is false and the service throws at TransactionService.php:127-131 after the unlink already happened).

**Fix:** Defer physical deletion until after service->update() succeeds: in the removal loop only collect $toDelete[] = $abs and set $kept/$proofChanged; run the @unlink loop after the try block's successful update (before the success redirect). That keeps deletes best-effort but guarantees the DB row is rewritten first, so validation failures or RuntimeExceptions leave both disk and DB untouched.

### 22. Signature save reports success even when the file write fails — file_put_contents return ignored
`app/Services/AcceptanceService.php:359`

**Scenario:** Customer signs; disk quota exhausted or storage/acceptance/signatures loses write permission on the shared host. file_put_contents() returns false, but saveSignature() ignores it and returns the filename anyway. Both public handlers then store that filename as digital_signature and approve — even public/auth/index.php, which dutifully checks `if (!$signaturePath)` at line 90, is defeated because the function lies. Result: an APPROVED acceptance whose signature file does not exist on disk; downloadEvidence later 404s, and the signed-consent evidence needed to fight a 'friendly fraud' chargeback was never captured. Same unchecked write at line 346 for the consent-based e-sign JSON.

**Fix:** Capture the return: `$written = file_put_contents($dir . $filename, $data);` and `if ($written === false || $written !== strlen($data) || !file_exists($dir . $filename)) { return null; }` at both call sites (lines 346 and 359), mirroring the !$moved || !file_exists($dest) pattern in saveEvidenceFile().

### 23. Monthly attendance report counts every future date and scheduled day off as 'absent' in the payroll CSV
`app/Services/AttendanceService.php:865`

**Scenario:** getMonthlyReport computes days_absent = daysInMonth - daysPresent over all calendar dates with no reference to shift_schedules or to today. Exporting month=2026-08 on Aug 14 for an agent who worked all 12 of their scheduled days prints a per-agent summary row of roughly '12 days present / 19 absent' (AttendanceController.php:516), because Aug 15-31 haven't happened yet and the agent's weekly offs are also counted as absences. Anyone using the export for payroll deductions or absence discipline gets a wildly inflated absence figure for the in-progress month, and even for a closed month the figure conflates unscheduled days with real no-shows.

**Fix:** Clamp the date range to min(last day of month, today) when the requested month is current/future, and compute days_absent against scheduled days only: fetch shift_schedules rows for the agent in the period and count as absent only dates that have a published shift, are <= today, and have no session. Emit 'Not scheduled' / 'Upcoming' instead of 'Absent' in the CSV for the other cases.

### 24. ReminderService::dispatchDue has no atomic claim and is invoked concurrently by every user's 30-second bell poll — due reminders dispatch multiple times
`app/Services/ReminderService.php:188`

**Scenario:** A booking reminder's remind_at passes at 14:00:00. Every logged-in user's browser polls GET /api/notifications every 30s (app/Views/partials/notification_bell.php:145), and NotificationController::feed line 33 calls dispatchDue() on each poll, in addition to the cron (cron/booking_reminders_dispatch.php:44). Two users' polls arriving within the dispatch window (resolveRecipients runs several queries plus one INSERT per recipient before the reminder is finally marked FIRED at line 152-155) both read the reminder as STATUS_SCHEDULED and both create the full notification fan-out. Every recipient — agent, manager chain, and all admins — receives 2x (or Nx with more overlapping polls) copies of the same reminder, and duplicate activity_log rows are written. If a dispatch crashes after inserting some notifications but before line 152, the reminder stays SCHEDULED and the whole fan-out repeats on the next poll.

**Fix:** In ReminderService::dispatch(), atomically claim the reminder before fanning out — run `UPDATE booking_reminders SET status='fired', fired_at=NOW() WHERE id=? AND status='scheduled'` and only create notifications if exactly 1 row was affected (optionally wrap fan-out in a transaction and add a unique index on notifications(reminder_id, user_id) as a backstop).

### 25. Concurrent void by two admins creates two auto-reversal transactions — the already-voided check runs outside the transaction with no row lock
`app/Services/TransactionService.php:331`

**Scenario:** Two admins triaging the same disputed booking both have /transactions/57 open and click Void within the same second (different PHP sessions, so the requests run in parallel — session-file locking only serializes same-user requests). Both pass `$txn->isVoided()` at line 331 because neither UPDATE has committed, both enter the DB transaction, and each creates its own reversal Transaction (line 346) with negative total_amount/cost_amount. The ledger now holds two reversal records and four void RecordNotes for one void; exports and the transaction list show a phantom second reversal that has to be explained to the merchant/accountant, and there is no code path to delete it (voids are immutable by design).

**Fix:** Move the voided check inside the transaction using SELECT ... FOR UPDATE (same pattern as createSession at AttendanceService.php:1038), or guard with an UPDATE ... WHERE status != 'voided' and throw if 0 rows affected; optionally add a UNIQUE index on void_of_transaction_id as a backstop.

### 26. auto_clockout cron overwrites a concurrent real clock-out with scheduled_end, erasing worked overtime from payroll
`cron/auto_clockout.php:114`

**Scenario:** An overnight agent scheduled to end at 09:00 keeps working past 10:00 (>1h grace). The 15-minute cron run starting 10:00 loads her session into $activeSessions (line 74). At 10:00:20 she clocks out via the web app — AttendanceService::clockOut writes her true clock_out=10:00:20 and total_work_mins including the extra hour, status=completed. Seconds later the cron loop reaches her stale model and executes `$session->update([...])` (lines 114-120) which is an unconditional `UPDATE ... WHERE id=?`: her clock_out is rewritten to 09:00:00, total_work_mins is recomputed from scheduled_end (line 111) dropping the overtime, status flips to auto_closed and resolution_required=1. Payroll pays her an hour short unless an admin manually spots and resolves the flag. The cron also has no overlap lock, so a hung IMAP-slow box running two instances double-inserts the activity_log rows at line 146.

**Fix:** Replace the stale-model update with a conditional query-builder write — Capsule::table('attendance_sessions')->where('id', $session->id)->where('status', AttendanceSession::STATUS_ACTIVE)->update([...]) — skipping the break-close/activity-log writes when 0 rows are affected, and wrap the whole run in MySQL GET_LOCK('auto_clockout', 0) to prevent overlapping instances.

### 27. IMAP reply dedup relies on check-then-insert; the migration comment claims a UNIQUE index but only a plain INDEX was added — overlapping cron runs store duplicate replies
`cron/check_email_replies.php:166`

**Scenario:** Both IMAP crons run every 5 minutes and scan 12 hours of inbox (line 113-114); on a busy mailbox over shared-host IMAP a run easily exceeds 5 minutes, so two instances overlap (no lockfile/GET_LOCK anywhere in the script). Both fetch the same new customer reply, both evaluate `ETicketReply::messageIdExists($messageId)` (line 166) before either has inserted, and both call processEmailReplyAcknowledgment — ETicketService.php lines 358-384 then insert TWO ETicketReply rows and TWO 'Customer replied via email' RecordNotes for one email. The same pattern hits cron/customer_email_inbound.php line 125 → CustomerEmailService::recordInbound (lines 356-372), duplicating the customer's message inside the thread agents reply from. The DB does not stop it: despite the migration header saying 'Adds a unique message_id column', 2026_05_29_add_message_id_to_eticket_replies.sql line 16 creates only `ADD INDEX idx_reply_message_id`, and customer_email_messages.message_id (2026_06_10_customer_emails.sql line 85) is likewise a plain INDEX.

**Fix:** Add UNIQUE indexes on eticket_replies.message_id(191) and customer_email_messages.message_id and catch the duplicate-key exception on insert; additionally take an overlap guard (GET_LOCK or an flock'd pidfile) at the top of both cron scripts.

### 28. shift_gap_alert inserts user_id=0 into activity_log, violating its FK — cron crashes at the first gap and no alert is ever logged
`cron/shift_gap_alert.php:67`

**Scenario:** The nightly 8 PM run finds one agent without a shift for tomorrow. It inserts into activity_log with 'user_id' => 0, but activity_log.user_id has FOREIGN KEY fk_log_user_id REFERENCES users(id) (database/schema.sql:73) and no user with id 0 exists, so MySQL rejects the insert with a 1452 FK violation. The Illuminate QueryException is uncaught, the script dies on the very first gap, none of the gaps are recorded for admin visibility, and any gaps for subsequent agents are never even echoed. The one code path this cron exists for (a gap found) is exactly the path that always crashes; the 'admin can fill gaps' logging has never worked.

**Fix:** Change 'user_id' => 0 to 'user_id' => null (matching ReminderService.php:160), and optionally wrap each insert in try/catch so one failure cannot abort logging for remaining agents.

### 29. schema.sql still defines profit_mco as a GENERATED column while the app writes it explicitly - any environment built from schema.sql cannot create transactions
`database/schema.sql:139`

**Scenario:** A staging/dev/DR database is provisioned from database/schema.sql (the canonical full-schema file). Its transactions table has `profit_mco DECIMAL(10,2) GENERATED ALWAYS AS (total_amount - cost_amount) STORED`. The first transaction submit runs TransactionService::create() which includes 'profit_mco' in the INSERT, and MySQL rejects it with error 3105 ('The value specified for generated column ... is not allowed') - transaction recording is completely broken on that environment. Production evidently runs the divergent migrations/transactions.sql definition ('manual entry', line 57), and the two files plus the Transaction.php docblock (lines 17/34, which still claim the generated column) are mutually contradictory - a live schema-drift trap given a staging environment is currently being stood up.

**Fix:** Update database/schema.sql:139 to match the live definition (`profit_mco DECIMAL(10,2) NOT NULL DEFAULT 0.00`), and correct the Transaction.php docblock that still describes it as a generated column, so schema.sql can safely provision staging/DR.

## LOW (10)

### 30. LIKE search terms are not wildcard-escaped, so % and _ act as wildcards in error-console/activity-log/user searches
`app/Controllers/AdminController.php:237`

**Scenario:** An admin/manager searches the error console for a string that legitimately contains an underscore or percent, e.g. searching for the message fragment `user_id` or `100%`. The term is interpolated as `'%' . $filters['search'] . '%'` into a `like` clause with no escaping of the LIKE metacharacters _ and %, so `_` matches any single character and `%` matches any run - returning rows that do not contain the literal text and hiding the intended match. A term of many `%` characters (e.g. `%%%%%%`) forces a broad scan over error_log. Same unescaped-LIKE pattern in AdminController activity-log search (line 165) and UserController user search (line 310), and in TransactionService (517-520) / ETicketService (426-428) / InvoiceService (401-405).

**Fix:** Escape the term before wrapping: addcslashes($term, '%_\\') (ideally via a small shared helper used by all LIKE search sites, including TransactionService/ETicketService/InvoiceService).

### 31. E-ticket acknowledgment notice — documented as 'the legal receipt' — is fire-and-forget; SMTP failure leaves no trace
`app/Controllers/ETicketController.php:396`

**Scenario:** Customer clicks 'I have read' on their public e-ticket during an SMTP outage. processAcknowledgment() stores the ack, then sendAcknowledgmentNotice() fails and returns ['success' => false] — but the return value is discarded, nothing is recorded on the e-ticket, no note/notification is raised, and the customer is redirected to the confirmed page. ETicketEmailService's own docblock (line 92) says this email 'constitutes the legal receipt of acknowledgment': the firm never receives it, believes the customer hasn't acknowledged, and the only evidence of the failure is a line in a storage log nobody reads. Contrast the same controller's contact-message path (lines 466-473) which at least wraps and logs, and the createForm send path (lines 193-198) which calls markEmailFailed().

**Fix:** Capture the result at line 396: `$result = $this->emailService->sendAcknowledgmentNotice($eticket->fresh()); if (empty($result['success'])) { ... }` — record the failure on the e-ticket (e.g. a service method akin to markEmailFailed(), or an admin notification/note) so the missed receipt is visible and retryable; optionally add a resend button or cron retry for ACK_NOTICE_ERROR entries.

### 32. Every void inflates the Performance scoreboard's 'Voided' count by 2 - the auto-reversal row is counted alongside the original
`app/Controllers/PerformanceController.php:408`

**Scenario:** Admin voids one transaction for agent A. TransactionService::void() creates a second row (the negative-amount reversal) with the same agent_id and status='voided'. computeScores' aggregate `SUM(status = 'voided') AS voided` counts both rows, so the agent's scoreboard and the exported CSV show Voided = 2 for a single voided booking - doubling a metric used to judge agent quality. An agent with 3 voids shows 6.

**Fix:** Change the aggregate to SUM(status = 'voided' AND void_of_transaction_id IS NULL) AS voided (and consider excluding reversal rows from the bookings SUM's denominator logic for consistency).

### 33. addNote lets manager/supervisor/CSA attach notes to any transaction across teams
`app/Controllers/TransactionController.php:852`

**Scenario:** POST /transactions/{id}/note only rejects when the actor is ROLE_AGENT editing someone else's booking (line 852 `if ($userRole === User::ROLE_AGENT && $txn->agent_id !== $userId)`). A manager or supervisor can POST a note to any transaction id belonging to another team, writing into the audit trail (RecordNote) of records they otherwise cannot manage. No team scoping is applied for manager/supervisor/CSA here, unlike view()/edit().

**Fix:** Mirror view()'s scoping in addNote: managers restricted to getManagerTeamIds()+self, supervisors to getTeamAgentIds(), and decide explicitly whether CSA should be limited, returning 403 otherwise.

### 34. clockOut ignores the auto-endBreak result, so a failed break-close silently inflates paid work minutes
`app/Services/AttendanceService.php:236`

**Scenario:** Agent on a lunch break clicks Clock Out (the UI allows it — clockOut auto-ends the break). endBreak() hits a transient DB error updating the break row and returns ['success' => false] — clockOut never checks this, proceeds, and computes total_break_mins with `whereNotNull('break_end')` (lines 251-253), which excludes the still-open 45-minute lunch break. net_work_mins = gross minus 0 break minutes, the session is marked COMPLETED, and the agent sees 'Clocked out. Net work: N minutes.' with N overstated by the entire break. Payroll built on total_work_mins overpays, and the orphaned open break row is never closed by anything else. This is also an extra call site for known bug #4's symptom (zero recorded break minutes) that persists even after the duration-calculation fix.

**Fix:** Check endBreak's return in clockOut and abort (return the failure) if success is false — or close the break row directly inside clockOut's try block the way adminClockOut does (lines 613-618), so break-close and totals share one error path.

### 35. bustStateCache after admin clock-in/out is a cross-session no-op — the agent stays locked out of the whole CRM for up to 60 seconds, contrary to the 'B4 FIX' comment
`app/Services/AttendanceService.php:588`

**Scenario:** An agent missed the grace window, so an admin uses admin clock-in. adminClockIn calls `$this->bustStateCache($agentId)` (line 588) — but this runs in the ADMIN's PHP process and unsets keys in the ADMIN's $_SESSION; the agent's own session file still caches state NOT_CLOCKED_IN/CLOCKED_OUT for up to 60s, and getCurrentState serves those two states straight from cache with zero DB validation (lines 55-76 — only active states are re-verified at lines 61-67). AttendanceGateMiddleware (lines 48-58) therefore keeps 302-redirecting the agent to /clock-in on every page, while pressing the Clock In button fails with 'You already have an active clock-in session' (attemptClockIn line 133 hits the DB directly) — a lockout loop, right after a late start, until the 60s TTL expires. adminClockOut (line 635) and adminForceEndBreak (line 695) have the identical dead bust.

**Fix:** The bust must reach the agent's storage: either stop caching the two terminal states (always hit the DB for NOT_CLOCKED_IN/CLOCKED_OUT, as the active states already re-verify), or add a shared invalidation signal (e.g. a users.att_state_version column bumped by admin actions and compared against the cached version).

### 36. startBreak is check-then-insert with no lock (unlike clock-in) — simultaneous break starts from two devices create two open breaks whose minutes are both deducted from paid work time
`app/Services/AttendanceService.php:351`

**Scenario:** An agent is logged in on the office desktop and her phone (two distinct PHP sessions, so the requests are not serialized by the session lock). She taps 'Lunch break' on both within the same moment. Both requests read state=clocked_in (lines 322-330) and both pass the lunch-count check (lines 335-340) before either inserts, so two AttendanceBreak rows are created with break_end NULL. endBreak later closes only the one getActiveBreak returns; the second stays open until clockOut's auto-endBreak (line 236) closes it too, giving it a duration spanning start-of-break to clock-out. clockOut then sums BOTH rows' duration_mins (lines 251-253) and subtracts the total from gross minutes (line 259) — e.g. a 30-minute lunch becomes 30 + 210 = 240 break minutes, and total_work_mins (what payroll reads) is under-counted by hours.

**Fix:** Wrap startBreak's check+insert in a DB transaction with SELECT ... FOR UPDATE on the attendance_sessions row (same pattern as createSession), and/or reject insert if an open break (break_end IS NULL) already exists for the session under the lock.

### 37. Customer-email inbound fallback threads unrelated mail (e.g. e-ticket acknowledgments) onto the sender's newest open thread
`app/Services/CustomerEmailService.php:393`

**Scenario:** Customer jane@x.com has an open Customer Emails thread about a refund. She replies 'Confirmed, thanks' to a separate e-ticket email. That reply's In-Reply-To references the e-ticket's Message-ID, which is not in customer_email_messages, so the reference match fails and the fallback at line 393 attaches the message to her open refund thread by sender address alone, flipping it to AWAITING_AGENT (recordInbound, line 369). The refund thread now shows a confusing out-of-context 'Confirmed, thanks' as the customer's latest word, and the agent is prompted to reply to a message that belongs to a different conversation — while the same email is also (correctly or incorrectly) consumed by the e-ticket cron, double-recording it.

**Fix:** Skip the address-only fallback when the email has reference IDs that matched nothing (it is provably a reply to something else); optionally also require a subject match against the thread before fallback, and log unmatched mail instead of guessing.

### 38. Card-authorization receipt stamps the IST signing time with a 'UTC' label, so the legal timestamp is off by 5.5 hours
`app/Views/acceptance/receipt.php:414`

**Scenario:** approved_at is written as Carbon::now() in Asia/Kolkata time (AcceptanceService.php:264, app timezone set in public/index.php:46), but the customer-facing receipt renders 'Signed: Aug 14, 2026 at 8:15:00 PM UTC' (line 414) and repeats the claim in the audit block ('Signed At (UTC)', lines 807 and 855). The actual UTC signing moment was 14:45. This receipt is the evidence document used in chargeback disputes: its asserted UTC signing time will contradict the card processor's genuine UTC authorization logs by 5.5 hours, which weakens exactly the document produced to prove the customer authorized the charge — and on charges near midnight UTC the receipt shows the wrong calendar date entirely.

**Fix:** Either convert before formatting ($approvedAt->copy()->setTimezone('UTC')) everywhere the UTC label appears (414, 808, 855), or relabel as IST; keep the label and the value consistent since the receipt is chargeback evidence.

### 39. Performance hold window is calendar-midnight aligned while every performance window is shift-aligned, splitting the 31 Jul and 9 Aug overnight shifts
`scripts/performance_hold.php:116`

**Scenario:** The hold is stored as from='2026-08-01 00:00:00' / until='2026-08-09 23:59:59' (lines 116-117), but the business day runs 18:00→09:00 and Performance 'daily' explicitly queries businessDayBounds (PerformanceController.php:338). Two concrete edge failures for non-admin viewers: (1) bookings made during the July 31 shift after midnight (Aug 1 00:00-09:00) are inside the held window and get hidden, so agents lose credit for July-shift sales that were never part of the merchant's August hold — a July monthly view silently undercounts that night's takings; (2) bookings from the Aug 9 shift made after midnight (Aug 10 00:00-09:00) fall outside the window and are counted, so the 'hidden' Aug 9 business day shows half a shift of held revenue on supervisor/manager scoreboards. When the hold is lifted per the runbook, admins comparing before/after will find the non-admin numbers never matched any whole business day.

**Fix:** If the hold should be shift-aligned, compute bounds from the business-day rollover hour when enabling: hold_from = <from-date> + rolloverHour (e.g. 2026-08-01 18:00:00) and hold_until = <until-date> +1 day + rolloverHour − 1s (e.g. 2026-08-10 17:59:59), reusing ShiftService::businessDayStartHour() in scripts/performance_hold.php instead of hardcoding 00:00:00/23:59:59. Confirm with the business which convention the merchant hold actually uses before changing it.

## REJECTED (false alarms — verified NOT bugs)

- `app/Controllers/AcceptanceController.php:600` — Public acceptance submit approves the authorization even when the signature/evidence files silently fail to save, and never enforces required documents. The missing checks in AcceptanceController::publicSubmit are real in the code, but that route is shadowed dead code: /auth resolves to the physical directory public/auth/ (RewriteCond !-d in public/.htaccess skips the Slim rewrite), verified live — GET https://crm.base-fare.com/auth returns mod_dir's 301 to /auth/ and the page shows "Invalid Authorization Link", a string unique to the standalone public/auth/index.php, which DOES check saveSignature/saveEvidenceFile nulls and enforces req_passport/req_cc_front (lines 90-122). No customer submission can reach the unguarded controller under the current deployment, so the claimed evidence-loss scenario cannot occur. Residual risk is latent only: a routing change (e.g. planned VPS migration with nginx try_files to index.php) would silently make the unguarded route live.
- `scripts/rotate_encryption_keys.php:210` — Live key rotation can silently overwrite a concurrent card edit with stale re-encrypted data. The race window (batch SELECT at rotate_encryption_keys.php:159-164, unguarded UPDATE at 209-211) exists as described, but the failing scenario does not: no code path in the app updates an existing row's *_enc column in place. Card corrections go through TransactionService::update (lines 210-214), which DELETEs the payment_cards rows and re-INSERTs under new auto-increment ids, so the rotation's stale UPDATE WHERE id = N matches zero rows and nothing reverts; acceptance_requests card ciphertext is write-once at create (AcceptanceService.php:87-111) with no edit path, and all other updates touch disjoint (non-enc) columns. The concurrent-overwrite outcome — customer charged on the wrong card — cannot be traced through real code.
