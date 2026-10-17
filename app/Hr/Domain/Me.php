<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Clock;
use App\Hr\Support\Fmt;
use App\Hr\Support\Notify;
use App\Hr\Support\Vault;

/**
 * The staff app. Every method works ONLY on the signed-in person's own
 * record: the employee id always comes from the session, never from input.
 */
final class Me
{
    /** Opened from the CRM (no password typed): bank changes and ID documents need a password sign-in. */
    public static function needsPasswordSession(Ctx $c, string $what): void
    {
        if (($c->auth['via'] ?? 'password') === 'crm' && !$c->isAdmin()) {
            throw new ApiError(403, 'needs_password', 'To ' . $what . ', sign in to Trio HR with your own password first. '
                . 'You opened Trio HR from the CRM, which is fine for everything else. No password yet? Use "Forgot your password?" on the sign-in page.');
        }
    }

    public static function employee(Ctx $c): object
    {
        $id = $c->employeeId();
        $e = $id ? Kernel::hr()->table('hr_employees')->where('id', $id)->first() : null;
        if (!$e) {
            throw ApiError::forbidden('This account isn\'t linked to a staff record. Please speak to the HR admin.');
        }
        return $e;
    }

    /**
     * A new request: audit it and tell the admins. Trio Mail passes $notify =
     * false and sends one notification for the message instead.
     */
    private static function filed(object $e, int $requestId, string $summary, bool $notify = true): void
    {
        Audit::log('request.create', ['employee_id' => (int) $e->id, 'entity' => 'hr_requests', 'entity_id' => $requestId, 'summary' => $summary]);
        if ($notify) {
            Notify::admins('request.new', $summary, 'Waiting for a decision in Approvals.', '#/approvals?id=' . $requestId);
        }
    }

    // ---- home ---------------------------------------------------------------------

    public static function home(Ctx $c): array
    {
        $e = self::employee($c);
        return [
            'greeting' => self::greeting(),
            'first'    => Fmt::first($e->preferred_name ?: $e->full_name),
            'punch'    => empty($c->auth['leaver']) ? Attendance::punchCard($c, $e) : null,
            'leave'    => Leave::balances((int) $e->id),
            'needs'    => empty($c->auth['leaver']) ? self::needs($c, $e) : [],
            'requests' => self::requests($c, 6),
            'leaver'   => $c->auth['leaver'] ?? null,
        ];
    }

    private static function greeting(): string
    {
        $h = (int) date('G');
        return $h >= 5 && $h < 12 ? 'Good morning' : ($h >= 12 && $h < 17 ? 'Good afternoon' : 'Good evening');
    }

    private static function needs(Ctx $c, object $e): array
    {
        $out = [];
        foreach (Kernel::hr()->table('hr_letters as l')->join('hr_letter_templates as t', 't.code', '=', 'l.template_code')
            ->where('l.employee_id', $e->id)->where('l.status', 'issued')->where('t.needs_ack', 1)->get(['l.id', 't.name']) as $l) {
            $out[] = ['tone' => 'gold', 'icon' => 'file', 'title' => 'Read and acknowledge your ' . Fmt::lower($l->name),
                'text' => 'It takes a minute', 'href' => '#/me/letters/' . $l->id];
        }
        foreach (Policies::outstandingFor((int) $e->id) as $p) {
            $out[] = ['tone' => 'gold', 'icon' => 'shield', 'title' => ($p['kind'] === 'consent' ? 'Please read: ' : 'Please sign: ') . $p['title'],
                'text' => $p['kind'] === 'consent' ? 'Two minutes to read. Sign it only if you agree.' : 'Two minutes to read', 'href' => '#/me/policies/' . $p['id']];
        }
        foreach (Attendance::fixable($e) as $n) {
            $out[] = ['tone' => 'warn', 'icon' => 'clock', 'title' => 'Punch-out missing on ' . Fmt::day($n['day']),
                'text' => 'Tell HR when you left. It takes a few seconds', 'href' => '#/me/fix/' . $n['ref']];
        }
        foreach (Kernel::hr()->table('hr_requests')->where('employee_id', $e->id)->where('status', 'sent_back')
            ->where('decided_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))->orderByDesc('decided_at')->limit(3)->get() as $r) {
            $out[] = ['tone' => 'bad', 'icon' => 'alert', 'title' => 'Sent back: ' . Approvals::TYPE_LABELS[$r->type],
                'text' => $r->decision_note ?: 'Open to see why', 'href' => '#/me/requests'];
        }
        // A note about a missed punch-out is already covered by the "Punch-out missing" line above.
        foreach (Kernel::hr()->table('hr_threads')->where('employee_id', $e->id)->whereColumn('staff_read_upto', '<', 'last_message_id')
            ->where(fn($q) => $q->whereNull('action_ref')->orWhereNotNull('request_id'))
            ->orderByDesc('last_at')->limit(3)->get(['id', 'subject', 'last_from']) as $t) {
            $out[] = ['tone' => 'gold', 'icon' => 'mail', 'title' => ($t->last_from === 'system' ? 'Update: ' : 'HR replied: ') . $t->subject,
                'text' => 'Open to read it', 'href' => '#/me/mail/' . $t->id];
        }
        foreach (array_slice(array_filter(Announcements::forEmployee((int) $e->id), fn($a) => $a['unread']), 0, 2) as $a) {
            $out[] = ['tone' => 'info', 'icon' => 'speaker', 'title' => $a['title'], 'text' => 'Announcement from HR', 'href' => '#/me/mail/a' . $a['id']];
        }
        $hasBank = Kernel::hr()->table('hr_employee_secrets')->where('employee_id', $e->id)->where('field', 'bank_account')->exists();
        $bankAsked = Kernel::hr()->table('hr_requests')->where('employee_id', $e->id)->where('type', 'bank_change')->where('status', 'pending')->exists();
        if (!$hasBank && !$bankAsked) {
            $out[] = ['tone' => 'gold', 'icon' => 'wallet', 'title' => 'Add your bank account', 'text' => 'So your salary can be paid', 'href' => '#/me/bank'];
        }
        if (!$e->emergency_phone) {
            $out[] = ['tone' => 'gold', 'icon' => 'users', 'title' => 'Add an emergency contact', 'text' => 'Someone we can call if needed', 'href' => '#/me/profile'];
        }
        return $out;
    }

    public static function requests(Ctx $c, int $limit = 50): array
    {
        $e = self::employee($c);
        return Kernel::hr()->table('hr_requests')->where('employee_id', $e->id)->orderByDesc('created_at')->limit($limit)->get()
            ->map(fn($r) => [
                'id' => (int) $r->id, 'type' => $r->type, 'type_label' => Approvals::TYPE_LABELS[$r->type] ?? $r->type,
                'summary' => $r->summary, 'status' => $r->status, 'status_label' => Approvals::STATUS_LABELS[$r->status] ?? $r->status,
                'note' => $r->decision_note, 'created_at' => $r->created_at, 'decided_at' => $r->decided_at,
            ])->all();
    }

    public static function withdraw(Ctx $c, int $id): array
    {
        $e = self::employee($c);
        $r = Kernel::hr()->table('hr_requests')->where('id', $id)->where('employee_id', $e->id)->first();
        $n = Kernel::hr()->table('hr_requests')->where('id', $id)->where('employee_id', $e->id)->where('status', 'pending')
            ->update(['status' => 'withdrawn', 'updated_at' => Kernel::now()]);
        if (!$n) {
            throw ApiError::conflict('That request has already been decided, so it can\'t be withdrawn.');
        }
        if ($r->type === 'document') {
            $docId = (int) (json_decode((string) $r->payload, true)['document_id'] ?? 0);
            Kernel::hr()->table('hr_documents')->where('id', $docId)->where('employee_id', $e->id)->where('status', 'pending')
                ->whereNull('deleted_at')->update(['deleted_at' => Kernel::now()]);
        }
        Audit::log('request.withdraw', ['employee_id' => (int) $e->id, 'entity' => 'hr_requests', 'entity_id' => $id]);
        return ['message' => 'Request withdrawn.'];
    }

    // ---- attendance -------------------------------------------------------------

    public static function attendance(Ctx $c): array
    {
        return Attendance::myMonth($c);
    }

    /** One missed punch-out, for the fix screen ("p12" = an HR punch, "s345" = a CRM night). */
    public static function night(Ctx $c, string $ref): array
    {
        $e = self::employee($c);
        $i = Attendance::openItem($e, $ref);
        return [
            'ref' => $i['ref'], 'kind' => $i['kind'], 'day' => $i['day'], 'in' => $i['in'], 'break_mins' => $i['break_mins'],
            'open_now' => $i['open_now'], 'last_seen' => Attendance::lastSeen($e, $i['in']),
            'fixable' => in_array($ref, array_column(Attendance::fixable($e), 'ref'), true),
            'fix_days' => (int) Settings::get('fix_window_days'),
        ];
    }

    /** "I left at 03:10": files an attendance fix for approval. From a Trio HR note, the answer joins that conversation. */
    public static function fixNight(Ctx $c): array
    {
        $e = self::employee($c);
        $ref = $c->has('night') ? (string) $c->str('night', 24, true, 'The day') : 's' . (int) $c->intVal('session_id', 1, PHP_INT_MAX, true, 'The night');
        $time = (string) $c->str('time', 5, true, 'The time');
        $note = $c->text('note', 500);
        $threadId = $c->intVal('thread', 1, PHP_INT_MAX);
        $thread = $threadId ? Kernel::hr()->table('hr_threads')->where('id', $threadId)->where('employee_id', $e->id)->where('action_ref', $ref)->first() : null;
        $id = self::fileFix($e, $ref, $time, $note, $thread === null);
        if ($thread) {
            Mail::answerAction($thread, $e, $id, $note);
        }
        return ['id' => $id, 'message' => "Sent for approval. You'll see the answer on Home."];
    }

    /** File an attendance fix for a missed punch-out. Returns the request id. */
    public static function fileFix(object $e, string $ref, string $time, ?string $note, bool $notify = true): int
    {
        $i = Attendance::openItem($e, $ref);
        if (!in_array($ref, array_column(Attendance::fixable($e), 'ref'), true)) {
            $window = (int) Settings::get('fix_window_days');
            if ($i['open_now']) {
                throw ApiError::conflict("You're still " . ($i['kind'] === 'crm' ? 'clocked in' : 'punched in') . '. ' . ($i['kind'] === 'crm' ? 'Clock out on the CRM' : 'Punch out') . ' when you leave instead.');
            }
            if (Clock::daysBetween($i['day'], Attendance::today()) > $window) {
                throw ApiError::invalid('Days older than ' . $window . ' days need your team leader to raise it with HR.');
            }
            throw ApiError::conflict('You already asked to fix that day, or it is locked for payroll.');
        }
        $out = Attendance::outTime($i['in'], $time);
        $now = Kernel::now();
        $payload = ['day' => $i['day'], 'clock_out' => $out, 'kind' => 'missed_clock_out'] + ($i['kind'] === 'hr' ? ['punch_id' => $i['id']] : ['session_id' => $i['id']]);
        $id = Kernel::hr()->table('hr_requests')->insertGetId([
            'employee_id' => $e->id, 'type' => 'attendance_fix', 'status' => 'pending',
            'summary' => 'Punch-out on ' . Fmt::day($i['day']) . ' · ' . Fmt::time($out),
            'payload' => json_encode($payload), 'note' => $note, 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::filed($e, (int) $id, Fmt::first($e->full_name) . ' told HR when they left on ' . Fmt::day($i['day']), $notify);
        return (int) $id;
    }

    // ---- leave ------------------------------------------------------------------------

    public static function leave(Ctx $c): array
    {
        $e = self::employee($c);
        $types = Leave::types();
        $mine = Kernel::hr()->table('hr_requests')->where('employee_id', $e->id)->where('type', 'leave')->orderByDesc('created_at')->limit(30)->get()
            ->map(fn($r) => [
                'id' => (int) $r->id, 'summary' => $r->summary, 'status' => $r->status,
                'status_label' => Approvals::STATUS_LABELS[$r->status] ?? $r->status, 'note' => $r->decision_note, 'created_at' => $r->created_at,
            ])->all();
        $today = Attendance::today();
        $holidays = [];
        foreach (Attendance::holidaysFor($e->centre, $today, date('Y-m-d', strtotime($today . ' +120 days'))) as $d => $name) {
            $holidays[] = ['day' => $d, 'name' => $name];
        }
        return ['balances' => Leave::balances((int) $e->id), 'types' => $types, 'requests' => $mine, 'today' => Clock::shiftDay(), 'holidays' => array_slice($holidays, 0, 6),
            'first_night' => Leave::firstNight(), 'sick_from' => Leave::sickFrom()];
    }

    public static function applyLeave(Ctx $c): array
    {
        $e = self::employee($c);
        $type = $c->str('leave_type', 20, true, 'Kind of leave');
        $from = $c->date('from', true, 'First night');
        $to = $c->date('to', false, 'Last night') ?? $from;
        $id = self::fileLeave($e, (string) $type, (string) $from, (string) $to, $c->text('note', 500));
        return ['id' => $id, 'message' => 'Sent for approval. You\'ll be told as soon as it\'s decided.'];
    }

    /** File a leave request. Returns the request id. */
    public static function fileLeave(object $e, string $type, string $from, string $to, ?string $note, bool $notify = true): int
    {
        $res = Leave::apply((int) $e->id, $type, $from, $to, $note);
        self::filed($e, (int) $res['id'], Fmt::first($e->full_name) . ' asked for ' . Fmt::plural($res['nights'], 'night') . ' of ' . Fmt::lower(Leave::typeNames()[$type] ?? 'leave'), $notify);
        return (int) $res['id'];
    }

    // ---- letters -----------------------------------------------------------------------

    /** Ask for a letter such as a salary certificate. An admin approves, then it is prepared and issued. */
    public static function requestLetter(Ctx $c): array
    {
        $e = self::employee($c);
        $code = (string) $c->str('template_code', 40, true, 'Kind of letter');
        $purpose = (string) $c->str('purpose', 120, true, 'What it is for');
        $id = self::fileLetter($e, $code, $purpose);
        return ['id' => $id, 'message' => 'Sent for approval. Once it is issued you will find it under More → My letters.'];
    }

    /** File a letter request. Returns the request id. */
    public static function fileLetter(object $e, string $code, string $purpose, bool $notify = true): int
    {
        $t = Kernel::hr()->table('hr_letter_templates')->where('code', $code)->where('staff_can_request', 1)->first();
        if (!$t) {
            throw ApiError::invalid('Please pick a kind of letter.', ['template_code' => 'Pick a kind of letter.']);
        }
        $waiting = Kernel::hr()->table('hr_requests')->where('employee_id', $e->id)->where('type', 'letter')->where('status', 'pending')->count();
        if ($waiting >= 3) {
            throw ApiError::conflict('You already have letters waiting. Please wait for those first.');
        }
        $now = Kernel::now();
        $id = Kernel::hr()->table('hr_requests')->insertGetId([
            'employee_id' => $e->id, 'type' => 'letter', 'status' => 'pending',
            'summary' => $t->name . ' · for ' . $purpose,
            'payload' => json_encode(['template_code' => $t->code, 'purpose' => $purpose]), 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::filed($e, (int) $id, Fmt::first($e->full_name) . ' asked for ' . Fmt::an(Fmt::lower($t->name)), $notify);
        return (int) $id;
    }

    // ---- profile ----------------------------------------------------------------------

    public static function profile(Ctx $c): array
    {
        $e = self::employee($c);
        $fields = [];
        foreach (Approvals::SELF_FIELDS as $f) {
            $fields[$f] = $e->$f;
        }
        $secrets = Kernel::hr()->table('hr_employee_secrets')->where('employee_id', $e->id)->pluck('hint', 'field')->all();
        $pending = Kernel::hr()->table('hr_requests')->where('employee_id', $e->id)->whereIn('type', ['profile_change', 'bank_change'])
            ->where('status', 'pending')->get(['id', 'type', 'summary', 'created_at'])->map(fn($r) => (array) $r)->all();
        return [
            'fields' => $fields, 'labels' => array_intersect_key(People::FIELDS, array_flip(Approvals::SELF_FIELDS)),
            'genders' => People::GENDER_LABELS,
            'job' => [
                'code' => $e->employee_code, 'designation' => $e->designation, 'team' => $e->team,
                'centre' => Settings::centreName($e->centre), 'joined' => $e->date_joined, 'tenure' => Fmt::tenure($e->date_joined),
                'type' => People::TYPE_LABELS[$e->employment_type] ?? $e->employment_type,
                'team_leader' => $e->reports_to_id ? Kernel::hr()->table('hr_employees')->where('id', $e->reports_to_id)->value('full_name') : null,
            ],
            'bank' => isset($secrets['bank_account']) ? ['account' => '••' . $secrets['bank_account'], 'ifsc' => isset($secrets['bank_ifsc']) ? $secrets['bank_ifsc'] . '…' : null] : null,
            'pan' => isset($secrets['pan']) ? '••••••' . $secrets['pan'] : null,
            'pending' => $pending,
            'cooling_days' => (int) Settings::get('bank_cooling_days'),
        ];
    }

    public static function requestProfileChange(Ctx $c): array
    {
        $e = self::employee($c);
        $changes = [];
        $errors = [];
        foreach (Approvals::SELF_FIELDS as $f) {
            if (!$c->has($f)) {
                continue;
            }
            try {
                $v = match ($f) {
                    'personal_email' => $c->email($f, false, 'Email'),
                    'phone', 'emergency_phone' => $c->phone($f, People::FIELDS[$f]),
                    'date_of_birth' => $c->date($f, false, 'Date of birth'),
                    'gender' => $c->pick($f, array_keys(People::GENDER_LABELS)),
                    'address' => $c->text($f, 500),
                    'full_name' => $c->str($f, 120, true, 'Full name'),
                    'preferred_name' => $c->str($f, 60),
                    'emergency_relation' => $c->str($f, 40),
                    default => $c->str($f, 120),
                };
            } catch (ApiError $err) {
                $errors += $err->fields ?: [$f => $err->getMessage()];
                continue;
            }
            if ((string) $v !== (string) $e->$f) {
                $changes[$f] = ['from' => $e->$f, 'to' => $v];
            }
        }
        if ($errors) {
            throw ApiError::invalid('Please check the highlighted fields.', $errors);
        }
        if (!$changes) {
            throw ApiError::invalid('Nothing has changed.');
        }
        if (Kernel::hr()->table('hr_requests')->where('employee_id', $e->id)->where('type', 'profile_change')->where('status', 'pending')->exists()) {
            throw ApiError::conflict('You already have a profile change waiting. Withdraw it first, or wait for the answer.');
        }
        $now = Kernel::now();
        $id = Kernel::hr()->table('hr_requests')->insertGetId([
            'employee_id' => $e->id, 'type' => 'profile_change', 'status' => 'pending',
            'summary' => 'Change ' . implode(', ', array_map(fn($f) => Fmt::lower(People::FIELDS[$f]), array_keys($changes))),
            'payload' => json_encode(['changes' => $changes]), 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::filed($e, (int) $id, Fmt::first($e->full_name) . ' asked to change their ' . implode(', ', array_map(fn($f) => Fmt::lower(People::FIELDS[$f]), array_keys($changes))));
        return ['id' => $id, 'message' => 'Sent for approval. Your details change once an admin approves.'];
    }

    public static function requestBankChange(Ctx $c): array
    {
        $e = self::employee($c);
        self::needsPasswordSession($c, 'change your bank account');
        foreach (['bank_holder' => 'Name on the account', 'bank_account' => 'Account number', 'bank_ifsc' => 'IFSC', 'bank_name' => 'Bank'] as $f => $label) {
            $c->str($f, 60, true, $label);
        }
        $v = Privacy::read($c);
        if (($c->str('bank_account_confirm', 30) ?? '') !== '' && preg_replace('/\D/', '', (string) $c->str('bank_account_confirm', 30)) !== $v['bank_account']) {
            throw ApiError::invalid("The account numbers don't match.", ['bank_account_confirm' => "This doesn't match the account number."]);
        }
        if (Kernel::hr()->table('hr_requests')->where('employee_id', $e->id)->where('type', 'bank_change')->where('status', 'pending')->exists()) {
            throw ApiError::conflict('You already have a bank change waiting for approval.');
        }
        $now = Kernel::now();
        $id = Kernel::hr()->table('hr_requests')->insertGetId([
            'employee_id' => $e->id, 'type' => 'bank_change', 'status' => 'pending',
            'summary' => 'New bank account · ' . $v['bank_name'] . ' ••' . substr($v['bank_account'], -4),
            // The account number is encrypted even while it waits for approval.
            'payload' => json_encode([
                'secrets' => ['bank_account' => base64_encode(Vault::encrypt($v['bank_account']))],
                'hints' => ['bank_account' => substr($v['bank_account'], -4)],
                'bank_ifsc' => $v['bank_ifsc'], 'bank_name' => $v['bank_name'], 'bank_holder' => $v['bank_holder'],
            ]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        self::filed($e, (int) $id, Fmt::first($e->full_name) . ' asked to change their bank account');
        return ['id' => $id, 'message' => 'Sent for approval. Your salary keeps going to your current account until then.'];
    }
}
