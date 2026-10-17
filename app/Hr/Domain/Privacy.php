<?php

namespace App\Hr\Domain;

use App\Hr\Http\ApiError;
use App\Hr\Http\Ctx;

/**
 * Validation for the private fields (PAN, Aadhaar, bank). Formats are checked
 * here; the values are only ever stored through the Vault.
 */
final class Privacy
{
    /** @return array<string,string> field => cleaned value, only for fields present */
    public static function read(Ctx $c): array
    {
        $out = [];
        $errors = [];
        $check = function (string $field, callable $clean, string $rule, string $message) use ($c, &$out, &$errors) {
            if (!$c->has($field)) {
                return;
            }
            $raw = $c->str($field, 60);
            if ($raw === null) {
                return;
            }
            $v = $clean($raw);
            if (!preg_match($rule, $v)) {
                $errors[$field] = $message;
                return;
            }
            $out[$field] = $v;
        };
        $upperNoSpace = fn($s) => strtoupper(preg_replace('/\s+/', '', $s) ?? '');
        $digits = fn($s) => preg_replace('/\D/', '', $s) ?? '';

        $check('pan', $upperNoSpace, '/^[A-Z]{5}[0-9]{4}[A-Z]$/', 'A PAN looks like ABCDE1234F.');
        $check('aadhaar', $digits, '/^[2-9][0-9]{11}$/', 'Aadhaar has 12 digits.');
        $check('bank_account', $digits, '/^[0-9]{9,18}$/', 'Account numbers have 9 to 18 digits.');
        $check('bank_ifsc', $upperNoSpace, '/^[A-Z]{4}0[A-Z0-9]{6}$/', 'An IFSC looks like HDFC0001234.');
        $check('bank_name', fn($s) => trim($s), '/^[\p{L}0-9 .&()\'-]{2,60}$/u', 'Please write the bank name.');
        $check('bank_holder', fn($s) => trim($s), '/^[\p{L} .\'-]{2,60}$/u', 'Write the name exactly as the bank has it.');

        if ($errors) {
            throw ApiError::invalid('Please check the highlighted details.', $errors);
        }
        return $out;
    }

    /** Loose name match for "name on the account": same words, any order, initials allowed. */
    public static function namesMatch(string $a, string $b): bool
    {
        $norm = fn($s) => array_values(array_filter(explode(' ', strtolower(preg_replace('/[^a-z ]/i', ' ', $s) ?? ''))));
        $x = $norm($a);
        $y = $norm($b);
        if (!$x || !$y) {
            return false;
        }
        $hits = 0;
        foreach ($x as $w) {
            foreach ($y as $u) {
                if ($w === $u || (strlen($w) === 1 && $u[0] === $w) || (strlen($u) === 1 && $w[0] === $u)) {
                    $hits++;
                    break;
                }
            }
        }
        return $hits >= min(count($x), count($y), 2);
    }
}
