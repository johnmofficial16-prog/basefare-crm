<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * CsrfMiddleware
 * 
 * Protects state-changing requests (POST, PUT, DELETE, PATCH) against
 * Cross-Site Request Forgery via token validation.
 */
class CsrfMiddleware
{
    /**
     * @param Request        $request
     * @param RequestHandler $handler
     * @return Response
     */
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // 1. Generate token if it doesn't exist
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // 2. Only validate on state-changing methods
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            
            $submittedToken = '';

            // Check parsed body first
            $body = $request->getParsedBody();
            if (is_array($body) && !empty($body['csrf_token'])) {
                $submittedToken = $body['csrf_token'];
            } else {
                // Determine if it was sent via header (e.g. AJAX requests)
                $headers = $request->getHeader('X-CSRF-Token');
                if (!empty($headers)) {
                    $submittedToken = $headers[0];
                }
            }

            // 3. Validation strict string comparison
            if (empty($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
                $response = new SlimResponse();
                $response->getBody()->write("Invalid or missing CSRF token. Request blocked.");
                return $response->withStatus(403);
            }
        }

        // 4. Safe to proceed
        return $handler->handle($request);
    }
}
