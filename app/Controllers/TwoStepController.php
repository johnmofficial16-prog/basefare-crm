<?php

namespace App\Controllers;

use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\TwoStep;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin sign-in, second step (security, 26 Oct 2026). Reached only from the
 * password step (AuthController sets $_SESSION['two_step']); nobody is signed
 * in until a code from the authenticator app — or a backup code — is accepted.
 * The first time, the admin scans a QR code to set the app up.
 */
class TwoStepController
{
    private const PENDING_SECONDS = 600;   // the password step is good for 10 minutes
    private const MAX_TRIES = 5;

    public function show(Request $request, Response $response): Response
    {
        [$p, $user] = $this->pending();
        if ($user === null) {
            return $this->restart($response, 'Please sign in again.');
        }
        $mode = TwoStep::isSetUp($user) ? 'code' : 'setup';
        if ($mode === 'setup' && empty($_SESSION['two_step']['secret'])) {
            $_SESSION['two_step']['secret'] = TwoStep::newSecret();
        }
        return $this->render($response, $mode, $user, $_SESSION['two_step_error'] ?? null);
    }

    public function verify(Request $request, Response $response): Response
    {
        [$p, $user] = $this->pending();
        if ($user === null) {
            return $this->restart($response, 'Please sign in again.');
        }
        $body     = $request->getParsedBody() ?? [];
        $code     = trim((string) ($body['code'] ?? ''));
        $remember = !empty($body['remember']);

        $codes = null;
        if (!TwoStep::isSetUp($user)) {
            $secret = (string) ($_SESSION['two_step']['secret'] ?? '');
            $step = $secret !== '' ? TwoStep::matchStep($secret, $code) : null;
            if ($step !== null) {
                $codes = TwoStep::enable($user, $secret, $step);
            }
            $ok = $step !== null;
        } else {
            $ok = TwoStep::verifyFor($user, $code);
        }

        if (!$ok) {
            $_SESSION['two_step']['tries'] = (int) ($p['tries'] ?? 0) + 1;
            if ($_SESSION['two_step']['tries'] >= self::MAX_TRIES) {
                LoginAttempt::recordAccountFailure((string) $user->email);
                LoginAttempt::recordFailure((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), (string) $user->email);
                return $this->restart($response, 'Too many wrong codes. Please sign in again.');
            }
            $_SESSION['two_step_error'] = 'That code didn\'t work. Use the newest 6-digit code from the app (they change every 30 seconds).';
            return $response->withHeader('Location', '/login/two-step')->withStatus(302);
        }

        unset($_SESSION['two_step_error']);
        $user->refresh();
        $redirect = (string) ($p['redirect'] ?? '/dashboard');
        $done = (new AuthController())->completeLogin($response, $user, $redirect);
        if ($remember && ($cookie = TwoStep::rememberCookie($user)) !== null) {
            $done = $done->withAddedHeader('Set-Cookie', $cookie);
        }
        if ($codes !== null) {
            // First set-up: show the backup codes once, then carry on to where sign-in was going.
            $_SESSION['two_step_backup'] = ['codes' => $codes, 'next' => $done->getHeaderLine('Location') ?: '/dashboard'];
            $done = $done->withHeader('Location', '/account/backup-codes');
        }
        return $done;
    }

    /** GET /account/backup-codes — shown once, right after setting up the app. */
    public function backupCodes(Request $request, Response $response): Response
    {
        $b = $_SESSION['two_step_backup'] ?? null;
        unset($_SESSION['two_step_backup']);
        if (!$b) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
        $mode  = 'backup';
        $codes = $b['codes'];
        $next  = $b['next'];
        $user  = User::find((int) ($_SESSION['user_id'] ?? 0));
        return $this->render($response, $mode, $user, null, compact('codes', 'next'));
    }

    // ---- helpers --------------------------------------------------------------

    /** @return array{0:?array,1:?User} */
    private function pending(): array
    {
        $p = $_SESSION['two_step'] ?? null;
        if (!$p || time() - (int) ($p['at'] ?? 0) > self::PENDING_SECONDS) {
            unset($_SESSION['two_step']);
            return [null, null];
        }
        $user = User::whereNull('deleted_at')->where('status', User::STATUS_ACTIVE)->find((int) $p['uid']);
        return [$p, ($user && TwoStep::requiredFor($user)) ? $user : null];
    }

    private function restart(Response $response, string $why): Response
    {
        unset($_SESSION['two_step'], $_SESSION['two_step_error']);
        $_SESSION['login_error'] = $why;
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    private function render(Response $response, string $mode, ?User $user, ?string $error, array $extra = []): Response
    {
        unset($_SESSION['two_step_error']);
        $secret   = $_SESSION['two_step']['secret'] ?? '';
        $setupUri = ($mode === 'setup' && $user) ? TwoStep::setupUri($user, $secret) : '';
        $firstName = $user ? explode(' ', trim((string) $user->name))[0] : 'there';
        extract($extra);
        ob_start();
        require __DIR__ . '/../Views/account/two_step.php';
        $response->getBody()->write((string) ob_get_clean());
        return $response->withHeader('Cache-Control', 'no-store');
    }
}
