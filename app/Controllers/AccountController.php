<?php

namespace App\Controllers;

use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * "Choose your password" after someone else reset it (security audit 26 Oct 2026).
 * Until it's done, AuthMiddleware sends every CRM page here, so the manager or
 * admin who reset the password can't go on using the account.
 */
class AccountController
{
    public function passwordForm(Request $request, Response $response): Response
    {
        return $this->render($response, $_SESSION['account_pw_error'] ?? null);
    }

    public function savePassword(Request $request, Response $response): Response
    {
        $body    = $request->getParsedBody() ?? [];
        $current = (string) ($body['current'] ?? '');
        $new     = (string) ($body['new'] ?? '');
        $confirm = (string) ($body['confirm'] ?? '');
        $user    = User::whereNull('deleted_at')->find((int) ($_SESSION['user_id'] ?? 0));

        $error = null;
        if (!$user || empty($user->password_hash) || !password_verify($current, $user->password_hash)) {
            $error = 'The password you were given isn\'t right.';
        } elseif (strlen($new) < 10) {
            $error = 'Your new password needs at least 10 characters.';
        } elseif ($new !== $confirm) {
            $error = 'The two new passwords don\'t match.';
        } elseif (password_verify($new, $user->password_hash)) {
            $error = 'Choose a different password from the one you were given.';
        } elseif (preg_match('/^(.)\1+$/', $new) || in_array(strtolower($new), ['password123', '1234567890', 'qwertyuiop', 'basefare123'], true)) {
            $error = 'That password is too easy to guess. Try a short sentence or a mix of words.';
        }
        if ($error !== null) {
            $_SESSION['account_pw_error'] = $error;
            return $response->withHeader('Location', '/account/password')->withStatus(302);
        }

        DB::table('users')->where('id', $user->id)->update([
            'password_hash'        => password_hash($new, PASSWORD_BCRYPT),
            'must_change_password' => 0,
        ]);
        try {
            DB::table('activity_log')->insert([
                'user_id' => $user->id, 'action' => 'password_changed_after_reset', 'entity_type' => 'users',
                'entity_id' => $user->id, 'details' => json_encode([]), 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[AccountController] activity_log insert failed: ' . $e->getMessage());
        }

        session_regenerate_id(true);
        $user->refresh();
        $user->active_session_id = session_id();
        $user->save();
        $_SESSION['active_session_id'] = session_id();
        unset($_SESSION['must_change_password'], $_SESSION['account_pw_error']);

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    private function render(Response $response, ?string $error): Response
    {
        unset($_SESSION['account_pw_error']);
        $firstName = explode(' ', trim((string) ($_SESSION['user_name'] ?? 'there')))[0];
        ob_start();
        require __DIR__ . '/../Views/account/password.php';
        $response->getBody()->write((string) ob_get_clean());
        return $response->withHeader('Cache-Control', 'no-store');
    }
}
