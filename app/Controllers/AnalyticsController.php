<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\MarketingSpend;
use App\Services\AnalyticsService;
use App\Services\CentreService;
use App\Services\GeminiService;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * AnalyticsController — admin-only centre performance & marketing analytics.
 * Routes behind AuthMiddleware([admin]).
 */
class AnalyticsController
{
    private AnalyticsService $analytics;
    private CentreService    $centres;

    public function __construct()
    {
        $this->analytics = new AnalyticsService();
        $this->centres   = new CentreService();
    }

    // =========================================================================
    // DASHBOARD — GET /analytics
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $q = $request->getQueryParams();
        [$period, $start, $end, $periodLabel] = $this->resolvePeriod($q);

        $overview = $this->analytics->overview($start, $end);
        $trend    = $this->analytics->weeklyTrend(8);
        $configured = $this->centres->isConfigured();

        // Display currency mirrors the rest of the CRM (Performance/Dashboard).
        $currency = DB::table('system_config')->where('key', 'default_currency')->value('value') ?: 'CAD';

        $selFrom = $q['date_from'] ?? '';
        $selTo   = $q['date_to'] ?? '';
        $aiConfigured = (new GeminiService())->isConfigured();

        return $this->render($response, 'analytics/index.php', [
            'overview'     => $overview,
            'trend'        => $trend,
            'period'       => $period,
            'periodLabel'  => $periodLabel,
            'currency'     => $currency,
            'selFrom'      => $selFrom,
            'selTo'        => $selTo,
            'configured'   => $configured,
            'aiConfigured' => $aiConfigured,
        ]);
    }

    // =========================================================================
    // CENTRE SETTINGS + SPEND — GET /analytics/settings
    // =========================================================================

    public function settings(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $cfg = $this->centres->config();
        $map = $this->centres->resolveMap();

        // Users for the pickers (active, non-CSA leadership + agents).
        $users = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_AGENT, User::ROLE_CSA])
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        $spends = MarketingSpend::with('creator')->orderByDesc('period_start')->limit(100)->get();

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return $this->render($response, 'analytics/settings.php', [
            'cfg'          => $cfg,
            'map'          => $map,
            'users'        => $users,
            'spends'       => $spends,
            'flashSuccess' => $flashSuccess,
            'flashError'   => $flashError,
        ]);
    }

    // POST /analytics/settings — save centre config (managers, DMR members, overrides)
    public function saveSettings(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            $_SESSION['flash_error'] = 'CSRF validation failed.';
            return $response->withHeader('Location', '/analytics/settings')->withStatus(302);
        }

        $overrides = [];
        foreach ((array) ($body['override_user'] ?? []) as $i => $uid) {
            $centre = ($body['override_centre'][$i] ?? '');
            if ((int) $uid > 0 && in_array($centre, CentreService::ALL, true)) {
                $overrides[(int) $uid] = $centre;
            }
        }

        $this->centres->saveConfig([
            'moh_manager_id' => (int) ($body['moh_manager_id'] ?? 0),
            'jsr_manager_id' => (int) ($body['jsr_manager_id'] ?? 0),
            'dmr_member_ids' => array_map('intval', (array) ($body['dmr_member_ids'] ?? [])),
            'overrides'      => $overrides,
        ], (int) $_SESSION['user_id']);

        $_SESSION['flash_success'] = 'Centre configuration saved.';
        return $response->withHeader('Location', '/analytics/settings')->withStatus(302);
    }

    // POST /analytics/spend — add a marketing spend row
    public function spendStore(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            $_SESSION['flash_error'] = 'CSRF validation failed.';
            return $response->withHeader('Location', '/analytics/settings')->withStatus(302);
        }

        $centre = $body['centre'] ?? '';
        $amount = (float) ($body['amount'] ?? 0);
        $from   = $body['period_start'] ?? '';
        $to     = $body['period_end'] ?? '';

        if (!in_array($centre, CentreService::ALL, true)) {
            $_SESSION['flash_error'] = 'Pick a valid centre.';
        } elseif ($amount <= 0) {
            $_SESSION['flash_error'] = 'Spend amount must be greater than 0.';
        } elseif (!$from || !$to || strtotime($to) < strtotime($from)) {
            $_SESSION['flash_error'] = 'Enter a valid date range (end on/after start).';
        } else {
            MarketingSpend::create([
                'centre'       => $centre,
                'period_start' => $from,
                'period_end'   => $to,
                'amount'       => round($amount, 2),
                'currency'     => strtoupper(trim($body['currency'] ?? 'USD')) ?: 'USD',
                'channel'      => trim($body['channel'] ?? '') ?: null,
                'notes'        => trim($body['notes'] ?? '') ?: null,
                'created_by'   => (int) $_SESSION['user_id'],
            ]);
            $_SESSION['flash_success'] = 'Marketing spend recorded.';
        }

        return $response->withHeader('Location', '/analytics/settings')->withStatus(302);
    }

    // POST /analytics/spend/{id}/delete
    public function spendDelete(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            $_SESSION['flash_error'] = 'CSRF validation failed.';
            return $response->withHeader('Location', '/analytics/settings')->withStatus(302);
        }
        MarketingSpend::where('id', (int) $args['id'])->delete();
        $_SESSION['flash_success'] = 'Spend entry removed.';
        return $response->withHeader('Location', '/analytics/settings')->withStatus(302);
    }

    // =========================================================================
    // AI ANALYSIS — POST /analytics/ai  (AJAX, PII-free aggregates only)
    // =========================================================================

    public function aiAnalyze(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $q = ['period' => $body['period'] ?? 'monthly', 'date_from' => $body['date_from'] ?? '', 'date_to' => $body['date_to'] ?? ''];
        [$period, $start, $end, $periodLabel] = $this->resolvePeriod($q);

        $overview = $this->analytics->overview($start, $end);
        $trend    = $this->analytics->weeklyTrend(8);

        // Strip to a compact, PII-free payload for the model.
        $payload = [
            'period'  => $periodLabel,
            'totals'  => $overview['totals'],
            'centres' => array_map(fn($c) => [
                'label' => $c['label'], 'agents' => $c['agents'], 'bookings' => $c['bookings'],
                'revenue' => $c['revenue'], 'gross_mco' => $c['gross'], 'net_mco' => $c['net'],
                'acceptances' => $c['acceptances'], 'marketing_spend' => $c['spend'],
                'roi_net_per_spend' => $c['roi'], 'cost_per_booking' => $c['cost_per_booking'],
                'wow_delta_pct' => $c['delta'],
            ], $overview['centres']),
            'weekly_trend' => $trend,
        ];

        $result = (new GeminiService())->analyzePerformance($payload);
        return $this->json($response, $result, $result['success'] ? 200 : 502);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function resolvePeriod(array $q): array
    {
        $period = $q['period'] ?? 'monthly';
        $now    = Carbon::now();
        try {
            switch ($period) {
                case 'weekly':
                    $start = $now->copy()->startOfWeek();
                    $end   = $now->copy()->endOfWeek();
                    $label = 'This week · ' . $start->format('M j') . ' – ' . $end->format('M j');
                    break;
                case 'last30':
                    $start = $now->copy()->subDays(29)->startOfDay();
                    $end   = $now->copy()->endOfDay();
                    $label = 'Last 30 days';
                    break;
                case 'custom':
                    $start = !empty($q['date_from']) ? Carbon::parse($q['date_from'])->startOfDay() : $now->copy()->startOfMonth();
                    $end   = !empty($q['date_to'])   ? Carbon::parse($q['date_to'])->endOfDay()     : $now->copy()->endOfDay();
                    if ($end->lt($start)) {
                        [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
                    }
                    $label = 'Custom · ' . $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
                    break;
                case 'monthly':
                default:
                    $period = 'monthly';
                    $start  = $now->copy()->startOfMonth();
                    $end    = $now->copy()->endOfMonth();
                    $label  = $start->format('F Y');
                    break;
            }
        } catch (\Throwable $e) {
            $period = 'monthly';
            $start  = $now->copy()->startOfMonth();
            $end    = $now->copy()->endOfMonth();
            $label  = $start->format('F Y');
        }
        return [$period, $start, $end, $label];
    }

    private function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? '') === User::ROLE_ADMIN;
    }

    private function csrfOk(array $body): bool
    {
        return !empty($body['csrf_token']) && $body['csrf_token'] === ($_SESSION['csrf_token'] ?? '');
    }

    private function render(Response $response, string $view, array $data = []): Response
    {
        extract($data);
        ob_start();
        require __DIR__ . '/../Views/' . $view;
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
