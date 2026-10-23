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
        ['Safety rules', ['cap_pct', 'quality_limit', 'quality_cut']],
        ['What a good night looks like', ['calls_target', 'conv_target', 'pps_target']],
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
}
