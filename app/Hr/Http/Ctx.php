<?php

namespace App\Hr\Http;

use App\Hr\Kernel;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * What a route handler gets: the request, route arguments, the signed-in
 * account (if any) and small, strict input readers. Every reader trims, caps
 * length and validates; handlers never touch raw input.
 */
final class Ctx
{
    private array $body;

    public function __construct(
        public readonly Request $req,
        public readonly array $args,
        public readonly ?array $auth
    ) {
        $parsed = $req->getParsedBody();
        $this->body = is_array($parsed) ? $parsed : [];
    }

    // ---- who ---------------------------------------------------------------

    public function accountId(): int
    {
        return (int) ($this->auth['account_id'] ?? 0);
    }

    public function employeeId(): ?int
    {
        $id = $this->auth['employee_id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function role(): string
    {
        return (string) ($this->auth['role'] ?? '');
    }

    public function isAdmin(): bool
    {
        return in_array($this->role(), ['owner', 'admin'], true);
    }

    public static function clientIp(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $trusted = array_filter(array_map('trim', explode(',', (string) Kernel::env('TRUSTED_PROXIES', ''))));
        if ($remote === '' || !in_array($remote, $trusted, true)) {
            return $remote;
        }
        // Behind a trusted proxy: the right-most hop is the one it appended.
        $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '') {
            return $cf;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')))));
        return $parts ? end($parts) : $remote;
    }

    // ---- route args and query ----------------------------------------------

    public function id(string $name = 'id'): int
    {
        $v = $this->args[$name] ?? '';
        if (!ctype_digit((string) $v) || (int) $v < 1) {
            throw ApiError::notFound();
        }
        return (int) $v;
    }

    /** A route argument already constrained by the route pattern (e.g. {ref:[ps][0-9]+}). */
    public function arg(string $name): string
    {
        return (string) ($this->args[$name] ?? '');
    }

    public function query(string $key, int $max = 100): ?string
    {
        $v = $this->req->getQueryParams()[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    // ---- JSON body ---------------------------------------------------------

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    public function raw(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }

    /** Trimmed single-line text, or null when empty. */
    public function str(string $key, int $max = 255, bool $required = false, string $label = 'This'): ?string
    {
        $v = $this->body[$key] ?? null;
        if ($v !== null && !is_string($v) && !is_int($v)) {
            throw ApiError::invalid('Please check the form.', [$key => $label . ' looks wrong.']);
        }
        $v = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
        if ($v === '') {
            if ($required) {
                throw ApiError::invalid('Please fill in the missing details.', [$key => $label . ' is needed.']);
            }
            return null;
        }
        if (mb_strlen($v) > $max) {
            throw ApiError::invalid('Please shorten that.', [$key => $label . " can't be longer than {$max} characters."]);
        }
        return $v;
    }

    /** Multi-line text (keeps line breaks), or null when empty. */
    public function text(string $key, int $max = 2000): ?string
    {
        $v = $this->body[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim(str_replace("\r\n", "\n", $v));
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            throw ApiError::invalid('Please shorten that.', [$key => "Keep it under {$max} characters."]);
        }
        return $v;
    }

    public function date(string $key, bool $required = false, string $label = 'The date'): ?string
    {
        $v = $this->str($key, 10, $required, $label);
        if ($v === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw ApiError::invalid('Please check the date.', [$key => $label . " isn't a valid date."]);
        }
        return $v;
    }

    /** One value from an allowed list. */
    public function pick(string $key, array $allowed, bool $required = false, string $label = 'This'): ?string
    {
        $v = $this->str($key, 60, $required, $label);
        if ($v === null) {
            return null;
        }
        if (!in_array($v, $allowed, true)) {
            throw ApiError::invalid('Please check the form.', [$key => $label . " isn't one of the choices."]);
        }
        return $v;
    }

    public function email(string $key, bool $required = false, string $label = 'Email'): ?string
    {
        $v = $this->str($key, 190, $required, $label);
        if ($v === null) {
            return null;
        }
        $v = mb_strtolower($v);
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            throw ApiError::invalid('Please check the email address.', [$key => $label . " doesn't look like an email address."]);
        }
        return $v;
    }

    /** Indian mobile: keeps digits and a leading +, 10–13 digits. */
    public function phone(string $key, string $label = 'Phone'): ?string
    {
        $v = $this->str($key, 20, false, $label);
        if ($v === null) {
            return null;
        }
        $clean = preg_replace('/[^\d+]/', '', $v) ?? '';
        $digits = preg_replace('/\D/', '', $clean) ?? '';
        if (strlen($digits) < 10 || strlen($digits) > 13) {
            throw ApiError::invalid('Please check the phone number.', [$key => $label . ' should have 10 digits.']);
        }
        return $clean;
    }

    public function intVal(string $key, int $min, int $max, bool $required = false, string $label = 'This'): ?int
    {
        $v = $this->body[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                throw ApiError::invalid('Please fill in the missing details.', [$key => $label . ' is needed.']);
            }
            return null;
        }
        if (!is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
            throw ApiError::invalid('Please check the form.', [$key => $label . " should be a whole number from {$min} to {$max}."]);
        }
        return (int) $v;
    }

    /** List of positive integer ids. */
    public function ids(string $key, int $maxCount = 200): array
    {
        $v = $this->body[$key] ?? [];
        if (!is_array($v) || count($v) > $maxCount) {
            throw ApiError::invalid('Please check your selection.');
        }
        $out = [];
        foreach ($v as $id) {
            if (!is_numeric($id) || (int) $id < 1) {
                throw ApiError::invalid('Please check your selection.');
            }
            $out[] = (int) $id;
        }
        return array_values(array_unique($out));
    }

    /** List of Y-m-d dates. */
    public function dates(string $key, int $maxCount = 62): array
    {
        $v = $this->body[$key] ?? [];
        if (!is_array($v) || count($v) > $maxCount) {
            throw ApiError::invalid('Please check the dates.');
        }
        $out = [];
        foreach ($v as $d) {
            $p = is_string($d) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $d) : false;
            if (!$p || $p->format('Y-m-d') !== $d) {
                throw ApiError::invalid('Please check the dates.');
            }
            $out[] = $d;
        }
        sort($out);
        return array_values(array_unique($out));
    }
}
