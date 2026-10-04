<?php

namespace App\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * FareTermsService — refundability terms driven by fare type.
 *
 * Why this exists: every acceptance and e-ticket used to say NON-REFUNDABLE,
 * hardcoded in six places, whatever the customer actually bought. A refundable
 * Business ticket still made the customer tick "I understand this purchase is
 * NON-REFUNDABLE" — terms that don't match the ticket are weak evidence in a
 * chargeback, not strong.
 *
 * Now the agent picks a fare type (pre-selected from the cabin). Each type is a
 * template with fill-in blanks; one choice drives the Fare Rules, Endorsements,
 * the refund clause of the policy, the customer's checkbox wording and the
 * e-ticket acknowledgement together. Agents fill blanks only — the wording is
 * the admin's (/admin/fare-terms), stored in system_config over the defaults
 * below.
 *
 * Blank syntax, usable in any template part:
 *   {{cancel_fee}}                       text/number input (type from the key suffix)
 *   {{name_change:Not Allowed|Allowed}}  dropdown; later {{name_change}} reuses it
 *   {{currency}}                         filled from the form's currency, not a blank
 *
 * public/assets/js/fare-terms.js renders the same templates in the browser for
 * the live preview. The server re-renders on save, so the stored text never
 * depends on what the browser sent (unless a manager/admin unlocked manual edit).
 */
class FareTermsService
{
    const NON_REFUNDABLE     = 'non_refundable';
    const REFUNDABLE_PENALTY = 'refundable_penalty';
    const FULLY_REFUNDABLE   = 'fully_refundable';
    const CUSTOM             = 'custom';

    const TYPES = [self::NON_REFUNDABLE, self::REFUNDABLE_PENALTY, self::FULLY_REFUNDABLE, self::CUSTOM];

    /** Template parts an admin can edit. */
    const PARTS = ['fare_rules', 'endorsements', 'refund_clause', 'checkbox', 'eticket_ack'];

    const CABINS = ['Economy', 'Premium Economy', 'Business', 'First'];

    /** Agreed 29 Sep 2026: Economy non-refundable, everything above refundable with penalty. */
    const DEFAULT_CABIN_MAP = [
        'Economy'         => self::NON_REFUNDABLE,
        'Premium Economy' => self::REFUNDABLE_PENALTY,
        'Business'        => self::REFUNDABLE_PENALTY,
        'First'           => self::REFUNDABLE_PENALTY,
    ];

    const CONFIG_TEMPLATE_PREFIX = 'fare_terms.template.';
    const CONFIG_CABIN_MAP       = 'fare_terms.cabin_map';

    /** Tokens filled by the system, never shown as blanks. */
    const RESERVED = ['currency', 'refund_clause'];

    const PLACEHOLDER_RE = '/\{\{\s*([a-z0-9_]+)\s*(?::([^}]*))?\}\}/i';

    // Said once in every refund clause: absolute "100% non-refundable" wording
    // contradicts the US DOT refund rule, which hurts us in a dispute.
    const DOT_LINE = 'This does not limit any refund you are owed by law if the airline cancels or significantly changes your flight.';

    const DEFAULT_TEMPLATES = [
        self::NON_REFUNDABLE => [
            'label'         => 'Non-refundable',
            'hint'          => 'Standard economy fares. No refund once issued.',
            'fare_rules'    => "Exchange : Permitted with Fee\nCancellation : Non Refundable Ticket\nName Change : Not Allowed",
            'endorsements'  => 'NON END/NON REF/NON RRT',
            'refund_clause' => 'REFUNDS & CHANGES: This ticket is NON-REFUNDABLE and NON-TRANSFERABLE once issued. Date changes, where the airline permits them, are subject to airline penalties plus any fare difference. ' . self::DOT_LINE,
            'checkbox'      => 'I understand this purchase is NON-REFUNDABLE and NON-TRANSFERABLE once issued.',
            'eticket_ack'   => 'non-refundable and non-transferable',
        ],
        self::REFUNDABLE_PENALTY => [
            'label'         => 'Refundable with penalty',
            'hint'          => 'Refund allowed before departure, less the airline penalty.',
            'fare_rules'    => "Exchange : Permitted — {{currency}} {{change_fee}} per passenger plus fare difference\nCancellation : Refundable less {{currency}} {{cancel_fee}} per passenger\nName Change : Not Allowed",
            'endorsements'  => 'NON END/NON RRT/PENALTY APPLIES',
            'refund_clause' => 'REFUNDS & CHANGES: This ticket is REFUNDABLE WITH A PENALTY. If cancelled before departure, the fare is refunded less a cancellation penalty of {{currency}} {{cancel_fee}} per passenger. Changes are permitted for {{currency}} {{change_fee}} per passenger plus any fare difference. Refunds go back to the original form of payment once the airline releases the funds. Our service fee is non-refundable and the ticket is non-transferable. ' . self::DOT_LINE,
            'checkbox'      => 'I understand this ticket is REFUNDABLE WITH A PENALTY of {{currency}} {{cancel_fee}} per passenger if cancelled before departure, that the service fee is non-refundable, and that the ticket is NON-TRANSFERABLE.',
            'eticket_ack'   => 'refundable only as per the fare rules above, less a penalty of {{currency}} {{cancel_fee}} per passenger, and non-transferable',
        ],
        self::FULLY_REFUNDABLE => [
            'label'         => 'Fully refundable',
            'hint'          => 'Full refund before departure (service fee excluded).',
            'fare_rules'    => "Exchange : Permitted without penalty, fare difference applies\nCancellation : Fully Refundable before departure\nName Change : Not Allowed",
            'endorsements'  => 'NON END/NON RRT',
            'refund_clause' => 'REFUNDS & CHANGES: This ticket is FULLY REFUNDABLE if cancelled before departure. Changes are permitted without an airline penalty; any fare difference applies. Refunds go back to the original form of payment once the airline releases the funds. Our service fee is non-refundable and the ticket is non-transferable. ' . self::DOT_LINE,
            'checkbox'      => 'I understand this ticket is FULLY REFUNDABLE if cancelled before departure, excluding the non-refundable service fee, and that it is NON-TRANSFERABLE.',
            'eticket_ack'   => 'refundable before departure as per the fare rules above (the service fee is non-refundable) and non-transferable',
        ],
        self::CUSTOM => [
            'label'         => 'Custom',
            'hint'          => 'Deadline-based or credit-only fares. Fill in the blanks.',
            'fare_rules'    => "Exchange : {{change_policy:Not permitted|Permitted with airline fee plus fare difference|Permitted without fee, fare difference applies}}\nCancellation : {{refund_outcome:REFUNDABLE|CONVERTIBLE TO TRAVEL CREDIT}} less {{currency}} {{cancel_fee}} per passenger if cancelled {{cancel_deadline_hours}}+ hours before departure; non-refundable after that\nName Change : {{name_change:Not Allowed|Allowed with airline fee}}",
            'endorsements'  => 'NON END/NON RRT/PENALTY APPLIES',
            'refund_clause' => 'REFUNDS & CHANGES: If cancelled at least {{cancel_deadline_hours}} hours before departure, this ticket is {{refund_outcome}} less a penalty of {{currency}} {{cancel_fee}} per passenger; cancellations after that are non-refundable. Exchanges: {{change_policy}}. Name changes: {{name_change}}. Our service fee is non-refundable and the ticket is non-transferable. ' . self::DOT_LINE,
            'checkbox'      => 'I understand that if cancelled at least {{cancel_deadline_hours}} hours before departure this ticket is {{refund_outcome}} less {{currency}} {{cancel_fee}} per passenger, that it is non-refundable after that, that the service fee is non-refundable, and that the ticket is NON-TRANSFERABLE.',
            'eticket_ack'   => '{{refund_outcome}} only as per the fare rules above and non-transferable',
        ],
    ];

    /** Friendly labels for the common blanks; anything else is humanised from its key. */
    const BLANK_LABELS = [
        'cancel_fee'            => 'Cancellation penalty (per pax)',
        'change_fee'            => 'Change fee (per pax)',
        'cancel_deadline_hours' => 'Cancel at least (hours before dep.)',
        'refund_outcome'        => 'On cancellation, ticket is',
        'change_policy'         => 'Exchanges',
        'name_change'           => 'Name change',
    ];

    /**
     * Acceptance (card authorisation) policy. Clause 2 is the fare type's refund
     * clause; the rest is common to every fare. Moved here from the create view.
     */
    const ACCEPTANCE_POLICY = "1. PASSENGER NAMES: Names must match your government-issued ID exactly. Lets Fly Travel LLC DBA Base Fare is not responsible for denied boarding due to name mismatches or Visa/Travel Document issues.\n"
        . "2. {{refund_clause}}\n"
        . "3. CHARGEBACK WAIVER: You explicitly acknowledge that all services described herein have been rendered by Lets Fly Travel LLC DBA Base Fare. Filing a credit card dispute or chargeback after signing this authorization constitutes Friendly Fraud. You explicitly waive your right to file a credit card dispute or chargeback for this transaction. This signed authorization, along with your IP address, device fingerprint, and user-agent information will be submitted as conclusive evidence to your financial institution to contest any such claim.\n"
        . "4. AUTHORIZATION: I authorize Lets Fly Travel LLC DBA Base Fare to charge the Total Amount listed to my credit card.\n"
        . "5. I confirm that I am the authorized cardholder and approve the charge of the agreed amount for the requested travel services.\n"
        . "6. I acknowledge that I have personally requested this service and that all details, including itinerary, pricing, and applicable terms, have been clearly explained to me prior to authorization.\n"
        . "7. I understand that the Lets Fly Travel LLC DBA Base Fare acts solely as an intermediary, and all bookings, cancellations, and refunds are subject to the respective airline's rules and regulations.\n"
        . self::SERVICE_FEE_CLAUSE . "\n"
        . "9. I acknowledge that the service is considered fully rendered once the reservation/ticket has been issued or the requested service has been completed.\n"
        . "10. I confirm that I have received and reviewed all booking details via email, phone, or message and have provided my consent to proceed.\n"
        . "11. I understand that any cancellations, changes, refund or any other travel related service requests will be governed strictly by the airline's fare rules and policies, and additional charges may apply.\n"
        . "12. I agree that this transaction is valid, authorized, and initiated by me voluntarily without any misrepresentation.\n"
        . "13. I undertake to contact Lets Fly Travel LLC DBA Base Fare directly for any concerns or clarifications before initiating any dispute or chargeback with my bank or card issuer.\n"
        . "14. I acknowledge that this transaction may be recorded (call/email/SMS) for quality, training, and verification purposes.\n"
        . "15. I confirm that the billing details provided by me are accurate and belong to me, and I take full responsibility for this transaction.\n"
        . "16. I understand and agree to comply with the 24-hour cancellation policy (if applicable), subject to airline terms and conditions.";

    /** Clause 8 of ACCEPTANCE_POLICY. */
    const SERVICE_FEE_CLAUSE = '8. I agree that the service fee charged by Lets Fly Travel LLC DBA Base Fare is non-refundable once the booking or requested service has been processed.';

    /**
     * Clause 8 on an Exchange that carries a Future Travel Voucher (agreed 4 Oct
     * 2026): still non-refundable in cash — the chargeback position holds — but
     * the voucher replaces it. See ExchangeVoucherService.
     */
    const SERVICE_FEE_CLAUSE_VOUCHER = '8. I agree that the service fee charged by Lets Fly Travel LLC DBA Base Fare is non-refundable in cash once the booking or requested service has been processed. For this exchange, a Future Travel Voucher of {{voucher_amount}} is issued to me in its place, valid until {{voucher_valid_until}}, redeemable for a future booking by contacting Lets Fly Travel LLC DBA Base Fare.';

    /** Swap clause 8 for the voucher wording when the exchange carries a voucher. */
    public static function applyVoucherClause(string $policy, ?array $ftv): string
    {
        if (!$ftv) {
            return $policy;
        }
        $clause = str_replace(
            ['{{voucher_amount}}', '{{voucher_valid_until}}'],
            [ExchangeVoucherService::money($ftv), ExchangeVoucherService::date($ftv['valid_until'])],
            self::SERVICE_FEE_CLAUSE_VOUCHER
        );
        return str_replace(self::SERVICE_FEE_CLAUSE, $clause, $policy);
    }

    /** E-ticket acknowledgement policy; clause 2 is the refund clause. */
    const ETICKET_POLICY = "1. TICKET RECEIPT: You have received your electronic travel ticket and all booking details are correct.\n\n"
        . "2. {{refund_clause}}\n\n"
        . "3. TRAVEL DOCUMENTS: You are solely responsible for ensuring valid passport, visa, and health documentation. "
        . "Denied boarding due to missing documents does not constitute grounds for a refund or dispute.\n\n"
        . "4. CHECK-IN: Please check in online within the airline's check-in window. Missed check-in is not the "
        . "responsibility of Lets Fly Travel DBA Base Fare.\n\n"
        . "5. GOVERNING LAW: This agreement is governed by the laws of the State of New York, USA.";

    /** The pre-fare-type wording, for records created before this shipped (fare_type NULL). */
    const LEGACY_CHECKBOX    = 'I understand this purchase is NON-REFUNDABLE and NON-TRANSFERABLE once issued.';
    const LEGACY_ETICKET_ACK = 'non-refundable and non-transferable';

    private static ?array $templatesCache = null;
    private static ?array $cabinMapCache  = null;

    // =========================================================================
    // TEMPLATES
    // =========================================================================

    /** All templates: defaults with any admin overrides from system_config on top. */
    public static function templates(): array
    {
        if (self::$templatesCache !== null) {
            return self::$templatesCache;
        }

        $templates = self::DEFAULT_TEMPLATES;
        foreach (self::loadConfig(self::CONFIG_TEMPLATE_PREFIX . '%') as $key => $value) {
            $slug = substr($key, strlen(self::CONFIG_TEMPLATE_PREFIX));
            $override = json_decode((string) $value, true);
            if (!isset($templates[$slug]) || !is_array($override)) {
                continue;
            }
            foreach (self::PARTS as $part) {
                if (isset($override[$part]) && is_string($override[$part])) {
                    $templates[$slug][$part] = $override[$part];
                }
            }
        }

        return self::$templatesCache = $templates;
    }

    public static function template(string $slug): array
    {
        $all = self::templates();
        return $all[$slug] ?? $all[self::NON_REFUNDABLE];
    }

    public static function isValidType(?string $slug): bool
    {
        return $slug !== null && in_array($slug, self::TYPES, true);
    }

    public static function label(?string $slug): string
    {
        return self::isValidType($slug) ? self::template($slug)['label'] : 'Non-refundable (legacy)';
    }

    /** Cabin → default fare type, admin-editable. */
    public static function cabinMap(): array
    {
        if (self::$cabinMapCache !== null) {
            return self::$cabinMapCache;
        }

        $map = self::DEFAULT_CABIN_MAP;
        $stored = self::loadConfig(self::CONFIG_CABIN_MAP)[self::CONFIG_CABIN_MAP] ?? null;
        $override = $stored ? json_decode((string) $stored, true) : null;
        if (is_array($override)) {
            foreach (self::CABINS as $cabin) {
                if (isset($override[$cabin]) && self::isValidType($override[$cabin])) {
                    $map[$cabin] = $override[$cabin];
                }
            }
        }

        return self::$cabinMapCache = $map;
    }

    public static function defaultForCabin(?string $cabin): string
    {
        return self::cabinMap()[$cabin ?? ''] ?? self::NON_REFUNDABLE;
    }

    /** Drop per-request caches after an admin save. */
    public static function flushCache(): void
    {
        self::$templatesCache = null;
        self::$cabinMapCache  = null;
    }

    // =========================================================================
    // BLANKS
    // =========================================================================

    /**
     * The blanks a template asks the agent to fill, in first-appearance order.
     *
     * @return array<string, array{key:string,label:string,type:string,options:string[]}>
     */
    public static function blanks(string $slug): array
    {
        $tpl = self::template($slug);
        $blanks = [];

        foreach (self::PARTS as $part) {
            preg_match_all(self::PLACEHOLDER_RE, $tpl[$part], $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $key = strtolower($m[1]);
                if (in_array($key, self::RESERVED, true)) {
                    continue;
                }
                $options = isset($m[2]) && trim($m[2]) !== ''
                    ? array_values(array_filter(array_map('trim', explode('|', $m[2])), 'strlen'))
                    : [];

                if (!isset($blanks[$key])) {
                    $blanks[$key] = [
                        'key'     => $key,
                        'label'   => self::BLANK_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                        'type'    => $options ? 'select' : self::inferType($key),
                        'options' => $options,
                    ];
                } elseif ($options && !$blanks[$key]['options']) {
                    // Options may be declared on a later occurrence
                    $blanks[$key]['options'] = $options;
                    $blanks[$key]['type'] = 'select';
                }
            }
        }

        return $blanks;
    }

    private static function inferType(string $key): string
    {
        return preg_match('/(_fee|_amount|_hours|_days)$/', $key) ? 'number' : 'text';
    }

    /**
     * Clean agent-supplied blank values against the template's blanks.
     *
     * @return array{values: array<string,string>, missing: string[]}
     */
    public static function cleanValues(string $slug, array $raw): array
    {
        $values = [];
        $missing = [];

        foreach (self::blanks($slug) as $key => $blank) {
            $v = trim(preg_replace('/\s+/', ' ', (string) ($raw[$key] ?? '')));
            $v = str_replace(['{', '}'], '', mb_substr($v, 0, 80));

            if ($blank['type'] === 'select') {
                $v = in_array($v, $blank['options'], true) ? $v : '';
            } elseif ($blank['type'] === 'number') {
                $num = str_replace(',', '', $v);
                if ($num === '' || !is_numeric($num) || (float) $num < 0) {
                    $v = '';
                } else {
                    $v = preg_match('/_(hours|days)$/', $key)
                        ? (string) (int) $num
                        : number_format((float) $num, 2);
                }
            }

            if ($v === '') {
                $missing[] = $blank['label'];
            } else {
                $values[$key] = $v;
            }
        }

        return ['values' => $values, 'missing' => $missing];
    }

    // =========================================================================
    // RENDER
    // =========================================================================

    /**
     * Fill every part of a template.
     *
     * @return array{fare_rules:string, endorsements:string, refund_clause:string, checkbox:string,
     *               eticket_ack:string, acceptance_policy:string, eticket_policy:string}
     */
    public static function render(string $slug, array $values, string $currency = 'USD'): array
    {
        $tpl = self::template($slug);
        $values['currency'] = strtoupper($currency ?: 'USD');

        $out = [];
        foreach (self::PARTS as $part) {
            $out[$part] = self::fill($tpl[$part], $values);
        }
        $out['acceptance_policy'] = str_replace('{{refund_clause}}', $out['refund_clause'], self::ACCEPTANCE_POLICY);
        $out['eticket_policy']    = str_replace('{{refund_clause}}', $out['refund_clause'], self::ETICKET_POLICY);

        return $out;
    }

    private static function fill(string $text, array $values): string
    {
        return preg_replace_callback(self::PLACEHOLDER_RE, function ($m) use ($values) {
            $key = strtolower($m[1]);
            return $values[$key] ?? '____';
        }, $text);
    }

    /**
     * Resolve the posted fare-terms fields into the text to store.
     *
     * Agents get server-rendered text only. A manager/admin who unlocked manual
     * edit (terms_manual=1) keeps the text they typed.
     *
     * @param array $body     request body: fare_type, fare_terms_values (JSON), terms_manual,
     *                        plus fare_rules / endorsements / policy_text for manual edit
     * @param string $policy  'acceptance' | 'eticket' — which base policy to build
     * @return array{fare_type:string, fare_terms_values:array, fare_rules:string, endorsements:string,
     *               policy_text:string, refund_ack_text:string, manual:bool, missing:string[]}
     */
    public static function resolveFromRequest(array $body, string $role, string $policy, string $currency): array
    {
        $slug = self::isValidType($body['fare_type'] ?? null) ? $body['fare_type'] : self::NON_REFUNDABLE;

        $raw = $body['fare_terms_values'] ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        $clean = self::cleanValues($slug, is_array($raw) ? $raw : []);
        $r = self::render($slug, $clean['values'], $currency);

        $canEdit = in_array($role, ['admin', 'manager'], true);
        $manual  = $canEdit && ($body['terms_manual'] ?? '') === '1';

        $policyKey = $policy === 'eticket' ? 'eticket_policy' : 'acceptance_policy';
        $ackKey    = $policy === 'eticket' ? 'eticket_ack' : 'checkbox';

        return [
            'fare_type'         => $slug,
            'fare_terms_values' => $clean['values'],
            'fare_rules'        => $manual ? trim((string) ($body['fare_rules'] ?? '')) : $r['fare_rules'],
            'endorsements'      => $manual ? trim((string) ($body['endorsements'] ?? '')) : $r['endorsements'],
            'policy_text'       => $manual ? trim((string) ($body['policy_text'] ?? '')) : $r[$policyKey],
            'refund_ack_text'   => $r[$ackKey],
            'manual'            => $manual,
            // Manual edit still needs the blanks: they feed the customer's checkbox
            'missing'           => $clean['missing'],
        ];
    }

    /** Everything the browser needs to render the picker and live preview. */
    public static function clientConfig(): array
    {
        $templates = [];
        foreach (self::templates() as $slug => $tpl) {
            $templates[$slug] = $tpl + ['blanks' => array_values(self::blanks($slug))];
        }

        return [
            'types'             => self::TYPES,
            'templates'         => $templates,
            'cabinMap'          => self::cabinMap(),
            'acceptancePolicy'  => self::ACCEPTANCE_POLICY,
            'eticketPolicy'     => self::ETICKET_POLICY,
            'serviceFeeClause'        => self::SERVICE_FEE_CLAUSE,
            'serviceFeeClauseVoucher' => self::SERVICE_FEE_CLAUSE_VOUCHER,
        ];
    }

    // =========================================================================
    // ADMIN SAVE
    // =========================================================================

    /**
     * Validate an admin template edit. Returns error strings (empty = fine).
     * Rules: every part non-empty, no unbalanced braces, a select blank's options
     * are consistent wherever they are declared.
     */
    public static function validateTemplate(array $parts): array
    {
        $errors = [];
        $optionDecls = [];

        foreach (self::PARTS as $part) {
            $text = (string) ($parts[$part] ?? '');
            if (trim($text) === '') {
                $errors[] = ucfirst(str_replace('_', ' ', $part)) . ' cannot be empty.';
                continue;
            }
            $stripped = preg_replace(self::PLACEHOLDER_RE, '', $text);
            if (str_contains($stripped, '{{') || str_contains($stripped, '}}')) {
                $errors[] = ucfirst(str_replace('_', ' ', $part)) . ' has a broken {{blank}} — check the braces.';
            }
            if (str_contains($text, '{{refund_clause}}')) {
                $errors[] = '{{refund_clause}} is reserved and cannot be used inside a template.';
            }
            preg_match_all(self::PLACEHOLDER_RE, $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                if (isset($m[2]) && trim($m[2]) !== '') {
                    $key = strtolower($m[1]);
                    $opts = implode('|', array_map('trim', explode('|', $m[2])));
                    if (isset($optionDecls[$key]) && $optionDecls[$key] !== $opts) {
                        $errors[] = "Blank {{{$key}}} lists different options in two places — declare them once.";
                    }
                    $optionDecls[$key] = $opts;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    public static function saveTemplate(string $slug, array $parts, int $adminId): void
    {
        $data = [];
        foreach (self::PARTS as $part) {
            $data[$part] = str_replace("\r\n", "\n", (string) $parts[$part]);
        }
        self::upsertConfig(self::CONFIG_TEMPLATE_PREFIX . $slug, json_encode($data, JSON_UNESCAPED_UNICODE), $adminId);
        self::flushCache();
    }

    public static function resetTemplate(string $slug): void
    {
        DB::table('system_config')->where('key', self::CONFIG_TEMPLATE_PREFIX . $slug)->delete();
        self::flushCache();
    }

    public static function saveCabinMap(array $map, int $adminId): void
    {
        $clean = [];
        foreach (self::CABINS as $cabin) {
            $clean[$cabin] = self::isValidType($map[$cabin] ?? null) ? $map[$cabin] : self::DEFAULT_CABIN_MAP[$cabin];
        }
        self::upsertConfig(self::CONFIG_CABIN_MAP, json_encode($clean), $adminId);
        self::flushCache();
    }

    /** Whether a template differs from its shipped default. */
    public static function isCustomised(string $slug): bool
    {
        $tpl = self::template($slug);
        foreach (self::PARTS as $part) {
            if ($tpl[$part] !== self::DEFAULT_TEMPLATES[$slug][$part]) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static array $columnsReady = [];

    /**
     * Whether the 2026_09_29_fare_terms migration has run on this table.
     * Code can land before the migration; saving must not break in between.
     */
    public static function columnsReady(string $table): bool
    {
        if (!isset(self::$columnsReady[$table])) {
            try {
                self::$columnsReady[$table] = DB::schema()->hasColumn($table, 'refund_ack_text');
            } catch (\Throwable $e) {
                self::$columnsReady[$table] = false;
            }
        }
        return self::$columnsReady[$table];
    }

    /** Drop the fare-term columns from a create() payload if the migration hasn't run. */
    public static function stripIfNotMigrated(string $table, array $attrs): array
    {
        if (!self::columnsReady($table)) {
            unset($attrs['fare_type'], $attrs['fare_terms_values'], $attrs['refund_ack_text']);
        }
        return $attrs;
    }

    /** @return array<string,string> key → value; empty if the table is unreachable */
    private static function loadConfig(string $keyPattern): array
    {
        try {
            $q = DB::table('system_config');
            $q = str_contains($keyPattern, '%') ? $q->where('key', 'like', $keyPattern) : $q->where('key', $keyPattern);
            return $q->pluck('value', 'key')->all();
        } catch (\Throwable $e) {
            // Defaults are complete on their own; a config read failure must never
            // stop an agent sending an authorisation form.
            error_log('[FareTermsService] config read failed: ' . $e->getMessage());
            return [];
        }
    }

    private static function upsertConfig(string $key, string $value, int $adminId): void
    {
        $now = date('Y-m-d H:i:s');
        if (DB::table('system_config')->where('key', $key)->exists()) {
            DB::table('system_config')->where('key', $key)->update([
                'value' => $value, 'updated_by' => $adminId, 'updated_at' => $now,
            ]);
        } else {
            DB::table('system_config')->insert([
                'key' => $key, 'value' => $value, 'updated_by' => $adminId, 'updated_at' => $now,
            ]);
        }
    }
}
