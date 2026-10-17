<?php

namespace App\Hr\Support;

use App\Hr\Crm\CrmBridge;
use App\Hr\Kernel;
use Throwable;

/**
 * The HR bell. Admins hear about new requests and things to check; staff hear
 * about decisions, letters and policies — in Trio HR and, because agents live
 * in the CRM at night, in the CRM's bell too (bridge command 5).
 *
 * Writing a notification never breaks the action behind it.
 */
final class Notify
{
    /** Every active owner/admin, except the one who caused it. */
    public static function admins(string $kind, string $title, ?string $body, ?string $link, ?int $except = null): void
    {
        try {
            $ids = Kernel::hr()->table('hr_accounts')->whereIn('role', ['owner', 'admin'])->where('status', 'active')
                ->when($except, fn($q) => $q->where('id', '!=', $except))->pluck('id');
            $now = Kernel::now();
            $rows = [];
            foreach ($ids as $id) {
                $rows[] = ['account_id' => $id, 'kind' => $kind, 'title' => mb_substr($title, 0, 200),
                    'body' => $body !== null ? mb_substr($body, 0, 500) : null, 'link' => $link, 'created_at' => $now];
            }
            if ($rows) {
                Kernel::hr()->table('hr_notifications')->insert($rows);
            }
        } catch (Throwable $e) {
            error_log('[Trio HR] notify admins failed: ' . $e->getMessage());
        }
    }

    /** One employee: their Trio HR bell (if they have joined) and their CRM bell. */
    public static function employee(int $employeeId, string $kind, string $title, ?string $body, string $link, bool $crm = true): void
    {
        try {
            $acc = Kernel::hr()->table('hr_accounts')->where('employee_id', $employeeId)->where('status', 'active')->value('id');
            if ($acc) {
                self::account((int) $acc, $kind, $title, $body, $link);
            }
            if ($crm) {
                $crmUser = Kernel::hr()->table('hr_employees')->where('id', $employeeId)->value('crm_user_id');
                if ($crmUser) {
                    CrmBridge::notify((int) $crmUser, $title, (string) $body, $link, $employeeId);
                }
            }
        } catch (Throwable $e) {
            error_log('[Trio HR] notify employee failed: ' . $e->getMessage());
        }
    }

    public static function account(int $accountId, string $kind, string $title, ?string $body, ?string $link): void
    {
        Kernel::hr()->table('hr_notifications')->insert([
            'account_id' => $accountId, 'kind' => $kind, 'title' => mb_substr($title, 0, 200),
            'body' => $body !== null ? mb_substr($body, 0, 500) : null, 'link' => $link, 'created_at' => Kernel::now(),
        ]);
    }

    // ---- reading ------------------------------------------------------------

    public static function feed(int $accountId): array
    {
        $db = Kernel::hr();
        $items = $db->table('hr_notifications')->where('account_id', $accountId)->orderByDesc('created_at')->limit(30)->get()
            ->map(fn($n) => [
                'id' => (int) $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body,
                'link' => $n->link, 'at' => $n->created_at, 'read' => $n->read_at !== null,
            ])->all();
        return ['unread' => self::unread($accountId), 'items' => $items];
    }

    public static function unread(int $accountId): int
    {
        return Kernel::hr()->table('hr_notifications')->where('account_id', $accountId)->whereNull('read_at')->count();
    }

    public static function markRead(int $accountId, ?int $id): void
    {
        $q = Kernel::hr()->table('hr_notifications')->where('account_id', $accountId)->whereNull('read_at');
        if ($id) {
            $q->where('id', $id);
        }
        $q->update(['read_at' => Kernel::now()]);
    }
}
