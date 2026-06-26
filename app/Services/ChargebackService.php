<?php

namespace App\Services;

use App\Models\ChargebackRefund;
use Carbon\Carbon;

/**
 * ChargebackService — per-centre bifurcation of manually-entered chargebacks and
 * refunds. Mirrors AnalyticsService's shape (centres keyed by CentreService::ALL,
 * with labels + brand colours) so the dashboard feels native.
 *
 * Amounts are summed across whatever currency was entered and shown in the CRM's
 * single display currency — the same simplification the rest of the analytics use.
 */
class ChargebackService
{
    // =========================================================================
    // ANALYSIS — per-centre chargeback vs refund totals for a window
    // =========================================================================

    public function analysis(Carbon $start, Carbon $end): array
    {
        $rows = ChargebackRefund::whereBetween('event_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('centre, kind, COUNT(*) AS cnt, SUM(amount) AS amt')
            ->groupBy('centre', 'kind')
            ->get();

        // Seed every centre with zeroes so the table/chart always shows all centres.
        $byCentre = [];
        foreach (CentreService::ALL as $code) {
            $byCentre[$code] = [
                'chargeback' => ['count' => 0, 'amount' => 0.0],
                'refund'     => ['count' => 0, 'amount' => 0.0],
            ];
        }
        foreach ($rows as $r) {
            if (isset($byCentre[$r->centre][$r->kind])) {
                $byCentre[$r->centre][$r->kind] = ['count' => (int) $r->cnt, 'amount' => (float) $r->amt];
            }
        }

        $centres = [];
        $tCbCount = $tRfCount = 0;
        $tCbAmt = $tRfAmt = 0.0;

        foreach (CentreService::ALL as $code) {
            $cb = $byCentre[$code]['chargeback'];
            $rf = $byCentre[$code]['refund'];

            $centres[$code] = [
                'code'         => $code,
                'label'        => CentreService::LABELS[$code],
                'color'        => CentreService::COLORS[$code],
                'chargebacks'  => ['count' => $cb['count'], 'amount' => round($cb['amount'], 2)],
                'refunds'      => ['count' => $rf['count'], 'amount' => round($rf['amount'], 2)],
                'total_count'  => $cb['count'] + $rf['count'],
                'total_amount' => round($cb['amount'] + $rf['amount'], 2),
            ];

            $tCbCount += $cb['count'];
            $tRfCount += $rf['count'];
            $tCbAmt   += $cb['amount'];
            $tRfAmt   += $rf['amount'];
        }

        $totals = [
            'chargebacks'  => ['count' => $tCbCount, 'amount' => round($tCbAmt, 2)],
            'refunds'      => ['count' => $tRfCount, 'amount' => round($tRfAmt, 2)],
            'total_count'  => $tCbCount + $tRfCount,
            'total_amount' => round($tCbAmt + $tRfAmt, 2),
        ];

        return ['centres' => $centres, 'totals' => $totals];
    }

    // =========================================================================
    // MONTHLY TREND — chargeback vs refund $ per month, last N months
    // =========================================================================

    public function monthlyTrend(int $months = 6): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ms = Carbon::now()->subMonths($i)->startOfMonth();
            $me = $ms->copy()->endOfMonth();
            $row = ChargebackRefund::whereBetween('event_date', [$ms->toDateString(), $me->toDateString()])
                ->selectRaw("SUM(CASE WHEN kind='chargeback' THEN amount ELSE 0 END) AS cb,
                             SUM(CASE WHEN kind='refund'     THEN amount ELSE 0 END) AS rf")
                ->first();
            $out[] = [
                'label'      => $ms->format('M'),
                'chargeback' => round((float) ($row->cb ?? 0), 2),
                'refund'     => round((float) ($row->rf ?? 0), 2),
            ];
        }
        return $out;
    }
}
