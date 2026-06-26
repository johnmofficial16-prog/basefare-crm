<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\ChargebackRefund;
use App\Services\ChargebackService;
use App\Services\CentreService;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ChargebackController — admin-only Chargebacks & Refunds analysis.
 *
 * Manual entry (this data isn't captured automatically yet) bifurcated per
 * centre. Entry/edit/delete are AJAX so the admin can record many rows quickly
 * without the page reloading. Mirrors the Centre Analytics module's shape.
 */
class ChargebackController
{
    private ChargebackService $service;

    public function __construct()
    {
        $this->service = new ChargebackService();
    }

    // =========================================================================
    // DASHBOARD — GET /chargebacks
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $q = $request->getQueryParams();
        [$period, $start, $end, $periodLabel] = $this->resolvePeriod($q);

        $analysis = $this->service->analysis($start, $end);
        $trend    = $this->service->monthlyTrend(6);
        $currency = DB::table('system_config')->where('key', 'default_currency')->value('value') ?: 'CAD';

        // Recent entries for the management log (all-time, newest first) so the
        // admin always sees what they just added regardless of the period filter.
        $entries = ChargebackRefund::with('creator')->orderByDesc('event_date')->orderByDesc('id')->limit(200)->get();

        return $this->render($response, 'chargebacks/index.php', [
            'analysis'    => $analysis,
            'trend'       => $trend,
            'period'      => $period,
            'periodLabel' => $periodLabel,
            'currency'    => $currency,
            'selFrom'     => $q['date_from'] ?? '',
            'selTo'       => $q['date_to'] ?? '',
            'entries'     => $entries,
            'csrf'        => $_SESSION['csrf_token'] ?? '',
        ]);
    }

    // =========================================================================
    // STORE — POST /chargebacks  (AJAX)
    // =========================================================================

    public function store(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $data = $this->validateEntry($body);
        if (isset($data['error'])) {
            return $this->json($response, ['success' => false, 'error' => $data['error']], 422);
        }

        $data['created_by'] = (int) $_SESSION['user_id'];
        $entry = ChargebackRefund::create($data);

        return $this->json($response, ['success' => true, 'entry' => $this->presentEntry($entry->fresh('creator'))]);
    }

    // =========================================================================
    // UPDATE — POST /chargebacks/{id}/update  (AJAX)
    // =========================================================================

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }

        $entry = ChargebackRefund::find((int) $args['id']);
        if (!$entry) {
            return $this->json($response, ['success' => false, 'error' => 'Entry not found.'], 404);
        }

        $data = $this->validateEntry($body);
        if (isset($data['error'])) {
            return $this->json($response, ['success' => false, 'error' => $data['error']], 422);
        }

        $entry->update($data);
        return $this->json($response, ['success' => true, 'entry' => $this->presentEntry($entry->fresh('creator'))]);
    }

    // =========================================================================
    // DELETE — POST /chargebacks/{id}/delete  (AJAX)
    // =========================================================================

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'CSRF validation failed.'], 403);
        }
        ChargebackRefund::where('id', (int) $args['id'])->delete();
        return $this->json($response, ['success' => true]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Validate + normalise an entry payload. Returns ['error'=>...] on failure. */
    private function validateEntry(array $body): array
    {
        $centre = $body['centre'] ?? '';
        $kind   = $body['kind'] ?? '';
        $amount = (float) ($body['amount'] ?? 0);
        $date   = $body['event_date'] ?? '';

        if (!in_array($centre, CentreService::ALL, true)) {
            return ['error' => 'Pick a valid centre.'];
        }
        if (!in_array($kind, ChargebackRefund::KINDS, true)) {
            return ['error' => 'Pick chargeback or refund.'];
        }
        if ($amount <= 0) {
            return ['error' => 'Amount must be greater than 0.'];
        }
        if (!$date || !strtotime($date)) {
            return ['error' => 'Enter a valid date.'];
        }

        $outcome = $body['outcome'] ?? '';
        $outcome = in_array($outcome, ChargebackRefund::OUTCOMES, true) ? $outcome : null;

        return [
            'centre'        => $centre,
            'kind'          => $kind,
            'event_date'    => date('Y-m-d', strtotime($date)),
            'amount'        => round($amount, 2),
            'currency'      => strtoupper(trim($body['currency'] ?? 'USD')) ?: 'USD',
            'pnr'           => trim($body['pnr'] ?? '') ?: null,
            'customer_name' => trim($body['customer_name'] ?? '') ?: null,
            'reason'        => trim($body['reason'] ?? '') ?: null,
            'outcome'       => $outcome,
            'notes'         => trim($body['notes'] ?? '') ?: null,
        ];
    }

    /** Shape an entry for the JSON response (used to render the table row in JS). */
    private function presentEntry(ChargebackRefund $e): array
    {
        return [
            'id'            => $e->id,
            'centre'        => $e->centre,
            'centre_color'  => CentreService::COLORS[$e->centre] ?? '#64748b',
            'kind'          => $e->kind,
            'event_date'    => $e->event_date ? $e->event_date->format('M j, Y') : '',
            'amount'        => (float) $e->amount,
            'currency'      => $e->currency,
            'pnr'           => $e->pnr,
            'customer_name' => $e->customer_name,
            'reason'        => $e->reason,
            'outcome'       => $e->outcome,
        ];
    }

    private function resolvePeriod(array $q): array
    {
        $period = $q['period'] ?? 'monthly';
        $now    = Carbon::now();
        try {
            switch ($period) {
                case 'last30':
                    $start = $now->copy()->subDays(29)->startOfDay();
                    $end   = $now->copy()->endOfDay();
                    $label = 'Last 30 days';
                    break;
                case 'year':
                    $start = $now->copy()->startOfYear();
                    $end   = $now->copy()->endOfYear();
                    $label = $now->format('Y');
                    break;
                case 'all':
                    $start = Carbon::create(2000, 1, 1)->startOfDay();
                    $end   = $now->copy()->endOfDay();
                    $label = 'All time';
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
