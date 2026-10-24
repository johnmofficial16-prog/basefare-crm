<?php
/**
 * Aisha — full page (/buddy). The same widget as every other page, mounted
 * inline into #aisha-page instead of floating, so there is one chat UI to keep
 * right rather than two. Old bell notifications still link here.
 *
 * @var string $userName
 */
\App\Services\Theme::markStaffPage();   // themed like the rest of the CRM (no sidebar here)
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aisha — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<script src="<?= \App\Services\Asset::url('assets/js/error-beacon.js') ?>"></script>
<style>
  html, body { margin: 0; height: 100%; background: rgb(var(--bf-slate-100)); color: rgb(var(--bf-on-surface)); font-family: Inter, system-ui, sans-serif; }
  .ap-wrap { box-sizing: border-box; max-width: 880px; margin: 0 auto; padding: 16px 16px 20px; height: 100%; display: flex; flex-direction: column; gap: 12px; }
  .ap-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .ap-back { display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding: 0 14px 0 8px; border-radius: 12px; color: rgb(var(--bf-primary)); font: 600 15px/1 Inter, sans-serif; text-decoration: none; }
  .ap-back:hover { background: rgb(var(--bf-slate-200)); }
  .ap-back:focus-visible { outline: 3px solid rgb(var(--bf-indigo-500)); outline-offset: 2px; }
  .ap-back svg { width: 20px; height: 20px; }
  .ap-hint { font-size: 13px; color: rgb(var(--bf-slate-500)); }
  #aisha-page { flex: 1; min-height: 0; }
  @media (max-width: 639px) { .ap-wrap { padding: 8px 8px 12px; } .ap-hint { display: none; } }
</style>
</head>
<body>
<div class="ap-wrap">
  <div class="ap-top">
    <a class="ap-back" href="/dashboard">
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 6 9 12l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Back to the CRM
    </a>
    <span class="ap-hint">Aisha is on every CRM page too — Alt+A</span>
  </div>
  <main id="aisha-page" aria-label="Aisha"></main>
</div>
<script src="<?= \App\Services\Asset::url('assets/js/buddy-widget.js') ?>" defer></script>
</body>
</html>
