<?php

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\User;
use App\Models\TravelVoucher;

class VoucherController
{
    /**
     * Display a list of all saved vouchers
     */
    public function index(Request $request, Response $response): Response
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $activePage = 'vouchers';
        $vouchers = TravelVoucher::with('creator')->orderBy('created_at', 'desc')->get();

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
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
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
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER])) {
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
}
