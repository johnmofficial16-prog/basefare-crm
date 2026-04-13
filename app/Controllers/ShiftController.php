<?php

namespace App\Controllers;

use App\Models\ShiftSchedule;
use App\Models\ShiftTemplate;
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

        // Default to the Monday of the current week if no date passed
        $weekStart = isset($queryParams['week'])
            ? ShiftSchedule::getMondayOfWeek($queryParams['week'])
            : ShiftSchedule::getMondayOfWeek(date('Y-m-d'));

        $weekDates  = $this->getWeekDates($weekStart);
        $agents     = $this->shiftService->getActiveAgents();
        $grid       = $this->shiftService->getWeekSchedule($weekStart);
        $templates  = ShiftTemplate::orderBy('name')->get();

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

        $adminId = $_SESSION['user_id'];
        $result  = $this->shiftService->publishWeek($entries, $adminId);

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
        $body    = json_decode((string)$request->getBody(), true);
        $agentId = (int)($body['agent_id'] ?? 0);
        $date    = $body['shift_date'] ?? '';
        $adminId = $_SESSION['user_id'];

        $result = $this->shiftService->updateCell($agentId, $date, $body, $adminId);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 422);
    }

    /**
     * POST /shifts/cell/delete
     * Remove a shift from a specific agent/day.
     * Expects JSON body: { agent_id, shift_date }
     */
    public function deleteCell(Request $request, Response $response): Response
    {
        $body    = json_decode((string)$request->getBody(), true);
        $agentId = (int)($body['agent_id'] ?? 0);
        $date    = $body['shift_date'] ?? '';

        $result = $this->shiftService->deleteCell($agentId, $date);
        return $this->jsonResponse($response, $result, $result['success'] ? 200 : 404);
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
