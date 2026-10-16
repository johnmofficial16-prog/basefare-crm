<?php

namespace App\Controllers;

use App\Models\User;
use App\Services\CentreService;
use App\Services\StaffSurveyService;
use App\Services\SurveyInvalid;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Staff survey: staff answer it in the pop-up (app/Views/partials/staff_survey.php);
 * admins read the answers at /admin/survey.
 */
class StaffSurveyController
{
    /** POST /survey (JSON): send the answers. */
    public function submit(Request $request, Response $response): Response
    {
        $role = (string) ($_SESSION['role'] ?? '');
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$userId || !StaffSurveyService::appliesTo($role)) {
            return $this->json($response, ['ok' => false, 'error' => 'This survey is for staff.'], 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            StaffSurveyService::submit($userId, $role, $body['answers'] ?? null);
        } catch (SurveyInvalid $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage(), 'fields' => $e->fields], 422);
        }
        return $this->json($response, ['ok' => true]);
    }

    /** POST /survey/skip: "Not now". */
    public function skip(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$userId || !StaffSurveyService::appliesTo((string) ($_SESSION['role'] ?? ''))) {
            return $this->json($response, ['ok' => false, 'error' => 'This survey is for staff.'], 403);
        }
        try {
            $s = StaffSurveyService::skip($userId);
        } catch (\DomainException $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 409);
        }
        return $this->json($response, ['ok' => true, 'skips_left' => $s['skips_left'],
            'snoozed_ms' => $s['snoozed_until'] ? strtotime($s['snoozed_until']) * 1000 : 0]);
    }

    /** GET /admin/survey: the answers, the summary and who hasn't answered. */
    public function index(Request $request, Response $response): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        $data = StaffSurveyService::results();
        return $this->render($response, 'admin/staff_survey.php', $data + [
            'questions' => StaffSurveyService::questions(),
            'pages' => StaffSurveyService::PAGES,
            'centreLabels' => CentreService::LABELS,
        ]);
    }

    /** GET /admin/survey/export: every answer as a spreadsheet (CSV). */
    public function export(Request $request, Response $response): Response
    {
        if (($_SESSION['role'] ?? '') !== User::ROLE_ADMIN) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        $data = StaffSurveyService::results();
        $questions = StaffSurveyService::questions();
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF"); // so Excel reads the text as UTF-8
        fputcsv($fh, array_merge(['Name', 'Role', 'Centre', 'Answered on', 'Times skipped first'], array_map(fn($q) => $q['label'], array_values($questions))));
        foreach ($data['responses'] as $r) {
            $row = [$r['name'], ucfirst($r['role']), CentreService::LABELS[$r['centre']] ?? '', $r['at'], $r['skips']];
            foreach ($questions as $key => $q) {
                $v = $r['answers'][$key] ?? '';
                $row[] = self::cell(is_array($v) ? implode('; ', $v) : (string) $v);
            }
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="staff-survey-' . StaffSurveyService::KEY . '.csv"');
    }

    /** A spreadsheet cell can't start a formula (=, +, -, @). */
    private static function cell(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    private function render(Response $response, string $view, array $data = []): Response
    {
        extract($data);
        ob_start();
        require __DIR__ . '/../Views/' . $view;
        $response->getBody()->write((string) ob_get_clean());
        return $response;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
