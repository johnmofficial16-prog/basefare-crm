<?php

namespace App\Hr\Support;

use App\Hr\Kernel;
use Throwable;

/**
 * The audit log: every sign-in, change and approval, and every VIEW of pay or
 * ID data. `summary` is the plain-English line the "What happened" feed shows;
 * leave it null for entries that shouldn't appear there (e.g. sign-ins).
 *
 * Writing the log must never break the action it records, but a failure is
 * reported to the server log so it isn't silent.
 */
final class Audit
{
    private static ?int $actor = null;

    public static function setActor(?int $accountId): void
    {
        self::$actor = $accountId;
    }

    public static function actor(): ?int
    {
        return self::$actor;
    }

    /**
     * @param array{employee_id?:int|null, entity?:string, entity_id?:int, summary?:string, detail?:array, account_id?:int|null} $o
     */
    public static function log(string $action, array $o = []): void
    {
        try {
            Kernel::hr()->table('hr_audit_log')->insert([
                'at'          => Kernel::now(),
                'account_id'  => array_key_exists('account_id', $o) ? $o['account_id'] : self::$actor,
                'action'      => substr($action, 0, 60),
                'employee_id' => $o['employee_id'] ?? null,
                'entity'      => $o['entity'] ?? null,
                'entity_id'   => $o['entity_id'] ?? null,
                'summary'     => isset($o['summary']) ? mb_substr($o['summary'], 0, 255) : null,
                'detail'      => isset($o['detail']) ? json_encode($o['detail'], JSON_UNESCAPED_UNICODE) : null,
                'ip'          => substr(\App\Hr\Http\Ctx::clientIp(), 0, 45) ?: null,
            ]);
        } catch (Throwable $e) {
            error_log('[Trio HR] audit write failed for ' . $action . ': ' . $e->getMessage());
        }
    }
}
