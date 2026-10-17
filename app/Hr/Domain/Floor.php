<?php

namespace App\Hr\Domain;

use App\Hr\Crm\CrmGateway;
use App\Hr\Kernel;
use App\Hr\Support\Clock;
use App\Hr\Support\Fmt;

/**
 * Tonight on the floor: the CRM's live clock-ins merged with HR's approved
 * leave, and each CRM login matched to its person in HR. Computed once per
 * request.
 */
final class Floor
{
    private static ?array $tonight = null;

    public static function tonight(): array
    {
        if (self::$tonight !== null) {
            return self::$tonight;
        }
        $day = Clock::shiftDay();
        $f = CrmGateway::floor($day);
        $f['counts']['on_leave'] = 0;

        $employees = Kernel::hr()->table('hr_employees')->whereNotNull('crm_user_id')
            ->get(['id', 'crm_user_id', 'full_name', 'designation', 'centre'])->keyBy('crm_user_id');
        foreach ($f['people'] as $uid => &$p) {
            $e = $employees[$uid] ?? null;
            $p['employee_id'] = $e ? (int) $e->id : null;
            $p['name'] = $e->full_name ?? $p['name'];
            $p['initials'] = Fmt::initials($p['name']);
            $p['designation'] = $e->designation ?? $p['role'];
            $p['centre'] = $e->centre ?? null;
            $p['leave'] = null;
        }
        unset($p);

        foreach (Leave::onLeave($day) as $l) {
            $uid = $l['crm_user_id'];
            if ($uid === null || !isset($f['people'][$uid])) {
                continue;
            }
            $p = &$f['people'][$uid];
            $p['leave'] = $l['kind'];
            if (in_array($p['state'], ['not_in', 'asking'], true)) {
                $f['counts'][$p['state']]--;
                $p['state'] = 'on_leave';
                $f['counts']['on_leave']++;
            }
            unset($p);
        }

        // People whose punch is the Trio HR button, punched in for the work day now under way.
        $f['counts']['hr_in'] = 0;
        $people = array_filter(Attendance::people(Attendance::today(), Attendance::today()), fn($e) => $e->via === 'hr');
        if ($people) {
            foreach (Kernel::hr()->table('hr_punches')->whereIn('employee_id', array_keys($people))->where('work_day', Attendance::today())
                ->orderBy('punch_in')->get() as $pu) {
                $e = $people[(int) $pu->employee_id];
                $open = $pu->punch_out === null;
                $prev = $f['people']['e' . $e->id] ?? null;
                if ($prev && $prev['state'] === 'working' && !$open) {
                    continue; // an earlier open punch wins over a later finished one
                }
                $f['people']['e' . $e->id] = [
                    'crm_user_id' => null, 'employee_id' => (int) $e->id, 'name' => $e->full_name, 'initials' => Fmt::initials($e->full_name),
                    'role' => $e->designation ?? 'Staff', 'designation' => $e->designation ?? 'Staff', 'centre' => $e->centre,
                    'state' => $open ? 'working' : 'finished', 'source' => 'hr', 'clock_in' => $pu->punch_in, 'clock_out' => $pu->punch_out,
                    'via' => 'hr', 'break' => null, 'asking_since' => null, 'stale_open' => false, 'leave' => null,
                ];
            }
            foreach ($f['people'] as $k => $p) {
                if (is_string($k) && $p['source'] === 'hr') {
                    $f['counts'][$p['state']]++;
                    $f['counts']['hr_in'] += $p['state'] === 'working' ? 1 : 0;
                }
            }
        }

        [$start, $end] = Clock::window($day);
        $f['window'] = ['start' => $start, 'end' => $end];
        $f['now'] = Kernel::now();
        return self::$tonight = $f;
    }
}
