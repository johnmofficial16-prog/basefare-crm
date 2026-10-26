<?php

namespace App\Middleware;

use App\Services\Theme;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Puts the CRM theme (Peacock / Night Shift) on staff pages.
 *
 * Views are full HTML documents, each with its own <head>, so rather than edit
 * seventy heads this adds the theme files on the way out. It only touches HTML
 * from a page that marked itself as a staff page (see Theme::markStaffPage), so
 * JSON, downloads, customer pages and documents pass straight through.
 */
class ThemeMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        if (!Theme::isStaffPage()) {
            return $response;
        }
        $type = $response->getHeaderLine('Content-Type');
        if ($type !== '' && stripos($type, 'text/html') === false) {
            return $response;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $html = (string) $body->getContents();
        if ($html === '' || stripos($html, '</head>') === false) {
            return $response;
        }

        try {
            $themed = Theme::inject($html);
            // Developer signed in: the floating dev bar (view, who, back to dev) on every page.
            if (\App\Services\DevMode::active() && ($close = strripos($themed, '</body>')) !== false) {
                $themed = substr_replace($themed, \App\Services\DevMode::bar(), $close, 0);
            }
        } catch (\Throwable $e) {
            error_log('[theme] inject failed: ' . $e->getMessage());
            return $response->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream($html));
        }
        $stream = (new \Slim\Psr7\Factory\StreamFactory())->createStream($themed);

        return $response->withBody($stream)->withoutHeader('Content-Length');
    }
}
