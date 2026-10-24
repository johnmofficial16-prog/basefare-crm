<?php
/**
 * Simple view — page frame (head + sidebar + main wrapper). Close with _foot.php.
 *
 * @var string $pageTitle
 * @var string $activePage
 */
if (!function_exists('sv_money')) {
    /** "CAD 1,234" — whole numbers read faster; cents only under 100. */
    function sv_money(float $v, string $cur): string
    {
        $neg = $v < 0;
        $abs = abs($v);
        $s = $abs >= 100 ? number_format($abs, 0) : number_format($abs, 2);
        return ($neg ? '−' : '') . $cur . ' ' . $s;
    }

    /** Change vs a comparison value: ['text' => '+12%', 'tone' => 'up|down|flat'] */
    function sv_delta(float $now, float $before): array
    {
        if (abs($before) < 0.005) {
            return $now > 0 ? ['text' => 'New today', 'tone' => 'up'] : ['text' => 'Same', 'tone' => 'flat'];
        }
        $pct = ($now - $before) / abs($before) * 100;
        if (abs($pct) < 1) return ['text' => 'About the same', 'tone' => 'flat'];
        return ['text' => ($pct > 0 ? '▲ ' : '▼ ') . number_format(abs($pct), 0) . '%', 'tone' => $pct > 0 ? 'up' : 'down'];
    }

    function sv_initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $i = mb_substr($parts[0] ?? '', 0, 1) . mb_substr(count($parts) > 1 ? end($parts) : '', 0, 1);
        return mb_strtoupper($i ?: '?');
    }

    function sv_e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= sv_e($pageTitle ?? 'Home') ?> — Base Fare</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,300..600,0..1,-50..200" rel="stylesheet">
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<script src="/assets/js/tailwind.js"></script>
<script>
tailwind.config = {
  theme: { extend: {
    colors: { primary: '#163274', ink: '#0f172a' },
    fontFamily: { headline: ['Manrope', 'Inter', 'sans-serif'], sans: ['Inter', 'system-ui', 'sans-serif'] },
  } }
};
</script>
<style>
  body { font-family: Inter, system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
  .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
  .card { background: rgb(var(--bf-white)); border: 1px solid rgb(var(--bf-slate-200)); border-radius: 18px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
  .tip { position: relative; display: inline-flex; cursor: help; }
  .tip .tip-box { visibility: hidden; opacity: 0; position: absolute; z-index: 40; left: 50%; bottom: calc(100% + 8px); transform: translateX(-50%);
    width: 260px; background: rgb(var(--bf-slate-900)); color: rgb(var(--bf-white)); font-size: 13px; line-height: 1.5; font-weight: 500; padding: 10px 12px; border-radius: 10px;
    transition: opacity .15s; text-transform: none; letter-spacing: 0; box-shadow: 0 10px 30px rgba(15,23,42,.25); }
  .tip:hover .tip-box, .tip:focus-within .tip-box { visibility: visible; opacity: 1; }
  .fade-out { transition: opacity .35s, transform .35s; opacity: 0; transform: translateY(-4px); }
</style>
</head>
<body class="bg-[#f5f6fa] text-ink">
<?php require __DIR__ . '/../partials/simple_sidebar.php'; ?>
<main class="ml-60 min-h-screen">
  <div class="max-w-[1240px] mx-auto pl-10 pr-20 pt-8 pb-16">
