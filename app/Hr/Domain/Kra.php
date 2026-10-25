<?php

namespace App\Hr\Domain;

use App\Hr\Crm\CrmGateway;
use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;
use App\Services\Kra\KraEngine;

/**
 * KRA & incentives — the admin's side (owner's brief, 23 Oct 2026): the whole
 * KRA runs on what is filled in here. The rules for a month and each person's
 * monthly cost / target; the CRM's "My KRA" page, Aisha and the incentive all
 * follow them through KraEngine. A month carries forward until changed, so the
 * admin only saves again when something changes.
 */
final class Kra
{
    /** The rules form, grouped as the screen shows it. */
    private const GROUPS = [
        ['The month', ['usd_inr', 'breakeven_inr']],
        ['Agent levels', ['l1_multiple', 'target_multiple', 'l3_ratio', 'rate1', 'rate2', 'rate3']],
        ['Team leaders and managers', ['tl_share', 'tl_bonus_pct', 'tl_bonus_inr', 'mgr_share']],
        ['Safety rules', ['cap_pct', 'quality_limit', 'quality_cut', 'refund_cutoff']],
        ['What a good night looks like', ['calls_target', 'conv_target', 'pps_target', 'rpc_target']],
        ['Spot rewards (paid automatically, within the budget)', ['spot_first_sale', 'spot_best_week', 'spot_best_month', 'spot_budget']],
    ];

    private static function engine(): KraEngine
    {
        return new KraEngine(Kernel::crm());
    }

    /** This month, or next month when asked (set it up ahead). Past months stay as they were. */
    private static function month(Ctx $c, string $key = 'month'): string
    {
        $want = $c->has($key) ? (string) $c->raw($key) : (string) ($c->query($key, 7) ?? '');
        $now = date('Y-m');
        $next = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));
        return in_array($want, [$now, $next], true) ? $want : $now;
    }

    /** This month or one of the eleven before it (insights and payouts look back). */
    private static function pastMonth(Ctx $c, string $key = 'month'): string
    {
        $want = $c->has($key) ? (string) $c->raw($key) : (string) ($c->query($key, 7) ?? '');
        if ($want === '') {
            return date('Y-m');
        }
        foreach (self::lookback() as $m) {
            if ($m['value'] === $want) {
                return $want;
            }
        }
        throw ApiError::invalid('That month is outside the last twelve.');
    }

    /** @return array<array{value:string,label:string}> this month first */
    private static function lookback(int $n = 12): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $m = date('Y-m', strtotime(date('Y-m-01') . " -{$i} months"));
            $out[] = ['value' => $m, 'label' => ($i === 0 ? 'This month · ' : '') . date('F Y', strtotime($m . '-01'))];
        }
        return $out;
    }

    private static function name(int $crmUserId): string
    {
        return (string) (Kernel::crm()->table('users')->where('id', $crmUserId)->value('name') ?? ('#' . $crmUserId));
    }

    public static function settings(Ctx $c): array
    {
        $month = self::month($c);
        $eng = self::engine();
        $cfg = $eng->config($month);
        $saved = $cfg !== null;
        $rules = $saved ? $cfg : KraEngine::DEFAULTS;
        $people = $eng->people($month);

        $users = Kernel::crm()->table('users')->whereNull('deleted_at')->where('status', 'active')
            ->whereIn('role', ['agent', 'supervisor', 'manager'])->orderByRaw("FIELD(role, 'manager', 'supervisor', 'agent')")->orderBy('name')
            ->get(['id', 'name', 'role', 'reports_to_id']);
        $names = $users->pluck('name', 'id');
        $rows = [];
        foreach ($users as $u) {
            $p = $people[(int) $u->id] ?? ['cost' => null, 'target' => null];
            $lv = $u->role === 'manager' ? null : KraEngine::levels($rules, $p['cost'], $p['target']);
            $rows[] = [
                'id'      => (int) $u->id,
                'name'    => $u->name,
                'role'    => CrmGateway::ROLE_LABELS[$u->role] ?? $u->role,
                'role_key'=> $u->role,
                'leader'  => $u->reports_to_id ? ($names[$u->reports_to_id] ?? null) : null,
                'cost'    => $p['cost'],
                'target'  => $p['target'],
                'levels'  => $lv,
            ];
        }

        $groups = [];
        foreach (self::GROUPS as [$title, $keys]) {
            $groups[] = ['title' => $title, 'fields' => array_map(fn($k) => [
                'key' => $k, 'label' => KraEngine::FIELDS[$k][0], 'unit' => KraEngine::FIELDS[$k][4],
                'min' => KraEngine::FIELDS[$k][2], 'max' => KraEngine::FIELDS[$k][3], 'value' => $rules[$k],
            ], $keys)];
        }

        return [
            'month'      => $month,
            'month_name' => date('F Y', strtotime($month . '-01')),
            'months'     => [
                ['value' => date('Y-m'), 'label' => 'This month · ' . date('F Y')],
                ['value' => date('Y-m', strtotime(date('Y-m-01') . ' +1 month')), 'label' => 'Next month · ' . date('F Y', strtotime(date('Y-m-01') . ' +1 month'))],
            ],
            'saved'      => $saved,
            'from_month' => $saved ? date('F Y', strtotime($cfg['_from_month'] . '-01')) : null,
            'inherited'  => $saved && $cfg['_from_month'] !== $month,
            'groups'     => $groups,
            'people'     => $rows,
        ];
    }

    public static function saveRules(Ctx $c): array
    {
        $month = self::month($c);
        $vals = [];
        $errors = [];
        foreach (KraEngine::FIELDS as $k => [$label, , $min, $max]) {
            $v = $c->raw($k);
            if ($v === null || $v === '' || !is_numeric($v)) {
                $errors[$k] = $label . ' is needed.';
                continue;
            }
            $n = (float) $v;
            if ($n < $min || $n > $max) {
                $errors[$k] = $label . " should be from {$min} to {$max}.";
                continue;
            }
            $vals[$k] = $n;
        }
        if (!$errors && $vals['l1_multiple'] > $vals['target_multiple']) {
            $errors['l1_multiple'] = 'Level 1 has to be below the target.';
        }
        if ($errors) {
            throw ApiError::invalid('Please check the highlighted numbers.', $errors);
        }
        Kernel::crm()->table('kra_months')->updateOrInsert(['month' => $month], [
            'config' => json_encode($vals), 'saved_by' => $c->accountId(), 'saved_at' => Kernel::now(),
        ]);
        Audit::log('kra.rules', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' saved the KRA rules for ' . date('F Y', strtotime($month . '-01')), 'detail' => $vals]);
        return ['message' => 'KRA rules saved for ' . date('F Y', strtotime($month . '-01')) . '. Staff see them on their next page load.'];
    }

    /** {month, people: [{id, cost, target}]} — costs in ₹, targets in $ (blank = worked out from cost). */
    public static function savePeople(Ctx $c): array
    {
        $month = self::month($c);
        $list = $c->raw('people');
        if (!is_array($list) || count($list) > 500) {
            throw ApiError::invalid('Nothing to save.');
        }
        $valid = Kernel::crm()->table('users')->whereIn('role', ['agent', 'supervisor', 'manager'])->pluck('id')->map(fn($v) => (int) $v)->flip()->all();
        $errors = [];
        $rows = [];
        foreach ($list as $i => $p) {
            $id = (int) ($p['id'] ?? 0);
            if (!isset($valid[$id])) {
                continue;
            }
            $cost = $p['cost'] ?? null;
            $target = $p['target'] ?? null;
            $cost = ($cost === '' || $cost === null) ? null : $cost;
            $target = ($target === '' || $target === null) ? null : $target;
            if ($cost !== null && (!is_numeric($cost) || $cost < 0 || $cost > 10000000)) {
                $errors["cost_{$id}"] = 'Monthly cost should be a number of rupees.';
                continue;
            }
            if ($target !== null && (!is_numeric($target) || $target < 0 || $target > 1000000)) {
                $errors["target_{$id}"] = 'Target should be a number of dollars.';
                continue;
            }
            $rows[$id] = ['cost' => $cost === null ? null : (int) round((float) $cost), 'target' => $target === null ? null : round((float) $target, 2)];
        }
        if ($errors) {
            throw ApiError::invalid('Please check the highlighted rows.', $errors);
        }
        $now = Kernel::now();
        foreach ($rows as $id => $r) {
            Kernel::crm()->table('kra_people')->updateOrInsert(['month' => $month, 'crm_user_id' => $id], [
                'monthly_cost' => $r['cost'], 'target_usd' => $r['target'], 'saved_by' => $c->accountId(), 'saved_at' => $now,
            ]);
        }
        Audit::log('kra.people', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' saved KRA costs and targets for ' . count($rows) . ' people']);
        return ['message' => 'Saved costs and targets for ' . count($rows) . ' ' . (count($rows) === 1 ? 'person' : 'people') . '.'];
    }

    // =========================================================================
    // INSIGHTS (phase 2)
    // =========================================================================

    public static function insights(Ctx $c): array
    {
        $month = self::pastMonth($c);
        $eng = self::engine();
        $snap = $eng->snapshot($month);
        $base = ['month' => $month, 'month_name' => date('F Y', strtotime($month . '-01')), 'months' => self::lookback()];
        if ($snap === null) {
            return $base + ['live' => false];
        }
        $cfg = $snap['config'];
        $people = $snap['people'];
        $sellers = array_filter($people, fn($p) => in_array($p['role'], ['agent', 'supervisor'], true));
        $names = array_map(fn($p) => $p['name'], $people);

        $mco = 0.0; $target = 0.0; $proj = 0.0; $past = 0; $setUp = 0; $calls = 0; $sales = 0; $noLog = [];
        $loggedMco = 0.0;   // revenue per call counts only people who log calls
        foreach ($sellers as $p) {
            $mco += $p['mco'];
            if ($p['calls'] > 0) {
                $loggedMco += $p['mco'];
            }
            $proj += $p['projected'];
            $calls += $p['calls'];
            $sales += $p['sales'];
            if ($p['set_up']) {
                $setUp++;
                $target += $p['levels']['l2'];
                if ($p['mco'] >= $p['levels']['l1']) {
                    $past++;
                }
            }
            if ($p['shifts'] >= 2 && $p['calls'] === 0) {
                $noLog[] = $p['name'];
            }
        }
        [$from, $to] = KraEngine::monthBounds($month);
        // Not every sale goes through an authorization link, so these are compared with each other, never with sales.
        $authRow = Kernel::crm()->table('acceptance_requests')->whereIn('agent_id', array_keys($sellers) ?: [0])
            ->where('is_preauth', 0)->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) AS sent, SUM(approved_at IS NOT NULL) AS signed')->first();
        $auths  = (int) ($authRow->sent ?? 0);
        $signed = (int) ($authRow->signed ?? 0);

        $now = KraEngine::statementFrom($snap, false);
        $fc  = $snap['is_current'] ? KraEngine::statementFrom($snap, true) : $now;
        // A closed month shows what was locked for payroll, not a fresh recount.
        $locked = self::closedMonth($month) !== null
            ? Kernel::crm()->table('kra_payouts')->where('month', $month)->pluck('total_inr', 'crm_user_id')->map(fn($v) => (int) $v)->all()
            : null;
        if ($locked !== null) {
            $now['before_cap'] = array_sum($locked);
            $now['scale'] = 1.0;
            $fc = $now;
        }
        $feedbackCount = Kernel::crm()->table('kra_feedback')->groupBy('crm_user_id')
            ->selectRaw('crm_user_id, COUNT(*) AS n')->pluck('n', 'crm_user_id');

        $rows = [];
        foreach ($people as $p) {
            $lvAtPace = null;
            // Revenue per call: a manager's is their floor's; everyone else's is their own
            $rpc = $p['role'] === 'manager'
                ? (($p['team']['rpc'] ?? null) === null ? null : ['text' => KraEngine::money((float) $p['team']['rpc']), 'ok' => $p['team']['rpc'] >= (float) $cfg['rpc_target']])
                : ($p['levers'][3]['value'] === null ? null : ['text' => $p['levers'][3]['text'], 'ok' => $p['levers'][3]['ok']]);
            if ($p['levels'] !== null) {
                $lvAtPace = $p['projected'] >= $p['levels']['l3'] ? 3 : ($p['projected'] >= $p['levels']['l2'] ? 2 : ($p['projected'] >= $p['levels']['l1'] ? 1 : 0));
            }
            $rows[] = [
                'id'        => $p['id'],
                'name'      => $p['name'],
                'role'      => CrmGateway::ROLE_LABELS[$p['role']] ?? $p['role'],
                'role_key'  => $p['role'],
                'leader'    => $p['reports_to'] !== null ? ($names[$p['reports_to']] ?? null) : null,
                'set_up'    => $p['set_up'],
                'progress'  => $p['role'] === 'manager' ? ($p['team']['progress'] ?? null) : $p['progress'],
                'mco'       => $p['role'] === 'manager' ? ($p['team']['mco'] ?? 0) : $p['mco'],
                'projected' => $p['role'] === 'manager' ? ($p['team']['projected'] ?? 0) : $p['projected'],
                'level_at_pace' => $lvAtPace,
                'rpc'       => $rpc,
                'weakest'   => $p['gap'] ? preg_replace('/^Your biggest gap · /', '', $p['gap']['title']) : null,
                'quality_hit' => $p['quality_hit'],
                'incentive' => $locked !== null ? ($locked[$p['id']] ?? 0) : $p['incentive_total'],
                'feedback'  => (int) ($feedbackCount[$p['id']] ?? 0),
            ];
        }
        usort($rows, fn($a, $b) => [$a['role_key'] === 'manager' ? 0 : 1, -($a['progress'] ?? -1)] <=> [$b['role_key'] === 'manager' ? 0 : 1, -($b['progress'] ?? -1)]);

        // What to do this week — plain facts, worked out, never invented.
        $actions = [];
        $convTarget = max(1, (float) $cfg['conv_target']);
        $scale = $snap['is_current'] ? $snap['days_in'] / max(1, $snap['day_now']) : 1;
        $pps = $sales > 0 ? $mco / $sales : (float) $cfg['pps_target'];
        if ($calls > 0 && $sales / $calls < 1 / $convTarget) {
            $worth = max(0, $calls * $scale / $convTarget - $sales * $scale) * $pps;
            $actions[] = 'The floor turns 1 in ' . max(1, (int) round($calls / max(1, $sales))) . ' logged calls into a sale; the target is 1 in ' . (int) $convTarget . '.'
                . ($worth > 0 ? ' Reaching it is worth about $' . number_format($worth) . ' more Net MCO this month — closing practice with the team leaders pays first.' : '');
        }
        $rpcTarget = (float) $cfg['rpc_target'];
        $floorRpc = $calls > 0 ? $loggedMco / $calls : null;
        if ($floorRpc !== null && $floorRpc < $rpcTarget) {
            $actions[] = 'The floor makes ' . KraEngine::money($floorRpc) . ' Net MCO per logged call; the revenue-per-call target is ' . KraEngine::money($rpcTarget) . '.'
                . ' That gap closes through more sales from the same calls and more profit on each sale.';
        }
        $behind = array_values(array_filter($rows, fn($r) => $r['role_key'] !== 'manager' && $r['set_up'] && $r['progress'] !== null));
        usort($behind, fn($a, $b) => $a['progress'] <=> $b['progress']);
        if (count($behind) >= 2 && $behind[0]['progress'] < 60) {
            $actions[] = 'Furthest behind: ' . $behind[0]['name'] . ' (' . $behind[0]['progress'] . '%) and ' . $behind[1]['name'] . ' (' . $behind[1]['progress'] . '%). A word from their team leader this week matters most.';
        }
        if ($noLog) {
            $actions[] = count($noLog) . ' ' . (count($noLog) === 1 ? 'person has' : 'people have') . ' worked shifts with no calls logged (' . implode(', ', array_slice($noLog, 0, 4)) . (count($noLog) > 4 ? '…' : '') . '), so their conversion can\'t be measured.';
        }
        $bSpent = array_sum(array_column($snap['boosters'], 'spent'));
        if ($bSpent > 0) {
            $bMco = array_sum(array_column($snap['boosters'], 'mco'));
            $actions[] = 'Boosters this month paid ₹' . number_format($bSpent) . ' on sales worth $' . number_format($bMco) . ' MCO (' . round($bMco * (float) $cfg['usd_inr'] / max(1, $bSpent), 1) . '× what they cost).';
        }
        if ($fc && $fc['scale'] < 1) {
            $actions[] = 'At this pace incentives would pass the cap: everyone\'s payout would be scaled to ' . round($fc['scale'] * 100) . '%. Raising the floor\'s profit, or the cap, changes that.';
        }

        $active = array_values(array_filter($snap['boosters'], fn($b) => $b['status'] === 'active'));
        return $base + [
            'live'       => true,
            'is_current' => $snap['is_current'],
            'closed'     => $locked !== null,
            'days_left'  => $snap['days_left'],
            'floor'      => ['mco' => round($mco, 2), 'target' => round($target), 'progress' => $target > 0 ? round($mco / $target * 100) : null,
                             'projected' => round($proj, 2), 'past_l1' => $past, 'set_up' => $setUp, 'sellers' => count($sellers)],
            'money'      => ['so_far' => $now ? $now['before_cap'] : 0, 'projected' => $fc ? $fc['before_cap'] : 0,
                             'cap' => $fc ? $fc['cap'] : 0, 'scale' => $fc ? $fc['scale'] : 1, 'breakeven_set' => $fc ? $fc['breakeven_set'] : false],
            'booster'    => $active ? ['rule' => $active[0]['rule'], 'spent' => $active[0]['spent'], 'budget' => $active[0]['budget'],
                                       'sales' => $active[0]['sales'], 'mco' => $active[0]['mco'], 'ends_at' => $active[0]['ends_at']] : null,
            'funnel'     => ['calls' => $calls, 'auths' => $auths, 'signed' => $signed, 'sales' => $sales, 'pps' => $sales > 0 ? round($mco / $sales) : null,
                             'rpc' => $floorRpc === null ? null : KraEngine::money($floorRpc), 'rpc_ok' => $floorRpc !== null && $floorRpc >= $rpcTarget],
            'rpc_target' => KraEngine::money($rpcTarget),
            'people'     => $rows,
            'actions'    => $actions,
        ];
    }

    // =========================================================================
    // FEEDBACK (phase 2): written in HR, shown on the person's KRA card
    // =========================================================================

    public static function feedback(Ctx $c): array
    {
        $id = (int) ($c->query('id', 12) ?? 0);
        $user = Kernel::crm()->table('users')->where('id', $id)->first(['id', 'name']);
        if ($user === null) {
            throw ApiError::notFound('That person');
        }
        $list = Kernel::crm()->table('kra_feedback')->where('crm_user_id', $id)->orderByDesc('id')->limit(20)
            ->get(['id', 'body', 'created_at'])->map(fn($f) => ['id' => (int) $f->id, 'body' => $f->body, 'at' => $f->created_at])->all();
        return ['id' => (int) $user->id, 'name' => $user->name, 'feedback' => $list];
    }

    public static function saveFeedback(Ctx $c): array
    {
        $id = (int) $c->intVal('id', 1, PHP_INT_MAX, true, 'The person');
        $body = $c->text('body', 2000);
        if ($body === null) {
            throw ApiError::invalid('Please write the feedback.', ['body' => 'Feedback is needed.']);
        }
        $user = Kernel::crm()->table('users')->where('id', $id)->whereIn('role', ['agent', 'supervisor', 'manager'])->first(['id', 'name']);
        if ($user === null) {
            throw ApiError::notFound('That person');
        }
        $now = Kernel::now();
        Kernel::crm()->table('kra_feedback')->insert(['crm_user_id' => $id, 'body' => $body, 'written_by' => $c->accountId(), 'created_at' => $now]);
        // The CRM bell, so they see it tonight rather than whenever they next open the page.
        try {
            Kernel::crm()->table('notifications')->insert([
                'user_id' => $id, 'type' => 'kra_feedback', 'transaction_id' => null,
                'title' => 'New feedback on your KRA', 'body' => mb_substr($body, 0, 140), 'link' => '/kra',
                'read_at' => null, 'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[Trio HR] KRA feedback bell failed: ' . $e->getMessage());
        }
        Audit::log('kra.feedback', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' wrote KRA feedback for ' . $user->name]);
        return ['message' => 'Feedback sent to ' . Fmt::first((string) $user->name) . '. It shows on their KRA page and their bell.'];
    }

    // =========================================================================
    // DAILY BOOSTERS (phase 3)
    // =========================================================================

    public static function boosters(Ctx $c): array
    {
        $snap = self::engine()->snapshot(date('Y-m'));
        $teams = Kernel::crm()->table('users')->where('role', 'supervisor')->where('status', 'active')->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name'])->map(fn($u) => ['value' => 'team:' . $u->id, 'label' => $u->name . '\'s team'])->all();
        $names = Kernel::crm()->table('users')->where('role', 'supervisor')->pluck('name', 'id');
        $list = [];
        foreach (array_reverse($snap['boosters'] ?? []) as $b) {
            $list[] = [
                'id' => $b['id'], 'rule' => $b['rule'], 'status' => $b['status'], 'spent' => $b['spent'], 'budget' => $b['budget'],
                'sales' => $b['sales'], 'mco' => $b['mco'], 'starts_at' => $b['starts_at'], 'ends_at' => $b['ends_at'],
                'who' => preg_match('/^team:(\d+)$/', $b['scope'], $m) ? (($names[(int) $m[1]] ?? 'A team leader') . '\'s team') : 'Everyone selling',
            ];
        }
        // Default end: the next 6 AM — the end of a night.
        $end = strtotime(date('Y-m-d 06:00:00'));
        if ($end <= time() + 3600) {
            $end += 86400;
        }
        return ['live' => $snap !== null, 'teams' => $teams, 'boosters' => $list, 'default_until' => date('Y-m-d\TH:i', $end)];
    }

    public static function createBooster(Ctx $c): array
    {
        if (self::engine()->config(date('Y-m')) === null) {
            throw ApiError::invalid('Save this month\'s KRA rules first — boosters run inside the KRA.');
        }
        $amount = $c->intVal('amount', 1, 100000, true, 'Amount per sale');
        $budget = $c->intVal('budget', 1, 1000000, true, 'Budget for the night');
        $min = $c->raw('min_mco');
        $min = ($min === null || $min === '') ? null : $min;
        if ($min !== null && (!is_numeric($min) || $min < 0 || $min > 100000)) {
            throw ApiError::invalid('Please check the form.', ['min_mco' => 'Minimum MCO should be a number of dollars.']);
        }
        if ($budget < $amount) {
            throw ApiError::invalid('Please check the form.', ['budget' => 'The budget has to cover at least one sale.']);
        }
        $until = strtotime(str_replace('T', ' ', (string) $c->str('until', 20, true, 'End time')));
        if ($until === false || $until < time() + 300 || $until > time() + 7 * 86400) {
            throw ApiError::invalid('Please check the form.', ['until' => 'Pick an end time between 5 minutes and 7 days from now.']);
        }
        $scope = (string) ($c->str('scope', 20) ?? 'all');
        if ($scope !== 'all') {
            if (!preg_match('/^team:(\d+)$/', $scope, $m)
                || !Kernel::crm()->table('users')->where('id', (int) $m[1])->where('role', 'supervisor')->exists()) {
                throw ApiError::invalid('Please check the form.', ['scope' => 'Pick everyone, or one team.']);
            }
        }
        $now = Kernel::now();
        // One booster at a time for the same people, so a sale is never paid twice (and a double click can't start two).
        $clash = Kernel::crm()->table('kra_boosters')->whereNull('stopped_at')->where('ends_at', '>', $now)
            ->where('starts_at', '<', date('Y-m-d H:i:s', $until))
            ->where(fn($q) => $scope === 'all' ? $q : $q->whereIn('scope', ['all', $scope]))
            ->orderBy('ends_at')->first(['ends_at']);
        if ($clash !== null) {
            throw ApiError::conflict('A booster is already running for these people until ' . date('g:i A', strtotime((string) $clash->ends_at))
                . '. Stop it first, or start this one after it ends.');
        }
        $id = (int) Kernel::crm()->table('kra_boosters')->insertGetId([
            'amount_inr' => $amount, 'min_mco' => $min === null ? null : round((float) $min, 2), 'scope' => $scope,
            'budget_inr' => $budget, 'starts_at' => $now, 'ends_at' => date('Y-m-d H:i:s', $until),
            'stopped_at' => null, 'created_by' => $c->accountId(), 'created_at' => $now,
        ]);

        // Aisha announces it to everyone it's for (her feed delivers it straight away, through quiet mode).
        $rule = KraEngine::boosterRule($amount, $min === null ? null : (float) $min);
        $sellers = Kernel::crm()->table('users')->whereIn('role', ['agent', 'supervisor'])->where('status', 'active')
            ->whereNull('deleted_at')->get(['id', 'reports_to_id']);
        $ids = [];
        foreach ($sellers as $u) {
            if ($scope === 'all' || ($scope === 'team:' . (int) $u->reports_to_id)) {
                $ids[] = (int) $u->id;
            }
        }
        foreach ($ids as $uid) {
            try {
                Kernel::crm()->table('buddy_nudges')->insert([
                    'user_id' => $uid, 'type' => 'booster',
                    'payload_json' => json_encode(['rule' => $rule, 'ends' => date('Y-m-d H:i:s', $until), 'booster_id' => $id], JSON_UNESCAPED_UNICODE),
                    'status' => 'pending', 'dedupe_key' => 'booster:' . $id . ':' . $uid, 'created_at' => $now,
                ]);
            } catch (\Throwable $e) {
                error_log('[Trio HR] booster nudge failed: ' . $e->getMessage());
            }
        }
        Audit::log('kra.booster', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' started a booster: ' . $rule . ' until ' . date('j M g:i A', $until)]);
        return ['message' => 'Booster on until ' . KraEngine::endsLabel(date('Y-m-d H:i:s', $until)) . '. ' . count($ids) . ' ' . (count($ids) === 1 ? 'person sees' : 'people see') . ' it now, and Aisha is telling them.'];
    }

    public static function stopBooster(Ctx $c, int $id): array
    {
        $n = Kernel::crm()->table('kra_boosters')->where('id', $id)->whereNull('stopped_at')->where('ends_at', '>', Kernel::now())
            ->update(['stopped_at' => Kernel::now()]);
        if ($n !== 1) {
            throw ApiError::conflict('That booster has already ended.');
        }
        // Withdraw any announcement Aisha hasn't delivered yet. (She also drops it on delivery, so this is tidy-up.)
        try {
            Kernel::crm()->table('buddy_nudges')->where('type', 'booster')->where('status', 'pending')
                ->where('dedupe_key', 'like', 'booster:' . $id . ':%')->update(['status' => 'expired']);
        } catch (\Throwable $e) {
            error_log('[Trio HR] booster nudge withdraw failed: ' . $e->getMessage());
        }
        Audit::log('kra.booster.stop', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' stopped a booster', 'entity' => 'kra_boosters', 'entity_id' => $id]);
        return ['message' => 'Booster stopped. Sales already made under it are still paid.'];
    }

    // =========================================================================
    // PAYOUT STATEMENT (phase 3)
    // =========================================================================

    public static function payouts(Ctx $c): array
    {
        $month = self::pastMonth($c);
        return self::payoutsFor($month, self::engine()->statement($month, false));
    }

    /** @return ?object the kra_closed_months row */
    private static function closedMonth(string $month): ?object
    {
        return Kernel::crm()->table('kra_closed_months')->where('month', $month)->first();
    }

    private static function payoutsFor(string $month, ?array $st): array
    {
        $base = ['month' => $month, 'month_name' => date('F Y', strtotime($month . '-01')), 'months' => self::lookback()];
        $lock = self::closedMonth($month);
        if ($lock !== null) {
            $closed = Kernel::crm()->table('kra_payouts')->where('month', $month)->orderByDesc('total_inr')->get();
            return $base + [
                'state' => 'closed', 'closed_at' => $lock->closed_at, 'scale' => (float) $lock->scale,
                'rows' => $closed->map(fn($r) => ['id' => (int) $r->crm_user_id, 'name' => $r->name, 'role' => CrmGateway::ROLE_LABELS[$r->role] ?? $r->role,
                    'net_mco' => (float) $r->net_mco, 'monthly' => (int) $r->monthly_inr, 'team' => (int) $r->team_inr,
                    'spot' => (int) $r->spot_inr, 'booster' => (int) $r->booster_inr, 'total' => (int) $r->total_inr])->all(),
                'total' => (int) $closed->sum('total_inr'),
            ];
        }
        if ($st === null) {
            return $base + ['state' => 'not_live'];
        }
        $isCurrent = $month === date('Y-m');
        $cutoff = date('Y-m-d', strtotime(date('Y-m-01', strtotime($month . '-01 +1 month')) . ' +' . ($st['refund_cutoff'] - 1) . ' days'));
        return $base + [
            'state'     => $isCurrent ? 'running' : 'open',
            'can_close' => !$isCurrent && date('Y-m-d') > $cutoff,
            'cutoff'    => $cutoff,
            'scale'     => $st['scale'],
            'cap'       => $st['cap'],
            'before_cap'=> $st['before_cap'],
            'breakeven_set' => $st['breakeven_set'],
            'rows'      => array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'role' => CrmGateway::ROLE_LABELS[$r['role']] ?? $r['role'],
                'net_mco' => $r['net_mco'], 'monthly' => $r['monthly'], 'team' => $r['team'], 'spot' => $r['spot'],
                'booster' => $r['booster'], 'total' => $r['total']], $st['rows']),
            'total'     => $st['after_cap'],
        ];
    }

    public static function closePayouts(Ctx $c): array
    {
        $month = self::pastMonth($c);
        if ($month === date('Y-m')) {
            throw ApiError::invalid('This month is still running. It can be closed after the refund cut-off next month.');
        }
        $st = self::engine()->statement($month, false);
        $d = self::payoutsFor($month, $st);
        if ($d['state'] === 'closed') {
            throw ApiError::conflict($d['month_name'] . ' is already closed.');
        }
        if ($d['state'] !== 'open' || empty($d['can_close'])) {
            throw ApiError::invalid($d['month_name'] . ' can be closed after ' . date('j F', strtotime($d['cutoff'] ?? 'now')) . ', once late refunds are in.');
        }
        $now = Kernel::now();
        try {
            Kernel::crm()->transaction(function () use ($st, $month, $now, $c) {
                // The lock row first: a second close at the same moment fails here, before any figures are written.
                Kernel::crm()->table('kra_closed_months')->insert([
                    'month' => $month, 'scale' => $st['scale'], 'total_inr' => $st['after_cap'], 'closed_by' => $c->accountId(), 'closed_at' => $now,
                ]);
                foreach ($st['rows'] as $r) {
                    Kernel::crm()->table('kra_payouts')->insert([
                        'month' => $month, 'crm_user_id' => $r['id'], 'name' => mb_substr($r['name'], 0, 120), 'role' => $r['role'],
                        'net_mco' => $r['net_mco'], 'monthly_inr' => $r['monthly'], 'team_inr' => $r['team'], 'spot_inr' => $r['spot'],
                        'booster_inr' => $r['booster'], 'scale' => $st['scale'], 'total_inr' => $r['total'],
                        'closed_by' => $c->accountId(), 'closed_at' => $now,
                    ]);
                }
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (self::closedMonth($month) !== null) {
                throw ApiError::conflict($d['month_name'] . ' is already closed.');
            }
            throw $e;
        }
        $paid = count(array_filter($st['rows'], fn($r) => $r['total'] > 0));
        $who = '₹' . number_format($st['after_cap']) . ' for ' . $paid . ' ' . ($paid === 1 ? 'person' : 'people');
        Audit::log('kra.close', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' closed KRA incentives for ' . $d['month_name'] . ': ' . $who]);
        return ['message' => $d['month_name'] . ' closed: ' . ($paid > 0 ? $who . '. The figures are now locked for payroll.' : 'nobody earned an incentive, and the month is now locked.')];
    }

    public static function reopenPayouts(Ctx $c): array
    {
        $month = self::pastMonth($c);
        $n = Kernel::crm()->transaction(function () use ($month) {
            Kernel::crm()->table('kra_payouts')->where('month', $month)->delete();
            return Kernel::crm()->table('kra_closed_months')->where('month', $month)->delete();
        });
        if ($n === 0) {
            throw ApiError::conflict('That month is not closed.');
        }
        Audit::log('kra.reopen', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' reopened KRA incentives for ' . date('F Y', strtotime($month . '-01'))]);
        return ['message' => 'Reopened. The statement is live again until you close it.'];
    }
}
