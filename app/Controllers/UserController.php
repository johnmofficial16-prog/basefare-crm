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
        $this->requireAdmin($response);

        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $filters = [
            'search' => trim($params['search'] ?? ''),
            'role'   => $params['role'] ?? '',
            'status' => $params['status'] ?? '',
        ];

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
        $this->requireAdmin($response);

        $activePage = 'users';
        $flashError = $_SESSION['flash_error'] ?? null;
        $old        = $_SESSION['form_old'] ?? [];
        unset($_SESSION['flash_error'], $_SESSION['form_old']);

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
        $this->requireAdmin($response);

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
        $this->requireAdmin($response);

        $userId = (int) ($args['id'] ?? 0);
        $user   = User::whereNull('deleted_at')->find($userId);

        if (!$user) {
            $_SESSION['flash_error'] = 'User not found.';
            return $response->withHeader('Location', '/users')->withStatus(302);
        }

        $activePage = 'users';
        $flashError = $_SESSION['flash_error'] ?? null;
        $old        = $_SESSION['form_old'] ?? [];
        unset($_SESSION['flash_error'], $_SESSION['form_old']);

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
        $this->requireAdmin($response);

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
        $this->requireAdmin($response);

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
        $this->requireAdmin($response);

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
        $this->requireAdmin($response);

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
    // Guard
    // ─────────────────────────────────────────────────────────────────────────

    private function requireAdmin(Response &$response): void
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            // Return 403 — but since we can't throw from here in Slim without
            // middleware, we redirect with an error message.
            $_SESSION['flash_error'] = 'Access denied. Admins only.';
            header('Location: /dashboard');
            exit;
        }
    }
}
