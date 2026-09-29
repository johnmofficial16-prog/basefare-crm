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

        // Exempt: client error beacon. navigator.sendBeacon() cannot attach
        // custom headers, and the login page (pre-auth) must be able to report
        // failures too. The endpoint is a write-only, length-capped, per-IP
        // rate-limited log sink (see ClientErrorController) — CSRF adds
        // nothing there but would silence every report.
        if ($request->getUri()->getPath() === '/api/client-error') {
            return $handler->handle($request);
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
                // An upload over post_max_size makes PHP drop the whole body, so
                // the token is "missing". Still blocked — but say why, instead of
                // telling an agent attaching files that it's a security error.
                if (empty($body) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
                    && str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'multipart/form-data')) {
                    $response = new SlimResponse();
                    $response->getBody()->write(
                        '<!DOCTYPE html><meta charset="utf-8"><title>Upload too large</title>'
                        . '<div style="font-family:sans-serif;max-width:480px;margin:80px auto;padding:24px;border:1px solid #fecaca;background:#fef2f2;border-radius:12px;color:#7f1d1d">'
                        . '<h2 style="margin-top:0">Files too large</h2><p>The attached files were bigger than the server accepts in one go (limit '
                        . htmlspecialchars((string) ini_get('post_max_size')) . '). Nothing was saved or sent.</p>'
                        . '<p><a href="javascript:history.back()">&larr; Go back</a> and attach fewer or smaller files.</p></div>'
                    );
                    return $response->withStatus(413)->withHeader('Content-Type', 'text/html; charset=utf-8');
                }

                $response = new SlimResponse();
                $response->getBody()->write("Invalid or missing CSRF token. Request blocked.");
                return $response->withStatus(403);
            }
        }

        // 4. Safe to proceed
        return $handler->handle($request);
    }
}
