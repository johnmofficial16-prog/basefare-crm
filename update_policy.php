<?php
/**
 * One-time: update policy_text for acceptance #45 to new chargeback waiver
 * Run via SSH: php update_policy.php
 * Delete after use.
 */

// Load env manually
$envFile = __DIR__ . '/.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$db   = $env['DB_NAME'] ?? '';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';

$pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$newPolicy = "By digitally signing this authorization form, you confirm that:

1. FINAL SALE: All airline tickets purchased are 100% NON-REFUNDABLE and NON-TRANSFERABLE. Making a purchase implies acceptance of the airline's fare rules.

2. CHARGEBACK WAIVER: You explicitly acknowledge that all services described herein have been rendered by Lets Fly Travel DBA Base Fare. Filing a credit card dispute or chargeback after signing this authorization constitutes Friendly Fraud. You explicitly waive your right to file a credit card dispute or chargeback for this transaction. This signed authorization, along with your IP address, device fingerprint, and user-agent information will be submitted as conclusive evidence to your financial institution to contest any such claim.

3. TRAVEL DOCUMENTS: Lets Fly Travel DBA Base Fare is not responsible for Visa, Passport, or Health documentation requirements. Denied boarding due to missing documents does not constitute grounds for a refund or dispute.

4. GOVERNING LAW: This agreement is governed by the laws of the State of New York, USA.";

$stmt = $pdo->prepare("UPDATE acceptance_requests SET policy_text = ? WHERE id = 45");
$stmt->execute([$newPolicy]);

echo "Done. Rows updated: " . $stmt->rowCount() . "\n\n";
echo "New policy:\n\n" . $newPolicy . "\n";
