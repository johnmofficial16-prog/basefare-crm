<?php

namespace App\Services\Kra;

use Illuminate\Database\ConnectionInterface;

/**
 * KRA & incentives — the one place the numbers are worked out.
 *
 * The admin sets the rules in Trio HR (kra_months, kra_people); this class
 * turns them plus the month's CRM activity into each person's KRA: target,
 * levels, progress, incentive so far, weakest lever and what fixing it is
 * worth. The CRM's "My KRA" page, Aisha and HR all read it, so they can never
 * disagree. Owner's brief (23 Oct 2026): the only organisational goal is more
 * sales; make it simple, show people where they lose and how to improve, and
 * pay incentives that can't hurt the company's finances.
 *
 * Money rules:
 *  - Sales are measured as Net MCO (profit after refunds), approved sales,
 *    calendar month — the same figure as the Performance tab.
 *  - Level 1 = monthly cost × l1_multiple; Level 2 = the target (cost ×
 *    target_multiple, or the admin's own figure); Level 3 = target × l3_ratio.
 *  - The incentive is paid ONLY on Net MCO above Level 1, at rate1 up to the
 *    target, rate2 up to Level 3 and rate3 beyond — a share of extra profit,
 *    so with no extra profit there is nothing to pay.
 *  - Quality gate: when refunds and disputes pass quality_limit % of sales,
 *    the incentive is cut by quality_cut %.
 *  - Team leaders add tl_share % of team MCO above the team target, plus a
 *    fixed bonus when tl_bonus_pct % of their agents pass Level 1. Managers
 *    add mgr_share % of their floor's MCO above its target.
 *  - Everything is "projected" until the month is closed; the company-wide cap
 *    (cap_pct % of profit above break-even) is checked in HR.
 *
 * Works on any connection to the CRM database: the CRM passes its own, Trio
 * HR passes Kernel::crm(). No sessions, no Eloquent, no side effects.
 */
final class KraEngine
{
    /** Starting values for the HR form. Nothing is live until the admin saves a month. */
    public const DEFAULTS = [
        'usd_inr'         => 84,     // ₹ per $1, for payouts
        'breakeven_inr'   => 0,      // company's monthly costs, for the cap
        'l1_multiple'     => 2.6,    // Level 1 = cost × this
        'target_multiple' => 4.2,    // Level 2 (target) = cost × this
        'l3_ratio'        => 1.33,   // Level 3 = target × this
        'rate1'           => 5,      // % of MCO between Level 1 and the target
        'rate2'           => 8,      // % between the target and Level 3
        'rate3'           => 10,     // % above Level 3
        'tl_share'        => 2,      // % of team MCO above team target
        'tl_bonus_pct'    => 75,     // % of agents past Level 1 that unlocks…
        'tl_bonus_inr'    => 5000,   // …this team bonus
        'mgr_share'       => 1,      // % of floor MCO above floor target
        'cap_pct'         => 25,     // all incentives ≤ this % of profit above break-even
        'quality_limit'   => 5,      // refunds + disputes, % of sales
        'quality_cut'     => 50,     // % taken off the incentive past the limit
        'calls_target'    => 40,     // calls a shift
        'conv_target'     => 16,     // one sale in this many calls
        'pps_target'      => 130,    // $ profit per sale
        'rpc_target'      => 8,      // $ Net MCO per logged call — the client's "RPC" (revenue per call)
        'spot_first_sale' => 500,    // ₹ for the first sale on the floor each shift (0 = off)
        'spot_best_week'  => 1000,   // ₹ for the floor's best single sale of the week
        'spot_best_month' => 500,    // ₹ for beating your own best month
        'spot_budget'     => 25000,  // ₹ a month for all spot rewards together
        'refund_cutoff'   => 5,      // refunds up to this day of next month are taken off before paying
    ];

    /** What each number means and its sensible range, for HR's form and validation. */
    public const FIELDS = [
        'usd_inr'         => ['Dollar rate for payouts', 'number', 50, 150, '₹ per $1'],
        'breakeven_inr'   => ['Monthly break-even (all company costs)', 'number', 0, 100000000, '₹'],
        'l1_multiple'     => ['Level 1 starts at (× monthly cost)', 'number', 1, 10, '×'],
        'target_multiple' => ['Target (× monthly cost)', 'number', 1, 20, '×'],
        'l3_ratio'        => ['Level 3 (× target)', 'number', 1.05, 3, '×'],
        'rate1'           => ['Level 1 → target earns', 'number', 0, 50, '%'],
        'rate2'           => ['Target → Level 3 earns', 'number', 0, 50, '%'],
        'rate3'           => ['Above Level 3 earns', 'number', 0, 50, '%'],
        'tl_share'        => ['Team leader: share of team MCO above team target', 'number', 0, 20, '%'],
        'tl_bonus_pct'    => ['Team bonus when this share of agents pass Level 1', 'number', 0, 100, '%'],
        'tl_bonus_inr'    => ['Team bonus amount', 'number', 0, 1000000, '₹'],
        'mgr_share'       => ['Manager: share of floor MCO above floor target', 'number', 0, 20, '%'],
        'cap_pct'         => ['All incentives together never more than (% of profit above break-even)', 'number', 0, 100, '%'],
        'quality_limit'   => ['Quality gate: refunds + disputes above (% of sales)', 'number', 0, 100, '%'],
        'quality_cut'     => ['…cuts the incentive by', 'number', 0, 100, '%'],
        'calls_target'    => ['Calls a shift (target)', 'number', 1, 300, 'calls'],
        'conv_target'     => ['One sale in every … calls (target)', 'number', 2, 500, 'calls'],
        'pps_target'      => ['Profit per sale (target)', 'number', 1, 10000, '$'],
        'rpc_target'      => ['Revenue per call — Net MCO per logged call (target)', 'number', 0.5, 1000, '$'],
        'spot_first_sale' => ['First sale of each shift', 'number', 0, 100000, '₹'],
        'spot_best_week'  => ['Best single sale of the week (whole floor)', 'number', 0, 1000000, '₹'],
        'spot_best_month' => ['New personal best month', 'number', 0, 1000000, '₹'],
        'spot_budget'     => ['Monthly budget for all spot rewards', 'number', 0, 10000000, '₹'],
        'refund_cutoff'   => ['Refunds up to this day of the next month are taken off', 'number', 1, 28, 'day'],
    ];

    /** Two concrete things to try, per lever. Shown on the card and used by Aisha. */
    public const STEPS = [
        'calls' => [
            'Take the next call within a minute of finishing the last one — short gaps add up over a night.',
            'Log every call (Alt+L) so your conversion is measured fairly.',
        ],
        'conversion' => [
            'When a customer says "I\'ll think about it", ask what\'s holding them back — price, dates or the itinerary — and solve that one thing.',
            'Send the card authorization while the customer is still on the call, not after.',
        ],
        'profit' => [
            'Offer seats, bags or a cabin upgrade on every booking where it fits.',
            'Quote the best-value fare first, not the cheapest one you can find.',
        ],
        'logging' => [
            'Log every call with Alt+L — it takes about ten seconds.',
            'Log the calls that don\'t buy too — they show where your sales slip away.',
        ],
    ];

    private ConnectionInterface $db;

    public function __construct(ConnectionInterface $db)
    {
        $this->db = $db;
    }

    public static function currentMonth(): string
    {
        return date('Y-m');
    }

    // =========================================================================
    // RULES
    // =========================================================================

    /** The rules in force for a month (latest saved month on or before it), or null if none was ever saved. */
    public function config(string $month): ?array
    {
        $row = $this->db->table('kra_months')->where('month', '<=', $month)->orderByDesc('month')->first(['month', 'config', 'saved_at']);
        if ($row === null) {
            return null;
        }
        $saved = json_decode((string) $row->config, true) ?: [];
        if (!array_key_exists('spot_budget', $saved)) {
            // Saved before spot rewards existed: they stay off until the admin turns them on.
            $saved += ['spot_first_sale' => 0, 'spot_best_week' => 0, 'spot_best_month' => 0, 'spot_budget' => 0];
        }
        $cfg = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        $cfg['_from_month'] = $row->month;
        $cfg['_saved_at'] = $row->saved_at;
        return $cfg;
    }

    /** Each person's cost and target override in force for a month. @return array<int,array{cost:?int,target:?float}> */
    public function people(string $month): array
    {
        $rows = $this->db->table('kra_people AS p')
            ->join($this->db->raw('(SELECT crm_user_id, MAX(month) AS m FROM kra_people WHERE month <= ' . $this->db->getPdo()->quote($month) . ' GROUP BY crm_user_id) AS l'),
                function ($j) {
                    $j->on('l.crm_user_id', '=', 'p.crm_user_id')->on('l.m', '=', 'p.month');
                })
            ->get(['p.crm_user_id', 'p.monthly_cost', 'p.target_usd']);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->crm_user_id] = [
                'cost'   => $r->monthly_cost === null ? null : (int) $r->monthly_cost,
                'target' => $r->target_usd === null ? null : (float) $r->target_usd,
            ];
        }
        return $out;
    }

    /** Level 1, the target (Level 2) and Level 3 in $, or null when the person isn't set up. */
    public static function levels(array $cfg, ?int $cost, ?float $targetOverride): ?array
    {
        $rate = max(1.0, (float) $cfg['usd_inr']);
        if ($targetOverride !== null && $targetOverride > 0) {
            $l2 = $targetOverride;
            $l1 = $l2 * (float) $cfg['l1_multiple'] / max(0.01, (float) $cfg['target_multiple']);
        } elseif ($cost !== null && $cost > 0) {
            $l1 = $cost * (float) $cfg['l1_multiple'] / $rate;
            $l2 = $cost * (float) $cfg['target_multiple'] / $rate;
        } else {
            return null;
        }
        $l1 = min($l1, $l2);
        return ['l1' => round($l1), 'l2' => round($l2), 'l3' => round($l2 * (float) $cfg['l3_ratio'])];
    }

    /** The monthly incentive in ₹ for a Net MCO figure (before the quality gate). */
    public static function incentive(float $mco, array $lv, array $cfg): int
    {
        $usd = max(0, min($mco, $lv['l2']) - $lv['l1']) * (float) $cfg['rate1'] / 100
             + max(0, min($mco, $lv['l3']) - $lv['l2']) * (float) $cfg['rate2'] / 100
             + max(0, $mco - $lv['l3']) * (float) $cfg['rate3'] / 100;
        return (int) round($usd * (float) $cfg['usd_inr']);
    }

    // =========================================================================
    // THE MONTH
    // =========================================================================

    /**
     * Everyone's KRA for a month. HR's overview reads all of it; the CRM picks
     * one person (card()). Null when no rules were ever saved.
     */
    public function snapshot(?string $month = null): ?array
    {
        $month = $month ?? self::currentMonth();
        $cfg = $this->config($month);
        if ($cfg === null) {
            return null;
        }
        [$from, $to, $daysIn, $dayNow, $isCurrent] = self::monthBounds($month);
        $people = $this->people($month);

        // Everyone active, plus anyone who sold this month and has since left — they still earned it.
        $soldIds = $this->db->table('transactions')->where('status', 'approved')->whereBetween('created_at', [$from, $to])
            ->distinct()->pluck('agent_id')->map(fn($v) => (int) $v)->all();
        $users = $this->db->table('users')->whereIn('role', ['agent', 'supervisor', 'manager'])
            ->where(function ($q) use ($soldIds) {
                $q->where(fn($a) => $a->whereNull('deleted_at')->where('status', 'active'));
                if ($soldIds) {
                    $q->orWhereIn('id', $soldIds);
                }
            })
            ->get(['id', 'name', 'role', 'reports_to_id'])->keyBy('id');
        $ids = $users->keys()->map(fn($v) => (int) $v)->all();

        $sales = $this->db->table('transactions')->whereIn('agent_id', $ids ?: [0])->where('status', 'approved')
            ->whereBetween('created_at', [$from, $to])->groupBy('agent_id')
            ->selectRaw("agent_id, COUNT(*) AS n, COALESCE(SUM(profit_mco - refund_mco_impact),0) AS net,
                         SUM(CASE WHEN (refund_status IS NOT NULL AND refund_status <> 'none') OR dispute_status IS NOT NULL THEN 1 ELSE 0 END) AS bad")
            ->get()->keyBy('agent_id');
        $calls = $this->db->table('call_logs')->whereIn('agent_id', $ids ?: [0])
            ->whereBetween('shift_date', [substr($from, 0, 10), substr($to, 0, 10)])
            ->groupBy('agent_id')->selectRaw('agent_id, COUNT(*) AS n')->pluck('n', 'agent_id');
        $shifts = $this->db->table('attendance_sessions')->whereIn('user_id', $ids ?: [0])
            ->whereBetween('clock_in', [$from, $to])->groupBy('user_id')
            ->selectRaw('user_id, COUNT(DISTINCT `date`) AS n')->pluck('n', 'user_id');

        $scale = $isCurrent ? $daysIn / max(1, $dayNow) : 1.0;   // month-end projection
        $out = [];
        foreach ($users as $u) {
            $id = (int) $u->id;
            $p = $people[$id] ?? ['cost' => null, 'target' => null];
            $s = $sales[$id] ?? null;
            $row = [
                'id'        => $id,
                'name'      => (string) $u->name,
                'role'      => (string) $u->role,
                'reports_to'=> $u->reports_to_id === null ? null : (int) $u->reports_to_id,
                'cost'      => $p['cost'],
                'target_override' => $p['target'],
                'mco'       => round((float) ($s->net ?? 0), 2),
                'sales'     => (int) ($s->n ?? 0),
                'bad'       => (int) ($s->bad ?? 0),
                'calls'     => (int) ($calls[$id] ?? 0),
                'shifts'    => (int) ($shifts[$id] ?? 0),
            ];
            $row['levels'] = $u->role === 'manager' ? null : self::levels($cfg, $p['cost'], $p['target']);
            $row['projected'] = round($row['mco'] * $scale, 2);
            $out[$id] = $row + $this->ownKra($row, $cfg, $scale);
        }

        // Teams: a team leader's agents; a manager's whole floor (everyone under them).
        foreach ($out as $id => &$r) {
            if ($r['role'] === 'supervisor') {
                $r['team'] = $this->teamBlock($out, $this->below($users, $id, false), $cfg, $scale, true);
            } elseif ($r['role'] === 'manager') {
                $r['team'] = $this->teamBlock($out, $this->below($users, $id, true), $cfg, $scale, false);
            }
        }
        unset($r);

        // Spot rewards and boosters, paid per sale / per moment on top.
        $sellers = array_keys(array_filter($out, fn($r) => in_array($r['role'], ['agent', 'supervisor'], true)));
        $spots = $this->spotRewards($month, $cfg, $sellers);
        $boost = $this->boosterEarnings($from, $to, $users, $sellers);
        foreach ($out as $id => &$r) {
            $r['spots'] = $spots['by_user'][$id] ?? [];
            $r['spot_inr'] = array_sum(array_map(fn($a) => $a['paid'] ? $a['amount'] : 0, $r['spots']));
            $r['booster_inr'] = (int) ($boost['by_user'][$id] ?? 0);
            $r['team_inr'] = (int) ($r['team']['share_inr'] ?? 0) + (int) ($r['team']['bonus_inr'] ?? 0);
            $r['incentive_total'] = $r['incentive'] + $r['team_inr'] + $r['spot_inr'] + $r['booster_inr'];
        }
        unset($r);

        return [
            'month'      => $month,
            'config'     => $cfg,
            'spots'      => ['spent' => $spots['spent'], 'over_budget' => $spots['over_budget']],
            'boosters'   => $boost['boosters'],
            'days_in'    => $daysIn,
            'day_now'    => $dayNow,
            'days_left'  => $isCurrent ? max(0, $daysIn - $dayNow) : 0,
            'is_current' => $isCurrent,
            'people'     => $out,
        ];
    }

    /** One person's own selling KRA: progress, level, incentive, levers, weakest gap. */
    private function ownKra(array $r, array $cfg, float $scale): array
    {
        $lv = $r['levels'];
        $levers = self::levers($r, $cfg);
        $base = ['set_up' => $lv !== null, 'incentive' => 0, 'quality_hit' => false, 'levers' => $levers, 'gap' => null,
                 'level' => 0, 'progress' => null, 'next' => null];
        if ($lv === null) {
            return $base;
        }
        $level = $r['mco'] >= $lv['l3'] ? 3 : ($r['mco'] >= $lv['l2'] ? 2 : ($r['mco'] >= $lv['l1'] ? 1 : 0));
        $qualityHit = self::qualityHit($r, $cfg);
        $inc = self::incentive($r['mco'], $lv, $cfg);
        if ($qualityHit) {
            $inc = (int) round($inc * (1 - (float) $cfg['quality_cut'] / 100));
        }
        $next = null;
        foreach ([1 => 'l1', 2 => 'l2', 3 => 'l3'] as $n => $k) {
            if ($r['mco'] < $lv[$k]) {
                $next = ['level' => $n, 'at' => $lv[$k], 'away' => round($lv[$k] - $r['mco'], 2)];
                break;
            }
        }
        return array_merge($base, [
            'incentive'   => $inc,
            'quality_hit' => $qualityHit,
            'level'       => $level,
            'progress'    => $lv['l2'] > 0 ? round($r['mco'] / $lv['l2'] * 100) : null,
            'next'        => $next,
            'gap'         => self::gap($r, $cfg, $scale, $levers),
        ]);
    }

    private static function qualityHit(array $r, array $cfg): bool
    {
        if ($r['bad'] <= 0) {
            return false;
        }
        // Small samples: one refund on two sales is not a pattern.
        if ($r['sales'] < 5) {
            return $r['bad'] >= 2;
        }
        return $r['bad'] / $r['sales'] * 100 > (float) $cfg['quality_limit'];
    }

    /**
     * The three levers (calls, conversion, profit per sale) against their
     * targets, then revenue per call — the client's "RPC". RPC is conversion ×
     * profit per sale, so it is shown and tracked but never picked as the
     * weakest area (that would count the same gap twice).
     */
    private static function levers(array $r, array $cfg): array
    {
        $cps  = $r['shifts'] > 0 ? $r['calls'] / $r['shifts'] : null;
        $conv = ($r['calls'] > 0) ? $r['sales'] / $r['calls'] : null;        // sales per call
        $pps  = $r['sales'] > 0 ? $r['mco'] / $r['sales'] : null;
        $rpc  = $r['calls'] > 0 ? $r['mco'] / $r['calls'] : null;
        $convTarget = 1 / max(1, (float) $cfg['conv_target']);
        $mk = fn($key, $label, $value, $text, $target, $targetText) => [
            'key' => $key, 'label' => $label, 'value' => $value, 'text' => $text, 'target_text' => $targetText,
            'ratio' => ($value === null || $target <= 0) ? null : $value / $target,
            'ok' => $value !== null && $value >= $target,
        ];
        return [
            $mk('calls', 'Calls a shift', $cps, $cps === null ? '—' : (string) round($cps),
                (float) $cfg['calls_target'], (string) (int) $cfg['calls_target']),
            $mk('conversion', 'Calls that turn into a sale', $conv,
                $conv === null ? ($r['shifts'] > 0 ? 'No calls logged' : '—') : ($conv > 0 ? '1 in ' . max(1, (int) round(1 / $conv)) : '0 so far'),
                $convTarget, '1 in ' . (int) $cfg['conv_target']),
            $mk('profit', 'Profit per sale', $pps, $pps === null ? '—' : '$' . number_format($pps),
                (float) $cfg['pps_target'], '$' . number_format((float) $cfg['pps_target'])),
            $mk('rpc', 'Revenue per call', $rpc,
                $rpc === null ? ($r['shifts'] > 0 ? 'No calls logged' : '—') : self::money($rpc),
                (float) $cfg['rpc_target'], self::money((float) $cfg['rpc_target'])),
        ];
    }

    /** $8.13 / $8 / $1,240 — cents only where they matter. */
    public static function money(float $v): string
    {
        return '$' . number_format($v, ($v < 100 && round($v, 2) != round($v)) ? 2 : 0);
    }

    /**
     * The weakest lever and what fixing it is worth by month end — the "where
     * you're losing" card. Money is estimated from their OWN numbers only.
     */
    private static function gap(array $r, array $cfg, float $scale, array $levers): ?array
    {
        if ($r['shifts'] > 0 && $r['calls'] === 0) {
            return ['lever' => 'logging', 'title' => 'Calls aren\'t being logged',
                    'text' => 'No calls are logged this month, so your conversion and weakest area can\'t be worked out.',
                    'extra_mco' => null, 'extra_inr' => null, 'steps' => self::STEPS['logging']];
        }
        $weak = null;
        foreach ($levers as $l) {
            if ($l['key'] === 'rpc') {
                continue;   // conversion × profit per sale: already covered by those two
            }
            if ($l['ratio'] !== null && $l['ratio'] < 1 && ($weak === null || $l['ratio'] < $weak['ratio'])) {
                $weak = $l;
            }
        }
        if ($weak === null) {
            return null;
        }
        $projSales = $r['sales'] * $scale;
        $projCalls = $r['calls'] * $scale;
        $pps = $r['sales'] > 0 ? $r['mco'] / $r['sales'] : (float) $cfg['pps_target'];
        $extra = 0.0;
        if ($weak['key'] === 'calls' && $r['calls'] > 0) {
            $extra = $projCalls * (1 / $weak['ratio'] - 1) * ($r['sales'] / $r['calls']) * $pps;
            $title = 'Your biggest gap · calls';
            $text = "You're taking {$weak['text']} calls a shift; the target is {$weak['target_text']}.";
        } elseif ($weak['key'] === 'conversion') {
            $extra = max(0, $projCalls / max(1, (float) $cfg['conv_target']) - $projSales) * $pps;
            $title = 'Your biggest gap · closing';
            $text = $r['sales'] > 0
                ? "{$weak['text']} calls turns into a sale. Get to {$weak['target_text']}"
                : "None of your logged calls has turned into a sale yet. Getting to {$weak['target_text']}";
            if ($r['sales'] === 0) {
                $pps = (float) $cfg['pps_target'];
                $extra = $projCalls / max(1, (float) $cfg['conv_target']) * $pps;
            }
        } else {
            $extra = $projSales * ((float) $cfg['pps_target'] - $pps);
            $title = 'Your biggest gap · profit per sale';
            $text = "Your sales average {$weak['text']} profit; the target is {$weak['target_text']}.";
        }
        // Never claim more than doubling their month (when they have one to double).
        $extra = round(max(0, $r['projected'] > 0 ? min($extra, $r['projected']) : $extra));
        $extraSales = $pps > 0 ? (int) round($extra / $pps) : 0;
        if ($weak['key'] === 'conversion') {
            $text .= $extraSales <= 0 ? '.'
                : ($r['sales'] > 0 ? " and that's about {$extraSales} more sales this month." : " would mean about {$extraSales} sales this month.");
        }
        $extraInr = null;
        if ($r['levels'] !== null && $extra > 0) {
            $extraInr = self::incentive($r['projected'] + $extra, $r['levels'], $cfg) - self::incentive($r['projected'], $r['levels'], $cfg);
        }
        return ['lever' => $weak['key'], 'title' => $title, 'text' => $text, 'extra_mco' => $extra ?: null,
                'extra_sales' => $extraSales, 'extra_inr' => $extraInr, 'steps' => self::STEPS[$weak['key']]];
    }

    /** Ids below a person: direct reports, or everyone under them at any depth. */
    private function below($users, int $id, bool $recursive): array
    {
        $out = [];
        $frontier = [$id];
        for ($d = 0; $d < 5 && $frontier; $d++) {
            $next = [];
            foreach ($users as $u) {
                if ($u->reports_to_id !== null && in_array((int) $u->reports_to_id, $frontier, true) && !in_array((int) $u->id, $out, true)) {
                    $out[] = (int) $u->id;
                    $next[] = (int) $u->id;
                }
            }
            if (!$recursive) {
                break;
            }
            $frontier = $next;
        }
        return $out;
    }

    /** A team's (or a floor's) totals and the leader's share of it. */
    private function teamBlock(array $all, array $ids, array $cfg, float $scale, bool $isTeamLeader): array
    {
        $members = array_values(array_filter(array_map(fn($i) => $all[$i] ?? null, $ids),
            fn($m) => $m !== null && in_array($m['role'], ['agent', 'supervisor'], true)));
        $mco = 0.0; $target = 0.0; $past = 0; $setUp = 0; $calls = 0; $loggedMco = 0.0;
        foreach ($members as $m) {
            $mco += $m['mco'];
            // Revenue per call counts only people who log calls: someone selling
            // with no calls logged would otherwise inflate the team's figure.
            if ($m['calls'] > 0) {
                $calls += $m['calls'];
                $loggedMco += $m['mco'];
            }
            if ($m['levels'] !== null) {
                $target += $m['levels']['l2'];
                $setUp++;
                if ($m['mco'] >= $m['levels']['l1']) {
                    $past++;
                }
            }
        }
        $rate = (float) $cfg['usd_inr'];
        $shareRate = (float) ($isTeamLeader ? $cfg['tl_share'] : $cfg['mgr_share']) / 100;
        $share = $target > 0 ? (int) round(max(0, $mco - $target) * $shareRate * $rate) : 0;
        $block = [
            'ids'       => array_map(fn($m) => $m['id'], $members),
            'count'     => count($members),
            'set_up'    => $setUp,
            'mco'       => round($mco, 2),
            'target'    => round($target),
            'progress'  => $target > 0 ? round($mco / $target * 100) : null,
            'projected' => round($mco * $scale, 2),
            'past_l1'   => $past,
            'calls'     => $calls,
            'rpc'       => $calls > 0 ? round($loggedMco / $calls, 2) : null,
            'share_inr' => $share,
            'bonus_inr' => 0,
        ];
        if ($isTeamLeader) {
            $need = (int) ceil($setUp * (float) $cfg['tl_bonus_pct'] / 100);
            $block['bonus_need'] = $need;
            $block['bonus_amount'] = (int) $cfg['tl_bonus_inr'];
            $block['bonus_unlocked'] = $setUp > 0 && $past >= $need;
            $block['bonus_inr'] = $block['bonus_unlocked'] ? (int) $cfg['tl_bonus_inr'] : 0;
        }
        return $block;
    }


    // =========================================================================
    // SPOT REWARDS
    // =========================================================================

    /**
     * Spot rewards for a month, worked out from the sales themselves:
     *  - the first approved sale on the floor each shift (shift days that
     *    start in this month),
     *  - the floor's best single sale of each week (Monday–Sunday by shift
     *    day, credited to the month the week ends in, once the week is over),
     *  - a new personal best month (beats their best of the previous 12).
     * Paid in time order while the month's spot budget lasts; anything that
     * doesn't fit is listed but not paid. An award once paid keeps its place
     * ahead of later ones (see committed()), so a sale approved late can't
     * take back money someone was already shown.
     *
     * @return array{by_user: array<int, array>, spent: int, over_budget: int}
     */
    public function spotRewards(string $month, array $cfg, array $sellerIds): array
    {
        $out = ['by_user' => [], 'spent' => 0, 'over_budget' => 0];
        if (!$sellerIds) {
            return $out;
        }
        [$from, $to, , , $isCurrent] = self::monthBounds($month);
        $h = $this->rolloverHour();
        $awards = [];

        $first = (int) $cfg['spot_first_sale'];
        if ($first > 0) {
            // Shift days that start in this month: the 1st's shift to the last day's, each running to the next rollover.
            $shiftFrom = date('Y-m-d H:i:s', strtotime($from) + $h * 3600);
            $shiftTo = date('Y-m-d H:i:s', strtotime($to) + $h * 3600);
            $sales = $this->db->table('transactions')->whereIn('agent_id', $sellerIds)->where('status', 'approved')
                ->whereBetween('created_at', [$shiftFrom, $shiftTo])->orderBy('created_at')->orderBy('id')
                ->get(['agent_id', 'created_at']);
            $seen = [];
            foreach ($sales as $t) {
                $day = date('Y-m-d', strtotime((string) $t->created_at) - $h * 3600);
                if (!isset($seen[$day])) {
                    $seen[$day] = true;
                    $awards[] = ['user' => (int) $t->agent_id, 'kind' => 'first_sale', 'key' => 'spot:first:' . $day . ':' . $t->agent_id,
                                 'label' => 'First sale on the floor · ' . date('j M', strtotime($day)),
                                 'amount' => $first, 'at' => (string) $t->created_at];
                }
            }
        }

        $week = (int) $cfg['spot_best_week'];
        if ($week > 0) {
            // Weeks ending inside this month (Sunday shift days), and already over.
            $d = strtotime(substr($from, 0, 10));
            $end = strtotime(substr($to, 0, 10));
            for (; $d <= $end; $d += 86400) {
                if ((int) date('N', $d) !== 7) {
                    continue;
                }
                $weekEndAt = date('Y-m-d H:i:s', $d + 86400 + $h * 3600 - 1);   // the Sunday shift runs to Monday's rollover
                if (strtotime($weekEndAt) > time()) {
                    continue;
                }
                $weekStartAt = date('Y-m-d H:i:s', $d - 6 * 86400 + $h * 3600);
                // After refunds: a refunded sale can't win the week.
                $best = $this->db->table('transactions')->whereIn('agent_id', $sellerIds)->where('status', 'approved')
                    ->whereBetween('created_at', [$weekStartAt, $weekEndAt])
                    ->selectRaw('agent_id, (profit_mco - refund_mco_impact) AS net')
                    ->orderByDesc('net')->orderBy('created_at')->first();
                if ($best !== null && (float) $best->net > 0) {
                    $awards[] = ['user' => (int) $best->agent_id, 'kind' => 'best_week', 'key' => 'spot:week:' . date('Y-m-d', $d) . ':' . $best->agent_id,
                                 'label' => 'Best sale of the week to ' . date('j M', $d) . ' · $' . number_format((float) $best->net) . ' MCO',
                                 'amount' => $week, 'at' => $weekEndAt];
                }
            }
        }

        $pb = (int) $cfg['spot_best_month'];
        if ($pb > 0) {
            $prevFrom = date('Y-m-01 00:00:00', strtotime($month . '-01 -12 months'));
            $rows = $this->db->table('transactions')->whereIn('agent_id', $sellerIds)->where('status', 'approved')
                ->whereBetween('created_at', [$prevFrom, $to])
                ->groupBy('agent_id', $this->db->raw("DATE_FORMAT(created_at, '%Y-%m')"))
                ->selectRaw("agent_id, DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(profit_mco - refund_mco_impact) AS net")
                ->get();
            $byUserMonth = [];
            foreach ($rows as $t) {
                $byUserMonth[(int) $t->agent_id][(string) $t->m] = (float) $t->net;
            }
            foreach ($byUserMonth as $uid => $months) {
                $now = $months[$month] ?? 0;
                unset($months[$month]);
                $prevBest = $months ? max($months) : null;
                // A first-ever month is not a "personal best"; there's nothing to beat.
                if ($prevBest !== null && $prevBest > 0 && $now > $prevBest) {
                    $awards[] = ['user' => $uid, 'kind' => 'best_month', 'key' => 'spot:month:' . $month . ':' . $uid,
                                 'label' => 'New personal best month' . ($isCurrent ? ' (so far)' : '') . ' · $' . number_format($now),
                                 'amount' => $pb, 'at' => $to];
                }
            }
        }

        $held = $this->committed(array_column($awards, 'key'));
        usort($awards, fn($a, $b) => self::payOrder($held, $a['key'], $a['at'], $b['key'], $b['at']));
        $budget = (int) $cfg['spot_budget'];
        $new = [];
        foreach ($awards as $a) {
            $a['paid'] = $out['spent'] + $a['amount'] <= $budget;
            if ($a['paid']) {
                $out['spent'] += $a['amount'];
                if (!isset($held[$a['key']])) {
                    $new[] = $a['key'];
                }
            } else {
                $out['over_budget'] += $a['amount'];
            }
            $out['by_user'][$a['user']][] = $a;
        }
        $this->commit($new);
        foreach ($out['by_user'] as &$list) {
            usort($list, fn($a, $b) => strcmp($a['at'], $b['at']));
        }
        unset($list);
        return $out;
    }

    /**
     * Awards and booster payments already shown as paid, with when they were
     * first paid. They keep their place in the queue ahead of anything newer,
     * so money someone was told they earned doesn't move to someone else when
     * an older sale is approved late. (Still dropped if the sale itself goes.)
     *
     * @param string[] $keys
     * @return array<string,string> key → first paid at
     */
    private function committed(array $keys): array
    {
        if (!$keys) {
            return [];
        }
        try {
            $out = [];
            foreach (array_chunk($keys, 500) as $chunk) {
                $out += $this->db->table('kra_award_log')->whereIn('k', $chunk)->pluck('committed_at', 'k')->all();
            }
            return array_map('strval', $out);
        } catch (\Throwable $e) {
            return [];   // before the migration
        }
    }

    /** @param string[] $keys */
    private function commit(array $keys): void
    {
        if (!$keys) {
            return;
        }
        try {
            $now = date('Y-m-d H:i:s');
            foreach (array_chunk($keys, 500) as $chunk) {
                $this->db->table('kra_award_log')->insertOrIgnore(array_map(fn($k) => ['k' => $k, 'committed_at' => $now], $chunk));
            }
        } catch (\Throwable $e) {
            error_log('[KRA] award log write failed: ' . $e->getMessage());
        }
    }

    /** Already-paid first (in the order they were paid), then the rest in time order. */
    private static function payOrder(array $held, string $ka, string $ta, string $kb, string $tb): int
    {
        $ha = $held[$ka] ?? null;
        $hb = $held[$kb] ?? null;
        if ($ha !== null && $hb !== null) {
            return [$ha, $ta, $ka] <=> [$hb, $tb, $kb];
        }
        if ($ha !== null || $hb !== null) {
            return $ha !== null ? -1 : 1;
        }
        return [$ta, $ka] <=> [$tb, $kb];
    }

    // =========================================================================
    // DAILY BOOSTERS
    // =========================================================================

    /** Who a booster is for: every seller, or one team leader's agents. */
    private function boosterEligible(string $scope, $users, array $sellerIds): array
    {
        if (preg_match('/^team:(\d+)$/', $scope, $m)) {
            return array_values(array_intersect($this->below($users, (int) $m[1], false), $sellerIds));
        }
        return $sellerIds;
    }

    /**
     * What every booster that touches [from, to] has paid, sale by sale, in
     * the order the sales came in, until its budget ran out.
     *
     * @return array{by_user: array<int,int>, boosters: array}
     */
    public function boosterEarnings(string $from, string $to, $users, array $sellerIds): array
    {
        $out = ['by_user' => [], 'boosters' => []];
        try {
            $rows = $this->db->table('kra_boosters')->where('starts_at', '<=', $to)->where('ends_at', '>=', $from)
                ->orderBy('starts_at')->get();
        } catch (\Throwable $e) {
            return $out;   // before the migration: no boosters
        }
        foreach ($rows as $b) {
            $eligible = $this->boosterEligible((string) $b->scope, $users, $sellerIds);
            $until = $b->stopped_at !== null && $b->stopped_at < $b->ends_at ? (string) $b->stopped_at : (string) $b->ends_at;
            // After refunds: a refunded sale doesn't earn a booster.
            $q = $this->db->table('transactions')->whereIn('agent_id', $eligible ?: [0])->where('status', 'approved')
                ->whereBetween('created_at', [(string) $b->starts_at, $until])
                ->selectRaw('id, agent_id, created_at, (profit_mco - refund_mco_impact) AS net');
            if ($b->min_mco !== null) {
                $q->whereRaw('(profit_mco - refund_mco_impact) >= ?', [(float) $b->min_mco]);
            }
            $sales = $q->get()->all();
            $keyOf = fn($t) => 'boost:' . $b->id . ':' . $t->id;
            $held = $this->committed(array_map($keyOf, $sales));
            usort($sales, fn($x, $y) => self::payOrder($held, $keyOf($x), (string) $x->created_at . sprintf('%012d', $x->id), $keyOf($y), (string) $y->created_at . sprintf('%012d', $y->id)));
            $spent = 0; $paidSales = 0; $mco = 0.0; $mine = []; $new = [];
            foreach ($sales as $t) {
                if ($spent + (int) $b->amount_inr > (int) $b->budget_inr) {
                    break;
                }
                if (!isset($held[$keyOf($t)])) {
                    $new[] = $keyOf($t);
                }
                $spent += (int) $b->amount_inr;
                $paidSales++;
                $mco += (float) $t->net;
                // Credited to the month the sale belongs to.
                if ((string) $t->created_at >= $from && (string) $t->created_at <= $to) {
                    $out['by_user'][(int) $t->agent_id] = ($out['by_user'][(int) $t->agent_id] ?? 0) + (int) $b->amount_inr;
                }
                $mine[(int) $t->agent_id] = ($mine[(int) $t->agent_id] ?? 0) + (int) $b->amount_inr;
            }
            $this->commit($new);
            $now = date('Y-m-d H:i:s');
            $status = $b->stopped_at !== null ? 'stopped'
                : ($spent + (int) $b->amount_inr > (int) $b->budget_inr ? 'budget_used'
                : ($now < (string) $b->starts_at ? 'scheduled' : ($now > (string) $b->ends_at ? 'ended' : 'active')));
            $out['boosters'][] = [
                'id' => (int) $b->id, 'amount' => (int) $b->amount_inr, 'min_mco' => $b->min_mco === null ? null : (float) $b->min_mco,
                'scope' => (string) $b->scope, 'budget' => (int) $b->budget_inr, 'starts_at' => (string) $b->starts_at,
                'ends_at' => (string) $b->ends_at, 'stopped_at' => $b->stopped_at, 'status' => $status,
                'spent' => $spent, 'sales' => $paidSales, 'mco' => round($mco, 2), 'eligible' => $eligible, 'by_user' => $mine,
                'rule' => self::boosterRule((int) $b->amount_inr, $b->min_mco === null ? null : (float) $b->min_mco),
            ];
        }
        return $out;
    }

    /** "6:00 AM" for tonight's; "Sat 25 Oct, 6:00 AM" when it runs past the next day. */
    public static function endsLabel(string $endsAt): string
    {
        $t = strtotime($endsAt);
        return $t - time() > 20 * 3600 ? date('D j M, g:i A', $t) : date('g:i A', $t);
    }

    public static function boosterRule(int $amount, ?float $minMco): string
    {
        return '₹' . number_format($amount) . ' for every approved sale' . ($minMco !== null ? ' of $' . number_format($minMco) . ' MCO or more' : '');
    }

    // =========================================================================
    // THE CAP AND THE MONTH'S STATEMENT
    // =========================================================================

    /**
     * Everyone's incentive for a month and the company-wide cap: all
     * incentives together may not exceed cap_pct % of the profit above
     * break-even. Over the cap, everyone's total is scaled down by the same
     * factor. $projected = use month-end projections (for HR's forecast).
     */
    public function statement(?string $month = null, bool $projected = false): ?array
    {
        $snap = $this->snapshot($month);
        return $snap === null ? null : self::statementFrom($snap, $projected);
    }

    /** The same statement from a snapshot already worked out (saves a second pass over the month). */
    public static function statementFrom(array $snap, bool $projected = false): array
    {
        $cfg = $snap['config'];
        $rate = (float) $cfg['usd_inr'];
        $rows = [];
        $total = 0; $floorMco = 0.0;
        foreach ($snap['people'] as $p) {
            if (in_array($p['role'], ['agent', 'supervisor'], true)) {
                $floorMco += $projected ? $p['projected'] : $p['mco'];
            }
            $monthly = $p['incentive'];
            if ($projected && $p['levels'] !== null) {
                $monthly = self::incentive($p['projected'], $p['levels'], $cfg);
                if ($p['quality_hit']) {
                    $monthly = (int) round($monthly * (1 - (float) $cfg['quality_cut'] / 100));
                }
            }
            $sum = $monthly + $p['team_inr'] + $p['spot_inr'] + $p['booster_inr'];
            if ($sum <= 0 && $p['mco'] == 0) {
                continue;
            }
            $rows[] = ['id' => $p['id'], 'name' => $p['name'], 'role' => $p['role'], 'net_mco' => $p['mco'],
                       'monthly' => $monthly, 'team' => $p['team_inr'], 'spot' => $p['spot_inr'], 'booster' => $p['booster_inr'], 'sum' => $sum];
            $total += $sum;
        }
        $profit = $floorMco * $rate - (float) $cfg['breakeven_inr'];
        $cap = (int) round(max(0, $profit) * (float) $cfg['cap_pct'] / 100);
        $scale = ($total > $cap && $total > 0) ? round($cap / $total, 4) : 1.0;
        foreach ($rows as &$r) {
            $r['total'] = (int) round($r['sum'] * $scale);
        }
        unset($r);
        usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
        return [
            'month' => $snap['month'], 'projected' => $projected, 'rows' => $rows, 'before_cap' => $total,
            'after_cap' => array_sum(array_column($rows, 'total')), 'cap' => $cap, 'scale' => $scale,
            'floor_mco' => round($floorMco, 2), 'profit_inr' => (int) round($profit), 'breakeven_set' => (float) $cfg['breakeven_inr'] > 0,
            'refund_cutoff' => (int) $cfg['refund_cutoff'],
        ];
    }

    /** The shift rollover hour (system_config, default 18). */
    private function rolloverHour(): int
    {
        try {
            $v = $this->db->table('system_config')->where('key', 'business_day_start_hour')->value('value');
            return $v === null ? 18 : max(0, min(23, (int) $v));
        } catch (\Throwable $e) {
            return 18;
        }
    }

    // =========================================================================
    // ONE PERSON (the CRM card and Aisha)
    // =========================================================================

    /**
     * One person's KRA card. Team members are given as progress only — their
     * incentive amounts are private to them (owner's rule, 23 Oct 2026).
     *
     * @return array|null null when no rules have ever been saved
     */
    public function card(int $userId, ?string $month = null): ?array
    {
        $snap = $this->snapshot($month);
        if ($snap === null) {
            return null;
        }
        $me = $snap['people'][$userId] ?? null;
        if ($me === null) {
            return ['live' => true, 'month' => $snap['month'], 'in_kra' => false];
        }
        $card = [
            'live'      => true,
            'in_kra'    => true,
            'month'     => $snap['month'],
            'days_left' => $snap['days_left'],
            'usd_inr'   => (float) $snap['config']['usd_inr'],
            'rpc_target'=> (float) $snap['config']['rpc_target'],
            'me'        => $me,
            'nightly'   => ($me['next'] && $snap['days_left'] > 0) ? (int) ceil($me['next']['away'] / $snap['days_left']) : null,
            'bests'     => $this->bests($userId, $snap['month']),
            'feedback'  => $this->latestFeedback($userId),
            'booster'   => null,
        ];
        foreach ($snap['boosters'] as $b) {
            if ($b['status'] === 'active' && in_array($userId, $b['eligible'], true)) {
                $card['booster'] = ['rule' => $b['rule'], 'ends_at' => $b['ends_at'], 'earned' => (int) ($b['by_user'][$userId] ?? 0)];
                break;
            }
        }
        if (isset($me['team'])) {
            $card['team'] = array_map(fn($id) => self::publicRow($snap['people'][$id]), $me['team']['ids']);
            if ($me['role'] === 'manager') {
                $card['teams'] = [];
                foreach ($snap['people'] as $p) {
                    if ($p['role'] === 'supervisor' && in_array($p['id'], $me['team']['ids'], true)) {
                        $card['teams'][] = ['name' => $p['name'], 'progress' => $p['team']['progress'], 'past_l1' => $p['team']['past_l1'], 'rpc' => $p['team']['rpc'],
                                            'count' => $p['team']['count'], 'weakest' => self::teamWeakest($snap['people'], $p['team']['ids'])];
                    }
                }
                $card['watch'] = $this->watchList($snap, $me['team']['ids']);
            }
            // The three furthest behind (sorted first, then cut).
            $help = array_values(array_filter($card['team'], fn($r) => $r['progress'] !== null && $r['progress'] < 70));
            usort($help, fn($a, $b) => $a['progress'] <=> $b['progress']);
            $card['help'] = array_slice($help, 0, 3);
        }
        return $card;
    }

    /** A team member as their leader may see them: progress and weakest lever, never money. */
    private static function publicRow(array $p): array
    {
        return [
            'id'       => $p['id'],
            'name'     => $p['name'],
            'role'     => $p['role'],
            'set_up'   => $p['set_up'],
            'progress' => $p['progress'],
            'level'    => $p['level'],
            'sales'    => $p['sales'],
            'away_l1'  => ($p['levels'] && $p['mco'] < $p['levels']['l1']) ? round($p['levels']['l1'] - $p['mco']) : null,
            'weakest'  => $p['gap']['title'] ?? null,
            'weak_key' => $p['gap']['lever'] ?? null,
            'calls_ps' => $p['levers'][0]['text'],
            'conv'     => $p['levers'][1]['text'],
            'rpc'      => $p['levers'][3]['text'],
            'rpc_ok'   => $p['levers'][3]['ok'],
        ];
    }

    private static function teamWeakest(array $people, array $ids): ?string
    {
        $count = [];
        foreach ($ids as $id) {
            $k = $people[$id]['gap']['lever'] ?? null;
            if ($k !== null) {
                $count[$k] = ($count[$k] ?? 0) + 1;
            }
        }
        if (!$count) {
            return null;
        }
        arsort($count);
        return ['calls' => 'calls', 'conversion' => 'closing', 'profit' => 'profit per sale', 'logging' => 'logging calls'][array_key_first($count)];
    }

    /** Things a manager should know: not logging calls, refunds near the limit, far behind pace. */
    private function watchList(array $snap, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $p = $snap['people'][$id] ?? null;
            if ($p === null || !in_array($p['role'], ['agent', 'supervisor'], true)) {
                continue;
            }
            if ($p['shifts'] >= 2 && $p['calls'] === 0) {
                $out[] = ['name' => $p['name'], 'text' => 'has worked ' . $p['shifts'] . ' shifts this month with no calls logged'];
            } elseif ($p['quality_hit']) {
                $out[] = ['name' => $p['name'], 'text' => 'is over the refund limit (' . $p['bad'] . ' this month)'];
            } elseif ($p['set_up'] && $snap['is_current'] && $snap['day_now'] >= 10 && $p['progress'] !== null && $p['progress'] < 35) {
                $out[] = ['name' => $p['name'], 'text' => 'is at ' . $p['progress'] . '% of target with ' . $snap['days_left'] . ' days left'];
            }
        }
        return array_slice($out, 0, 6);
    }

    /** Personal bests: best month (last 12), biggest sale, current streak of shifts with a sale. */
    private function bests(int $userId, string $month): array
    {
        $out = ['best_month' => null, 'biggest' => null, 'streak' => 0];
        try {
            $from = date('Y-m-01 00:00:00', strtotime($month . '-01 -12 months'));
            $byMonth = $this->db->table('transactions')->where('agent_id', $userId)->where('status', 'approved')
                ->where('created_at', '>=', $from)
                ->groupBy($this->db->raw("DATE_FORMAT(created_at, '%Y-%m')"))
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(profit_mco - refund_mco_impact) AS net")
                ->pluck('net', 'm')->map(fn($v) => (float) $v)->all();
            unset($byMonth[$month]);
            if ($byMonth) {
                arsort($byMonth);
                $m = array_key_first($byMonth);
                if ($byMonth[$m] > 0) {
                    $out['best_month'] = ['month' => date('F Y', strtotime($m . '-01')), 'mco' => round($byMonth[$m])];
                }
            }
            $big = $this->db->table('transactions')->where('agent_id', $userId)->where('status', 'approved')->max('profit_mco');
            $out['biggest'] = $big === null ? null : round((float) $big);

            // Streak: shift days in a row (counting back from the latest one they worked) with a sale.
            $h = $this->rolloverHour();
            $worked = $this->db->table('attendance_sessions')->where('user_id', $userId)
                ->where('clock_in', '>=', date('Y-m-d H:i:s', time() - 45 * 86400))->orderByDesc('clock_in')
                ->pluck('clock_in')->map(fn($c) => date('Y-m-d', strtotime((string) $c) - $h * 3600))->unique()->values()->all();
            $sold = $this->db->table('transactions')->where('agent_id', $userId)->where('status', 'approved')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 46 * 86400))->pluck('created_at')
                ->map(fn($c) => date('Y-m-d', strtotime((string) $c) - $h * 3600))->unique()->flip()->all();
            $today = date('Y-m-d', time() - $h * 3600);
            foreach ($worked as $i => $d) {
                if (isset($sold[$d])) {
                    $out['streak']++;
                } elseif ($i === 0 && $d === $today) {
                    continue;   // tonight isn't over yet
                } else {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // bests are a bonus; the card works without them
        }
        return $out;
    }

    private function latestFeedback(int $userId): ?array
    {
        try {
            $f = $this->db->table('kra_feedback')->where('crm_user_id', $userId)->orderByDesc('id')->first(['body', 'created_at']);
            return $f === null ? null : ['body' => (string) $f->body, 'at' => (string) $f->created_at];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** [from, to, days in month, today's day of month, is current month] */
    public static function monthBounds(string $month): array
    {
        $first = strtotime($month . '-01');
        $daysIn = (int) date('t', $first);
        $isCurrent = $month === date('Y-m');
        $dayNow = $isCurrent ? (int) date('j') : $daysIn;
        return [date('Y-m-01 00:00:00', $first), date('Y-m-t 23:59:59', $first), $daysIn, $dayNow, $isCurrent];
    }
}
