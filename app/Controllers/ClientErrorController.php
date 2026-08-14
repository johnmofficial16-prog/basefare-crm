<?php

namespace App\Controllers;

use App\Services\ErrorLogService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * ClientErrorController
 *
 * Receiving end of public/assets/js/error-beacon.js. Writes browser-side
 * failures (resource-load errors, uncaught JS, unhandled rejections) into the
 * error_log table with severity 'client'.
 *
 * Security posture:
 *  - CSRF-EXEMPT by design (see CsrfMiddleware): sendBeacon cannot set custom
 *    headers, and the login page (pre-auth) must be able to report too. The
 *    endpoint is write-only into a log table, accepts no identifiers from the
 *    client beyond error text, and caps every field length.
 *  - RATE-LIMITED per IP (RATE_MAX rows per RATE_WINDOW_MIN minutes) so a
 *    hostile client can't flood the console. Over-limit reports are dropped
 *    silently — a 204 either way, nothing to probe.
 *  - Always 204. Never errors outward; a broken logger must not create
 *    client-visible failures (or beacon loops).
 */
class ClientErrorController
{
    private const RATE_MAX        = 40;
    private const RATE_WINDOW_MIN = 10;
    private const MAX_BODY_BYTES  = 4096;

    public function report(Request $request, Response $response): Response
    {
        try {
            $raw = (string) $request->getBody();
            if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
                return $response->withStatus(204);
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['message'])) {
                return $response->withStatus(204);
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            // Per-IP rate limit — one cheap indexed COUNT.
            $recent = DB::table('error_log')
                ->where('severity', 'client')
                ->where('ip_address', $ip)
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - self::RATE_WINDOW_MIN * 60))
                ->count();
            if ($recent >= self::RATE_MAX) {
                return $response->withStatus(204);
            }

            $type    = preg_replace('/[^a-z]/', '', (string) ($data['type'] ?? 'js')) ?: 'js';
            $message = '[' . $type . '] ' . mb_substr(trim((string) $data['message']), 0, 500);
            $source  = isset($data['source']) ? mb_substr((string) $data['source'], 0, 300) : null;
            $line    = isset($data['line']) ? (int) $data['line'] : null;
            $pageUrl = isset($data['url']) ? mb_substr((string) $data['url'], 0, 500) : null;
            if ($pageUrl !== null) {
                $message .= ' | page: ' . $pageUrl;
            }

            ErrorLogService::log('client', $message, $source, $line);
        } catch (Throwable $e) {
            // Swallow everything — this endpoint must never fail outward.
        }
        return $response->withStatus(204);
    }
}
