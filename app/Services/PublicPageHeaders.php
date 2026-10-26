<?php

namespace App\Services;

/**
 * Security headers for the customer pages that run outside Slim
 * (public/auth — card authorization, public/eticket — e-ticket), so they don't
 * get SecurityHeadersMiddleware. Added in the security audit of 26 Oct 2026.
 *
 * - can't be framed by another site (a signature page inside someone else's page
 *   is the classic way to trick a customer into signing),
 * - the secret token in the address is never sent on to other sites,
 * - nothing is kept in a shared or proxy cache, and search engines stay out.
 *
 * Deliberately no script/style allow-list: these pages load fonts, styles and
 * airline logos from several places, and a wrong list would break them for customers.
 */
final class PublicPageHeaders
{
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
