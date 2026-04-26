<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;

class UserService
{
    // ─────────────────────────────────────────────────────────────────────────
    // LIST
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return a paginated list of users with optional filters.
     *
     * @param array $filters  Keys: search, role, status
     * @param int   $page
     * @param int   $perPage
     * @return array  { records, total, page, per_page, total_pages }
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $query = User::with('reportsTo')
                     ->whereNull('deleted_at')
                     ->orderBy('name', 'asc');

        // Hard-exclude roles that the actor is not allowed to see
        if (!empty($filters['exclude_roles']) && is_array($filters['exclude_roles'])) {
            $query->whereNotIn('role', $filters['exclude_roles']);

            // Also prevent the actor from filtering by a role they can't see
            if (!empty($filters['role']) && in_array($filters['role'], $filters['exclude_roles'])) {
                $filters['role'] = ''; // silently drop the forbidden role filter
            }
        }

        // Search by name or email
        if (!empty($filters['search'])) {
            $s = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                  ->orWhere('email', 'like', $s);
            });
        }

        // Filter by role
        $validRoles = [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_AGENT, User::ROLE_CSA];
        if (!empty($filters['role']) && in_array($filters['role'], $validRoles)) {
            $query->where('role', $filters['role']);
        }

        // Filter by status
        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'inactive', 'suspended'])) {
            $query->where('status', $filters['status']);
        }

        $total   = $query->count();
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return [
            'records'     => $records,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new user account.
     *
     * @param array $data  Keys: name, email, role, password, grace_period_mins
     * @param int   $adminId  ID of the admin performing the action
     * @return array  { success, user?, error? }
     */
    public function create(array $data, int $adminId): array
    {
        // Validate
        $name       = trim($data['name'] ?? '');
        $email      = trim(strtolower($data['email'] ?? ''));
        $role       = $data['role'] ?? User::ROLE_AGENT;
        $pass       = $data['password'] ?? '';
        $grace      = (int) ($data['grace_period_mins'] ?? 30);
        $reportsToId = !empty($data['reports_to_id']) ? (int)$data['reports_to_id'] : null;

        if (empty($name) || strlen($name) < 2) {
            return ['success' => false, 'error' => 'Name must be at least 2 characters.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }
        $validRoles = [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_AGENT, User::ROLE_CSA];
        if (!in_array($role, $validRoles)) {
            return ['success' => false, 'error' => 'Invalid role.'];
        }

        // Rank protection: managers cannot create manager or admin accounts
        $actor = User::find($adminId);
        if ($actor && $actor->role === User::ROLE_MANAGER) {
            if (in_array($role, [User::ROLE_MANAGER, User::ROLE_ADMIN])) {
                return ['success' => false, 'error' => 'Managers can only create agent and supervisor accounts.'];
            }
        }
        if (strlen($pass) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        if ($grace < 0 || $grace > 120) {
            return ['success' => false, 'error' => 'Grace period must be between 0 and 120 minutes.'];
        }

        // Agents and supervisors MUST have a reports_to_id pointing to a manager or supervisor
        if (in_array($role, [User::ROLE_AGENT, User::ROLE_SUPERVISOR])) {
            if (!$reportsToId) {
                return ['success' => false, 'error' => 'Agents and supervisors must be assigned a direct superior (manager or supervisor).'];
            }
            $superior = User::whereNull('deleted_at')->find($reportsToId);
            if (!$superior || !in_array($superior->role, [User::ROLE_MANAGER, User::ROLE_SUPERVISOR])) {
                return ['success' => false, 'error' => 'The assigned superior must be an active manager or supervisor.'];
            }
            // Agents cannot report to a supervisor if role is supervisor (supervisor reports to manager only)
            if ($role === User::ROLE_SUPERVISOR && $superior->role !== User::ROLE_MANAGER) {
                return ['success' => false, 'error' => 'Supervisors must report directly to a manager.'];
            }
        } else {
            $reportsToId = null; // admins and managers have no reports_to
        }

        // Check email uniqueness (including soft-deleted to avoid silent reuse)
        $existing = User::withTrashed()->where('email', $email)->first();
        if ($existing) {
            return ['success' => false, 'error' => 'An account with this email already exists.'];
        }

        $user = User::create([
            'name'              => $name,
            'email'             => $email,
            'password_hash'     => password_hash($pass, PASSWORD_BCRYPT),
            'role'              => $role,
            'reports_to_id'     => $reportsToId,
            'status'            => 'active',
            'grace_period_mins' => $grace,
        ]);

        $this->log($adminId, 'user_created', 'users', $user->id, [
            'name'         => $name,
            'email'        => $email,
            'role'         => $role,
            'reports_to_id'=> $reportsToId,
        ]);

        return ['success' => true, 'user' => $user];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update an existing user's profile fields.
     *
     * @param int   $userId   Target user to update
     * @param array $data     Keys: name, email, role, grace_period_mins
     * @param int   $adminId  ID of the admin performing the action
     * @return array  { success, error? }
     */
    public function update(int $userId, array $data, int $adminId): array
    {
        $user = User::whereNull('deleted_at')->find($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        // Rank protection for managers
        $actor = User::find($adminId);
        if ($actor && $actor->role === User::ROLE_MANAGER) {
            // Cannot edit admins or other managers
            if (in_array($user->role, [User::ROLE_MANAGER, User::ROLE_ADMIN])) {
                return ['success' => false, 'error' => 'Managers cannot edit admin or manager accounts.'];
            }
            // Cannot promote someone to manager or admin
            $incomingRole = $data['role'] ?? $user->role;
            if (in_array($incomingRole, [User::ROLE_MANAGER, User::ROLE_ADMIN])) {
                return ['success' => false, 'error' => 'Managers cannot assign manager or admin roles.'];
            }
        }

        $name        = trim($data['name'] ?? $user->name);
        $email       = trim(strtolower($data['email'] ?? $user->email));
        $role        = $data['role'] ?? $user->role;
        $grace       = isset($data['grace_period_mins']) ? (int) $data['grace_period_mins'] : $user->grace_period_mins;
        $reportsToId = array_key_exists('reports_to_id', $data)
            ? (!empty($data['reports_to_id']) ? (int)$data['reports_to_id'] : null)
            : $user->reports_to_id;

        if (empty($name) || strlen($name) < 2) {
            return ['success' => false, 'error' => 'Name must be at least 2 characters.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }
        $validRoles = [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR, User::ROLE_AGENT, User::ROLE_CSA];
        if (!in_array($role, $validRoles)) {
            return ['success' => false, 'error' => 'Invalid role.'];
        }
        if ($grace < 0 || $grace > 120) {
            return ['success' => false, 'error' => 'Grace period must be between 0 and 120 minutes.'];
        }

        // Agents and supervisors MUST have a reports_to_id
        if (in_array($role, [User::ROLE_AGENT, User::ROLE_SUPERVISOR])) {
            if (!$reportsToId) {
                return ['success' => false, 'error' => 'Agents and supervisors must be assigned a direct superior.'];
            }
            $superior = User::whereNull('deleted_at')->find($reportsToId);
            if (!$superior || !in_array($superior->role, [User::ROLE_MANAGER, User::ROLE_SUPERVISOR])) {
                return ['success' => false, 'error' => 'The assigned superior must be an active manager or supervisor.'];
            }
            if ($role === User::ROLE_SUPERVISOR && $superior->role !== User::ROLE_MANAGER) {
                return ['success' => false, 'error' => 'Supervisors must report directly to a manager.'];
            }
        } else {
            $reportsToId = null;
        }

        // Check email uniqueness (excluding self)
        $conflict = User::withTrashed()->where('email', $email)->where('id', '!=', $userId)->first();
        if ($conflict) {
            return ['success' => false, 'error' => 'Another account already uses this email address.'];
        }

        $before = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'reports_to_id' => $user->reports_to_id];

        $user->update([
            'name'              => $name,
            'email'             => $email,
            'role'              => $role,
            'reports_to_id'     => $reportsToId,
            'grace_period_mins' => $grace,
        ]);

        $this->log($adminId, 'user_updated', 'users', $userId, [
            'before' => $before,
            'after'  => ['name' => $name, 'email' => $email, 'role' => $role, 'reports_to_id' => $reportsToId],
        ]);

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SUSPEND / REACTIVATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Toggle a user's status between active and suspended.
     * Cannot suspend yourself.
     *
     * @param int $userId   Target user
     * @param int $adminId  Admin performing the action
     * @return array  { success, new_status?, error? }
     */
    public function toggleStatus(int $userId, int $adminId): array
    {
        if ($userId === $adminId) {
            return ['success' => false, 'error' => 'You cannot suspend your own account.'];
        }

        $user = User::whereNull('deleted_at')->find($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        // Managers can only suspend agents and supervisors — not other managers or admins
        $actor = User::find($adminId);
        if ($actor && $actor->role === User::ROLE_MANAGER) {
            if (in_array($user->role, [User::ROLE_MANAGER, User::ROLE_ADMIN])) {
                return ['success' => false, 'error' => 'Managers can only suspend agents and supervisors.'];
            }
        }

        $oldStatus = $user->status;
        $newStatus = ($oldStatus === 'active') ? 'suspended' : 'active';
        $user->update(['status' => $newStatus]);

        $this->log($adminId, $newStatus === 'suspended' ? 'user_suspended' : 'user_reactivated', 'users', $userId, [
            'previous_status' => $oldStatus,
            'new_status'      => $newStatus,
            'target_name'     => $user->name,
        ]);

        return ['success' => true, 'new_status' => $newStatus];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RESET PASSWORD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Reset a user's password to a new value set by admin.
     *
     * @param int    $userId     Target user
     * @param string $newPassword  Plaintext new password (min 8 chars)
     * @param int    $adminId    Admin performing the action
     * @return array  { success, error? }
     */
    public function resetPassword(int $userId, string $newPassword, int $adminId): array
    {
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        $user = User::whereNull('deleted_at')->find($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        $user->update(['password_hash' => password_hash($newPassword, PASSWORD_BCRYPT)]);

        $this->log($adminId, 'password_reset', 'users', $userId, [
            'target_name'  => $user->name,
            'target_email' => $user->email,
            'reset_by'     => $adminId,
        ]);

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SOFT DELETE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Soft-delete a user. Cannot delete yourself.
     *
     * @param int $userId
     * @param int $adminId
     * @return array  { success, error? }
     */
    public function delete(int $userId, int $adminId): array
    {
        if ($userId === $adminId) {
            return ['success' => false, 'error' => 'You cannot delete your own account.'];
        }

        $user = User::whereNull('deleted_at')->find($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        // Managers can only delete agents and supervisors — not other managers or admins
        $actor = User::find($adminId);
        if ($actor && $actor->role === User::ROLE_MANAGER) {
            if (in_array($user->role, [User::ROLE_MANAGER, User::ROLE_ADMIN])) {
                return ['success' => false, 'error' => 'Managers can only remove agents and supervisors.'];
            }
        }

        // Soft delete by setting deleted_at
        $user->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'status'     => 'inactive',
        ]);

        $this->log($adminId, 'user_deleted', 'users', $userId, [
            'name'  => $user->name,
            'email' => $user->email,
        ]);

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL: Activity Logger
    // ─────────────────────────────────────────────────────────────────────────

    private function log(int $userId, string $action, string $entityType, ?int $entityId, array $details = []): void
    {
        try {
            DB::table('activity_log')->insert([
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'details'     => json_encode($details),
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the main flow
            error_log('[UserService] Activity log failed: ' . $e->getMessage());
        }
    }
}
