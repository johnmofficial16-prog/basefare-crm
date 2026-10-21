<?php

namespace App\Controllers;

use App\Services\ChatService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * NoteController — the Notepad: each CRM user's own notes (slide-in panel on every page).
 *
 *   GET  /api/notes              the list (title, preview, pinned, updated)
 *   GET  /api/notes/{id}         one note
 *   POST /api/notes              new note (optional title/body)
 *   POST /api/notes/{id}         save title / body / pinned (autosave)
 *   POST /api/notes/{id}/delete  delete (soft)
 *
 * Every query is scoped to the signed-in user: notes are private to whoever wrote them.
 */
class NoteController
{
    private const TITLE_MAX = 120;
    private const BODY_MAX = 20000;
    private const MAX_NOTES = 300;

    private function uid(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    private function own(int $id): ?object
    {
        return DB::table('user_notes')->where('id', $id)->where('user_id', $this->uid())->whereNull('deleted_at')->first();
    }

    private static function preview(string $body): string
    {
        $one = trim((string) preg_replace('/\s+/u', ' ', $body));
        return mb_strlen($one) > 110 ? mb_substr($one, 0, 109) . '…' : $one;
    }

    private static function present(object $n, bool $full = false): array
    {
        $title = trim($n->title) !== '' ? $n->title : (trim(strtok(trim($n->body) . "\n", "\n")) ?: 'Untitled note');
        $out = ['id' => (int) $n->id, 'title' => mb_substr($title, 0, self::TITLE_MAX), 'own_title' => $n->title,
            'preview' => self::preview($n->body), 'pinned' => (bool) $n->pinned, 'updated_at' => $n->updated_at];
        if ($full) {
            $out['body'] = $n->body;
        }
        return $out;
    }

    public function index(Request $request, Response $response): Response
    {
        $rows = DB::table('user_notes')->where('user_id', $this->uid())->whereNull('deleted_at')
            ->orderByDesc('pinned')->orderByDesc('updated_at')->limit(self::MAX_NOTES)->get();
        return $this->json($response, ['ok' => true, 'notes' => $rows->map(fn($n) => self::present($n))->all()]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $n = $this->own((int) $args['id']);
        return $n ? $this->json($response, ['ok' => true, 'note' => self::present($n, true)])
            : $this->json($response, ['ok' => false, 'error' => 'That note was not found.'], 404);
    }

    /** Checks shared by create and save. Returns an error message, or null when fine. */
    private function problem(?string $title, ?string $body): ?string
    {
        if ($title !== null && mb_strlen($title) > self::TITLE_MAX) {
            return 'The title is too long.';
        }
        if ($body !== null && mb_strlen($body) > self::BODY_MAX) {
            return 'That note is too long. Split it into two notes.';
        }
        if (ChatService::hasCardNumber((string) $title . "\n" . (string) $body)) {
            return "Card numbers can't be saved in the notepad. Remove the card number and it will save.";
        }
        return null;
    }

    public function create(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $title = isset($b['title']) ? trim((string) $b['title']) : '';
        $body = isset($b['body']) ? str_replace("\r\n", "\n", (string) $b['body']) : '';
        if ($err = $this->problem($title, $body)) {
            return $this->json($response, ['ok' => false, 'error' => $err], 422);
        }
        if (DB::table('user_notes')->where('user_id', $this->uid())->whereNull('deleted_at')->count() >= self::MAX_NOTES) {
            return $this->json($response, ['ok' => false, 'error' => 'You have ' . self::MAX_NOTES . ' notes. Delete some old ones first.'], 409);
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) DB::table('user_notes')->insertGetId([
            'user_id' => $this->uid(), 'title' => $title, 'body' => $body, 'pinned' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->json($response, ['ok' => true, 'note' => self::present($this->own($id), true)]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $n = $this->own((int) $args['id']);
        if (!$n) {
            return $this->json($response, ['ok' => false, 'error' => 'That note was not found. It may have been deleted in another tab.'], 404);
        }
        $b = (array) $request->getParsedBody();
        $set = [];
        if (array_key_exists('title', $b)) {
            $set['title'] = trim((string) $b['title']);
        }
        if (array_key_exists('body', $b)) {
            $set['body'] = str_replace("\r\n", "\n", (string) $b['body']);
        }
        if (array_key_exists('pinned', $b)) {
            $set['pinned'] = in_array((string) $b['pinned'], ['1', 'true'], true) ? 1 : 0;
        }
        if ($err = $this->problem($set['title'] ?? null, $set['body'] ?? null)) {
            return $this->json($response, ['ok' => false, 'error' => $err], 422);
        }
        if ($set) {
            $set['updated_at'] = date('Y-m-d H:i:s');
            DB::table('user_notes')->where('id', $n->id)->where('user_id', $this->uid())->update($set);
        }
        return $this->json($response, ['ok' => true, 'note' => self::present($this->own((int) $n->id))]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $n = DB::table('user_notes')->where('id', (int) $args['id'])->where('user_id', $this->uid())->whereNull('deleted_at')
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
        return $n ? $this->json($response, ['ok' => true]) : $this->json($response, ['ok' => false, 'error' => 'That note was not found.'], 404);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')->withStatus($status);
    }
}
