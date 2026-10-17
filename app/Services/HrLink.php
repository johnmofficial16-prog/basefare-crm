<?php

namespace App\Services;

/**
 * The CRM's "Trio HR" link: opens the HR portal already signed in, for staff.
 *
 * The CRM signs a one-time link for the signed-in user; Trio HR checks it
 * (App\Hr\Auth\Handoff::accept — keep the format identical) and opens its own
 * session. Nothing about the CRM session changes.
 *
 * Token: base64url(json{v:1,u:userId,n:32 hex,e:expiry}) . "." . base64url(HMAC-SHA256(payload, HR_HANDOFF_KEY))
 * It goes in the URL fragment (#/from-crm/…), which browsers never send to a server.
 *
 * Off unless both HR_APP_URL and HR_HANDOFF_KEY (32+ characters, same value
 * on both sides) are set.
 */
final class HrLink
{
    private static function env(string $key): string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return is_string($v) ? trim($v) : '';
    }

    public static function enabled(): bool
    {
        return self::env('HR_APP_URL') !== '' && strlen(self::env('HR_HANDOFF_KEY')) >= 32;
    }

    public static function appUrl(): string
    {
        return rtrim(self::env('HR_APP_URL'), '/');
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** A staff screen to open after signing in, e.g. "/me/mail/12" (anything else is ignored). */
    public static function cleanTarget(?string $to): ?string
    {
        return is_string($to) && preg_match('#^/me(/[a-z0-9-]{1,30}){0,3}$#', $to) ? $to : null;
    }

    /** Where to send this user: staff get a signed link, admins the HR sign-in page. */
    public static function destination(int $userId, string $role, ?string $to = null): string
    {
        if ($role === 'admin') {
            return self::appUrl() . '/#/signin';
        }
        $payload = self::b64(json_encode(['v' => 1, 'u' => $userId, 'n' => bin2hex(random_bytes(16)), 'e' => time() + 60]));
        $sig = self::b64(hash_hmac('sha256', $payload, self::env('HR_HANDOFF_KEY'), true));
        $to = self::cleanTarget($to);
        return self::appUrl() . '/#/from-crm/' . $payload . '.' . $sig . ($to ? '?to=' . rawurlencode($to) : '');
    }

    /** The sidebar entry, or null when the link is off. */
    public static function navItem(): ?array
    {
        return self::enabled() ? ['href' => '/hr', 'icon' => 'badge', 'label' => 'Trio HR', 'key' => 'trio_hr', 'new_tab' => true] : null;
    }
}
