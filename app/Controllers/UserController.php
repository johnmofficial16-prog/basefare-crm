<?php

namespace App\Controllers;

use App\Models\User;
use App\Services\UserService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserController
{
    private UserService $svc;

    public function __construct()
    {
        $this->svc = new UserService();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LIST
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdminOrManager($response);

        $actorId   = (int)$_SESSION['user_id'];
        $actorRole = $_SESSION['role'] ?? 'agent';
        $params    = $request->getQueryParams();
        $page      = max(1, (int) ($params['page'] ?? 1));
        $filters   = [
            'search' => trim($params['search'] ?? ''),
            'role'   => $params['role'] ?? '',
            'status' => $params['status'] ?? '',
        ];

        if ($actorRole === User::ROLE_MANAGER) {
            // Managers only see agents directly assigned to them
            $teamIds = $this->getManagerTeamIds($actorId);
            $filters['only_ids'] = $teamIds;
        } elseif ($actorRole !== User::ROLE_ADMIN) {
            // Non-admins cannot see admin accounts
            $filters['exclude_roles'] = ['admin'];
        }

        $data = $this->svc->list($filters, $page, 20);

        // Managers can move agents between themselves and their own team leaders
        $teamSuperiors = $actorRole === User::ROLE_MANAGER
            ? $this->svc->teamSuperiors(User::find($actorId))
            : collect();

        $activePage   = 'users';
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        ob_start();
        require __DIR__ . '/../Views/users/list.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE — Form
    // ─────────────────────────────────────────────────────────────────────────

    public function createForm(Request $request, Response $response): Response
    {
        $this->requireAdminOrManager($response);

        $activePage = 'users';
        $actorRole  = $_SESSION['role'] ?? 'agent';
        $flashError = $_SESSION['flash_error'] ?? null;
        $old        = $_SESSION['form_old'] ?? [];
        unset($_SESSION['flash_error'], $_SESSION['form_old']);

        // Load managers + supervisors for the reports_to_id dropdown.
        // A manager's new hires join their own team: them or their team leaders.
        $superiors = $actorRole === User::ROLE_MANAGER
            ? $this->svc->teamSuperiors(User::find((int) $_SESSION['user_id']))
            : User::whereIn('role', [User::ROLE_MANAGER, User::ROLE_SUPERVISOR])
                ->where('status', User::STATUS_ACTIVE)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name', 'role']);

        ob_start();
        require __DIR__ . '/../Views/users/create.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE — Submit
    // ─────────────────────────────────────────────────────────────────────────

    public function store(Request $request, Response $response): Response
    {
        $this->requireAdminOrManager($response);

        $body    = $request->getParsedBody() ?? [];
        $actorId = (int) $_SESSION['user_id'];
        $grace   = null;

        // Managers: the new hire joins their own team (them, or one of their
        // team leaders for an agent) and clocks in on the team's grace period.
        if (($_SESSION['role'] ?? '') === User::ROLE_MANAGER) {
            $manager = User::find($actorId);
            $role    = ($body['role'] ?? '') === User::ROLE_SUPERVISOR ? User::ROLE_SUPERVISOR : User::ROLE_AGENT;
            $to      = (int) ($body['reports_to_id'] ?? 0);
            if ($role === User::ROLE_SUPERVISOR || !$this->svc->teamSuperiors($manager)->firstWhere('id', $to)) {
                $to = $actorId;
            }
            $grace = $this->svc->teamGrace($manager);
            $body  = array_merge($body, [
                'role'              => $role,
                'reports_to_id'     => $to,
                'grace_period_mins' => min($grace, 120),
            ]);
        }

        $result = $this->svc->create($body, $actorId);

        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['error'];
            $_SESSION['form_old']    = $body;
            return $response->withHeader('Location', '/users/create')->withStatus(302);
        }

        // The team may be on the 24/7 roster's long grace, which the form's 0–120 check doesn't allow
        if ($grace !== null && $grace > 120) {
            $result['user']->update(['grace_period_mins' => $grace]);
        }

        $_SESSION['flash_success'] = 'User "' . htmlspecialchars($body['name'] ?? '') . '" created successfully.';
        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EDIT — Form
    // ─────────────────────────────────────────────────────────────────────────

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrManager($response);

        // Managers cannot edit users
        if (($_SESSION['role'] ?? '') === User::ROLE_MANAGER) {
            $_SESSION['flash_error'] = 'Managers cannot edit user accounts.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $userId = (int) ($args['id'] ?? 0);
        $user   = User::whereNull('deleted_at')->find($userId);

        if (!$user) {
            $_SESSION['flash_error'] = 'User not found.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        // Rank protection: managers cannot open the edit form for admins or other managers
        $actorRole = $_SESSION['role'] ?? 'agent';
        if ($actorRole === User::ROLE_MANAGER && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $_SESSION['flash_error'] = 'Managers cannot edit admin or manager accounts.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $activePage = 'users';
        $flashError = $_SESSION['flash_error'] ?? null;
        $old        = $_SESSION['form_old'] ?? [];
        unset($_SESSION['flash_error'], $_SESSION['form_old']);

        // Load managers + supervisors for reports_to_id dropdown
        $superiors = User::whereIn('role', [User::ROLE_MANAGER, User::ROLE_SUPERVISOR])
            ->where('status', User::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->where('id', '!=', $userId) // can't report to yourself
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        ob_start();
        require __DIR__ . '/../Views/users/edit.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EDIT — Submit
    // ─────────────────────────────────────────────────────────────────────────

    public function update(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrManager($response);

        // Managers cannot edit users
        if (($_SESSION['role'] ?? '') === User::ROLE_MANAGER) {
            $_SESSION['flash_error'] = 'Managers cannot edit user accounts.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $userId = (int) ($args['id'] ?? 0);
        $body   = $request->getParsedBody() ?? [];
        $result = $this->svc->update($userId, $body, (int) $_SESSION['user_id']);

        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['error'];
            $_SESSION['form_old']    = $body;
            return $response->withHeader('Location', '/users/' . $userId . '/edit')->withStatus(302);
        }

        // Simple view switch (admin accounts only; the box is only on their form)
        if (!empty($body['simple_view_present']) && ($_SESSION['role'] ?? '') === User::ROLE_ADMIN) {
            $target = User::find($userId);
            if ($target && $target->role === User::ROLE_ADMIN) {
                try {
                    $target->simple_view = !empty($body['simple_view']) ? 1 : 0;
                    $target->save();
                } catch (\Throwable $e) {
                    error_log('[UserController] simple_view not saved (migration 2026_10_12 run?): ' . $e->getMessage());
                }
            }
        }

        $_SESSION['flash_success'] = 'User updated successfully.';
        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOGGLE STATUS (Suspend / Reactivate) — AJAX/POST
    // ─────────────────────────────────────────────────────────────────────────

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrManager($response);

        // Managers cannot toggle status
        if (($_SESSION['role'] ?? '') === User::ROLE_MANAGER) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Managers cannot change user status.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $userId = (int) ($args['id'] ?? 0);
        $result = $this->svc->toggleStatus($userId, (int) $_SESSION['user_id']);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RESET PASSWORD — POST
    // ─────────────────────────────────────────────────────────────────────────

    public function resetPassword(Request $request, Response $response, array $args): Response
    {
        $actorId   = (int)$_SESSION['user_id'];
        $actorRole = $_SESSION['role'] ?? '';
        $targetId  = (int) ($args['id'] ?? 0);

        if ($actorRole === User::ROLE_MANAGER) {
            // Managers can only reset passwords for their direct-report agents
            $teamIds = $this->getManagerTeamIds($actorId);
            if (!in_array($targetId, $teamIds)) {
                $response->getBody()->write(json_encode(['success' => false, 'error' => 'You can only reset passwords for agents assigned to you.']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }
        } elseif ($actorRole !== User::ROLE_ADMIN) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Access denied.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $body    = $request->getParsedBody() ?? [];
        $newPass = $body['new_password'] ?? '';

        $result = $this->svc->resetPassword($targetId, $newPass, $actorId);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE — POST (soft delete)
    // ─────────────────────────────────────────────────────────────────────────

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrManager($response);

        // Managers cannot delete users
        if (($_SESSION['role'] ?? '') === User::ROLE_MANAGER) {
            $_SESSION['flash_error'] = 'Managers cannot delete user accounts.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $userId = (int) ($args['id'] ?? 0);
        $result = $this->svc->delete($userId, (int) $_SESSION['user_id']);

        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['error'];
        } else {
            $_SESSION['flash_success'] = 'User account removed.';
        }

        return $response->withHeader('Location', '/users')->withStatus(302);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEFT THE COMPANY — POST (manager, own team only; account set inactive)
    // ─────────────────────────────────────────────────────────────────────────

    public function markLeft(Request $request, Response $response, array $args): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_MANAGER) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }
        $result = $this->svc->markLeft((int) ($args['id'] ?? 0), (int) $_SESSION['user_id']);
        if ($result['success']) {
            $_SESSION['flash_success'] = $result['name'] . ' has been marked as left and removed from your team.'
                . ($result['moved_up'] ? ' Their ' . $result['moved_up'] . ' agent' . ($result['moved_up'] === 1 ? '' : 's') . ' now report to you.' : '');
        }
        return $this->json($response, $result, $result['success'] ? 200 : 422);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MOVE WITHIN TEAM — POST (manager: agent to self or own team leader)
    // ─────────────────────────────────────────────────────────────────────────

    public function moveWithinTeam(Request $request, Response $response, array $args): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_MANAGER) {
            return $this->json($response, ['success' => false, 'error' => 'Access denied.'], 403);
        }
        $body   = $request->getParsedBody() ?? [];
        $result = $this->svc->moveWithinTeam((int) ($args['id'] ?? 0), (int) ($body['reports_to_id'] ?? 0), (int) $_SESSION['user_id']);
        if ($result['success']) {
            $_SESSION['flash_success'] = 'Moved. They now report to ' . $result['name'] . '.';
        }
        return $this->json($response, $result, $result['success'] ? 200 : 422);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSV EXPORT  —  GET /users/export  (admin/manager only)
    // ─────────────────────────────────────────────────────────────────────────

    public function exportCsv(Request $request, Response $response): Response
    {
        $this->requireAdminOrManager($response);

        // Managers cannot export the user list
        if (($_SESSION['role'] ?? '') === User::ROLE_MANAGER) {
            $response->getBody()->write('Access denied.');
            return $response->withStatus(403);
        }

        $params  = $request->getQueryParams();
        $filters = [
            'search' => trim($params['search'] ?? ''),
            'role'   => $params['role'] ?? '',
            'status' => $params['status'] ?? '',
        ];

        // Non-admins cannot export admin accounts
        $actorRole = $_SESSION['role'] ?? 'agent';
        if ($actorRole !== 'admin') {
            $filters['exclude_roles'] = ['admin'];
        }

        // Pull all matching users (no pagination)
        $users = User::whereNull('deleted_at')
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $q->where(fn($q2) => $q2->where('name', 'LIKE', $term)->orWhere('email', 'LIKE', $term));
            })
            ->when(!empty($filters['role']), fn($q) => $q->where('role', $filters['role']))
            ->when(!empty($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->with('supervisor')
            ->orderBy('name')
            ->get();

        $headers = ['ID', 'Name', 'Email', 'Role', 'Status', 'Reports To', 'Created At'];

        $rows = $users->map(fn($u) => [
            $u->id,
            $u->name,
            $u->email,
            $u->role,
            $u->status,
            $u->supervisor?->name ?? '—',
            $u->created_at,
        ]);

        $filename = 'users_' . date('Y-m-d') . '.csv';

        $tmp = fopen('php://temp', 'r+');
        fputcsv($tmp, $headers);
        foreach ($rows as $row) {
            fputcsv($tmp, array_values((array)$row));
        }
        rewind($tmp);
        $csv = stream_get_contents($tmp);
        fclose($tmp);

        $response->getBody()->write("\xEF\xBB\xBF" . $csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guards & Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Allows admin and manager. Supervisor/agent are denied. */
    private function requireAdminOrManager(Response &$response): void
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $_SESSION['flash_error'] = 'Access denied.';
            header('Location: /dashboard');
            exit;
        }
    }

    /**
     * Returns the IDs of agents directly reporting to the given manager.
     * Returns [-1] if the manager has no assigned agents, ensuring queries
     * return empty results rather than all records.
     */
    private function getManagerTeamIds(int $managerId): array
    {
        $manager = User::find($managerId);
        if (!$manager) return [-1];
        $ids = $manager->getTeamAgentIds();
        return empty($ids) ? [-1] : $ids;
    }
}
