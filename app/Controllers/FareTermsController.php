<?php

namespace App\Controllers;

use App\Services\FareTermsService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin — Fare Terms templates (/admin/fare-terms).
 *
 * Edits the wording behind each fare type and the cabin → default mapping.
 * Route group is admin-only (AuthMiddleware). Changes apply to forms created
 * afterwards; records already sent keep the text they were sent with.
 */
class FareTermsController
{
    public function index(Request $request, Response $response): Response
    {
        $templates    = FareTermsService::templates();
        $cabinMap     = FareTermsService::cabinMap();
        $customised   = [];
        foreach (FareTermsService::TYPES as $slug) {
            $customised[$slug] = FareTermsService::isCustomised($slug);
        }

        $activePage   = 'fare_terms';
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        ob_start();
        require __DIR__ . '/../Views/admin/fare_terms.php';
        $response->getBody()->write(ob_get_clean());
        return $response;
    }

    public function saveTemplate(Request $request, Response $response, array $args): Response
    {
        $slug = (string) $args['slug'];
        if (!FareTermsService::isValidType($slug)) {
            return $this->back($response, null, 'Unknown fare type.');
        }

        $body  = $request->getParsedBody() ?? [];
        $parts = [];
        foreach (FareTermsService::PARTS as $part) {
            $parts[$part] = trim((string) ($body[$part] ?? ''));
        }

        $errors = FareTermsService::validateTemplate($parts);
        if ($errors) {
            return $this->back($response, null, FareTermsService::label($slug) . ': ' . implode(' ', $errors), $slug);
        }

        FareTermsService::saveTemplate($slug, $parts, (int) $_SESSION['user_id']);
        $this->log('fare_terms_template_saved', ['type' => $slug]);

        return $this->back($response, FareTermsService::label($slug) . ' wording saved. New forms use it from now on.', null, $slug);
    }

    public function resetTemplate(Request $request, Response $response, array $args): Response
    {
        $slug = (string) $args['slug'];
        if (!FareTermsService::isValidType($slug)) {
            return $this->back($response, null, 'Unknown fare type.');
        }

        FareTermsService::resetTemplate($slug);
        $this->log('fare_terms_template_reset', ['type' => $slug]);

        return $this->back($response, FareTermsService::label($slug) . ' reset to the default wording.', null, $slug);
    }

    public function saveCabinMap(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $map  = is_array($body['cabin'] ?? null) ? $body['cabin'] : [];

        FareTermsService::saveCabinMap($map, (int) $_SESSION['user_id']);
        $this->log('fare_terms_cabin_map_saved', FareTermsService::cabinMap());

        return $this->back($response, 'Cabin defaults saved.');
    }

    private function back(Response $response, ?string $ok, ?string $err = null, ?string $anchor = null): Response
    {
        if ($ok)  $_SESSION['flash_success'] = $ok;
        if ($err) $_SESSION['flash_error']   = $err;
        return $response->withHeader('Location', '/admin/fare-terms' . ($anchor ? '#tpl-' . $anchor : ''))->withStatus(302);
    }

    private function log(string $action, array $details): void
    {
        try {
            DB::table('activity_log')->insert([
                'user_id'     => (int) $_SESSION['user_id'],
                'action'      => $action,
                'entity_type' => 'system_config',
                'entity_id'   => null,
                'details'     => json_encode($details),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[FareTermsController] Activity log failed: ' . $e->getMessage());
        }
    }
}
