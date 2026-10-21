<?php

namespace App\Hr;

use App\Hr\Auth\Auth;
use App\Hr\Auth\SecondStep;
use App\Hr\Domain\Account;
use App\Hr\Domain\Approvals;
use App\Hr\Domain\Colleagues;
use App\Hr\Domain\Attendance;
use App\Hr\Domain\Defaults;
use App\Hr\Domain\Documents;
use App\Hr\Domain\Letters;
use App\Hr\Domain\Policies;
use App\Hr\Domain\Floor;
use App\Hr\Domain\Leave;
use App\Hr\Domain\Mail;
use App\Hr\Domain\Announcements;
use App\Hr\Domain\Me;
use App\Hr\Domain\People;
use App\Hr\Domain\Setup;
use App\Hr\Domain\Today;
use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;
use App\Hr\Support\Audit;
use App\Hr\Support\Fmt;
use App\Hr\Support\Notify;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Throwable;

/**
 * The Trio HR JSON API: one entry point (public_hr/api/index.php), one error
 * handler, and a role check on every route.
 *
 *  - Every route is either public (sign-in, invite, reset), admin-only
 *    (owner/admin) or staff-only (their own data, the id comes from the session).
 *  - State-changing requests must be JSON, carry the session's CSRF token in
 *    X-CSRF-Token and, when the browser sends one, a same-origin Origin header.
 *  - Errors come back as {error: {message, reason, fields, ref}}. Unexpected
 *    ones show a generic message with a reference that matches the server log.
 */
final class Api
{
    private const ADMIN = ['owner', 'admin'];
    private const STAFF = ['employee', 'owner', 'admin'];
    private const OWNER = ['owner'];

    public static function run(): void
    {
        try {
            Kernel::boot();
        } catch (Throwable $e) {
            $ref = self::ref();
            error_log('[Trio HR ' . $ref . '] boot failed: ' . $e->getMessage());
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['error' => ['message' => "Trio HR can't start right now. Please try again in a few minutes.", 'reason' => 'unavailable', 'ref' => $ref]]);
            return;
        }

        // Trio HR answers on its own address only. The CRM's web root holds this
        // folder too (crm.../public_hr/), and HR must never run on the CRM's
        // origin: separate cookies, separate same-origin checks.
        if (!Kernel::isLocal()) {
            $want = strtolower((string) parse_url((string) Kernel::env('HR_APP_URL', ''), PHP_URL_HOST));
            $host = strtolower((string) preg_replace('/:\d+$/', '', trim((string) ($_SERVER['HTTP_HOST'] ?? ''))));
            if ($want === '' || $host !== $want) {
                http_response_code($want === '' ? 503 : 404);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(['error' => ['message' => $want === '' ? 'Trio HR is not set up on this server yet.' : 'Not found.',
                    'reason' => $want === '' ? 'unavailable' : 'not_found']]);
                return;
            }
        }

        Defaults::ensure(); // law table, letter templates, policy drafts on a fresh install

        $app = AppFactory::create();
        $app->setBasePath('/api');
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $errors = $app->addErrorMiddleware(false, false, false);
        $errors->setDefaultErrorHandler(fn(Request $req, Throwable $e) => self::error($app, $e));
        self::routes($app);
        $app->add(function (Request $req, Handler $h): Response {
            return $h->handle($req)
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Referrer-Policy', 'same-origin')
                ->withHeader('X-Frame-Options', 'DENY');
        });
        $app->run();
    }

    // ---- plumbing -------------------------------------------------------------

    private static function ref(): string
    {
        return 'HR-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private static function error(App $app, Throwable $e): Response
    {
        $res = $app->getResponseFactory()->createResponse();
        if ($e instanceof ApiError) {
            return self::json($res, ['error' => ['message' => $e->getMessage(), 'reason' => $e->reason, 'fields' => $e->fields ?: null]], $e->status);
        }
        if ($e instanceof HttpNotFoundException || $e instanceof HttpMethodNotAllowedException) {
            return self::json($res, ['error' => ['message' => "That page doesn't exist.", 'reason' => 'not_found']], 404);
        }
        // A unique key caught a double submit (two clicks, two tabs, two admins at once): the first one won.
        if ($e instanceof \Illuminate\Database\QueryException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            return self::json($res, ['error' => ['message' => 'That was already done a moment ago. Refresh to see the latest.', 'reason' => 'duplicate']], 409);
        }
        $ref = self::ref();
        error_log('[Trio HR ' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        try {
            // The CRM's Error Console, tagged so HR errors are easy to find.
            \App\Services\ErrorLogService::log('error', '[HR ' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        } catch (Throwable) {
        }
        $msg = 'Something went wrong on our side. Please try again. If it keeps happening, share this reference: ' . $ref;
        $detail = Kernel::isLocal() ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : null;
        return self::json($res, ['error' => ['message' => $msg, 'reason' => 'server', 'ref' => $ref, 'detail' => $detail]], 500);
    }

    /** Wrap a handler: fn(Ctx) => array|null becomes a JSON route. */
    private static function h(callable $fn): callable
    {
        return function (Request $req, Response $res, array $args) use ($fn): Response {
            $out = $fn(new Ctx($req, $args, $req->getAttribute('auth')));
            return self::json($res, $out ?? ['ok' => true]);
        };
    }

    /** Signed-in session with one of $roles; CSRF + Origin + JSON on changes. */
    private static function guard(array $roles, bool $upload = false): callable
    {
        return function (Request $req, Handler $h) use ($roles, $upload): Response {
            $auth = Auth::resolve($req);
            if (!$auth) {
                throw new ApiError(401, 'signed_out', 'You have been signed out. Please sign in again.');
            }
            if (!in_array($auth['role'], $roles, true)) {
                throw ApiError::forbidden();
            }
            if ($req->getMethod() !== 'GET' && !empty($auth['leaver'])
                && !preg_match('#/(session|me/password|me/devices/\d+|me/signout-others|me/mail|me/mail/\d+/reply|mail/files)$#', $req->getUri()->getPath())) {
                throw new ApiError(403, 'leaver', 'You have left Trio, so Trio HR only lets you look at and download your things now. For anything else, write to HR from your Inbox.');
            }
            if ($req->getMethod() !== 'GET') {
                self::assertSameOrigin($req);
                if ($upload) {
                    // Raw file bodies (no multipart forms, which the hosting firewall tends to block).
                    $type = strtolower(trim(explode(';', $req->getHeaderLine('Content-Type'))[0]));
                    if (!in_array($type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'application/octet-stream'], true)) {
                        throw new ApiError(415, 'file_type', 'Please upload a PDF or a photo (JPG, PNG or WebP).');
                    }
                } else {
                    self::assertJson($req);
                }
                $token = $req->getHeaderLine('X-CSRF-Token');
                if ($token === '' || !hash_equals($auth['csrf_token'], $token)) {
                    throw new ApiError(419, 'csrf', 'This page is out of date. Refresh it and try again.');
                }
            }
            Audit::setActor((int) $auth['account_id']);
            return $h->handle($req->withAttribute('auth', $auth));
        };
    }

    /** Public routes that change state still need JSON and a same-origin request. */
    private static function open(): callable
    {
        return function (Request $req, Handler $h): Response {
            if ($req->getMethod() !== 'GET') {
                self::assertSameOrigin($req);
                self::assertJson($req);
            }
            return $h->handle($req);
        };
    }

    private static function assertSameOrigin(Request $req): void
    {
        $origin = $req->getHeaderLine('Origin');
        if ($origin === '') {
            return; // non-browser client; the CSRF token still applies to signed-in routes
        }
        // The raw Host header (Slim's PSR request drops the port from Host).
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        $o = parse_url($origin);
        $originHost = strtolower(($o['host'] ?? '') . (isset($o['port']) ? ':' . $o['port'] : ''));
        $scheme = strtolower((string) ($o['scheme'] ?? ''));
        // Behind Hostinger's proxy HTTPS shows in either of these (the same test .htaccess uses).
        $https = in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if ($host === '' || $originHost !== $host || !in_array($scheme, ['http', 'https'], true) || ($https && $scheme !== 'https')) {
            throw ApiError::forbidden('That request came from another website, so it was blocked.');
        }
    }

    private static function assertJson(Request $req): void
    {
        if (!str_starts_with(strtolower($req->getHeaderLine('Content-Type')), 'application/json')) {
            throw new ApiError(415, 'json_only', 'Please use the Trio HR app to make changes.');
        }
    }

    /** A decrypted file, shown inline in its own tab. */
    private static function fileResponse(Response $res, array $file): Response
    {
        $res->getBody()->write($file['bytes']);
        $res = $res->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', 'inline; filename*=UTF-8\'\'' . rawurlencode($file['name']));
        // Images open as a bare image page; PDFs need the browser's own viewer, so no CSP there.
        return str_starts_with($file['mime'], 'image/')
            ? $res->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'")
            : $res;
    }

    /** A finished sign-in: set its cookies and describe the session. */
    private static function signedIn(Response $res, array $done): Response
    {
        foreach ($done['cookies'] as $cookie) {
            $res = $res->withAddedHeader('Set-Cookie', $cookie);
        }
        return self::json($res, Auth::describe($done['session']));
    }

    // ---- routes ---------------------------------------------------------------

    private static function routes(App $app): void
    {
        $open = self::open();
        $admin = self::guard(self::ADMIN);
        $staff = self::guard(self::STAFF);

        // Session
        $app->get('/session', function (Request $req, Response $res) {
            $auth = Auth::resolve($req);
            return $auth ? self::json($res, Auth::describe($auth)) : self::json($res, ['signed_in' => false, 'email_on' => \App\Hr\Support\Outbox::enabled()]);
        });
        $app->post('/session', function (Request $req, Response $res) {
            $c = new Ctx($req, [], null);
            $email = $c->email('email', true);
            $password = (string) $c->raw('password');
            if ($password === '') {
                throw ApiError::invalid('Please enter your password.', ['password' => 'Enter your password.']);
            }
            $ip = Ctx::clientIp();
            $ua = Auth::clientUa($req);
            $account = Auth::checkPassword((string) $email, $password, $ip);
            $trusted = SecondStep::trustedDevice($req, (int) $account->id);
            if (SecondStep::needed($account, $trusted) !== null) {
                [$challenge, $info] = SecondStep::start($account, $ip, $ua);
                return self::json($res, ['signed_in' => false, 'challenge' => $challenge] + $info);
            }
            return self::signedIn($res, Auth::complete($account, $ip, $ua, $trusted));
        })->add($open);
        $app->post('/session/verify', function (Request $req, Response $res) {
            $c = new Ctx($req, [], null);
            $ip = Ctx::clientIp();
            $ua = Auth::clientUa($req);
            $account = SecondStep::verify((string) $c->str('challenge', 64, true), (string) $c->str('code', 20, true, 'The code'), $ip);
            $trusted = SecondStep::trustedDevice($req, (int) $account->id);
            return self::signedIn($res, Auth::complete($account, $ip, $ua, $trusted, true, (bool) $c->raw('remember')));
        })->add($open);
        $app->post('/session/resend', function (Request $req, Response $res) {
            $c = new Ctx($req, [], null);
            [$challenge, $info] = SecondStep::resend((string) $c->str('challenge', 64, true));
            return self::json($res, ['challenge' => $challenge] + $info + ['message' => 'A new code is on its way.']);
        })->add($open);
        $app->delete('/session', function (Request $req, Response $res) {
            Auth::signOut($req->getAttribute('auth'));
            return self::json($res->withAddedHeader('Set-Cookie', Auth::cookie('', 0)), ['ok' => true]);
        })->add($staff);

        // One click from the CRM (signed one-time link; staff only, never admins)
        $app->post('/session/crm', function (Request $req, Response $res) {
            $c = new Ctx($req, [], null);
            $existing = Auth::resolve($req);
            $account = \App\Hr\Auth\Handoff::accept((string) $c->str('token', 400, true, 'The link'), Ctx::clientIp());
            // A shared office PC still signed in as someone else: never swap people silently.
            if ($existing && (int) $existing['account_id'] !== (int) $account->id) {
                throw new ApiError(409, 'handoff_other', 'Trio HR is still signed in as ' . Fmt::first((string) $existing['display_name'])
                    . ' on this computer. Sign them out of Trio HR, then open it from the CRM again.');
            }
            // A sign-in like any other (new-device email on an unknown device), marked as opened from the CRM:
            // bank changes and ID documents then need a password sign-in.
            return self::signedIn($res, Auth::complete($account, Ctx::clientIp(), Auth::clientUa($req),
                SecondStep::trustedDevice($req, (int) $account->id), false, false, 'crm'));
        })->add($open);

        // Invites and password resets (token travels in the body, never the URL path)
        $app->post('/invite/check', self::h(fn(Ctx $c) => Auth::inviteInfo((string) $c->str('token', 64, true))))->add($open);
        $app->post('/invite/accept', function (Request $req, Response $res) {
            $c = new Ctx($req, [], null);
            return self::signedIn($res, Auth::acceptInvite((string) $c->str('token', 64, true), (string) $c->raw('password'), Ctx::clientIp(), Auth::clientUa($req)));
        })->add($open);
        $app->post('/password/forgot', self::h(function (Ctx $c) {
            if (!\App\Hr\Support\Outbox::enabled()) {
                return ['message' => "Trio HR doesn't send emails. If you use the CRM, open Trio HR with the CRM's Trio HR button. Otherwise ask HR for a new sign-in link.", 'email_off' => true];
            }
            Auth::forgot((string) $c->email('email', true), Ctx::clientIp());
            \App\Hr\Support\Outbox::flushAfterResponse(2);
            return ['message' => "If that email has a Trio HR account, a reset link is on its way. It works for 1 hour."];
        }))->add($open);
        $app->post('/password/check', self::h(fn(Ctx $c) => Auth::resetCheck((string) $c->str('token', 64, true))))->add($open);
        $app->post('/password/reset', function (Request $req, Response $res) {
            $c = new Ctx($req, [], null);
            return self::signedIn($res, Auth::reset((string) $c->str('token', 64, true), (string) $c->raw('password'), Ctx::clientIp(), Auth::clientUa($req)));
        })->add($open);

        // Admin console
        $app->get('/today', self::h(fn(Ctx $c) => Today::build($c)))->add($admin);
        $app->get('/floor', self::h(fn(Ctx $c) => ['floor' => Today::floorSummary(Floor::tonight()), 'clock' => Today::clocks()]))->add($admin);
        $app->get('/floor/full', self::h(fn(Ctx $c) => Today::floorFull()))->add($admin);
        $app->get('/compliance', self::h(fn(Ctx $c) => Today::complianceAll()))->add($admin);
        $app->post('/compliance/{id}/done', self::h(fn(Ctx $c) => Today::complianceDone($c, $c->id())))->add($admin);
        $app->get('/nav', self::h(fn(Ctx $c) => [
            'approvals' => Approvals::counts()['all'],
            'letters' => Kernel::hr()->table('hr_letters')->where('status', 'pending_check')->where('prepared_by', '!=', $c->accountId())->count(),
            'unread' => Notify::unread($c->accountId()),
            'mail' => (function () {
                Mail::escalate();
                Attendance::nudge();
                return Mail::counts()['waiting'];
            })(),
        ]))->add($admin);
        $app->get('/search', self::h(fn(Ctx $c) => self::search($c)))->add($admin);

        $app->get('/people', self::h(fn(Ctx $c) => People::list($c)))->add($admin);
        $app->get('/people/meta', self::h(fn(Ctx $c) => People::formMeta($c->query('except') !== null ? (int) $c->query('except') : null)))->add($admin);
        $app->post('/people', self::h(fn(Ctx $c) => People::create($c)))->add($admin);
        $app->get('/people/from-crm', self::h(fn(Ctx $c) => People::crmCandidates()))->add($admin);
        $app->post('/people/from-crm', self::h(fn(Ctx $c) => People::bringIn($c)))->add($admin);
        $app->get('/people/{id}', self::h(fn(Ctx $c) => People::get($c->id())))->add($admin);
        $app->patch('/people/{id}', self::h(fn(Ctx $c) => People::update($c, $c->id())))->add($admin);
        $app->post('/people/{id}/invite', self::h(function (Ctx $c) {
            $r = Auth::invite($c->id(), $c->accountId());
            if ($r['link']) {
                $name = Fmt::first((string) Kernel::hr()->table('hr_employees')->where('id', $c->id())->value('full_name'));
                return ['message' => 'Here is ' . $name . "'s set-up link.", 'link' => ['link' => $r['link'], 'setup' => true, 'name' => $name, 'valid' => '7 days']];
            }
            \App\Hr\Support\Outbox::flushAfterResponse(2);
            return $r + ['message' => ($r['resent'] ? 'Invite sent again to ' : 'Invite sent to ') . $r['email'] . '.'];
        }))->add($admin);
        $app->post('/people/{id}/signin-link', self::h(function (Ctx $c) {
            $acc = Kernel::hr()->table('hr_accounts')->where('employee_id', $c->id())->first(['id', 'role']);
            if (!$acc) {
                throw ApiError::conflict('They have no Trio HR sign-in yet. Use "Invite to Trio HR" first.');
            }
            if ($acc->role !== 'employee') {
                throw ApiError::conflict('They have admin access. Only an owner can make their link, in Settings → Admins.');
            }
            $r = Auth::handoverLink((int) $acc->id, $c->accountId());
            return ['message' => 'Here is ' . $r['name'] . "'s link.", 'link' => $r];
        }))->add($admin);
        $app->post('/people/invite-all', self::h(function (Ctx $c) {
            if (!\App\Hr\Support\Outbox::enabled()) {
                throw ApiError::conflict("Email is switched off for Trio HR, so there is nothing to send. Staff with a CRM login open Trio HR from the CRM's Trio HR button. For anyone else, open their record and use \"Get a set-up link\".");
            }
            $rows = Kernel::hr()->table('hr_employees as e')->leftJoin('hr_accounts as a', 'a.employee_id', '=', 'e.id')
                ->whereIn('e.status', ['joining', 'active', 'notice'])->whereNull('a.id')->orderBy('e.full_name')
                ->get(['e.id', 'e.full_name', 'e.work_email', 'e.personal_email']);
            $sent = 0;
            $skipped = [];
            foreach ($rows as $r) {
                if (!$r->work_email && !$r->personal_email) {
                    $skipped[] = Fmt::first($r->full_name);
                    continue;
                }
                try {
                    Auth::invite((int) $r->id, $c->accountId());
                    $sent++;
                } catch (ApiError) {
                    $skipped[] = Fmt::first($r->full_name);
                }
            }
            \App\Hr\Support\Outbox::flushAfterResponse(25);
            return ['sent' => $sent, 'skipped' => $skipped, 'message' => 'Invited ' . Fmt::plural($sent, 'person', 'people') . '.'
                . ($skipped ? ' Skipped (no email address): ' . implode(', ', $skipped) . '.' : '')];
        }))->add($admin);
        $app->post('/people/{id}/access', self::h(fn(Ctx $c) => People::setAccess($c, $c->id())))->add($admin);
        $app->post('/people/{id}/reveal', self::h(fn(Ctx $c) => People::reveal($c, $c->id())))->add($admin);
        $app->post('/people/{id}/private', self::h(fn(Ctx $c) => People::setPrivate($c, $c->id())))->add($admin);
        $app->get('/people/{id}/attendance', self::h(function (Ctx $c) {
            $e = Kernel::hr()->table('hr_employees')->where('id', $c->id())->first();
            if (!$e) {
                throw ApiError::notFound('That person');
            }
            return Attendance::personMonth($e, $c->query('month', 7) ?? substr(Attendance::today(), 0, 7));
        }))->add($admin);
        $app->post('/people/{id}/punch-via', self::h(fn(Ctx $c) => People::setPunchVia($c, $c->id())))->add($admin);

        // Attendance: the register, month close and lock, holidays, opening balances
        $app->get('/attendance', self::h(fn(Ctx $c) => Attendance::register($c)))->add($admin);
        $app->get('/attendance/close', self::h(fn(Ctx $c) => Attendance::closeView($c)))->add($admin);
        $app->post('/attendance/mark', self::h(fn(Ctx $c) => Attendance::mark($c)))->add($admin);
        $app->post('/attendance/weekly-offs', self::h(fn(Ctx $c) => Attendance::markWeeklyOffs($c)))->add($admin);
        $app->post('/attendance/set-out', self::h(fn(Ctx $c) => Attendance::setOut($c)))->add($admin);
        $app->post('/attendance/lock', self::h(fn(Ctx $c) => Attendance::lock($c)))->add($admin);
        $app->post('/attendance/unlock', self::h(fn(Ctx $c) => Attendance::unlock($c)))->add($admin);
        $app->get('/holidays', self::h(fn(Ctx $c) => Attendance::holidays($c)))->add($admin);
        $app->post('/holidays', self::h(fn(Ctx $c) => Attendance::addHoliday($c)))->add($admin);
        $app->delete('/holidays/{id}', self::h(fn(Ctx $c) => Attendance::removeHoliday($c, $c->id())))->add($admin);
        $app->get('/leave/balances', self::h(function (Ctx $c) {
            $year = (int) ($c->query('year', 4) ?? date('Y'));
            if ($year < 2020 || $year > 2100) {
                throw ApiError::invalid('Please pick a year.');
            }
            return Leave::yearSheet($year);
        }))->add($admin);
        $app->post('/leave/balances', self::h(function (Ctx $c) {
            $year = (int) $c->intVal('year', 2020, 2100, true, 'The year');
            $rows = $c->raw('rows');
            if (!is_array($rows) || count($rows) > 2000) {
                throw ApiError::invalid('Nothing to save.');
            }
            $n = Leave::setOpenings($year, array_values(array_filter($rows, 'is_array')), $c->accountId());
            Audit::log('leave.openings', ['detail' => ['year' => $year, 'changed' => $n],
                'summary' => Fmt::first((string) ($c->auth['display_name'] ?? 'An admin')) . ' set ' . Fmt::plural($n, 'opening leave balance') . ' for ' . $year]);
            return ['message' => $n ? Fmt::plural($n, 'balance') . ' saved.' : 'Nothing had changed.'];
        }))->add($admin);

        $app->get('/approvals', self::h(fn(Ctx $c) => Approvals::list($c->query('view', 20))))->add($admin);
        $app->get('/approvals/{id}', self::h(fn(Ctx $c) => Approvals::detail($c->id())))->add($admin);
        $app->post('/approvals/{id}/approve', self::h(fn(Ctx $c) => Approvals::approve($c, $c->id())))->add($admin);
        $app->post('/approvals/{id}/send-back', self::h(fn(Ctx $c) => Approvals::sendBack($c, $c->id())))->add($admin);
        $app->post('/approvals/bulk', self::h(fn(Ctx $c) => Approvals::bulkApprove($c)))->add($admin);

        // Trio Mail (admins)
        $app->get('/mail', self::h(fn(Ctx $c) => Mail::inbox($c)))->add($admin);
        $app->post('/mail', self::h(fn(Ctx $c) => Mail::start($c)))->add($admin);
        $app->post('/mail/files', self::h(fn(Ctx $c) => Mail::upload($c)))->add(self::guard(self::STAFF, true));
        $app->get('/mail/files/{id:[0-9]+}', function (Request $req, Response $res, array $args) {
            $file = Mail::file(new Ctx($req, $args, $req->getAttribute('auth')), (int) $args['id']);
            return self::fileResponse($res, $file);
        })->add($staff);
        $app->get('/mail/{id:[0-9]+}', self::h(fn(Ctx $c) => Mail::thread($c, $c->id())))->add($admin);
        $app->post('/mail/{id:[0-9]+}/reply', self::h(fn(Ctx $c) => Mail::reply($c, $c->id())))->add($admin);
        $app->post('/mail/{id:[0-9]+}/status', self::h(fn(Ctx $c) => Mail::setStatus($c, $c->id())))->add($admin);
        $app->post('/mail/{id:[0-9]+}/suggest', self::h(fn(Ctx $c) => Mail::suggest($c, $c->id())))->add($admin);
        $app->get('/announcements', self::h(fn(Ctx $c) => Announcements::list()))->add($admin);
        $app->post('/announcements', self::h(fn(Ctx $c) => Announcements::create($c)))->add($admin);
        $app->get('/announcements/{id:[0-9]+}', self::h(fn(Ctx $c) => Announcements::receipts($c->id())))->add($admin);
        $app->post('/announcements/{id:[0-9]+}/withdraw', self::h(fn(Ctx $c) => Announcements::withdraw($c, $c->id())))->add($admin);

        // Trio Mail (staff: their own conversations only)
        $app->get('/me/mail', self::h(fn(Ctx $c) => Mail::staffInbox($c)))->add($staff);
        $app->get('/me/mail/new', self::h(fn(Ctx $c) => Mail::composeMeta($c)))->add($staff);
        $app->get('/me/mail/unread', self::h(fn(Ctx $c) => ['unread' => $c->employeeId() ? Mail::staffUnread((int) $c->employeeId()) : 0]))->add($staff);
        $app->post('/me/mail', self::h(fn(Ctx $c) => Mail::compose($c)))->add($staff);
        $app->get('/me/mail/{id:[0-9]+}', self::h(fn(Ctx $c) => Mail::staffThread($c, $c->id())))->add($staff);
        $app->post('/me/mail/{id:[0-9]+}/reply', self::h(fn(Ctx $c) => Mail::staffReply($c, $c->id())))->add($staff);
        // Trio Mail between colleagues (CRM users only; admins read everything, read-only)
        $app->get('/me/colleagues', self::h(fn(Ctx $c) => Colleagues::list($c)))->add($staff);
        $app->get('/me/chats', self::h(fn(Ctx $c) => Colleagues::inbox($c)))->add($staff);
        $app->post('/me/chats', self::h(fn(Ctx $c) => Colleagues::compose($c)))->add($staff);
        $app->get('/me/chats/{id:[0-9]+}', self::h(fn(Ctx $c) => Colleagues::thread($c, $c->id())))->add($staff);
        $app->post('/me/chats/{id:[0-9]+}/reply', self::h(fn(Ctx $c) => Colleagues::reply($c, $c->id())))->add($staff);
        $app->get('/chats', self::h(fn(Ctx $c) => Colleagues::adminList($c)))->add($admin);
        $app->get('/chats/{id:[0-9]+}', self::h(fn(Ctx $c) => Colleagues::adminThread($c, $c->id())))->add($admin);
        $app->get('/me/announcements/{id:[0-9]+}', self::h(fn(Ctx $c) => Announcements::read($c, $c->id())))->add($staff);

        // Notifications (everyone signed in)
        $owner = self::guard(self::OWNER);
        $app->get('/notifications', self::h(fn(Ctx $c) => Notify::feed($c->accountId())))->add($staff);
        $app->post('/notifications/read', self::h(function (Ctx $c) {
            Notify::markRead($c->accountId(), $c->intVal('id', 1, PHP_INT_MAX));
            return ['unread' => Notify::unread($c->accountId())];
        }))->add($staff);

        // My account (everyone signed in)
        $app->get('/account', self::h(fn(Ctx $c) => Account::get($c)))->add($staff);
        $app->post('/account/signout-others', self::h(fn(Ctx $c) => Account::signOutOthers($c)))->add($staff);
        $app->delete('/account/remembered/{id}', self::h(fn(Ctx $c) => Account::forgetDevice($c, $c->id())))->add($staff);
        $app->delete('/account/devices/{id}', self::h(function (Ctx $c) {
            Auth::revokeDevice($c->auth, $c->id());
            return ['message' => 'That device is signed out.'];
        }))->add($staff);
        $app->post('/account/password', self::h(function (Ctx $c) {
            Auth::changePassword($c->auth, (string) $c->raw('current'), (string) $c->raw('password'));
            return ['message' => 'Password changed. Other devices have been signed out.'];
        }))->add($staff);

        // Settings (admins; access and security are owner-only)
        $app->get('/settings', self::h(fn(Ctx $c) => Setup::overview($c)))->add($admin);
        $app->patch('/settings/company', self::h(fn(Ctx $c) => Setup::saveCompany($c)))->add($admin);
        $app->patch('/settings/centres', self::h(fn(Ctx $c) => Setup::saveCentre($c)))->add($admin);
        $app->patch('/settings/rules', self::h(fn(Ctx $c) => Setup::saveRules($c)))->add($admin);
        $app->patch('/settings/security', self::h(fn(Ctx $c) => Setup::saveSecurity($c)))->add($owner);
        $app->get('/law', self::h(fn(Ctx $c) => Setup::law()))->add($admin);
        $app->post('/law', self::h(fn(Ctx $c) => Setup::addLaw($c)))->add($admin);
        $app->get('/admins', self::h(fn(Ctx $c) => Setup::admins()))->add($admin);
        $app->post('/admins', self::h(fn(Ctx $c) => Setup::addAdmin($c)))->add($owner);
        $app->patch('/admins/{id}', self::h(fn(Ctx $c) => Setup::updateAdmin($c, $c->id())))->add($owner);
        $app->post('/admins/{id}/invite', self::h(function (Ctx $c) {
            $link = Auth::sendAccountInvite($c->id(), $c->accountId());
            if ($link) {
                $r = Auth::handoverLink($c->id(), $c->accountId());
                return ['message' => 'Here is ' . $r['name'] . "'s set-up link.", 'link' => $r];
            }
            \App\Hr\Support\Outbox::flushAfterResponse(2);
            return ['message' => 'Invitation sent again.'];
        }))->add($owner);
        $app->post('/admins/{id}/signin-link', self::h(function (Ctx $c) {
            if ($c->id() === $c->accountId()) {
                throw ApiError::forbidden('Use "Change password" in My account for your own sign-in.');
            }
            if (!Kernel::hr()->table('hr_accounts')->where('id', $c->id())->whereIn('role', ['owner', 'admin'])->exists()) {
                throw ApiError::notFound('That admin');
            }
            $r = Auth::handoverLink($c->id(), $c->accountId());
            return ['message' => 'Here is ' . $r['name'] . "'s link.", 'link' => $r];
        }))->add($owner);
        $app->get('/health', self::h(fn(Ctx $c) => Setup::health()))->add($admin);
        $app->post('/health/retry', self::h(fn(Ctx $c) => Setup::retryOutbox($c)))->add($admin);
        $app->get('/audit', self::h(fn(Ctx $c) => Setup::audit($c)))->add($admin);
        $app->post('/compliance', self::h(fn(Ctx $c) => Setup::saveCompliance($c, null)))->add($admin);
        $app->patch('/compliance/{id}', self::h(fn(Ctx $c) => Setup::saveCompliance($c, $c->id())))->add($admin);

        // Policies (admin)
        $app->get('/policies', self::h(fn(Ctx $c) => Policies::list()))->add($admin);
        $app->post('/policies', self::h(fn(Ctx $c) => Policies::save($c, null)))->add($admin);
        $app->get('/policies/{id}', self::h(fn(Ctx $c) => Policies::get($c->id())))->add($admin);
        $app->patch('/policies/{id}', self::h(fn(Ctx $c) => Policies::save($c, $c->id())))->add($admin);
        $app->post('/policies/{id}/new-version', self::h(fn(Ctx $c) => Policies::newVersion($c, $c->id())))->add($admin);
        $app->post('/policies/{id}/publish', self::h(fn(Ctx $c) => Policies::publish($c, $c->id())))->add($admin);
        $app->post('/policies/{id}/remind', self::h(fn(Ctx $c) => Policies::remind($c, $c->id())))->add($admin);

        // Letters (admin)
        $app->get('/letters', self::h(fn(Ctx $c) => Letters::list($c)))->add($admin);
        $app->get('/letters/form', self::h(fn(Ctx $c) => Letters::form((int) $c->query('person', 10), (string) $c->query('template', 40))))->add($admin);
        $app->post('/letters', self::h(fn(Ctx $c) => Letters::create($c)))->add($admin);
        $app->post('/letters/catch-up', self::h(fn(Ctx $c) => Letters::catchUp($c)))->add($admin);
        $app->get('/letters/{id}', self::h(fn(Ctx $c) => Letters::get($c->id())))->add($admin);
        $app->patch('/letters/{id}', self::h(fn(Ctx $c) => Letters::update($c, $c->id())))->add($admin);
        $app->post('/letters/{id}/submit', self::h(fn(Ctx $c) => Letters::submit($c, $c->id())))->add($admin);
        $app->post('/letters/{id}/approve', self::h(fn(Ctx $c) => Letters::approve($c, $c->id())))->add($admin);
        $app->post('/letters/{id}/return', self::h(fn(Ctx $c) => Letters::returnToDraft($c, $c->id())))->add($admin);
        $app->post('/letters/{id}/void', self::h(fn(Ctx $c) => Letters::void($c, $c->id())))->add($admin);

        // Documents
        $app->get('/people/{id}/documents', self::h(fn(Ctx $c) => ['documents' => Documents::forEmployee($c->id())]))->add($admin);
        $app->post('/people/{id}/documents', self::h(fn(Ctx $c) => Documents::upload($c, $c->id(), false)))->add(self::guard(self::ADMIN, true));
        $app->delete('/documents/{id}', self::h(fn(Ctx $c) => Documents::delete($c, $c->id())))->add($admin);
        $app->get('/documents/{id}/file', function (Request $req, Response $res, array $args) {
            $file = Documents::open(new Ctx($req, $args, $req->getAttribute('auth')), (int) ($args['id'] ?? 0));
            return self::fileResponse($res, $file);
        })->add($staff);

        // Public: is this letter genuine?
        $app->post('/verify', self::h(fn(Ctx $c) => Letters::verify((string) $c->str('code', 20, true, 'The code'))))->add($open);

        // Staff: policies, letters and documents of their own
        $app->get('/me/policies', self::h(fn(Ctx $c) => Policies::mine($c)))->add($staff);
        $app->get('/me/policies/{id}', self::h(fn(Ctx $c) => Policies::read($c, $c->id())))->add($staff);
        $app->post('/me/policies/{id}/sign', self::h(fn(Ctx $c) => Policies::acknowledge($c, $c->id())))->add($staff);
        $app->post('/me/policies/{id}/withdraw', self::h(fn(Ctx $c) => Policies::withdraw($c, $c->id())))->add($staff);
        $app->get('/me/letters', self::h(fn(Ctx $c) => Letters::mine($c)))->add($staff);
        $app->post('/me/letters/request', self::h(fn(Ctx $c) => Me::requestLetter($c)))->add($staff);
        $app->get('/me/letters/{id}', self::h(fn(Ctx $c) => Letters::get($c->id(), (int) $c->employeeId())))->add($staff);
        $app->post('/me/letters/{id}/ack', self::h(fn(Ctx $c) => Letters::acknowledge($c, $c->id())))->add($staff);
        $app->get('/me/documents', self::h(fn(Ctx $c) => ['documents' => Documents::forEmployee((int) $c->employeeId()), 'kinds' => Documents::KINDS]))->add($staff);
        $app->post('/me/documents', self::h(fn(Ctx $c) => Documents::upload($c, (int) $c->employeeId(), true)))->add(self::guard(self::STAFF, true));

        // Staff app (own data only)
        $app->get('/me', self::h(function (Ctx $c) {
            Attendance::nudge();
            return Me::home($c);
        }))->add($staff);
        $app->get('/me/attendance', self::h(fn(Ctx $c) => Me::attendance($c)))->add($staff);
        $app->get('/me/night/{ref:[ps][0-9]{1,18}}', self::h(fn(Ctx $c) => Me::night($c, (string) $c->arg('ref'))))->add($staff);
        $app->get('/me/punch', self::h(fn(Ctx $c) => Attendance::punchCard($c, Me::employee($c))))->add($staff);
        $app->post('/me/punch', self::h(fn(Ctx $c) => Attendance::punch($c)))->add($staff);
        $app->post('/me/fix', self::h(fn(Ctx $c) => Me::fixNight($c)))->add($staff);
        $app->get('/me/leave', self::h(fn(Ctx $c) => Me::leave($c)))->add($staff);
        $app->post('/me/leave', self::h(fn(Ctx $c) => Me::applyLeave($c)))->add($staff);
        $app->get('/me/requests', self::h(fn(Ctx $c) => ['requests' => Me::requests($c)]))->add($staff);
        $app->post('/me/requests/{id}/withdraw', self::h(fn(Ctx $c) => Me::withdraw($c, $c->id())))->add($staff);
        $app->get('/me/profile', self::h(fn(Ctx $c) => Me::profile($c)))->add($staff);
        $app->post('/me/profile', self::h(fn(Ctx $c) => Me::requestProfileChange($c)))->add($staff);
        $app->post('/me/bank', self::h(fn(Ctx $c) => Me::requestBankChange($c)))->add($staff);
        $app->get('/me/devices', self::h(fn(Ctx $c) => ['devices' => Auth::devices($c->auth)]))->add($staff);
        $app->delete('/me/devices/{id}', self::h(function (Ctx $c) {
            Auth::revokeDevice($c->auth, $c->id());
            return ['message' => 'That device is signed out.'];
        }))->add($staff);
        $app->post('/me/password', self::h(function (Ctx $c) {
            Auth::changePassword($c->auth, (string) $c->raw('current'), (string) $c->raw('password'));
            return ['message' => 'Password changed. Other devices have been signed out.'];
        }))->add($staff);
    }

    /** Ctrl K: people by name, code, email or phone. Actions are matched in the browser. */
    private static function search(Ctx $c): array
    {
        $term = $c->query('q', 60);
        if ($term === null || mb_strlen($term) < 2) {
            return ['people' => []];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
        $rows = Kernel::hr()->table('hr_employees')
            ->where(fn($w) => $w->where('full_name', 'like', $like)->orWhere('preferred_name', 'like', $like)
                ->orWhere('employee_code', 'like', $like)->orWhere('work_email', 'like', $like)->orWhere('phone', 'like', $like))
            ->orderByRaw("CASE WHEN full_name LIKE ? THEN 0 ELSE 1 END", [str_replace('%', '', $like) . '%'])
            ->orderBy('full_name')->limit(8)->get(['id', 'full_name', 'designation', 'centre', 'status', 'employee_code']);
        return ['people' => $rows->map(fn($r) => [
            'id' => (int) $r->id, 'name' => $r->full_name, 'initials' => Fmt::initials($r->full_name),
            'detail' => trim(($r->designation ?? '') . ' · ' . (\App\Hr\Domain\Settings::centreName($r->centre) ?? '') . ' · ' . $r->employee_code, ' ·'),
            'left' => $r->status === 'left',
        ])->all()];
    }
}
