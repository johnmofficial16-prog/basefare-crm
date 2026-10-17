<?php

namespace App\Hr\Domain;

use App\Hr\Auth\Auth;
use App\Hr\Auth\SecondStep;
use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Kernel;
use App\Hr\Support\Audit;

/**
 * "My account" for anyone signed in: password, signed-in devices and
 * remembered devices.
 */
final class Account
{
    public static function get(Ctx $c): array
    {
        $a = Kernel::hr()->table('hr_accounts')->where('id', $c->accountId())->first();
        $remembered = Kernel::hr()->table('hr_trusted_devices')->where('account_id', $a->id)->whereNull('revoked_at')
            ->orderByDesc('last_used_at')->limit(20)->get()->map(fn($d) => [
                'id' => (int) $d->id, 'device' => SecondStep::deviceName((string) $d->user_agent), 'since' => $d->created_at, 'used' => $d->last_used_at,
            ])->all();
        return [
            'name' => $a->display_name, 'email' => $a->email, 'role' => $a->role,
            'password_changed_at' => $a->password_changed_at,
            'devices' => Auth::devices($c->auth),
            'remembered' => $remembered,
        ];
    }

    public static function forgetDevice(Ctx $c, int $id): array
    {
        $n = Kernel::hr()->table('hr_trusted_devices')->where('id', $id)->where('account_id', $c->accountId())->whereNull('revoked_at')
            ->update(['revoked_at' => Kernel::now()]);
        if (!$n) {
            throw ApiError::notFound('That device');
        }
        Audit::log('device.forget', ['entity' => 'hr_trusted_devices', 'entity_id' => $id]);
        return ['message' => 'Forgotten. Signing in from it will send a new-device alert again.'];
    }

    public static function signOutOthers(Ctx $c): array
    {
        $n = Kernel::hr()->table('hr_sessions')->where('account_id', $c->accountId())->where('id', '!=', $c->auth['session_id'])
            ->whereNull('revoked_at')->update(['revoked_at' => Kernel::now()]);
        Audit::log('device.signout_all', ['detail' => ['count' => $n]]);
        return ['message' => $n ? 'Signed out of ' . $n . ' other ' . ($n === 1 ? 'device' : 'devices') . '.' : 'No other devices were signed in.'];
    }
}
