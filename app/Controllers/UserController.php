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

        $actorRole = $_SESSION['role'] ?? 'agent';
        $params    = $request->getQueryParams();
        $page      = max(1, (int) ($params['page'] ?? 1));
        $filters   = [
            'search' => trim($params['search'] ?? ''),
            'role'   => $params['role'] ?? '',
            'status' => $params['status'] ?? '',
        ];

        // Non-admins cannot see admin accounts at all
        if ($actorRole !== 'admin') {
            $filters['exclude_roles'] = ['admin'];
        }

        $data = $this->svc->list($filters, $page, 20);

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

        // Load managers + supervisors for the reports_to_id dropdown
        $superiors = User::whereIn('role', [User::ROLE_MANAGER, User::ROLE_SUPERVISOR])
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

        $body   = $request->getParsedBody() ?? [];
        $result = $this->svc->create($body, (int) $_SESSION['user_id']);

        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['error'];
            $_SESSION['form_old']    = $body;
            return $response->withHeader('Location', '/users/create')->withStatus(302);
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

        $userId = (int) ($args['id'] ?? 0);
        $body   = $request->getParsedBody() ?? [];
        $result = $this->svc->update($userId, $body, (int) $_SESSION['user_id']);

        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['error'];
            $_SESSION['form_old']    = $body;
            return $response->withHeader('Location', '/users/' . $userId . '/edit')->withStatus(302);
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
        // Password reset is admin-only
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Admins only.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $userId  = (int) ($args['id'] ?? 0);
        $body    = $request->getParsedBody() ?? [];
        $newPass = $body['new_password'] ?? '';

        $result = $this->svc->resetPassword($userId, $newPass, (int) $_SESSION['user_id']);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE — POST (soft delete)
    // ─────────────────────────────────────────────────────────────────────────

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrManager($response);

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
    // CSV EXPORT  —  GET /users/export  (admin/manager only)
    // ─────────────────────────────────────────────────────────────────────────

    public function exportCsv(Request $request, Response $response): Response
    {
        $this->requireAdminOrManager($response);

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
    // Guards
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
}
