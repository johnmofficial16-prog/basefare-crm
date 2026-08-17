<?php

namespace App\Services\Buddy;

use App\Services\ErrorLogService;
use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * BuddySettings — the single safe way to touch buddy_settings.extra_json.
 *
 * Three unrelated features now keep state in that one JSON blob: the
 * once-per-business-day greeting claim, the feed pacing stamp, and the agent's
 * self-set monthly goal. Each of them was doing its own read → merge → write,
 * which is a lost-update waiting to happen:
 *
 *   setGoal reads {greeted: "18th"} … stampFeed writes {greeted, last_feed_at}
 *   … setGoal writes {greeted, goal}  ← last_feed_at silently gone
 *
 * The damage is small but real — a lost pacing stamp makes Aisha talk sooner
 * than intended, and a lost greeting stamp lets her greet the same agent twice
 * at double the API cost, which is the exact bug the atomic claim was written
 * to kill in the first place.
 *
 * mutate() serialises the whole read-modify-write behind a row lock, so the
 * three features can share the blob without knowing about each other. On MySQL
 * that is SELECT … FOR UPDATE inside a transaction; on SQLite (the offline test
 * fixture) the lock compiles away and the transaction still gives atomicity.
 */
class BuddySettings
{
    /** Read the decoded extra_json blob. Never throws. */
    public static function read(int $userId): array
    {
        try {
            $raw = DB::table('buddy_settings')->where('user_id', $userId)->value('extra_json');
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Atomically read-modify-write the blob.
     *
     * @param callable $fn fn(array $extra): array{0: array, 1: mixed}
     *                     Return [newExtra, resultToReturn]. Return the blob
     *                     unchanged to decline the write (nothing is saved).
     * @param mixed    $default Returned if anything goes wrong.
     * @return mixed The second element of the callback's return.
     */
    public static function mutate(int $userId, callable $fn, $default = null)
    {
        try {
            return DB::transaction(function () use ($userId, $fn) {
                // Ensure the row exists so there is something to lock. A racing
                // insert loses on the primary key; we then read what won.
                $exists = DB::table('buddy_settings')->where('user_id', $userId)->exists();
                if (!$exists) {
                    try {
                        DB::table('buddy_settings')->insert(['user_id' => $userId]);
                    } catch (Throwable $e) {
                        // someone else created it first — fine, read it below
                    }
                }

                $row = DB::table('buddy_settings')
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first(['extra_json']);

                $decoded = json_decode((string) ($row->extra_json ?? ''), true);
                $before  = is_array($decoded) ? $decoded : [];

                [$after, $result] = $fn($before);

                if ($after !== $before) {
                    DB::table('buddy_settings')
                        ->where('user_id', $userId)
                        ->update(['extra_json' => json_encode($after, JSON_UNESCAPED_UNICODE)]);
                }

                return $result;
            });
        } catch (Throwable $e) {
            ErrorLogService::log('warning', '[buddy] settings mutate failed: ' . $e->getMessage());
            return $default;
        }
    }
}
