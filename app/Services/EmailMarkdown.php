<?php

namespace App\Services;

/**
 * EmailMarkdown — tiny, dependency-free, XSS-safe Markdown → HTML renderer for
 * customer emails.
 *
 * The AI (or an agent) writes light Markdown — short paragraphs, **bold**,
 * *italic*, bullet / numbered lists, the occasional heading, and pipe TABLES —
 * and this turns it into clean, email-client-safe HTML with inline styles, the
 * way ChatGPT renders its answers, but constrained to a fixed set of safe tags.
 *
 * SECURITY: every cell/line is htmlspecialchars()'d BEFORE any formatting tags
 * are applied, so nothing the AI or an agent types can inject HTML or script.
 * Only the small, fixed set of tags this class emits can ever appear.
 *
 * Supported: ## / ### headings, **bold**, *italic*, "- "/"* " bullets,
 * "1." numbered lists, | pipe | tables |, blank-line paragraphs, line breaks.
 * Deliberately NOT supported: raw HTML, images, arbitrary links.
 */
class EmailMarkdown
{
    /** Render Markdown to inline-styled, email-safe HTML. */
    public static function toEmailHtml(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $n     = count($lines);

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
                $lis = '';
                foreach ($listItems as $it) {
                    $lis .= '<li style="margin:0 0 6px;">' . $it . '</li>';
                }
                $out[] = '<' . $listType . ' style="margin:0 0 14px;padding-left:22px;">' . $lis . '</' . $listType . '>';
                $listType  = null;
                $listItems = [];
            }
        };

        for ($i = 0; $i < $n; $i++) {
            $trimmed = trim($lines[$i]);

            // Blank line — close any open block.
            if ($trimmed === '') {
                $flushParagraph();
                $flushList();
                continue;
            }

            // Table: a pipe row immediately followed by a separator row.
            if (self::isTableRow($trimmed) && $i + 1 < $n && self::isTableSeparator(trim($lines[$i + 1]))) {
                $flushParagraph();
                $flushList();
                $header = self::splitRow($trimmed);
                $aligns = self::parseAligns(trim($lines[$i + 1]));
                $i += 2; // consume header + separator
                $rows = [];
                while ($i < $n && self::isTableRow(trim($lines[$i]))) {
                    $rows[] = self::splitRow(trim($lines[$i]));
                    $i++;
                }
                $i--; // step back so the for-loop's ++ lands on the next unprocessed line
                $out[] = self::renderTable($header, $aligns, $rows);
                continue;
            }

            // Heading: # / ## / ###.
            if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                $flushList();
                $size = [1 => 18, 2 => 16, 3 => 15][strlen($m[1])] ?? 16;
                $out[] = '<div style="font-weight:800;font-size:' . $size . 'px;color:#0f1e3c;margin:18px 0 8px;">'
                       . self::inline($m[2]) . '</div>';
                continue;
            }

            // Bullet list item.
            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                if ($listType !== 'ul') { $flushList(); $listType = 'ul'; }
                $listItems[] = self::inline($m[1]);
                continue;
            }

            // Numbered list item.
            if (preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                if ($listType !== 'ol') { $flushList(); $listType = 'ol'; }
                $listItems[] = self::inline($m[1]);
                continue;
            }

            // Ordinary text line.
            $flushList();
            $paragraph[] = self::inline($trimmed);
        }

        $flushParagraph();
        $flushList();

        return implode("\n", $out);
    }

    /**
     * Plain-text version (email AltBody) — strip Markdown markers so the
     * fallback reads cleanly. Tables are left as their pipe form (readable).
     */
    public static function toPlainText(string $markdown): string
    {
        $t = preg_replace('/^#{1,3}\s+/m', '', $markdown);
        $t = preg_replace('/\*\*(.+?)\*\*/s', '$1', $t);
        $t = preg_replace('/(?<!\*)\*(?!\*)(.+?)\*(?!\*)/s', '$1', $t);
        return trim($t);
    }

    // ── Table helpers ────────────────────────────────────────────────────────

    private static function isTableRow(string $s): bool
    {
        return $s !== '' && $s[0] === '|' && substr_count($s, '|') >= 2;
    }

    private static function isTableSeparator(string $s): bool
    {
        // e.g. | --- | :--: | ---: |  (only pipes, dashes, colons, spaces; ≥1 dash)
        return (bool) preg_match('/^\|(\s*:?-+:?\s*\|)+\s*$/', $s);
    }

    /** Split "| a | b | c |" into ['a','b','c']. */
    private static function splitRow(string $s): array
    {
        $s = trim($s);
        $s = preg_replace('/^\|/', '', $s);
        $s = preg_replace('/\|$/', '', $s);
        return array_map('trim', explode('|', $s));
    }

    /** Parse alignment from a separator row into left|center|right per column. */
    private static function parseAligns(string $sep): array
    {
        $aligns = [];
        foreach (self::splitRow($sep) as $cell) {
            $l = str_starts_with($cell, ':');
            $r = str_ends_with($cell, ':');
            $aligns[] = ($l && $r) ? 'center' : ($r ? 'right' : 'left');
        }
        return $aligns;
    }

    private static function renderTable(array $header, array $aligns, array $rows): string
    {
        $cols = count($header);

        $ths = '';
        foreach ($header as $idx => $cell) {
            $align = $aligns[$idx] ?? 'left';
            $ths  .= '<th style="text-align:' . $align . ';padding:9px 11px;background:#0f1e3c;color:#ffffff;'
                   . 'font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px;">'
                   . self::inline($cell) . '</th>';
        }

        $trs = '';
        foreach ($rows as $r => $cells) {
            $bg  = $r % 2 === 0 ? '#ffffff' : '#f8fafc';
            $tds = '';
            for ($c = 0; $c < $cols; $c++) {
                $align = $aligns[$c] ?? 'left';
                $val   = $cells[$c] ?? '';
                $tds  .= '<td style="text-align:' . $align . ';padding:8px 11px;border-bottom:1px solid #f1f5f9;'
                       . 'font-size:13px;color:#1e293b;">' . self::inline($val) . '</td>';
            }
            $trs .= '<tr style="background:' . $bg . ';">' . $tds . '</tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin:0 0 16px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">'
             . '<thead><tr>' . $ths . '</tr></thead><tbody>' . $trs . '</tbody></table>';
    }

    /**
     * Apply inline formatting to ONE piece of text. Escapes first (XSS-safe),
     * then **bold** (and __bold__) before *italic* so double-stars aren't eaten.
     */
    private static function inline(string $text): string
    {
        $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong style="font-weight:700;color:#0f1e3c;">$1</strong>', $t);
        $t = preg_replace('/__(.+?)__/s', '<strong style="font-weight:700;color:#0f1e3c;">$1</strong>', $t);
        $t = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/s', '<em>$1</em>', $t);
        return $t;
    }
}
