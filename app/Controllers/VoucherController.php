<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\User;
use App\Models\TravelVoucher;

/**
 * Travel vouchers. Admins: all vouchers. Team leaders (John, 22 Oct): make
 * vouchers, and see the ones they or their agents made. Managers (John, 22 Oct,
 * reversing May's 100d214 for vouchers only): make vouchers and see their whole
 * team's (team leaders and everyone under them). Only admins delete.
 */
class VoucherController
{
    private const MAKERS = [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SUPERVISOR];

    /** null = every voucher (admin); otherwise the creators whose vouchers this user may see. */
    private function creatorScope(): ?array
    {
        if (($_SESSION['role'] ?? '') === User::ROLE_ADMIN) {
            return null;
        }
        $me = (int) ($_SESSION['user_id'] ?? 0);
        $team = User::find($me)?->getTeamAgentIds(true) ?? [];
        return array_values(array_unique(array_merge(array_map('intval', $team), [$me])));
    }

    /**
     * Display a list of all saved vouchers
     */
    public function index(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, self::MAKERS, true)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $activePage = 'vouchers';
        $scope = $this->creatorScope();
        $vouchers = TravelVoucher::with('creator')
            ->when($scope !== null, fn($q) => $q->whereIn('created_by', $scope))
            ->orderBy('created_at', 'desc')->get();

        ob_start();
        require __DIR__ . '/../Views/vouchers/list.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Render the voucher maker editor
     */
    public function maker(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, self::MAKERS, true)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $activePage = 'vouchers';
        ob_start();
        require __DIR__ . '/../Views/vouchers/maker.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * API: Save a new voucher
     */
    public function store(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, self::MAKERS, true)) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $data = $request->getParsedBody();
        
        try {
            $voucher = TravelVoucher::create([
                'voucher_no' => $data['voucher_no'],
                'customer_name' => $data['customer_name'],
                'pnr' => $data['pnr'] ?? null,
                'ticket_number' => $data['ticket_number'] ?? null,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'USD',
                'issue_date' => $data['issue_date'],
                'expiry_date' => $data['expiry_date'],
                'reason' => $data['reason'] ?? null,
                'terms' => $data['terms'] ?? null,
                'status' => 'active',
                'created_by' => $_SESSION['user_id']
            ]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Voucher saved successfully',
                'id' => $voucher->id
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to save voucher: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Display a specific voucher
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, self::MAKERS, true)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $id = $args['id'];
        $voucher = TravelVoucher::find($id);
        $scope = $this->creatorScope();

        if (!$voucher || ($scope !== null && !in_array((int) $voucher->created_by, $scope, true))) {
            return $response->withHeader('Location', '/vouchers')->withStatus(302);
        }

        $activePage = 'vouchers';
        ob_start();
        require __DIR__ . '/../Views/vouchers/view.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * API: Delete a voucher (Admin Only)
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $role = $_SESSION['role'] ?? '';
        if ($role !== User::ROLE_ADMIN) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Unauthorized. Admin access required.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $id = $args['id'];
        $voucher = TravelVoucher::find($id);

        if (!$voucher) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Voucher not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        try {
            $voucher->delete();
            $response->getBody()->write(json_encode(['success' => true, 'message' => 'Voucher deleted successfully']));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
