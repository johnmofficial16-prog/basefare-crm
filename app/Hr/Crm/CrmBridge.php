<?php

namespace App\Hr\Crm;

use App\Hr\Kernel;
use App\Hr\Support\Audit;
use Throwable;

/**
 * The bridge's write side: the ONLY place HR changes anything in the CRM.
 * HR_PLAN.md §6.3 allows five guarded commands; each is logged in HR's audit
 * log. Built so far:
 *
 *   5. Post a notification to an employee's CRM bell.
 *
 * (1–4 — logins, clock-out before switch-off, roster publishing and the grace
 * window — arrive with the modules that need them.)
 *
 * A bridge failure must never break the HR action that triggered it: errors are
 * logged and swallowed.
 */
final class CrmBridge
{
    /** Post to a CRM user's bell. The link is absolute so it opens Trio HR. */
    public static function notify(int $crmUserId, string $title, string $body, string $hrLink, ?int $employeeId = null): bool
    {
        try {
            $user = Kernel::crm()->table('users')->where('id', $crmUserId)->first(['id', 'status', 'deleted_at']);
            if (!$user || $user->status !== 'active' || $user->deleted_at) {
                return false;
            }
            // With the one-click sign-in on, the notice opens through the CRM ("/hr?to=…"), so
            // the agent lands signed in on the right screen. Otherwise it links to Trio HR itself.
            $crmUrl = rtrim((string) Kernel::env('HR_CRM_URL', ''), '/');
            $to = preg_replace('#^\##', '', $hrLink);
            $link = $crmUrl !== '' && \App\Hr\Auth\Handoff::enabled() && str_starts_with($to, '/me')
                ? $crmUrl . '/hr?to=' . rawurlencode($to)
                : rtrim((string) Kernel::env('HR_APP_URL', 'https://hr.triotoursandtravels.in'), '/') . '/' . ltrim($hrLink, '/');
            Kernel::crm()->table('notifications')->insert([
                'user_id'    => $crmUserId,
                'type'       => 'hr',
                'title'      => mb_substr('Trio HR · ' . $title, 0, 200),
                'body'       => mb_substr($body, 0, 1000),
                'link'       => mb_substr($link, 0, 255),
                'created_at' => Kernel::now(),
            ]);
            Audit::log('crm.notify', ['employee_id' => $employeeId, 'entity' => 'crm.notifications', 'summary' => 'Posted to their CRM bell: ' . $title,
                'detail' => ['crm_user_id' => $crmUserId, 'title' => $title]]);
            return true;
        } catch (Throwable $e) {
            error_log('[Trio HR] CRM bell write failed: ' . $e->getMessage());
            return false;
        }
    }
}
