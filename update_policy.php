<?php
require __DIR__ . '/vendor/autoload.php';

use App\Models\AcceptanceRequest;
use Illuminate\Database\Capsule\Manager as DB;

try {
    $capsule = new DB;
    $capsule->addConnection([
        'driver'    => 'mysql',
        'host'      => '127.0.0.1',
        'database'  => 'basefare_crm',
        'username'  => 'root',
        'password'  => '',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix'    => '',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $newPolicy = "1. PASSENGER NAMES: Names must match your government-issued ID exactly. Lets Fly Travel DBA Base Fare is not responsible for denied boarding due to name mismatches or Visa/Travel Document issues.\n2. REFUNDS & CHANGES: All tickets are NON-REFUNDABLE and NON-TRANSFERABLE once issued. Date changes are subject to airline penalties plus fare differences.\n3. CHARGEBACK WAIVER: By signing, I acknowledge the service has been performed. I agree NOT to dispute or chargeback this transaction with my card issuer for any reason.\n4. AUTHORIZATION: I authorize Lets Fly Travel DBA Base Fare to charge the Total Amount listed to my credit card.\n5. I confirm that I am the authorized cardholder and approve the charge of the agreed amount for the requested travel services.\n6. I acknowledge that I have personally requested this service and that all details, including itinerary, pricing, and applicable terms, have been clearly explained to me prior to authorization.\n7. I understand that the Lets Fly Travel DBA Base Fare acts solely as an intermediary, and all bookings, cancellations, and refunds are subject to the respective airline’s rules and regulations.\n8. I agree that the service fee charged by Lets Fly Travel DBA Base Fare is non-refundable once the booking or requested service has been processed.\n9. I acknowledge that the service is considered fully rendered once the reservation/ticket has been issued or the requested service has been completed.\n10. I confirm that I have received and reviewed all booking details via email, phone, or message and have provided my consent to proceed.\n11. I understand that any cancellations, changes, refund or any other travel related service requests will be governed strictly by the airline’s fare rules and policies, and additional charges may apply.\n12. I agree that this transaction is valid, authorized, and initiated by me voluntarily without any misrepresentation.\n13. I undertake to contact Lets Fly Travel DBA Base Fare directly for any concerns or clarifications before initiating any dispute or chargeback with my bank or card issuer.\n14. I acknowledge that this transaction may be recorded (call/email/SMS) for quality, training, and verification purposes.\n15. I confirm that the billing details provided by me are accurate and belong to me, and I take full responsibility for this transaction.\n16. I understand and agree to comply with the 24-hour cancellation policy (if applicable), subject to airline terms and conditions.";

    $updated = DB::table('acceptance_requests')->update(['policy_text' => $newPolicy]);
    echo "Updated policy_text for $updated records.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
