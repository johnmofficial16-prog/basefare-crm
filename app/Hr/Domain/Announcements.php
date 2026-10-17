<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;
use App\Hr\Support\Notify;

/**
 * Announcements: one message from HR to many people (everyone, one or more
 * centres, or chosen people), shown in each person's Trio Mail inbox and on
 * Home, with read receipts. The audience is fixed when it is sent: someone who
 * joins later doesn't get old announcements.
 */
final class Announcements
{
    private const BODY_MAX = 4000;

    /** People who receive it: current staff only (not leavers), by audience. */
    private static function audienceIds(array $aud): array
    {
        $q = Kernel::hr()->table('hr_employees')->whereIn('status', ['joining', 'active', 'notice']);
        if (!empty($aud['people'])) {
            $q->whereIn('id', $aud['people']);
        } elseif (!empty($aud['centres'])) {
            $q->whereIn('centre', $aud['centres']);
        }
        return $q->pluck('id')->map(fn($v) => (int) $v)->all();
    }

    private static function audienceLabel(array $aud): string
    {
        if (!empty($aud['people'])) {
            return Fmt::plural(count($aud['people']), 'person', 'people');
        }
        if (!empty($aud['centres'])) {
            return implode(', ', array_map(fn($c) => Settings::centreName($c) ?? $c, $aud['centres']));
        }
        return 'Everyone';
    }

    // ---- admins -----------------------------------------------------------------

    public static function list(): array
    {
        $db = Kernel::hr();
        $rows = $db->table('hr_announcements')->orderByDesc('created_at')->limit(100)->get();
        $stats = $db->table('hr_announcement_reads')->whereIn('announcement_id', $rows->pluck('id')->all())
            ->groupBy('announcement_id')->selectRaw('announcement_id, COUNT(*) AS sent, SUM(read_at IS NOT NULL) AS seen')->get()->keyBy('announcement_id');
        $names = $db->table('hr_accounts')->pluck('display_name', 'id')->all();
        return ['announcements' => $rows->map(function ($a) use ($stats, $names) {
            $aud = json_decode((string) $a->audience, true) ?: [];
            $s = $stats[$a->id] ?? null;
            return [
                'id' => (int) $a->id, 'title' => $a->title, 'body' => $a->body, 'pinned' => (bool) $a->pinned,
                'expires_on' => $a->expires_on, 'withdrawn' => $a->withdrawn_at !== null, 'at' => $a->created_at,
                'by' => $names[$a->created_by] ?? null, 'audience' => self::audienceLabel($aud),
                'sent' => (int) ($s->sent ?? 0), 'seen' => (int) ($s->seen ?? 0),
            ];
        })->all(), 'centres' => People::centreOptions()];
    }

    public static function create(Ctx $c): array
    {
        $title = (string) $c->str('title', 160, true, 'Title');
        $body = $c->text('body', self::BODY_MAX);
        if ($body === null) {
            throw ApiError::invalid('Please write the announcement.', ['body' => 'Write the announcement.']);
        }
        $to = (string) $c->pick('to', ['all', 'centres', 'people'], true, 'Who it goes to');
        $aud = ['all' => true];
        if ($to === 'centres') {
            $valid = array_keys(Settings::get('centres'));
            $centres = array_values(array_intersect(is_array($c->raw('centres')) ? $c->raw('centres') : [], $valid));
            if (!$centres) {
                throw ApiError::invalid('Pick at least one centre.', ['centres' => 'Pick at least one centre.']);
            }
            $aud = ['centres' => $centres];
        } elseif ($to === 'people') {
            $people = $c->ids('people', 500);
            if (!$people) {
                throw ApiError::invalid('Pick at least one person.', ['people' => 'Pick at least one person.']);
            }
            $aud = ['people' => $people];
        }
        $expires = $c->date('expires_on', false, 'Show until');
        if ($expires !== null && $expires < date('Y-m-d')) {
            throw ApiError::invalid('Pick a date from today on.', ['expires_on' => 'That date has passed.']);
        }
        $ids = self::audienceIds($aud);
        if (!$ids) {
            throw ApiError::invalid('Nobody is in that group yet.');
        }
        $db = Kernel::hr();
        $now = Kernel::now();
        $id = $db->transaction(function () use ($db, $title, $body, $aud, $expires, $c, $now, $ids) {
            $id = (int) $db->table('hr_announcements')->insertGetId([
                'title' => $title, 'body' => $body, 'audience' => json_encode($aud), 'pinned' => $c->raw('pinned') ? 1 : 0,
                'expires_on' => $expires, 'created_by' => $c->accountId(), 'created_at' => $now,
            ]);
            foreach (array_chunk($ids, 200) as $chunk) {
                $db->table('hr_announcement_reads')->insert(array_map(fn($e) => ['announcement_id' => $id, 'employee_id' => $e], $chunk));
            }
            return $id;
        });
        foreach ($ids as $e) {
            Notify::employee($e, 'announcement', 'Announcement: ' . $title, null, '#/me/mail/a' . $id);
        }
        Audit::log('announcement.create', ['entity' => 'hr_announcements', 'entity_id' => $id,
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' announced "' . $title . '" to ' . self::audienceLabel($aud)]);
        return ['id' => $id, 'message' => 'Sent to ' . Fmt::plural(count($ids), 'person', 'people') . '.'];
    }

    /** Who has read it, and who hasn't yet. */
    public static function receipts(int $id): array
    {
        $db = Kernel::hr();
        $a = $db->table('hr_announcements')->where('id', $id)->first();
        if (!$a) {
            throw ApiError::notFound('That announcement');
        }
        $rows = $db->table('hr_announcement_reads as r')->join('hr_employees as e', 'e.id', '=', 'r.employee_id')
            ->where('r.announcement_id', $id)->orderBy('e.full_name')->get(['e.id', 'e.full_name', 'e.centre', 'r.read_at']);
        return [
            'id' => (int) $a->id, 'title' => $a->title, 'body' => $a->body,
            'read' => $rows->whereNotNull('read_at')->map(fn($r) => ['id' => (int) $r->id, 'name' => $r->full_name, 'at' => $r->read_at])->values()->all(),
            'unread' => $rows->whereNull('read_at')->map(fn($r) => ['id' => (int) $r->id, 'name' => $r->full_name])->values()->all(),
        ];
    }

    public static function withdraw(Ctx $c, int $id): array
    {
        $n = Kernel::hr()->table('hr_announcements')->where('id', $id)->whereNull('withdrawn_at')->update(['withdrawn_at' => Kernel::now()]);
        if (!$n) {
            throw ApiError::conflict('That announcement was already taken down.');
        }
        Audit::log('announcement.withdraw', ['entity' => 'hr_announcements', 'entity_id' => $id]);
        return ['message' => 'Taken down. Staff no longer see it.'];
    }

    // ---- staff ------------------------------------------------------------------

    private static function live(\Illuminate\Database\Query\Builder $q): \Illuminate\Database\Query\Builder
    {
        return $q->whereNull('a.withdrawn_at')->where(fn($w) => $w->whereNull('a.expires_on')->orWhere('a.expires_on', '>=', date('Y-m-d')));
    }

    public static function forEmployee(int $employeeId): array
    {
        $q = Kernel::hr()->table('hr_announcement_reads as r')->join('hr_announcements as a', 'a.id', '=', 'r.announcement_id')
            ->where('r.employee_id', $employeeId);
        return self::live($q)->orderByDesc('a.pinned')->orderByDesc('a.created_at')->limit(50)
            ->get(['a.id', 'a.title', 'a.body', 'a.pinned', 'a.created_at', 'r.read_at'])
            ->map(fn($a) => [
                'id' => (int) $a->id, 'title' => $a->title, 'preview' => mb_strlen($a->body) > 150 ? rtrim(mb_substr($a->body, 0, 149)) . '…' : $a->body,
                'pinned' => (bool) $a->pinned, 'at' => $a->created_at, 'unread' => $a->read_at === null,
            ])->all();
    }

    public static function unreadFor(int $employeeId): int
    {
        $q = Kernel::hr()->table('hr_announcement_reads as r')->join('hr_announcements as a', 'a.id', '=', 'r.announcement_id')
            ->where('r.employee_id', $employeeId)->whereNull('r.read_at');
        return self::live($q)->count();
    }

    /** One announcement, for its recipient. Opening it marks it read. */
    public static function read(Ctx $c, int $id): array
    {
        $e = Me::employee($c);
        $q = Kernel::hr()->table('hr_announcement_reads as r')->join('hr_announcements as a', 'a.id', '=', 'r.announcement_id')
            ->where('r.employee_id', $e->id)->where('a.id', $id);
        $a = $q->whereNull('a.withdrawn_at')->first(['a.id', 'a.title', 'a.body', 'a.created_at', 'a.created_by', 'r.read_at']);
        if (!$a) {
            throw ApiError::notFound('That announcement');
        }
        if ($a->read_at === null) {
            Kernel::hr()->table('hr_announcement_reads')->where('announcement_id', $id)->where('employee_id', $e->id)->update(['read_at' => Kernel::now()]);
        }
        return ['id' => (int) $a->id, 'title' => $a->title, 'body' => $a->body, 'at' => $a->created_at,
            'by' => Fmt::first((string) Kernel::hr()->table('hr_accounts')->where('id', $a->created_by)->value('display_name'))];
    }
}
