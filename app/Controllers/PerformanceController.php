<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Illuminate\Database\Capsule\Manager as DB;
use App\Models\Transaction;
use App\Models\AcceptanceRequest;
use App\Models\User;
use App\Services\ShiftService;
use Carbon\Carbon;

/**
 * Agent Performance / Scores
 *
 * Gives team leads (manager / supervisor) and admins an agent-wise scoreboard so
 * they can analyse how their agents are performing and assist accordingly.
 *
 * Scope rules:
 *   - admin              → the whole workforce (agents, csa, supervisors, managers)
 *   - manager/supervisor → their direct reports only (getTeamAgentIds)
 *
 * The export is deliberately PII-free: only agent name, role and aggregate scores
 * leave the system — never customer names, contacts, PNRs, cards or amounts tied
 * to an individual booking.
 */
class PerformanceController
{
    // =========================================================================
    // PAGE — GET /performance
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];
        $role   = $_SESSION['role'] ?? User::ROLE_AGENT;

        $q = $request->getQueryParams();
        [$period, $start, $end, $periodLabel] = $this->resolvePeriod($q);

        // Currency label for display (mirrors DashboardController)
        $currencyRow = DB::table('system_config')->where('key', 'default_currency')->first();
        $currency    = $currencyRow?->value ?? 'CAD';

        // Values the form needs to repopulate its inputs.
        $selMonth = $q['month']     ?? Carbon::now()->format('Y-m');
        $selFrom  = $q['date_from'] ?? '';
        $selTo    = $q['date_to']   ?? '';

        // ── Agent self-view: own day-wise bookings + rank among peers ───────────
        if ($role === User::ROLE_AGENT) {
            $rows       = $this->bookingDetail([$userId], $start, $end);
            $netTotals  = $this->agentNetTotals($start, $end);
            [$rank, $rankTotal, $myGross, $myNet] = $this->rankFor($userId, $netTotals);
            $myBookings = count($rows);

            ob_start();
            require __DIR__ . '/../Views/performance/agent.php';
            $html = ob_get_clean();
            $response->getBody()->write($html);
            return $response;
        }

        // ── Team lead / admin scoreboard ────────────────────────────────────────
        $agents = $this->scopedAgents($userId, $role);
        $rows   = $this->computeScores($agents, $start, $end);
        $totals = $this->summarise($rows);

        // Preserve the current filter so the Export button carries it through.
        $exportQuery = http_build_query(array_filter([
            'period'    => $period,
            'month'     => $q['month']     ?? null,
            'date_from' => $q['date_from'] ?? null,
            'date_to'   => $q['date_to']   ?? null,
        ], fn($v) => $v !== null && $v !== ''));

        ob_start();
        require __DIR__ . '/../Views/performance/index.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // AGENT DRILL-DOWN — GET /performance/agent/{id}  (team leads / admin)
    // =========================================================================

    public function agentDetail(Request $request, Response $response, array $args): Response
    {
        $userId = (int)$_SESSION['user_id'];
        $role   = $_SESSION['role'] ?? User::ROLE_AGENT;

        // Agents use their own self-view; this drill-down is for team leads/admin.
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR], true)) {
            return $response->withHeader('Location', '/performance')->withStatus(302);
        }

        $targetId = (int)$args['id'];

        // Managers/supervisors may only inspect their own team (or themselves).
        if ($role !== User::ROLE_ADMIN && $targetId !== $userId) {
            $actor = User::find($userId);
            if (!$actor || !$actor->isInMyTeam($targetId)) {
                $_SESSION['flash_error'] = 'Access denied.';
                return $response->withHeader('Location', '/performance')->withStatus(302);
            }
        }

        $target = User::find($targetId);
        if (!$target) {
            return $response->withHeader('Location', '/performance')->withStatus(302);
        }

        $q = $request->getQueryParams();
        [$period, $start, $end, $periodLabel] = $this->resolvePeriod($q);

        $currencyRow = DB::table('system_config')->where('key', 'default_currency')->first();
        $currency    = $currencyRow?->value ?? 'CAD';
        $selMonth = $q['month']     ?? Carbon::now()->format('Y-m');
        $selFrom  = $q['date_from'] ?? '';
        $selTo    = $q['date_to']   ?? '';

        $rows = $this->bookingDetail([$targetId], $start, $end);

        ob_start();
        require __DIR__ . '/../Views/performance/detail.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // PRIVATE — per-booking detail + agent ranking
    // =========================================================================

    /**
     * Per-booking rows (approved only) for the given agents in the window.
     * Each row carries Gross MCO and refund-adjusted Net MCO.
     */
    private function bookingDetail(array $agentIds, ?Carbon $start, ?Carbon $end): array
    {
        if (empty($agentIds)) {
            return [];
        }
        $txns = Transaction::with('agent')
            ->whereIn('agent_id', $agentIds)
            ->where('status', Transaction::STATUS_APPROVED)
            ->when($start, fn($qq) => $qq->whereBetween('created_at', [$start, $end]))
            ->orderByDesc('created_at')
            ->get();

        return $txns->map(fn($t) => [
            'id'              => $t->id,
            'date'            => $t->created_at,
            'customer_name'   => $t->customer_name,
            'phone'           => $t->customer_phone,
            'email'           => $t->customer_email,
            'pnr'             => $t->pnr,
            'gross'           => $t->grossMco(),
            'net'             => $t->netMco(),
            'refund_status'   => $t->refund_status,
            'refunded_amount' => (float) $t->refunded_amount,
            'currency'        => $t->currency,
            'agent_name'      => $t->agent->name ?? '—',
        ])->all();
    }

    /**
     * Net + Gross MCO totals per active agent in the window, keyed by agent id.
     * Used to rank an agent against their peers.
     */
    private function agentNetTotals(?Carbon $start, ?Carbon $end): array
    {
        $agentIds = User::where('role', User::ROLE_AGENT)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->pluck('id')->all();
        if (empty($agentIds)) {
            return [];
        }

        $agg = Transaction::whereIn('agent_id', $agentIds)
            ->where('status', Transaction::STATUS_APPROVED)
            ->when($start, fn($qq) => $qq->whereBetween('created_at', [$start, $end]))
            ->selectRaw('agent_id, SUM(profit_mco) AS gross, SUM(refund_mco_impact) AS impact')
            ->groupBy('agent_id')->get()->keyBy('agent_id');

        $out = [];
        foreach ($agentIds as $aid) {
            $g = (float) ($agg->get($aid)->gross  ?? 0);
            $i = (float) ($agg->get($aid)->impact ?? 0);
            $out[$aid] = ['gross' => $g, 'net' => round($g - $i, 2)];
        }
        return $out;
    }

    /**
     * Resolve an agent's rank (1 = best) by Net MCO among their peers.
     *
     * @return array{0:int,1:int,2:float,3:float} [rank, totalAgents, myGross, myNet]
     */
    private function rankFor(int $agentId, array $netTotals): array
    {
        uasort($netTotals, fn($a, $b) => $b['net'] <=> $a['net']);
        $rank = 0; $i = 0; $mine = ['gross' => 0.0, 'net' => 0.0];
        foreach ($netTotals as $aid => $v) {
            $i++;
            if ((int) $aid === $agentId) { $rank = $i; $mine = $v; }
        }
        return [$rank, count($netTotals), $mine['gross'], $mine['net']];
    }

    // =========================================================================
    // EXPORT — GET /performance/export  (CSV, PII-free)
    // =========================================================================

    public function export(Request $request, Response $response): Response
    {
        $userId = (int)$_SESSION['user_id'];
        $role   = $_SESSION['role'] ?? User::ROLE_AGENT;

        // Agents have a personal self-view, not the cross-agent export.
        if ($role === User::ROLE_AGENT) {
            $response->getBody()->write('Access denied.');
            return $response->withStatus(403);
        }

        $q = $request->getQueryParams();
        [$period, $start, $end, $periodLabel] = $this->resolvePeriod($q);

        $agents = $this->scopedAgents($userId, $role);
        $rows   = $this->computeScores($agents, $start, $end);

        // ── Headers: names + scores + analysis only. No customer PII, no card data,
        //    no contact info, no PNRs, no per-booking amounts. ─────────────────────
        $headers = [
            'Rank', 'Agent', 'Role',
            'Bookings', 'Approved', 'Pending', 'Voided',
            'Acceptances', 'Revenue (Approved)', 'Profit MCO (Approved)', 'Avg Profit / Approved',
            'Currency',
        ];

        $rank = 0;
        $csvRows = array_map(function ($r) use (&$rank) {
            $rank++;
            return [
                $rank,
                $r['name'],
                ucfirst($r['role']),
                $r['bookings'],
                $r['approved'],
                $r['pending'],
                $r['voided'],
                $r['acceptances'],
                number_format($r['revenue'], 2, '.', ''),
                number_format($r['profit'], 2, '.', ''),
                number_format($r['avg_profit'], 2, '.', ''),
                $r['currency'] ?? '',
            ];
        }, $rows);

        $slug     = preg_replace('/[^a-z0-9]+/', '-', strtolower($period));
        $filename = 'agent-performance_' . $slug . '_' . date('Y-m-d') . '.csv';

        return $this->csvResponse($response, $headers, $csvRows, $filename, $periodLabel);
    }

    // =========================================================================
    // PRIVATE — scope, period, scoring
    // =========================================================================

    /**
     * The agents this actor is allowed to analyse.
     * Returns an Eloquent collection of User (id, name, role), name-sorted.
     */
    private function scopedAgents(int $userId, string $role)
    {
        if ($role === User::ROLE_ADMIN) {
            return User::whereIn('role', [
                    User::ROLE_AGENT, User::ROLE_CSA,
                    User::ROLE_SUPERVISOR, User::ROLE_MANAGER,
                ])
                ->where('status', User::STATUS_ACTIVE)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name', 'role']);
        }

        // manager / supervisor → direct reports + themselves. A team lead's own
        // bookings count everywhere else (transactions, dashboard KPIs), so their
        // own row belongs on their performance board too.
        $actor = User::find($userId);
        if (!$actor) {
            return collect();
        }
        $ids   = $actor->getTeamAgentIds();
        $ids[] = (int)$userId;
        $ids   = array_values(array_unique($ids));

        return User::whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

    /**
     * Turn the request into a [period, start, end, label] window.
     * start/end are Carbon instances, or null/null for all-time ("till date").
     *
     * @return array{0:string,1:?Carbon,2:?Carbon,3:string}
     */
    private function resolvePeriod(array $q): array
    {
        $period = $q['period'] ?? 'monthly';
        $now    = Carbon::now();

        try {
            switch ($period) {
                case 'daily':
                    // Operations "business day", not the calendar day — matches the
                    // dashboard/liveboard so daily scores tie out with what they see.
                    [$start, $end] = ShiftService::businessDayBounds();
                    $label = 'Business day · ' . $start->format('M j, g:i A')
                           . ' → ' . $start->copy()->addDay()->format('M j, g:i A');
                    break;

                case 'alltime':
                    $start = null;
                    $end   = null;
                    $label = 'All time (till date)';
                    break;

                case 'custom':
                    $start = !empty($q['date_from'])
                        ? Carbon::parse($q['date_from'])->startOfDay()
                        : $now->copy()->startOfMonth()->startOfDay();
                    $end = !empty($q['date_to'])
                        ? Carbon::parse($q['date_to'])->endOfDay()
                        : $now->copy()->endOfDay();
                    // Guard against an inverted range.
                    if ($end->lt($start)) {
                        [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
                    }
                    $label = 'Custom · ' . $start->format('M j, Y') . ' → ' . $end->format('M j, Y');
                    break;

                case 'monthly':
                default:
                    $period = 'monthly';
                    $month  = !empty($q['month']) ? $q['month'] : $now->format('Y-m');
                    $start  = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth()->startOfDay();
                    $end    = $start->copy()->endOfMonth()->endOfDay();
                    $label  = $start->format('F Y');
                    break;
            }
        } catch (\Throwable $e) {
            // Bad date input → fall back to the current month.
            $period = 'monthly';
            $start  = $now->copy()->startOfMonth()->startOfDay();
            $end    = $now->copy()->endOfMonth()->endOfDay();
            $label  = $start->format('F Y');
        }

        return [$period, $start, $end, $label];
    }

    /**
     * Aggregate per-agent scores for the window. Agents with zero activity are
     * still returned (a blank row is meaningful when analysing performance).
     * Sorted by profit, descending.
     */
    private function computeScores($agents, ?Carbon $start, ?Carbon $end): array
    {
        if ($agents->isEmpty()) {
            return [];
        }
        $ids = $agents->pluck('id')->all();

        // One grouped pass over transactions with conditional aggregates.
        $txn = Transaction::whereIn('agent_id', $ids)
            ->when($start, fn($qq) => $qq->whereBetween('created_at', [$start, $end]))
            ->selectRaw(
                "agent_id,
                 SUM(status <> 'voided')             AS bookings,
                 SUM(status =  'approved')           AS approved,
                 SUM(status =  'pending_review')     AS pending,
                 SUM(status =  'voided')             AS voided,
                 -- Money is counted on APPROVED transactions only (realized), so
                 -- pending bookings don't inflate an agent's revenue/profit score.
                 SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END) AS revenue,
                 SUM(CASE WHEN status = 'approved' THEN profit_mco   ELSE 0 END) AS profit,
                 -- Net MCO = Gross profit minus the MCO removed by refunds.
                 SUM(CASE WHEN status = 'approved' THEN refund_mco_impact ELSE 0 END) AS refund_impact,
                 MAX(currency)                       AS currency"
            )
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        // Approved (non-preauth) acceptances per agent.
        $acc = AcceptanceRequest::whereIn('agent_id', $ids)
            ->where('status', AcceptanceRequest::STATUS_APPROVED)
            ->where('is_preauth', false)
            ->when($start, fn($qq) => $qq->whereBetween('approved_at', [$start, $end]))
            ->selectRaw('agent_id, COUNT(*) AS acc_count')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        $rows = [];
        foreach ($agents as $a) {
            $t        = $txn->get($a->id);
            $bookings = (int)   ($t->bookings ?? 0);
            $approved = (int)   ($t->approved ?? 0);
            $profit   = (float) ($t->profit   ?? 0);
            $net      = round($profit - (float) ($t->refund_impact ?? 0), 2);
            $rows[] = [
                'id'          => $a->id,
                'name'        => $a->name,
                'role'        => $a->role,
                'bookings'    => $bookings,
                'approved'    => $approved,
                'pending'     => (int)   ($t->pending  ?? 0),
                'voided'      => (int)   ($t->voided   ?? 0),
                'acceptances' => (int)   ($acc->get($a->id)->acc_count ?? 0),
                'revenue'     => (float) ($t->revenue ?? 0),
                'profit'      => $profit,   // Gross MCO
                'net'         => $net,      // Net MCO (after refunds)
                // Average is profit per approved booking (same basis as profit).
                'avg_profit'  => $approved > 0 ? $profit / $approved : 0.0,
                'currency'    => $t->currency ?? null,
            ];
        }

        usort($rows, fn($x, $y) => $y['profit'] <=> $x['profit']);
        return $rows;
    }

    /** Team-level rollups used for the analysis cards. */
    private function summarise(array $rows): array
    {
        $agentCount  = count($rows);
        $activeCount = count(array_filter($rows, fn($r) => $r['bookings'] > 0));
        $bookings    = array_sum(array_column($rows, 'bookings'));
        $approved    = array_sum(array_column($rows, 'approved'));
        $acceptances = array_sum(array_column($rows, 'acceptances'));
        $revenue     = array_sum(array_column($rows, 'revenue'));
        $profit      = array_sum(array_column($rows, 'profit'));
        $net         = array_sum(array_column($rows, 'net'));

        $top = $rows[0] ?? null; // already profit-sorted

        return [
            'agent_count'  => $agentCount,
            'active_count' => $activeCount,
            'bookings'     => $bookings,
            'approved'     => $approved,
            'acceptances'  => $acceptances,
            'revenue'      => $revenue,
            'profit'       => $profit,
            'net'          => $net,
            // Profit is approved-only, so the per-booking average uses approved count.
            'avg_profit'   => $approved > 0 ? $profit / $approved : 0.0,
            'avg_per_agent'=> $agentCount > 0 ? $profit / $agentCount : 0.0,
            'top_name'     => $top['name'] ?? null,
            'top_profit'   => $top['profit'] ?? 0.0,
        ];
    }

    // =========================================================================
    // PRIVATE — CSV writer (UTF-8 BOM for Excel)
    // =========================================================================

    private function csvResponse(Response $response, array $headers, iterable $rows, string $filename, string $periodLabel): Response
    {
        $tmp = fopen('php://temp', 'r+');
        // Provenance line so an exported sheet is self-describing.
        fputcsv($tmp, ['Agent Performance Export', $periodLabel, 'Generated ' . date('Y-m-d H:i')]);
        fputcsv($tmp, []);
        fputcsv($tmp, $headers);
        foreach ($rows as $row) {
            fputcsv($tmp, array_values((array)$row));
        }
        rewind($tmp);
        $csv = stream_get_contents($tmp);
        fclose($tmp);

        $response->getBody()->write("\xEF\xBB\xBF" . $csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
