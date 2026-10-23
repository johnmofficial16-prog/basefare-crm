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
            'Your conversion and your weakest area can only be worked out from logged calls.',
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

        $users = $this->db->table('users')->whereNull('deleted_at')->where('status', 'active')
            ->whereIn('role', ['agent', 'supervisor', 'manager'])
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
            $r['incentive_total'] = $r['incentive'] + (int) ($r['team']['share_inr'] ?? 0) + (int) ($r['team']['bonus_inr'] ?? 0);
        }
        unset($r);

        return [
            'month'      => $month,
            'config'     => $cfg,
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

    /** The three levers (calls, conversion, profit per sale) against their targets. */
    private static function levers(array $r, array $cfg): array
    {
        $cps  = $r['shifts'] > 0 ? $r['calls'] / $r['shifts'] : null;
        $conv = ($r['calls'] > 0) ? $r['sales'] / $r['calls'] : null;        // sales per call
        $pps  = $r['sales'] > 0 ? $r['mco'] / $r['sales'] : null;
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
        ];
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
        $mco = 0.0; $target = 0.0; $past = 0; $setUp = 0;
        foreach ($members as $m) {
            $mco += $m['mco'];
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
            'me'        => $me,
            'nightly'   => ($me['next'] && $snap['days_left'] > 0) ? (int) ceil($me['next']['away'] / $snap['days_left']) : null,
            'bests'     => $this->bests($userId, $snap['month']),
            'feedback'  => $this->latestFeedback($userId),
        ];
        if (isset($me['team'])) {
            $card['team'] = array_map(fn($id) => self::publicRow($snap['people'][$id]), $me['team']['ids']);
            if ($me['role'] === 'manager') {
                $card['teams'] = [];
                foreach ($snap['people'] as $p) {
                    if ($p['role'] === 'supervisor' && in_array($p['id'], $me['team']['ids'], true)) {
                        $card['teams'][] = ['name' => $p['name'], 'progress' => $p['team']['progress'], 'past_l1' => $p['team']['past_l1'],
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
            $rows = $this->db->table('transactions')->where('agent_id', $userId)->where('status', 'approved')
                ->where('created_at', '>=', $from)->get(['created_at', 'profit_mco', 'refund_mco_impact']);
            $byMonth = [];
            foreach ($rows as $t) {
                $m = substr((string) $t->created_at, 0, 7);
                $byMonth[$m] = ($byMonth[$m] ?? 0) + (float) $t->profit_mco - (float) $t->refund_mco_impact;
            }
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
            $h = (int) ($this->db->table('system_config')->where('key', 'business_day_start_hour')->value('value') ?? 18);
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
