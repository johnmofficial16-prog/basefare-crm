<?php

namespace App\Services;

/**
 * Theme — the CRM's look: Peacock (light, the default) or Night Shift (dark).
 *
 * Each person picks dark mode themselves with the switch in the sidebar; the
 * choice lives on their account (users.theme) and in the session.
 *
 * Only staff pages are themed. A page counts as one when it draws a CRM
 * sidebar (every sidebar partial calls markStaffPage()) or calls it itself.
 * Customer pages, documents and the TV board never call it, so they are
 * left exactly as they were. ThemeMiddleware does the injecting.
 */
class Theme
{
    public const LIGHT = 'light';
    public const DARK = 'dark';

    private static bool $staffPage = false;

    public static function markStaffPage(): void
    {
        self::$staffPage = true;
    }

    public static function isStaffPage(): bool
    {
        return self::$staffPage;
    }

    /** The signed-in person's theme. */
    public static function current(): string
    {
        // Sessions that began before this existed (or before login stored it)
        // read the account once.
        if (!array_key_exists('theme', $_SESSION ?? []) && !empty($_SESSION['user_id'])) {
            try {
                $_SESSION['theme'] = self::normalise(
                    \Illuminate\Database\Capsule\Manager::table('users')->where('id', (int) $_SESSION['user_id'])->value('theme')
                );
            } catch (\Throwable $e) {
                $_SESSION['theme'] = null;   // column not migrated yet: light
            }
        }
        return (($_SESSION['theme'] ?? null) === self::DARK) ? self::DARK : self::LIGHT;
    }

    public static function normalise(?string $value): ?string
    {
        return $value === self::DARK ? self::DARK : null;   // NULL = light (the default)
    }

    /**
     * Adds data-theme to <html> and the theme files before </head>.
     * Leaves the HTML untouched if it has no <head> to add to.
     */
    public static function inject(string $html): string
    {
        $closeHead = stripos($html, '</head>');
        if ($closeHead === false) {
            return $html;
        }
        $theme = self::current();
        $tags = "\n" . '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet">'
            . '<link rel="stylesheet" href="' . Asset::url('assets/css/theme.css') . '">'
            . '<script src="' . Asset::url('assets/js/theme.js') . '"></script>' . "\n";
        $html = substr_replace($html, $tags, $closeHead, 0);

        // data-theme on the opening <html ...> tag (replacing one if present).
        return preg_replace_callback('/<html\b([^>]*)>/i', static function (array $m) use ($theme): string {
            $attrs = preg_replace('/\sdata-theme\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', $m[1]);
            return '<html' . $attrs . ' data-theme="' . $theme . '">';
        }, $html, 1) ?? $html;
    }

    /** "Hide the menu" button for the sidebar header (Alt+B does the same). */
    public static function sidebarHideButton(): string
    {
        return '<button type="button" class="bf-side-btn" data-bf-side-toggle aria-label="Hide the menu (Alt+B)" title="Hide the menu (Alt+B)">'
            . '<span class="material-symbols-outlined" aria-hidden="true">left_panel_close</span></button>';
    }

    /**
     * The slim rail shown while the sidebar is hidden: the logo and a button to
     * bring the menu back. Each browser remembers the choice (theme.js).
     */
    public static function sidebarRail(string $home = '/dashboard'): string
    {
        return '<div class="bf-rail" data-theme="dark" aria-label="Menu hidden">'
            . '<a href="' . htmlspecialchars($home, ENT_QUOTES) . '" class="bf-rail-logo" aria-label="Base Fare home">'
            . '<img src="/assets/img/basefare-logo-email.png" alt=""></a>'
            . '<button type="button" class="bf-side-btn" data-bf-side-toggle aria-label="Show the menu (Alt+B)" title="Show the menu (Alt+B)">'
            . '<span class="material-symbols-outlined" aria-hidden="true">left_panel_open</span></button></div>';
    }

    /** The switch shown in every sidebar footer. */
    public static function toggleButton(bool $full = false): string
    {
        $dark = self::current() === self::DARK;
        $csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
        return '<button type="button" class="bf-theme-toggle' . ($full ? ' bf-full' : '') . '" data-bf-theme-toggle data-csrf="' . $csrf . '"'
            . ' aria-pressed="' . ($dark ? 'true' : 'false') . '">'
            . '<span class="material-symbols-outlined" aria-hidden="true">' . ($dark ? 'light_mode' : 'dark_mode') . '</span>'
            . '<span data-bf-theme-label>' . ($dark ? 'Light mode' : 'Dark mode') . '</span></button>';
    }
}
