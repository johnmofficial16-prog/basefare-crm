<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use App\Services\AttendanceService;
use App\Services\ShiftService;
use App\Models\AttendanceSession;
use App\Models\AcceptanceRequest;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class DashboardController
{
    private AttendanceService $attendanceService;
    private ShiftService $shiftService;

    public function __construct()
    {
        $this->shiftService     = new ShiftService();
        $this->attendanceService = new AttendanceService();
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'];
        $role   = $_SESSION['role'] ?? 'agent';

        // ── Attendance state + today's session ───────────────────────────────
        $stateInfo    = $this->attendanceService->getCurrentState($userId);
        $todaySession = AttendanceSession::forUser($userId)
            ->forDate(date('Y-m-d'))
            ->whereIn('status', [AttendanceSession::STATUS_ACTIVE, AttendanceSession::STATUS_COMPLETED])
            ->latest('id')->first();

        $todayStats = [
            'work_mins'  => $todaySession?->total_work_mins  ?? 0,
            'break_mins' => $todaySession?->total_break_mins ?? 0,
            'clock_in'   => $todaySession?->clock_in,
            'clock_out'  => $todaySession?->clock_out,
            'late_mins'  => $todaySession?->late_minutes     ?? 0,
            'status'     => $todaySession?->status           ?? 'none',
        ];

        // ── This week's attendance sessions ──────────────────────────────────
        $startOfWeek  = date('Y-m-d', strtotime('monday this week'));
        $weekSessions = AttendanceSession::forUser($userId)
            ->whereBetween('date', [$startOfWeek, date('Y-m-d', strtotime('sunday this week'))])
            ->orderBy('date', 'asc')->get()
            ->groupBy(fn($s) => date('Y-m-d', strtotime($s->date)));

        $weekData = [];
        for ($d = 0; $d < 7; $d++) {
            $dateStr  = date('Y-m-d', strtotime($startOfWeek . " + $d days"));
            $sessions = $weekSessions->get($dateStr, collect());
            $weekData[] = [
                'date'       => $dateStr,
                'day'        => date('D', strtotime($dateStr)),
                'has_data'   => $sessions->count() > 0,
                'work_mins'  => $sessions->sum('total_work_mins'),
                'break_mins' => $sessions->sum('total_break_mins'),
                'late_mins'  => $sessions->sum('late_minutes'),
                'is_today'   => $dateStr === date('Y-m-d'),
            ];
        }

        $todayShift = $this->shiftService->getAgentShiftForDate($userId, date('Y-m-d'));

        // ── Attendance board counts (admin / manager / supervisor) ────────────
        $adminCounts = null;
        if (in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $bd = $this->attendanceService->getLiveBoardData();
            $adminCounts = [
                'in' => count($bd['in']), 'on_break' => count($bd['on_break']),
                'completed' => count($bd['completed']), 'absent' => count($bd['absent']),
                'pending' => count($bd['pending_override']),
            ];
        } elseif ($role === User::ROLE_SUPERVISOR) {
            $sup     = User::find($userId);
            $teamIds = $sup ? $sup->getTeamAgentIds() : [];
            if (!empty($teamIds)) {
                $bd = $this->attendanceService->getLiveBoardData($teamIds);
                $adminCounts = [
                    'in' => count($bd['in']), 'on_break' => count($bd['on_break']),
                    'completed' => count($bd['completed']), 'absent' => count($bd['absent']),
                    'pending' => count($bd['pending_override']),
                ];
            }
        }

        // ── Date-range helpers ────────────────────────────────────────────────
        $todayStart = Carbon::today()->startOfDay();
        $todayEnd   = Carbon::today()->endOfDay();
        $weekStart  = Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd    = Carbon::now()->endOfWeek(Carbon::SUNDAY)->endOfDay();
        $weekLabel  = $weekStart->format('M j') . ' – ' . $weekEnd->format('M j, Y');

        // =====================================================================
        // ADMIN / MANAGER — full business dashboard
        // =====================================================================
        $dashboardData = null;
        if (in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {

            // Acceptance KPIs
            $pendingAcc    = AcceptanceRequest::where('status', AcceptanceRequest::STATUS_PENDING)
                                ->where('is_preauth', false)->count();
            $todayNewAcc   = AcceptanceRequest::whereBetween('created_at', [$todayStart, $todayEnd])->count();
            $todayApprAcc  = AcceptanceRequest::where('status', AcceptanceRequest::STATUS_APPROVED)
                                ->whereBetween('approved_at', [$todayStart, $todayEnd])->count();
            $expiringSoon  = AcceptanceRequest::where('status', AcceptanceRequest::STATUS_PENDING)
                                ->where('is_preauth', false)
                                ->where('created_at', '<', Carbon::now()->subHours(8))->count();

            // Transaction KPIs — today (explicit queries, no clone)
            $todayBase        = fn() => Transaction::whereBetween('created_at', [$todayStart, $todayEnd])
                                    ->where('status', '!=', Transaction::STATUS_VOIDED);
            $todayTxnCount    = $todayBase()->count();
            $todayRevenue     = (float) $todayBase()->sum('total_amount');
            $todayCost        = (float) $todayBase()->sum('cost_amount');
            $todayProfit      = $todayRevenue - $todayCost;
            $pendingTxnCount  = Transaction::where('status', Transaction::STATUS_PENDING)->count();

            // Transaction KPIs — this week (explicit queries)
            $weekBase       = fn() => Transaction::whereBetween('created_at', [$weekStart, $weekEnd])
                                  ->where('status', '!=', Transaction::STATUS_VOIDED);
            $weekTxnCount   = $weekBase()->count();
            $weekRevenue    = (float) $weekBase()->sum('total_amount');
            $weekCost       = (float) $weekBase()->sum('cost_amount');
            $weekProfit     = $weekRevenue - $weekCost;

            // All-time totals (non-voided)
            $allBase        = fn() => Transaction::where('status', '!=', Transaction::STATUS_VOIDED);
            $allTxnCount    = $allBase()->count();
            $allRevenue     = (float) $allBase()->sum('total_amount');
            $allProfit      = (float) $allBase()->sum('profit_mco');

            // Agent leaderboard — today
            $leaderboard = Transaction::whereBetween('created_at', [$todayStart, $todayEnd])
                ->where('status', '!=', Transaction::STATUS_VOIDED)
                ->selectRaw('agent_id, SUM(total_amount) as revenue, SUM(profit_mco) as profit, COUNT(*) as txn_count')
                ->groupBy('agent_id')->orderByDesc('revenue')->limit(5)
                ->with('agent:id,name')->get();

            // Recent activity
            $recentTxns = Transaction::with('agent:id,name')->latest()->limit(6)
                ->get(['id','agent_id','customer_name','type','total_amount','currency','status','created_at']);
            $recentAcceptances = AcceptanceRequest::with('agent:id,name')->latest()->limit(6)
                ->get(['id','agent_id','customer_name','type','total_amount','currency','status','created_at']);

            // Action items
            $pendingAccList = AcceptanceRequest::where('status', AcceptanceRequest::STATUS_PENDING)
                ->where('is_preauth', false)->with('agent:id,name')->latest()->limit(5)
                ->get(['id','agent_id','customer_name','total_amount','currency','created_at']);
            $pendingTxnList = Transaction::where('status', Transaction::STATUS_PENDING)
                ->with('agent:id,name')->latest()->limit(5)
                ->get(['id','agent_id','customer_name','type','total_amount','currency','created_at']);

            $dashboardData = [
                'pending_acceptances' => $pendingAcc,   'today_new_acc'      => $todayNewAcc,
                'today_approved_acc'  => $todayApprAcc, 'expiring_soon'      => $expiringSoon,
                'today_txn_count'     => $todayTxnCount,'today_revenue'      => $todayRevenue,
                'today_cost'          => $todayCost,    'today_profit'       => $todayProfit,
                'pending_txn_count'   => $pendingTxnCount,
                'week_txn_count'  => $weekTxnCount, 'week_revenue' => $weekRevenue,
                'week_cost'       => $weekCost,     'week_profit'  => $weekProfit,
                'all_txn_count'   => $allTxnCount,  'all_revenue'  => $allRevenue, 'all_profit' => $allProfit,
                'leaderboard'         => $leaderboard,
                'recent_txns'         => $recentTxns,
                'recent_acceptances'  => $recentAcceptances,
                'pending_acc_list'    => $pendingAccList,
                'pending_txn_list'    => $pendingTxnList,
                'week_label'          => $weekLabel,
            ];
        }

        // =====================================================================
        // SUPERVISOR — team-scoped dashboard
        // =====================================================================
        $supervisorData = null;
        if ($role === User::ROLE_SUPERVISOR) {
            $sup     = User::find($userId);
            $teamIds = $sup ? $sup->getTeamAgentIds() : [];

            if (!empty($teamIds)) {
                // Team acceptance KPIs
                $teamPendingAcc   = AcceptanceRequest::where('status', AcceptanceRequest::STATUS_PENDING)
                                        ->where('is_preauth', false)
                                        ->whereIn('agent_id', $teamIds)->count();
                $teamTodayNewAcc  = AcceptanceRequest::whereBetween('created_at', [$todayStart, $todayEnd])
                                        ->whereIn('agent_id', $teamIds)->count();

                // Team transaction KPIs — today
                $teamTodayBase      = fn() => Transaction::whereBetween('created_at', [$todayStart, $todayEnd])
                                         ->where('status', '!=', Transaction::STATUS_VOIDED)
                                         ->whereIn('agent_id', $teamIds);
                $teamTodayCount     = $teamTodayBase()->count();
                $teamTodayRevenue   = (float) $teamTodayBase()->sum('total_amount');
                $teamPendingReview  = Transaction::where('status', Transaction::STATUS_PENDING)
                                         ->whereIn('agent_id', $teamIds)->count();

                // Pending review transactions (supervisor can approve these)
                $pendingApprovals = Transaction::where('status', Transaction::STATUS_PENDING)
                    ->whereIn('agent_id', $teamIds)->with('agent:id,name')->latest()->limit(8)
                    ->get(['id','agent_id','customer_name','type','total_amount','currency','created_at']);

                // Agents needing attention (late or absent today)
                $teamMembers = User::whereIn('id', $teamIds)
                    ->where('status', User::STATUS_ACTIVE)->get(['id','name']);

                $agentStatuses = [];
                foreach ($teamMembers as $member) {
                    $sess = AttendanceSession::forUser($member->id)->forDate(date('Y-m-d'))
                        ->latest('id')->first();
                    $agentStatuses[] = [
                        'id'       => $member->id,
                        'name'     => $member->name,
                        'status'   => $sess?->status ?? 'absent',
                        'late_min' => $sess?->late_minutes ?? 0,
                        'state'    => $this->attendanceService->getCurrentState($member->id)['state'] ?? 'not_clocked_in',
                    ];
                }

                // Recent team activity
                $teamRecentTxns = Transaction::whereIn('agent_id', $teamIds)
                    ->with('agent:id,name')->latest()->limit(8)
                    ->get(['id','agent_id','customer_name','type','total_amount','currency','status','created_at']);

                $supervisorData = [
                    'team_ids'           => $teamIds,
                    'team_pending_acc'   => $teamPendingAcc,
                    'team_today_new_acc' => $teamTodayNewAcc,
                    'team_today_count'   => $teamTodayCount,
                    'team_today_rev'     => $teamTodayRevenue,
                    'team_pending_rev'   => $teamPendingReview,
                    'pending_approvals'  => $pendingApprovals,
                    'agent_statuses'     => $agentStatuses,
                    'team_recent_txns'   => $teamRecentTxns,
                    'week_label'         => $weekLabel,
                ];
            }
        }

        // =====================================================================
        // AGENT — personal performance dashboard
        // =====================================================================
        $agentData = null;
        if (in_array($role, [User::ROLE_AGENT, User::ROLE_CSA])) {
            $myTodayBase     = fn() => Transaction::whereBetween('created_at', [$todayStart, $todayEnd])
                                   ->where('status', '!=', Transaction::STATUS_VOIDED)
                                   ->where('agent_id', $userId);
            $myTodayCount    = $myTodayBase()->count();
            $myTodayRevenue  = (float) $myTodayBase()->sum('total_amount');
            $myPendingCount  = Transaction::where('status', Transaction::STATUS_PENDING)
                                   ->where('agent_id', $userId)->count();
            $myTodayAccCount = AcceptanceRequest::whereBetween('created_at', [$todayStart, $todayEnd])
                                   ->where('agent_id', $userId)->count();

            $myRecentTxns = Transaction::where('agent_id', $userId)->latest()->limit(5)
                ->get(['id','customer_name','type','total_amount','currency','status','created_at']);

            $agentData = [
                'today_txn_count'    => $myTodayCount,
                'today_revenue'      => $myTodayRevenue,
                'pending_txn_count'  => $myPendingCount,
                'today_acc_count'    => $myTodayAccCount,
                'recent_txns'        => $myRecentTxns,
            ];
        }

        // ── Default currency from system_config ─────────────────────────────
        $currencyRow = DB::table('system_config')->where('key', 'default_currency')->first();
        $currency    = $currencyRow?->value ?? 'CAD';

        ob_start();
        require __DIR__ . '/../Views/dashboard.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }
}
