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
        'status',
        'deleted_at',
    ];

    protected $hidden = [
        'password_hash',
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
     * Get all agent IDs that directly report to this user.
     * For supervisors: returns their team. For managers/admins: returns all direct reports.
     * Returns an array of user IDs.
     */
    public function getTeamAgentIds(): array
    {
        return $this->directReports()
            ->whereNull('deleted_at')
            ->where('status', self::STATUS_ACTIVE)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Check if a given agent_id is part of this user's direct team.
     */
    public function isInMyTeam(int $agentId): bool
    {
        return in_array($agentId, $this->getTeamAgentIds());
    }
}
