<?php

namespace App\Middleware;

use App\Services\DevMode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Logs every action (anything but a page view) a developer takes while viewing
 * the CRM as someone else: "dev acting as Rohit: POST /call-logs → 302".
 * Field values are not stored — only which fields were sent — so passwords and
 * card numbers typed while testing never land in the log.
 */
class DevAuditMiddleware
{
    /** Background calls the page makes on its own (and the switch itself, logged separately) — not actions. */
    private const QUIET = ['/dev/view', '/buddy/greeting', '/buddy/admin/greeting', '/api/client-error'];

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        if ($method !== 'GET' && $method !== 'HEAD' && DevMode::actingAs() !== null && !in_array($path, self::QUIET, true)) {
            $body = $request->getParsedBody();
            if (!is_array($body) && str_contains($request->getHeaderLine('Content-Type'), 'json')) {
                // JSON requests aren't decoded yet out here; only the field NAMES are kept anyway.
                $body = json_decode((string) $request->getBody(), true);
            }
            DevMode::log('dev_action_as', [
                'as_user_id' => DevMode::actingAs(),
                'as_name'    => $_SESSION['dev']['as_name'] ?? null,
                'request'    => $method . ' ' . $request->getUri()->getPath(),
                'status'     => $response->getStatusCode(),
                'fields'     => is_array($body) ? array_values(array_diff(array_keys($body), ['csrf_token'])) : [],
            ]);
        }
        return $response;
    }
}
