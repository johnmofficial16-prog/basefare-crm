<?php

namespace App\Services;

/**
 * EmailMarkdown — tiny, dependency-free, XSS-safe Markdown → HTML renderer for
 * customer emails.
 *
 * The AI writes light Markdown (short paragraphs, **bold**, *italic*, bullet /
 * numbered lists, the occasional heading); this turns it into clean,
 * email-client-safe HTML with inline styles — the way ChatGPT renders its
 * answers, but constrained to a handful of safe constructs.
 *
 * SECURITY: every line is htmlspecialchars()'d BEFORE any formatting tags are
 * applied, so nothing the AI or an agent types can inject HTML or script. Only
 * the small, fixed set of tags this class emits can ever appear.
 *
 * Supported: ## / ### headings, **bold**, *italic*, "- " / "* " bullet lists,
 * "1." numbered lists, blank-line paragraphs, single-newline line breaks.
 * Deliberately NOT supported: raw HTML, images, arbitrary links (the AI is told
 * never to invent URLs).
 */
class EmailMarkdown
{
    /** Render Markdown to inline-styled, email-safe HTML. */
    public static function toEmailHtml(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));

        $out       = [];
        $paragraph = [];
        $listType  = null;   // 'ul' | 'ol' | null
        $listItems = [];

        $flushParagraph = function () use (&$paragraph, &$out) {
            if ($paragraph) {
                $out[] = '<p style="margin:0 0 14px;">' . implode('<br>', $paragraph) . '</p>';
                $paragraph = [];
            }
        };
        $flushList = function () use (&$listType, &$listItems, &$out) {
            if ($listType) {
                $tag  = $listType;
                $lis  = '';
                foreach ($listItems as $it) {
                    $lis .= '<li style="margin:0 0 6px;">' . $it . '</li>';
                }
                $out[] = '<' . $tag . ' style="margin:0 0 14px;padding-left:22px;">' . $lis . '</' . $tag . '>';
                $listType  = null;
                $listItems = [];
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Blank line — close any open block.
            if ($trimmed === '') {
                $flushParagraph();
                $flushList();
                continue;
            }

            // Heading: ## or ### (also tolerate a single #).
            if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                $flushList();
                $size = [1 => 18, 2 => 16, 3 => 15][strlen($m[1])] ?? 16;
                $out[] = '<div style="font-weight:800;font-size:' . $size . 'px;color:#0f1e3c;margin:18px 0 8px;">'
                       . self::inline($m[2]) . '</div>';
                continue;
            }

            // Bullet list item: "- " or "* ".
            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                if ($listType !== 'ul') { $flushList(); $listType = 'ul'; }
                $listItems[] = self::inline($m[1]);
                continue;
            }

            // Numbered list item: "1." / "2)" etc.
            if (preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                if ($listType !== 'ol') { $flushList(); $listType = 'ol'; }
                $listItems[] = self::inline($m[1]);
                continue;
            }

            // Ordinary text line — part of the current paragraph.
            $flushList();
            $paragraph[] = self::inline($trimmed);
        }

        $flushParagraph();
        $flushList();

        return implode("\n", $out);
    }

    /**
     * Plain-text version (for the email's AltBody) — strip the Markdown markers
     * so the fallback reads cleanly.
     */
    public static function toPlainText(string $markdown): string
    {
        $t = preg_replace('/^#{1,3}\s+/m', '', $markdown);   // headings
        $t = preg_replace('/\*\*(.+?)\*\*/s', '$1', $t);     // bold
        $t = preg_replace('/(?<!\*)\*(?!\*)(.+?)\*(?!\*)/s', '$1', $t); // italic
        return trim($t);
    }

    /**
     * Apply inline formatting to ONE line. Escapes first (XSS-safe), then turns
     * **bold** and *italic* into styled tags. Order matters: bold (**) before
     * italic (*) so the double-stars aren't eaten by the single-star rule.
     */
    private static function inline(string $text): string
    {
        $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // **bold** and __bold__
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong style="font-weight:700;color:#0f1e3c;">$1</strong>', $t);
        $t = preg_replace('/__(.+?)__/s', '<strong style="font-weight:700;color:#0f1e3c;">$1</strong>', $t);

        // *italic* — single star not adjacent to another star or word char.
        $t = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/s', '<em>$1</em>', $t);

        return $t;
    }
}
