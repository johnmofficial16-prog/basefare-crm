<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    // Connect to the users table
    protected $table = 'users';

    // Mass assignable attributes
    protected $fillable = [
        'name',
        'email',
        'password_hash',
        'role',
        'grace_period_mins',
        'status'
    ];

    // Hide the hash from array/json outputs
    protected $hidden = [
        'password_hash',
    ];

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_SUSPENDED = 'suspended';

    // Role constants
    const ROLE_ADMIN = 'admin';
    const ROLE_MANAGER = 'manager';
    const ROLE_AGENT = 'agent';
}
