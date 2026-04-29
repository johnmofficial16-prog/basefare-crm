<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use Illuminate\Database\Capsule\Manager as DB;
use App\Models\User;

class IpRestrictionMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Only run after authentication (AuthMiddleware)
        if (!isset($_SESSION['user_id'])) {
            return $handler->handle($request);
        }

        $userRole = $_SESSION['role'] ?? 'guest';

        // Admins are exempt from IP restrictions
        if ($userRole === User::ROLE_ADMIN) {
            return $handler->handle($request);
        }

        // Check if IP whitelisting is enabled globally
        $whitelistingEnabled = DB::table('system_config')->where('key', 'ip_whitelisting_enabled')->value('value') === '1';
        
        if (!$whitelistingEnabled) {
            return $handler->handle($request);
        }

        // Get the client's real IP address (accounting for Cloudflare/Proxies)
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] 
                 ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
                 ?? $_SERVER['REMOTE_ADDR'] 
                 ?? '';
        
        // Handle comma-separated list in X-Forwarded-For
        if (strpos($clientIp, ',') !== false) {
            $parts = explode(',', $clientIp);
            $clientIp = trim($parts[0]);
        }

        // Check if IP is in the whitelist table
        // Also support dynamic DNS by checking if the DB holds hostnames,
        // but since users enter IPs or hostnames, let's just resolve DB values
        $whitelistedRecords = DB::table('ip_whitelist')->pluck('ip_address')->toArray();
        $isAllowed = false;

        foreach ($whitelistedRecords as $allowedValue) {
            $allowedValue = trim($allowedValue);

            // If it's a hostname (DDNS), resolve it to an IP first
            if (!filter_var($allowedValue, FILTER_VALIDATE_IP) && !str_contains($allowedValue, '*')) {
                $resolvedIp = gethostbyname($allowedValue);
                if ($resolvedIp !== $allowedValue) {
                    $allowedValue = $resolvedIp;
                }
            }

            if ($this->isIpAllowed($clientIp, $allowedValue)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            // Save user info before destroying session
            $blockedUserId = $_SESSION['user_id'] ?? null;
            
            // Destroy the session and log them out
            session_destroy();
            
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['login_error'] = 'Access Restricted: You must be connected to an authorized office network to log into the CRM.';

            // Log this security event
            try {
                DB::table('activity_log')->insert([
                    'user_id'     => $blockedUserId,
                    'action'      => 'blocked_login_ip',
                    'entity_type' => 'security',
                    'details'     => json_encode(['role' => $userRole, 'message' => 'Blocked unauthorized IP attempt']),
                    'ip_address'  => $clientIp,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                // Ignore log failure
            }

            $response = new SlimResponse();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        return $handler->handle($request);
    }

    /**
     * Normalize any IPv6 (or IPv4) address to a canonical lowercase string.
     */
    private function normalizeIp(string $ip): ?string
    {
        $binary = @inet_pton(trim($ip));
        return $binary !== false ? inet_ntop($binary) : null;
    }

    /**
     * Check if a client IP matches an allowed value from the DB.
     */
    private function isIpAllowed(string $clientIp, string $allowedValue): bool
    {
        // Wildcard prefix path
        if (str_contains($allowedValue, '*')) {
            $rawPrefix = rtrim($allowedValue, '*');
            $normalizedClient = $this->normalizeIp($clientIp);
            if ($normalizedClient === null) {
                return false;
            }
            $normalizedPrefix = $this->normalizePrefixSegment($rawPrefix);
            if ($normalizedPrefix === null) {
                return false;
            }
            return str_starts_with($normalizedClient, $normalizedPrefix);
        }

        // Exact match path
        $normalizedClient  = $this->normalizeIp($clientIp);
        $normalizedAllowed = $this->normalizeIp($allowedValue);

        if ($normalizedClient === null || $normalizedAllowed === null) {
            return false;
        }

        return $normalizedClient === $normalizedAllowed;
    }

    /**
     * Normalize a raw wildcard prefix like "2401:4900:8FC1:b7dd:"
     */
    private function normalizePrefixSegment(string $rawPrefix): ?string
    {
        // For IPv4 wildcards (e.g. 192.168.1.)
        if (!str_contains($rawPrefix, ':')) {
             return $rawPrefix; // IPv4 prefix doesn't need complex normalization
        }

        // Complete the IPv6 prefix into a parseable address
        $completed = rtrim($rawPrefix, ':') . '::';
        $normalized = $this->normalizeIp($completed);
        if ($normalized === null) {
            return null;
        }

        $groupCount = substr_count(rtrim($rawPrefix, ':'), ':') + 1;
        $parts = explode(':', $normalized);
        $prefixParts = array_slice($parts, 0, $groupCount);

        return implode(':', $prefixParts) . ':';
    }
}
