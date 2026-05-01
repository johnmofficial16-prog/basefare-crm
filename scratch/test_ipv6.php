<?php

/**
 * Normalize any IPv6 (or IPv4) address to a canonical lowercase string.
 */
function normalizeIp(string $ip): ?string
{
    $binary = @inet_pton(trim($ip));
    return $binary !== false ? inet_ntop($binary) : null;
}

/**
 * Normalize a raw wildcard prefix like "2401:4900:8FC1:b7dd:"
 */
function normalizePrefixSegment(string $rawPrefix): ?string
{
    if (!str_contains($rawPrefix, ':')) {
         return $rawPrefix; 
    }
    $completed = rtrim($rawPrefix, ':') . '::';
    $normalized = normalizeIp($completed);
    if ($normalized === null) return null;

    $groupCount = substr_count(rtrim($rawPrefix, ':'), ':') + 1;
    $parts = explode(':', $normalized);
    $prefixParts = array_slice($parts, 0, $groupCount);

    return implode(':', $prefixParts) . ':';
}

/**
 * Check if a client IP matches an allowed value.
 */
function isIpAllowed(string $clientIp, string $allowedValue): bool
{
    if (str_contains($allowedValue, '*')) {
        $rawPrefix = rtrim($allowedValue, '*');
        $normalizedClient = normalizeIp($clientIp);
        if ($normalizedClient === null) return false;
        
        $normalizedPrefix = normalizePrefixSegment($rawPrefix);
        if ($normalizedPrefix === null) return false;
        
        return str_starts_with($normalizedClient, $normalizedPrefix);
    }

    $normalizedClient  = normalizeIp($clientIp);
    $normalizedAllowed = normalizeIp($allowedValue);

    if ($normalizedClient === null || $normalizedAllowed === null) return false;
    return $normalizedClient === $normalizedAllowed;
}

// ---------------------------------------------------------
// TEST CASES
// ---------------------------------------------------------

$wildcardRule = '2401:4900:8fc1:b7dd:*';

$testIps = [
    '2401:4900:8fc1:b7dd:69a3:ef90:8249:606', // Noah / Jeff
    '2401:4900:8fc1:b7dd:6ca2:6a03:e2b6:22f1', // Travis
    '2401:4900:8FC1:B7DD:1111:2222:3333:4444', // Testing uppercase hex
    '2001:db8::1',                             // Different network entirely
];

echo "Rule in Database: {$wildcardRule}\n";
echo str_repeat("-", 50) . "\n";

foreach ($testIps as $ip) {
    $result = isIpAllowed($ip, $wildcardRule);
    $status = $result ? "✅ ALLOWED" : "❌ BLOCKED";
    echo "Agent IP: " . str_pad($ip, 40) . " => {$status}\n";
}

echo "\nDone testing.\n";
