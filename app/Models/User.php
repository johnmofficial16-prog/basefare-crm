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

    // Role constants
    const ROLE_ADMIN   = 'admin';
    const ROLE_MANAGER = 'manager';
    const ROLE_AGENT   = 'agent';

    // ─── Helpers ─────────────────────────────────────────────────────────────

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
}
