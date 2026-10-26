<?php

namespace App\Controllers;

use App\Services\HrLink;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /hr — open Trio HR. Staff arrive signed in (a one-time link that works
 * for 60 seconds); admins arrive at the HR sign-in page. ?to=/me/mail/12 opens
 * that screen (Trio HR notices in the CRM bell link here).
 */
class HrLinkController
{
    public function open(Request $request, Response $response): Response
    {
        if (!HrLink::enabled()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $role = (string) ($_SESSION['role'] ?? '');
        // Developer in dev/admin view: straight into Trio HR as its owner account
        // (no HR password — the CRM already required password + app code). While
        // viewing as someone, Trio HR opens as that person.
        if (\App\Services\DevMode::active()) {
            if (\App\Services\DevMode::actingAs() === null) {
                $userId = (int) \App\Services\DevMode::devId();
                $role = 'dev';
            }
            \App\Services\DevMode::log('dev_open_hr', ['as_user_id' => \App\Services\DevMode::actingAs()]);
        }
        $to = $request->getQueryParams()['to'] ?? null;
        return $response
            ->withHeader('Location', HrLink::destination($userId, $role, is_string($to) ? $to : null))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withStatus(302);
    }
}
