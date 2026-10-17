<?php

namespace App\Hr\Support;

/**
 * The light formatting admins write policies and letter templates in, turned
 * into safe HTML. Everything is escaped first; only these marks survive:
 *
 *   # Heading          a line starting with "# "
 *   - item             a line starting with "- " (consecutive lines make a list)
 *   **bold**           inside any line
 *   blank line         new paragraph; a single line break inside a paragraph is kept
 *
 * No HTML, links or attributes can ever come through, so stored texts can't
 * carry scripts into anyone's browser.
 */
final class Markup
{
    public static function toHtml(string $text): string
    {
        $lines = preg_split('/\r?\n/', trim($text)) ?: [];
        $out = [];
        $para = [];
        $list = [];
        $flushPara = function () use (&$para, &$out) {
            if ($para) {
                $out[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $para)) . '</p>';
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$out) {
            if ($list) {
                $out[] = '<ul>' . implode('', array_map(fn($l) => '<li>' . self::inline($l) . '</li>', $list)) . '</ul>';
                $list = [];
            }
        };
        foreach ($lines as $line) {
            $t = rtrim($line);
            if (trim($t) === '') {
                $flushPara();
                $flushList();
            } elseif (str_starts_with(ltrim($t), '# ')) {
                $flushPara();
                $flushList();
                $out[] = '<h3>' . self::inline(substr(ltrim($t), 2)) . '</h3>';
            } elseif (str_starts_with(ltrim($t), '- ')) {
                $flushPara();
                $list[] = substr(ltrim($t), 2);
            } else {
                $flushList();
                $para[] = $t;
            }
        }
        $flushPara();
        $flushList();
        return implode("\n", $out);
    }

    private static function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Line-break markers (vertical tab) placed by merge fields for multi-line values such as an address.
        $s = str_replace("\v", '<br>', $s);
        return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
    }

    /**
     * Fill {{fields}} in a template. Values are plain text: newlines inside a
     * value become line breaks, and nothing in a value can create formatting
     * that changes the template's structure.
     */
    public static function fill(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+(?:\.[a-z_]+)*)\s*\}\}/', function ($m) use ($values) {
            $v = $values[$m[1]] ?? '';
            // One line in the template; newlines come back as <br>. "**" can't start bold text.
            return str_replace(["\r\n", "\r", "\n", '**'], ["\v", "\v", "\v", '* *'], (string) $v);
        }, $template) ?? $template;
    }

    /** The {{fields}} a template uses. */
    public static function fields(string $template): array
    {
        preg_match_all('/\{\{\s*([a-z_]+(?:\.[a-z_]+)*)\s*\}\}/', $template, $m);
        return array_values(array_unique($m[1]));
    }
}
