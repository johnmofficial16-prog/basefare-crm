<?php

namespace App\Hr\Domain;

use App\Hr\Auth\Auth;
use App\Hr\Crm\CrmGateway;
use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;
use App\Hr\Support\Clock;
use App\Hr\Support\Fmt;
use App\Hr\Support\Outbox;
use App\Hr\Support\Vault;

/**
 * People: the directory, each person's full record, adding and editing
 * people, and the private-details vault (PAN, Aadhaar, bank).
 */
final class People
{
    public const STATUS_LABELS = ['joining' => 'Joining', 'active' => 'Working', 'notice' => 'On notice', 'left' => 'Left'];
    public const TYPE_LABELS = ['permanent' => 'Permanent', 'fixed_term' => 'Fixed term', 'trainee' => 'Trainee'];
    public const GENDER_LABELS = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'undisclosed' => 'Prefer not to say'];
    public const SECRET_LABELS = [
        'pan' => 'PAN', 'aadhaar' => 'Aadhaar', 'bank_account' => 'Bank account number',
        'bank_ifsc' => 'IFSC', 'bank_name' => 'Bank', 'bank_holder' => 'Name on the account',
    ];

    /** Fields an admin can edit, with the label used in history and diffs. */
    public const FIELDS = [
        'full_name' => 'Full name', 'preferred_name' => 'Preferred name', 'work_email' => 'Work email',
        'personal_email' => 'Personal email', 'phone' => 'Phone', 'date_of_birth' => 'Date of birth', 'gender' => 'Gender',
        'designation' => 'Job title', 'team' => 'Team', 'centre' => 'Centre', 'employment_type' => 'Employment type',
        'contract_end' => 'Contract ends', 'date_joined' => 'Joined', 'probation_end' => 'Probation ends',
        'confirmed_on' => 'Confirmed on', 'notice_days' => 'Notice period (days)', 'reports_to_id' => 'Team leader',
        'status' => 'Status', 'last_working_day' => 'Last working day', 'address' => 'Address',
        'emergency_name' => 'Emergency contact', 'emergency_relation' => 'Relationship', 'emergency_phone' => 'Emergency phone',
        'crm_user_id' => 'CRM login', 'pan_status' => 'PAN checked', 'uan' => 'UAN (PF)', 'esic_ip' => 'ESIC number',
    ];

    /** Changes to these go on the dated job timeline. */
    private const JOB_FIELDS = ['designation', 'team', 'centre', 'employment_type', 'reports_to_id', 'status', 'probation_end', 'confirmed_on', 'contract_end', 'last_working_day'];

    // ---- directory ------------------------------------------------------------

    public static function list(Ctx $c): array
    {
        $db = Kernel::hr();
        $q = $db->table('hr_employees as e')
            ->leftJoin('hr_employees as tl', 'tl.id', '=', 'e.reports_to_id')
            ->leftJoin('hr_accounts as a', 'a.employee_id', '=', 'e.id')
            ->select('e.*', 'tl.full_name as tl_name', 'a.status as account_status', 'a.last_login_at');

        $status = $c->query('status') ?? 'current';
        if ($status === 'current') {
            $q->whereIn('e.status', ['joining', 'active', 'notice']);
        } elseif (isset(self::STATUS_LABELS[$status])) {
            $q->where('e.status', $status);
        }
        if (($centre = $c->query('centre')) && isset(Settings::get('centres')[$centre])) {
            $q->where('e.centre', $centre);
        }
        if ($team = $c->query('team', 80)) {
            $q->where('e.team', $team);
        }
        if ($term = $c->query('q', 80)) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
            $q->where(fn($w) => $w->where('e.full_name', 'like', $like)->orWhere('e.preferred_name', 'like', $like)
                ->orWhere('e.employee_code', 'like', $like)->orWhere('e.work_email', 'like', $like)
                ->orWhere('e.phone', 'like', $like)->orWhere('e.designation', 'like', $like));
        }
        $rows = $q->orderBy('e.full_name')->limit(500)->get();

        $floor = Floor::tonight()['people'];
        $people = [];
        $hrCounts = ['not_invited' => 0, 'invited' => 0, 'joined' => 0];
        foreach ($rows as $r) {
            $hr = match ($r->account_status) {
                'active' => 'joined', 'invited' => 'invited', 'disabled' => 'disabled', default => 'not_invited',
            };
            if (isset($hrCounts[$hr])) {
                $hrCounts[$hr]++;
            }
            $hrFilter = $c->query('hr');
            if ($hrFilter === 'not_joined' ? in_array($hr, ['joined', 'disabled'], true) : ($hrFilter && $hrFilter !== $hr)) {
                continue;
            }
            $people[] = self::card($r, $hr, $r->crm_user_id ? ($floor[(int) $r->crm_user_id]['state'] ?? null) : null);
        }

        $teams = $db->table('hr_employees')->whereNotNull('team')->where('status', '!=', 'left')->distinct()->orderBy('team')->pluck('team')->all();
        $linked = $db->table('hr_employees')->whereNotNull('crm_user_id')->pluck('crm_user_id')->all();
        $crmWaiting = Kernel::crm()->table('users')->whereNull('deleted_at')->where('status', 'active')->where('is_dev', 0)->where('role', '!=', 'admin')
            ->when($linked, fn($q) => $q->whereNotIn('id', $linked))->count();
        return ['people' => $people, 'hr_counts' => $hrCounts, 'teams' => $teams, 'centres' => self::centreOptions(), 'crm_waiting' => $crmWaiting];
    }

    private static function card(object $r, string $hr, ?string $tonight): array
    {
        return [
            'id'          => (int) $r->id,
            'code'        => $r->employee_code,
            'name'        => $r->full_name,
            'initials'    => Fmt::initials($r->full_name),
            'designation' => $r->designation,
            'team'        => $r->team,
            'centre'      => $r->centre,
            'centre_name' => Settings::centreName($r->centre),
            'status'      => $r->status,
            'status_label' => self::STATUS_LABELS[$r->status] ?? $r->status,
            'joined'      => $r->date_joined,
            'tenure'      => Fmt::tenure($r->date_joined, $r->status === 'left' ? $r->last_working_day : null),
            'team_leader' => $r->tl_name ?? null,
            'hr'          => $hr,
            'tonight'     => $tonight,
            'email'       => $r->work_email ?: $r->personal_email,
            'phone'       => $r->phone,
            'complete'    => $r->profile_completed_at !== null,
            'reports_to_id' => $r->reports_to_id ? (int) $r->reports_to_id : null,
        ];
    }

    public static function centreOptions(): array
    {
        $out = [];
        foreach (Settings::get('centres') as $code => $c) {
            $out[] = ['code' => $code, 'name' => $c['name'], 'state' => $c['state']];
        }
        return $out;
    }

    /** Lists for the add/edit forms. */
    public static function formMeta(?int $exceptId = null): array
    {
        $db = Kernel::hr();
        $leaders = $db->table('hr_employees')->whereIn('status', ['active', 'notice'])
            ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
            ->orderBy('full_name')->get(['id', 'full_name', 'designation'])
            ->map(fn($e) => ['id' => (int) $e->id, 'name' => $e->full_name, 'designation' => $e->designation])->all();
        $linked = $db->table('hr_employees')->whereNotNull('crm_user_id')
            ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))->pluck('crm_user_id')->map(fn($v) => (int) $v)->all();
        return [
            'centres'      => self::centreOptions(),
            'leaders'      => $leaders,
            'designations' => $db->table('hr_employees')->whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation')->all(),
            'teams'        => $db->table('hr_employees')->whereNotNull('team')->distinct()->orderBy('team')->pluck('team')->all(),
            'crm_users'    => CrmGateway::unlinkedUsers($linked),
            'types'        => self::TYPE_LABELS,
            'statuses'     => self::STATUS_LABELS,
            'genders'      => self::GENDER_LABELS,
        ];
    }

    // ---- one person's record -------------------------------------------------

    public static function get(int $id): array
    {
        $db = Kernel::hr();
        $e = $db->table('hr_employees')->where('id', $id)->first();
        if (!$e) {
            throw ApiError::notFound('That person');
        }
        $tl = $e->reports_to_id ? $db->table('hr_employees')->where('id', $e->reports_to_id)->first(['id', 'full_name', 'designation']) : null;
        $reports = $db->table('hr_employees')->where('reports_to_id', $id)->whereIn('status', ['joining', 'active', 'notice'])
            ->orderBy('full_name')->get(['id', 'full_name', 'designation'])
            ->map(fn($r) => ['id' => (int) $r->id, 'name' => $r->full_name, 'initials' => Fmt::initials($r->full_name), 'designation' => $r->designation])->all();
        $account = $db->table('hr_accounts')->where('employee_id', $id)->first(['status', 'role', 'email', 'last_login_at', 'invited_at']);

        // CRM link
        $crm = null;
        $tonight = null;
        if ($e->crm_user_id) {
            $u = CrmGateway::users([(int) $e->crm_user_id])[(int) $e->crm_user_id] ?? null;
            if ($u) {
                $lead = $u->reports_to_id ? (CrmGateway::users([(int) $u->reports_to_id])[(int) $u->reports_to_id]->name ?? null) : null;
                $crm = [
                    'id' => (int) $u->id, 'name' => $u->name, 'email' => $u->email,
                    'role' => CrmGateway::ROLE_LABELS[$u->role] ?? ucfirst($u->role),
                    'status' => $u->deleted_at ? 'deleted' : $u->status, 'lead' => $lead,
                    'grace' => (int) $u->grace_period_mins, 'last_activity' => $u->last_activity_at,
                ];
                $tonight = Floor::tonight()['people'][(int) $u->id] ?? null;
            }
        }

        // Private details: what's on file, never the values.
        $secrets = $db->table('hr_employee_secrets')->where('employee_id', $id)->get()->keyBy('field');
        $private = [];
        foreach (self::SECRET_LABELS as $field => $label) {
            $s = $secrets[$field] ?? null;
            $private[$field] = ['label' => $label, 'set' => (bool) $s, 'hint' => $s->hint ?? null, 'updated_at' => $s->updated_at ?? null];
        }

        $person = (array) $e;
        $person['id'] = (int) $e->id;
        $person['initials'] = Fmt::initials($e->full_name);
        $person['first'] = Fmt::first($e->preferred_name ?: $e->full_name);
        $person['centre_name'] = Settings::centreName($e->centre);
        $person['status_label'] = self::STATUS_LABELS[$e->status] ?? $e->status;
        $person['type_label'] = self::TYPE_LABELS[$e->employment_type] ?? $e->employment_type;
        $person['gender_label'] = $e->gender ? (self::GENDER_LABELS[$e->gender] ?? null) : null;
        $person['tenure'] = Fmt::tenure($e->date_joined, $e->status === 'left' ? $e->last_working_day : null);
        $person['team_leader'] = $tl ? ['id' => (int) $tl->id, 'name' => $tl->full_name, 'designation' => $tl->designation] : null;
        $person['reports'] = $reports;
        $person['missing'] = self::missing($e, $private);

        return [
            'person'     => $person,
            'account'    => $account ? (array) $account : null,
            'crm'        => $crm,
            'tonight'    => $tonight,
            'private'    => $private,
            'vault'      => Vault::available(),
            'leave'      => Leave::balances($id),
            'requests'   => self::requests($id),
            'timeline'   => self::timeline($id),
            'attendance' => Attendance::personStrip($e),
            'sales'      => $e->crm_user_id ? CrmGateway::sales((int) $e->crm_user_id) : null,
            'mail'       => $db->table('hr_threads')->where('employee_id', $id)->orderByDesc('last_at')->limit(6)
                ->get(['id', 'subject', 'status', 'private', 'last_from', 'last_at'])
                ->map(fn($t) => ['id' => (int) $t->id, 'subject' => $t->private ? 'Private complaint' : $t->subject, 'status' => $t->status,
                    'waiting' => $t->status === 'open' && $t->last_from === 'staff', 'at' => $t->last_at])->all(),
            'access'     => self::accessLog($id),
            'documents'  => Documents::forEmployee($id),
            'doc_kinds'  => Documents::KINDS,
            'letters'    => Kernel::hr()->table('hr_letters as l')->join('hr_letter_templates as t', 't.code', '=', 'l.template_code')
                ->where('l.employee_id', $id)->orderByDesc('l.updated_at')->get(['l.id', 'l.number', 'l.status', 'l.issued_at', 'l.ack_at', 'l.updated_at', 't.name'])
                ->map(fn($l) => ['id' => (int) $l->id, 'number' => $l->number, 'status' => $l->status, 'status_label' => Letters::STATUS_LABELS[$l->status],
                    'name' => $l->name, 'issued_at' => $l->issued_at, 'ack_at' => $l->ack_at, 'updated_at' => $l->updated_at])->all(),
        ];
    }

    /** What a complete record still lacks, in plain words. */
    private static function missing(object $e, array $private): array
    {
        $m = [];
        if (!$e->work_email && !$e->personal_email) $m[] = 'an email address';
        if (!$e->phone) $m[] = 'a phone number';
        if (!$e->date_of_birth) $m[] = 'date of birth';
        if (!$e->emergency_phone) $m[] = 'an emergency contact';
        if (!$private['pan']['set']) $m[] = 'PAN';
        if (!$private['bank_account']['set']) $m[] = 'bank details';
        return $m;
    }

    private static function requests(int $id): array
    {
        return Kernel::hr()->table('hr_requests')->where('employee_id', $id)->orderByDesc('created_at')->limit(12)->get()
            ->map(fn($r) => [
                'id' => (int) $r->id, 'type' => $r->type, 'type_label' => Approvals::TYPE_LABELS[$r->type] ?? $r->type,
                'summary' => $r->summary, 'status' => $r->status, 'status_label' => Approvals::STATUS_LABELS[$r->status] ?? $r->status,
                'created_at' => $r->created_at, 'decided_at' => $r->decided_at,
            ])->all();
    }

    private static function timeline(int $id): array
    {
        $db = Kernel::hr();
        $names = $db->table('hr_accounts')->pluck('display_name', 'id')->all();
        $items = [];
        foreach ($db->table('hr_employee_history')->where('employee_id', $id)->orderByDesc('at')->limit(40)->get() as $h) {
            $items[] = ['at' => $h->at, 'on' => $h->effective_on, 'kind' => $h->kind, 'text' => $h->summary, 'by' => $names[$h->by_account_id] ?? null];
        }
        usort($items, fn($a, $b) => strcmp(($b['on'] ?? substr($b['at'], 0, 10)) . $b['at'], ($a['on'] ?? substr($a['at'], 0, 10)) . $a['at']));
        return $items;
    }

    private static function accessLog(int $id): array
    {
        $db = Kernel::hr();
        $names = $db->table('hr_accounts')->pluck('display_name', 'id')->all();
        return $db->table('hr_audit_log')->where('employee_id', $id)->whereIn('action', ['secret.view', 'secret.update'])
            ->orderByDesc('at')->limit(10)->get()->map(function ($a) use ($names) {
                $d = json_decode((string) $a->detail, true) ?: [];
                return [
                    'at' => $a->at, 'who' => $names[$a->account_id] ?? 'Someone',
                    'what' => ($a->action === 'secret.view' ? 'Viewed ' : 'Changed ') . (self::SECRET_LABELS[$d['field'] ?? ''] ?? 'a private detail'),
                ];
            })->all();
    }

    /** This month's nights from the CRM, plus a strip of the last 14. */
    /** How this person punches: the CRM clock-in or the Trio HR button (null = from their CRM role). */
    public static function setPunchVia(\App\Hr\Http\Ctx $c, int $id): array
    {
        $e = Kernel::hr()->table('hr_employees')->where('id', $id)->first();
        if (!$e) {
            throw ApiError::notFound('That person');
        }
        $via = $c->pick('via', ['crm', 'hr', 'auto'], true, 'How they punch');
        if ($via === 'crm' && !$e->crm_user_id) {
            throw ApiError::invalid('They have no CRM login, so their punch has to be the Trio HR button.');
        }
        Kernel::hr()->table('hr_employees')->where('id', $id)->update(['punch_via' => $via === 'auto' ? null : $via, 'updated_at' => Kernel::now()]);
        $e = Kernel::hr()->table('hr_employees')->where('id', $id)->first();
        $info = Attendance::viaFor($e);
        Audit::log('person.punch_via', ['employee_id' => $id, 'detail' => ['via' => $via],
            'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' set ' . Fmt::first($e->full_name) . "'s punch to: " . Fmt::lower($info['label'])]);
        return ['message' => Fmt::first($e->full_name) . ' now punches with: ' . Fmt::lower($info['label']) . '.', 'via' => $info];
    }

    // ---- add / edit -----------------------------------------------------------

    /** Read and validate the editable fields present in the request. */
    private static function readFields(Ctx $c, ?object $current): array
    {
        $centres = array_keys(Settings::get('centres'));
        $v = [];
        $map = [
            // Fields a record can't do without are required whenever they are sent (on edit, only sent fields are read).
            'full_name'          => fn() => $c->str('full_name', 120, true, 'Full name'),
            'preferred_name'     => fn() => $c->str('preferred_name', 60),
            'work_email'         => fn() => $c->email('work_email', false, 'Work email'),
            'personal_email'     => fn() => $c->email('personal_email', false, 'Personal email'),
            'phone'              => fn() => $c->phone('phone'),
            'date_of_birth'      => fn() => $c->date('date_of_birth', false, 'Date of birth'),
            'gender'             => fn() => $c->pick('gender', array_keys(self::GENDER_LABELS)),
            'designation'        => fn() => $c->str('designation', 80, true, 'Job title'),
            'team'               => fn() => $c->str('team', 80),
            'centre'             => fn() => $c->pick('centre', $centres, true, 'Centre'),
            'employment_type'    => fn() => $current ? $c->pick('employment_type', array_keys(self::TYPE_LABELS), true, 'Employment type')
                : ($c->pick('employment_type', array_keys(self::TYPE_LABELS)) ?? 'permanent'),
            'contract_end'       => fn() => $c->date('contract_end', false, 'Contract end'),
            'date_joined'        => fn() => $c->date('date_joined', true, 'Joining date'),
            'probation_end'      => fn() => $c->date('probation_end', false, 'Probation end'),
            'confirmed_on'       => fn() => $c->date('confirmed_on', false, 'Confirmation date'),
            'notice_days'        => fn() => $c->intVal('notice_days', 0, 180, false, 'Notice period'),
            'reports_to_id'      => fn() => $c->intVal('reports_to_id', 1, PHP_INT_MAX, false, 'Team leader'),
            'status'             => fn() => $c->pick('status', array_keys(self::STATUS_LABELS), $current !== null, 'Status'),
            'last_working_day'   => fn() => $c->date('last_working_day', false, 'Last working day'),
            'address'            => fn() => $c->text('address', 500),
            'emergency_name'     => fn() => $c->str('emergency_name', 120),
            'emergency_relation' => fn() => $c->str('emergency_relation', 40),
            'emergency_phone'    => fn() => $c->phone('emergency_phone', 'Emergency phone'),
            'crm_user_id'        => fn() => $c->intVal('crm_user_id', 1, PHP_INT_MAX, false, 'CRM login'),
            'pan_status'         => fn() => $current ? $c->pick('pan_status', ['not_checked', 'valid', 'inoperative'], true, 'PAN check')
                : ($c->pick('pan_status', ['not_checked', 'valid', 'inoperative']) ?? 'not_checked'),
            'uan'                => fn() => ($v = $c->str('uan', 12)) !== null && !preg_match('/^\d{12}$/', $v)
                ? throw ApiError::invalid('Please check the UAN.', ['uan' => 'A UAN has 12 digits.']) : $v,
            'esic_ip'            => fn() => ($v = $c->str('esic_ip', 17)) !== null && !preg_match('/^\d{10,17}$/', $v)
                ? throw ApiError::invalid('Please check the ESIC number.', ['esic_ip' => 'An ESIC number has 10 to 17 digits.']) : $v,
        ];
        $errors = [];
        foreach ($map as $field => $read) {
            if ($current !== null && !$c->has($field)) {
                continue;
            }
            try {
                $v[$field] = $read();
            } catch (ApiError $e) {
                $errors += $e->fields ?: [$field => $e->getMessage()];
            }
        }
        if ($errors) {
            throw ApiError::invalid('Please check the highlighted fields.', $errors);
        }
        if ($current === null && !isset($v['status'])) {
            $v['status'] = ($v['date_joined'] ?? '') > date('Y-m-d') ? 'joining' : 'active';
        }

        // Cross-field rules
        $type = $v['employment_type'] ?? $current->employment_type ?? 'permanent';
        $contractEnd = array_key_exists('contract_end', $v) ? $v['contract_end'] : ($current->contract_end ?? null);
        if ($type === 'fixed_term' && !$contractEnd) {
            throw ApiError::invalid('A fixed-term contract needs an end date.', ['contract_end' => 'Add the date the contract ends.']);
        }
        $status = $v['status'] ?? $current->status ?? 'active';
        $lwd = array_key_exists('last_working_day', $v) ? $v['last_working_day'] : ($current->last_working_day ?? null);
        if (in_array($status, ['notice', 'left'], true) && !$lwd) {
            throw ApiError::invalid('Add the last working day.', ['last_working_day' => 'Needed when someone is on notice or has left.']);
        }
        if (!empty($v['reports_to_id'])) {
            if ($current && (int) $v['reports_to_id'] === (int) $current->id) {
                throw ApiError::invalid("Someone can't be their own team leader.", ['reports_to_id' => 'Pick someone else.']);
            }
            if (!Kernel::hr()->table('hr_employees')->where('id', $v['reports_to_id'])->exists()) {
                throw ApiError::invalid('Please pick the team leader from the list.', ['reports_to_id' => 'Not found.']);
            }
        }
        if (!empty($v['crm_user_id'])) {
            if (!CrmGateway::users([$v['crm_user_id']])) {
                throw ApiError::invalid('That CRM login was not found.', ['crm_user_id' => 'Not found in the CRM.']);
            }
            $taken = Kernel::hr()->table('hr_employees')->where('crm_user_id', $v['crm_user_id'])
                ->when($current, fn($q) => $q->where('id', '!=', $current->id))->value('full_name');
            if ($taken) {
                throw ApiError::invalid('That CRM login is already linked to ' . $taken . '.', ['crm_user_id' => 'Already linked to ' . $taken . '.']);
            }
        }
        if (!empty($v['work_email'])) {
            $dupe = Kernel::hr()->table('hr_employees')->where('work_email', $v['work_email'])
                ->when($current, fn($q) => $q->where('id', '!=', $current->id))->value('full_name');
            if ($dupe) {
                throw ApiError::invalid($dupe . ' already uses that work email.', ['work_email' => 'Already used by ' . $dupe . '.']);
            }
        }
        return $v;
    }

    public static function create(Ctx $c): array
    {
        $v = self::readFields($c, null);
        $id = self::insert($v, $c->accountId(), $v['full_name'] . ' was added to People');
        return ['id' => $id];
    }

    /** Save a new person (fields already checked): next employee code, history line, audit. */
    private static function insert(array $v, int $byAccount, string $auditSummary): int
    {
        $db = Kernel::hr();
        $id = (int) $db->transaction(function () use ($db, $v, $byAccount) {
            $next = (int) $db->table('hr_employees')->selectRaw("MAX(CAST(SUBSTRING(employee_code, 4) AS UNSIGNED)) as n")->value('n') + 1;
            $now = Kernel::now();
            $id = $db->table('hr_employees')->insertGetId($v + [
                'employee_code' => 'TT-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('hr_employee_history')->insert([
                'employee_id' => $id, 'kind' => 'joined', 'effective_on' => $v['date_joined'],
                'summary' => 'Joined as ' . $v['designation'] . ' · ' . Settings::centreName($v['centre']),
                'by_account_id' => $byAccount, 'at' => $now,
            ]);
            return $id;
        });
        Audit::log('employee.create', ['employee_id' => $id, 'entity' => 'hr_employees', 'entity_id' => $id, 'summary' => $auditSummary]);
        return $id;
    }

    // ---- bring in staff from the CRM ----------------------------------------------

    /**
     * Active CRM logins with no HR record yet, prefilled from the CRM: name,
     * email, job (from their CRM role), centre (the CRM's centre rules), start
     * date (when their CRM login was made) and team leader (if that person is
     * already in HR). The admin checks the suggestions before bringing them in.
     */
    public static function crmCandidates(): array
    {
        $db = Kernel::hr();
        $linked = $db->table('hr_employees')->whereNotNull('crm_user_id')->pluck('id', 'crm_user_id')->all();
        $rows = Kernel::crm()->table('users')->whereNull('deleted_at')->where('status', 'active')->where('is_dev', 0)
            ->where('role', '!=', 'admin')->orderBy('name')->get(['id', 'name', 'email', 'role', 'reports_to_id', 'created_at']);
        $centres = [];
        try {
            $centres = (new \App\Services\CentreService())->resolveMap();
        } catch (\Throwable $e) {
            error_log('[Trio HR] centre lookup failed: ' . $e->getMessage());
        }
        $valid = array_keys(Settings::get('centres'));
        $takenEmails = array_flip(array_filter($db->table('hr_employees')->pluck('work_email')->map(fn($e) => mb_strtolower((string) $e))->all()));
        $out = [];
        foreach ($rows as $u) {
            if (isset($linked[(int) $u->id])) {
                continue;
            }
            $centre = $centres[(int) $u->id] ?? null;
            $email = mb_strtolower((string) $u->email);
            $out[] = [
                'crm_user_id' => (int) $u->id, 'name' => $u->name, 'email' => $u->email,
                'email_taken' => $email !== '' && isset($takenEmails[$email]),
                'role' => CrmGateway::ROLE_LABELS[$u->role] ?? ucfirst((string) $u->role),
                'designation' => CrmGateway::ROLE_LABELS[$u->role] ?? ucfirst((string) $u->role),
                'centre' => in_array($centre, $valid, true) ? $centre : null,
                'date_joined' => $u->created_at ? substr((string) $u->created_at, 0, 10) : null,
                'team_leader' => $u->reports_to_id && isset($linked[(int) $u->reports_to_id])
                    ? ['id' => (int) $linked[(int) $u->reports_to_id], 'name' => (string) $db->table('hr_employees')->where('id', $linked[(int) $u->reports_to_id])->value('full_name')] : null,
            ];
        }
        return ['people' => $out, 'centres' => self::centreOptions()];
    }

    /** Create HR records for chosen CRM logins (and invite them, if asked). */
    public static function bringIn(Ctx $c): array
    {
        $items = $c->raw('people');
        if (!is_array($items) || !$items || count($items) > 200) {
            throw ApiError::invalid('Pick at least one person to bring in.');
        }
        $candidates = [];
        foreach (self::crmCandidates()['people'] as $p) {
            $candidates[$p['crm_user_id']] = $p;
        }
        $valid = array_keys(Settings::get('centres'));
        $errors = [];
        $plan = [];
        foreach ($items as $i => $it) {
            $uid = is_array($it) ? (int) ($it['crm_user_id'] ?? 0) : 0;
            $p = $candidates[$uid] ?? null;
            if (!$p) {
                $errors["people.$i"] = 'That CRM login is not available any more (already brought in, or switched off).';
                continue;
            }
            $centre = (string) ($it['centre'] ?? $p['centre'] ?? '');
            $joined = (string) ($it['date_joined'] ?? $p['date_joined'] ?? '');
            $designation = trim((string) ($it['designation'] ?? $p['designation']));
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $joined);
            if (!in_array($centre, $valid, true)) {
                $errors["people.$i"] = 'Pick a centre for ' . $p['name'] . '.';
            } elseif (!$d || $d->format('Y-m-d') !== $joined) {
                $errors["people.$i"] = 'Check the start date for ' . $p['name'] . '.';
            } elseif ($designation === '' || mb_strlen($designation) > 80) {
                $errors["people.$i"] = 'Check the job title for ' . $p['name'] . '.';
            } else {
                $plan[] = [$p, [
                    'full_name' => mb_substr($p['name'], 0, 120), 'work_email' => $p['email_taken'] ? null : (mb_strtolower((string) $p['email']) ?: null),
                    'designation' => $designation, 'centre' => $centre, 'date_joined' => $joined,
                    'status' => $joined > date('Y-m-d') ? 'joining' : 'active', 'employment_type' => 'permanent',
                    'crm_user_id' => $p['crm_user_id'], 'reports_to_id' => $p['team_leader']['id'] ?? null, 'pan_status' => 'not_checked',
                ]];
            }
        }
        if ($errors) {
            throw ApiError::invalid('Please check the highlighted people.', $errors);
        }
        $invite = (bool) $c->raw('invite') && \App\Hr\Support\Outbox::enabled(); // with email off, staff come in through the CRM's Trio HR button
        $made = [];
        $invited = 0;
        $admin = Fmt::first((string) ($c->auth['display_name'] ?? 'An admin'));
        foreach ($plan as [$p, $v]) {
            $id = self::insert($v, $c->accountId(), $admin . ' brought ' . $v['full_name'] . ' in from the CRM');
            $made[] = $id;
            if ($invite && $v['work_email']) {
                try {
                    \App\Hr\Auth\Auth::invite($id, $c->accountId());
                    $invited++;
                } catch (ApiError) {
                }
            }
        }
        if ($invited) {
            \App\Hr\Support\Outbox::flushAfterResponse(25);
        }
        return ['created' => $made, 'message' => 'Brought in ' . Fmt::plural(count($made), 'person', 'people') . ' from the CRM'
            . ($invite ? ' and invited ' . Fmt::plural($invited, 'of them', 'of them') . ' to Trio HR' : '') . '.'];
    }

    public static function update(Ctx $c, int $id): array
    {
        $db = Kernel::hr();
        $current = $db->table('hr_employees')->where('id', $id)->first();
        if (!$current) {
            throw ApiError::notFound('That person');
        }
        $v = self::readFields($c, $current);
        $changes = [];
        foreach ($v as $field => $new) {
            $old = $current->$field;
            if ((string) $old !== (string) $new) {
                $changes[$field] = ['from' => $old, 'to' => $new];
            }
        }
        if (!$changes) {
            return ['changed' => 0];
        }
        $effective = $c->date('effective_on') ?? date('Y-m-d');
        $db->transaction(function () use ($db, $id, $v, $changes, $effective, $c) {
            $db->table('hr_employees')->where('id', $id)->update(array_intersect_key($v, $changes) + ['updated_at' => Kernel::now()]);
            $other = [];
            foreach ($changes as $field => $ch) {
                if (in_array($field, self::JOB_FIELDS, true)) {
                    $db->table('hr_employee_history')->insert([
                        'employee_id' => $id, 'kind' => $field, 'effective_on' => $effective,
                        'summary' => self::FIELDS[$field] . ': ' . self::show($field, $ch['from']) . ' → ' . self::show($field, $ch['to']),
                        'detail' => json_encode($ch), 'by_account_id' => $c->accountId(), 'at' => Kernel::now(),
                    ]);
                } else {
                    $other[] = Fmt::lower(self::FIELDS[$field]);
                }
            }
            if ($other) {
                $db->table('hr_employee_history')->insert([
                    'employee_id' => $id, 'kind' => 'details', 'effective_on' => date('Y-m-d'),
                    'summary' => 'Updated ' . implode(', ', $other), 'by_account_id' => $c->accountId(), 'at' => Kernel::now(),
                ]);
            }
        });
        Audit::log('employee.update', [
            'employee_id' => $id, 'entity' => 'hr_employees', 'entity_id' => $id,
            'summary' => Fmt::first($current->full_name) . "'s record was updated (" . implode(', ', array_map(fn($f) => Fmt::lower(self::FIELDS[$f]), array_keys($changes))) . ')',
            'detail' => ['fields' => array_keys($changes)],
        ]);
        $notes = array_filter([
            isset($changes['status']) && $v['status'] === 'left' ? self::offboard($id, $current) : null,
            isset($changes['work_email']) || isset($changes['personal_email']) ? self::moveLoginEmail($id) : null,
        ]);
        return ['changed' => count($changes), 'message' => $notes ? implode(' ', $notes) : null];
    }

    /**
     * A changed email moves the Trio HR login with it, so resets and answers
     * reach the person. The old address is told, in case it wasn't expected.
     */
    private static function moveLoginEmail(int $id): ?string
    {
        $db = Kernel::hr();
        $e = $db->table('hr_employees')->where('id', $id)->first(['work_email', 'personal_email', 'full_name']);
        $email = mb_strtolower((string) ($e->work_email ?: $e->personal_email));
        $acc = $db->table('hr_accounts')->where('employee_id', $id)->first();
        if (!$acc || $email === '' || $acc->email === $email || $acc->status === 'disabled') {
            return null;
        }
        if ($db->table('hr_accounts')->where('email', $email)->where('id', '!=', $acc->id)->exists()) {
            return 'Their Trio HR login still uses ' . $acc->email . ', because another account already uses ' . $email . '.';
        }
        $db->table('hr_accounts')->where('id', $acc->id)->update(['email' => $email, 'updated_at' => Kernel::now()]);
        if ($acc->status === 'active') {
            $lines = ['Hi ' . Fmt::first($e->full_name) . ',', 'Your Trio HR sign-in email was changed to ' . $email . '. From now on, sign in with that address.',
                "If you didn't expect this, tell your HR admin straight away."];
            Outbox::queue('login-email:' . $acc->id . ':' . substr(hash('sha256', $email), 0, 16), $acc->email, 'Your Trio HR sign-in email changed',
                implode("\n\n", $lines), Outbox::html('Your sign-in email changed', $lines));
            Outbox::flushAfterResponse(1);
        }
        Audit::log('account.email', ['employee_id' => $id, 'entity' => 'hr_accounts', 'entity_id' => $acc->id,
            'summary' => Fmt::first($e->full_name) . "'s Trio HR sign-in email changed to " . $email]);
        return 'Their Trio HR sign-in is now ' . $email . '.';
    }

    /** The password as typed (spaces and all), or null when none was sent. */
    private static function typedPassword(Ctx $c): ?string
    {
        $p = $c->raw('password');
        return is_string($p) && $p !== '' ? mb_substr($p, 0, 200) : null;
    }

    /**
     * Someone left: Trio HR becomes read-only for them for 12 months (letters,
     * documents, payslips), as the plan says. Admin access is for an owner to end.
     */
    private static function offboard(int $id, object $person): ?string
    {
        $acc = Kernel::hr()->table('hr_accounts')->where('employee_id', $id)->first();
        if (!$acc || $acc->status === 'disabled') {
            return null;
        }
        $first = Fmt::first($person->full_name);
        if ($acc->role !== 'employee') {
            return $first . ' still has admin access. An owner can switch it off in Settings → Admins.';
        }
        return 'Trio HR is now read-only for ' . $first . ': for 12 months they can see their letters, documents and payslips. To stop that now, use "Switch off Trio HR sign-in".';
    }

    /** Switch someone's Trio HR sign-in off (for example after a dismissal), or back on. */
    public static function setAccess(Ctx $c, int $id): array
    {
        $db = Kernel::hr();
        $e = $db->table('hr_employees')->where('id', $id)->first(['full_name']);
        $acc = $db->table('hr_accounts')->where('employee_id', $id)->first();
        if (!$e || !$acc) {
            throw ApiError::notFound('Their Trio HR account');
        }
        if ($acc->role !== 'employee') {
            throw ApiError::forbidden('This is an admin account. An owner manages it in Settings → Admins.');
        }
        $first = Fmt::first($e->full_name);
        $by = Fmt::first((string) $c->auth['display_name']);
        if (!$c->raw('on')) {
            if ($acc->status === 'disabled') {
                return ['message' => 'Already switched off.'];
            }
            Auth::switchOff((int) $acc->id);
            Audit::log('account.switch_off', ['employee_id' => $id, 'entity' => 'hr_accounts', 'entity_id' => $acc->id,
                'summary' => $by . " switched off " . $first . "'s Trio HR sign-in"]);
            return ['message' => $first . ' can no longer sign in to Trio HR.'];
        }
        if ($acc->status !== 'disabled') {
            return ['message' => 'Already switched on.'];
        }
        $db->table('hr_accounts')->where('id', $acc->id)->update(['status' => $acc->password_hash ? 'active' : 'invited', 'updated_at' => Kernel::now()]);
        Audit::log('account.switch_on', ['employee_id' => $id, 'entity' => 'hr_accounts', 'entity_id' => $acc->id,
            'summary' => $by . " switched " . $first . "'s Trio HR sign-in back on"]);
        return ['message' => $acc->password_hash ? $first . ' can sign in again.' : 'Switched back on. ' . $first . ' never set a password, so send the invite again.'];
    }

    /** A field value as people read it. */
    public static function show(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'not set';
        }
        return match ($field) {
            'centre' => (string) Settings::centreName((string) $value),
            'status' => self::STATUS_LABELS[$value] ?? (string) $value,
            'employment_type' => self::TYPE_LABELS[$value] ?? (string) $value,
            'gender' => self::GENDER_LABELS[$value] ?? (string) $value,
            'reports_to_id' => (string) (Kernel::hr()->table('hr_employees')->where('id', $value)->value('full_name') ?? 'someone'),
            'crm_user_id' => (string) (CrmGateway::users([(int) $value])[(int) $value]->email ?? 'a CRM login'),
            'date_of_birth', 'date_joined', 'probation_end', 'confirmed_on', 'contract_end', 'last_working_day' => Fmt::day((string) $value),
            default => (string) $value,
        };
    }

    // ---- private details vault ----------------------------------------------

    public static function reveal(Ctx $c, int $id): array
    {
        $field = $c->pick('field', array_keys(self::SECRET_LABELS), true, 'Field');
        Auth::reauth($c->auth, self::typedPassword($c));
        $row = Kernel::hr()->table('hr_employee_secrets')->where('employee_id', $id)->where('field', $field)->first();
        if (!$row) {
            throw ApiError::notFound('That detail');
        }
        $value = Vault::decrypt($row->ciphertext);
        Audit::log('secret.view', ['employee_id' => $id, 'entity' => 'hr_employee_secrets', 'detail' => ['field' => $field]]);
        return ['field' => $field, 'value' => $value];
    }

    public static function setPrivate(Ctx $c, int $id): array
    {
        if (!Kernel::hr()->table('hr_employees')->where('id', $id)->exists()) {
            throw ApiError::notFound('That person');
        }
        Auth::reauth($c->auth, self::typedPassword($c));
        $values = Privacy::read($c);
        foreach ($values as $field => $value) {
            Vault::put($id, $field, $value, $c->accountId());
            Audit::log('secret.update', ['employee_id' => $id, 'entity' => 'hr_employee_secrets', 'detail' => ['field' => $field]]);
        }
        if ($values) {
            Kernel::hr()->table('hr_employee_history')->insert([
                'employee_id' => $id, 'kind' => 'private', 'effective_on' => date('Y-m-d'),
                'summary' => 'Private details updated: ' . implode(', ', array_map(fn($f) => self::SECRET_LABELS[$f], array_keys($values))),
                'by_account_id' => $c->accountId(), 'at' => Kernel::now(),
            ]);
        }
        return ['changed' => count($values)];
    }
}
