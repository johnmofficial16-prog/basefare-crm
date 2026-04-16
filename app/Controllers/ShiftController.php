<?php

namespace App\Controllers;

use App\Models\ShiftSchedule;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\ShiftService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ShiftController
{
    private ShiftService $shiftService;

    public function __construct()
    {
        $this->shiftService = new ShiftService();
    }

    // -------------------------------------------------------------------------
    // WEEK VIEW
    // -------------------------------------------------------------------------

    /**
     * GET /shifts/week[?week=YYYY-MM-DD]
     * Render the Admin Weekly Scheduling Grid.
     */
    public function weekView(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $actorId     = (int)$_SESSION['user_id'];
        $actorRole   = $_SESSION['role'] ?? 'agent';

        // Default to the Monday of the current week if no date passed
        $weekStart = isset($queryParams['week'])
            ? ShiftSchedule::getMondayOfWeek($queryParams['week'])
            : ShiftSchedule::getMondayOfWeek(date('Y-m-d'));

        $weekDates = $this->getWeekDates($weekStart);
        $templates = ShiftTemplate::orderBy('name')->get();

        // Supervisors only see their own team; managers/admins see everyone
        $supervisorId = ($actorRole === User::ROLE_SUPERVISOR) ? $actorId : null;
        $agents       = $this->shiftService->getActiveAgents($supervisorId);
        $grid         = $this->shiftService->getWeekSchedule($weekStart);

        // Pending approvals badge for managers/admins
        $pendingApprovals = [];
        if (in_array($actorRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $pendingApprovals = $this->shiftService->getPendingApprovals();
        }

        ob_start();
        require __DIR__ . '/../Views/shifts/week.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    // -------------------------------------------------------------------------
    // PUBLISH WEEK (Admin only)
    // -------------------------------------------------------------------------

    /**
     * POST /shifts/week/publish
     * Bulk-create or update all shifts for a week from a form submission.
     * Expects JSON body: { week_start: "YYYY-MM-DD", entries: [{agent_id, shift_date, shift_start, shift_end, template_id?}] }
     */
    public function publishWeek(Request $request, Response $response): Response
    {
        $body    = json_decode((string)$request->getBody(), true);
        $entries = $body['entries'] ?? [];

        if (empty($entries) || !is_array($entries)) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'No schedule entries provided.'], 422);
        }

        $adminId   = (int)$_SESSION['user_id'];
        $actorRole = $_SESSION['role'] ?? 'agent';
        $result    = $this->shiftService->publishWeek($entries, $adminId, $actorRole);

        $statusCode = $result['success'] ? 200 : 422;
        return $this->jsonResponse($response, $result, $statusCode);
    }

    // -------------------------------------------------------------------------
    // CELL OPERATIONS (Mid-week edit / delete)
    // -------------------------------------------------------------------------

    /**
     * POST /shifts/cell/update
     * Update a single agent/day cell.
     * Expects JSON body: { agent_id, shift_date, shift_start, shift_end, template_id? }
     */
    public function updateCell(Request $request, Response $response): Response
    {
        $body      = json_decode((string)$request->getBody(), true);
        $agentId   = (int)($body['agent_id'] ?? 0);
        $date      = $body['shift_date'] ?? '';
        $adminId   = (int)$_SESSION['user_id'];
        $actorRole = $_SESSION['role'] ?? 'agent';

        $result = $this->shiftService->updateCell($agentId, $date, $body, $adminId, $actorRole);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /shifts/cell/delete
     * Remove a shift from a specific agent/day.
     * Expects JSON body: { agent_id, shift_date }
     */
    public function deleteCell(Request $request, Response $response): Response
    {
        $body      = json_decode((string)$request->getBody(), true);
        $agentId   = (int)($body['agent_id'] ?? 0);
        $date      = $body['shift_date'] ?? '';
        $actorId   = (int)$_SESSION['user_id'];
        $actorRole = $_SESSION['role'] ?? 'agent';

        $result = $this->shiftService->deleteCell($agentId, $date, $actorId, $actorRole);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 404);
    }

    // -------------------------------------------------------------------------
    // APPROVE PUBLISH (Manager/Admin only)
    // -------------------------------------------------------------------------

    /**
     * POST /shifts/week/approve
     * Approve pending_approval shifts for a given week.
     * Expects JSON: { week_start: "YYYY-MM-DD", supervisor_id?: int }
     */
    public function approvePublish(Request $request, Response $response): Response
    {
        $actorRole = $_SESSION['role'] ?? 'agent';
        if (!in_array($actorRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Insufficient permissions.'], 403);
        }

        $body        = json_decode((string)$request->getBody(), true);
        $weekStart   = $body['week_start'] ?? '';
        $supervisorId = !empty($body['supervisor_id']) ? (int)$body['supervisor_id'] : null;

        if (empty($weekStart)) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'week_start is required.'], 422);
        }

        $approverId = (int)$_SESSION['user_id'];
        $result     = $this->shiftService->approveShiftPublish($weekStart, $approverId, $supervisorId);

        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 500);
    }

    /**
     * GET /shifts/pending-approvals
     * Returns pending approval weeks as JSON (manager/admin only).
     */
    public function pendingApprovals(Request $request, Response $response): Response
    {
        $actorRole = $_SESSION['role'] ?? 'agent';
        if (!in_array($actorRole, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Insufficient permissions.'], 403);
        }

        $pending = $this->shiftService->getPendingApprovals();
        return $this->jsonResponse($response, ['success' => true, 'pending' => $pending], 200);
    }

    // -------------------------------------------------------------------------
    // TEMPLATES
    // -------------------------------------------------------------------------

    /**
     * GET /shifts/templates
     * Return all templates as JSON (used by the grid's dropdown selectors).
     */
    public function getTemplates(Request $request, Response $response): Response
    {
        $templates = ShiftTemplate::orderBy('name')->get(['id', 'name', 'start_time', 'end_time']);
        return $this->jsonResponse($response, $templates->toArray(), 200);
    }

    /**
     * POST /shifts/templates
     * Create a new shift template.
     * Expects JSON body: { name, start_time, end_time }
     */
    public function saveTemplate(Request $request, Response $response): Response
    {
        $body = json_decode((string)$request->getBody(), true);

        $name  = trim($body['name'] ?? '');
        $start = $body['start_time'] ?? '';
        $end   = $body['end_time'] ?? '';

        $errors = [];
        if (empty($name)) $errors['name'] = 'Template name is required.';
        if (empty($start)) $errors['start_time'] = 'Start time is required.';
        if (empty($end)) $errors['end_time'] = 'End time is required.';
        // Overnight shifts (e.g. 21:00 → 06:00) are valid — only reject if times are identical
        if (!empty($start) && !empty($end) && $start === $end) {
            $errors['end_time'] = 'Start and end time cannot be the same.';
        }

        if (!empty($errors)) {
            return $this->jsonResponse($response, ['success' => false, 'errors' => $errors], 422);
        }

        try {
            $template = ShiftTemplate::create([
                'name'       => $name,
                'start_time' => $start,
                'end_time'   => $end,
                'created_by' => $_SESSION['user_id'],
            ]);
            return $this->jsonResponse($response, ['success' => true, 'template' => $template->toArray()], 201);
        } catch (\Throwable $e) {
            error_log('[ShiftController::saveTemplate] Error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Could not save template.'], 500);
        }
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    /**
     * Build an array of 7 date strings (Mon–Sun) for a given week start (Monday).
     */
    private function getWeekDates(string $weekStart): array
    {
        $dates = [];
        $d = new \DateTime($weekStart);
        for ($i = 0; $i < 7; $i++) {
            $dates[] = $d->format('Y-m-d');
            $d->modify('+1 day');
        }
        return $dates;
    }

    /**
     * Return a JSON response.
     */
    private function jsonResponse(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
