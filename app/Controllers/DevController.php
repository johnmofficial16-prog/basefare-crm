<?php

namespace App\Controllers;

use App\Services\DevMode;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /dev — the developer's home: switch view (agent / team leader / manager
 * as a real person, admin, dev) and the maintenance tools.
 * POST /dev/view — switch.
 * Only for a signed-in developer (users.is_dev); everyone else gets a 404.
 */
class DevController
{
    public function index(Request $request, Response $response): Response
    {
        if (!DevMode::active()) {
            return $response->withStatus(404);
        }
        $people = [];
        foreach (['manager', 'supervisor', 'agent'] as $v) {
            $people[$v] = DevMode::people($v);
        }
        $recent = DB::table('activity_log as l')->leftJoin('users as u', 'u.id', '=', 'l.entity_id')
            ->where('l.entity_type', 'dev')->where('l.user_id', DevMode::devId())
            ->orderByDesc('l.id')->limit(15)->get(['l.created_at', 'l.action', 'l.details', 'u.name as as_name'])->all();
        $migrations = $this->migrationStatus();
        $errors24h = 0;
        try {
            $errors24h = (int) DB::table('error_log')->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count();
        } catch (\Throwable $e) {
            $errors24h = -1;
        }
        $flash = $_SESSION['dev_flash'] ?? null;
        unset($_SESSION['dev_flash']);
        $activePage = 'dev';
        ob_start();
        require __DIR__ . '/../Views/dev/index.php';
        $response->getBody()->write((string) ob_get_clean());
        return $response->withHeader('Cache-Control', 'no-store');
    }

    public function switchView(Request $request, Response $response): Response
    {
        if (!DevMode::active()) {
            return $response->withStatus(404);
        }
        $body = $request->getParsedBody() ?? [];
        $view = (string) ($body['view'] ?? 'dev');
        $error = DevMode::switchTo($view, isset($body['person']) ? (int) $body['person'] : null);
        if ($error !== null) {
            $_SESSION['dev_flash'] = $error;
            return $response->withHeader('Location', '/dev')->withStatus(302);
        }
        return $response->withHeader('Location', $view === 'dev' ? '/dev' : '/dashboard')->withStatus(302);
    }

    /** Migrations the ledger hasn't seen yet (i.e. waiting for `php hostinger_migrate.php`). */
    private function migrationStatus(): array
    {
        try {
            $done = DB::table('schema_migrations')->pluck('filename')->all();
        } catch (\Throwable $e) {
            return ['ok' => false, 'pending' => []];
        }
        $onDisk = array_map('basename', glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: []);
        return ['ok' => true, 'pending' => array_values(array_diff($onDisk, $done)), 'applied' => count($done)];
    }
}
