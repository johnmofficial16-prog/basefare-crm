<?php

namespace App\Controllers;

use App\Models\CustomerEmailMessage;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CustomerEmailService;
use App\Services\InvoiceService;
use App\Services\SimpleViewService;
use App\Services\TransactionService;
use App\Services\UserService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * SimpleViewController — the simplified admin interface (/simple).
 *
 * For an admin account with users.simple_view = 1 (the client's wife, 12 Oct
 * 2026): same powers as any admin, but a short menu, plain-English pages and a
 * confirmation box before every approval (public/assets/js/simple-ui.js).
 * Admin-only routes; any admin can open these pages, and "Switch to full CRM"
 * (POST /simple/mode) flips the session between the two interfaces.
 *
 * Actions reuse the same services as the full CRM's buttons so the outcome is
 * identical (status changes, notes, emails sent); they answer JSON so the page
 * can stay where it is and confirm in plain words.
 */
class SimpleViewController
{
    private SimpleViewService $svc;

    public function __construct()
    {
        $this->svc = new SimpleViewService();
    }

    // =========================================================================
    // PAGES
    // =========================================================================

    public function home(Request $request, Response $response): Response
    {
        [$s, $e, $label] = $this->svc->window('today');
        [$ys, $ye]       = $this->svc->window('yesterday');
        // Same point in yesterday's shift, so the comparison is fair mid-shift
        $elapsed = max(0, time() - $s->getTimestamp());
        $yCut    = $ys->copy()->addSeconds($elapsed);

        return $this->render($response, 'home', [
            'activePage' => 'simple_home',
            'today'      => $this->svc->totals($s, $e),
            'yesterday'  => $this->svc->totals($ys, $yCut->lt($ye) ? $yCut : $ye),
            'shiftLabel' => $label,
            'top'        => array_slice($this->svc->byAgent($s, $e), 0, 5),
            'teamNow'    => $this->svc->teamNow(),
            'pending'    => $this->svc->pendingCounts(),
            'attention'  => $this->svc->attention(),
            'currency'   => $this->svc->currency(),
        ]);
    }

    public function approvals(Request $request, Response $response): Response
    {
        return $this->render($response, 'approvals', [
            'activePage' => 'simple_approvals',
            'a'          => $this->svc->approvals(),
            'currency'   => $this->svc->currency(),
            'today'      => date('Y-m-d'),
        ]);
    }

    public function team(Request $request, Response $response): Response
    {
        return $this->render($response, 'team', [
            'activePage' => 'simple_team',
            'people'     => $this->svc->people(),
            'leaders'    => $this->svc->leaders(),
            'currency'   => $this->svc->currency(),
            'monthLabel' => date('F'),
        ]);
    }

    public function sales(Request $request, Response $response): Response
    {
        $period = (string) ($request->getQueryParams()['period'] ?? 'today');
        if (!isset(SimpleViewService::PERIODS[$period])) {
            $period = 'today';
        }
        [$s, $e, $label] = $this->svc->window($period);
        $agents = $this->svc->byAgent($s, $e);

        return $this->render($response, 'sales', [
            'activePage' => 'simple_sales',
            'period'     => $period,
            'label'      => $label,
            'totals'     => $this->svc->totals($s, $e),
            'agents'     => $agents,
            'centres'    => $this->svc->byCentre($agents),
            'currency'   => $this->svc->currency(),
        ]);
    }

    public function reports(Request $request, Response $response): Response
    {
        return $this->render($response, 'reports', [
            'activePage' => 'simple_reports',
            'month'      => date('Y-m'),
        ]);
    }

    /** POST /simple/mode  mode=full|simple — switch interface for this session. */
    public function mode(Request $request, Response $response): Response
    {
        $mode = (string) (($request->getParsedBody() ?? [])['mode'] ?? '');
        $_SESSION['simple_mode'] = $mode === 'simple';
        return $response->withHeader('Location', $_SESSION['simple_mode'] ? '/simple' : '/dashboard')->withStatus(302);
    }

    // =========================================================================
    // ACTIONS (JSON)
    // =========================================================================

    public function approveBooking(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        try {
            (new TransactionService())->approve($id, $this->uid(), User::ROLE_ADMIN);
            return $this->json($response, ['success' => true, 'message' => 'Booking #' . $id . ' is approved.']);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'error' => $this->plain($e->getMessage())]);
        }
    }

    public function email(Request $request, Response $response, array $args): Response
    {
        $action  = $args['action'];
        $message = CustomerEmailMessage::with(['thread', 'author'])->find((int) $args['id']);
        if (!$message || $message->direction !== CustomerEmailMessage::DIR_OUTBOUND) {
            return $this->json($response, ['success' => false, 'error' => 'This email could not be found.']);
        }
        $svc = new CustomerEmailService();
        $me  = User::find($this->uid());

        if ($action === 'approve') {
            $res = $svc->approveAndSend($message, $me);
            return $this->json($response, $res['success']
                ? ['success' => true, 'message' => 'Approved and sent to ' . ($message->thread->customer_email ?? 'the customer') . '.']
                : ['success' => false, 'error' => $this->plain($res['error'] ?? 'The email could not be sent.')]);
        }
        $ok = $svc->reject($message, $me, $this->reason($request));
        return $this->json($response, $ok
            ? ['success' => true, 'message' => 'Sent back to ' . ($message->author->name ?? 'the agent') . ' with your note.']
            : ['success' => false, 'error' => 'This email is no longer waiting for approval.']);
    }

    public function invoice(Request $request, Response $response, array $args): Response
    {
        $action  = $args['action'];
        $invoice = Invoice::find((int) $args['id']);
        if (!$invoice) {
            return $this->json($response, ['success' => false, 'error' => 'This invoice could not be found.']);
        }
        $svc = new InvoiceService();
        $me  = User::find($this->uid());

        if ($action === 'approve') {
            $res = $svc->approveAndSend($invoice, $me);
            return $this->json($response, $res['success']
                ? ['success' => true, 'message' => 'Invoice ' . $invoice->invoice_no . ' approved and emailed to ' . $invoice->customer_email . '.']
                : ['success' => false, 'error' => $this->plain($res['error'] ?? 'The invoice could not be sent.')]);
        }
        $ok = $svc->reject($invoice, $me, $this->reason($request));
        return $this->json($response, $ok
            ? ['success' => true, 'message' => 'Invoice ' . $invoice->invoice_no . ' sent back with your note.']
            : ['success' => false, 'error' => 'This invoice is no longer waiting for approval.']);
    }

    /** POST /simple/api/team/add — create a login for a new team member. */
    public function addPerson(Request $request, Response $response): Response
    {
        $b = $request->getParsedBody() ?? [];
        $role = (string) ($b['role'] ?? '');
        if (!in_array($role, [User::ROLE_AGENT, User::ROLE_SUPERVISOR, User::ROLE_MANAGER, User::ROLE_CSA], true)) {
            return $this->json($response, ['success' => false, 'error' => 'Please choose what job this person does.']);
        }
        $password = (string) ($b['password'] ?? '');
        $res = (new UserService())->create([
            'name'              => $b['name'] ?? '',
            'email'             => $b['email'] ?? '',
            'role'              => $role,
            'password'          => $password,
            'grace_period_mins' => 30,
            'reports_to_id'     => $b['reports_to_id'] ?? null,
        ], $this->uid());

        if (!$res['success']) {
            return $this->json($response, ['success' => false, 'error' => $this->plain($res['error'])]);
        }
        return $this->json($response, [
            'success' => true,
            'message' => $res['user']->name . ' can now sign in.',
            'name'    => $res['user']->name,
            'email'   => $res['user']->email,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function uid(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    private function reason(Request $request): string
    {
        $r = trim((string) (($request->getParsedBody() ?? [])['reason'] ?? ''));
        return $r !== '' ? $r : 'No reason given';
    }

    /** Turn system wording into something a non-technical reader understands. */
    private function plain(string $msg): string
    {
        $map = [
            'Only pending_review transactions can be approved.' => 'This booking is no longer waiting for approval — someone may have approved it already.',
            'Agents and supervisors must be assigned a direct superior (manager or supervisor).' => 'Please choose who this person reports to.',
            'The assigned superior must be an active manager or supervisor.' => 'Please choose a manager or team leader for this person to report to.',
            'Supervisors must report directly to a manager.' => 'A team leader must report to a manager.',
            'An account with this email already exists.' => 'Someone already has a login with this email address.',
            'Invalid email address.' => 'That email address doesn\'t look right.',
            'Password must be at least 8 characters.' => 'The password needs at least 8 characters.',
        ];
        return $map[$msg] ?? $msg;
    }

    private function render(Response $response, string $view, array $data): Response
    {
        $data['csrf']   = $_SESSION['csrf_token'] ?? '';
        $data['meName'] = $_SESSION['user_name'] ?? '';
        extract($data);
        $svcView = $this->svc;
        ob_start();
        require __DIR__ . '/../Views/simple/' . $view . '.php';
        $response->getBody()->write((string) ob_get_clean());
        return $response;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
