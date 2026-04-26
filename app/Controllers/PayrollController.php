<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\User;

class PayrollController
{
    public function slipMaker(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $activePage = 'payroll';
        ob_start();
        require __DIR__ . '/../Views/payroll/slip_maker.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }
}
