<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\Transaction;
use App\Models\AcceptanceRequest;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

class LiveBoardController
{
    // =========================================================================
    // PIN HELPERS
    // =========================================================================

    private function getPin(): string
    {
        return DB::table('system_config')->where('key', 'tv_pin')->value('value') ?? '1234';
    }

    private function isPinVerified(): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return ($_SESSION['tv_pin_verified'] ?? false) === true;
    }

    // =========================================================================
    // PAGE  —  GET /tv
    // =========================================================================

    public function page(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $pinVerified = $this->isPinVerified();
        $pinError    = $_SESSION['tv_pin_error'] ?? null;
        unset($_SESSION['tv_pin_error']);

        ob_start();
        require __DIR__ . '/../Views/liveboard/tv.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    // =========================================================================
    // AUTH  —  POST /tv/auth
    // =========================================================================

    public function auth(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $body    = (array)$request->getParsedBody();
        $entered = trim($body['pin'] ?? '');
        $correct = $this->getPin();

        if ($entered === $correct) {
            $_SESSION['tv_pin_verified'] = true;
        } else {
            $_SESSION['tv_pin_error'] = 'Incorrect PIN. Please try again.';
        }

        return $response->withHeader('Location', '/liveboard/score')->withStatus(302);
    }

    // =========================================================================
    // FEED  —  GET /api/tv-feed   (JSON, PIN-session-gated)
    // =========================================================================

    public function feed(Request $request, Response $response): Response
    {
        if (!$this->isPinVerified()) {
            $response->getBody()->write(json_encode(['error' => 'unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $now = Carbon::now();
        if ($now->hour >= 18) {
            $shiftStart = $now->copy()->startOfDay()->addHours(18); // Today 6 PM
            $shiftEnd   = $now->copy()->addDay()->startOfDay()->addHours(18)->subSecond(); // Tomorrow 5:59:59 PM
        } else {
            $shiftStart = $now->copy()->subDay()->startOfDay()->addHours(18); // Yesterday 6 PM
            $shiftEnd   = $now->copy()->startOfDay()->addHours(18)->subSecond(); // Today 5:59:59 PM
        }

        // DB strings need to be in UTC because Hostinger MySQL runs in UTC by default
        $shiftStartDb = $shiftStart->copy()->setTimezone('UTC');
        $shiftEndDb   = $shiftEnd->copy()->setTimezone('UTC');

        // ── Leaderboard: approved transactions today ──────────────────────────
        $txnRows = Transaction::whereBetween('created_at', [$shiftStartDb, $shiftEndDb])
            ->where('status', Transaction::STATUS_APPROVED)
            ->selectRaw('agent_id, COUNT(*) as txn_count, SUM(profit_mco) as profit, MAX(currency) as currency')
            ->groupBy('agent_id')
            ->with('agent:id,name')
            ->get()
            ->keyBy('agent_id');

        // Approved acceptances today per agent
        $accRows = AcceptanceRequest::whereBetween('approved_at', [$shiftStartDb, $shiftEndDb])
            ->where('status', 'APPROVED')
            ->where('is_preauth', false)
            ->selectRaw('agent_id, COUNT(*) as acc_count')
            ->groupBy('agent_id')
            ->with('agent:id,name')
            ->get()
            ->keyBy('agent_id');

        // Merge all unique agent IDs
        $allAgentIds = $txnRows->keys()->merge($accRows->keys())->unique();

        $leaderboard = $allAgentIds->map(function ($agentId) use ($txnRows, $accRows) {
            $tRow = $txnRows[$agentId] ?? null;
            $aRow = $accRows[$agentId] ?? null;
            
            // Get name from either relation
            $agentName = ($tRow->agent->name ?? ($aRow->agent->name ?? 'Agent'));
            
            $parts   = explode(' ', trim($agentName));
            $display = $parts[0] . (isset($parts[1]) ? ' ' . strtoupper($parts[1][0]) . '.' : '');
            
            return [
                'full_name' => $agentName,
                'display'   => $display,
                'txn_count' => (int) ($tRow->txn_count ?? 0),
                'acc_count' => (int) ($aRow->acc_count ?? 0),
                'profit'    => (int) round((float) ($tRow->profit ?? 0)),
                'currency'  => $tRow->currency ?? ($aRow->currency ?? 'USD'),
            ];
        })->sortByDesc('profit')->values();

        // ── Recent events feed ────────────────────────────────────────────────
        $recentTxns = Transaction::whereBetween('created_at', [$shiftStartDb, $shiftEndDb])
            ->where('status', Transaction::STATUS_APPROVED)
            ->with('agent:id,name')
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get(['id', 'agent_id', 'type', 'profit_mco', 'currency', 'updated_at']);

        $recentAccs = AcceptanceRequest::whereBetween('approved_at', [$shiftStartDb, $shiftEndDb])
            ->where('status', 'APPROVED')
            ->where('is_preauth', false)
            ->with('agent:id,name')
            ->orderByDesc('approved_at')
            ->limit(12)
            ->get(['id', 'agent_id', 'type', 'currency', 'approved_at']);

        $events = collect();

        foreach ($recentTxns as $t) {
            $events->push([
                'id'         => 'txn_' . $t->id,
                'agent_name' => $t->agent->name ?? 'Agent',
                'kind'       => 'transaction',
                'label'      => $this->typeLabel($t->type),
                'profit'     => (int) round((float) $t->profit_mco),
                'currency'   => $t->currency ?? 'USD',
                // convert UTC back to IST for frontend display
                'time'       => $t->updated_at ? Carbon::parse($t->updated_at, 'UTC')->setTimezone('Asia/Kolkata')->toIso8601String() : null,
            ]);
        }

        foreach ($recentAccs as $a) {
            $events->push([
                'id'         => 'acc_' . $a->id,
                'agent_name' => $a->agent->name ?? 'Agent',
                'kind'       => 'acceptance',
                'label'      => $this->typeLabel($a->type),
                'profit'     => null,
                'currency'   => $a->currency ?? 'USD',
                'time'       => $a->approved_at ? Carbon::parse($a->approved_at, 'UTC')->setTimezone('Asia/Kolkata')->toIso8601String() : null,
            ]);
        }

        $events = $events->sortByDesc('time')->take(15)->values();

        // ── Summary totals ────────────────────────────────────────────────────
        $totalTxns   = Transaction::whereBetween('created_at', [$shiftStartDb, $shiftEndDb])
            ->where('status', Transaction::STATUS_APPROVED)->count();
        $totalAccs   = AcceptanceRequest::whereBetween('approved_at', [$shiftStartDb, $shiftEndDb])
            ->where('status', 'APPROVED')->where('is_preauth', false)->count();
        $totalProfit = (int) round((float) Transaction::whereBetween('created_at', [$shiftStartDb, $shiftEndDb])
            ->where('status', Transaction::STATUS_APPROVED)->sum('profit_mco'));

        $payload = [
            'leaderboard'   => $leaderboard,
            'events'        => $events,
            'last_event_id' => $events->first()['id'] ?? null,
            'total_txns'    => $totalTxns,
            'total_accs'    => $totalAccs,
            'total_profit'  => $totalProfit,
            'currency'      => 'USD',
            'server_time'   => Carbon::now()->toIso8601String(),
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'new_booking'   => 'New Booking',
            'exchange'      => 'Exchange',
            'seat_purchase' => 'Seat Purchase',
            'cabin_upgrade' => 'Cabin Upgrade',
            'cancel_refund' => 'Cancellation',
            'cancel_credit' => 'Credit Shell',
            'other'         => 'Other',
            default         => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
