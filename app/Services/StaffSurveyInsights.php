<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * Staff survey insights for the admin: what each person's answers say about
 * them, how they took the survey, how that lines up with their actual work,
 * and what the whole team is saying.
 *
 * Everything is rule-based and explainable: every tag says why it is there.
 * The rules read patterns, not verdicts. With few answers they are early signs.
 */
final class StaffSurveyInsights
{
    /** Work numbers cover this many days back from today. */
    public const WINDOW_DAYS = 30;

    /** Under this many seconds for the whole survey reads as "rushed". */
    private const RUSHED_SECS = 75;

    private const STAY_SCORE = ['Yes, definitely' => 0, 'Probably' => 5, 'Not sure' => 15, 'Probably not' => 30, 'No' => 40];
    private const LEAVING = ['Probably not', 'No'];
    private const STAYING = ['Yes, definitely', 'Probably'];
    private const SCALES = ['happy', 'valued', 'support', 'tools', 'workload'];

    /**
     * Themes picked out of the written answers. Words are matched whole, case
     * insensitive; Hinglish and Hindi words are included because staff are told
     * they can write in any language.
     */
    public const THEMES = [
        'pay'      => ['Pay and incentives', ['incentive', 'incentives', 'salary', 'salaries', 'pay', 'paid', 'payout', 'bonus', 'commission', 'increment', 'hike', 'appraisal', 'money', 'paisa', 'paise', 'tds', 'slab', 'slabs', 'पैसा', 'सैलरी', 'तनख्वाह']],
        'shift'    => ['Shift and timings', ['shift', 'shifts', 'timing', 'timings', 'night', 'nights', 'hours', 'overtime', 'roster', 'sleep', 'late', 'raat', 'शिफ्ट', 'रात']],
        'leads'    => ['Leads and calls', ['lead', 'leads', 'call', 'calls', 'calling', 'data', 'queue', 'dialer', 'inbound', 'traffic', 'customers', 'customer']],
        'manager'  => ['Manager and support', ['manager', 'managers', 'tl', 'supervisor', 'boss', 'support', 'guidance', 'senior', 'seniors', 'sir', 'mam', 'maam', 'ma\'am']],
        'growth'   => ['Training and growth', ['training', 'train', 'learn', 'learning', 'skill', 'skills', 'gds', 'course', 'promotion', 'growth', 'grow', 'career', 'sikhna', 'सीखना']],
        'pressure' => ['Targets and pressure', ['target', 'targets', 'pressure', 'stress', 'stressed', 'quota', 'scold', 'scolding', 'daant', 'tension', 'टारगेट']],
        'leave'    => ['Breaks and leave', ['break', 'breaks', 'leave', 'leaves', 'holiday', 'holidays', 'off', 'weekoff', 'chutti', 'chhutti', 'छुट्टी']],
        'tools'    => ['Tools and systems', ['crm', 'system', 'systems', 'tool', 'tools', 'software', 'computer', 'pc', 'laptop', 'internet', 'wifi', 'headset', 'headphones', 'slow']],
        'place'    => ['Office, food and transport', ['cab', 'cabs', 'transport', 'travel', 'food', 'snack', 'snacks', 'tea', 'ac', 'office', 'chair', 'washroom', 'canteen', 'parking']],
        'culture'  => ['Team and respect', ['team', 'colleague', 'colleagues', 'family', 'environment', 'culture', 'respect', 'politics', 'favouritism', 'favoritism', 'bias', 'fair', 'unfair']],
    ];

    // ---- work data ----------------------------------------------------------------

    /**
     * Bookings, profit, attendance and calls for the last WINDOW_DAYS days, per
     * person, plus the average for each role (active, non-dev staff only) to
     * compare against. Each block fails on its own: a missing table just leaves
     * that number out.
     *
     * Bookings and profit follow the Performance rule: approved rows only,
     * Net = profit_mco − refund_mco_impact. Attendance counts days, not sessions.
     *
     * @return array{people: array<int,array>, avg: array<string,array>, from: string, to: string}
     */
    public static function work(): array
    {
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-' . (self::WINDOW_DAYS - 1) . ' days'));
        $staff = DB::table('users')
            ->whereIn('role', [User::ROLE_AGENT, User::ROLE_CSA, User::ROLE_SUPERVISOR, User::ROLE_MANAGER])
            ->where('status', User::STATUS_ACTIVE)->whereNull('deleted_at')->where('is_dev', 0)
            ->pluck('role', 'id')->all();
        $people = [];
        foreach ($staff as $id => $role) {
            $people[(int) $id] = ['role' => $role, 'bookings' => null, 'net' => null, 'days' => null, 'late' => null, 'flagged' => null, 'calls' => null];
        }
        $ids = array_keys($people);
        if (!$ids) {
            return ['people' => [], 'avg' => [], 'from' => $from, 'to' => $to, 'currency' => 'CAD'];
        }

        try {
            $rows = DB::table('transactions')->whereIn('agent_id', $ids)
                ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->groupBy('agent_id')
                ->selectRaw("agent_id, SUM(status = 'approved') AS bookings,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN profit_mco - COALESCE(refund_mco_impact, 0) END), 0) AS net")
                ->get();
            foreach ($people as $id => &$p) {
                $p['bookings'] = 0;
                $p['net'] = 0.0;
            }
            unset($p);
            foreach ($rows as $r) {
                $people[(int) $r->agent_id]['bookings'] = (int) $r->bookings;
                $people[(int) $r->agent_id]['net'] = round((float) $r->net, 2);
            }
        } catch (Throwable $e) {
            error_log('[staff survey insights] bookings: ' . $e->getMessage());
        }

        try {
            $rows = DB::table('attendance_sessions')->whereIn('user_id', $ids)->whereBetween('date', [$from, $to])
                ->groupBy('user_id')
                ->selectRaw('user_id, COUNT(DISTINCT date) AS days, COUNT(DISTINCT CASE WHEN late_minutes > 0 THEN date END) AS late')
                ->get();
            foreach ($people as $id => &$p) {
                $p['days'] = 0;
                $p['late'] = 0;
            }
            unset($p);
            foreach ($rows as $r) {
                $people[(int) $r->user_id]['days'] = (int) $r->days;
                $people[(int) $r->user_id]['late'] = (int) $r->late;
            }
            $rows = DB::table('attendance_breaks as b')->join('attendance_sessions as s', 's.id', '=', 'b.session_id')
                ->whereIn('s.user_id', $ids)->whereBetween('s.date', [$from, $to])->where('b.flagged', 1)
                ->groupBy('s.user_id')->selectRaw('s.user_id, COUNT(*) AS n')->get();
            foreach ($people as $id => &$p) {
                $p['flagged'] = 0;
            }
            unset($p);
            foreach ($rows as $r) {
                $people[(int) $r->user_id]['flagged'] = (int) $r->n;
            }
        } catch (Throwable $e) {
            error_log('[staff survey insights] attendance: ' . $e->getMessage());
        }

        try {
            $rows = DB::table('call_logs')->whereIn('agent_id', $ids)->whereBetween('shift_date', [$from, $to])
                ->groupBy('agent_id')->selectRaw('agent_id, COUNT(*) AS n')->get();
            foreach ($people as $id => &$p) {
                $p['calls'] = 0;
            }
            unset($p);
            foreach ($rows as $r) {
                $people[(int) $r->agent_id]['calls'] = (int) $r->n;
            }
        } catch (Throwable $e) {
            error_log('[staff survey insights] calls: ' . $e->getMessage());
        }

        // Averages per role. Everyone active counts, including people with zero
        // activity (the Performance module does the same).
        $avg = [];
        foreach (array_unique(array_values($staff)) as $role) {
            $group = array_filter($people, fn($p) => $p['role'] === $role);
            foreach (['bookings', 'net', 'days', 'late', 'flagged', 'calls'] as $m) {
                $vals = array_filter(array_column($group, $m), fn($v) => $v !== null);
                $avg[$role][$m] = $vals ? array_sum($vals) / count($vals) : null;
            }
            $avg[$role]['n'] = count($group);
        }
        $currency = 'CAD';
        try {
            // Same display currency as Performance and Analytics.
            $currency = DB::table('system_config')->where('key', 'default_currency')->value('value') ?: 'CAD';
        } catch (Throwable) {
        }
        return ['people' => $people, 'avg' => $avg, 'from' => $from, 'to' => $to, 'currency' => $currency];
    }

    // ---- one person -----------------------------------------------------------------

    /**
     * The read on one response.
     *
     * @param array      $r    a response from StaffSurveyService::results()
     * @param array|null $work their numbers from work()['people']
     * @param array|null $avg  the average for their role from work()['avg']
     */
    public static function person(array $r, ?array $work = null, ?array $avg = null): array
    {
        $a = $r['answers'];
        $first = explode(' ', trim((string) $r['name']))[0] ?: 'They';
        $stay = $a['stay'] ?? null;
        $happy = $a['happy'] ?? null;
        $rec = $a['recommend'] ?? null;
        $goals = (array) ($a['goals'] ?? []);
        $help = (array) ($a['help'] ?? []);
        $path = $a['path'] ?? null;
        $tired = in_array($a['shift_end'] ?? null, ['Drained', 'Stressed'], true);
        $low = fn($k) => isset($a[$k]) && $a[$k] <= 2;
        $quote = fn($k) => self::snippet($a[$k] ?? '');

        // Flight risk: a points score from the answers most linked to leaving.
        $score = self::STAY_SCORE[$stay] ?? 0;
        $why = [];
        if (in_array($stay, ['Not sure', 'Probably not', 'No'], true)) {
            $why[] = 'A year from now: "' . $stay . '"';
        }
        if ($rec !== null && $rec <= 6) {
            $score += $rec <= 4 ? 20 : 12;
            $why[] = 'Would recommend us ' . $rec . '/10';
        } elseif ($rec !== null && $rec <= 8) {
            $score += 4;
        }
        if ($happy !== null && $happy <= 3) {
            $score += [1 => 20, 2 => 12, 3 => 4][$happy];
            if ($happy <= 2) {
                $why[] = 'Happiness ' . $happy . '/5';
            }
        }
        foreach (['valued' => 'Feels unseen', 'support' => 'Low support', 'workload' => 'Workload too heavy'] as $k => $label) {
            if ($low($k)) {
                $score += $k === 'workload' ? 6 : 8;
                $why[] = $label;
            }
        }
        if ($tired) {
            $score += 6;
        }
        if (in_array($path, ['Running my own business', 'Studying further'], true)) {
            $score += 5;
            $why[] = 'In 2 years: ' . lcfirst($path);
        }
        $level = $score >= 45 ? 'high' : ($score >= 20 ? 'medium' : 'low');

        // Tags: each one is a pattern with a reason.
        $tags = [];
        $tag = function (string $tone, string $label, string $hint) use (&$tags) {
            $tags[] = ['tone' => $tone, 'label' => $label, 'hint' => $hint];
        };
        $happyNow = $happy !== null && $happy >= 4;
        if (in_array($stay, self::LEAVING, true) && $happyNow) {
            $tag('red', 'Happy now, leaving later', "Rates happiness {$happy}/5 but doesn't see a future here: usually about growth or pay, not the job itself.");
        } elseif (in_array($stay, self::LEAVING, true)) {
            $tag('red', 'Thinking of leaving', 'Said "' . $stay . '" to being here in a year.');
        } elseif ($stay === 'Not sure') {
            $tag('amber', 'Undecided', 'Not sure about being here in a year. The next few months decide it.');
        }
        if (in_array($stay, self::STAYING, true) && $happy !== null && $happy <= 2) {
            $tag('amber', 'Staying but unhappy', "Plans to stay but rates happiness {$happy}/5. Risk of quietly switching off.");
        }
        if ($tired && $low('workload')) {
            $tag('red', 'Burnout warning', 'Ends shifts ' . strtolower($a['shift_end']) . ' and finds the workload too much (' . $a['workload'] . '/5).');
        } elseif ($tired) {
            $tag('amber', 'Worn out', 'Usually ends the shift ' . strtolower($a['shift_end']) . '.');
        }
        if ($low('valued')) {
            $tag('amber', 'Feels unseen', 'Rates feeling valued ' . $a['valued'] . '/5.');
        }
        if ($low('support')) {
            $tag('amber', 'Wants more support', 'Rates support from the people they report to ' . $a['support'] . '/5.');
        }
        if ($low('tools')) {
            $tag('amber', 'Needs training or tools', 'Rates training and tools ' . $a['tools'] . '/5.');
        }
        if ($rec !== null && $rec >= 9 && $happyNow && in_array($stay, self::STAYING, true)) {
            $tag('green', 'Champion', "Would recommend us {$rec}/10, happy and staying. A natural referrer or buddy for new joiners.");
        }
        if ($path === 'Leading a team' || in_array('A promotion', $goals, true)) {
            $tag('blue', 'Wants to grow', $path === 'Leading a team' ? 'Sees themselves leading a team in 2 years.' : 'A promotion is one of their top goals.');
        }
        if (in_array('Earning more (incentives)', $goals, true) || in_array('A better incentive plan', $help, true)) {
            $tag('blue', 'Money-driven', 'Earning more is what they are working for.');
        }
        if (in_array('Better work-life balance', $goals, true) || in_array('Flexible shift options', $help, true)) {
            $tag('blue', 'Wants balance', 'Work-life balance or flexible shifts matter to them.');
        }

        // How they took the survey.
        $meta = is_array($a['_meta'] ?? null) ? $a['_meta'] : [];
        $asked = StaffSurveyService::writtenAsked($a);
        $written = array_filter(array_keys($asked), fn($k) => trim((string) ($a[$k] ?? '')) !== '');
        $words = 0;
        foreach ($written as $k) {
            $words += count(preg_split('/\s+/u', trim((string) $a[$k]), -1, PREG_SPLIT_NO_EMPTY));
        }
        $scaleVals = array_values(array_filter(array_map(fn($k) => $a[$k] ?? null, self::SCALES), fn($v) => $v !== null));
        $straight = count($scaleVals) === count(self::SCALES) && count(array_unique($scaleVals)) === 1;
        $secs = isset($meta['secs']) ? (int) $meta['secs'] : null;
        $rushed = $secs !== null && $secs > 0 && $secs < self::RUSHED_SECS;
        $taking = [
            'secs' => $secs, 'pages' => $meta['pages'] ?? null, 'voice' => (int) ($meta['voice'] ?? 0),
            'starters' => (int) ($meta['starters'] ?? 0), 'nudged' => (int) ($meta['nudged'] ?? 0),
            'asked' => count($asked), 'written' => count($written), 'words' => $words,
            'straight' => $straight, 'rushed' => $rushed, 'skips' => (int) $r['skips'],
            'more_at' => $meta['more_at'] ?? null, 'hour' => $r['at'] ? (int) date('G', strtotime($r['at'])) : null,
        ];
        if ($words >= 40) {
            $tag('green', 'Opened up', "Wrote {$words} words in their own words. Worth reading in full.");
        } elseif (!$written) {
            $tag('grey', 'Wrote nothing', 'Picked answers but skipped every written question. A chat may get more than the form did.');
        }
        if ($straight) {
            $tag('grey', 'Same score everywhere', 'Gave ' . $scaleVals[0] . '/5 to every rating. The scores may not be carefully thought through.');
        }
        if ($rushed) {
            $tag('grey', 'Rushed it', 'Finished in ' . $secs . ' seconds. Read the scores with care.');
        }
        if ($r['skips'] >= StaffSurveyService::MAX_SKIPS) {
            $tag('grey', 'Put it off', 'Pressed "Not now" ' . $r['skips'] . ' times before answering.');
        }

        // Work, next to their role's average.
        $compare = [];
        if ($work && $avg) {
            $vs = function (?float $mine, ?float $team) {
                if ($mine === null || $team === null) {
                    return null;
                }
                if ($team <= 0) {
                    return $mine > 0 ? 'above' : 'level';
                }
                $ratio = $mine / $team;
                return $ratio >= 1.25 ? 'above' : ($ratio <= 0.75 ? 'below' : 'level');
            };
            foreach (['bookings', 'net', 'days', 'late', 'flagged', 'calls'] as $m) {
                $compare[$m] = $vs($work[$m] ?? null, $avg[$m] ?? null);
            }
            $seller = in_array($work['role'], [User::ROLE_AGENT, User::ROLE_CSA], true);
            $strong = $seller && ($compare['net'] === 'above' || $compare['bookings'] === 'above');
            if ($strong && $level !== 'low') {
                $tag('red', 'Top performer at risk', 'Above-average results in the last ' . self::WINDOW_DAYS . ' days, and the answers show a ' . $level . ' risk of leaving.');
            }
            if ($compare['late'] === 'above' && ($work['late'] ?? 0) >= 3 && ($tired || $low('workload'))) {
                $tag('amber', 'Timing strain shows', 'Late on ' . $work['late'] . ' days in the last ' . self::WINDOW_DAYS . ', and says the workload or timings are hard.');
            }
            if ($seller && $low('workload') && $compare['bookings'] === 'below') {
                $tag('amber', 'Struggling, not overloaded', 'Says the workload is too much, but bookings are below average. Coaching or tools may help more than a lighter load.');
            }
            if ($seller && $happyNow && $compare['net'] === 'below' && ($work['days'] ?? 0) >= 10) {
                $tag('blue', 'Content but under target', 'Happy here, but results are below average. Needs a push or skills, not morale.');
            }
        }

        // Most serious first: red, amber, then the good news and the survey-taking notes.
        $rank = ['red' => 0, 'amber' => 1, 'green' => 2, 'blue' => 3, 'grey' => 4];
        usort($tags, fn($x, $y) => $rank[$x['tone']] <=> $rank[$y['tone']]);

        // What drives them: from their goals, in their order.
        $drivers = array_values(array_unique(array_map(fn($g) => [
            'Earning more (incentives)' => 'Money', 'A promotion' => 'Promotion', 'Learning new skills' => 'Learning',
            'Better work-life balance' => 'Balance', 'Job security' => 'Security', 'Saving for something big' => 'A savings goal',
            'Further studies' => 'Studies', 'Recognition for my work' => 'Recognition',
        ][$g] ?? $g, $goals)));

        return [
            'risk' => ['level' => $level, 'score' => $score, 'why' => $why],
            'tags' => $tags,
            'drivers' => $drivers,
            'read' => self::read($first, $a, $level, $drivers, $tags),
            'talk' => self::talk($a, $level, $tags, $quote),
            'taking' => $taking,
            'compare' => $compare,
        ];
    }

    /** Two or three plain sentences: how they feel, what drives them, the main concern. */
    private static function read(string $first, array $a, string $level, array $drivers, array $tags): string
    {
        $happy = $a['happy'] ?? null;
        $mood = $happy === null ? '' : ([1 => 'very unhappy', 2 => 'unhappy', 3 => 'so-so', 4 => 'happy', 5 => 'very happy'][$happy] ?? '');
        $stay = [
            'Yes, definitely' => 'fully expects to be here in a year',
            'Probably' => 'probably sees themselves here in a year',
            'Not sure' => 'is not sure about being here in a year',
            'Probably not' => 'probably won\'t be here in a year',
            'No' => 'does not see themselves here in a year',
        ][$a['stay'] ?? ''] ?? '';
        $s = $first . ($mood ? ' is ' . $mood . ' at work' : '') . ($stay ? ($mood ? ' and ' : ' ') . $stay : '') . '.';
        if ($drivers) {
            $list = count($drivers) > 1 ? implode(', ', array_slice($drivers, 0, -1)) . ' and ' . end($drivers) : $drivers[0];
            $s .= ' What drives them: ' . strtolower($list) . '.';
        }
        $path = $a['path'] ?? null;
        if ($path && $path !== 'Not sure yet') {
            $s .= ' In two years they want to be ' . lcfirst(str_replace('In another department (quality, training, operations or marketing)', 'in another department', $path)) . '.';
        }
        $concern = null;
        foreach ($tags as $t) {
            if (in_array($t['tone'], ['red', 'amber'], true)) {
                $concern = $t['label'];
                break;
            }
        }
        if ($concern) {
            $s .= ' Main thing to watch: ' . lcfirst($concern) . '.';
        } elseif ($level === 'low') {
            $s .= ' Nothing worrying in their answers.';
        }
        return $s;
    }

    /** Things to bring up in a one-to-one, most important first. Uses their own words where they wrote some. */
    private static function talk(array $a, string $level, array $tags, callable $quote): array
    {
        $labels = array_column($tags, 'label');
        $has = fn($l) => in_array($l, $labels, true);
        $out = [];
        if ($has('Thinking of leaving') || $has('Happy now, leaving later') || $has('Undecided')) {
            $said = $quote('stay_why');
            $out[] = 'Ask plainly what would keep them here for another year.' . ($said ? ' They wrote: "' . $said . '"' : '')
                . (($a['help'] ?? []) ? ' They asked for: ' . strtolower(implode(', ', $a['help'])) . '.' : '');
        }
        if ($has('Top performer at risk')) {
            $out[] = 'They are one of your stronger performers. Don\'t wait for a resignation: talk about their next step this week.';
        }
        if ($has('Burnout warning') || $has('Worn out')) {
            $said = $quote('shift_why') ?: $quote('workload_why');
            $out[] = 'Look at their shift and breaks together and ask what wears them out.' . ($said ? ' They wrote: "' . $said . '"' : '');
        }
        if ($has('Feels unseen')) {
            $said = $quote('valued_why');
            $out[] = 'Thank them for a specific recent win, in front of the team.' . ($said ? ' They wrote: "' . $said . '"' : '');
        }
        if ($has('Wants more support')) {
            $said = $quote('support_why');
            $out[] = 'Check how often they get time with their team leader or manager.' . ($said ? ' They wrote: "' . $said . '"' : '');
        }
        if ($has('Needs training or tools') || trim((string) ($a['skill'] ?? '')) !== '') {
            $skill = $quote('skill') ?: $quote('tools_why');
            $out[] = $skill ? 'Offer training on what they asked for: "' . $skill . '".' : 'Ask which training or tool would help them most.';
        }
        if ($has('Struggling, not overloaded')) {
            $out[] = 'Sit in on a few of their calls: the fix may be technique, not hours.';
        }
        if ($has('Wants to grow')) {
            $out[] = 'Give them a small leadership task (for example, buddy for a new joiner) and talk about what promotion takes.';
        }
        if ($has('Money-driven')) {
            $out[] = 'Walk through the incentive plan with them: how far they are from the next slab and what gets them there.';
        }
        if ($has('Wants balance')) {
            $out[] = 'Ask which shift pattern would suit them better.';
        }
        if ($has('Champion')) {
            $out[] = 'Ask them to refer friends who would fit, or to look after a new joiner.';
        }
        if ($has('Wrote nothing') || $has('Same score everywhere') || $has('Rushed it')) {
            $out[] = 'The form got little from them. A relaxed chat will tell you more than the scores.';
        }
        if (!$out) {
            $out[] = $level === 'low' ? 'Keep doing what works. Tell them their answers were read.' : 'Ask how things are going and what would make next month better.';
        }
        return array_slice($out, 0, 5);
    }

    // ---- the whole team ---------------------------------------------------------

    /**
     * Plain-English findings across everyone, most important first, plus the
     * people to talk to first, the themes in what people wrote, and centre/role
     * breakdowns.
     *
     * @param array $responses from StaffSurveyService::results()
     * @param array $reads     user_id => person() result
     */
    public static function team(array $responses, array $reads, array $pending, array $centreLabels): array
    {
        $n = count($responses);
        $findings = [];
        $add = function (string $tone, string $icon, string $text) use (&$findings) {
            $findings[] = ['tone' => $tone, 'icon' => $icon, 'text' => $text];
        };
        if (!$n) {
            return ['findings' => [], 'priority' => [], 'themes' => [], 'groups' => [], 'written' => null];
        }
        $names = fn(array $rs) => implode(', ', array_map(fn($r) => explode(' ', trim($r['name']))[0], array_slice($rs, 0, 4))) . (count($rs) > 4 ? ' and ' . (count($rs) - 4) . ' more' : '');

        if ($n < 5) {
            $add('grey', 'info', "Only {$n} " . ($n === 1 ? 'answer' : 'answers') . ' so far. Treat the patterns below as early signs, not conclusions.');
        }

        $high = array_values(array_filter($responses, fn($r) => $reads[$r['user_id']]['risk']['level'] === 'high'));
        $medium = array_values(array_filter($responses, fn($r) => $reads[$r['user_id']]['risk']['level'] === 'medium'));
        if ($high) {
            $add('red', 'logout', count($high) . ' of ' . $n . ' show a high risk of leaving: ' . $names($high) . '.');
        }
        if ($medium) {
            $add('amber', 'help', count($medium) . ' more ' . (count($medium) === 1 ? 'is' : 'are') . ' wavering: ' . $names($medium) . '.');
        }
        $tagged = function (string $label) use ($responses, $reads) {
            return array_values(array_filter($responses, fn($r) => in_array($label, array_column($reads[$r['user_id']]['tags'], 'label'), true)));
        };
        if ($x = $tagged('Top performer at risk')) {
            $add('red', 'star', 'Strong performers who may leave: ' . $names($x) . '. These are the costliest to lose.');
        }
        if ($x = $tagged('Burnout warning')) {
            $add('red', 'local_fire_department', count($x) . ' show burnout signs (drained or stressed, and the workload is too much): ' . $names($x) . '.');
        }
        if ($x = $tagged('Happy now, leaving later')) {
            $add('amber', 'trending_down', count($x) . ' like the job today but don\'t see a future here (' . $names($x) . '). That is usually about growth or pay: a clear path could keep them.');
        }

        // Weakest and strongest areas.
        $avgs = [];
        foreach (self::SCALES as $k) {
            $vals = array_values(array_filter(array_map(fn($r) => $r['answers'][$k] ?? null, $responses), fn($v) => $v !== null));
            if ($vals) {
                $avgs[$k] = array_sum($vals) / count($vals);
            }
        }
        $areaName = ['happy' => 'overall happiness', 'valued' => 'feeling valued', 'support' => 'support from managers', 'tools' => 'training and tools', 'workload' => 'workload and timings'];
        if (count($avgs) >= 2) {
            asort($avgs);
            $weak = array_key_first($avgs);
            $strong = array_key_last($avgs);
            if ($avgs[$weak] < 3.5) {
                $add($avgs[$weak] < 2.5 ? 'red' : 'amber', 'arrow_downward', 'Weakest area: ' . $areaName[$weak] . ' (' . number_format($avgs[$weak], 1) . ' out of 5).');
            }
            if ($avgs[$strong] >= 3.5 && $strong !== $weak) {
                $add('green', 'arrow_upward', 'Strongest area: ' . $areaName[$strong] . ' (' . number_format($avgs[$strong], 1) . ' out of 5).');
            }
        }

        // What people ask for and want.
        $top = function (string $key) use ($responses) {
            $c = [];
            foreach ($responses as $r) {
                foreach ((array) ($r['answers'][$key] ?? []) as $v) {
                    $c[$v] = ($c[$v] ?? 0) + 1;
                }
            }
            arsort($c);
            return $c;
        };
        $ask = $top('help');
        if ($ask) {
            $k = array_key_first($ask);
            $add('blue', 'volunteer_activism', 'Top ask: ' . lcfirst($k) . ' (' . $ask[$k] . ' of ' . $n . ').' . (count($ask) > 1 ? ' Next: ' . lcfirst(array_keys($ask)[1]) . '.' : ''));
        }
        $goal = $top('goals');
        if ($goal) {
            $k = array_key_first($goal);
            $add('blue', 'flag', 'What people are working for: ' . lcfirst($k) . ' (' . $goal[$k] . ' of ' . $n . ').');
        }

        // Centre and role gaps (only with at least 2 answers in each group compared).
        $groups = ['centre' => [], 'role' => []];
        foreach ($responses as $r) {
            foreach (['centre' => $r['centre'] ?: 'unknown', 'role' => $r['role']] as $dim => $g) {
                $groups[$dim][$g][] = $r;
            }
        }
        $groupRows = [];
        foreach ($groups as $dim => $gs) {
            foreach ($gs as $g => $rs) {
                $groupRows[$dim][$g] = self::groupStats($rs, $reads);
            }
        }
        $gap = function (string $dim, string $metric, string $what) use ($groupRows, $add, $centreLabels) {
            $rows = array_filter($groupRows[$dim] ?? [], fn($s, $g) => $s['n'] >= 2 && $s[$metric] !== null && $g !== 'unknown', ARRAY_FILTER_USE_BOTH);
            if (count($rows) < 2) {
                return;
            }
            uasort($rows, fn($x, $y) => $y[$metric] <=> $x[$metric]);
            $hi = array_key_first($rows);
            $lo = array_key_last($rows);
            if ($rows[$hi][$metric] - $rows[$lo][$metric] >= 0.8) {
                $label = fn($g) => $dim === 'centre' ? ($centreLabels[$g] ?? $g)
                    : (['agent' => 'Agents', 'csa' => 'CSAs', 'supervisor' => 'Supervisors', 'manager' => 'Managers'][$g] ?? ucfirst($g));
                $add('amber', 'compare_arrows', $label($lo) . ' trails ' . $label($hi) . ' on ' . $what . ' (' . number_format($rows[$lo][$metric], 1) . ' vs ' . number_format($rows[$hi][$metric], 1) . ').');
            }
        };
        $gap('centre', 'happy', 'happiness');
        $gap('role', 'support', 'feeling supported');

        // Written answers: how many people actually wrote, and the themes.
        $wrote = count(array_filter($reads, fn($p) => $p['taking']['written'] > 0));
        $themes = self::themes($responses);
        if ($themes) {
            $t = $themes[0];
            $add('blue', 'forum', 'Most talked-about topic in written answers: ' . strtolower($t['label']) . ' (' . $t['people'] . ' ' . ($t['people'] === 1 ? 'person' : 'people') . ').');
        }
        $quiet = $n - $wrote;
        if ($quiet > 0) {
            $add('grey', 'edit_off', $quiet . ' of ' . $n . ' wrote nothing in their own words. Anyone who wrote nothing (or just one line) is asked once more, at their next sign-in, for a few lines.');
        }
        $careless = array_filter($reads, fn($p) => $p['taking']['straight'] || $p['taking']['rushed']);
        if ($careless) {
            $add('grey', 'speed', count($careless) . ' rushed or gave the same score to everything. Their scores are less reliable.');
        }

        // Talk to first: highest risk, then most red/amber tags.
        $priority = [];
        foreach ($responses as $r) {
            $p = $reads[$r['user_id']];
            $weight = $p['risk']['score'] + 10 * count(array_filter($p['tags'], fn($t) => $t['tone'] === 'red')) + 4 * count(array_filter($p['tags'], fn($t) => $t['tone'] === 'amber'));
            if ($p['risk']['level'] !== 'low' || array_filter($p['tags'], fn($t) => $t['tone'] === 'red')) {
                $priority[] = ['r' => $r, 'p' => $p, 'weight' => $weight];
            }
        }
        usort($priority, fn($x, $y) => $y['weight'] <=> $x['weight']);

        return [
            'findings' => $findings,
            'priority' => array_slice($priority, 0, 8),
            'themes' => $themes,
            'groups' => $groupRows,
            'written' => ['wrote' => $wrote, 'of' => $n],
        ];
    }

    private static function groupStats(array $rs, array $reads): array
    {
        $avg = function (string $k) use ($rs) {
            $v = array_values(array_filter(array_map(fn($r) => $r['answers'][$k] ?? null, $rs), fn($x) => $x !== null));
            return $v ? array_sum($v) / count($v) : null;
        };
        $recs = array_values(array_filter(array_map(fn($r) => $r['answers']['recommend'] ?? null, $rs), fn($x) => $x !== null));
        $enps = $recs ? (int) round((count(array_filter($recs, fn($v) => $v >= 9)) - count(array_filter($recs, fn($v) => $v <= 6))) / count($recs) * 100) : null;
        $staying = count(array_filter($rs, fn($r) => in_array($r['answers']['stay'] ?? null, self::STAYING, true)));
        return [
            'n' => count($rs), 'happy' => $avg('happy'), 'support' => $avg('support'), 'workload' => $avg('workload'), 'enps' => $enps,
            'stay' => (int) round($staying / count($rs) * 100),
            'risk' => count(array_filter($rs, fn($r) => ($reads[$r['user_id']]['risk']['level'] ?? '') === 'high')),
        ];
    }

    /**
     * Themes across every written answer: how many people raised each, and a
     * few short quotes (with who said it, for the admin). Most-raised first.
     */
    public static function themes(array $responses): array
    {
        $out = [];
        foreach ($responses as $r) {
            $a = $r['answers'];
            foreach (StaffSurveyService::writtenAsked($a) as $key => $q) {
                $text = trim((string) ($a[$key] ?? ''));
                if ($text === '') {
                    continue;
                }
                $words = preg_split('/[^\p{L}\p{M}\p{N}\']+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
                foreach (self::THEMES as $t => [$label, $terms]) {
                    if (array_intersect($words, $terms)) {
                        $out[$t]['label'] = $label;
                        $out[$t]['who'][$r['user_id']] = true;
                        if (count($out[$t]['quotes'] ?? []) < 3 && empty($out[$t]['quoted'][$r['user_id']])) {
                            $out[$t]['quotes'][] = ['text' => self::snippet($text, 160), 'name' => $r['name'], 'user_id' => $r['user_id'], 'q' => $q['label']];
                            $out[$t]['quoted'][$r['user_id']] = true;
                        }
                    }
                }
            }
        }
        $list = [];
        foreach ($out as $t => $v) {
            $list[] = ['key' => $t, 'label' => $v['label'], 'people' => count($v['who']), 'quotes' => $v['quotes'] ?? []];
        }
        usort($list, fn($x, $y) => [$y['people'], $x['label']] <=> [$x['people'], $y['label']]);
        return $list;
    }

    private static function snippet(mixed $text, int $max = 140): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
    }
}
