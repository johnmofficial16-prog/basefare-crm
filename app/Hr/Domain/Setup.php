<?php

namespace App\Hr\Domain;

use App\Hr\Auth\Auth;
use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Clock;
use App\Hr\Support\Fmt;
use App\Hr\Support\Outbox;
use App\Hr\Support\Vault;

/**
 * Settings screens: company and centres, rule numbers, the law table, admins
 * and security, system health, the audit log and compliance dates.
 * Owner-only actions are guarded at the route.
 */
final class Setup
{
    // ---- overview -----------------------------------------------------------

    public static function overview(Ctx $c): array
    {
        $rules = [];
        foreach (Settings::RULES as $key => [$label, $help, $min, $max]) {
            $rules[] = ['key' => $key, 'label' => $label, 'help' => $help, 'min' => $min, 'max' => $max, 'value' => (int) Settings::get($key)];
        }
        return [
            'company'  => Settings::get('company'),
            'centres'  => Settings::get('centres'),
            'rules'    => $rules,
            'security' => Settings::get('security'),
            'is_owner' => $c->role() === 'owner',
        ];
    }

    public static function saveCompany(Ctx $c): array
    {
        $cur = Settings::get('company');
        $v = [
            'name'            => $c->str('name', 120, true, 'Company name'),
            'legal_name'      => $c->str('legal_name', 160, true, 'Registered name'),
            'cin'             => $c->str('cin', 21),
            'address'         => $c->text('address', 300),
            'tan'             => $c->str('tan', 10),
            'pan'             => $c->str('pan', 10),
            'signatory_name'  => $c->str('signatory_name', 120),
            'signatory_title' => $c->str('signatory_title', 120),
            'hr_email'        => $c->email('hr_email', false, 'HR email'),
            'phone'           => $c->phone('phone'),
        ];
        $errors = [];
        if ($v['cin'] && !preg_match('/^[LU]\d{5}[A-Z]{2}\d{4}[A-Z]{3}\d{6}$/', strtoupper($v['cin']))) {
            $errors['cin'] = 'A CIN looks like U63030PB2022PTC055278.';
        }
        if ($v['tan'] && !preg_match('/^[A-Z]{4}\d{5}[A-Z]$/', strtoupper($v['tan']))) {
            $errors['tan'] = 'A TAN looks like PTLT12345A.';
        }
        if ($v['pan'] && !preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', strtoupper($v['pan']))) {
            $errors['pan'] = 'A PAN looks like ABCDE1234F.';
        }
        if ($errors) {
            throw ApiError::invalid('Please check the highlighted details.', $errors);
        }
        foreach (['cin', 'tan', 'pan'] as $k) {
            $v[$k] = $v[$k] ? strtoupper($v[$k]) : null;
        }
        Settings::set('company', array_replace($cur, $v), $c->accountId());
        return ['message' => 'Company details saved.'];
    }

    public static function saveCentre(Ctx $c): array
    {
        $centres = Settings::get('centres');
        $code = $c->pick('code', array_keys($centres), true, 'Centre');
        $centres[$code] = [
            'name'     => $c->str('name', 60, true, 'Name'),
            'city'     => $c->str('city', 60),
            'state'    => $c->pick('state', ['Punjab', 'Jharkhand', 'Haryana', 'Chandigarh', 'Delhi', 'Uttar Pradesh', 'West Bengal', 'Maharashtra', 'Karnataka', 'Other'], false, 'State'),
            'address'  => $c->text('address', 300),
            'shop_reg' => $c->str('shop_reg', 60),
        ];
        Settings::set('centres', $centres, $c->accountId());
        return ['message' => $centres[$code]['name'] . ' saved.'];
    }

    public static function saveRules(Ctx $c): array
    {
        $n = 0;
        foreach (Settings::RULES as $key => [$label, , $min, $max]) {
            if ($c->has($key)) {
                $val = $c->intVal($key, $min, $max, true, $label);
                if ((int) Settings::get($key) !== $val) {
                    Settings::set($key, $val, $c->accountId());
                    $n++;
                }
            }
        }
        return ['message' => $n ? 'Saved.' : 'Nothing changed.'];
    }

    public static function saveSecurity(Ctx $c): array
    {
        $cur = Settings::get('security');
        foreach (['staff_email_code', 'letters_need_check'] as $k) {
            if ($c->has($k)) {
                $cur[$k] = (bool) $c->raw($k);
            }
        }
        Settings::set('security', $cur, $c->accountId());
        Audit::log('security.update', ['summary' => Fmt::first((string) $c->auth['display_name']) . ' changed the sign-in and letter safety settings', 'detail' => $cur]);
        return ['message' => 'Security settings saved.', 'security' => $cur];
    }

    // ---- the law table --------------------------------------------------------

    public static function law(): array
    {
        // Group by rule, newest date first (ties: the later entry wins), whatever each row's label says.
        $rows = Kernel::hr()->table('hr_law_values')->orderBy('key')->orderBy('scope')->orderByDesc('effective_from')->orderByDesc('id')->get();
        $groups = [];
        foreach ($rows as $r) {
            $k = $r->key . '|' . $r->scope;
            $groups[$k] ??= ['key' => $r->key, 'label' => $r->label, 'scope' => $r->scope, 'current' => null, 'history' => []];
            $upcoming = $r->status === 'in_force' && $r->effective_from > date('Y-m-d');
            $item = [
                'id' => (int) $r->id, 'value' => $r->value, 'unit' => $r->unit, 'from' => $r->effective_from,
                'status' => $upcoming ? 'upcoming' : $r->status, 'source' => $r->source, 'note' => $r->note, 'added' => $r->created_at,
            ];
            // Rows come newest date first: the first in-force row already started is the one that applies today.
            if ($groups[$k]['current'] === null && $r->status === 'in_force' && !$upcoming) {
                $groups[$k]['current'] = $item;
            } else {
                if ($r->status === 'in_force' && !$upcoming) {
                    $item['status'] = 'superseded'; // an older value a later one has replaced
                }
                $groups[$k]['history'][] = $item;
            }
        }
        $groups = array_values($groups);
        usort($groups, fn($a, $b) => [$a['scope'], $a['label']] <=> [$b['scope'], $b['label']]);
        return ['groups' => $groups];
    }

    public static function addLaw(Ctx $c): array
    {
        $key = $c->str('key', 60, true, 'Key');
        if (!preg_match('/^[a-z][a-z0-9_]{2,59}$/', (string) $key)) {
            throw ApiError::invalid('Please check the form.', ['key' => 'Use lower-case letters, digits and _ only.']);
        }
        $row = [
            'key'            => $key,
            'label'          => $c->str('label', 160, true, 'What it is'),
            'value'          => $c->str('value', 60, true, 'Value'),
            'unit'           => $c->str('unit', 30),
            'scope'          => $c->str('scope', 40) ?? 'India',
            'effective_from' => $c->date('effective_from', true, 'Applies from'),
            'status'         => $c->pick('status', ['in_force', 'draft'], false) ?? 'in_force',
            'source'         => $c->str('source', 255, true, 'Source'),
            'note'           => $c->str('note', 255),
            'created_by'     => $c->accountId(),
            'created_at'     => Kernel::now(),
        ];
        // Nothing older is touched: which value applies on a given day is worked
        // out from the dates, so today's rate stays in force until a new one starts.
        $id = Kernel::hr()->table('hr_law_values')->insertGetId($row);
        Audit::log('law.add', ['entity' => 'hr_law_values', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' recorded a new value for "' . $row['label'] . '" from ' . Fmt::day($row['effective_from'])]);
        return ['id' => $id, 'message' => 'Recorded. Earlier values stay on file for past months.'];
    }

    // ---- admins ------------------------------------------------------------------

    public static function admins(): array
    {
        $names = Kernel::hr()->table('hr_accounts')->pluck('display_name', 'id')->all();
        return ['admins' => Kernel::hr()->table('hr_accounts')->whereIn('role', ['owner', 'admin'])->orderBy('role', 'desc')->orderBy('display_name')->get()
            ->map(fn($a) => [
                'id' => (int) $a->id, 'name' => $a->display_name, 'email' => $a->email, 'role' => $a->role, 'status' => $a->status,
                'initials' => Fmt::initials($a->display_name), 'last_login' => $a->last_login_at,
                'invited_at' => $a->invited_at, 'is_employee' => $a->employee_id !== null,
            ])->all()];
    }

    public static function addAdmin(Ctx $c): array
    {
        $name = $c->str('name', 120, true, 'Name');
        $email = $c->email('email', true, 'Email');
        $role = $c->pick('role', ['owner', 'admin'], true, 'Role');
        $db = Kernel::hr();
        $existing = $db->table('hr_accounts')->where('email', $email)->first();
        if ($existing) {
            throw ApiError::conflict($existing->role === 'employee'
                ? $existing->display_name . ' already has a staff account with that email. Give admins their own email address.'
                : 'There is already an admin with that email.');
        }
        $now = Kernel::now();
        $id = $db->table('hr_accounts')->insertGetId([
            'employee_id' => null, 'display_name' => $name, 'email' => $email, 'role' => $role, 'status' => 'invited',
            'invited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $link = Auth::sendAccountInvite($id, $c->accountId());
        Audit::log('admin.add', ['entity' => 'hr_accounts', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' invited ' . $name . ' as ' . ($role === 'owner' ? 'an owner' : 'an admin')]);
        Outbox::flushAfterResponse(2);
        return $link
            ? ['id' => $id, 'message' => $name . ' is added. Give them their set-up link.', 'link' => ['link' => $link, 'setup' => true, 'name' => Fmt::first($name), 'valid' => '7 days']]
            : ['id' => $id, 'message' => 'Invitation sent to ' . $email . '.'];
    }

    public static function updateAdmin(Ctx $c, int $id): array
    {
        $db = Kernel::hr();
        $a = $db->table('hr_accounts')->where('id', $id)->whereIn('role', ['owner', 'admin'])->first();
        if (!$a) {
            throw ApiError::notFound('That admin');
        }
        if ($id === $c->accountId()) {
            throw ApiError::forbidden("You can't change your own access. Ask another owner.");
        }
        $role = $c->pick('role', ['owner', 'admin']);
        $status = $c->pick('status', ['active', 'disabled']);
        $owners = $db->table('hr_accounts')->where('role', 'owner')->where('status', 'active')->count();
        $losingOwner = $a->role === 'owner' && $a->status === 'active' && (($role && $role !== 'owner') || $status === 'disabled');
        if ($losingOwner && $owners <= 1) {
            throw ApiError::conflict('Trio HR always needs at least one active owner.');
        }
        $set = [];
        if ($role && $role !== $a->role) $set['role'] = $role;
        $message = 'Saved.';
        if ($status === 'disabled' && $a->status !== 'disabled') {
            $set['status'] = 'disabled'; // an invite not yet accepted can be switched off too; its link stops working
        } elseif ($status === 'active' && $a->status === 'disabled') {
            // Someone who never set a password goes back to "invited" and needs the invitation again.
            $set['status'] = $a->password_hash ? 'active' : 'invited';
            if (!$a->password_hash) {
                $message = 'Switched back on. They never set a password, so send the invitation again.';
            }
        }
        if (!$set) {
            return ['message' => 'Nothing changed.'];
        }
        $db->table('hr_accounts')->where('id', $id)->update($set + ['updated_at' => Kernel::now()]);
        if (($set['status'] ?? null) === 'disabled') {
            Auth::switchOff($id);
        }
        Audit::log('admin.update', ['entity' => 'hr_accounts', 'entity_id' => $id, 'detail' => $set,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' changed ' . $a->display_name . "'s access (" . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($set), $set)) . ')']);
        return ['message' => $message];
    }

    // ---- health -------------------------------------------------------------------

    public static function health(): array
    {
        $db = Kernel::hr();
        $counts = $db->table('hr_outbox')->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status')->all();
        $recent = $db->table('hr_outbox')->orderByDesc('id')->limit(15)->get(['id', 'to_email', 'subject', 'status', 'attempts', 'last_error', 'created_at', 'sent_at'])
            ->map(fn($m) => (array) $m)->all();
        $dbNow = $db->selectOne('SELECT NOW() AS t, @@session.time_zone AS tz')->t ?? null;
        $beat = Settings::get('heartbeat.outbox');
        $storage = self::storageDir();
        return [
            'checks' => [
                ['label' => 'Database', 'ok' => true, 'text' => 'Connected. Database clock ' . ($dbNow ? Fmt::time($dbNow) : 'unknown') . ', Trio HR clock ' . date('g:i a') . ' IST.'],
                ['label' => 'Private-details key', 'ok' => Vault::available(), 'text' => Vault::available() ? 'Set up. PAN, Aadhaar and bank details can be stored.' : 'Missing. Private details and documents cannot be opened.'],
                ['label' => 'Email sending', 'ok' => Kernel::env('HR_SMTP_HOST') !== null, 'text' => Kernel::env('HR_SMTP_HOST') !== null ? 'Set up. Mail goes out through the outbox.' : 'Not set up. Emails wait in the outbox.'],
                ['label' => 'Document storage', 'ok' => is_dir($storage) && is_writable($storage), 'text' => is_dir($storage) && is_writable($storage) ? 'Ready.' : 'The storage folder is missing or not writable.'],
                ['label' => 'Background job (outbox)', 'ok' => is_string($beat) && strtotime($beat) > time() - 1800,
                    'text' => is_string($beat) ? 'Last ran ' . Fmt::ago($beat) . ', ' . Fmt::time($beat) . '.' : 'Not scheduled yet. Emails are still sent straight after each action.'],
                ['label' => 'Server', 'ok' => true, 'text' => 'PHP ' . PHP_VERSION . (Kernel::isLocal() ? ' · local preview' : '')],
            ],
            'outbox' => ['counts' => [
                'queued' => (int) (($counts['queued'] ?? 0) + ($counts['sending'] ?? 0)), 'sent' => (int) ($counts['sent'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0),
                'retryable' => $db->table('hr_outbox')->where('status', 'failed')->where('body_text', '!=', '')->count(),
            ], 'recent' => $recent],
        ];
    }

    public static function retryOutbox(Ctx $c): array
    {
        // Only mail that still has its text: anything given up on (and wiped) stays failed.
        Kernel::hr()->table('hr_outbox')->where('status', 'failed')->where('body_text', '!=', '')->update(['status' => 'queued', 'attempts' => 0]);
        $sent = Outbox::flush(20);
        return ['message' => $sent ? 'Sent ' . Fmt::plural($sent, 'email') . '.' : 'Nothing could be sent yet. Check the email settings.'];
    }

    public static function storageDir(): string
    {
        return rtrim((string) Kernel::env('HR_STORAGE_DIR', dirname(__DIR__, 3) . '/storage/hr'), '/\\');
    }

    // ---- audit log --------------------------------------------------------------------

    public static function audit(Ctx $c): array
    {
        $db = Kernel::hr();
        $q = $db->table('hr_audit_log as l')->leftJoin('hr_accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('hr_employees as e', 'e.id', '=', 'l.employee_id')
            ->select('l.*', 'a.display_name as who', 'e.full_name as about');
        $kind = $c->query('kind', 20);
        $groups = [
            'views'    => ['secret.view', 'document.view'],
            'signins'  => ['signin.ok', 'signin.fail', 'signout', 'signin.code_fail', 'reauth.fail', 'password.reset', 'password.change', 'device.signout'],
            'changes'  => ['employee.create', 'employee.update', 'secret.update', 'settings.update', 'security.update', 'law.add', 'admin.add', 'admin.update'],
            'requests' => ['request.create', 'request.approved', 'request.partly_approved', 'request.sent_back', 'request.withdraw'],
        ];
        if ($kind && isset($groups[$kind])) {
            $q->whereIn('l.action', $groups[$kind]);
        }
        if ($emp = $c->query('employee', 10)) {
            $q->where('l.employee_id', (int) $emp);
        }
        if ($from = $c->query('from', 10)) {
            $q->where('l.at', '>=', $from . ' 00:00:00');
        }
        $page = max(1, (int) ($c->query('page', 4) ?? 1));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('l.id')->offset(($page - 1) * 50)->limit(50)->get();
        $labels = [
            'secret.view' => 'Looked at private details', 'secret.update' => 'Changed private details', 'document.view' => 'Opened a document',
            'signin.ok' => 'Signed in', 'signin.fail' => 'Wrong password', 'signout' => 'Signed out',
            'signin.code_fail' => 'Wrong emailed code', 'reauth.fail' => 'Wrong password (re-check)', 'password.reset' => 'Reset password',
            'password.change' => 'Changed password', 'device.signout' => 'Signed out a device', 'employee.create' => 'Added a person',
            'employee.update' => 'Edited a record', 'settings.update' => 'Changed settings', 'security.update' => 'Changed security',
            'law.add' => 'Recorded a law value', 'admin.add' => 'Invited an admin', 'admin.update' => 'Changed admin access',
            'crm.notify' => 'Posted to a CRM bell',
        ];
        return [
            'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / 50),
            'rows' => $rows->map(function ($r) use ($labels) {
                $d = json_decode((string) $r->detail, true) ?: [];
                $what = $r->summary ?: ($labels[$r->action] ?? ucfirst(str_replace(['.', '_'], ' ', $r->action)));
                if ($r->action === 'secret.view' && isset($d['field'])) {
                    $what = 'Looked at ' . Fmt::lower(People::SECRET_LABELS[$d['field']] ?? 'a private detail');
                }
                return [
                    'id' => (int) $r->id, 'at' => $r->at, 'who' => $r->who ?? 'System', 'what' => $what, 'action' => $r->action,
                    'about' => $r->about, 'employee_id' => $r->employee_id ? (int) $r->employee_id : null, 'ip' => $r->ip,
                    'sensitive' => in_array($r->action, ['secret.view', 'document.view', 'secret.update'], true),
                ];
            })->all(),
        ];
    }

    // ---- compliance dates --------------------------------------------------------------

    public static function saveCompliance(Ctx $c, ?int $id): array
    {
        $row = [
            'title'  => $c->str('title', 160, true, 'What'),
            'due_on' => $c->date('due_on', true, 'Due date'),
            'note'   => $c->str('note', 255),
            'source' => $c->str('source', 255),
        ];
        $db = Kernel::hr();
        if ($id) {
            $n = $db->table('hr_compliance_items')->where('id', $id)->update($row + ($c->has('status') ? ['status' => $c->pick('status', ['open', 'not_applicable'], true)] : []));
            if (!$n && !$db->table('hr_compliance_items')->where('id', $id)->exists()) {
                throw ApiError::notFound('That date');
            }
        } else {
            $id = $db->table('hr_compliance_items')->insertGetId($row + ['status' => 'open', 'created_at' => Kernel::now()]);
        }
        Audit::log('compliance.save', ['entity' => 'hr_compliance_items', 'entity_id' => $id,
            'summary' => Fmt::first((string) $c->auth['display_name']) . ' saved the compliance date "' . $row['title'] . '" (' . Fmt::day($row['due_on']) . ')']);
        return ['id' => $id, 'message' => 'Saved.'];
    }
}
