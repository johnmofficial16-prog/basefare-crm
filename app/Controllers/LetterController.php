<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\User;

/**
 * Company letter makers (HR / corporate correspondence).
 *
 * Same shape as PayrollController::slipMaker — a self-contained view that
 * renders a live preview client-side and exports to print / PDF.
 */
class LetterController
{
    public function loiMaker(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $activePage = 'letters';
        ob_start();
        require __DIR__ . '/../Views/letters/loi_maker.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }
}
