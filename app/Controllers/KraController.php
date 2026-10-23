<?php

namespace App\Controllers;

use App\Models\User;
use App\Services\Kra\KraEngine;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * "My KRA" — each person's own targets, progress and incentive (agents, team
 * leaders, managers). The numbers come from KraEngine, which follows the rules
 * the admin sets in Trio HR; nothing shows until a month has been saved there.
 *
 * Privacy (owner's rule, 23 Oct 2026): a person sees their own incentive; a
 * team leader or manager sees their team's progress, never their money.
 */
class KraController
{
    private const ROLES = [User::ROLE_AGENT, User::ROLE_SUPERVISOR, User::ROLE_MANAGER];

    public function page(Request $request, Response $response): Response
    {
        $role = (string) ($_SESSION['role'] ?? '');
        if (!in_array($role, self::ROLES, true)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        $userId = (int) $_SESSION['user_id'];
        $card = null;
        $failed = false;
        try {
            $card = (new KraEngine(DB::connection()))->card($userId);
        } catch (\Throwable $e) {
            // The page must still open (for instance before the KRA migration has run).
            $failed = true;
            \App\Services\ErrorLogService::log('warning', '[kra] card failed: ' . $e->getMessage());
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $activePage = 'kra';
        ob_start();
        require __DIR__ . '/../Views/kra/index.php';
        $response->getBody()->write((string) ob_get_clean());
        return $response;
    }
}
