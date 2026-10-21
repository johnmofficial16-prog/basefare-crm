<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;
use App\Hr\Support\Notify;

/**
 * Trio Mail between colleagues (John, 21 Oct).
 *
 *  - Only staff with an active CRM login can write, and only to colleagues
 *    who also have one, picked from a list (the directory). Nothing is ever
 *    addressed by typing an email, and nothing leaves Trio HR.
 *  - Every message is kept; nobody can edit or delete one.
 *  - HR admins can read every conversation (Trio Mail → Staff
 *    conversations), read-only. Each time an admin opens one is audited.
 *    No notice to staff about this (John, 21 Oct); the screens never call
 *    these messages private.
 */
final class Colleagues
{
    private const BODY_MAX = 4000;
    private const MAX_TO = 10;
    private const MAX_PER_HOUR = 60;          // messages per person per hour
    private const MAX_NEW_PER_HOUR = 20;      // new conversations per person per hour

    // ---- who is in the directory ---------------------------------------------------

    /**
     * Current staff whose HR record is linked to an active CRM login.
     *
     * @return array<int, object> keyed by hr_employees.id
     */
    public static function directory(): array
    {
        $emps = Kernel::hr()->table('hr_employees')->whereIn('status', ['active', 'notice'])->whereNotNull('crm_user_id')
            ->orderBy('full_name')->get(['id', 'full_name', 'preferred_name', 'designation', 'centre', 'crm_user_id']);
        if ($emps->isEmpty()) {
            return [];
        }
        $active = Kernel::crm()->table('users')->whereIn('id', $emps->pluck('crm_user_id')->all())
            ->where('status', 'active')->whereNull('deleted_at')->where('is_dev', 0)->pluck('id')->map(fn($v) => (int) $v)->all();
        $active = array_flip($active);
        $out = [];
        foreach ($emps as $e) {
            if (isset($active[(int) $e->crm_user_id])) {
                $out[(int) $e->id] = $e;
            }
        }
        return $out;
    }

    /** The signed-in person, if they may use colleague mail. */
    private static function me(Ctx $c): object
    {
        if (!empty($c->auth['leaver'])) {
            throw ApiError::forbidden('You have left Trio, so messages to colleagues are closed. You can still write to HR.');
        }
        $e = Me::employee($c);
        if (!isset(self::directory()[(int) $e->id])) {
            throw new ApiError(403, 'no_crm', 'Messages to colleagues are for staff with an active CRM login. You can still write to HR.');
        }
        return $e;
    }

    private static function person(object $e): array
    {
        return ['id' => (int) $e->id, 'name' => $e->full_name, 'first' => Fmt::first($e->preferred_name ?: $e->full_name),
            'initials' => Fmt::initials($e->full_name), 'designation' => $e->designation, 'centre' => Settings::centreName($e->centre)];
    }

    /** The colleague list for the "To" dropdown (not including yourself). */
    public static function list(Ctx $c): array
    {
        $me = self::me($c);
        $people = [];
        foreach (self::directory() as $id => $e) {
            if ($id !== (int) $me->id) {
                $people[] = self::person($e);
            }
        }
        return ['people' => $people, 'max_to' => self::MAX_TO];
    }

    // ---- staff: their conversations ------------------------------------------------

    private static function body(Ctx $c): string
    {
        $raw = $c->raw('body');
        $body = is_string($raw) ? trim(str_replace("\r\n", "\n", $raw)) : '';
        if ($body === '') {
            throw ApiError::invalid('Please write your message.', ['body' => 'Write your message.']);
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            throw ApiError::invalid('That message is too long.', ['body' => 'Keep it under ' . self::BODY_MAX . ' characters.']);
        }
        return $body;
    }

    private static function preview(string $body): string
    {
        $one = trim((string) preg_replace('/\s+/u', ' ', $body));
        return mb_strlen($one) > 150 ? mb_substr($one, 0, 149) . '…' : $one;
    }

    /** Names of everyone in each conversation: [thread_id => [employee_id => object]]. */
    private static function members(array $threadIds): array
    {
        if (!$threadIds) {
            return [];
        }
        $out = [];
        foreach (Kernel::hr()->table('hr_chat_members as m')->join('hr_employees as e', 'e.id', '=', 'm.employee_id')
            ->whereIn('m.thread_id', $threadIds)->orderBy('e.full_name')
            ->get(['m.thread_id', 'm.read_upto', 'e.id', 'e.full_name', 'e.preferred_name', 'e.designation', 'e.centre']) as $r) {
            $out[(int) $r->thread_id][(int) $r->id] = $r;
        }
        return $out;
    }

    public static function inbox(Ctx $c): array
    {
        $me = self::me($c);
        $db = Kernel::hr();
        $rows = $db->table('hr_chat_threads as t')->join('hr_chat_members as m', 'm.thread_id', '=', 't.id')
            ->where('m.employee_id', $me->id)->orderByDesc('t.last_at')->limit(200)
            ->get(['t.*', 'm.read_upto']);
        $members = self::members($rows->pluck('id')->map(fn($v) => (int) $v)->all());
        $threads = [];
        foreach ($rows as $t) {
            $others = array_values(array_filter($members[(int) $t->id] ?? [], fn($m) => (int) $m->id !== (int) $me->id));
            $threads[] = [
                'id' => (int) $t->id, 'subject' => $t->subject, 'preview' => $t->last_preview, 'last_at' => $t->last_at,
                'from_me' => (int) $t->last_sender === (int) $me->id,
                'last_by' => ($m = $members[(int) $t->id][(int) $t->last_sender] ?? null) ? Fmt::first($m->preferred_name ?: $m->full_name) : null,
                'with' => array_map(fn($m) => Fmt::first($m->preferred_name ?: $m->full_name), $others),
                'with_initials' => $others ? Fmt::initials($others[0]->full_name) : '',
                'unread' => (int) $t->read_upto < (int) $t->last_message_id,
            ];
        }
        return ['threads' => $threads, 'unread' => self::unread((int) $me->id)];
    }

    public static function unread(int $employeeId): int
    {
        return (int) Kernel::hr()->table('hr_chat_threads as t')->join('hr_chat_members as m', 'm.thread_id', '=', 't.id')
            ->where('m.employee_id', $employeeId)->whereColumn('m.read_upto', '<', 't.last_message_id')->count();
    }

    private static function throttle(int $employeeId, bool $new): void
    {
        $db = Kernel::hr();
        $since = date('Y-m-d H:i:s', time() - 3600);
        if ($db->table('hr_chat_messages')->where('employee_id', $employeeId)->where('created_at', '>=', $since)->count() >= self::MAX_PER_HOUR) {
            throw new ApiError(429, 'throttled', 'That is a lot of messages in an hour. Please wait a little before sending more.');
        }
        if ($new && $db->table('hr_chat_threads')->where('created_by', $employeeId)->where('created_at', '>=', $since)->count() >= self::MAX_NEW_PER_HOUR) {
            throw new ApiError(429, 'throttled', 'That is a lot of new conversations in an hour. Reply in one of your open ones instead.');
        }
    }

    /** A new conversation with one or more colleagues from the directory. */
    public static function compose(Ctx $c): array
    {
        $me = self::me($c);
        $to = array_values(array_unique(array_map('intval', (array) ($c->raw('to') ?? []))));
        $to = array_values(array_filter($to, fn($id) => $id > 0 && $id !== (int) $me->id));
        if (!$to) {
            throw ApiError::invalid('Pick who to write to.', ['to' => 'Pick at least one colleague from the list.']);
        }
        if (count($to) > self::MAX_TO) {
            throw ApiError::invalid('Up to ' . self::MAX_TO . ' colleagues in one conversation.', ['to' => 'Pick fewer people.']);
        }
        $dir = self::directory();
        foreach ($to as $id) {
            if (!isset($dir[$id])) {
                throw ApiError::invalid('You can only write to colleagues on the list.', ['to' => 'Someone you picked is not on the list any more. Pick again.']);
            }
        }
        $subject = trim((string) ($c->str('subject', 160) ?? ''));
        $body = self::body($c);
        self::throttle((int) $me->id, true);
        $files = Mail::claimFilesFor($c);
        $db = Kernel::hr();
        $now = Kernel::now();
        $first = Fmt::first($me->preferred_name ?: $me->full_name);
        if ($subject === '') {
            $subject = 'Message from ' . $first;
        }
        $id = $db->transaction(function () use ($db, $me, $to, $subject, $body, $files, $now, $c) {
            $id = (int) $db->table('hr_chat_threads')->insertGetId([
                'subject' => $subject, 'created_by' => $me->id, 'last_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (array_merge([(int) $me->id], $to) as $eid) {
                $db->table('hr_chat_members')->insert(['thread_id' => $id, 'employee_id' => $eid, 'read_upto' => 0, 'added_at' => $now]);
            }
            self::post($id, $me, $c, $body, $files);
            return $id;
        });
        $names = implode(', ', array_map(fn($eid) => Fmt::first($dir[$eid]->preferred_name ?: $dir[$eid]->full_name), $to));
        Audit::log('chat.new', ['employee_id' => (int) $me->id, 'entity' => 'hr_chat_threads', 'entity_id' => $id,
            'summary' => $first . ' started a conversation with ' . $names]);
        return ['id' => $id, 'message' => 'Sent to ' . $names . '.'];
    }

    /** Save a message, move the thread on, and tell the others (once while they have unread). */
    private static function post(int $threadId, object $me, Ctx $c, string $body, array $files): int
    {
        $db = Kernel::hr();
        $now = Kernel::now();
        $prev = (int) $db->table('hr_chat_threads')->where('id', $threadId)->value('last_message_id');
        $msgId = (int) $db->table('hr_chat_messages')->insertGetId([
            'thread_id' => $threadId, 'employee_id' => $me->id, 'account_id' => $c->accountId(), 'body' => $body,
            'ip' => Ctx::clientIp(), 'created_at' => $now,
        ]);
        if ($files) {
            $db->table('hr_mail_files')->whereIn('id', $files)->whereNull('message_id')->whereNull('chat_message_id')
                ->update(['chat_message_id' => $msgId, 'chat_thread_id' => $threadId]);
        }
        $db->table('hr_chat_threads')->where('id', $threadId)->update([
            'last_at' => $now, 'last_preview' => self::preview($body) ?: '(attachment)', 'last_message_id' => $msgId,
            'last_sender' => $me->id, 'updated_at' => $now,
        ]);
        $db->table('hr_chat_members')->where('thread_id', $threadId)->where('employee_id', $me->id)->update(['read_upto' => $msgId]);
        $subject = (string) $db->table('hr_chat_threads')->where('id', $threadId)->value('subject');
        $first = Fmt::first($me->preferred_name ?: $me->full_name);
        foreach ($db->table('hr_chat_members')->where('thread_id', $threadId)->where('employee_id', '!=', $me->id)->get() as $m) {
            if ((int) $m->read_upto >= $prev) {
                // They had read everything before this one: one ping, not one per message.
                Notify::employee((int) $m->employee_id, 'chat.new', 'Message from ' . $first, $subject, '#/me/chats/' . $threadId);
            }
        }
        return $msgId;
    }

    private static function ownThread(object $me, int $id): object
    {
        $t = Kernel::hr()->table('hr_chat_threads as t')->join('hr_chat_members as m', 'm.thread_id', '=', 't.id')
            ->where('t.id', $id)->where('m.employee_id', $me->id)->first(['t.*', 'm.read_upto']);
        if (!$t) {
            throw ApiError::notFound('That conversation');
        }
        return $t;
    }

    private static function messages(int $threadId, ?int $meId): array
    {
        $db = Kernel::hr();
        $rows = $db->table('hr_chat_messages as m')->join('hr_employees as e', 'e.id', '=', 'm.employee_id')
            ->where('m.thread_id', $threadId)->orderBy('m.id')->limit(500)
            ->get(['m.id', 'm.employee_id', 'm.body', 'm.created_at', 'e.full_name', 'e.preferred_name']);
        $files = [];
        $ids = $rows->pluck('id')->all();
        if ($ids) {
            foreach ($db->table('hr_mail_files')->whereIn('chat_message_id', $ids)->orderBy('id')->get(['id', 'chat_message_id', 'file_name', 'mime', 'size']) as $f) {
                $files[(int) $f->chat_message_id][] = ['id' => (int) $f->id, 'name' => $f->file_name, 'mime' => $f->mime, 'size' => (int) $f->size];
            }
        }
        return $rows->map(fn($m) => [
            'id' => (int) $m->id, 'mine' => $meId !== null && (int) $m->employee_id === $meId,
            'author' => $m->full_name, 'first' => Fmt::first($m->preferred_name ?: $m->full_name), 'initials' => Fmt::initials($m->full_name),
            'body' => $m->body, 'at' => $m->created_at, 'files' => $files[(int) $m->id] ?? [],
        ])->all();
    }

    public static function thread(Ctx $c, int $id): array
    {
        $me = self::me($c);
        $t = self::ownThread($me, $id);
        Kernel::hr()->table('hr_chat_members')->where('thread_id', $id)->where('employee_id', $me->id)->update(['read_upto' => (int) $t->last_message_id]);
        $members = self::members([$id])[$id] ?? [];
        $dir = self::directory();
        return [
            'thread' => [
                'id' => (int) $t->id, 'subject' => $t->subject,
                'members' => array_values(array_map(fn($m) => ['id' => (int) $m->id, 'name' => $m->full_name, 'me' => (int) $m->id === (int) $me->id,
                    'active' => isset($dir[(int) $m->id])], $members)),
                // Replying needs at least one other member who is still on the list.
                'can_reply' => (bool) array_filter($members, fn($m) => (int) $m->id !== (int) $me->id && isset($dir[(int) $m->id])),
            ],
            'messages' => self::messages($id, (int) $me->id),
        ];
    }

    public static function reply(Ctx $c, int $id): array
    {
        $me = self::me($c);
        self::ownThread($me, $id);
        $body = self::body($c);
        self::throttle((int) $me->id, false);
        $dir = self::directory();
        $others = array_filter(self::members([$id])[$id] ?? [], fn($m) => (int) $m->id !== (int) $me->id && isset($dir[(int) $m->id]));
        if (!$others) {
            throw ApiError::conflict('Nobody else in this conversation is at Trio any more, so it is closed.');
        }
        $files = Mail::claimFilesFor($c);
        self::post($id, $me, $c, $body, $files);
        return ['message' => 'Sent.'];
    }

    // ---- admins: every conversation, read-only, every open logged --------------------

    public static function adminList(Ctx $c): array
    {
        $db = Kernel::hr();
        $q = trim((string) ($c->query('q', 80) ?? ''));
        $person = $c->query('person', 10);
        $threads = $db->table('hr_chat_threads')->orderByDesc('last_at');
        if ($person !== null && ctype_digit($person)) {
            $threads->whereIn('id', $db->table('hr_chat_members')->where('employee_id', (int) $person)->select('thread_id'));
        }
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $threads->where(fn($w) => $w->where('subject', 'like', $like)
                ->orWhereIn('id', $db->table('hr_chat_members as m')->join('hr_employees as e', 'e.id', '=', 'm.employee_id')
                    ->where('e.full_name', 'like', $like)->select('m.thread_id'))
                ->orWhereIn('id', $db->table('hr_chat_messages')->where('body', 'like', $like)->select('thread_id')));
        }
        $rows = $threads->limit(200)->get();
        $members = self::members($rows->pluck('id')->map(fn($v) => (int) $v)->all());
        $counts = $rows->isEmpty() ? [] : $db->table('hr_chat_messages')->whereIn('thread_id', $rows->pluck('id')->all())
            ->groupBy('thread_id')->selectRaw('thread_id, COUNT(*) AS n')->pluck('n', 'thread_id')->all();
        return [
            'items' => $rows->map(fn($t) => [
                'id' => (int) $t->id, 'subject' => $t->subject, 'preview' => $t->last_preview, 'last_at' => $t->last_at,
                'messages' => (int) ($counts[$t->id] ?? 0),
                'members' => array_values(array_map(fn($m) => $m->full_name, $members[(int) $t->id] ?? [])),
            ])->all(),
            'total' => (int) $db->table('hr_chat_threads')->count(),
        ];
    }

    public static function adminThread(Ctx $c, int $id): array
    {
        $t = Kernel::hr()->table('hr_chat_threads')->where('id', $id)->first();
        if (!$t) {
            throw ApiError::notFound('That conversation');
        }
        $members = self::members([$id])[$id] ?? [];
        $names = array_values(array_map(fn($m) => $m->full_name, $members));
        Audit::log('chat.view', ['entity' => 'hr_chat_threads', 'entity_id' => $id,
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' read a staff conversation between ' . implode(', ', $names)]);
        return [
            'thread' => ['id' => (int) $t->id, 'subject' => $t->subject, 'started' => $t->created_at,
                'members' => array_values(array_map(fn($m) => self::person($m) + ['read_upto' => (int) $m->read_upto], $members))],
            'messages' => self::messages($id, null),
            'opens' => Kernel::hr()->table('hr_audit_log')->where('action', 'chat.view')->where('entity_id', $id)->count(),
        ];
    }
}
