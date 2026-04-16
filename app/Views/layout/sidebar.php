<?php
/**
 * Layout Sidebar — Role-aware sidebar dispatcher.
 *
 * Acceptance module views (create, list, view) include this path.
 * Routes to agent_sidebar or admin_sidebar based on session role.
 *
 * @var string $activePage  Set by the including view before require().
 */
$activePage = $activePage ?? 'acceptance';
$_sidebarRole = $_SESSION['role'] ?? 'agent';
if (in_array($_sidebarRole, ['admin', 'manager', 'supervisor'])) {
    require __DIR__ . '/../partials/admin_sidebar.php';
} else {
    require __DIR__ . '/../partials/agent_sidebar.php';
}
