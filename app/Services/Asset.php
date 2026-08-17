<?php

namespace App\Services;

/**
 * Asset — cache-busted URLs for our own JS/CSS.
 *
 * Why this exists: buddy-widget.js was deployed, the server served the new
 * bytes, and every already-open browser kept running the OLD cached copy —
 * the Super Buddy simply didn't appear (found live 16 Aug). Agents never hard
 * refresh, so without a changing URL a widget fix can sit undelivered for
 * days while looking deployed.
 *
 * Appends the file's mtime as ?v=, so a `git pull` changes the URL and the
 * browser fetches immediately, while unchanged files stay cacheable forever.
 *
 * Deliberately a static class (composer-autoloaded) rather than a global
 * helper function: views are rendered from controllers, cron scripts and
 * possibly email templates — an undefined global function in any of those
 * paths would be a fatal error on a live page. This can never be undefined.
 */
class Asset
{
    /** @var array<string,string> mtime cache, per request */
    private static array $cache = [];

    /**
     * @param string $publicRelPath e.g. 'assets/js/buddy-widget.js'
     * @return string '/assets/js/buddy-widget.js?v=1755330000'
     */
    public static function url(string $publicRelPath): string
    {
        $rel = ltrim($publicRelPath, '/');
        if (isset(self::$cache[$rel])) {
            return self::$cache[$rel];
        }

        $abs = dirname(__DIR__, 2) . '/public/' . $rel;
        $mtime = @filemtime($abs);
        $url = '/' . $rel . ($mtime !== false ? '?v=' . $mtime : '');

        return self::$cache[$rel] = $url;
    }
}
