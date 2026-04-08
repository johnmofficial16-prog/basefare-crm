<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * LoginAttempt - tracks failed login attempts per IP address.
 *
 * Used by AuthController to enforce brute-force rate limiting.
 * Rate limit: 10 failed attempts within a 10-minute window.
 */
class LoginAttempt extends Model
{
    protected $table      = 'login_attempts';
    public    $timestamps = false;

    protected $fillable = [
        'ip_address',
        'email',
        'attempts',
        'last_attempt_at',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Rate-limit constants — adjust here to change the policy globally
    // -------------------------------------------------------------------------
    const MAX_ATTEMPTS  = 10;   // Block after this many failures
    const LOCKOUT_MINS  = 10;   // Lockout window in minutes

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the LoginAttempt record for a given IP (or null).
     */
    public static function forIp(string $ip): ?self
    {
        return static::where('ip_address', $ip)->first();
    }

    /**
     * Is this IP currently locked out?
     */
    public function isLockedOut(): bool
    {
        if ($this->attempts < self::MAX_ATTEMPTS) {
            return false;
        }

        // Check if the lockout window has expired
        $windowExpiry = $this->last_attempt_at->copy()->addMinutes(self::LOCKOUT_MINS);
        return Carbon::now()->lt($windowExpiry);
    }

    /**
     * How many minutes remain in the lockout (rounded up).
     */
    public function minutesRemaining(): int
    {
        $expiry = $this->last_attempt_at->copy()->addMinutes(self::LOCKOUT_MINS);
        return (int) ceil(Carbon::now()->diffInSeconds($expiry, false) / 60);
    }

    /**
     * Record a failed attempt for the given IP / email.
     * Creates a new row, or increments an existing one.
     */
    public static function recordFailure(string $ip, string $email): void
    {
        $record = static::forIp($ip);

        if ($record) {
            // If the previous lockout window has already expired, reset counter
            $windowExpiry = $record->last_attempt_at->copy()->addMinutes(self::LOCKOUT_MINS);
            if (Carbon::now()->gte($windowExpiry)) {
                $record->attempts         = 1;
                $record->email            = $email;
                $record->last_attempt_at  = Carbon::now();
            } else {
                $record->attempts++;
                $record->email           = $email;
                $record->last_attempt_at = Carbon::now();
            }
            $record->save();
        } else {
            static::create([
                'ip_address'      => $ip,
                'email'           => $email,
                'attempts'        => 1,
                'last_attempt_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Clear the attempt record on successful login.
     */
    public static function clearFor(string $ip): void
    {
        static::where('ip_address', $ip)->delete();
    }
}
