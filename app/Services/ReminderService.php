<?php

namespace App\Services;

use App\Models\BookingReminder;
use App\Models\Notification;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

/**
 * ReminderService
 *
 * Central logic for booking reminders:
 *   - computing a reminder's fire time from confirmed departure + timing,
 *   - resolving who gets alerted (agent + agent's manager chain + all admins),
 *   - dispatching due reminders into per-recipient in-app notifications.
 *
 * Shared by ReminderController (create/edit) and the cron dispatcher so the
 * recipient rules live in exactly one place.
 */
class ReminderService
{
    /**
     * Compute the fire datetime for a reminder.
     *
     * @param string      $departureAt  "Y-m-d H:i:s" confirmed departure
     * @param string      $mode         BookingReminder::MODE_PRESET | MODE_ABSOLUTE
     * @param int|null    $offsetHours  hours before departure (preset mode)
     * @param string|null $absoluteAt   "Y-m-d H:i:s" (absolute mode)
     * @return Carbon
     */
    public function computeRemindAt(string $departureAt, string $mode, ?int $offsetHours, ?string $absoluteAt): Carbon
    {
        if ($mode === BookingReminder::MODE_ABSOLUTE) {
            return Carbon::parse($absoluteAt);
        }
        return Carbon::parse($departureAt)->subHours(max(0, (int) $offsetHours));
    }

    /**
     * Resolve the set of user IDs that should be alerted for a booking:
     *   1. the booking's agent,
     *   2. the agent's reporting chain up to (and including) the first manager
     *      (so an intermediate supervisor is included too),
     *   3. every active admin.
     *
     * Soft-deleted / inactive users are excluded. Returns a de-duplicated,
     * positive-int array.
     *
     * @return int[]
     */
    public function resolveRecipients(Transaction $txn): array
    {
        $ids = [];

        // 1. The owning agent.
        if ($txn->agent_id) {
            $ids[] = (int) $txn->agent_id;
        }

        // 2. Walk up the reports_to chain until we hit a manager/admin (inclusive),
        //    capturing any supervisor in between. Guard against cycles.
        $current = User::whereNull('deleted_at')->find($txn->agent_id);
        $hops = 0;
        while ($current && $current->reports_to_id && $hops < 10) {
            $parent = User::whereNull('deleted_at')->find($current->reports_to_id);
            if (!$parent) {
                break;
            }
            $ids[] = (int) $parent->id;
            if (in_array($parent->role, [User::ROLE_MANAGER, User::ROLE_ADMIN], true)) {
                break; // reached the manager — stop climbing
            }
            $current = $parent;
            $hops++;
        }

        // 3. All active admins.
        $adminIds = User::where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();
        foreach ($adminIds as $aid) {
            $ids[] = (int) $aid;
        }

        // De-dupe + drop empties, keep only users that still exist & are active.
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }
        $valid = User::whereIn('id', $ids)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->toArray();

        return array_values(array_unique($valid));
    }

    /**
     * Dispatch a single due reminder: create one Notification per recipient and
     * mark the reminder fired. Idempotent-ish — a reminder already marked fired
     * is skipped. Returns the number of notifications created.
     */
    public function dispatch(BookingReminder $reminder): int
    {
        if (!$reminder->isScheduled()) {
            return 0;
        }

        // ATOMIC CLAIM. dispatchDue() is called by the cron AND lazily by every
        // user's 30-second notification poll, so a dozen requests can reach the same
        // due reminder in the same second. The "is it still scheduled?" check above
        // is a read — all of them passed it, all of them fanned out notifications,
        // and every recipient's bell rang several times for one reminder.
        //
        // This UPDATE is the real gate: exactly one caller can flip
        // scheduled -> fired, and only that caller proceeds. Marking it fired up
        // front also means a crash mid-fan-out cannot cause a re-send storm.
        $claimed = BookingReminder::where('id', $reminder->id)
            ->where('status', BookingReminder::STATUS_SCHEDULED)
            ->update([
                'status'   => BookingReminder::STATUS_FIRED,
                'fired_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ]);

        if ($claimed === 0) {
            return 0;   // someone else got there first
        }

        $txn = $reminder->transaction()->with('agent')->first();
        if (!$txn) {
            // Booking vanished — cancel the orphan so it stops being "due".
            // (Overrides the FIRED status set by the claim above; nothing was sent.)
            $reminder->update(['status' => BookingReminder::STATUS_CANCELLED, 'fired_at' => null]);
            return 0;
        }

        $recipients = $this->resolveRecipients($txn);

        $title = '⏰ ' . $reminder->title;
        $depAt = $reminder->departure_at ? $reminder->departure_at->format('D, M j Y · g:i A') : '—';
        $bodyParts = [];
        $bodyParts[] = 'Booking ' . ($txn->pnr ?: ('#' . $txn->id)) . ' — ' . $txn->customer_name;
        $bodyParts[] = 'Departure: ' . $depAt;
        if ($reminder->message) {
            $bodyParts[] = $reminder->message;
        }
        $body = implode("\n", $bodyParts);
        $link = '/transactions/' . $txn->id;
        $now  = Carbon::now()->format('Y-m-d H:i:s');

        $created = 0;
        foreach ($recipients as $uid) {
            Notification::create([
                'user_id'        => $uid,
                'type'           => Notification::TYPE_BOOKING_REMINDER,
                'reminder_id'    => $reminder->id,
                'transaction_id' => $txn->id,
                'title'          => $title,
                'body'           => $body,
                'link'           => $link,
                'read_at'        => null,
            ]);
            $created++;
        }

        // (status/fired_at were already set by the atomic claim at the top.)

        // Activity log for admin visibility (mirrors shift_gap_alert pattern).
        try {
            DB::table('activity_log')->insert([
                'user_id'     => null, // system action (activity_log.user_id FK is nullable)
                'action'      => 'booking_reminder_fired',
                'entity_type' => 'transaction',
                'entity_id'   => $txn->id,
                'details'     => json_encode([
                    'reminder_id' => $reminder->id,
                    'title'       => $reminder->title,
                    'recipients'  => $recipients,
                ]),
                'ip_address'  => '127.0.0.1',
                'created_at'  => $now,
            ]);
        } catch (\Throwable $e) {
            // activity_log is best-effort; never block dispatch on it.
            error_log('[ReminderService] activity_log insert failed: ' . $e->getMessage());
        }

        return $created;
    }

    /**
     * Dispatch every scheduled reminder whose fire time has passed. Used by both
     * the cron and the lazy fallback in the notification poll endpoint.
     *
     * @return array{reminders:int, notifications:int}
     */
    public function dispatchDue(): array
    {
        $due = BookingReminder::where('status', BookingReminder::STATUS_SCHEDULED)
            ->where('remind_at', '<=', Carbon::now()->format('Y-m-d H:i:s'))
            ->orderBy('remind_at', 'asc')
            ->limit(500)
            ->get();

        $notifs = 0;
        foreach ($due as $reminder) {
            $notifs += $this->dispatch($reminder);
        }

        return ['reminders' => $due->count(), 'notifications' => $notifs];
    }
}
