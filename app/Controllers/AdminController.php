<?php

namespace App\Controllers;

use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    // ─────────────────────────────────────────────────────────────────────────
    // SETTINGS PAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function settings(Request $request, Response $response): Response
    {
        $this->requireAdmin($response);

        // Load all system_config rows into a key→value map
        $rows   = DB::table('system_config')->get();
        $config = [];
        foreach ($rows as $row) {
            $config[$row->key] = $row->value;
        }

        $activePage   = 'settings';
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        ob_start();
        require __DIR__ . '/../Views/admin/settings.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        $this->requireAdmin($response);

        $body    = $request->getParsedBody() ?? [];
        $adminId = (int) $_SESSION['user_id'];
        $now     = date('Y-m-d H:i:s');
        $errors  = [];

        // Define expected keys with their validation rules
        $keys = [
            'abuse.single_washroom_max' => ['label' => 'Single washroom max',   'min' => 1,  'max' => 120],
            'abuse.washroom_count_max'  => ['label' => 'Washroom count max',    'min' => 1,  'max' => 30],
            'abuse.washroom_total_max'  => ['label' => 'Total washroom max',    'min' => 5,  'max' => 300],
            'default_grace_period_mins' => ['label' => 'Default grace period',  'min' => 0,  'max' => 120],
        ];

        foreach ($keys as $key => $rule) {
            if (!isset($body[$key])) continue;
            $val = (int) $body[$key];

            if ($val < $rule['min'] || $val > $rule['max']) {
                $errors[] = "{$rule['label']} must be between {$rule['min']} and {$rule['max']}.";
                continue;
            }

            // Upsert into system_config
            $existing = DB::table('system_config')->where('key', $key)->first();
            if ($existing) {
                DB::table('system_config')->where('key', $key)->update([
                    'value'      => (string) $val,
                    'updated_by' => $adminId,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('system_config')->insert([
                    'key'        => $key,
                    'value'      => (string) $val,
                    'updated_by' => $adminId,
                    'updated_at' => $now,
                ]);
            }
        }

        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
        } else {
            // Log the settings change
            try {
                DB::table('activity_log')->insert([
                    'user_id'     => $adminId,
                    'action'      => 'settings_updated',
                    'entity_type' => 'system_config',
                    'entity_id'   => null,
                    'details'     => json_encode(['keys_updated' => array_keys($keys)]),
                    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                    'created_at'  => $now,
                ]);
            } catch (\Throwable $e) {
                error_log('[AdminController] Activity log failed: ' . $e->getMessage());
            }

            $_SESSION['flash_success'] = 'Settings saved successfully.';
        }

        return $response->withHeader('Location', '/admin/settings')->withStatus(302);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ACTIVITY LOG
    // ─────────────────────────────────────────────────────────────────────────

    public function activityLog(Request $request, Response $response): Response
    {
        // managers and admins can view
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $_SESSION['flash_error'] = 'Access denied.';
            $response->getBody()->write('');
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = 30;

        $filters = [
            'user_id'     => (int) ($params['user_id'] ?? 0),
            'action'      => trim($params['action'] ?? ''),
            'entity_type' => trim($params['entity_type'] ?? ''),
            'date_from'   => $params['date_from'] ?? '',
            'date_to'     => $params['date_to'] ?? '',
        ];

        $query = DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->select(
                'al.id', 'al.user_id', 'al.action', 'al.entity_type',
                'al.entity_id', 'al.details', 'al.ip_address', 'al.created_at',
                'u.name as user_name', 'u.role as user_role'
            )
            ->orderByDesc('al.created_at');

        if ($filters['user_id'] > 0) {
            $query->where('al.user_id', $filters['user_id']);
        }
        if ($filters['action']) {
            $query->where('al.action', 'like', '%' . $filters['action'] . '%');
        }
        if ($filters['entity_type']) {
            $query->where('al.entity_type', $filters['entity_type']);
        }
        if ($filters['date_from']) {
            $query->where('al.created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if ($filters['date_to']) {
            $query->where('al.created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        $total      = $query->count();
        $records    = $query->skip(($page - 1) * $perPage)->take($perPage)->get();
        $totalPages = max(1, (int) ceil($total / $perPage));

        $allUsers    = DB::table('users')->select('id', 'name')->whereNull('deleted_at')->orderBy('name')->get();
        $entityTypes = DB::table('activity_log')->select('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type');

        $activePage = 'activity_log';

        ob_start();
        require __DIR__ . '/../Views/admin/activity_log.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard
    // ─────────────────────────────────────────────────────────────────────────

    private function requireAdmin(Response &$response): void
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            $_SESSION['flash_error'] = 'Access denied. Admins only.';
            header('Location: /dashboard');
            exit;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Error Console (Phase 8)
    // ─────────────────────────────────────────────────────────────────────────

    public function errorConsole(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            $_SESSION['flash_error'] = 'Access denied.';
            $response->getBody()->write('');
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = 25;

        $filters = [
            'severity'  => trim($params['severity'] ?? ''),
            'search'    => trim($params['search'] ?? ''),
            'date_from' => $params['date_from'] ?? '',
            'date_to'   => $params['date_to'] ?? '',
        ];

        $query = DB::table('error_log')->orderByDesc('created_at');

        if ($filters['severity']) {
            $query->where('severity', $filters['severity']);
        }
        if ($filters['search']) {
            $query->where('message', 'like', '%' . $filters['search'] . '%');
        }
        if ($filters['date_from']) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if ($filters['date_to']) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        $total      = $query->count();
        $errors     = $query->skip(($page - 1) * $perPage)->take($perPage)->get();
        $totalPages = max(1, (int) ceil($total / $perPage));

        $activePage = 'error_console';

        ob_start();
        require __DIR__ . '/../Views/admin/error_console.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function clearErrorLog(Request $request, Response $response): Response
    {
        // Only admins can clear errors
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            $_SESSION['flash_error'] = 'Only admins can clear the error log.';
            return $response->withHeader('Location', '/admin/error-console')->withStatus(302);
        }

        DB::table('error_log')->truncate();
        $_SESSION['flash_success'] = 'Error log cleared successfully.';
        return $response->withHeader('Location', '/admin/error-console')->withStatus(302);
    }
}
