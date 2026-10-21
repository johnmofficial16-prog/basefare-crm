<?php

namespace App\Services;

use Illuminate\Database\Capsule\Manager as DB;


/**
 * CRM Messages: staff writing to each other inside the CRM (John, 22 Oct).
 *
 *  - Anyone with an active CRM login can write to colleagues who also have
 *    one, picked from a list (never a typed address). Nothing leaves the CRM.
 *  - Every message is kept; there is no edit or delete.
 *  - CRM admins read every conversation, read-only (/messages/all); each open
 *    is written to activity_log. Trio HR's admins see them too.
 *  - Card numbers can't be sent (PCI): a message containing one is refused.
 *
 * Failures are thrown as ChatError with a plain-English message for the user.
 */
class ChatService
{
    public const BODY_MAX = 4000;
    public const MAX_TO = 10;
    private const MAX_PER_HOUR = 60;
    private const MAX_NEW_PER_HOUR = 20;

    /** The sidebar entry, with the signed-in person's unread count. */
    public static function navItem(): array
    {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        return ['href' => '/messages', 'icon' => 'forum', 'label' => 'Messages', 'key' => 'messages',
            'badge' => $uid ? (new self())->unread($uid) : 0];
    }

    // ---- the directory ---------------------------------------------------------------

    /** @return array<int, object> active CRM users by id */
    public function directory(): array
    {
        $out = [];
        // Everyone with a working CRM login. (users.is_dev means "developer access to the AI
        // maintenance buddy", not a test account, so it must not hide anyone here.)
        foreach (DB::table('users')->where('status', 'active')->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name', 'role']) as $u) {
            $out[(int) $u->id] = $u;
        }
        return $out;
    }

    public function canUse(int $userId): bool
    {
        return isset($this->directory()[$userId]);
    }

    /** The "To" list: everyone else, grouped later by role in the view. */
    public function colleagues(int $userId): array
    {
        $roles = ['admin' => 'Admin', 'manager' => 'Manager', 'supervisor' => 'Team leader', 'agent' => 'Travel agent', 'csa' => 'Customer support'];
        $out = [];
        foreach ($this->directory() as $id => $u) {
            if ($id !== $userId) {
                $out[] = ['id' => $id, 'name' => $u->name, 'role' => $roles[$u->role] ?? ucfirst((string) $u->role), 'initials' => self::initials($u->name)];
            }
        }
        return $out;
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last) ?: '?';
    }

    private static function first(string $name): string
    {
        return explode(' ', trim($name))[0] ?: $name;
    }

    // ---- unread ------------------------------------------------------------------------

    public function unread(int $userId): int
    {
        try {
            return (int) DB::table('chat_threads as t')->join('chat_members as m', 'm.thread_id', '=', 't.id')
                ->where('m.user_id', $userId)->whereColumn('m.read_upto', '<', 't.last_message_id')->count();
        } catch (\Throwable) {
            return 0; // before the migration has run, the sidebar must still render
        }
    }

    // ---- conversations -------------------------------------------------------------------

    /** Names of everyone in each conversation: [thread_id => [user_id => object]]. */
    private function members(array $threadIds): array
    {
        if (!$threadIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->whereIn('m.thread_id', $threadIds)
            ->orderBy('u.name')->get(['m.thread_id', 'm.read_upto', 'u.id', 'u.name', 'u.role', 'u.status', 'u.deleted_at']) as $r) {
            $out[(int) $r->thread_id][(int) $r->id] = $r;
        }
        return $out;
    }

    public function inbox(int $userId): array
    {
        $rows = DB::table('chat_threads as t')->join('chat_members as m', 'm.thread_id', '=', 't.id')
            ->where('m.user_id', $userId)->orderByDesc('t.last_at')->limit(200)->get(['t.*', 'm.read_upto']);
        $members = $this->members($rows->pluck('id')->map(fn($v) => (int) $v)->all());
        $out = [];
        foreach ($rows as $t) {
            $others = array_values(array_filter($members[(int) $t->id] ?? [], fn($m) => (int) $m->id !== $userId));
            $last = $members[(int) $t->id][(int) $t->last_sender] ?? null;
            $out[] = [
                'id' => (int) $t->id, 'subject' => $t->subject, 'preview' => $t->last_preview, 'last_at' => $t->last_at,
                'from_me' => (int) $t->last_sender === $userId, 'last_by' => $last ? self::first($last->name) : null,
                'with' => array_map(fn($m) => self::first($m->name), $others),
                'initials' => $others ? self::initials($others[0]->name) : '?', 'group' => count($others) > 1,
                'unread' => (int) $t->read_upto < (int) $t->last_message_id,
            ];
        }
        return $out;
    }

    private function ownThread(int $userId, int $id): object
    {
        $t = DB::table('chat_threads as t')->join('chat_members as m', 'm.thread_id', '=', 't.id')
            ->where('t.id', $id)->where('m.user_id', $userId)->first(['t.*', 'm.read_upto']);
        if (!$t) {
            throw new ChatError('That conversation was not found.', 404);
        }
        return $t;
    }

    private function messages(int $threadId, ?int $userId, int $after = 0): array
    {
        return DB::table('chat_messages as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.thread_id', $threadId)->where('m.id', '>', $after)->orderBy('m.id')->limit(500)
            ->get(['m.id', 'm.user_id', 'm.body', 'm.created_at', 'u.name'])
            ->map(fn($m) => [
                'id' => (int) $m->id, 'mine' => $userId !== null && (int) $m->user_id === $userId,
                'author' => $m->name, 'first' => self::first($m->name), 'initials' => self::initials($m->name),
                'body' => $m->body, 'at' => $m->created_at,
            ])->all();
    }

    /** One conversation for its member; marks it read. */
    public function thread(int $userId, int $id): array
    {
        $t = $this->ownThread($userId, $id);
        DB::table('chat_members')->where('thread_id', $id)->where('user_id', $userId)->update(['read_upto' => (int) $t->last_message_id]);
        $members = $this->members([$id])[$id] ?? [];
        $dir = $this->directory();
        return [
            'id' => (int) $t->id, 'subject' => $t->subject,
            'members' => array_values(array_map(fn($m) => ['id' => (int) $m->id, 'name' => $m->name, 'me' => (int) $m->id === $userId,
                'active' => isset($dir[(int) $m->id])], $members)),
            'can_reply' => (bool) array_filter($members, fn($m) => (int) $m->id !== $userId && isset($dir[(int) $m->id])),
            'messages' => $this->messages($id, $userId),
        ];
    }

    /** New messages since $after (the open conversation polls this); marks them read. */
    public function since(int $userId, int $id, int $after): array
    {
        $t = $this->ownThread($userId, $id);
        $msgs = $this->messages($id, $userId, $after);
        if ($msgs) {
            DB::table('chat_members')->where('thread_id', $id)->where('user_id', $userId)->update(['read_upto' => (int) $t->last_message_id]);
        }
        return ['messages' => $msgs, 'unread' => $this->unread($userId)];
    }

    // ---- writing --------------------------------------------------------------------------

    private function cleanBody(?string $raw): string
    {
        $body = trim(str_replace("\r\n", "\n", (string) $raw));
        if ($body === '') {
            throw new ChatError('Please write your message.', 422, 'body');
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            throw new ChatError('That message is too long. Keep it under ' . self::BODY_MAX . ' characters.', 422, 'body');
        }
        if (self::hasCardNumber($body)) {
            throw new ChatError("Card numbers can't be sent in messages. Remove the card number and send again.", 422, 'body');
        }
        return $body;
    }

    /** 13–19 digits (spaces or dashes allowed) that pass the Luhn check. */
    public static function hasCardNumber(string $text): bool
    {
        if (!preg_match_all('/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', $text, $m)) {
            return false;
        }
        foreach ($m[0] as $cand) {
            $digits = preg_replace('/\D/', '', $cand);
            $len = strlen($digits);
            if ($len < 13 || $len > 19) {
                continue;
            }
            $sum = 0;
            for ($i = 0; $i < $len; $i++) {
                $d = (int) $digits[$len - 1 - $i];
                if ($i % 2 === 1) {
                    $d *= 2;
                    if ($d > 9) {
                        $d -= 9;
                    }
                }
                $sum += $d;
            }
            if ($sum % 10 === 0) {
                return true;
            }
        }
        return false;
    }

    private static function preview(string $body): string
    {
        $one = trim((string) preg_replace('/\s+/u', ' ', $body));
        return mb_strlen($one) > 150 ? mb_substr($one, 0, 149) . '…' : $one;
    }

    private function throttle(int $userId, bool $new): void
    {
        $since = date('Y-m-d H:i:s', time() - 3600);
        if (DB::table('chat_messages')->where('user_id', $userId)->where('created_at', '>=', $since)->count() >= self::MAX_PER_HOUR) {
            throw new ChatError('That is a lot of messages in an hour. Please wait a little before sending more.', 429);
        }
        if ($new && DB::table('chat_threads')->where('created_by', $userId)->where('created_at', '>=', $since)->count() >= self::MAX_NEW_PER_HOUR) {
            throw new ChatError('That is a lot of new conversations in an hour. Reply in one of your open ones instead.', 429);
        }
    }

    /** Start a conversation. Returns its id. */
    public function compose(int $userId, array $to, ?string $subject, ?string $body, string $ip): int
    {
        $dir = $this->directory();
        if (!isset($dir[$userId])) {
            throw new ChatError('Your CRM login is not active.', 403);
        }
        $to = array_values(array_unique(array_filter(array_map('intval', $to), fn($id) => $id > 0 && $id !== $userId)));
        if (!$to) {
            throw new ChatError('Pick at least one colleague from the list.', 422, 'to');
        }
        if (count($to) > self::MAX_TO) {
            throw new ChatError('Up to ' . self::MAX_TO . ' people in one conversation.', 422, 'to');
        }
        foreach ($to as $id) {
            if (!isset($dir[$id])) {
                throw new ChatError('Someone you picked is not on the list any more. Pick again.', 422, 'to');
            }
        }
        $body = $this->cleanBody($body);
        $this->throttle($userId, true);
        $subject = trim(mb_substr((string) $subject, 0, 160));
        if ($subject === '') {
            $subject = 'Message from ' . self::first($dir[$userId]->name);
        }
        $now = date('Y-m-d H:i:s');
        $id = DB::connection()->transaction(function () use ($userId, $to, $subject, $body, $ip, $now) {
            $id = (int) DB::table('chat_threads')->insertGetId([
                'subject' => $subject, 'created_by' => $userId, 'last_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (array_merge([$userId], $to) as $uid) {
                DB::table('chat_members')->insert(['thread_id' => $id, 'user_id' => $uid, 'read_upto' => 0, 'added_at' => $now]);
            }
            $this->post($id, $userId, $body, $ip, $now);
            return $id;
        });
        $this->notifyOthers($id, $userId, $subject, 0);
        return $id;
    }

    public function reply(int $userId, int $id, ?string $body, string $ip): int
    {
        $t = $this->ownThread($userId, $id);
        $dir = $this->directory();
        if (!isset($dir[$userId])) {
            throw new ChatError('Your CRM login is not active.', 403);
        }
        $body = $this->cleanBody($body);
        $this->throttle($userId, false);
        $others = array_filter($this->members([$id])[$id] ?? [], fn($m) => (int) $m->id !== $userId && isset($dir[(int) $m->id]));
        if (!$others) {
            throw new ChatError('Nobody else in this conversation still has an active CRM login, so it is closed.', 409);
        }
        $prev = (int) $t->last_message_id;
        $msgId = $this->post($id, $userId, $body, $ip, date('Y-m-d H:i:s'));
        $this->notifyOthers($id, $userId, $t->subject, $prev);
        return $msgId;
    }

    private function post(int $threadId, int $userId, string $body, string $ip, string $now): int
    {
        $msgId = (int) DB::table('chat_messages')->insertGetId([
            'thread_id' => $threadId, 'user_id' => $userId, 'body' => $body, 'ip' => $ip ?: null, 'created_at' => $now,
        ]);
        DB::table('chat_threads')->where('id', $threadId)->update([
            'last_at' => $now, 'last_preview' => self::preview($body), 'last_message_id' => $msgId, 'last_sender' => $userId, 'updated_at' => $now,
        ]);
        DB::table('chat_members')->where('thread_id', $threadId)->where('user_id', $userId)->update(['read_upto' => $msgId]);
        return $msgId;
    }

    /** One bell notice per person per run of unread messages, not one per message. */
    private function notifyOthers(int $threadId, int $userId, string $subject, int $prevLastId): void
    {
        try {
            $from = (string) DB::table('users')->where('id', $userId)->value('name');
            foreach (DB::table('chat_members')->where('thread_id', $threadId)->where('user_id', '!=', $userId)->get() as $m) {
                if ((int) $m->read_upto >= $prevLastId) {
                    DB::table('notifications')->insert([
                        'user_id' => (int) $m->user_id, 'type' => 'message',
                        'title' => mb_substr('Message from ' . self::first($from), 0, 200), 'body' => mb_substr($subject, 0, 1000),
                        'link' => '/messages?c=' . $threadId, 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[messages] bell notice failed: ' . $e->getMessage()); // the message itself is saved either way
        }
    }

    // ---- admins: every conversation, read-only --------------------------------------------

    public function adminList(string $q = ''): array
    {
        $threads = DB::table('chat_threads')->orderByDesc('last_at');
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $threads->where(fn($w) => $w->where('subject', 'like', $like)
                ->orWhereIn('id', DB::table('chat_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('u.name', 'like', $like)->select('m.thread_id'))
                ->orWhereIn('id', DB::table('chat_messages')->where('body', 'like', $like)->select('thread_id')));
        }
        $rows = $threads->limit(300)->get();
        $members = $this->members($rows->pluck('id')->map(fn($v) => (int) $v)->all());
        $counts = $rows->isEmpty() ? [] : DB::table('chat_messages')->whereIn('thread_id', $rows->pluck('id')->all())
            ->groupBy('thread_id')->selectRaw('thread_id, COUNT(*) AS n')->pluck('n', 'thread_id')->all();
        return [
            'items' => $rows->map(fn($t) => [
                'id' => (int) $t->id, 'subject' => $t->subject, 'preview' => $t->last_preview, 'last_at' => $t->last_at,
                'messages' => (int) ($counts[$t->id] ?? 0),
                'members' => array_values(array_map(fn($m) => $m->name, $members[(int) $t->id] ?? [])),
            ])->all(),
            'total' => (int) DB::table('chat_threads')->count(),
        ];
    }

    public function adminThread(int $id): array
    {
        $t = DB::table('chat_threads')->where('id', $id)->first();
        if (!$t) {
            throw new ChatError('That conversation was not found.', 404);
        }
        $members = $this->members([$id])[$id] ?? [];
        return [
            'id' => (int) $t->id, 'subject' => $t->subject, 'started' => $t->created_at,
            'members' => array_values(array_map(fn($m) => ['id' => (int) $m->id, 'name' => $m->name, 'role' => $m->role,
                'initials' => self::initials($m->name)], $members)),
            'messages' => $this->messages($id, null),
        ];
    }
}
