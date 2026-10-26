<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;
use App\Hr\Support\Markup;
use App\Hr\Support\Notify;

/**
 * Letters on Trio's letterhead.
 *
 *  - Filled from the employee record plus a few details asked for when
 *    preparing (e.g. salary for an appointment letter).
 *  - A second admin checks every letter before it is issued (switch:
 *    security.letters_need_check). Nobody checks their own letter.
 *  - On issue a letter gets a number (TT/HR/2026/0007) and a verification
 *    code, and its text is frozen: the archive always shows exactly what was sent.
 *  - The employee acknowledges it with a click where the template asks for it.
 *  - Anyone can confirm a letter is genuine with its code (#/verify/CODE).
 */
final class Letters
{
    public const STATUS_LABELS = [
        'draft' => 'Draft', 'pending_check' => 'Waiting for a second check', 'issued' => 'Issued',
        'acknowledged' => 'Acknowledged', 'void' => 'Cancelled',
    ];
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // payslips use the same codes
    private const FIELD_LABELS = [
        'signatory.name' => 'the authorised signatory (Settings → Company)', 'signatory.title' => "the signatory's designation (Settings → Company)",
        'employee.address' => "the employee's address", 'employee.last_working_day' => 'the last working day', 'employee.notice_days' => 'the notice period',
        'employee.designation' => 'the job title', 'employee.a_designation' => 'the job title', 'employee.date_joined' => 'the joining date', 'employee.service_line' => 'the joining date',
    ];

    // ---- templates ----------------------------------------------------------------

    public static function templates(): array
    {
        return Kernel::hr()->table('hr_letter_templates')->orderBy('sort')->get()->map(fn($t) => [
            'code' => $t->code, 'name' => $t->name, 'needs_ack' => (bool) $t->needs_ack, 'staff_can_request' => (bool) $t->staff_can_request,
            'fields' => json_decode((string) $t->fields, true) ?: [],
        ])->all();
    }

    private static function template(string $code): object
    {
        $t = Kernel::hr()->table('hr_letter_templates')->where('code', $code)->first();
        if (!$t) {
            throw ApiError::invalid('Please pick a kind of letter.', ['template_code' => 'Pick a kind of letter.']);
        }
        return $t;
    }

    // ---- filling ----------------------------------------------------------------------

    private static function longDate(?string $d): string
    {
        return $d ? date('j F Y', strtotime(substr($d, 0, 10) . ' 12:00:00')) : '';
    }

    /** Everything a template can use, as plain text. */
    public static function context(object $e, array $fields, array $fieldDefs, ?object $letter = null, ?array $frozen = null): array
    {
        $company = $frozen['company'] ?? Settings::get('company');
        $tl = $e->reports_to_id ? Kernel::hr()->table('hr_employees')->where('id', $e->reports_to_id)->value('full_name') : null;
        $left = $e->last_working_day && $e->last_working_day <= date('Y-m-d');
        $ctx = [
            'company.name' => $company['name'] ?? '', 'company.legal_name' => $company['legal_name'] ?? '',
            'company.address' => $company['address'] ?? '', 'company.cin' => $company['cin'] ?? '', 'company.hr_email' => $company['hr_email'] ?? '',
            'signatory.name' => $company['signatory_name'] ?? '', 'signatory.title' => $company['signatory_title'] ?? '',
            'employee.name' => $e->full_name, 'employee.first' => Fmt::first($e->preferred_name ?: $e->full_name),
            'employee.designation' => (string) $e->designation, 'employee.code' => $e->employee_code,
            'employee.a_designation' => $e->designation ? Fmt::an((string) $e->designation) : '',
            'employee.centre' => (string) Settings::centreName($e->centre), 'employee.team_leader' => (string) $tl,
            'employee.date_joined' => self::longDate($e->date_joined), 'employee.date_joined_raw' => (string) $e->date_joined,
            'employee.probation_end' => self::longDate($e->probation_end), 'employee.probation_end_raw' => (string) $e->probation_end,
            'employee.notice_days' => $e->notice_days !== null ? (string) $e->notice_days : '',
            'employee.last_working_day' => self::longDate($e->last_working_day), 'employee.address' => (string) $e->address,
            'employee.service_line' => !$e->date_joined ? '' : ($left
                ? 'worked with us from ' . self::longDate($e->date_joined) . ' to ' . self::longDate($e->last_working_day) . '.'
                : 'has been working with us since ' . self::longDate($e->date_joined) . '.'),
            'letter.date' => self::longDate($letter->issued_at ?? date('Y-m-d')), 'letter.number' => (string) ($letter->number ?? ''),
        ];
        foreach ($fieldDefs as $f) {
            $v = $fields[$f['key']] ?? '';
            $ctx['fields.' . $f['key']] = match ($f['type'] ?? 'text') {
                'date' => self::longDate($v ?: null),
                'money' => $v !== '' ? ltrim(Fmt::rupees((float) $v), '₹') : '',
                default => (string) $v,
            };
        }
        return $ctx;
    }

    /** @return array{subject:string, html:string, missing:list<string>} */
    private static function render(object $tpl, array $ctx): array
    {
        $missing = [];
        foreach (array_merge(Markup::fields($tpl->body), Markup::fields($tpl->subject)) as $key) {
            if (($ctx[$key] ?? '') === '') {
                $missing[] = $key;
            }
        }
        $missing = array_values(array_unique($missing));
        // In a draft, a missing value shows as a marked gap; a letter can't be issued with gaps.
        $show = $ctx;
        foreach ($missing as $key) {
            $show[$key] = '⟨' . self::missingLabel($key) . ' missing⟩';
        }
        return [
            'subject' => str_replace("\v", ' ', Markup::fill($tpl->subject, $show)),
            'html' => Markup::toHtml(Markup::fill($tpl->body, $show)),
            'missing' => $missing,
        ];
    }

    private static function missingLabel(string $key): string
    {
        if (isset(self::FIELD_LABELS[$key])) {
            return self::FIELD_LABELS[$key];
        }
        return str_starts_with($key, 'fields.') ? str_replace('_', ' ', substr($key, 7)) : $key;
    }

    /** Validate the extra details a template asks for. */
    private static function readFields(Ctx $c, array $defs, bool $strict): array
    {
        $raw = $c->raw('fields');
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        $errors = [];
        foreach ($defs as $f) {
            $k = $f['key'];
            $v = isset($raw[$k]) && (is_string($raw[$k]) || is_numeric($raw[$k])) ? trim((string) $raw[$k]) : '';
            if ($v === '') {
                if ($strict && !empty($f['required'])) {
                    $errors['fields.' . $k] = $f['label'] . ' is needed.';
                }
                $out[$k] = '';
                continue;
            }
            switch ($f['type'] ?? 'text') {
                case 'date':
                    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                    if (!$d || $d->format('Y-m-d') !== $v) $errors['fields.' . $k] = 'Pick a date.';
                    break;
                case 'money':
                    $v = str_replace([',', ' ', '₹'], '', $v);
                    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $v)) $errors['fields.' . $k] = 'Enter an amount in rupees, like 25000 or 25000.50.';
                    break;
                case 'number':
                    if (!ctype_digit($v) || (int) $v > 120) $errors['fields.' . $k] = 'Enter a whole number.';
                    break;
                case 'textarea':
                    if (mb_strlen($v) > 1500) $errors['fields.' . $k] = 'Keep it under 1,500 characters.';
                    break;
                default:
                    if (mb_strlen($v) > 200) $errors['fields.' . $k] = 'Keep it under 200 characters.';
            }
            $out[$k] = $v;
        }
        if ($errors) {
            throw ApiError::invalid('Please check the highlighted details.', $errors);
        }
        return $out;
    }

    /** Defaults for a new letter's details (from the record or the staff request). */
    public static function defaults(object $e, array $defs, ?array $request = null): array
    {
        $out = [];
        foreach ($defs as $f) {
            $v = $f['default'] ?? '';
            if (!empty($f['from'])) {
                $v = match ($f['from']) {
                    'employee.date_joined_raw' => (string) $e->date_joined,
                    'employee.probation_end_raw' => (string) $e->probation_end,
                    'request.purpose' => (string) ($request['purpose'] ?? ''),
                    default => $v,
                } ?: $v;
            }
            $out[$f['key']] = $v;
        }
        return $out;
    }

    // ---- admin: list, prepare, check, issue ----------------------------------------------

    public static function list(Ctx $c): array
    {
        $db = Kernel::hr();
        $status = $c->query('status', 20);
        $q = $db->table('hr_letters as l')->join('hr_employees as e', 'e.id', '=', 'l.employee_id')
            ->join('hr_letter_templates as t', 't.code', '=', 'l.template_code')
            ->select('l.*', 'e.full_name', 'e.designation', 't.name as template_name');
        if ($status && isset(self::STATUS_LABELS[$status])) {
            $q->where('l.status', $status);
        } elseif ($status === 'open') {
            $q->whereIn('l.status', ['draft', 'pending_check']);
        }
        if ($emp = $c->query('employee', 10)) {
            $q->where('l.employee_id', (int) $emp);
        }
        $names = $db->table('hr_accounts')->pluck('display_name', 'id')->all();
        $rows = $q->orderByRaw("FIELD(l.status, 'pending_check', 'draft', 'issued', 'acknowledged', 'void')")->orderByDesc('l.updated_at')->limit(300)->get();
        $counts = $db->table('hr_letters')->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status')->all();
        $current = $db->table('hr_employees')->whereIn('status', ['active', 'notice'])->pluck('id')->all();
        $withAppt = $db->table('hr_letters')->where('template_code', 'appointment')->whereIn('status', ['issued', 'acknowledged'])
            ->whereIn('employee_id', $current ?: [0])->distinct()->pluck('employee_id')->all();
        $apptOpen = $db->table('hr_letters')->where('template_code', 'appointment')->whereIn('status', ['draft', 'pending_check'])->distinct()->pluck('employee_id')->all();
        return [
            'letters' => $rows->map(fn($l) => [
                'id' => (int) $l->id, 'number' => $l->number, 'subject' => $l->subject, 'template' => $l->template_name,
                'person' => ['id' => (int) $l->employee_id, 'name' => $l->full_name, 'initials' => Fmt::initials($l->full_name), 'designation' => $l->designation],
                'status' => $l->status, 'status_label' => self::STATUS_LABELS[$l->status], 'prepared_by' => $names[$l->prepared_by] ?? null,
                'mine' => (int) $l->prepared_by === $c->accountId(), 'updated_at' => $l->updated_at, 'issued_at' => $l->issued_at,
            ])->all(),
            'counts' => array_map('intval', $counts),
            'catch_up' => ['staff' => count($current), 'have' => count($withAppt), 'in_progress' => count(array_diff($apptOpen, $withAppt)),
                'missing' => count(array_diff($current, $withAppt, $apptOpen))],
            'templates' => self::templates(),
            'needs_check' => Settings::security('letters_need_check'),
        ];
    }

    /** The prepare form: template details with defaults for this person. */
    public static function form(int $employeeId, string $code): array
    {
        $e = Kernel::hr()->table('hr_employees')->where('id', $employeeId)->first();
        if (!$e) {
            throw ApiError::notFound('That person');
        }
        $t = self::template($code);
        $defs = json_decode((string) $t->fields, true) ?: [];
        $vals = self::defaults($e, $defs);
        $r = self::render($t, self::context($e, $vals, $defs));
        return ['template' => ['code' => $t->code, 'name' => $t->name, 'fields' => $defs, 'needs_ack' => (bool) $t->needs_ack],
            'values' => $vals, 'preview' => $r, 'person' => ['id' => (int) $e->id, 'name' => $e->full_name, 'designation' => $e->designation]];
    }

    public static function create(Ctx $c): array
    {
        $eid = $c->intVal('employee_id', 1, PHP_INT_MAX, true, 'Person');
        $t = self::template((string) $c->str('template_code', 40, true, 'Kind of letter'));
        $e = Kernel::hr()->table('hr_employees')->where('id', $eid)->first();
        if (!$e) {
            throw ApiError::notFound('That person');
        }
        $defs = json_decode((string) $t->fields, true) ?: [];
        $fields = self::readFields($c, $defs, false);
        $r = self::render($t, self::context($e, $fields, $defs));
        $now = Kernel::now();
        $id = Kernel::hr()->table('hr_letters')->insertGetId([
            'employee_id' => $eid, 'template_code' => $t->code, 'subject' => $r['subject'], 'data' => json_encode(['fields' => $fields]),
            'body_html' => $r['html'], 'status' => 'draft', 'prepared_by' => $c->accountId(), 'prepared_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Audit::log('letter.prepare', ['employee_id' => $eid, 'entity' => 'hr_letters', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' prepared ' . Fmt::an(Fmt::lower($t->name)) . ' for ' . $e->full_name]);
        return ['id' => $id, 'missing' => $r['missing']];
    }

    /** Start a letter from an approved staff request (runs inside the approval). */
    public static function fromRequest(object $request, int $byAccount): int
    {
        $p = json_decode($request->payload, true) ?: [];
        $t = self::template((string) ($p['template_code'] ?? ''));
        $e = Kernel::hr()->table('hr_employees')->where('id', $request->employee_id)->first();
        $defs = json_decode((string) $t->fields, true) ?: [];
        $fields = self::defaults($e, $defs, $p);
        $r = self::render($t, self::context($e, $fields, $defs));
        $now = Kernel::now();
        $ready = !$r['missing'];
        $id = Kernel::hr()->table('hr_letters')->insertGetId([
            'employee_id' => $e->id, 'template_code' => $t->code, 'subject' => $r['subject'], 'data' => json_encode(['fields' => $fields]),
            'body_html' => $r['html'], 'status' => $ready && Settings::security('letters_need_check') ? 'pending_check' : 'draft',
            'prepared_by' => $byAccount, 'prepared_at' => $now, 'request_id' => $request->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $id;
    }

    private static function load(int $id): object
    {
        $l = Kernel::hr()->table('hr_letters')->where('id', $id)->first();
        if (!$l) {
            throw ApiError::notFound('That letter');
        }
        return $l;
    }

    public static function get(int $id, ?int $onlyEmployee = null): array
    {
        $l = self::load($id);
        if ($onlyEmployee !== null && ((int) $l->employee_id !== $onlyEmployee || !in_array($l->status, ['issued', 'acknowledged'], true))) {
            throw ApiError::notFound('That letter');
        }
        $db = Kernel::hr();
        $e = $db->table('hr_employees')->where('id', $l->employee_id)->first();
        $t = self::template($l->template_code);
        $data = json_decode((string) $l->data, true) ?: [];
        $defs = json_decode((string) $t->fields, true) ?: [];
        $names = $db->table('hr_accounts')->pluck('display_name', 'id')->all();
        $live = in_array($l->status, ['draft', 'pending_check'], true);
        // Drafts re-render (so record edits show up); issued letters are frozen.
        $r = $live ? self::render($t, self::context($e, $data['fields'] ?? [], $defs, $l)) : ['subject' => $l->subject, 'html' => $l->body_html, 'missing' => []];
        $company = $data['letterhead'] ?? Settings::get('company');
        return [
            'letter' => [
                'id' => (int) $l->id, 'number' => $l->number, 'subject' => $r['subject'], 'html' => $r['html'], 'missing' => array_map([self::class, 'missingLabel'], $r['missing']),
                'status' => $l->status, 'status_label' => self::STATUS_LABELS[$l->status], 'template' => $t->name, 'template_code' => $t->code,
                'needs_ack' => (bool) $t->needs_ack, 'fields' => $data['fields'] ?? [], 'field_defs' => $defs,
                'prepared_by' => $names[$l->prepared_by] ?? null, 'prepared_by_id' => (int) $l->prepared_by, 'prepared_at' => $l->prepared_at,
                'checked_by' => $names[$l->checked_by] ?? null, 'check_note' => $l->check_note, 'issued_at' => $l->issued_at,
                'ack_at' => $l->ack_at, 'ack_name' => $l->ack_name, 'void_reason' => $l->void_reason,
                'date' => self::longDate($l->issued_at ?? date('Y-m-d')),
                'verify_code' => $l->verify_code ? self::groupCode($l->verify_code) : null,
                'verify_url' => $l->verify_code ? rtrim((string) Kernel::env('HR_APP_URL', 'https://hr.triotoursandtravels.in'), '/') . '/#/verify/' . $l->verify_code : null,
                'needs_check' => Settings::security('letters_need_check'),
            ],
            'person' => ['id' => (int) $e->id, 'name' => $e->full_name, 'designation' => $e->designation, 'code' => $e->employee_code],
            'letterhead' => [
                'legal_name' => $company['legal_name'] ?? '', 'address' => $company['address'] ?? '', 'cin' => $company['cin'] ?? '',
                'signatory_name' => $company['signatory_name'] ?? '', 'signatory_title' => $company['signatory_title'] ?? '',
            ],
        ];
    }

    public static function update(Ctx $c, int $id): array
    {
        $l = self::load($id);
        if (!in_array($l->status, ['draft', 'pending_check'], true)) {
            throw ApiError::conflict('An issued letter can’t be changed. Cancel it and prepare a new one.');
        }
        $t = self::template($l->template_code);
        $defs = json_decode((string) $t->fields, true) ?: [];
        $fields = self::readFields($c, $defs, false);
        $e = Kernel::hr()->table('hr_employees')->where('id', $l->employee_id)->first();
        $r = self::render($t, self::context($e, $fields, $defs, $l));
        $done = Kernel::hr()->table('hr_letters')->where('id', $id)->whereIn('status', ['draft', 'pending_check'])->update([
            'data' => json_encode(['fields' => $fields]), 'subject' => $r['subject'], 'body_html' => $r['html'],
            // Edited by someone else while waiting: they become the preparer, and a different admin must check.
            'prepared_by' => $c->accountId(), 'status' => 'draft', 'updated_at' => Kernel::now(),
        ]);
        if (!$done) {
            throw self::changed();
        }
        return ['message' => 'Saved.', 'missing' => array_map([self::class, 'missingLabel'], $r['missing'])];
    }

    /** Draft → waiting for a second check (or straight to issued when checks are switched off). */
    public static function submit(Ctx $c, int $id): array
    {
        $l = self::load($id);
        if ($l->status !== 'draft') {
            throw ApiError::conflict('Only a draft can be sent for checking.');
        }
        $info = self::get($id);
        if ($info['letter']['missing']) {
            throw ApiError::invalid('Fill in ' . implode(', ', $info['letter']['missing']) . ' first.');
        }
        if (!Settings::security('letters_need_check')) {
            self::issue($l, $c->accountId(), $c, 'draft');
            return ['status' => 'issued', 'message' => 'Issued. ' . $info['person']['name'] . ' has been told.'];
        }
        if (!Kernel::hr()->table('hr_letters')->where('id', $id)->where('status', 'draft')->update(['status' => 'pending_check', 'check_note' => null, 'updated_at' => Kernel::now()])) {
            throw self::changed();
        }
        Notify::admins('letter.check', Fmt::first((string) $c->auth['display_name']) . ' prepared ' . Fmt::an(Fmt::lower($info['letter']['template'])) . ' for ' . $info['person']['name'],
            'It needs a second admin to check it before it is issued.', '#/letters/' . $id, $c->accountId());
        return ['status' => 'pending_check', 'message' => 'Sent for a second check. Another admin will check and issue it.'];
    }

    /** The second admin's check: approve and issue. */
    public static function approve(Ctx $c, int $id): array
    {
        $l = self::load($id);
        if ($l->status !== 'pending_check') {
            throw ApiError::conflict('This letter isn’t waiting for a check.');
        }
        if ((int) $l->prepared_by === $c->accountId()) {
            throw ApiError::forbidden('You prepared this letter, so another admin has to check it.');
        }
        $info = self::get($id);
        if ($info['letter']['missing']) {
            throw ApiError::invalid('This letter still needs ' . implode(', ', $info['letter']['missing']) . '.');
        }
        self::issue($l, $c->accountId(), $c, 'pending_check');
        return ['message' => 'Checked and issued as ' . Kernel::hr()->table('hr_letters')->where('id', $id)->value('number') . '. ' . $info['person']['name'] . ' has been told.'];
    }

    public static function returnToDraft(Ctx $c, int $id): array
    {
        $l = self::load($id);
        if ($l->status !== 'pending_check') {
            throw ApiError::conflict('This letter isn’t waiting for a check.');
        }
        $note = (string) $c->str('note', 255, true, 'What needs changing');
        if (!Kernel::hr()->table('hr_letters')->where('id', $id)->where('status', 'pending_check')->update(['status' => 'draft', 'check_note' => $note, 'checked_by' => $c->accountId(), 'updated_at' => Kernel::now()])) {
            throw self::changed();
        }
        if ($l->prepared_by) {
            Notify::account((int) $l->prepared_by, 'letter.returned', 'A letter you prepared needs changes', $note, '#/letters/' . $id);
        }
        Audit::log('letter.return', ['employee_id' => (int) $l->employee_id, 'entity' => 'hr_letters', 'entity_id' => $id, 'detail' => ['note' => $note]]);
        return ['message' => 'Sent back with your note.'];
    }

    /** A letter changed under someone's feet (another admin acted first). */
    private static function changed(): ApiError
    {
        return ApiError::conflict('Someone else changed this letter a moment ago. Refresh to see where it stands.');
    }

    private static function issue(object $l, int $byAccount, Ctx $c, string $from): void
    {
        $db = Kernel::hr();
        $e = $db->table('hr_employees')->where('id', $l->employee_id)->first();
        $t = self::template($l->template_code);
        $defs = json_decode((string) $t->fields, true) ?: [];
        $year = date('Y');
        $key = 'letter_seq.' . $year;
        // The year's counter row must exist before it is locked: two first-of-the-year letters would otherwise deadlock.
        $db->table('hr_settings')->insertOrIgnore(['key' => $key, 'value' => '0', 'updated_at' => Kernel::now()]);
        $number = $db->transaction(function () use ($db, $l, $e, $t, $defs, $byAccount, $from, $key, $year) {
            // Lock the letter and check it is still in the state the admin saw, with the data as it is now.
            $cur = $db->table('hr_letters')->where('id', $l->id)->lockForUpdate()->first();
            if (!$cur || $cur->status !== $from || ($from === 'pending_check' && (int) $cur->prepared_by === $byAccount)) {
                throw self::changed();
            }
            $data = json_decode((string) $cur->data, true) ?: [];
            $row = $db->table('hr_settings')->where('key', $key)->lockForUpdate()->first();
            $seq = (int) json_decode((string) $row->value) + 1;
            $db->table('hr_settings')->where('key', $key)->update(['value' => (string) $seq, 'updated_at' => Kernel::now()]);
            $number = sprintf('TT/HR/%s/%04d', $year, $seq);
            do {
                $code = '';
                for ($i = 0; $i < 12; $i++) {
                    $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
                }
            } while ($db->table('hr_letters')->where('verify_code', $code)->exists());
            $issued = (object) ['issued_at' => Kernel::now(), 'number' => $number];
            $company = Settings::get('company');
            $r = self::render($t, self::context($e, $data['fields'] ?? [], $defs, $issued));
            $db->table('hr_letters')->where('id', $l->id)->update([
                'status' => 'issued', 'number' => $number, 'verify_code' => $code, 'issued_at' => $issued->issued_at,
                'checked_by' => (int) $cur->prepared_by === $byAccount ? null : $byAccount,
                'subject' => $r['subject'], 'body_html' => $r['html'],
                // Freeze the letterhead, signatory and the person's name as they were on the day.
                'data' => json_encode(['fields' => $data['fields'] ?? [], 'letterhead' => $company, 'person_name' => $e->full_name], JSON_UNESCAPED_UNICODE),
                'updated_at' => Kernel::now(),
            ]);
            return $number;
        });
        Audit::log('letter.issue', ['employee_id' => (int) $e->id, 'entity' => 'hr_letters', 'entity_id' => (int) $l->id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' issued the ' . Fmt::lower($t->name) . ' ' . $number . ' to ' . $e->full_name]);
        Notify::employee((int) $e->id, 'letter.issued', 'Your ' . Fmt::lower($t->name) . ' is ready' . ($t->needs_ack ? ' to read and acknowledge' : ''),
            $number, '#/me/letters/' . $l->id);
        if ($l->request_id) {
            $db->table('hr_requests')->where('id', $l->request_id)->update(['decision' => json_encode(['letter_id' => (int) $l->id, 'number' => $number]), 'updated_at' => Kernel::now()]);
        }
    }

    public static function void(Ctx $c, int $id): array
    {
        $l = self::load($id);
        if ($l->status === 'void') {
            throw ApiError::conflict('Already cancelled.');
        }
        $reason = (string) $c->str('reason', 255, true, 'Why');
        if (!Kernel::hr()->table('hr_letters')->where('id', $id)->where('status', $l->status)->update(['status' => 'void', 'void_reason' => $reason, 'updated_at' => Kernel::now()])) {
            throw self::changed();
        }
        Audit::log('letter.void', ['employee_id' => (int) $l->employee_id, 'entity' => 'hr_letters', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' cancelled letter ' . ($l->number ?? '#' . $id), 'detail' => ['reason' => $reason]]);
        return ['message' => $l->number ? 'Cancelled. Its code now shows as cancelled to anyone who checks it.' : 'Draft deleted.'];
    }

    /** The appointment-letter catch-up: a draft for everyone who has none. */
    public static function catchUp(Ctx $c): array
    {
        // Two tabs pressing it at once take turns, so nobody gets two drafts.
        return Kernel::withLock('letters_catch_up', fn() => self::startCatchUp($c));
    }

    private static function startCatchUp(Ctx $c): array
    {
        $db = Kernel::hr();
        $have = $db->table('hr_letters')->where('template_code', 'appointment')->where('status', '!=', 'void')->distinct()->pluck('employee_id')->all();
        $people = $db->table('hr_employees')->whereIn('status', ['active', 'notice'])->whereNotIn('id', $have ?: [0])->get();
        $t = self::template('appointment');
        $defs = json_decode((string) $t->fields, true) ?: [];
        $now = Kernel::now();
        foreach ($people as $e) {
            $fields = self::defaults($e, $defs);
            $r = self::render($t, self::context($e, $fields, $defs));
            $db->table('hr_letters')->insert([
                'employee_id' => $e->id, 'template_code' => 'appointment', 'subject' => $r['subject'], 'data' => json_encode(['fields' => $fields]),
                'body_html' => $r['html'], 'status' => 'draft', 'prepared_by' => $c->accountId(), 'prepared_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        Audit::log('letter.catch_up', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' started appointment letters for ' . Fmt::plural(count($people), 'person', 'people'),
            'detail' => ['count' => count($people)]]);
        return ['count' => count($people), 'message' => count($people)
            ? 'Started ' . Fmt::plural(count($people), 'appointment letter') . '. Add each salary, then send them for checking.'
            : 'Everyone already has an appointment letter.'];
    }

    // ---- staff ---------------------------------------------------------------------------------

    public static function mine(Ctx $c): array
    {
        $rows = Kernel::hr()->table('hr_letters as l')->join('hr_letter_templates as t', 't.code', '=', 'l.template_code')
            ->where('l.employee_id', $c->employeeId())->whereIn('l.status', ['issued', 'acknowledged'])->orderByDesc('l.issued_at')
            ->get(['l.id', 'l.number', 'l.subject', 'l.status', 'l.issued_at', 'l.ack_at', 't.name', 't.needs_ack']);
        $requestable = Kernel::hr()->table('hr_letter_templates')->where('staff_can_request', 1)->orderBy('sort')->get(['code', 'name'])
            ->map(fn($t) => ['code' => $t->code, 'name' => $t->name])->all();
        return ['letters' => $rows->map(fn($l) => [
            'id' => (int) $l->id, 'number' => $l->number, 'name' => $l->name, 'subject' => $l->subject, 'issued_at' => $l->issued_at,
            'to_ack' => (bool) $l->needs_ack && $l->status === 'issued', 'ack_at' => $l->ack_at,
        ])->all(), 'requestable' => $requestable, 'needs_check' => Settings::security('letters_need_check')];
    }

    public static function acknowledge(Ctx $c, int $id): array
    {
        $l = self::load($id);
        $eid = (int) $c->employeeId();
        if ((int) $l->employee_id !== $eid || $l->status !== 'issued' || !self::template($l->template_code)->needs_ack) {
            throw ApiError::conflict('This letter doesn’t need acknowledging.');
        }
        if (!$c->raw('agree')) {
            throw ApiError::invalid('Tick the box to confirm.', ['agree' => 'Tick to confirm.']);
        }
        $typed = (string) $c->str('typed_name', 120, true, 'Your name');
        $e = Kernel::hr()->table('hr_employees')->where('id', $eid)->first(['full_name', 'preferred_name']);
        $norm = fn($s) => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $s)));
        if (!in_array($norm($typed), [$norm($e->full_name), $norm($e->preferred_name)], true)) {
            throw ApiError::invalid('Type your full name exactly as it appears: ' . $e->full_name . '.', ['typed_name' => 'Type ' . $e->full_name . '.']);
        }
        $done = Kernel::hr()->table('hr_letters')->where('id', $id)->where('status', 'issued')->update([
            'status' => 'acknowledged', 'ack_at' => Kernel::now(), 'ack_name' => $typed, 'ack_ip' => substr(Ctx::clientIp(), 0, 45), 'updated_at' => Kernel::now(),
        ]);
        if (!$done) {
            return ['message' => 'Already acknowledged. Thank you.'];
        }
        Audit::log('letter.ack', ['employee_id' => $eid, 'entity' => 'hr_letters', 'entity_id' => $id,
            'summary' => Fmt::first($e->full_name) . ' acknowledged letter ' . $l->number]);
        Notify::admins('letter.ack', Fmt::first($e->full_name) . ' acknowledged ' . $l->number, $l->subject, '#/letters/' . $id);
        return ['message' => 'Thank you. Acknowledged.'];
    }

    // ---- public verification -----------------------------------------------------------------

    public static function verify(string $code): array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        $ip = Ctx::clientIp();
        $recent = Kernel::hr()->table('hr_audit_log')->where('action', 'letter.verify')->where('ip', $ip)
            ->where('at', '>=', date('Y-m-d H:i:s', time() - 3600))->count();
        if ($recent >= 30) {
            throw new ApiError(429, 'throttled', 'Too many checks from this network. Please try again in an hour.');
        }
        Audit::log('letter.verify', ['account_id' => null, 'detail' => ['code' => substr($code, 0, 12)]]);
        if (strlen($code) !== 12) {
            return ['found' => false];
        }
        $l = Kernel::hr()->table('hr_letters as l')->join('hr_employees as e', 'e.id', '=', 'l.employee_id')
            ->join('hr_letter_templates as t', 't.code', '=', 'l.template_code')
            ->where('l.verify_code', $code)->whereIn('l.status', ['issued', 'acknowledged', 'void'])
            ->first(['l.number', 'l.status', 'l.issued_at', 'l.void_reason', 'l.data', 'e.full_name', 't.name']);
        if (!$l) {
            // Payslips carry the same kind of code (Phase 3).
            return Payroll::verifyPayslip($code) ?? ['found' => false];
        }
        $data = json_decode((string) $l->data, true) ?: [];
        $company = $data['letterhead'] ?? Settings::get('company');
        return ['found' => true, 'valid' => $l->status !== 'void', 'number' => $l->number, 'kind' => $l->name,
            'name' => $data['person_name'] ?? $l->full_name, 'issued_on' => self::longDate($l->issued_at), 'issuer' => $company['legal_name'] ?? '',
            'contact' => (string) (Settings::get('company')['hr_email'] ?? '') ?: null];
    }

    public static function groupCode(string $code): string
    {
        return implode('-', str_split($code, 4));
    }
}
