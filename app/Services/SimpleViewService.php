<?php

namespace App\Services;

use App\Models\AcceptanceRequest;
use App\Models\AttendanceOverride;
use App\Models\AttendanceSession;
use App\Models\ChargebackRefund;
use App\Models\CustomerEmailMessage;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * SimpleViewService — numbers and lists for the simplified admin interface
 * (/simple, see SimpleViewController).
 *
 * Money follows the admin dashboard: every booking that isn't voided counts
 * (approved + waiting for approval), profit = Net MCO (profit_mco minus refund
 * impact). "Today" is the operations shift day (ShiftService::businessDayBounds).
 */
class SimpleViewService
{
    /** Periods offered on the Sales page, in display order. */
    const PERIODS = [
        'today'      => 'Today',
        'yesterday'  => 'Yesterday',
        'this_week'  => 'This week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
    ];

    public function currency(): string
    {
        try {
            return (string) (DB::table('system_config')->where('key', 'default_currency')->value('value') ?: 'CAD');
        } catch (\Throwable $e) {
            return 'CAD';
        }
    }

    // =========================================================================
    // TIME WINDOWS
    // =========================================================================

    /** @return array{0: Carbon, 1: Carbon, 2: string} [start, end, human label] */
    public function window(string $period): array
    {
        [$dayStart, $dayEnd] = ShiftService::businessDayBounds();
        switch ($period) {
            case 'yesterday':
                $s = $dayStart->copy()->subDay();
                $e = $dayEnd->copy()->subDay();
                return [$s, $e, $this->shiftLabel($s)];
            case 'this_week':
                $s = Carbon::now()->startOfWeek();
                return [$s, Carbon::now()->endOfWeek(), $s->format('M j') . ' – ' . Carbon::now()->endOfWeek()->format('M j')];
            case 'this_month':
                return [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), Carbon::now()->format('F Y')];
            case 'last_month':
                $s = Carbon::now()->subMonthNoOverflow()->startOfMonth();
                return [$s, $s->copy()->endOfMonth(), $s->format('F Y')];
            default:
                return [$dayStart, $dayEnd, $this->shiftLabel($dayStart)];
        }
    }

    private function shiftLabel(Carbon $start): string
    {
        return $start->format('D M j, g:i A') . ' → ' . $start->copy()->addDay()->format('D M j, g:i A');
    }

    // =========================================================================
    // SALES
    // =========================================================================

    private function live(Carbon $s, Carbon $e)
    {
        return Transaction::whereBetween('created_at', [$s, $e])->where('status', '!=', Transaction::STATUS_VOIDED);
    }

    /** {bookings, sales, profit, waiting, refunds, refunded} for a window. */
    public function totals(Carbon $s, Carbon $e): array
    {
        $row = $this->live($s, $e)->selectRaw(
            'COUNT(*) AS bookings, COALESCE(SUM(total_amount),0) AS sales,
             COALESCE(SUM(profit_mco - COALESCE(refund_mco_impact,0)),0) AS profit,
             SUM(status = ?) AS waiting', [Transaction::STATUS_PENDING]
        )->first();

        $ref = Transaction::where('refund_status', '!=', 'none')->whereBetween('refunded_at', [$s, $e])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(refunded_amount),0) AS amt')->first();

        return [
            'bookings' => (int) ($row->bookings ?? 0),
            'sales'    => (float) ($row->sales ?? 0),
            'profit'   => (float) ($row->profit ?? 0),
            'waiting'  => (int) ($row->waiting ?? 0),
            'refunds'  => (int) ($ref->n ?? 0),
            'refunded' => (float) ($ref->amt ?? 0),
        ];
    }

    /**
     * One row per agent with sales in the window, best first:
     * {id, name, centre, bookings, sales, profit}
     */
    public function byAgent(Carbon $s, Carbon $e): array
    {
        $rows = $this->live($s, $e)
            ->selectRaw('agent_id, COUNT(*) AS bookings, COALESCE(SUM(total_amount),0) AS sales,
                         COALESCE(SUM(profit_mco - COALESCE(refund_mco_impact,0)),0) AS profit')
            ->groupBy('agent_id')->orderByDesc('profit')->get();

        $names   = User::withTrashed()->whereIn('id', $rows->pluck('agent_id')->filter()->all())->pluck('name', 'id');
        $centres = $this->centreMap();

        return $rows->map(fn($r) => [
            'id'       => (int) $r->agent_id,
            'name'     => $names[$r->agent_id] ?? 'Unknown',
            'centre'   => $centres[(int) $r->agent_id] ?? null,
            'bookings' => (int) $r->bookings,
            'sales'    => (float) $r->sales,
            'profit'   => (float) $r->profit,
        ])->values()->all();
    }

    /** Agent rows summed per centre: [code => {label, color, bookings, sales, profit}] */
    public function byCentre(array $agentRows): array
    {
        $out = [];
        foreach (array_merge(CentreService::ALL, ['UNASSIGNED']) as $c) {
            $out[$c] = [
                'label'    => CentreService::LABELS[$c] ?? 'Not in a centre',
                'color'    => CentreService::COLORS[$c] ?? '#94a3b8',
                'bookings' => 0, 'sales' => 0.0, 'profit' => 0.0,
            ];
        }
        foreach ($agentRows as $r) {
            $c = $r['centre'] ?: 'UNASSIGNED';
            $out[$c]['bookings'] += $r['bookings'];
            $out[$c]['sales']    += $r['sales'];
            $out[$c]['profit']   += $r['profit'];
        }
        if ($out['UNASSIGNED']['bookings'] === 0) {
            unset($out['UNASSIGNED']);
        }
        return $out;
    }

    public function centreMap(): array
    {
        try {
            return (new CentreService())->resolveMap();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // TEAM
    // =========================================================================

    /**
     * Who is working right now, from the attendance live board:
     * {in: [{id,name,since,late}], on_break: [{id,name,since}], finished: [...], not_in: [...], asking: [...]}
     */
    public function teamNow(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $b = (new AttendanceService())->getLiveBoardData();
        $fmt = fn($t) => $t ? Carbon::parse($t)->format('g:i A') : '';

        return $cache = [
            'in' => array_map(fn($x) => [
                'id' => $x['agent']->id, 'name' => $x['agent']->name,
                'since' => $fmt($x['session']->clock_in ?? null),
                'late'  => (int) ($x['session']->late_minutes ?? 0),
            ], $b['in'] ?? []),
            'on_break' => array_map(fn($x) => [
                'id' => $x['agent']->id, 'name' => $x['agent']->name,
                'since' => $fmt($x['break']->break_start ?? $x['break']->start_time ?? null),
            ], $b['on_break'] ?? []),
            'finished' => array_map(fn($x) => [
                'id' => $x['agent']->id, 'name' => $x['agent']->name,
                'since' => $fmt($x['session']->clock_out ?? null),
            ], $b['completed'] ?? []),
            'not_in' => array_map(fn($u) => ['id' => $u->id, 'name' => $u->name], $b['absent'] ?? []),
            'asking' => array_map(fn($u) => ['id' => $u->id, 'name' => $u->name], $b['pending_override'] ?? []),
        ];
    }

    /** Status word for one person from teamNow(): in | on_break | finished | asking | not_in */
    public function statusMap(): array
    {
        $map = [];
        foreach ($this->teamNow() as $state => $people) {
            foreach ($people as $p) {
                $map[$p['id']] = $state;
            }
        }
        return $map;
    }

    /** Everyone on the team (not admins), grouped by centre for the Team page. */
    public function people(): array
    {
        $users = User::whereNull('deleted_at')
            ->whereIn('role', [User::ROLE_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_AGENT, User::ROLE_CSA])
            ->with('reportsTo:id,name')
            ->orderBy('name')->get();

        [$ms, $me] = $this->window('this_month');
        $month = collect($this->byAgent($ms, $me))->keyBy('id');
        $status  = $this->statusMap();
        $centres = $this->centreMap();

        $out = [];
        foreach ($users as $u) {
            $m = $month[$u->id] ?? null;
            $out[] = [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'role'      => $u->role,
                'job'       => self::jobTitle($u->role),
                'active'    => $u->status === User::STATUS_ACTIVE,
                'reports'   => $u->reportsTo->name ?? null,
                'centre'    => $centres[$u->id] ?? null,
                'now'       => $status[$u->id] ?? 'not_in',
                'bookings'  => $m['bookings'] ?? 0,
                'profit'    => $m['profit'] ?? 0.0,
            ];
        }
        return $out;
    }

    public static function jobTitle(string $role): string
    {
        return [
            User::ROLE_MANAGER    => 'Manager',
            User::ROLE_SUPERVISOR => 'Team leader',
            User::ROLE_AGENT      => 'Travel agent',
            User::ROLE_CSA        => 'Customer support',
            User::ROLE_ADMIN      => 'Admin',
        ][$role] ?? ucfirst($role);
    }

    /** Managers and team leaders an agent can report to (for Add a person). */
    public function leaders(): array
    {
        return User::whereNull('deleted_at')->where('status', User::STATUS_ACTIVE)
            ->whereIn('role', [User::ROLE_MANAGER, User::ROLE_SUPERVISOR])
            ->orderByRaw("FIELD(role, 'manager', 'supervisor')")->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'job' => self::jobTitle($u->role)])
            ->all();
    }

    // =========================================================================
    // APPROVALS
    // =========================================================================

    /** Everything waiting on an admin, as lists for the Approvals page. */
    public function approvals(): array
    {
        $bookings = Transaction::with('agent:id,name')->where('status', Transaction::STATUS_PENDING)
            ->orderBy('created_at')->get();

        $emails = CustomerEmailMessage::with(['thread', 'author:id,name'])->pendingApproval()
            ->orderBy('created_at')->get();

        $invoices = Invoice::with('creator:id,name')->pendingApproval()->orderBy('created_at')->get();

        try {
            $shifts = (new ShiftService())->getPendingApprovals();
        } catch (\Throwable $e) {
            $shifts = [];
        }

        return [
            'bookings' => $bookings,
            'emails'   => $emails,
            'invoices' => $invoices,
            'clockins' => $this->teamNow()['asking'],
            'shifts'   => $shifts,
        ];
    }

    /** Counts per kind + total, for the sidebar badge and Home. Never throws. */
    public function pendingCounts(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $c = ['bookings' => 0, 'emails' => 0, 'invoices' => 0, 'clockins' => 0, 'shifts' => 0];
        $try = function (string $k, callable $fn) use (&$c) {
            try { $c[$k] = (int) $fn(); } catch (\Throwable $e) { /* leave 0 */ }
        };
        $try('bookings', fn() => Transaction::where('status', Transaction::STATUS_PENDING)->count());
        $try('emails',   fn() => CustomerEmailMessage::pendingApproval()->count());
        $try('invoices', fn() => Invoice::pendingApproval()->count());
        $try('clockins', fn() => $this->clockinRequestCount());
        $try('shifts',   fn() => count((new ShiftService())->getPendingApprovals()));
        $c['total'] = array_sum($c);

        return $cache = $c;
    }

    /**
     * How many people are waiting for a late clock-in decision — the same rule
     * as AttendanceService::getLiveBoardData()'s pending_override bucket, in
     * three queries instead of two per team member (this runs on every page
     * via the sidebar badge).
     */
    public function clockinRequestCount(): int
    {
        $today = date('Y-m-d');
        $blocked = DB::table('activity_log')->where('action', 'clock_in_blocked')
            ->where('created_at', '>=', $today . ' 00:00:00')->distinct()->pluck('user_id')->all();
        if (!$blocked) return 0;

        $eligible = User::whereIn('id', $blocked)
            ->whereIn('role', [User::ROLE_AGENT, User::ROLE_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_CSA])
            ->where('status', User::STATUS_ACTIVE)->whereNull('deleted_at')->pluck('id')->all();
        if (!$eligible) return 0;

        $hasSession = AttendanceSession::whereIn('user_id', $eligible)
            ->where(fn($q) => $q->where('date', $today)->orWhere('status', AttendanceSession::STATUS_ACTIVE))
            ->distinct()->pluck('user_id')->all();
        $hasOverride = AttendanceOverride::whereIn('agent_id', $eligible)->where('shift_date', $today)
            ->distinct()->pluck('agent_id')->all();

        return count(array_diff($eligible, $hasSession, $hasOverride));
    }

    // =========================================================================
    // NEEDS ATTENTION (Home)
    // =========================================================================

    /** Short list of things worth a look: [{icon, tone, title, detail, href}] */
    public function attention(): array
    {
        $cur  = $this->currency();
        $out  = [];

        try {
            $disputes = Transaction::whereNotNull('dispute_status')->where('dispute_status', '!=', 'resolved')
                ->orderByDesc('dispute_flagged_at')->limit(5)->get(['id', 'customer_name', 'pnr', 'dispute_status', 'total_amount', 'currency']);
            foreach ($disputes as $t) {
                $out[] = [
                    'icon' => 'gavel', 'tone' => 'rose',
                    'title' => 'Open dispute — ' . $t->customer_name,
                    'detail' => 'PNR ' . $t->pnr . ' · ' . ($t->currency ?: $cur) . ' ' . number_format((float) $t->total_amount, 2)
                        . ' · ' . ucwords(str_replace('_', ' ', (string) $t->dispute_status)),
                    'href' => '/transactions/' . $t->id,
                ];
            }
        } catch (\Throwable $e) {}

        try {
            $refunds = Transaction::where('refund_status', '!=', 'none')->where('refunded_at', '>=', Carbon::now()->subDays(7))
                ->orderByDesc('refunded_at')->limit(5)->get(['id', 'customer_name', 'pnr', 'refunded_amount', 'currency', 'refunded_at']);
            foreach ($refunds as $t) {
                $out[] = [
                    'icon' => 'undo', 'tone' => 'amber',
                    'title' => 'Refund given — ' . $t->customer_name,
                    'detail' => ($t->currency ?: $cur) . ' ' . number_format((float) $t->refunded_amount, 2) . ' · PNR ' . $t->pnr
                        . ' · ' . Carbon::parse($t->refunded_at)->diffForHumans(),
                    'href' => '/transactions/' . $t->id,
                ];
            }
        } catch (\Throwable $e) {}

        try {
            $cb = ChargebackRefund::where('outcome', 'pending')->orderByDesc('event_date')->limit(5)->get();
            foreach ($cb as $r) {
                $out[] = [
                    'icon' => 'credit_card_off', 'tone' => 'rose',
                    'title' => ($r->kind === 'chargeback' ? 'Chargeback' : 'Refund') . ' still open — ' . ($r->customer_name ?: 'PNR ' . $r->pnr),
                    'detail' => $r->centre . ' · ' . ($r->currency ?: $cur) . ' ' . number_format((float) $r->amount, 2)
                        . ' · since ' . Carbon::parse($r->event_date)->format('M j'),
                    'href' => '/chargebacks',
                ];
            }
        } catch (\Throwable $e) {}

        try {
            // expires_at, not created_at: a resend gives the link a fresh 12 hours
            $expiring = AcceptanceRequest::where('status', AcceptanceRequest::STATUS_PENDING)->where('is_preauth', false)
                ->whereBetween('expires_at', [Carbon::now(), Carbon::now()->addHours(4)])->count();
            if ($expiring > 0) {
                $out[] = [
                    'icon' => 'timer', 'tone' => 'slate',
                    'title' => $expiring . ' customer authorization' . ($expiring > 1 ? 's' : '') . ' about to expire',
                    'detail' => 'Sent to the customer but not signed yet; the link stops working within the next 4 hours.',
                    'href' => '/acceptance?status=PENDING',
                ];
            }
        } catch (\Throwable $e) {}

        return $out;
    }
}
