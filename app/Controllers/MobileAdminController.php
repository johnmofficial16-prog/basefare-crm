<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Transaction;
use App\Models\AcceptanceRequest;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

/**
 * MobileAdminController
 *
 * Serves the mobile-first Admin Quick Panel at /admin/mobile.
 * Provides JSON data APIs consumed by the panel's tabs.
 * Access: Admin + Manager only (enforced in routes middleware + here).
 */
class MobileAdminController
{
    private AttendanceService $attendanceService;

    public function __construct()
    {
        $this->attendanceService = new AttendanceService();
    }

    // =========================================================================
    // PAGE  —  GET /admin/mobile
    // =========================================================================

    public function page(Request $request, Response $response): Response
    {
        $userRole = $_SESSION['role'] ?? '';
        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $response->getBody()->write('<p style="padding:2rem;font-family:sans-serif">Access denied.</p>');
            return $response->withStatus(403);
        }

        $userName = $_SESSION['user_name'] ?? 'Admin';
        $csrfToken = $_SESSION['csrf_token'] ?? '';

        ob_start();
        require __DIR__ . '/../Views/admin/mobile_admin.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // DASHBOARD DATA  —  GET /api/admin/mobile/data
    // =========================================================================

    public function dashboardData(Request $request, Response $response): Response
    {
        $userRole = $_SESSION['role'] ?? '';
        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $this->json($response, ['error' => 'Access denied.'], 403);
        }

        $todayStart = Carbon::today()->startOfDay();
        $todayEnd   = Carbon::today()->endOfDay();
        $monthStart = Carbon::now()->startOfMonth()->startOfDay();
        $monthEnd   = Carbon::now()->endOfMonth()->endOfDay();

        // Today KPIs
        $todayTxns   = Transaction::whereBetween('created_at', [$todayStart, $todayEnd])
                           ->where('status', '!=', Transaction::STATUS_VOIDED)->count();
        $todayProfit = (float) Transaction::whereBetween('created_at', [$todayStart, $todayEnd])
                           ->where('status', '!=', Transaction::STATUS_VOIDED)->sum('profit_mco');
        $pendingTxns = Transaction::where('status', Transaction::STATUS_PENDING)->count();
        $pendingAcc  = AcceptanceRequest::where('status', AcceptanceRequest::STATUS_PENDING)
                           ->where('is_preauth', false)->count();

        // Month KPIs
        $monthTxns   = Transaction::whereBetween('created_at', [$monthStart, $monthEnd])
                           ->where('status', '!=', Transaction::STATUS_VOIDED)->count();
        $monthProfit = (float) Transaction::whereBetween('created_at', [$monthStart, $monthEnd])
                           ->where('status', '!=', Transaction::STATUS_VOIDED)->sum('profit_mco');

        // Today's leaderboard
        $leaderboard = Transaction::whereBetween('created_at', [$todayStart, $todayEnd])
            ->where('status', '!=', Transaction::STATUS_VOIDED)
            ->selectRaw('agent_id, SUM(profit_mco) as profit, COUNT(*) as txn_count, MAX(currency) as currency')
            ->groupBy('agent_id')->orderByDesc('profit')->limit(5)
            ->with('agent:id,name')->get()
            ->map(fn($r) => [
                'name'      => $r->agent->name ?? 'Unknown',
                'profit'    => round((float)$r->profit, 2),
                'txn_count' => (int)$r->txn_count,
                'currency'  => $r->currency ?? 'USD',
            ])->values()->toArray();

        // Recent transactions
        $recent = Transaction::with('agent:id,name')->latest()->limit(8)
            ->get(['id','agent_id','customer_name','type','total_amount','profit_mco','currency','status','created_at'])
            ->map(fn($t) => [
                'id'            => $t->id,
                'customer_name' => $t->customer_name,
                'type'          => $t->typeLabel(),
                'amount'        => (float)$t->total_amount,
                'mco'           => (float)$t->profit_mco,
                'currency'      => $t->currency,
                'status'        => $t->status,
                'agent'         => $t->agent->name ?? '—',
                'created_at'    => $t->created_at ? $t->created_at->format('M j, g:i A') : '—',
            ])->values()->toArray();

        return $this->json($response, [
            'today_txns'   => $todayTxns,
            'today_profit' => $todayProfit,
            'pending_txns' => $pendingTxns,
            'pending_acc'  => $pendingAcc,
            'month_txns'   => $monthTxns,
            'month_profit' => $monthProfit,
            'leaderboard'  => $leaderboard,
            'recent'       => $recent,
            'month_label'  => $monthStart->format('M j') . ' – ' . $monthEnd->format('M j, Y'),
        ]);
    }

    // =========================================================================
    // TRANSACTIONS DATA  —  GET /api/admin/mobile/transactions
    // Supports ?status=pending|approved|voided&page=N
    // =========================================================================

    public function transactionsData(Request $request, Response $response): Response
    {
        $userRole = $_SESSION['role'] ?? '';
        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $this->json($response, ['error' => 'Access denied.'], 403);
        }

        $params = $request->getQueryParams();
        $status = $params['status'] ?? 'all';
        $page   = max(1, (int)($params['page'] ?? 1));
        $perPage = 20;

        $query = Transaction::with('agent:id,name')->latest();

        if ($status === 'pending') {
            $query->where('status', Transaction::STATUS_PENDING);
        } elseif ($status === 'approved') {
            $query->where('status', Transaction::STATUS_APPROVED);
        } elseif ($status === 'voided') {
            $query->where('status', Transaction::STATUS_VOIDED);
        }

        $total   = $query->count();
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $data = $records->map(fn($t) => [
            'id'            => $t->id,
            'customer_name' => $t->customer_name,
            'pnr'           => $t->pnr,
            'type'          => $t->typeLabel(),
            'type_badge'    => $t->typeBadge(),
            'amount'        => (float)$t->total_amount,
            'mco'           => (float)$t->profit_mco,
            'currency'      => $t->currency,
            'status'        => $t->status,
            'payment_status'=> $t->payment_status,
            'agent'         => $t->agent->name ?? '—',
            'gateway_status'=> $t->gateway_status,
            'gateway_txn_id'=> $t->gateway_transaction_id,
            'created_at'    => $t->created_at ? $t->created_at->format('M j, Y g:i A') : '—',
        ])->values()->toArray();

        return $this->json($response, [
            'data'       => $data,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'last_page'  => (int)ceil($total / $perPage),
        ]);
    }

    // =========================================================================
    // ACTIONS DATA  —  GET /api/admin/mobile/actions
    // Returns pending transactions + pending acceptances for Actions tab
    // =========================================================================

    public function actionsData(Request $request, Response $response): Response
    {
        $userRole = $_SESSION['role'] ?? '';
        if (!in_array($userRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $this->json($response, ['error' => 'Access denied.'], 403);
        }

        $pendingTxns = Transaction::where('status', Transaction::STATUS_PENDING)
            ->with('agent:id,name')->latest()->limit(30)->get()
            ->map(fn($t) => [
                'id'            => $t->id,
                'customer_name' => $t->customer_name,
                'pnr'           => $t->pnr,
                'type'          => $t->typeLabel(),
                'type_badge'    => $t->typeBadge(),
                'amount'        => (float)$t->total_amount,
                'mco'           => (float)$t->profit_mco,
                'currency'      => $t->currency,
                'agent'         => $t->agent->name ?? '—',
                'gateway_status'=> $t->gateway_status,
                'gateway_txn_id'=> $t->gateway_transaction_id ?? '',
                'created_at'    => $t->created_at ? $t->created_at->format('M j, g:i A') : '—',
            ])->values()->toArray();

        $pendingAcc = AcceptanceRequest::where('status', AcceptanceRequest::STATUS_PENDING)
            ->where('is_preauth', false)
            ->with('agent:id,name')->latest()->limit(20)->get()
            ->map(fn($a) => [
                'id'            => $a->id,
                'customer_name' => $a->customer_name,
                'pnr'           => $a->pnr,
                'type'          => ucwords(str_replace('_', ' ', $a->type)),
                'amount'        => (float)$a->total_amount,
                'currency'      => $a->currency,
                'agent'         => $a->agent->name ?? '—',
                'created_at'    => $a->created_at ? $a->created_at->format('M j, g:i A') : '—',
            ])->values()->toArray();

        return $this->json($response, [
            'pending_txns' => $pendingTxns,
            'pending_acc'  => $pendingAcc,
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
