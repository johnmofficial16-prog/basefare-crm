<?php

namespace App\Controllers;

use App\Services\Theme;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ThemeController — the dark-mode switch in the sidebar.
 *
 *   POST /theme   theme=dark|light   → {"theme":"dark"}
 *
 * Saved on the person's own account, so it follows them to any computer.
 */
class ThemeController
{
    public function save(Request $request, Response $response): Response
    {
        // A developer viewing as someone changes their OWN look, never that person's.
        $uid = (int) (\App\Services\DevMode::devId() ?? $_SESSION['user_id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $wanted = Theme::normalise(isset($body['theme']) ? (string) $body['theme'] : null);
        if (\App\Services\DevMode::active()) {
            $_SESSION['dev']['theme'] = $wanted;
        }

        if ($uid <= 0) {
            return $this->json($response, ['error' => 'Please sign in again.'], 401);
        }

        try {
            DB::table('users')->where('id', $uid)->update(['theme' => $wanted]);
        } catch (\Throwable $e) {
            // users.theme not migrated yet: still switch for this session.
            error_log('[theme] could not save choice: ' . $e->getMessage());
        }
        $_SESSION['theme'] = $wanted;

        return $this->json($response, ['theme' => Theme::current()]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
