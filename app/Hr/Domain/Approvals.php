<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Clock;
use App\Hr\Support\Fmt;
use App\Hr\Support\Notify;
use App\Hr\Support\Outbox;
use App\Hr\Support\Vault;

/**
 * One inbox for every staff request. Each request carries the context needed
 * to decide in seconds (checks, coverage, history), and nothing reaches anyone's
 * record or pay until it is approved here.
 */
final class Approvals
{
    public const TYPE_LABELS = [
        'leave' => 'Leave', 'attendance_fix' => 'Attendance fix', 'profile_change' => 'Profile change',
        'bank_change' => 'Bank details', 'letter' => 'Letter', 'document' => 'Document',
    ];
    public const STATUS_LABELS = [
        'pending' => 'Waiting', 'approved' => 'Approved', 'partly_approved' => 'Partly approved',
        'sent_back' => 'Sent back', 'withdrawn' => 'Withdrawn',
    ];
    private const VIEWS = [
        'all' => ['label' => 'All', 'types' => null],
        'leave' => ['label' => 'Leave', 'types' => ['leave']],
        'attendance' => ['label' => 'Attendance', 'types' => ['attendance_fix']],
        'profile' => ['label' => 'Profile & bank', 'types' => ['profile_change', 'bank_change']],
        'letters' => ['label' => 'Letters & documents', 'types' => ['letter', 'document']],
    ];
    private const REASONS = [
        'leave' => ['Coverage is too low that night', 'Please pick other dates', 'Not enough leave left', 'Talk to me first'],
        'attendance_fix' => ["That time doesn't match our records", 'Please add what happened', 'Talk to me first'],
        'profile_change' => ['Please share proof of the change', "That doesn't look right", 'Talk to me first'],
        'bank_change' => ["The name doesn't match the account", 'Please share a cancelled cheque', 'Talk to me first'],
        'letter' => ['Please tell me what it is for', 'Talk to me first'],
        'document' => ["The photo isn't clear enough", "That isn't the right document", 'Please upload all pages', 'Talk to me first'],
    ];
    /** Contact fields: changes to only these are routine. */
    private const CONTACT_FIELDS = ['phone', 'personal_email', 'address', 'emergency_name', 'emergency_relation', 'emergency_phone', 'preferred_name'];
    /** Fields staff may ask to change about themselves. */
    public const SELF_FIELDS = ['full_name', 'preferred_name', 'personal_email', 'phone', 'date_of_birth', 'gender', 'address', 'emergency_name', 'emergency_relation', 'emergency_phone'];

    // ---- the list ---------------------------------------------------------------

    public static function counts(): array
    {
        $byType = Kernel::hr()->table('hr_requests')->where('status', 'pending')->groupBy('type')
            ->selectRaw('type, COUNT(*) as n')->pluck('n', 'type')->all();
        $out = [];
        foreach (self::VIEWS as $key => $v) {
            $out[$key] = $v['types'] === null ? (int) array_sum($byType) : (int) array_sum(array_intersect_key($byType, array_flip($v['types'])));
        }
        return $out;
    }

    public static function list(?string $view): array
    {
        $view = isset(self::VIEWS[$view ?? '']) ? $view : 'all';
        $q = Kernel::hr()->table('hr_requests as r')->join('hr_employees as e', 'e.id', '=', 'r.employee_id')
            ->where('r.status', 'pending')->orderBy('r.created_at')->limit(300)
            ->select('r.*', 'e.full_name', 'e.designation', 'e.centre');
        if (self::VIEWS[$view]['types']) {
            $q->whereIn('r.type', self::VIEWS[$view]['types']);
        }
        $stale = (int) Settings::get('stale_request_days');
        $items = [];
        foreach ($q->get() as $r) {
            $age = Clock::daysBetween(substr($r->created_at, 0, 10), date('Y-m-d'));
            $items[] = [
                'id' => (int) $r->id, 'type' => $r->type, 'type_label' => self::TYPE_LABELS[$r->type] ?? $r->type,
                'person' => ['id' => (int) $r->employee_id, 'name' => $r->full_name, 'initials' => Fmt::initials($r->full_name), 'designation' => $r->designation, 'centre_name' => Settings::centreName($r->centre)],
                'summary' => $r->summary, 'created_at' => $r->created_at, 'age_days' => $age,
                'tone' => $age >= 2 * $stale ? 'bad' : ($age >= $stale ? 'warn' : null),
                'routine' => self::isRoutine($r),
                'flag' => $r->type === 'bank_change' ? 'Check before payroll' : null,
            ];
        }
        $views = [];
        $counts = self::counts();
        foreach (self::VIEWS as $key => $v) {
            $views[] = ['key' => $key, 'label' => $v['label'], 'count' => $counts[$key]];
        }
        return ['view' => $view, 'views' => $views, 'items' => $items, 'routine' => count(array_filter($items, fn($i) => $i['routine']))];
    }

    private static function isRoutine(object $r): bool
    {
        if ($r->type === 'profile_change') {
            $fields = array_keys((json_decode($r->payload, true)['changes'] ?? []));
            return $fields && !array_diff($fields, self::CONTACT_FIELDS);
        }
        if ($r->type === 'leave') {
            foreach (self::leaveChecks($r) as $check) {
                if ($check['tone'] !== 'ok' && $check['tone'] !== 'info') {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    // ---- one request ------------------------------------------------------------

    private static function load(int $id): object
    {
        $r = Kernel::hr()->table('hr_requests')->where('id', $id)->first();
        if (!$r) {
            throw ApiError::notFound('That request');
        }
        return $r;
    }

    public static function detail(int $id): array
    {
        $r = self::load($id);
        $db = Kernel::hr();
        $e = $db->table('hr_employees')->where('id', $r->employee_id)->first();
        $tl = $e && $e->reports_to_id ? $db->table('hr_employees')->where('id', $e->reports_to_id)->value('full_name') : null;
        $p = json_decode($r->payload, true) ?: [];

        $out = [
            'request' => [
                'id' => (int) $r->id, 'type' => $r->type, 'type_label' => self::TYPE_LABELS[$r->type] ?? $r->type,
                'status' => $r->status, 'status_label' => self::STATUS_LABELS[$r->status] ?? $r->status,
                'summary' => $r->summary, 'note' => $r->note, 'created_at' => $r->created_at,
                'decided_at' => $r->decided_at, 'decision_note' => $r->decision_note,
                'decided_by' => $r->decided_by ? $db->table('hr_accounts')->where('id', $r->decided_by)->value('display_name') : null,
            ],
            'person' => [
                'id' => (int) $e->id, 'name' => $e->full_name, 'first' => Fmt::first($e->preferred_name ?: $e->full_name),
                'initials' => Fmt::initials($e->full_name), 'designation' => $e->designation,
                'centre_name' => Settings::centreName($e->centre), 'team_leader' => $tl, 'tenure' => Fmt::tenure($e->date_joined),
            ],
            'headline' => $r->summary, 'checks' => [], 'facts' => [], 'diff' => [], 'days' => [],
            'reasons' => self::REASONS[$r->type] ?? ['Talk to me first'],
            'approve_label' => 'Approve',
        ];

        switch ($r->type) {
            case 'leave':
                $days = $p['days'] ?? [];
                $type = Leave::typeNames()[$p['leave_type'] ?? ''] ?? 'Leave';
                $out['headline'] = $type . ' for ' . self::daysInWords($days) . ' (' . Fmt::plural(count($days), 'night') . ')';
                $out['checks'] = self::leaveChecks($r);
                $out['days'] = self::coverage($r);
                $out['facts'] = self::leaveFacts($r, $e);
                $out['approve_label'] = count($days) > 1 ? 'Approve ' . Fmt::plural(count($days), 'night') : 'Approve';
                break;
            case 'attendance_fix':
                [$out['headline'], $out['checks'], $out['facts']] = self::fixDetail($r, $e, $p);
                $out['approve_label'] = 'Approve the fix';
                break;
            case 'profile_change':
                $out['headline'] = 'Change ' . implode(', ', array_map(fn($f) => Fmt::lower(People::FIELDS[$f] ?? $f), array_keys($p['changes'] ?? [])));
                foreach ($p['changes'] ?? [] as $field => $ch) {
                    $now = $e->$field ?? null;
                    $out['diff'][] = [
                        'label' => People::FIELDS[$field] ?? $field,
                        'from' => People::show($field, $ch['from'] ?? null), 'to' => People::show($field, $ch['to'] ?? null),
                        'changed_since' => (string) $now !== (string) ($ch['from'] ?? null),
                    ];
                }
                $out['checks'] = self::profileChecks($p);
                $out['approve_label'] = 'Approve the change';
                break;
            case 'bank_change':
                [$out['headline'], $out['checks'], $out['facts']] = self::bankDetail($r, $e, $p);
                $out['approve_label'] = 'Approve new bank details';
                break;
            case 'letter':
                $t = Kernel::hr()->table('hr_letter_templates')->where('code', $p['template_code'] ?? '')->first();
                $defs = $t ? (json_decode((string) $t->fields, true) ?: []) : [];
                $toFill = array_values(array_filter($defs, fn($f) => !empty($f['required']) && ($f['from'] ?? '') !== 'request.purpose' && empty($f['default'])));
                $out['headline'] = ($t->name ?? 'Letter') . ($p['purpose'] ?? null ? ' · for ' . $p['purpose'] : '');
                $out['checks'] = [
                    $t ? ['tone' => 'ok', 'title' => 'A letter staff can ask for', 'text' => $t->name . ' is filled from ' . Fmt::first($e->full_name) . "'s record."]
                       : ['tone' => 'bad', 'title' => 'Unknown letter', 'text' => 'This kind of letter no longer exists.'],
                    $toFill ? ['tone' => 'info', 'title' => 'You add a detail', 'text' => 'Before it is issued, add: ' . implode(', ', array_map(fn($f) => Fmt::lower($f['label']), $toFill)) . '.']
                            : ['tone' => 'ok', 'title' => 'Nothing to fill in', 'text' => 'It can go straight to a second admin to check and issue.'],
                    ['tone' => 'info', 'title' => 'Second check', 'text' => Settings::security('letters_need_check') ? 'Another admin checks the letter before it is issued.' : 'Letters are issued without a second check (switched off in Settings).'],
                ];
                $out['approve_label'] = 'Approve and prepare the letter';
                break;
            case 'document':
                $d = Kernel::hr()->table('hr_documents')->where('id', $p['document_id'] ?? 0)->whereNull('deleted_at')->first();
                $out['headline'] = $d ? (Documents::KINDS[$d->kind] ?? 'Document') . ($d->title !== (Documents::KINDS[$d->kind] ?? '') ? ' · ' . $d->title : '') : 'Document';
                $out['document'] = $d ? ['id' => (int) $d->id, 'mime' => $d->mime, 'name' => $d->file_name] : null;
                $out['checks'] = $d ? [
                    ['tone' => 'ok', 'title' => 'A real ' . ($d->mime === 'application/pdf' ? 'PDF' : 'photo'), 'text' => 'Checked by its content, not its name. ' . number_format($d->size / 1024) . ' KB.'],
                    ['tone' => 'info', 'title' => 'Look at it', 'text' => 'Open the file and check it is clear, complete and belongs to ' . Fmt::first($e->full_name) . '. Opening it is recorded.'],
                ] : [['tone' => 'bad', 'title' => 'File missing', 'text' => 'The uploaded file is no longer there.']];
                $out['facts'] = $d ? [['label' => 'File', 'value' => $d->file_name], ['label' => 'Uploaded', 'value' => Fmt::day($d->created_at) . ', ' . Fmt::time($d->created_at)]] : [];
                $out['approve_label'] = 'Mark as verified';
                break;
        }
        return $out;
    }

    private static function daysInWords(array $days): string
    {
        $n = count($days);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return date('l j F', strtotime($days[0]));
        }
        if ($n === 2) {
            return date('D j', strtotime($days[0])) . ' and ' . date('D j F', strtotime($days[1]));
        }
        return date('D j M', strtotime($days[0])) . ' to ' . date('D j M', strtotime($days[$n - 1]));
    }

    // ---- leave ------------------------------------------------------------------

    private static function leaveChecks(object $r): array
    {
        $p = json_decode($r->payload, true) ?: [];
        $days = $p['days'] ?? [];
        $code = $p['leave_type'] ?? '';
        $types = collect(Leave::types())->keyBy('code');
        $type = $types[$code] ?? null;
        $checks = [];
        $n = count($days);

        // Balance
        if ($type && $type['needs_balance']) {
            // Each year's nights come out of that year's balance (29 Dec – 3 Jan touches two).
            $years = Leave::byYear($days ?: [date('Y-m-d')]);
            $parts = [];
            $ok = true;
            $others = [];
            foreach ($years as $year => $inYear) {
                $bal = collect(Leave::balances((int) $r->employee_id, (int) $year))->keyBy('code');
                $b = $bal[$code] ?? ['available' => 0, 'pending' => 0];
                $mine = $r->status === 'pending' ? count($inYear) : 0;
                $otherPending = max(0, $b['pending'] - $mine);
                $after = $b['available'] - $otherPending - $mine;
                $ok = $ok && $after >= 0;
                $parts[] = (count($years) > 1 ? $year . ': ' : '') . self::num($b['available'] - $otherPending) . ' → ' . self::num($after)
                    . ($otherPending ? ' (another ' . Fmt::plural($otherPending, 'night') . ' already waiting)' : '');
                if (!$others) {
                    foreach ($bal as $c => $x) {
                        if ($c !== $code && $x['total'] > 0) {
                            $others[] = $x['name'] . ' ' . self::num($x['available']);
                        }
                    }
                }
            }
            $checks[] = [
                'tone' => $ok ? 'ok' : 'bad',
                'title' => $ok ? 'Within balance' : 'More than the balance',
                'text' => $type['name'] . ' ' . implode(' · ', $parts) . ' after this' . ($others ? ' · ' . implode(' · ', $others) : ''),
            ];
        } elseif ($type) {
            $checks[] = ['tone' => 'info', 'title' => 'Unpaid', 'text' => Fmt::plural($n, 'night') . ' will be unpaid. No balance is used.'];
        }

        // Notice
        if ($type && $days) {
            $ahead = Clock::daysBetween(Clock::shiftDay(strtotime($r->created_at)), $days[0]);
            if ($code === 'sick') {
                $checks[] = ['tone' => 'info', 'title' => 'Sick leave', 'text' => "Sick leave doesn't need notice." . ($ahead < 0 ? ' Asked ' . Fmt::plural(-$ahead, 'day') . ' after the night.' : '')];
            } else {
                $need = $type['min_notice_days'];
                $checks[] = $ahead >= $need
                    ? ['tone' => 'ok', 'title' => 'Enough notice', 'text' => Fmt::plural($ahead, 'day') . ' ahead · policy asks for ' . $need]
                    : ['tone' => 'warn', 'title' => 'Short notice', 'text' => 'Asked ' . ($ahead <= 0 ? 'on the night' : Fmt::plural($ahead, 'day') . ' ahead') . ' · policy asks for ' . $need];
            }
        }

        // Coverage: the floor's headcount (people whose punch is the CRM clock-in) minus approved leave.
        $cov = self::coverage($r);
        if ($cov) {
            $short = array_filter($cov, fn($d) => !$d['ok']);
            $min = (int) Settings::get('min_floor');
            if ($short) {
                $checks[] = ['tone' => 'warn', 'title' => 'Coverage those nights', 'text' => 'Below your minimum of ' . $min . ' on the floor on ' . implode(', ', array_map(fn($d) => Fmt::day($d['day']), $short)) . '.', 'coverage' => true];
            } else {
                $busy = array_filter($cov, fn($d) => $d['others']);
                $checks[] = ['tone' => 'ok', 'title' => 'Coverage those nights', 'text' => ($busy ? 'Others are off too, but the floor stays' : 'The floor stays') . ' at or above your minimum of ' . $min . '.', 'coverage' => true];
            }
        }
        return $checks;
    }

    /**
     * Per night: the floor's headcount, who else is off, and what's left after
     * this request. The floor is everyone whose punch is the CRM clock-in; no
     * roster or shift is assumed (John, 16 Oct). Not shown for people who
     * aren't on the floor.
     */
    private static function coverage(object $r): array
    {
        $p = json_decode($r->payload, true) ?: [];
        $days = $p['days'] ?? [];
        if (!$days) {
            return [];
        }
        $people = Attendance::people(min($days), max($days));
        $me = $people[(int) $r->employee_id] ?? null;
        if (!$me || $me->via !== 'crm') {
            return [];
        }
        $floorIds = [];
        foreach ($people as $e) {
            if ($e->via === 'crm') {
                $floorIds[(int) $e->crm_user_id] = true;
            }
        }
        $others = Leave::othersOff($days, (int) $r->employee_id);
        $min = (int) Settings::get('min_floor');
        $out = [];
        foreach ($days as $d) {
            $floor = count(array_filter($people, fn($e) => $e->via === 'crm' && in_array($e->status, ['active', 'notice'], true)
                && (!$e->date_joined || $e->date_joined <= $d) && (!$e->last_working_day || $e->last_working_day >= $d)));
            $off = count(array_filter($others[$d] ?? [], fn($o) => $o['approved'] && $o['crm_user_id'] && isset($floorIds[(int) $o['crm_user_id']])));
            $after = $floor - $off - 1;
            $out[] = [
                'day' => $d, 'floor' => $floor, 'after' => max(0, $after), 'min' => $min, 'ok' => $after >= $min,
                'others' => array_map(fn($o) => $o['name'] . ' (' . Fmt::lower($o['kind']) . ')', $others[$d] ?? []),
            ];
        }
        return $out;
    }

    private static function leaveFacts(object $r, object $e): array
    {
        $db = Kernel::hr();
        $p = json_decode($r->payload, true) ?: [];
        $last = $db->table('hr_requests')->where('employee_id', $e->id)->where('type', 'leave')
            ->whereIn('status', ['approved', 'partly_approved'])->where('id', '!=', $r->id)->orderByDesc('decided_at')->first(['summary', 'status']);
        $year = substr(Clock::shiftDay(), 0, 4);
        $taken = $db->table('hr_leave_days')->where('employee_id', $e->id)->where('shift_day', 'like', $year . '%')->count();
        $sentBack = $db->table('hr_requests')->where('employee_id', $e->id)->where('type', 'leave')->where('status', 'sent_back')
            ->where('created_at', '>=', $year . '-01-01')->count();
        $paid = collect(Leave::types())->firstWhere('code', $p['leave_type'] ?? '')['paid'] ?? true;
        return [
            ['label' => 'Last leave', 'value' => $last ? self::trimType($last->summary) . ' · ' . Fmt::lower(self::STATUS_LABELS[$last->status]) : 'None this year'],
            ['label' => 'This year', 'value' => Fmt::plural($taken, 'night') . ' of leave taken' . ($sentBack ? ' · ' . $sentBack . ' sent back' : '')],
            ['label' => 'Pay effect', 'value' => $paid ? 'None. Paid leave' : Fmt::plural(count($p['days'] ?? []), 'unpaid night')],
            ['label' => 'Asked', 'value' => Fmt::day($r->created_at) . ', ' . Fmt::time($r->created_at)],
        ];
    }

    private static function trimType(string $summary): string
    {
        $parts = explode(' · ', $summary);
        return count($parts) > 1 ? implode(' · ', array_slice($parts, 1)) : $summary;
    }

    private static function num(float $n): string
    {
        return (float) (int) $n === $n ? (string) (int) $n : number_format($n, 1);
    }

    // ---- attendance fix -----------------------------------------------------------

    private static function fixDetail(object $r, object $e, array $p): array
    {
        $out = $p['clock_out'] ?? null;
        $day = $p['day'] ?? null;
        $headline = 'Punch-out on ' . Fmt::day($day) . ' at ' . Fmt::time($out);
        $checks = [];
        $facts = [];
        $breaks = 0;
        if (!empty($p['punch_id'])) {
            $pu = Kernel::hr()->table('hr_punches')->where('id', (int) $p['punch_id'])->where('employee_id', $e->id)->first();
            if (!$pu) {
                $checks[] = ['tone' => 'bad', 'title' => 'Punch not found', 'text' => 'The punch this refers to is no longer in Trio HR.'];
                return [$headline, $checks, $facts];
            }
            $in = (string) $pu->punch_in;
            $checks[] = $pu->punch_out === null
                ? ['tone' => 'ok', 'title' => 'No punch-out was recorded', 'text' => 'They punched in on Trio HR at ' . Fmt::time($in) . ' and never punched out.']
                : ['tone' => 'warn', 'title' => 'Already has a punch-out', 'text' => 'Recorded as ' . Fmt::time($pu->punch_out) . '. Send this back.'];
            $source = 'Trio HR punch button';
        } else {
            $s = !empty($p['session_id']) ? Kernel::crm()->table('attendance_sessions')->where('id', (int) $p['session_id'])->first() : null;
            if (!$s || (int) $s->user_id !== (int) $e->crm_user_id) {
                $checks[] = ['tone' => 'bad', 'title' => 'CRM record not found', 'text' => 'The day this refers to is no longer in the CRM.'];
                return [$headline, $checks, $facts];
            }
            $in = (string) $s->clock_in;
            $breaks = (int) $s->total_break_mins;
            $checks[] = match (true) {
                $s->status === 'auto_closed' => ['tone' => 'ok', 'title' => 'The CRM closed this day by itself', 'text' => 'No clock-out was recorded, so Trio HR counts no hours until this is decided.'],
                (bool) $s->resolution_required => ['tone' => 'ok', 'title' => 'Flagged for review in the CRM', 'text' => 'The CRM marked this day as needing a decision.'],
                $s->status === 'active' => ['tone' => 'warn', 'title' => 'Still open in the CRM', 'text' => 'This day has no clock-out yet. Approving records the time in Trio HR.'],
                default => ['tone' => 'warn', 'title' => 'The CRM already has a clock-out', 'text' => 'Recorded as ' . Fmt::time($s->clock_out) . '. Approving replaces it for payroll only.'],
            };
            $source = 'CRM clock-in';
        }
        $window = (int) Settings::get('fix_window_days');
        $asked = Clock::daysBetween($day ?? substr($in, 0, 10), Clock::sessionNight((string) $r->created_at));
        $checks[] = $asked <= $window
            ? ['tone' => 'ok', 'title' => 'Asked in time', 'text' => 'Within ' . $window . ' days of the day.']
            : ['tone' => 'warn', 'title' => 'Asked late', 'text' => Fmt::plural($asked, 'day') . ' after the day · policy allows ' . $window . '.'];
        $mins = $out ? (int) floor((strtotime($out) - strtotime($in)) / 60) : 0;
        $checks[] = match (true) {
            $mins <= 0 => ['tone' => 'bad', 'title' => "The time doesn't work", 'text' => 'The punch-out is before the punch-in.'],
            $mins > 16 * 60 => ['tone' => 'bad', 'title' => 'Very long day', 'text' => self::hm($mins) . ' between punch-in and punch-out.'],
            default => ['tone' => 'info', 'title' => 'Hours this gives', 'text' => self::hm($mins) . ' between punch-in and punch-out' . ($breaks ? ', with ' . self::hm($breaks) . ' of breaks recorded in the CRM' : '') . '.'],
        };
        $seen = Attendance::lastSeen($e, $in);
        if ($seen) {
            $gap = $out ? (int) round((strtotime($out) - strtotime($seen)) / 60) : 0;
            $checks[] = ['tone' => abs($gap) <= 60 ? 'ok' : 'warn', 'title' => 'Last active in the CRM at ' . Fmt::time($seen),
                'text' => abs($gap) <= 60 ? 'Close to the time they gave.' : ($gap > 0 ? 'The time they gave is ' . self::hm($gap) . ' after that.' : 'They were still active ' . self::hm(-$gap) . ' after the time they gave.')];
        }
        $checks[] = ['tone' => 'info', 'title' => 'What approving does', 'text' => !empty($p['punch_id'])
            ? 'Trio HR records this punch-out for attendance and pay.'
            : "Trio HR records this punch-out for attendance and pay. The CRM's own record stays as it is."];
        $facts = [
            ['label' => 'Punched in', 'value' => Fmt::day($in) . ', ' . Fmt::time($in)],
            ['label' => 'How they punch', 'value' => $source],
            ['label' => 'Breaks', 'value' => $breaks ? self::hm($breaks) : 'None recorded'],
            ['label' => 'Asks for punch-out', 'value' => Fmt::day($out) . ', ' . Fmt::time($out)],
        ];
        return [$headline, $checks, $facts];
    }

    public static function hm(int $mins): string
    {
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return $h ? $h . ' h' . ($m ? ' ' . $m . ' m' : '') : $m . ' m';
    }

    // ---- profile & bank -------------------------------------------------------------

    private static function profileChecks(array $p): array
    {
        $fields = array_keys($p['changes'] ?? []);
        $identity = array_intersect($fields, ['full_name', 'date_of_birth']);
        return $identity
            ? [['tone' => 'warn', 'title' => 'Identity details', 'text' => 'Name and date of birth should match their PAN or Aadhaar. Check the proof before approving.']]
            : [['tone' => 'ok', 'title' => 'Contact details only', 'text' => 'Nothing here affects pay or tax.']];
    }

    private static function bankDetail(object $r, object $e, array $p): array
    {
        $db = Kernel::hr();
        $old = $db->table('hr_employee_secrets')->where('employee_id', $e->id)->whereIn('field', ['bank_account', 'bank_ifsc'])->pluck('hint', 'field')->all();
        $hint = $p['hints']['bank_account'] ?? '';
        $headline = 'New bank account · ' . ($p['bank_name'] ?? 'Bank') . ' ••' . $hint;
        $match = Privacy::namesMatch((string) ($p['bank_holder'] ?? ''), $e->full_name);
        $cooling = (int) Settings::get('bank_cooling_days');
        $lastChange = $db->table('hr_requests')->where('employee_id', $e->id)->where('type', 'bank_change')
            ->where('status', 'approved')->max('decided_at');
        $checks = [
            $match
                ? ['tone' => 'ok', 'title' => 'Name matches', 'text' => '"' . ($p['bank_holder'] ?? '') . '" matches ' . $e->full_name . '.']
                : ['tone' => 'warn', 'title' => "Name doesn't match", 'text' => 'The account is in the name "' . ($p['bank_holder'] ?? '') . '", not ' . $e->full_name . '. Salary should only go to their own account.'],
            ['tone' => 'ok', 'title' => 'IFSC looks right', 'text' => ($p['bank_ifsc'] ?? '') . ' is a valid IFSC format. A ₹1 test transfer confirms the account before payday.'],
            ['tone' => 'info', 'title' => 'Cooling-off', 'text' => 'Once approved, salary goes to the new account from ' . Fmt::day(date('Y-m-d', time() + $cooling * 86400)) . '. ' . Fmt::first($e->full_name) . ' is told on email.'],
        ];
        if ($lastChange && strtotime($lastChange) > time() - 90 * 86400) {
            $checks[] = ['tone' => 'warn', 'title' => 'Changed recently', 'text' => 'Bank details were last changed on ' . Fmt::day($lastChange) . '.'];
        }
        $facts = [
            ['label' => 'Current account', 'value' => isset($old['bank_account']) ? '••' . $old['bank_account'] . (isset($old['bank_ifsc']) ? ' · ' . $old['bank_ifsc'] . '…' : '') : 'None on file'],
            ['label' => 'New account', 'value' => '••' . $hint . ' · ' . ($p['bank_ifsc'] ?? '')],
            ['label' => 'Bank', 'value' => $p['bank_name'] ?? ''],
            ['label' => 'Name on the account', 'value' => $p['bank_holder'] ?? ''],
        ];
        return [$headline, $checks, $facts];
    }

    // ---- decisions ------------------------------------------------------------------

    public static function approve(Ctx $c, int $id): array
    {
        $note = $c->str('note', 255);
        $chosen = $c->has('days') ? $c->dates('days') : null;
        $db = Kernel::hr();
        $result = $db->transaction(function () use ($db, $c, $id, $note, $chosen) {
            $r = $db->table('hr_requests')->where('id', $id)->lockForUpdate()->first();
            self::assertDecidable($c, $r);
            $p = json_decode($r->payload, true) ?: [];
            $status = 'approved';
            $decision = null;
            switch ($r->type) {
                case 'leave':
                    $days = $p['days'] ?? [];
                    if ($chosen !== null) {
                        if (!$chosen || array_diff($chosen, $days)) {
                            throw ApiError::invalid('Pick at least one of the nights asked for.');
                        }
                        if (count($chosen) < count($days)) {
                            $status = 'partly_approved';
                        }
                        $days = $chosen;
                    }
                    Attendance::assertOpen($days);
                    $clash = $db->table('hr_leave_days')->where('employee_id', $r->employee_id)->whereIn('shift_day', $days)->exists();
                    if ($clash) {
                        throw ApiError::conflict('Some of those nights are already approved as leave.');
                    }
                    Leave::grant($r, $days);
                    $decision = ['days' => $days];
                    break;
                case 'attendance_fix':
                    Attendance::assertOpen([$p['day'] ?? '']);
                    if (!empty($p['punch_id'])) {
                        $n = $db->table('hr_punches')->where('id', (int) $p['punch_id'])->where('employee_id', $r->employee_id)->whereNull('punch_out')
                            ->update(['punch_out' => $p['clock_out'], 'out_source' => 'fix', 'fix_request_id' => $r->id, 'updated_at' => Kernel::now()]);
                        if (!$n) {
                            throw ApiError::conflict('That punch already has a punch-out. Send this back instead.');
                        }
                        $decision = ['clock_out' => $p['clock_out'] ?? null, 'punch_id' => (int) $p['punch_id']];
                    } else {
                        $decision = ['clock_out' => $p['clock_out'] ?? null, 'session_id' => $p['session_id'] ?? null];
                    }
                    break;
                case 'profile_change':
                    $set = [];
                    foreach ($p['changes'] ?? [] as $field => $ch) {
                        if (in_array($field, self::SELF_FIELDS, true)) {
                            $set[$field] = $ch['to'] ?? null;
                        }
                    }
                    if ($set) {
                        $db->table('hr_employees')->where('id', $r->employee_id)->update($set + ['updated_at' => Kernel::now()]);
                        $db->table('hr_employee_history')->insert([
                            'employee_id' => $r->employee_id, 'kind' => 'details', 'effective_on' => date('Y-m-d'),
                            'summary' => 'Updated ' . implode(', ', array_map(fn($f) => Fmt::lower(People::FIELDS[$f]), array_keys($set))) . ' (their request)',
                            'by_account_id' => $c->accountId(), 'at' => Kernel::now(),
                        ]);
                    }
                    break;
                case 'bank_change':
                    $plainAccount = Vault::decrypt(base64_decode((string) ($p['secrets']['bank_account'] ?? ''), true) ?: '');
                    Vault::put((int) $r->employee_id, 'bank_account', $plainAccount, $c->accountId());
                    foreach (['bank_ifsc', 'bank_name', 'bank_holder'] as $f) {
                        if (!empty($p[$f])) {
                            Vault::put((int) $r->employee_id, $f, (string) $p[$f], $c->accountId());
                        }
                    }
                    $from = date('Y-m-d', time() + (int) Settings::get('bank_cooling_days') * 86400);
                    $decision = ['salary_from' => $from];
                    $db->table('hr_employee_history')->insert([
                        'employee_id' => $r->employee_id, 'kind' => 'bank', 'effective_on' => $from,
                        'summary' => 'Bank account changed to ••' . ($p['hints']['bank_account'] ?? '') . ' (salary from ' . Fmt::day($from) . ')',
                        'by_account_id' => $c->accountId(), 'at' => Kernel::now(),
                    ]);
                    break;
                case 'letter':
                    $decision = ['letter_id' => Letters::fromRequest($r, $c->accountId())];
                    break;
                case 'document':
                    if (!Documents::live((int) ($p['document_id'] ?? 0))) {
                        throw ApiError::conflict('The file was removed, so there is nothing to verify. Send it back instead.');
                    }
                    Documents::setStatus((int) ($p['document_id'] ?? 0), 'verified', $c->accountId());
                    break;
                default:
                    break;
            }
            $db->table('hr_requests')->where('id', $id)->update([
                'status' => $status, 'decided_by' => $c->accountId(), 'decided_at' => Kernel::now(),
                'decision_note' => $note, 'decision' => $decision ? json_encode($decision) : null, 'updated_at' => Kernel::now(),
            ]);
            return [$r, $status, $decision];
        });
        [$r, $status, $decision] = $result;
        self::afterDecision($c, $r, $status, $note, $decision);
        return ['status' => $status, 'message' => self::doneMessage($r, $status, $decision)];
    }

    public static function sendBack(Ctx $c, int $id): array
    {
        $reason = $c->str('reason', 255, true, 'A reason');
        $db = Kernel::hr();
        $r = $db->transaction(function () use ($db, $c, $id, $reason) {
            $r = $db->table('hr_requests')->where('id', $id)->lockForUpdate()->first();
            self::assertDecidable($c, $r);
            $db->table('hr_requests')->where('id', $id)->update([
                'status' => 'sent_back', 'decided_by' => $c->accountId(), 'decided_at' => Kernel::now(),
                'decision_note' => $reason, 'updated_at' => Kernel::now(),
            ]);
            $docId = $r->type === 'document' ? (int) (json_decode($r->payload, true)['document_id'] ?? 0) : 0;
            if ($docId && Documents::live($docId)) {
                Documents::setStatus($docId, 'rejected', $c->accountId(), $reason);
            }
            return $r;
        });
        self::afterDecision($c, $r, 'sent_back', $reason, null);
        $first = Fmt::first((string) Kernel::hr()->table('hr_employees')->where('id', $r->employee_id)->value('full_name'));
        return ['status' => 'sent_back', 'message' => 'Sent back to ' . $first . ' with your reason.'];
    }

    /** Approve every routine request in the list given (others are skipped, never forced). */
    public static function bulkApprove(Ctx $c): array
    {
        $ids = $c->ids('ids', 100);
        $done = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $r = Kernel::hr()->table('hr_requests')->where('id', $id)->first();
            if (!$r || $r->status !== 'pending' || !self::isRoutine($r) || (int) $r->employee_id === (int) $c->employeeId()) {
                $skipped++;
                continue;
            }
            try {
                self::approve($c, $id);
                $done++;
            } catch (ApiError) {
                $skipped++;
            }
        }
        return ['approved' => $done, 'skipped' => $skipped,
            'message' => 'Approved ' . Fmt::plural($done, 'request') . ($skipped ? ' · ' . $skipped . ' left for you to look at' : '') . '.'];
    }

    private static function assertDecidable(Ctx $c, ?object $r): void
    {
        if (!$r) {
            throw ApiError::notFound('That request');
        }
        if ($r->status !== 'pending') {
            $who = $r->decided_by ? Kernel::hr()->table('hr_accounts')->where('id', $r->decided_by)->value('display_name') : null;
            throw ApiError::conflict('This was already ' . Fmt::lower(self::STATUS_LABELS[$r->status] ?? $r->status) . ($who ? ' by ' . Fmt::first($who) : '') . '.');
        }
        if ($c->employeeId() !== null && (int) $r->employee_id === $c->employeeId()) {
            throw ApiError::forbidden("You can't decide your own request. Another admin needs to.");
        }
    }

    private static function doneMessage(object $r, string $status, ?array $decision): string
    {
        $first = Fmt::first((string) Kernel::hr()->table('hr_employees')->where('id', $r->employee_id)->value('full_name'));
        return match ($r->type) {
            'leave' => ($status === 'partly_approved' ? 'Approved ' . Fmt::plural(count($decision['days'] ?? []), 'night') . ' of ' : 'Approved ') . $first . "'s leave. " . $first . ' has been told.',
            'attendance_fix' => $first . "'s clock-out is recorded for payroll.",
            'profile_change' => $first . "'s details are updated.",
            'bank_change' => $first . "'s new bank account is saved. Salary goes there from " . Fmt::day($decision['salary_from'] ?? null) . '.',
            'letter' => 'Approved. The letter is started in Letters' . (Kernel::hr()->table('hr_letters')->where('id', $decision['letter_id'] ?? 0)->value('status') === 'pending_check'
                ? ' and waits for a second admin to check it.' : '. Add any details, then send it for checking.'),
            'document' => $first . "'s document is marked as verified.",
            default => 'Approved.',
        };
    }

    /** Audit + tell the employee, after the decision is committed. */
    private static function afterDecision(Ctx $c, object $r, string $status, ?string $note, ?array $decision): void
    {
        Kernel::hr()->table('hr_notifications')->where('link', '#/approvals?id=' . $r->id)->whereNull('read_at')->update(['read_at' => Kernel::now()]);
        $e = Kernel::hr()->table('hr_employees')->where('id', $r->employee_id)->first(['full_name', 'preferred_name']);
        $first = Fmt::first($e->preferred_name ?: $e->full_name);
        $admin = Fmt::first((string) ($c->auth['display_name'] ?? 'An admin'));
        $verb = ['approved' => 'approved', 'partly_approved' => 'partly approved', 'sent_back' => 'sent back'][$status] ?? $status;
        $what = Fmt::lower(self::TYPE_LABELS[$r->type] ?? 'request');
        $p = json_decode($r->payload, true) ?: [];
        $about = match ($r->type) {
            'leave' => Fmt::lower(Leave::typeNames()[$p['leave_type'] ?? ''] ?? 'leave') . ' for ' . self::trimType($r->summary),
            'attendance_fix' => 'clock-out fix for ' . Fmt::day($p['day'] ?? null),
            'profile_change' => 'profile change',
            'bank_change' => 'new bank account',
            'letter' => 'request for a letter',
            'document' => 'document',
            default => $what,
        };
        Audit::log('request.' . $status, [
            'employee_id' => (int) $r->employee_id, 'entity' => 'hr_requests', 'entity_id' => (int) $r->id,
            'summary' => $admin . ' ' . $verb . ' ' . $first . "'s " . $about,
            'detail' => ['type' => $r->type, 'note' => $note],
        ]);
        Mail::onDecision($r, $status, $note, $decision, $c->accountId());
        Notify::employee((int) $r->employee_id, 'request.decided', 'Your request was ' . $verb . ': ' . $r->summary,
            $note ? (($status === 'sent_back' ? 'Reason: ' : 'Note: ') . $note) : null, '#/me/requests');
        $account = Kernel::hr()->table('hr_accounts')->where('employee_id', $r->employee_id)->where('status', 'active')->first(['email']);
        if ($account) {
            $lines = ["Hi {$first},", 'Your request "' . $r->summary . '" was ' . $verb . '.'];
            if ($status === 'partly_approved' && $decision) {
                $lines[] = 'Approved nights: ' . implode(', ', array_map([Fmt::class, 'day'], $decision['days'])) . '.';
            }
            if ($note) {
                $lines[] = ($status === 'sent_back' ? 'Reason: ' : 'Note: ') . $note;
            }
            $lines[] = 'You can see it in Trio HR under Home.';
            Outbox::queue('decision:' . $r->id . ':' . $status, $account->email, 'Your request was ' . $verb,
                implode("\n\n", $lines), Outbox::html('Your request was ' . $verb, $lines));
            try {
                Outbox::flushAfterResponse(3);
            } catch (\Throwable $e) {
                error_log('[Trio HR] outbox flush failed: ' . $e->getMessage());
            }
        }
    }
}
