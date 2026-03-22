<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use App\Models\User;

class AuthMiddleware
{
    private $requiredRoles;

    /**
     * Optional: Pass roles (e.g. ['admin', 'manager']) to restrict the route
     */
    public function __construct(array $requiredRoles = [])
    {
        $this->requiredRoles = $requiredRoles;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Check if user is logged into session
        if (!isset($_SESSION['user_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // Optional: Role-based Access Control (RBAC) check
        if (!empty($this->requiredRoles)) {
            $userRole = $_SESSION['role'] ?? 'guest';
            if (!in_array($userRole, $this->requiredRoles)) {
                // Deny access
                $response = new SlimResponse();
                $response->getBody()->write('403 Forbidden - You do not have permission to access this page.');
                return $response->withStatus(403);
            }
        }

        // Passed security checks, proceed deeper into the app
        return $handler->handle($request);
    }
}
