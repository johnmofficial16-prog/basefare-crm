<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;
use App\Hr\Support\Markup;
use App\Hr\Support\Notify;
use App\Hr\Support\Outbox;

/**
 * Policies and consents, with versions. Staff acknowledge a specific version;
 * publishing a new version asks everyone in its audience to sign again.
 * A consent (e.g. night work) can be withdrawn by the employee at any time.
 */
final class Policies
{
    public const AUDIENCES = ['all' => 'Everyone', 'women_nights' => 'Women at Punjab centres (night-work consent)'];

    // ---- who it is for ---------------------------------------------------------

    /** Current staff ids a policy applies to. */
    public static function audienceIds(object $p): array
    {
        $q = Kernel::hr()->table('hr_employees')->whereIn('status', ['joining', 'active', 'notice']);
        if ($p->audience === 'women_nights') {
            $punjab = array_keys(array_filter(Settings::get('centres'), fn($c) => ($c['state'] ?? null) === 'Punjab'));
            $q->where('gender', 'female')->whereIn('centre', $punjab ?: ['__none__']);
        }
        return $q->pluck('id')->map(fn($v) => (int) $v)->all();
    }

    private static function load(int $id): object
    {
        $p = Kernel::hr()->table('hr_policies')->where('id', $id)->first();
        if (!$p) {
            throw ApiError::notFound('That policy');
        }
        return $p;
    }

    private static function ackStats(object $p): array
    {
        $aud = self::audienceIds($p);
        $acks = Kernel::hr()->table('hr_policy_acks')->where('policy_id', $p->id)->whereNull('withdrawn_at')->pluck('employee_id')->map(fn($v) => (int) $v)->all();
        $signed = array_values(array_intersect($aud, $acks));
        return ['audience' => count($aud), 'signed' => count($signed), 'missing' => array_values(array_diff($aud, $acks))];
    }

    // ---- admin ------------------------------------------------------------------------

    public static function list(): array
    {
        $rows = Kernel::hr()->table('hr_policies')->orderBy('title')->orderByDesc('version')->get();
        $byCode = [];
        foreach ($rows as $p) {
            $byCode[$p->code][] = $p;
        }
        $out = [];
        foreach ($byCode as $versions) {
            $live = null;
            $draft = null;
            foreach ($versions as $v) {
                if ($v->status === 'published' && !$live) $live = $v;
                if ($v->status === 'draft' && !$draft) $draft = $v;
            }
            $main = $live ?? $draft ?? $versions[0];
            $stats = $live ? self::ackStats($live) : null;
            $out[] = [
                'id' => (int) $main->id, 'code' => $main->code, 'title' => $main->title, 'summary' => $main->summary,
                'kind' => $main->kind, 'audience' => self::AUDIENCES[$main->audience] ?? $main->audience,
                'live' => $live ? ['id' => (int) $live->id, 'version' => (int) $live->version, 'published_at' => $live->published_at] : null,
                'draft' => $draft ? ['id' => (int) $draft->id, 'version' => (int) $draft->version] : null,
                'signed' => $stats['signed'] ?? 0, 'of' => $stats['audience'] ?? 0,
                'withdrawn' => $live ? Kernel::hr()->table('hr_policy_acks')->where('policy_id', $live->id)->whereNotNull('withdrawn_at')->count() : 0,
            ];
        }
        usort($out, fn($a, $b) => [$a['live'] === null, $a['title']] <=> [$b['live'] === null, $b['title']]);
        return ['policies' => $out, 'audiences' => self::AUDIENCES];
    }

    public static function get(int $id): array
    {
        $p = self::load($id);
        $db = Kernel::hr();
        $names = $db->table('hr_accounts')->pluck('display_name', 'id')->all();
        $people = [];
        if ($p->status === 'published') {
            $aud = self::audienceIds($p);
            $acks = $db->table('hr_policy_acks')->where('policy_id', $p->id)->get()->keyBy('employee_id');
            $emps = $db->table('hr_employees')->whereIn('id', $aud ?: [0])->orderBy('full_name')->get(['id', 'full_name', 'designation', 'centre']);
            foreach ($emps as $e) {
                $a = $acks[$e->id] ?? null;
                $people[] = [
                    'id' => (int) $e->id, 'name' => $e->full_name, 'initials' => Fmt::initials($e->full_name), 'designation' => $e->designation,
                    'state' => !$a ? 'missing' : ($a->withdrawn_at ? 'withdrawn' : 'signed'),
                    'at' => $a ? ($a->withdrawn_at ?? $a->acked_at) : null,
                ];
            }
        }
        $versions = $db->table('hr_policies')->where('code', $p->code)->orderByDesc('version')
            ->get(['id', 'version', 'status', 'published_at'])->map(fn($v) => (array) $v)->all();
        return [
            'policy' => [
                'id' => (int) $p->id, 'code' => $p->code, 'version' => (int) $p->version, 'title' => $p->title, 'summary' => $p->summary,
                'body' => $p->body, 'html' => Markup::toHtml($p->body), 'audience' => $p->audience, 'kind' => $p->kind, 'status' => $p->status,
                'published_at' => $p->published_at, 'published_by' => $p->published_by ? ($names[$p->published_by] ?? null) : null,
                'updated_at' => $p->updated_at,
            ],
            'people' => $people,
            'stats' => $p->status === 'published' ? array_diff_key(self::ackStats($p), ['missing' => 1]) : null,
            'versions' => $versions,
            'audiences' => self::AUDIENCES,
        ];
    }

    public static function save(Ctx $c, ?int $id): array
    {
        $title = $c->str('title', 160, true, 'Title');
        $summary = $c->str('summary', 255);
        $body = $c->text('body', 60000);
        if (!$body) {
            throw ApiError::invalid('Write the policy text.', ['body' => 'The text is needed.']);
        }
        $audience = $c->pick('audience', array_keys(self::AUDIENCES)) ?? 'all';
        $kind = $c->pick('kind', ['policy', 'consent']) ?? 'policy';
        $db = Kernel::hr();
        $now = Kernel::now();
        if ($id) {
            $p = self::load($id);
            if ($p->status !== 'draft') {
                throw ApiError::conflict('Published versions can’t be changed. Make a new version instead.');
            }
            $db->table('hr_policies')->where('id', $id)->update(compact('title', 'summary', 'body', 'audience', 'kind') + ['updated_at' => $now]);
        } else {
            $code = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($title)) ?? 'policy', '_') ?: 'policy';
            $code = substr($code, 0, 34);
            $base = $code;
            for ($i = 2; $db->table('hr_policies')->where('code', $code)->exists(); $i++) {
                $code = $base . '_' . $i;
            }
            $id = $db->table('hr_policies')->insertGetId(compact('code', 'title', 'summary', 'body', 'audience', 'kind') + [
                'version' => 1, 'status' => 'draft', 'created_by' => $c->accountId(), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        Audit::log('policy.save', ['entity' => 'hr_policies', 'entity_id' => $id]);
        return ['id' => $id, 'message' => 'Draft saved.'];
    }

    public static function newVersion(Ctx $c, int $id): array
    {
        $p = self::load($id);
        $db = Kernel::hr();
        $draft = $db->table('hr_policies')->where('code', $p->code)->where('status', 'draft')->value('id');
        if ($draft) {
            return ['id' => (int) $draft, 'message' => 'There is already a draft of the next version.'];
        }
        $next = (int) $db->table('hr_policies')->where('code', $p->code)->max('version') + 1;
        $now = Kernel::now();
        $newId = $db->table('hr_policies')->insertGetId([
            'code' => $p->code, 'version' => $next, 'title' => $p->title, 'summary' => $p->summary, 'body' => $p->body,
            'audience' => $p->audience, 'kind' => $p->kind, 'status' => 'draft', 'created_by' => $c->accountId(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['id' => $newId, 'message' => 'Version ' . $next . ' started as a draft.'];
    }

    public static function publish(Ctx $c, int $id): array
    {
        $p = self::load($id);
        if ($p->status !== 'draft') {
            throw ApiError::conflict('Only a draft can be published.');
        }
        $db = Kernel::hr();
        $db->transaction(function () use ($db, $p, $c) {
            $db->table('hr_policies')->where('code', $p->code)->where('status', 'published')->update(['status' => 'retired', 'updated_at' => Kernel::now()]);
            $db->table('hr_policies')->where('id', $p->id)->update(['status' => 'published', 'published_at' => Kernel::now(), 'published_by' => $c->accountId(), 'updated_at' => Kernel::now()]);
        });
        $p->status = 'published';
        $ids = self::audienceIds($p);
        foreach ($ids as $eid) {
            Notify::employee($eid, 'policy.publish', ($p->kind === 'consent' ? 'Please read and give your consent: ' : 'Please read and sign: ') . $p->title . ($p->version > 1 ? ' (version ' . $p->version . ')' : ''),
                $p->summary, '#/me/policies/' . $p->id);
        }
        Audit::log('policy.publish', ['entity' => 'hr_policies', 'entity_id' => $p->id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' published "' . $p->title . '"' . ($p->version > 1 ? ' version ' . $p->version : '') . ' for ' . Fmt::plural(count($ids), 'person', 'people') . ' to sign']);
        return ['message' => 'Published. ' . Fmt::plural(count($ids), 'person', 'people') . (count($ids) === 1 ? ' has' : ' have') . ' been asked to sign it.'];
    }

    public static function remind(Ctx $c, int $id): array
    {
        $p = self::load($id);
        if ($p->status !== 'published') {
            throw ApiError::conflict('Publish it first.');
        }
        $missing = self::ackStats($p)['missing'];
        $withdrew = [];
        if ($p->kind === 'consent') {
            $withdrew = Kernel::hr()->table('hr_policy_acks')->where('policy_id', $p->id)->whereNotNull('withdrawn_at')
                ->pluck('employee_id')->map(fn($v) => (int) $v)->all();
            $missing = array_values(array_diff($missing, $withdrew));
        }
        $emails = Kernel::hr()->table('hr_accounts')->whereIn('employee_id', $missing ?: [0])->where('status', 'active')->pluck('email', 'employee_id')->all();
        foreach ($missing as $eid) {
            Notify::employee($eid, 'policy.remind', 'Reminder: please sign ' . $p->title, $p->summary, '#/me/policies/' . $p->id);
            if (isset($emails[$eid])) {
                $lines = ['Please read and sign "' . $p->title . '" in Trio HR. It takes about two minutes.'];
                Outbox::queue('policy-remind:' . $p->id . ':' . $eid . ':' . date('Ymd'), $emails[$eid], 'Please sign: ' . $p->title, implode("\n\n", $lines), Outbox::html('Please sign: ' . $p->title, $lines));
            }
        }
        Outbox::flushAfterResponse(25);
        Audit::log('policy.remind', ['entity' => 'hr_policies', 'entity_id' => $p->id, 'detail' => ['count' => count($missing)]]);
        $left = $withdrew ? ' ' . Fmt::plural(count($withdrew), 'person', 'people') . ' who withdrew their consent ' . (count($withdrew) === 1 ? 'was' : 'were') . ' left out.' : '';
        return ['message' => ($missing ? 'Reminded ' . Fmt::plural(count($missing), 'person', 'people') . '.' : 'Nobody else needs a reminder.') . $left];
    }

    // ---- staff ----------------------------------------------------------------------------

    /** Published policies this employee should see, newest first, with their state. */
    public static function mine(Ctx $c): array
    {
        $eid = (int) $c->employeeId();
        $out = [];
        foreach (Kernel::hr()->table('hr_policies')->where('status', 'published')->orderBy('title')->get() as $p) {
            if (!in_array($eid, self::audienceIds($p), true)) {
                continue;
            }
            $a = Kernel::hr()->table('hr_policy_acks')->where('policy_id', $p->id)->where('employee_id', $eid)->first();
            $out[] = [
                'id' => (int) $p->id, 'title' => $p->title, 'summary' => $p->summary, 'version' => (int) $p->version, 'kind' => $p->kind,
                'state' => !$a ? 'to_sign' : ($a->withdrawn_at ? 'withdrawn' : 'signed'), 'at' => $a ? ($a->withdrawn_at ?? $a->acked_at) : null,
            ];
        }
        usort($out, fn($a, $b) => [$a['state'] !== 'to_sign', $a['title']] <=> [$b['state'] !== 'to_sign', $b['title']]);
        return ['policies' => $out];
    }

    /** Policies an employee still has to sign (for their Home and the admin's Today). */
    public static function outstandingFor(int $employeeId): array
    {
        $out = [];
        foreach (Kernel::hr()->table('hr_policies')->where('status', 'published')->get() as $p) {
            if (in_array($employeeId, self::audienceIds($p), true)
                && !Kernel::hr()->table('hr_policy_acks')->where('policy_id', $p->id)->where('employee_id', $employeeId)->exists()) {
                $out[] = ['id' => (int) $p->id, 'title' => $p->title, 'kind' => $p->kind];
            }
        }
        return $out;
    }

    public static function read(Ctx $c, int $id): array
    {
        $p = self::load($id);
        $eid = (int) $c->employeeId();
        if ($p->status !== 'published' || !in_array($eid, self::audienceIds($p), true)) {
            throw ApiError::notFound('That policy');
        }
        $a = Kernel::hr()->table('hr_policy_acks')->where('policy_id', $p->id)->where('employee_id', $eid)->first();
        $name = Kernel::hr()->table('hr_employees')->where('id', $eid)->value('full_name');
        return [
            'id' => (int) $p->id, 'code' => $p->code, 'title' => $p->title, 'summary' => $p->summary, 'version' => (int) $p->version, 'kind' => $p->kind,
            'html' => Markup::toHtml($p->body), 'published_at' => $p->published_at, 'my_name' => $name,
            'ack' => $a ? ['at' => $a->acked_at, 'name' => $a->typed_name, 'withdrawn_at' => $a->withdrawn_at] : null,
        ];
    }

    public static function acknowledge(Ctx $c, int $id): array
    {
        $p = self::load($id);
        $eid = (int) $c->employeeId();
        if ($p->status !== 'published' || !in_array($eid, self::audienceIds($p), true)) {
            throw ApiError::notFound('That policy');
        }
        if (!$c->raw('agree')) {
            throw ApiError::invalid('Tick the box to confirm you have read it.', ['agree' => 'Tick to confirm.']);
        }
        $typed = (string) $c->str('typed_name', 120, true, 'Your name');
        $e = Kernel::hr()->table('hr_employees')->where('id', $eid)->first(['full_name', 'preferred_name']);
        $norm = fn($s) => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $s)));
        if (!in_array($norm($typed), [$norm($e->full_name), $norm($e->preferred_name)], true)) {
            throw ApiError::invalid('Type your full name exactly as it appears: ' . $e->full_name . '.', ['typed_name' => 'Type ' . $e->full_name . '.']);
        }
        $db = Kernel::hr();
        $existing = $db->table('hr_policy_acks')->where('policy_id', $p->id)->where('employee_id', $eid)->first();
        if ($existing && !$existing->withdrawn_at) {
            return ['message' => 'Already signed.'];
        }
        $row = ['typed_name' => $typed, 'acked_at' => Kernel::now(), 'ip' => substr(Ctx::clientIp(), 0, 45),
            'user_agent' => mb_substr($c->req->getHeaderLine('User-Agent'), 0, 255), 'withdrawn_at' => null];
        if ($existing) {
            $db->table('hr_policy_acks')->where('id', $existing->id)->update($row);
        } else {
            $db->table('hr_policy_acks')->insert($row + ['policy_id' => $p->id, 'employee_id' => $eid]);
        }
        Audit::log('policy.ack', ['employee_id' => $eid, 'entity' => 'hr_policies', 'entity_id' => $p->id,
            'summary' => Fmt::first($e->full_name) . ($p->kind === 'consent' ? ' gave consent: ' : ' signed ') . $p->title . ($p->version > 1 ? ' (v' . $p->version . ')' : '')]);
        return ['message' => $p->kind === 'consent' ? 'Thank you. Your consent is recorded.' : 'Thank you. Signed.'];
    }

    public static function withdraw(Ctx $c, int $id): array
    {
        $p = self::load($id);
        if ($p->kind !== 'consent') {
            throw ApiError::invalid('Only a consent can be withdrawn.');
        }
        if ($p->status !== 'published') {
            throw ApiError::conflict('That version is no longer in use, so there is nothing to withdraw.');
        }
        $eid = (int) $c->employeeId();
        $n = Kernel::hr()->table('hr_policy_acks')->where('policy_id', $p->id)->where('employee_id', $eid)->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => Kernel::now()]);
        if (!$n) {
            throw ApiError::conflict('There is no consent to withdraw.');
        }
        $name = (string) Kernel::hr()->table('hr_employees')->where('id', $eid)->value('full_name');
        Audit::log('policy.withdraw', ['employee_id' => $eid, 'entity' => 'hr_policies', 'entity_id' => $p->id,
            'summary' => Fmt::first($name) . ' withdrew their consent: ' . $p->title]);
        $night = $p->code === 'night_consent';
        Notify::admins('consent.withdrawn', $night ? $name . ' withdrew their night-work consent' : $name . ' withdrew their consent: ' . $p->title,
            $night ? 'Change their roster to day work where possible.' : null, '#/people/' . $eid);
        return ['message' => $night ? 'Your consent is withdrawn. HR has been told and will change your roster.' : 'Your consent is withdrawn. HR has been told.'];
    }
}
