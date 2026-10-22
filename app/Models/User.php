<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password_hash',
        'role',
        'reports_to_id',
        'grace_period_mins',
        'email_signature',
        'status',
        'deleted_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        // Per-agent signature fields ({title, direct, ext, enabled}); NULL means
        // "use the role defaults" — see App\Services\EmailSignature.
        'email_signature' => 'array',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    // Status constants
    const STATUS_ACTIVE    = 'active';
    const STATUS_INACTIVE  = 'inactive';
    const STATUS_SUSPENDED = 'suspended';

    // Role constants — ordered by privilege level
    const ROLE_ADMIN      = 'admin';
    const ROLE_MANAGER    = 'manager';
    const ROLE_SUPERVISOR = 'supervisor';
    const ROLE_AGENT      = 'agent';
    const ROLE_CSA        = 'csa';

    // ─── Relationships ────────────────────────────────────────────────────────

    /** The supervisor or manager this user directly reports to. */
    public function reportsTo()
    {
        return $this->belongsTo(User::class, 'reports_to_id');
    }

    /** Agents or supervisors who directly report to this user. */
    public function directReports()
    {
        return $this->hasMany(User::class, 'reports_to_id');
    }

    // ─── Role Helpers ─────────────────────────────────────────────────────────

    /** Returns true if this user can access the CRM. */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Returns true if the user holds an admin or manager role. */
    public function isAdminOrManager(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_MANAGER]);
    }

    /** Returns true if the user is manager-level or above (admin or manager). */
    public function isManagerOrAbove(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_MANAGER]);
    }

    /** Returns true if the user is supervisor-level or above. */
    public function isSupervisorOrAbove(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_SUPERVISOR]);
    }

    /**
     * Get the IDs of everyone who reports to this user, directly or through
     * a team leader. For supervisors: their agents. For managers: their team
     * leaders, those team leaders' agents, and any agents reporting directly.
     * Returns an array of user IDs.
     *
     * @param bool $includeInactive When true, suspended/inactive reports are
     *        included (soft-deleted are always excluded). Operational surfaces
     *        (live boards, scoreboards, scheduling) use the default (active
     *        only); record-access paths pass true so a manager can still see a
     *        suspended ex-agent's historical records.
     */
    public function getTeamAgentIds(bool $includeInactive = false): array
    {
        // Everyone below this user, at any depth: a manager's team includes
        // the agents of the team leaders who report to them (manager → team
        // leader → agent), not only their direct reports. (John, 22 Oct.)
        $seen     = [(int) $this->id => true];
        $ids      = [];
        $frontier = [(int) $this->id];
        for ($depth = 0; $depth < 5 && $frontier; $depth++) {
            $rows = self::whereIn('reports_to_id', $frontier)->whereNull('deleted_at')->get(['id', 'status']);
            $frontier = [];
            foreach ($rows as $r) {
                $id = (int) $r->id;
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $frontier[] = $id;
                if ($includeInactive || $r->status === self::STATUS_ACTIVE) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * Check if a given agent_id is part of this user's direct team.
     */
    public function isInMyTeam(int $agentId): bool
    {
        return in_array($agentId, $this->getTeamAgentIds());
    }
}
