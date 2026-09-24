<?php

namespace App\Controllers;

use App\Models\CallLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CallLogService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * CallLogController — agent call logs.
 *
 *   GET  /call-logs                    agent's own page (quick log + today)
 *   POST /call-logs                    create (AJAX; also used by the top-bar quick log)
 *   POST /call-logs/{id}/update        edit own log, same shift day only
 *   POST /call-logs/{id}/follow-up-done
 *   POST /call-logs/{id}/delete        admin only
 *   GET  /api/call-logs/lookup         repeat-caller hint while typing a number
 *   GET  /call-logs/team               live board + log list (admin/manager/supervisor)
 *   GET  /call-logs/export             CSV (admin/manager)
 *   POST /call-logs/settings           compliance thresholds (admin)
 */
class CallLogController
{
    private CallLogService $service;

    public function __construct()
    {
        $this->service = new CallLogService();
    }

    // =========================================================================
    // AGENT PAGE — GET /call-logs
    // =========================================================================

    public function myPage(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $today  = $this->service->shiftDateFor();

        $q    = $request->getQueryParams();
        $date = $this->validDate($q['date'] ?? '') ?? $today;
        if ($date > $today) {
            $date = $today;
        }

        $logs = CallLog::with('transaction:id,pnr')
            ->where('agent_id', $userId)->where('shift_date', $date)
            ->orderByDesc('created_at')->get();

        $followUps = CallLog::where('agent_id', $userId)
            ->where('follow_up_done', 0)->whereNotNull('follow_up_at')
            ->orderBy('follow_up_at')->limit(30)->get();

        // Last 7 working shift days, oldest first, for the mini chart.
        $days = [];
        $d = $today;
        while (count($days) < 7) {
            if (!$this->service->isOffDay($d)) {
                $days[] = $d;
            }
            $d = Carbon::parse($d)->subDay()->toDateString();
        }
        $days = array_reverse($days);
        $counts = CallLog::where('agent_id', $userId)->whereIn('shift_date', $days)
            ->selectRaw('shift_date, COUNT(*) AS n')->groupBy('shift_date')
            ->pluck('n', 'shift_date')->toArray();
        $week = [];
        foreach ($days as $day) {
            $week[] = ['date' => $day, 'n' => (int) ($counts[$day] ?? 0)];
        }

        return $this->render($response, 'call_logs/my.php', [
            'stats'       => $this->service->agentToday($userId),
            'logs'        => $logs->map(fn ($l) => $this->present($l))->values()->toArray(),
            'followUps'   => $followUps->map(fn ($l) => $this->present($l))->values()->toArray(),
            'week'        => $week,
            'viewDate'    => $date,
            'today'       => $today,
            'isOffDay'    => $this->service->isOffDay($today),
            'favAirlines' => $this->service->favouriteAirlines($userId),
            'bookings'    => $this->bookingsForLinking($userId),
            'csrf'        => $_SESSION['csrf_token'] ?? '',
        ]);
    }

    // =========================================================================
    // CREATE — POST /call-logs  (AJAX)
    // =========================================================================

    public function store(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $parsed = $this->validate((array) $request->getParsedBody(), $userId);
        if (isset($parsed['error'])) {
            return $this->json($response, ['success' => false, 'error' => $parsed['error'], 'field' => $parsed['field'] ?? null], 422);
        }

        $log = CallLog::create($parsed + [
            'agent_id'   => $userId,
            'shift_date' => $this->service->shiftDateFor(),
        ]);

        return $this->json($response, [
            'success' => true,
            'log'     => $this->present($log->fresh('transaction')),
            'stats'   => $this->service->agentToday($userId),
        ]);
    }

    // =========================================================================
    // UPDATE — POST /call-logs/{id}/update  (AJAX)
    // =========================================================================

    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $log = CallLog::find((int) $args['id']);
        if (!$log || $log->agent_id !== $userId) {
            return $this->json($response, ['success' => false, 'error' => 'Call log not found.'], 404);
        }
        if (substr((string) $log->shift_date, 0, 10) !== $this->service->shiftDateFor()) {
            return $this->json($response, ['success' => false, 'error' => 'Only calls from the current shift can be edited.'], 422);
        }

        $parsed = $this->validate((array) $request->getParsedBody(), $userId);
        if (isset($parsed['error'])) {
            return $this->json($response, ['success' => false, 'error' => $parsed['error'], 'field' => $parsed['field'] ?? null], 422);
        }
        // A changed call-back time should ring again.
        $newFollow = $parsed['follow_up_at'];
        $oldFollow = $log->follow_up_at ? $log->follow_up_at->format('Y-m-d H:i:s') : null;
        if ($newFollow !== $oldFollow) {
            $parsed['follow_up_notified_at'] = null;
            $parsed['follow_up_done'] = 0;
        }
        $log->update($parsed);

        return $this->json($response, [
            'success' => true,
            'log'     => $this->present($log->fresh('transaction')),
            'stats'   => $this->service->agentToday($userId),
        ]);
    }

    // =========================================================================
    // FOLLOW-UP DONE — POST /call-logs/{id}/follow-up-done  (AJAX)
    // =========================================================================

    public function followUpDone(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $log = CallLog::find((int) $args['id']);
        if (!$log || $log->agent_id !== $userId) {
            return $this->json($response, ['success' => false, 'error' => 'Call log not found.'], 404);
        }
        $log->update(['follow_up_done' => 1]);
        return $this->json($response, ['success' => true, 'stats' => $this->service->agentToday($userId)]);
    }

    // =========================================================================
    // DELETE — POST /call-logs/{id}/delete  (admin only)
    // =========================================================================

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $this->json($response, ['success' => false, 'error' => 'Only admins can delete call logs.'], 403);
        }
        $log = CallLog::find((int) $args['id']);
        if (!$log) {
            return $this->json($response, ['success' => false, 'error' => 'Call log not found.'], 404);
        }
        $this->activity('call_log_deleted', $log->id, [
            'agent_id' => $log->agent_id, 'shift_date' => (string) $log->shift_date,
            'phone' => $log->phone, 'reason' => $log->reason, 'outcome' => $log->outcome,
        ]);
        $log->delete();
        return $this->json($response, ['success' => true]);
    }

    // =========================================================================
    // REPEAT-CALLER LOOKUP — GET /api/call-logs/lookup?phone=
    // =========================================================================

    public function lookup(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $digits = CallLog::normalisePhone((string) ($request->getQueryParams()['phone'] ?? ''));
        if (strlen($digits) < 7) {
            return $this->json($response, ['success' => true, 'found' => false]);
        }

        $mine = CallLog::where('agent_id', $userId)->where('phone_digits', $digits)
            ->orderByDesc('created_at')->limit(4)->get();
        $others = CallLog::where('agent_id', '!=', $userId)->where('phone_digits', $digits)
            ->where('created_at', '>=', Carbon::now()->subDays(30)->format('Y-m-d H:i:s'))
            ->count();

        // The agent's own bookings for this number (phones are stored free-form,
        // so compare on the trailing digits).
        $bookings = Transaction::where('agent_id', $userId)
            ->whereNotNull('customer_phone')
            ->where('customer_phone', 'LIKE', '%' . substr($digits, -4))
            ->orderByDesc('created_at')->limit(20)
            ->get(['id', 'pnr', 'airline', 'customer_name', 'customer_phone', 'created_at'])
            ->filter(fn ($t) => CallLog::normalisePhone((string) $t->customer_phone) === $digits)
            ->take(3)->values();

        $name = optional($mine->firstWhere('customer_name', '!=', null))->customer_name
            ?? optional($bookings->first())->customer_name;

        return $this->json($response, [
            'success'  => true,
            'found'    => $mine->isNotEmpty() || $others > 0 || $bookings->isNotEmpty(),
            'name'     => $name,
            'calls'    => $mine->map(fn ($l) => [
                'ago'     => $l->created_at->diffForHumans(),
                'reason'  => $l->reasonLabel(),
                'airline' => $l->airline,
                'outcome' => $l->outcomeLabel(),
            ])->toArray(),
            'others'   => $others,
            'bookings' => $bookings->map(fn ($t) => [
                'id'      => $t->id,
                'pnr'     => $t->pnr ?: ('#' . $t->id),
                'airline' => $t->airline,
                'date'    => Carbon::parse($t->created_at)->format('M j'),
            ])->toArray(),
        ]);
    }

    // =========================================================================
    // TEAM PAGE — GET /call-logs/team
    // =========================================================================

    public function teamPage(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!$this->isLead()) {
            return $response->withHeader('Location', '/call-logs')->withStatus(302);
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $scope  = $this->service->visibleUserIds($userId, $role);
        $today  = $this->service->shiftDateFor();

        $q    = $request->getQueryParams();
        $date = $this->validDate($q['date'] ?? '') ?? $today;
        if ($date > $today) {
            $date = $today;
        }

        $agents = $this->service->loggers($scope);
        $board  = $this->service->board($date, $agents);
        $order  = ['no_logs' => 0, 'quiet' => 1, 'warming_up' => 2, 'logging' => 3, 'not_in' => 4, 'off_day' => 5];
        uasort($board, fn ($a, $b) => [$order[$a['status']] ?? 9, -$a['calls'], $a['name']]
                                   <=> [$order[$b['status']] ?? 9, -$b['calls'], $b['name']]);

        // Log list for the chosen day (with filters).
        $filters = [
            'agent'   => (int) ($q['agent'] ?? 0),
            'reason'  => array_key_exists($q['reason'] ?? '', CallLog::REASONS) ? $q['reason'] : '',
            'outcome' => array_key_exists($q['outcome'] ?? '', CallLog::OUTCOMES) ? $q['outcome'] : '',
            'phone'   => trim((string) ($q['phone'] ?? '')),
        ];
        $logs = $this->filteredQuery($scope, $date, $date, $filters)
            ->with(['agent:id,name', 'transaction:id,pnr'])
            ->orderByDesc('created_at')->limit(300)->get();

        // Breakdown for the day (unfiltered, whole scope).
        $dayQuery = fn () => CallLog::where('shift_date', $date)
            ->when($scope !== null, fn ($qq) => $qq->whereIn('agent_id', $scope));
        $byReason  = $dayQuery()->selectRaw('reason, COUNT(*) AS n')->groupBy('reason')->orderByDesc('n')->pluck('n', 'reason')->toArray();
        $byOutcome = $dayQuery()->selectRaw('outcome, COUNT(*) AS n')->groupBy('outcome')->orderByDesc('n')->pluck('n', 'outcome')->toArray();
        $byAirline = $dayQuery()->selectRaw('airline, COUNT(*) AS n')->groupBy('airline')->orderByDesc('n')->limit(8)->pluck('n', 'airline')->toArray();

        $settings = [];
        if ($role === User::ROLE_ADMIN) {
            foreach (array_keys(CallLogService::DEFAULTS) as $k) {
                $settings[$k] = $this->service->config($k);
            }
        }

        return $this->render($response, 'call_logs/team.php', [
            'board'     => $board,
            'logs'      => $logs,
            'agents'    => $agents,
            'filters'   => $filters,
            'viewDate'  => $date,
            'today'     => $today,
            'isOffDay'  => $this->service->isOffDay($date),
            'byReason'  => $byReason,
            'byOutcome' => $byOutcome,
            'byAirline' => $byAirline,
            'settings'  => $settings,
            'userRole'  => $role,
            'canExport' => in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER], true),
            'svc'       => $this->service,
            'csrf'      => $_SESSION['csrf_token'] ?? '',
        ]);
    }

    // =========================================================================
    // CSV EXPORT — GET /call-logs/export?from=&to=  (admin/manager)
    // =========================================================================

    public function export(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER], true)) {
            return $response->withHeader('Location', '/call-logs')->withStatus(302);
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $scope  = $this->service->visibleUserIds($userId, $role);
        $q      = $request->getQueryParams();
        $today  = $this->service->shiftDateFor();
        $from   = $this->validDate($q['from'] ?? '') ?? $today;
        $to     = $this->validDate($q['to'] ?? '') ?? $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92) {
            $from = Carbon::parse($to)->subDays(92)->toDateString();
        }
        $filters = [
            'agent'   => (int) ($q['agent'] ?? 0),
            'reason'  => array_key_exists($q['reason'] ?? '', CallLog::REASONS) ? $q['reason'] : '',
            'outcome' => array_key_exists($q['outcome'] ?? '', CallLog::OUTCOMES) ? $q['outcome'] : '',
            'phone'   => trim((string) ($q['phone'] ?? '')),
        ];

        $rows = $this->filteredQuery($scope, $from, $to, $filters)
            ->with(['agent:id,name', 'transaction:id,pnr'])
            ->orderBy('created_at')->limit(20000)->get();

        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['Logged at', 'Shift day', 'Agent', 'Direction', 'Phone', 'Customer', 'Reason', 'Airline', 'Outcome', 'Booking', 'Call back at', 'Call back done', 'Notes']);
        foreach ($rows as $l) {
            fputcsv($fh, array_map([$this, 'csvSafe'], [
                $l->created_at->format('Y-m-d H:i'),
                substr((string) $l->shift_date, 0, 10),
                $l->agent->name ?? ('#' . $l->agent_id),
                $l->direction,
                $l->phone,
                $l->customer_name,
                $l->reasonLabel(),
                $l->airline,
                $l->outcomeLabel(),
                $l->transaction->pnr ?? ($l->transaction_id ? '#' . $l->transaction_id : ''),
                $l->follow_up_at ? $l->follow_up_at->format('Y-m-d H:i') : '',
                $l->follow_up_at ? ($l->follow_up_done ? 'yes' : 'no') : '',
                $l->notes,
            ]));
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $this->activity('call_logs_exported', null, ['from' => $from, 'to' => $to, 'rows' => $rows->count()]);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="call-logs-' . $from . '_to_' . $to . '.csv"');
    }

    // =========================================================================
    // SETTINGS — POST /call-logs/settings  (admin)
    // =========================================================================

    public function saveSettings(Request $request, Response $response): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $this->json($response, ['success' => false, 'error' => 'Admins only.'], 403);
        }
        $b = (array) $request->getParsedBody();
        $off = array_values(array_unique(array_map('intval', array_filter((array) ($b['off_days'] ?? []), 'is_numeric'))));
        $off = array_values(array_filter($off, fn ($d) => $d >= 0 && $d <= 6));
        $values = [
            'call_log_off_days'      => implode(',', $off),
            'call_log_grace_hours'   => max(1, min(12, (int) ($b['grace_hours'] ?? 2))),
            'call_log_quiet_hours'   => max(1, min(12, (int) ($b['quiet_hours'] ?? 3))),
            'call_log_realert_hours' => max(1, min(24, (int) ($b['realert_hours'] ?? 3))),
            'call_log_summary_hour'  => max(0, min(23, (int) ($b['summary_hour'] ?? 10))),
        ];
        // An empty off-day list must be stored as something non-empty, or the
        // default (Sat/Sun) would silently come back.
        if ($values['call_log_off_days'] === '') {
            $values['call_log_off_days'] = 'none';
        }
        $this->service->saveConfig($values, (int) $_SESSION['user_id']);
        $this->activity('call_log_settings_saved', null, $values);
        return $this->json($response, ['success' => true]);
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /** Validate + normalise a create/update payload. */
    private function validate(array $b, int $userId): array
    {
        $phone = trim((string) ($b['phone'] ?? ''));
        $digits = CallLog::normalisePhone($phone);
        if (strlen($digits) < 7) {
            return ['error' => 'Enter the customer\'s phone number.', 'field' => 'phone'];
        }
        $reason = (string) ($b['reason'] ?? '');
        if (!array_key_exists($reason, CallLog::REASONS)) {
            return ['error' => 'Pick the reason for the call.', 'field' => 'reason'];
        }
        $airline = trim(preg_replace('/\s+/', ' ', (string) ($b['airline'] ?? '')));
        if ($airline === '') {
            return ['error' => 'Pick the airline (or "Not decided").', 'field' => 'airline'];
        }
        $outcome = (string) ($b['outcome'] ?? '');
        if (!array_key_exists($outcome, CallLog::OUTCOMES)) {
            return ['error' => 'How did the call end? Pick an outcome.', 'field' => 'outcome'];
        }

        $followUpAt = null;
        if ($outcome === 'follow_up') {
            $raw = trim((string) ($b['follow_up_at'] ?? ''));
            $ts  = $raw !== '' ? strtotime($raw) : false;
            if (!$ts) {
                return ['error' => 'When should we remind you to call back?', 'field' => 'follow_up_at'];
            }
            if ($ts < time() - 300 || $ts > time() + 30 * 86400) {
                return ['error' => 'Call-back time must be within the next 30 days.', 'field' => 'follow_up_at'];
            }
            $followUpAt = date('Y-m-d H:i:s', $ts);
        }

        $txnId = (int) ($b['transaction_id'] ?? 0) ?: null;
        if ($txnId !== null) {
            $owner = Transaction::where('id', $txnId)->value('agent_id');
            if ((int) $owner !== $userId) {
                return ['error' => 'You can only link your own bookings.', 'field' => 'transaction_id'];
            }
        }

        $name  = trim((string) ($b['customer_name'] ?? ''));
        $notes = trim((string) ($b['notes'] ?? ''));

        return [
            'direction'      => ($b['direction'] ?? '') === CallLog::DIRECTION_OUT ? CallLog::DIRECTION_OUT : CallLog::DIRECTION_IN,
            'phone'          => mb_substr($phone, 0, 32),
            'phone_digits'   => $digits,
            'customer_name'  => $name !== '' ? mb_substr($name, 0, 120) : null,
            'reason'         => $reason,
            'airline'        => mb_substr($airline, 0, 80),
            'outcome'        => $outcome,
            'transaction_id' => $txnId,
            'follow_up_at'   => $followUpAt,
            'notes'          => $notes !== '' ? mb_substr($notes, 0, 2000) : null,
        ];
    }

    private function filteredQuery(?array $scope, string $from, string $to, array $f)
    {
        return CallLog::whereBetween('shift_date', [$from, $to])
            ->when($scope !== null, fn ($q) => $q->whereIn('agent_id', $scope))
            ->when($f['agent'] > 0, fn ($q) => $q->where('agent_id', $f['agent']))
            ->when($f['reason'] !== '', fn ($q) => $q->where('reason', $f['reason']))
            ->when($f['outcome'] !== '', fn ($q) => $q->where('outcome', $f['outcome']))
            ->when($f['phone'] !== '', function ($q) use ($f) {
                $d = CallLog::normalisePhone($f['phone']);
                $d !== '' ? $q->where('phone_digits', 'LIKE', '%' . $d . '%') : $q->whereRaw('1=0');
            });
    }

    /** Agent's bookings from the last ~2 days, to link a "Booked" call. */
    private function bookingsForLinking(int $userId): array
    {
        return Transaction::where('agent_id', $userId)
            ->where('created_at', '>=', Carbon::now()->subHours(36)->format('Y-m-d H:i:s'))
            ->orderByDesc('created_at')->limit(25)
            ->get(['id', 'pnr', 'customer_name', 'customer_phone', 'airline'])
            ->map(fn ($t) => [
                'id'     => $t->id,
                'label'  => ($t->pnr ?: '#' . $t->id) . ' — ' . $t->customer_name . ($t->airline ? ' · ' . $t->airline : ''),
                'digits' => CallLog::normalisePhone((string) $t->customer_phone),
            ])->toArray();
    }

    /** Shape a log for the JSON response (rendered as a row in JS). */
    private function present(CallLog $l): array
    {
        return [
            'id'            => $l->id,
            'time'          => $l->created_at->format('g:i A'),
            'direction'     => $l->direction,
            'phone'         => $l->phone,
            'customer_name' => $l->customer_name,
            'reason'        => $l->reason,
            'reason_label'  => $l->reasonLabel(),
            'reason_icon'   => CallLog::REASONS[$l->reason][1] ?? 'call',
            'airline'       => $l->airline,
            'outcome'       => $l->outcome,
            'outcome_label' => $l->outcomeLabel(),
            'outcome_colour'=> $l->outcomeColour(),
            'transaction_id'=> $l->transaction_id,
            'pnr'           => $l->transaction->pnr ?? null,
            'follow_up_at'  => $l->follow_up_at ? $l->follow_up_at->format('Y-m-d\TH:i') : null,
            'follow_up_label' => $l->follow_up_at ? $l->follow_up_at->format('D g:i A') : null,
            'follow_up_ts'  => $l->follow_up_at ? $l->follow_up_at->getTimestamp() * 1000 : null,
            'follow_up_done'=> (bool) $l->follow_up_done,
            'notes'         => $l->notes,
        ];
    }

    private function isLead(): bool
    {
        return in_array($_SESSION['role'] ?? '', [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR], true);
    }

    private function validDate(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
    }

    /** Neutralise spreadsheet formula injection in exported cells. */
    private function csvSafe($v): string
    {
        $v = (string) ($v ?? '');
        return ($v !== '' && strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
    }

    private function activity(string $action, ?int $entityId, array $details): void
    {
        try {
            DB::table('activity_log')->insert([
                'user_id'     => (int) ($_SESSION['user_id'] ?? 0) ?: null,
                'action'      => $action,
                'entity_type' => 'call_logs',
                'entity_id'   => $entityId,
                'details'     => json_encode($details),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[CallLogController] activity_log insert failed: ' . $e->getMessage());
        }
    }

    private function render(Response $response, string $view, array $data = []): Response
    {
        extract($data);
        ob_start();
        require __DIR__ . '/../Views/' . $view;
        $response->getBody()->write(ob_get_clean());
        return $response;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
