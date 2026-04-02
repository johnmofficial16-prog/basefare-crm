<?php
/**
 * Layout Sidebar — Alias for the shared admin sidebar.
 *
 * Acceptance module views (create, list, view) include this path.
 * We simply delegate to the canonical sidebar partial so there's one source of truth.
 *
 * @var string $activePage  Set by the including view before require().
 */
$activePage = $activePage ?? 'acceptance';
require __DIR__ . '/../partials/admin_sidebar.php';
