<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;

/**
 * Staff conversations, for Trio HR's admins.
 *
 * Staff write to each other inside the CRM (CRM → Messages, John, 22 Oct:
 * "trio mail needs to be inside the crm"). The messages live in the CRM's
 * chat_* tables; Trio HR only reads them here, read-only, and every open is
 * audited. CRM admins see the same conversations at /messages/all.
 *
 * (The short-lived Trio HR colleague mail, hr_chat_*, is no longer used.)
 */
final class Colleagues
{
    /** CRM members of each conversation, with their Trio HR record if they have one. */
    private static function members(array $threadIds): array
    {
        if (!$threadIds) {
            return [];
        }
        $rows = Kernel::crm()->table('chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.thread_id', $threadIds)->orderBy('u.name')->get(['m.thread_id', 'u.id', 'u.name', 'u.role']);
        $hr = Kernel::hr()->table('hr_employees')->whereIn('crm_user_id', $rows->pluck('id')->unique()->all() ?: [0])
            ->get(['id', 'crm_user_id', 'designation', 'centre'])->keyBy('crm_user_id');
        $out = [];
        foreach ($rows as $r) {
            $e = $hr[$r->id] ?? null;
            $out[(int) $r->thread_id][(int) $r->id] = [
                'name' => $r->name, 'initials' => Fmt::initials($r->name), 'employee_id' => $e ? (int) $e->id : null,
                'designation' => $e->designation ?? (\App\Hr\Crm\CrmGateway::ROLE_LABELS[$r->role] ?? ucfirst((string) $r->role)),
                'centre' => $e ? Settings::centreName($e->centre) : null,
            ];
        }
        return $out;
    }

    public static function adminList(Ctx $c): array
    {
        $db = Kernel::crm();
        $q = trim((string) ($c->query('q', 80) ?? ''));
        $threads = $db->table('chat_threads')->orderByDesc('last_at');
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $threads->where(fn($w) => $w->where('subject', 'like', $like)
                ->orWhereIn('id', $db->table('chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('u.name', 'like', $like)->select('m.thread_id'))
                ->orWhereIn('id', $db->table('chat_messages')->where('body', 'like', $like)->select('thread_id')));
        }
        $rows = $threads->limit(200)->get();
        $members = self::members($rows->pluck('id')->map(fn($v) => (int) $v)->all());
        $counts = $rows->isEmpty() ? [] : $db->table('chat_messages')->whereIn('thread_id', $rows->pluck('id')->all())
            ->groupBy('thread_id')->selectRaw('thread_id, COUNT(*) AS n')->pluck('n', 'thread_id')->all();
        return [
            'items' => $rows->map(fn($t) => [
                'id' => (int) $t->id, 'subject' => $t->subject, 'preview' => $t->last_preview, 'last_at' => $t->last_at,
                'messages' => (int) ($counts[$t->id] ?? 0),
                'members' => ($t->kind ?? 'chat') === 'broadcast'
                    ? ['Broadcast from ' . (($members[(int) $t->id][(int) $t->created_by] ?? null)['name'] ?? 'an admin') . ' to ' . $t->audience]
                    : array_values(array_map(fn($m) => $m['name'], $members[(int) $t->id] ?? [])),
            ])->all(),
            'total' => (int) $db->table('chat_threads')->count(),
        ];
    }

    /** A file on a staff message (admins only; each open is audited). */
    public static function file(Ctx $c, int $id): array
    {
        try {
            $a = Kernel::crm()->table('chat_attachments')->where('id', $id)->first();
        } catch (\Throwable) {
            $a = null;
        }
        $path = $a ? \App\Services\ChatFiles::safePath((string) $a->stored_path) : null;
        if (!$path) {
            throw ApiError::notFound('That file');
        }
        Audit::log('chat.file.view', ['entity' => 'chat_attachments', 'entity_id' => $id,
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' opened "' . $a->original_name . '" from a staff conversation',
            'detail' => ['chat_thread_id' => (int) $a->thread_id]]);
        return ['bytes' => (string) file_get_contents($path), 'mime' => $a->mime_type, 'name' => $a->original_name];
    }

    public static function adminThread(Ctx $c, int $id): array
    {
        $db = Kernel::crm();
        $t = $db->table('chat_threads')->where('id', $id)->first();
        if (!$t) {
            throw ApiError::notFound('That conversation');
        }
        $members = self::members([$id])[$id] ?? [];
        Audit::log('chat.view', ['entity' => 'chat_threads', 'entity_id' => $id,
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' read a staff conversation between ' . implode(', ', array_column($members, 'name'))]);
        $messages = $db->table('chat_messages as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.thread_id', $id)
            ->orderBy('m.id')->limit(1000)->get(['m.id', 'm.body', 'm.created_at', 'u.name']);
        $files = [];
        try {
            foreach ($db->table('chat_attachments')->where('thread_id', $id)->orderBy('id')->get() as $a) {
                $files[(int) $a->message_id][] = ['id' => (int) $a->id, 'name' => $a->original_name, 'mime' => $a->mime_type, 'size' => (int) $a->size_bytes];
            }
        } catch (\Throwable) {
            // before the CRM's chat_attachments migration: no files yet
        }
        $messages = $messages->map(fn($m) => ['id' => (int) $m->id, 'mine' => false, 'author' => $m->name, 'first' => Fmt::first($m->name),
                'initials' => Fmt::initials($m->name), 'body' => $m->body, 'at' => $m->created_at, 'files' => $files[(int) $m->id] ?? []])->all();
        return [
            'thread' => ['id' => (int) $t->id, 'subject' => $t->subject, 'started' => $t->created_at,
                'members' => array_values(array_map(fn($m) => ['id' => $m['employee_id'], 'name' => $m['name'], 'initials' => $m['initials'],
                    'designation' => $m['designation'], 'centre' => $m['centre']], $members))],
            'messages' => $messages,
            'opens' => Kernel::hr()->table('hr_audit_log')->where('action', 'chat.view')->where('entity_id', $id)->count(),
        ];
    }
}
