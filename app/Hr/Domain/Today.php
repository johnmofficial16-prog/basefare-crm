<?php

namespace App\Hr\Domain;

use App\Hr\Crm\CrmGateway;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Clock;
use App\Hr\Support\Fmt;

/**
 * The admin home. Leads with what needs a decision (worst first, one sentence
 * and one button each), then tonight's floor live from the CRM, this month's
 * people numbers, compliance dates, people moments and what happened.
 */
final class Today
{
    public static function build(Ctx $c): array
    {
        $day = Clock::shiftDay();
        $floor = Floor::tonight();
        return [
            'greeting'   => self::greeting(),
            'first'      => Fmt::first((string) ($c->auth['display_name'] ?? '')),
            'clock'      => self::clocks(),
            'day'        => $day,
            'kpis'       => self::kpis($floor),
            'needs'      => self::needs($c, $day, $floor),
            'floor'      => self::floorSummary($floor),
            'compliance' => self::compliance(),
            'moments'    => self::moments(),
            'feed'       => self::feed(),
        ];
    }

    private static function greeting(): string
    {
        $h = (int) date('G');
        return match (true) {
            $h >= 5 && $h < 12 => 'Good morning',
            $h >= 12 && $h < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /** "Thursday, 15 October", Mohali and New York times (US daylight saving aware). */
    public static function clocks(): array
    {
        $ny = new \DateTimeImmutable('now', new \DateTimeZone('America/New_York'));
        return ['date' => date('l, j F'), 'ist' => date('g:i a'), 'ny' => $ny->format('g:i a')];
    }

    private static function kpis(array $floor): array
    {
        $db = Kernel::hr();
        $month = date('Y-m');
        $current = $db->table('hr_employees')->whereIn('status', ['active', 'notice'])->count();
        $joining = $db->table('hr_employees')->where('status', 'joining')->count();
        $joined = $db->table('hr_employees')->whereBetween('date_joined', [$month . '-01', date('Y-m-d')])->where('status', '!=', 'joining')->count();
        $left = $db->table('hr_employees')->where('status', 'left')->where('last_working_day', 'like', $month . '%')->count();
        $c = $floor['counts'];
        $in = $c['working'] + $c['on_break'];
        $approvals = Approvals::counts()['all'];
        $oldest = $db->table('hr_requests')->where('status', 'pending')->min('created_at');
        $accounts = $db->table('hr_accounts as a')->join('hr_employees as e', 'e.id', '=', 'a.employee_id')
            ->whereIn('e.status', ['joining', 'active', 'notice'])->where('a.status', 'active')->count();
        $all = $current + $joining;

        $peopleNote = [];
        if ($joined) $peopleNote[] = $joined . ' joined this month';
        if ($left) $peopleNote[] = $left . ' left';
        if ($joining) $peopleNote[] = $joining . ' joining soon';
        $floorNote = [];
        if ($c['finished']) $floorNote[] = $c['finished'] . ' done for the night';
        if ($c['on_leave']) $floorNote[] = $c['on_leave'] . ' on leave';
        if ($c['asking']) $floorNote[] = $c['asking'] . ' waiting to clock in';

        return [
            ['key' => 'people', 'icon' => 'users', 'label' => 'People', 'value' => (string) $current,
                'note' => $peopleNote ? implode(' · ', $peopleNote) : 'No joiners or leavers this month', 'tone' => null, 'href' => '#/people'],
            ['key' => 'floor', 'icon' => 'moon', 'label' => 'Punched in now', 'value' => (string) $in, 'of' => null,
                'note' => $floorNote ? implode(' · ', $floorNote) : 'Live from the CRM and Trio HR', 'tone' => $c['asking'] ? 'warn' : null, 'href' => '#/floor'],
            ['key' => 'approvals', 'icon' => 'inbox', 'label' => 'Waiting for you', 'value' => (string) $approvals,
                'note' => $approvals ? 'Oldest from ' . Fmt::ago($oldest) : 'All clear', 'tone' => $approvals ? 'warn' : 'ok', 'href' => '#/approvals'],
            ['key' => 'hr', 'icon' => 'check', 'label' => 'On Trio HR', 'value' => (string) $accounts, 'of' => 'of ' . $all,
                'note' => $all - $accounts ? ($all - $accounts) . ' still to set up their account' : 'Everyone has set up their account', 'tone' => null, 'href' => '#/people?hr=not_joined'],
        ];
    }

    private static function needs(Ctx $c, string $day, array $floor): array
    {
        $db = Kernel::hr();
        $crm = rtrim((string) Kernel::env('HR_CRM_URL', 'https://crm.base-fare.com'), '/');
        $items = [];
        $stale = (int) Settings::get('stale_request_days');
        $add = function (int $rank, string $tone, string $icon, string $title, string $text, string $label, string $href, bool $external = false) use (&$items) {
            $items[] = compact('rank', 'tone', 'icon', 'title', 'text', 'label', 'href', 'external');
        };

        // People waiting to clock in right now (decided in the CRM, which stays the place to unblock agents at night).
        foreach ($floor['people'] as $p) {
            if ($p['state'] === 'asking') {
                $mins = (int) floor((time() - strtotime($p['asking_since'])) / 60);
                $add($mins >= 15 ? 5 : 15, $mins >= 15 ? 'bad' : 'warn', 'clock', $p['name'] . ' is waiting to clock in',
                    "The CRM's clock-in gate stopped them " . ($mins < 1 ? 'just now' : Fmt::plural($mins, 'minute') . ' ago') . ' · decide in the CRM live board',
                    'Open live board', $crm . '/attendance/admin', true);
            }
        }

        // Compliance dates overdue or close.
        foreach (self::compliance() as $ci) {
            if ($ci['tone'] === 'bad') {
                $add(10, 'bad', 'receipt', $ci['title'] . ' is overdue', 'It was due ' . Fmt::day($ci['due_on']) . ($ci['note'] ? ' · ' . $ci['note'] : ''), 'Open', '#/compliance');
            } elseif ($ci['tone'] === 'warn') {
                $add(30, 'warn', 'receipt', $ci['title'], 'Due ' . Fmt::day($ci['due_on']) . ' · ' . Fmt::plural($ci['days'], 'day') . ' left' . ($ci['note'] ? ' · ' . $ci['note'] : ''), 'Open', '#/compliance');
            }
        }

        // Trio Mail: people waiting for a reply (private complaints first, then the longest wait).
        $waiting = $db->table('hr_threads')->where('status', 'open')->where('last_from', 'staff')
            ->selectRaw('COUNT(*) AS n, MIN(last_at) AS oldest, SUM(private) AS private')->first();
        if ((int) ($waiting->n ?? 0) > 0) {
            $hours = (int) floor((time() - strtotime((string) $waiting->oldest)) / 3600);
            $add((int) $waiting->private > 0 || $hours >= 48 ? 8 : ($hours >= 24 ? 14 : 22), $hours >= 48 ? 'bad' : ($hours >= 24 ? 'warn' : 'neutral'), 'mail',
                Fmt::plural((int) $waiting->n, 'message') . ' waiting for a reply',
                ((int) $waiting->private > 0 ? Fmt::plural((int) $waiting->private, 'private complaint') . ' · ' : '')
                . 'Longest wait ' . ($hours < 1 ? 'under an hour' : Fmt::plural($hours, 'hour')),
                'Open Trio Mail', '#/mail');
        }

        // Approvals, one line per kind.
        $pending = $db->table('hr_requests')->where('status', 'pending')->groupBy('type')
            ->selectRaw('type, COUNT(*) as n, MIN(created_at) as oldest')->get();
        $words = [
            'leave' => ['leave request', 'leave requests', 'leave'], 'attendance_fix' => ['attendance fix', 'attendance fixes', 'attendance'],
            'profile_change' => ['profile change', 'profile changes', 'profile'], 'bank_change' => ['bank detail change', 'bank detail changes', 'profile'],
        ];
        foreach ($pending as $p) {
            $age = Clock::daysBetween(substr($p->oldest, 0, 10), date('Y-m-d'));
            [$noun, $nouns, $view] = $words[$p->type] ?? ['request', 'requests', 'all'];
            $add($age >= 2 * $stale ? 12 : 20, $age >= $stale ? 'warn' : 'neutral', $p->type === 'leave' ? 'sun' : ($p->type === 'attendance_fix' ? 'clock' : 'users'),
                Fmt::plural((int) $p->n, $noun, $nouns) . ' waiting',
                'Oldest from ' . Fmt::ago($p->oldest) . ($p->type === 'bank_change' ? ' · check before the next payroll' : ''),
                'Review', '#/approvals?view=' . $view);
        }

        // The published roster running out (the 24/7 roster silently expired once).
        $ends = CrmGateway::rosterEnds();
        $warnDays = (int) Settings::get('roster_warning_days');
        if ($ends === null || $ends < $day) {
            $add(8, 'bad', 'cal', 'There is no published roster for tonight', $ends ? 'The roster ended on ' . Fmt::day($ends) . '. Clock-in rules fall back to defaults.' : 'No roster has been published in the CRM.', 'Open roster', $crm . '/shifts', true);
        } elseif (Clock::daysBetween($day, $ends) <= $warnDays) {
            $left = Clock::daysBetween($day, $ends);
            $add(25, 'warn', 'cal', 'The roster ends ' . ($left === 0 ? 'tonight' : 'on ' . Fmt::day($ends)), 'Publish the next weeks so clock-in keeps working · ' . ($left === 0 ? 'last night' : Fmt::plural($left, 'night') . ' left'), 'Open roster', $crm . '/shifts', true);
        }

        // Attendance facts: missing punch-outs, and no punch several days in a row.
        $alerts = Attendance::alerts();
        if ($alerts['missing']) {
            $m = $alerts['missing'];
            $who = array_map(fn($x) => Fmt::first($x['name']) . ' (' . Fmt::day($x['day']) . ')', array_slice($m, 0, 3));
            $add(40, 'neutral', 'clock', Fmt::plural(count($m), 'punch-out') . ' missing',
                implode(', ', $who) . (count($m) > 3 ? ' and ' . (count($m) - 3) . ' more' : '') . ' · they were asked in Trio Mail; no hours count until it is fixed',
                'See the month', '#/attendance/close?month=' . substr($m[0]['day'], 0, 7));
        }
        foreach (array_slice($alerts['streaks'], 0, 4) as $st) {
            $add(28, 'warn', 'alert', Fmt::first($st['name']) . ': no punch for ' . Fmt::plural($st['days'], 'day') . ' in a row',
                'Since ' . Fmt::day($st['since']) . ' · no leave asked', 'Write to them', '#/mail/new?to=' . $st['employee_id']);
        }
        if (count($alerts['streaks']) > 4) {
            $add(29, 'warn', 'alert', (count($alerts['streaks']) - 4) . ' more with no punch for days', 'No leave asked', 'Open attendance', '#/attendance');
        }

        // Month end: the last month isn't locked for payroll yet.
        $close = Attendance::defaultCloseMonth();
        if ($close < substr(Attendance::today(), 0, 7)) {
            $add(16, 'warn', 'lock', date('F', strtotime($close . '-01')) . ' attendance isn\'t closed for payroll yet',
                'Sort the days still open, then lock the month', 'Close ' . date('F', strtotime($close . '-01')), '#/attendance/close?month=' . $close);
        }

        // Fixed-term contracts ending (no probation tracking: John, 16 Oct).
        foreach ($db->table('hr_employees')->where('employment_type', 'fixed_term')->whereIn('status', ['active', 'notice'])
            ->whereBetween('contract_end', [date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))])->get() as $e) {
            $add(36, 'info', 'file', $e->full_name . "'s contract ends " . Fmt::day($e->contract_end), 'Renew it or plan the exit', 'Open record', '#/people/' . $e->id);
        }

        // Letters waiting for a second admin (never your own).
        $toCheck = $db->table('hr_letters')->where('status', 'pending_check')->where('prepared_by', '!=', $c->accountId())->count();
        if ($toCheck) {
            $add(18, 'warn', 'file', Fmt::plural($toCheck, 'letter') . ' waiting for your check', 'Prepared by another admin · check and issue', 'Check', '#/letters?status=pending_check');
        }
        // Policies people still have to sign.
        foreach ($db->table('hr_policies')->where('status', 'published')->get() as $p) {
            $missing = count(array_diff(Policies::audienceIds($p), $db->table('hr_policy_acks')->where('policy_id', $p->id)->pluck('employee_id')->map(fn($v) => (int) $v)->all()));
            if ($missing) {
                $add(48, 'neutral', 'shield', Fmt::plural($missing, 'person', 'people') . ($missing === 1 ? " hasn't signed " : " haven't signed ") . $p->title, 'Published ' . Fmt::day($p->published_at), 'See who', '#/policies/' . $p->id);
            }
        }
        // Written appointment letters are now required for everyone.
        $current = $db->table('hr_employees')->whereIn('status', ['active', 'notice'])->pluck('id')->all();
        $withLetter = $db->table('hr_letters')->where('template_code', 'appointment')->where('status', '!=', 'void')->whereIn('employee_id', $current ?: [0])->distinct()->count('employee_id');
        if (count($current) - $withLetter > 0) {
            $add(46, 'neutral', 'file', Fmt::plural(count($current) - $withLetter, 'person', 'people') . ' without an appointment letter',
                'The Labour Codes require one in writing for everyone', 'Open letters', '#/letters');
        }

        // The fresh start: everyone should set up their own record.
        $notInvited = $db->table('hr_employees as e')->leftJoin('hr_accounts as a', 'a.employee_id', '=', 'e.id')
            ->whereIn('e.status', ['joining', 'active', 'notice'])->whereNull('a.id')->count();
        if ($notInvited) {
            $add(50, 'neutral', 'users', Fmt::plural($notInvited, 'person', 'people') . ' not invited to Trio HR yet',
                'They fill in their own details, so nothing is copied from old sheets', 'See who', '#/people?hr=not_invited');
        }
        $stuck = $db->table('hr_accounts')->where('status', 'invited')->where('invited_at', '<', date('Y-m-d H:i:s', strtotime('-3 days')))->count();
        if ($stuck) {
            $add(45, 'neutral', 'users', Fmt::plural($stuck, 'invite') . ' not accepted after 3 days', 'Send a reminder or check their email address', 'See who', '#/people?hr=invited');
        }

        usort($items, fn($a, $b) => $a['rank'] <=> $b['rank']);
        return array_map(function ($i) {
            unset($i['rank']);
            return $i;
        }, $items);
    }

    public static function floorSummary(array $floor): array
    {
        $c = $floor['counts'];
        $groups = ['asking' => [], 'on_break' => [], 'on_leave' => []];
        foreach ($floor['people'] as $p) {
            if (isset($groups[$p['state']])) {
                $groups[$p['state']][] = [
                    'employee_id' => $p['employee_id'], 'name' => $p['name'], 'initials' => $p['initials'],
                    'detail' => match ($p['state']) {
                        'on_break' => ucfirst($p['break']['type']) . ' break since ' . Fmt::time($p['break']['since']),
                        'on_leave' => $p['leave'],
                        'asking' => 'Waiting since ' . Fmt::time($p['asking_since']),
                        default => '',
                    },
                ];
            }
        }
        return ['counts' => $c, 'groups' => $groups, 'day' => $floor['day'], 'now' => $floor['now']];
    }

    /** Everyone on tonight's floor, one line each (the #/floor page). */
    public static function floorFull(): array
    {
        $fl = Floor::tonight();
        $people = [];
        foreach ($fl['people'] as $p) {
            $on = $p['source'] === 'hr' ? 'Trio HR' : 'the CRM';
            $detail = match ($p['state']) {
                'working' => 'In since ' . Fmt::time($p['clock_in']) . ' · ' . Approvals::hm(max(0, intdiv(time() - strtotime((string) $p['clock_in']), 60))) . ' so far',
                'on_break' => ucfirst((string) $p['break']['type']) . ' break since ' . Fmt::time($p['break']['since']),
                'finished' => 'In ' . Fmt::time($p['clock_in']) . ' · out ' . Fmt::time($p['clock_out']),
                'asking' => 'Tried to clock in at ' . Fmt::time($p['asking_since']) . ' · decide in the CRM',
                'on_leave' => (string) $p['leave'],
                default => 'No clock-in tonight',
            };
            $badge = null;
            if ($p['stale_open']) {
                $badge = ['tone' => 'p-bad', 'text' => 'Open since an earlier night'];
            } elseif ($p['source'] === 'hr' && $p['state'] !== 'not_in') {
                $badge = ['tone' => 'p-info', 'text' => 'Trio HR punch'];
            } elseif ($p['via'] === 'admin' && in_array($p['state'], ['working', 'on_break', 'finished'], true)) {
                $badge = ['tone' => 'p-info', 'text' => 'Clocked in by an admin'];
            }
            $people[] = [
                'employee_id' => $p['employee_id'], 'name' => $p['name'], 'initials' => $p['initials'],
                'state' => $p['state'], 'detail' => $detail, 'badge' => $badge, 'on' => $on,
            ];
        }
        return [
            'day' => $fl['day'], 'people' => $people, 'counts' => $fl['counts'], 'clock' => self::clocks(),
            'crm_url' => rtrim((string) Kernel::env('HR_CRM_URL', 'https://crm.base-fare.com'), '/'),
        ];
    }

    /** All open compliance dates, plus those done in the last 60 days. */
    public static function complianceAll(): array
    {
        $today = date('Y-m-d');
        $rows = Kernel::hr()->table('hr_compliance_items')
            ->where(fn($q) => $q->where('status', 'open')->orWhere('done_at', '>=', date('Y-m-d H:i:s', strtotime('-60 days'))))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderBy('due_on')->get();
        return ['items' => $rows->map(function ($i) use ($today) {
            $days = Clock::daysBetween($today, $i->due_on);
            return [
                'id' => (int) $i->id, 'title' => $i->title, 'due_on' => $i->due_on, 'days' => $days, 'status' => $i->status,
                'note' => $i->note, 'source' => $i->source, 'done_at' => $i->done_at,
                'tone' => $i->status !== 'open' ? 'ok' : ($days < 0 ? 'bad' : ($days <= 7 ? 'warn' : 'neutral')),
            ];
        })->all()];
    }

    public static function complianceDone(\App\Hr\Http\Ctx $c, int $id): array
    {
        $n = Kernel::hr()->table('hr_compliance_items')->where('id', $id)->where('status', 'open')
            ->update(['status' => 'done', 'done_at' => Kernel::now(), 'done_by' => $c->accountId()]);
        if (!$n) {
            throw \App\Hr\Http\ApiError::conflict('That date is already marked as done.');
        }
        $title = Kernel::hr()->table('hr_compliance_items')->where('id', $id)->value('title');
        \App\Hr\Support\Audit::log('compliance.done', ['entity' => 'hr_compliance_items', 'entity_id' => $id,
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' marked "' . $title . '" as done']);
        return ['ok' => true];
    }

    public static function compliance(): array
    {
        $today = date('Y-m-d');
        return Kernel::hr()->table('hr_compliance_items')->where('status', 'open')->orderBy('due_on')->limit(12)->get()
            ->map(function ($i) use ($today) {
                $days = Clock::daysBetween($today, $i->due_on);
                return [
                    'id' => (int) $i->id, 'title' => $i->title, 'due_on' => $i->due_on, 'days' => $days, 'note' => $i->note, 'source' => $i->source,
                    'tone' => $days < 0 ? 'bad' : ($days <= 7 ? 'warn' : 'neutral'),
                ];
            })->all();
    }

    private static function moments(): array
    {
        $db = Kernel::hr();
        $out = [];
        $today = date('Y-m-d');
        $week = [];
        for ($i = 0; $i < 7; $i++) {
            $week[date('m-d', strtotime("+{$i} days"))] = $i;
        }
        foreach ($db->table('hr_employees')->whereIn('status', ['active', 'notice'])->get(['id', 'full_name', 'date_of_birth', 'date_joined', 'designation', 'centre']) as $e) {
            if ($e->date_of_birth && isset($week[substr($e->date_of_birth, 5)])) {
                $in = $week[substr($e->date_of_birth, 5)];
                $out[] = ['kind' => 'birthday', 'in' => $in, 'employee_id' => (int) $e->id, 'name' => $e->full_name, 'initials' => Fmt::initials($e->full_name),
                    'title' => Fmt::first($e->full_name) . "'s birthday", 'when' => $in === 0 ? 'Today' : ($in === 1 ? 'Tomorrow' : date('l', strtotime("+{$in} days")))];
            }
            if ($e->date_joined && $e->date_joined < date('Y-m-d', strtotime('-300 days')) && isset($week[substr($e->date_joined, 5)])) {
                $in = $week[substr($e->date_joined, 5)];
                $years = (int) date('Y', strtotime("+{$in} days")) - (int) substr($e->date_joined, 0, 4);
                if ($years >= 1) {
                    $out[] = ['kind' => 'anniversary', 'in' => $in, 'employee_id' => (int) $e->id, 'name' => $e->full_name, 'initials' => Fmt::initials($e->full_name),
                        'title' => $e->full_name . ' · ' . Fmt::plural($years, 'year') . ' with Trio', 'when' => $in === 0 ? 'Today' : ($in === 1 ? 'Tomorrow' : date('l', strtotime("+{$in} days")))];
                }
            }
        }
        foreach ($db->table('hr_employees')->where('status', 'joining')->whereBetween('date_joined', [$today, date('Y-m-d', strtotime('+14 days'))])->get() as $e) {
            $in = Clock::daysBetween($today, $e->date_joined);
            $out[] = ['kind' => 'joining', 'in' => $in, 'employee_id' => (int) $e->id, 'name' => $e->full_name, 'initials' => Fmt::initials($e->full_name),
                'title' => $e->full_name . ' joins ' . ($in === 0 ? 'today' : ($in === 1 ? 'tomorrow' : Fmt::day($e->date_joined))),
                'when' => trim(($e->designation ?? '') . ' · ' . (Settings::centreName($e->centre) ?? ''), ' ·')];
        }
        usort($out, fn($a, $b) => $a['in'] <=> $b['in']);
        return array_slice($out, 0, 6);
    }

    private static function feed(): array
    {
        $db = Kernel::hr();
        $items = [];
        foreach ($db->table('hr_audit_log')->whereNotNull('summary')->orderByDesc('at')->limit(10)->get(['at', 'summary', 'employee_id']) as $a) {
            $items[] = ['at' => $a->at, 'text' => $a->summary, 'employee_id' => $a->employee_id ? (int) $a->employee_id : null, 'source' => 'hr'];
        }
        usort($items, fn($a, $b) => strcmp($b['at'], $a['at']));
        return array_slice($items, 0, 8);
    }
}
