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
if ($_sidebarRole === 'admin' && !empty($_SESSION['simple_mode'])) {
    // Simple view: same admin powers, short menu + plain-English confirmations
    require __DIR__ . '/../partials/simple_sidebar.php';
} elseif ($_sidebarRole === 'admin') {
    require __DIR__ . '/../partials/admin_sidebar.php';
} elseif ($_sidebarRole === 'manager') {
    require __DIR__ . '/../partials/manager_sidebar.php';
} elseif ($_sidebarRole === 'supervisor') {
    require __DIR__ . '/../partials/supervisor_sidebar.php';
} else {
    require __DIR__ . '/../partials/agent_sidebar.php';
}
