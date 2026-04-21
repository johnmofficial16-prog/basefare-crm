<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * SecurityHeadersMiddleware
 *
 * Injects recommended HTTP security headers into every response
 * to mitigate clickjacking, XSS, MIME sniffing, and downgrade attacks.
 */
class SecurityHeadersMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        return $response
            // Prevent clickjacking — CRM should never be embedded in an iframe
            ->withHeader('X-Frame-Options', 'DENY')
            // Block MIME-type sniffing exploits
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Enable browser XSS filter (legacy, still helps older browsers)
            ->withHeader('X-XSS-Protection', '1; mode=block')
            // HSTS — force HTTPS for 1 year (includes subdomains)
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            // Prevent token/PII leakage via Referer header on external links
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            // Restrict browser features the CRM doesn't need
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            // Baseline CSP — allow CDN assets (Tailwind, Google Fonts, Material Symbols)
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com; "
                . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
                . "font-src 'self' https://fonts.gstatic.com; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "frame-ancestors 'none';"
            );
    }
}
