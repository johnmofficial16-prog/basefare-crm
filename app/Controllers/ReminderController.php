<?php

namespace App\Controllers;

use App\Models\BookingReminder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReminderService;
use Carbon\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ReminderController — booking reminders (admin + manager only).
 *
 * Admins/managers attach reminders to a booking's detail page (AJAX create /
 * cancel), and get a cross-booking management view at GET /reminders. Recipients
 * (agent + manager chain + admins) are resolved at fire time by ReminderService.
 */
class ReminderController
{
    private ReminderService $service;

    public function __construct()
    {
        $this->service = new ReminderService();
    }

    // =========================================================================
    // MANAGEMENT PAGE — GET /reminders
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        if (!$this->canManage()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $userId   = (int) ($_SESSION['user_id'] ?? 0);
        $userRole = $_SESSION['role'] ?? 'agent';

        $q = $request->getQueryParams();
        $filter = in_array($q['filter'] ?? '', ['upcoming', 'past', 'all'], true) ? $q['filter'] : 'upcoming';

        $query = BookingReminder::with(['transaction.agent', 'creator']);

        // Managers only see reminders on their own team's bookings (+ their own).
        if ($userRole === User::ROLE_MANAGER) {
            $teamIds = $this->managerScope($userId);
            $query->whereHas('transaction', function ($t) use ($teamIds) {
                $t->whereIn('agent_id', $teamIds);
            });
        }

        if ($filter === 'upcoming') {
            $query->where('status', BookingReminder::STATUS_SCHEDULED)->orderBy('remind_at', 'asc');
        } elseif ($filter === 'past') {
            $query->whereIn('status', [BookingReminder::STATUS_FIRED, BookingReminder::STATUS_DISMISSED, BookingReminder::STATUS_CANCELLED])
                  ->orderByDesc('remind_at');
        } else {
            $query->orderByDesc('remind_at');
        }

        $reminders = $query->limit(300)->get();

        return $this->render($response, 'reminders/index.php', [
            'reminders' => $reminders,
            'filter'    => $filter,
            'userRole'  => $userRole,
            'csrf'      => $_SESSION['csrf_token'] ?? '',
        ]);
    }

    // =========================================================================
    // CREATE — POST /transactions/{id}/reminders  (AJAX)
    // =========================================================================

    public function store(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage()) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'Session expired — refresh and try again.'], 403);
        }

        $txn = Transaction::find((int) $args['id']);
        if (!$txn) {
            return $this->json($response, ['success' => false, 'error' => 'Booking not found.'], 404);
        }
        if (!$this->canManageBooking($txn)) {
            return $this->json($response, ['success' => false, 'error' => 'You can only set reminders on your own team\'s bookings.'], 403);
        }

        $parsed = $this->validate($body);
        if (isset($parsed['error'])) {
            return $this->json($response, ['success' => false, 'error' => $parsed['error']], 422);
        }

        $reminder = BookingReminder::create([
            'transaction_id' => $txn->id,
            'created_by'     => (int) $_SESSION['user_id'],
            'title'          => $parsed['title'],
            'message'        => $parsed['message'],
            'departure_at'   => $parsed['departure_at'],
            'timing_mode'    => $parsed['timing_mode'],
            'offset_hours'   => $parsed['offset_hours'],
            'remind_at'      => $parsed['remind_at'],
            'status'         => BookingReminder::STATUS_SCHEDULED,
        ]);

        return $this->json($response, [
            'success'  => true,
            'reminder' => $this->present($reminder->fresh('creator')),
        ]);
    }

    // =========================================================================
    // CANCEL — POST /reminders/{id}/cancel  (AJAX)
    // =========================================================================

    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!$this->canManage()) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->csrfOk($body)) {
            return $this->json($response, ['success' => false, 'error' => 'Session expired — refresh and try again.'], 403);
        }

        $reminder = BookingReminder::with('transaction')->find((int) $args['id']);
        if (!$reminder) {
            return $this->json($response, ['success' => false, 'error' => 'Reminder not found.'], 404);
        }
        if (!$reminder->transaction || !$this->canManageBooking($reminder->transaction)) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }
        if (!$reminder->isScheduled()) {
            return $this->json($response, ['success' => false, 'error' => 'Only scheduled reminders can be cancelled.'], 422);
        }

        $reminder->update(['status' => BookingReminder::STATUS_CANCELLED]);
        return $this->json($response, ['success' => true]);
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /** Validate + normalise the create payload. Returns ['error'=>...] on failure. */
    private function validate(array $body): array
    {
        $title = trim($body['title'] ?? '');
        if ($title === '') {
            return ['error' => 'Give the reminder a short title (e.g. "Name correction required").'];
        }
        if (mb_strlen($title) > 160) {
            $title = mb_substr($title, 0, 160);
        }

        $departureRaw = trim($body['departure_at'] ?? '');
        if ($departureRaw === '' || !strtotime($departureRaw)) {
            return ['error' => 'Confirm the departure date & time.'];
        }
        $departureAt = date('Y-m-d H:i:s', strtotime($departureRaw));

        $mode = ($body['timing_mode'] ?? 'preset') === BookingReminder::MODE_ABSOLUTE
            ? BookingReminder::MODE_ABSOLUTE
            : BookingReminder::MODE_PRESET;

        $offsetHours = null;
        $absoluteAt  = null;

        if ($mode === BookingReminder::MODE_PRESET) {
            $offsetHours = (int) ($body['offset_hours'] ?? 0);
            if ($offsetHours <= 0) {
                return ['error' => 'Choose how long before departure to remind.'];
            }
            if ($offsetHours > 24 * 90) {
                return ['error' => 'Lead time is too large (max 90 days).'];
            }
        } else {
            $absRaw = trim($body['absolute_at'] ?? '');
            if ($absRaw === '' || !strtotime($absRaw)) {
                return ['error' => 'Pick the exact date & time to be reminded.'];
            }
            $absoluteAt = date('Y-m-d H:i:s', strtotime($absRaw));
        }

        $remindAt = $this->service->computeRemindAt($departureAt, $mode, $offsetHours, $absoluteAt);

        // The fire time may legitimately be in the past (short-notice booking);
        // that's fine — the dispatcher will fire it on its next run. But block
        // absurd values.
        if ($remindAt->year < 2000 || $remindAt->year > 2100) {
            return ['error' => 'That timing produces an invalid reminder date.'];
        }

        return [
            'title'        => $title,
            'message'      => trim($body['message'] ?? '') ?: null,
            'departure_at' => $departureAt,
            'timing_mode'  => $mode,
            'offset_hours' => $offsetHours,
            'remind_at'    => $remindAt->format('Y-m-d H:i:s'),
        ];
    }

    // =========================================================================
    // PRESENTATION
    // =========================================================================

    /** Shape a reminder for the JSON response (used to render a row in JS). */
    private function present(BookingReminder $r): array
    {
        [$statusLabel, $statusClass] = $r->statusBadge();
        return [
            'id'           => $r->id,
            'title'        => $r->title,
            'message'      => $r->message,
            'departure_at' => $r->departure_at ? $r->departure_at->format('M j, Y g:i A') : '',
            'remind_at'    => $r->remind_at ? $r->remind_at->format('M j, Y g:i A') : '',
            'timing_label' => $r->timingLabel(),
            'status'       => $r->status,
            'status_label' => $statusLabel,
            'status_class' => $statusClass,
            'creator'      => $r->creator->name ?? '—',
            'can_cancel'   => $r->isScheduled(),
        ];
    }

    // =========================================================================
    // ACCESS HELPERS
    // =========================================================================

    private function canManage(): bool
    {
        return in_array($_SESSION['role'] ?? '', [User::ROLE_ADMIN, User::ROLE_MANAGER], true);
    }

    /** Admins manage any booking; managers only their own team's (+ their own). */
    private function canManageBooking(Transaction $txn): bool
    {
        $role = $_SESSION['role'] ?? '';
        if ($role === User::ROLE_ADMIN) {
            return true;
        }
        if ($role === User::ROLE_MANAGER) {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            return in_array((int) $txn->agent_id, $this->managerScope($userId), true);
        }
        return false;
    }

    /** Manager's team agent IDs plus themselves. */
    private function managerScope(int $managerId): array
    {
        $manager = User::find($managerId);
        $ids = $manager ? $manager->getTeamAgentIds(true) : [];
        $ids[] = $managerId;
        return array_values(array_unique(array_map('intval', $ids)));
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
