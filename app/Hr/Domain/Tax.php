<?php

namespace App\Hr\Domain;

/**
 * Salary TDS (Income-tax Act 2025, s.392; incentives paid to employees are
 * salary). Pure calculations — every rate comes from the payroll settings'
 * tax table, so the CA can correct a figure without a code change.
 *
 * The monthly method (HR_PLAN §3.10): estimate the year's taxable income,
 * work out the year's tax, take off what was already deducted, and spread the
 * rest over the months left. The extra tax an incentive causes is taken in
 * the month it is paid, so later months don't carry it.
 *
 * Defaults are FY 2026-27 (unchanged by Budget 2026): new regime nil to ₹4 lakh,
 * then 5/10/15/20/25%, 30% above ₹24 lakh; standard deduction ₹75,000; rebate up
 * to ₹60,000 when taxable income is ₹12 lakh or less (with marginal relief);
 * 4% health and education cess. Old regime: 2.5/5/10 lakh at 5/20/30%,
 * standard deduction ₹50,000, rebate ₹12,500 to ₹5 lakh. No surcharge (it
 * starts above ₹50 lakh, far beyond this payroll); the CA can add it.
 */
final class Tax
{
    public const DEFAULT_TABLE = [
        'source' => 'FY 2026-27 rates (Budget 2026 made no change). Check with the CA every April.',
        'cess_pct' => 4,
        'no_pan_pct' => 20,
        'new' => ['std' => 75000, 'rebate_limit' => 1200000, 'rebate_max' => 60000,
                  'slabs' => [[400000, 0], [800000, 5], [1200000, 10], [1600000, 15], [2000000, 20], [2400000, 25], [null, 30]]],
        'old' => ['std' => 50000, 'rebate_limit' => 500000, 'rebate_max' => 12500,
                  'slabs' => [[250000, 0], [500000, 5], [1000000, 20], [null, 30]]],
    ];

    /** "2026-27" for any month from April 2026 to March 2027. */
    public static function taxYear(string $ym): string
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $start = $m >= 4 ? $y : $y - 1;
        return $start . '-' . substr((string) ($start + 1), -2);
    }

    /** The months of a tax year, April first. */
    public static function yearMonths(string $taxYear): array
    {
        $start = (int) substr($taxYear, 0, 4);
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $out[] = date('Y-m', strtotime(sprintf('%04d-04-01 +%d months', $start, $i)));
        }
        return $out;
    }

    /** Months left in the tax year, counting this one (April = 12, March = 1). */
    public static function monthsLeft(string $ym): int
    {
        $m = (int) substr($ym, 5, 2);
        return $m >= 4 ? 16 - $m : 4 - $m;
    }

    /** Slab tax on taxable income, before rebate and cess. */
    public static function slabTax(int $taxable, array $slabs): float
    {
        $tax = 0.0;
        $lower = 0;
        foreach ($slabs as [$upper, $pct]) {
            if ($taxable <= $lower) {
                break;
            }
            $top = $upper === null ? $taxable : min($taxable, (int) $upper);
            $tax += ($top - $lower) * ((float) $pct / 100);
            if ($upper === null) {
                break;
            }
            $lower = (int) $upper;
        }
        return $tax;
    }

    /**
     * Tax for a year on taxable income (after the standard deduction etc.),
     * with the rebate, marginal relief just above the rebate limit (new
     * regime), and cess. Whole rupees.
     */
    public static function annualTax(int $taxable, string $regime, array $table): int
    {
        $r = $table[$regime] ?? $table['new'];
        $taxable = max(0, $taxable);
        $tax = self::slabTax($taxable, $r['slabs']);
        if ($taxable <= (int) $r['rebate_limit']) {
            $tax = max(0.0, $tax - (float) $r['rebate_max']);
        } elseif ($regime === 'new') {
            // Marginal relief: the tax can't be more than the income above the limit.
            $tax = min($tax, (float) ($taxable - (int) $r['rebate_limit']));
        }
        return (int) round($tax * (1 + (float) $table['cess_pct'] / 100));
    }

    /**
     * This month's TDS.
     *
     * @param array $in [
     *   'regime' => 'new'|'old', 'month' => 'YYYY-MM',
     *   'paid_salary' => taxable salary already paid this tax year (earlier runs + previous employer),
     *   'paid_tds' => TDS already deducted this tax year (earlier runs + previous employer),
     *   'salary' => this month's taxable salary (earned + taxable additions),
     *   'incentive' => this month's incentive,
     *   'monthly' => the full monthly salary (for the months still to come),
     *   'deductions' => old-regime declared deductions,
     *   'final' => true for a final settlement (no months to come),
     *   'pan_ok' => bool ]
     * @return array{tds:int, annual_tax:int, projected:int, salary_tds:int, incentive_tds:int, why:string}
     */
    public static function monthly(array $in, array $table): array
    {
        $regime = $in['regime'] === 'old' ? 'old' : 'new';
        $r = $table[$regime];
        $left = !empty($in['final']) ? 1 : self::monthsLeft($in['month']);
        $future = !empty($in['final']) ? 0 : (int) $in['monthly'] * ($left - 1);
        $deduct = (int) $r['std'] + ($regime === 'old' ? (int) ($in['deductions'] ?? 0) : 0);

        $withoutInc = (int) $in['paid_salary'] + (int) $in['salary'] + $future;
        $withInc = $withoutInc + (int) $in['incentive'];
        $taxNoInc = self::annualTax($withoutInc - $deduct, $regime, $table);
        $taxAll = self::annualTax($withInc - $deduct, $regime, $table);

        // Salary part spread over the months left; the incentive's extra tax all now.
        $salaryTds = (int) max(0, round(($taxNoInc - (int) $in['paid_tds']) / $left));
        $incTds = (int) max(0, $taxAll - $taxNoInc);
        $tds = $salaryTds + $incTds;

        $why = $taxAll === 0
            ? 'No tax this year: estimated taxable income ₹' . number_format(max(0, $withInc - $deduct)) . ' is within the ' . ($regime === 'new' ? 'new' : 'old') . ' regime\'s tax-free limit.'
            : 'Estimated income this year ₹' . number_format($withInc) . ', less ₹' . number_format($deduct) . ' deductions; tax for the year ₹'
              . number_format($taxAll) . '. Already deducted ₹' . number_format((int) $in['paid_tds']) . '; the rest is spread over ' . $left
              . ' month' . ($left === 1 ? '' : 's') . ($incTds ? ', and the extra tax on this month\'s incentive (₹' . number_format($incTds) . ') is taken now' : '') . '.';

        // No valid PAN: at least the higher rate on this month's pay (when any tax is due at all).
        if (empty($in['pan_ok']) && $taxAll > 0) {
            $floor = (int) round(((int) $in['salary'] + (int) $in['incentive']) * (float) $table['no_pan_pct'] / 100);
            if ($floor > $tds) {
                $tds = $floor;
                $why .= ' No valid PAN on record, so the higher ' . $table['no_pan_pct'] . '% rate applies to this month\'s pay.';
            }
        }
        return ['tds' => $tds, 'annual_tax' => $taxAll, 'projected' => $withInc, 'salary_tds' => $salaryTds, 'incentive_tds' => $incTds, 'why' => $why];
    }
}
