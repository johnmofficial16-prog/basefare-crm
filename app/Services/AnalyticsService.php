<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\AcceptanceRequest;
use App\Models\MarketingSpend;
use Carbon\Carbon;

/**
 * AnalyticsService — centre-level performance + marketing analytics for admins.
 *
 * Centre membership comes from CentreService (derived live + overrides). Money
 * metrics mirror the Performance module: Gross MCO = profit_mco; Net MCO =
 * profit_mco − refund_mco_impact (refund-aware). Marketing spend is prorated
 * from any overlapping date-range rows to compute ROI and cost-per-booking.
 *
 * Everything here is aggregate + PII-free, safe to feed to the AI insights call.
 */
class AnalyticsService
{
    private CentreService $centres;

    public function __construct()
    {
        $this->centres = new CentreService();
    }

    // =========================================================================
    // OVERVIEW — per-centre metrics for a window, with previous-period deltas
    // =========================================================================

    public function overview(Carbon $start, Carbon $end): array
    {
        // Previous window of equal length, immediately before.
        $len       = $start->diffInDays($end);
        $prevEnd   = $start->copy()->subSecond();
        $prevStart = $prevEnd->copy()->subDays($len)->startOfDay();

        $byCentre = $this->centres->agentsByCentre(true); // incl UNASSIGNED

        $centres = [];
        $tAgents = $tBookings = $tAcc = 0;
        $tRevenue = $tGross = $tNet = $tSpend = 0.0;

        foreach (CentreService::ALL as $code) {
            $ids  = $byCentre[$code] ?? [];
            $cur  = $this->centreMetrics($ids, $start, $end);
            $prev = $this->centreMetrics($ids, $prevStart, $prevEnd);
            $spend     = $this->spendInWindow($code, $start, $end);
            $prevSpend = $this->spendInWindow($code, $prevStart, $prevEnd);

            $centres[$code] = [
                'code'             => $code,
                'label'            => CentreService::LABELS[$code],
                'color'            => CentreService::COLORS[$code],
                'agents'           => count($ids),
                'bookings'         => $cur['bookings'],
                'revenue'          => $cur['revenue'],
                'gross'            => $cur['gross'],
                'net'              => $cur['net'],
                'acceptances'      => $cur['acceptances'],
                'spend'            => $spend,
                'roi'              => $spend > 0 ? round($cur['net'] / $spend, 2) : null,
                'cost_per_booking' => $cur['bookings'] > 0 ? round($spend / $cur['bookings'], 2) : null,
                'conversion'       => $cur['acceptances'] > 0 ? round($cur['bookings'] / $cur['acceptances'] * 100, 1) : null,
                'delta'            => [
                    'revenue'  => $this->pct($cur['revenue'],  $prev['revenue']),
                    'net'      => $this->pct($cur['net'],      $prev['net']),
                    'bookings' => $this->pct($cur['bookings'], $prev['bookings']),
                    'spend'    => $this->pct($spend,           $prevSpend),
                ],
            ];

            $tAgents   += count($ids);
            $tBookings += $cur['bookings'];
            $tAcc      += $cur['acceptances'];
            $tRevenue  += $cur['revenue'];
            $tGross    += $cur['gross'];
            $tNet      += $cur['net'];
            $tSpend    += $spend;
        }

        $totals = [
            'agents'           => $tAgents,
            'bookings'         => $tBookings,
            'revenue'          => $tRevenue,
            'gross'            => $tGross,
            'net'              => $tNet,
            'acceptances'      => $tAcc,
            'spend'            => $tSpend,
            'roi'              => $tSpend > 0 ? round($tNet / $tSpend, 2) : null,
            'cost_per_booking' => $tBookings > 0 ? round($tSpend / $tBookings, 2) : null,
        ];

        return ['centres' => $centres, 'totals' => $totals,
                'prev_label' => $prevStart->format('M j') . ' – ' . $prevEnd->format('M j')];
    }

    // =========================================================================
    // WEEKLY TREND — last N ISO weeks, per centre
    // =========================================================================

    public function weeklyTrend(int $weeks = 8): array
    {
        $byCentre = $this->centres->agentsByCentre();
        $out = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $ws = Carbon::now()->subWeeks($i)->startOfWeek();   // Monday
            $we = $ws->copy()->endOfWeek();                     // Sunday
            $row = ['label' => $ws->format('M j')];
            foreach (CentreService::ALL as $code) {
                $m = $this->centreMetrics($byCentre[$code] ?? [], $ws, $we);
                $row[$code] = [
                    'net'      => $m['net'],
                    'revenue'  => $m['revenue'],
                    'bookings' => $m['bookings'],
                    'spend'    => $this->spendInWindow($code, $ws, $we),
                ];
            }
            $out[] = $row;
        }
        return $out;
    }

    // =========================================================================
    // METRICS
    // =========================================================================

    /** Approved-booking metrics for a set of agents in a window. */
    public function centreMetrics(array $agentIds, Carbon $start, Carbon $end): array
    {
        if (empty($agentIds)) {
            return ['bookings' => 0, 'revenue' => 0.0, 'gross' => 0.0, 'net' => 0.0, 'acceptances' => 0];
        }

        $t = Transaction::whereIn('agent_id', $agentIds)
            ->where('status', Transaction::STATUS_APPROVED)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(*) AS bookings, SUM(total_amount) AS revenue,
                         SUM(profit_mco) AS gross, SUM(refund_mco_impact) AS impact')
            ->first();

        $gross = (float) ($t->gross ?? 0);
        $net   = round($gross - (float) ($t->impact ?? 0), 2);

        $acc = AcceptanceRequest::whereIn('agent_id', $agentIds)
            ->where('status', 'APPROVED')
            ->where('is_preauth', false)
            ->whereBetween('approved_at', [$start, $end])
            ->count();

        return [
            'bookings'    => (int) ($t->bookings ?? 0),
            'revenue'     => (float) ($t->revenue ?? 0),
            'gross'       => $gross,
            'net'         => $net,
            'acceptances' => $acc,
        ];
    }

    /** Marketing spend for a centre, prorated by overlap with the window. */
    public function spendInWindow(string $centre, Carbon $start, Carbon $end): float
    {
        $rows = MarketingSpend::where('centre', $centre)
            ->whereDate('period_start', '<=', $end->toDateString())
            ->whereDate('period_end', '>=', $start->toDateString())
            ->get();

        $total = 0.0;
        foreach ($rows as $r) {
            $rs = Carbon::parse($r->period_start)->startOfDay();
            $re = Carbon::parse($r->period_end)->endOfDay();
            $os = $rs->greaterThan($start) ? $rs : $start;
            $oe = $re->lessThan($end) ? $re : $end;
            if ($oe->lessThan($os)) {
                continue;
            }
            $overlapDays = $os->copy()->startOfDay()->diffInDays($oe->copy()->startOfDay()) + 1;
            $totalDays   = $rs->copy()->startOfDay()->diffInDays($re->copy()->startOfDay()) + 1;
            $total += $totalDays > 0 ? (float) $r->amount * ($overlapDays / $totalDays) : (float) $r->amount;
        }
        return round($total, 2);
    }

    private function pct(float $cur, float $prev): ?float
    {
        if ($prev == 0.0) {
            return $cur > 0 ? 100.0 : ($cur < 0 ? -100.0 : 0.0);
        }
        return round(($cur - $prev) / abs($prev) * 100, 1);
    }
}
