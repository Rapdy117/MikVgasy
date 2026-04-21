<?php
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/message.php';
require_once __DIR__ . '/../includes/admin_notifications.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    set_message('Veuillez vous connecter pour accéder à cette page.', 'danger');
    header('Location: ../index.php');
    exit();
}

requireAdministratorAccess('Seul l administrateur peut consulter les notifications.');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

ensureAdminNotificationsTable($pdo);
syncInvalidPasswordNotifications($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = trim((string)($_POST['csrf_token'] ?? ''));
        if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
            throw new RuntimeException('CSRF invalide');
        }

        $action = trim((string)($_POST['notification_action'] ?? ''));
        if ($action === 'mark_read') {
            markAdminNotificationRead($pdo, (int)($_POST['notification_id'] ?? 0));
            set_message('Notification marquée comme lue.', 'success');
        } elseif ($action === 'mark_all_read') {
            markAllAdminNotificationsRead($pdo);
            set_message('Toutes les notifications sont marquées comme lues.', 'success');
        } elseif ($action === 'delete_selected') {
            $notificationIds = $_POST['notification_ids'] ?? [];
            if (!is_array($notificationIds)) {
                $notificationIds = [];
            }

            $notificationIds = array_values(array_filter(array_map(
                static fn($value): int => (int)$value,
                $notificationIds
            )));

            if ($notificationIds === []) {
                throw new RuntimeException('Sélectionnez au moins une notification.');
            }

            $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM admin_notifications WHERE id IN ($placeholders)");
            $stmt->execute($notificationIds);
            set_message(count($notificationIds) > 1 ? 'Notifications supprimées.' : 'Notification supprimée.', 'success');
        }
    } catch (Throwable $e) {
        set_message($e->getMessage(), 'danger');
    }

    header('Location: /pages/admin_notifications.php');
    exit();
}

$notifications = listAdminNotifications($pdo, 150);
$unreadCount = countUnreadAdminNotifications($pdo);
?>
<?php
$pageTitle = 'Notifications';
$extraCss = array (
  0 => '../css/admin_notifications.css',
);
require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="card shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-center text-white" style="font-size: calc(0.875rem + 2px);">
            <i class="fa fa-bell me-2"></i>
            <span class="small fw-semibold">Notifications Système</span>
        </div>
    </div>
</div>

<div class="card shadow-sm admin-notifications-card">
    <div class="card-header d-flex flex-wrap align-items-center admin-notifications-header-row standard-card-header">
        <div class="admin-notifications-title-wrap">
            <span><i class="fa fa-triangle-exclamation me-2"></i> Événements importants</span>
            <span class="badge <?= $unreadCount > 0 ? 'bg-warning text-dark' : 'bg-success' ?>">
                <?= $unreadCount ?> non lue<?= $unreadCount > 1 ? 's' : '' ?>
            </span>
        </div>
        <div class="admin-notifications-toolbar">
            <div class="input-group admin-notifications-search-group">
                <span class="input-group-text"><i class="fa fa-search"></i></span>
                <input type="text" class="form-control" id="adminNotificationsSearch" placeholder="Rechercher...">
            </div>
            <select class="form-select admin-notifications-filter" id="adminNotificationsSeverity">
                <option value="">Sévérité</option>
                <option value="critical">Critique</option>
                <option value="warning">Avertissement</option>
                <option value="success">Succès</option>
                <option value="info">Info</option>
            </select>
            <form method="POST" class="mb-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="notification_action" value="mark_all_read">
                <button type="submit" class="btn btn-test">
                    <i class="fa fa-check-double me-1"></i> Tout lire
                </button>
            </form>
            <form method="POST" class="mb-0" id="adminNotificationsDeleteForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="notification_action" value="delete_selected">
                <div id="adminNotificationsDeleteInputs"></div>
                <button type="submit" class="btn btn-delete" id="deleteSelectedNotificationsBtn" disabled>
                    <i class="fa fa-trash me-1"></i> Supprimer
                </button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover table-striped mb-0 admin-notifications-table table-standard" id="adminNotificationsTable">
                <thead>
                    <tr>
                        <th class="admin-notifications-select-col">
                            <input type="checkbox" class="form-check-input mt-0" id="selectAllNotifications" aria-label="Tout sélectionner">
                        </th>
                        <th>Heure</th>
                        <th>Titre</th>
                        <th>Catégorie</th>
                        <th>Message</th>
                        <th>Sévérité</th>
                        <th>Lecture</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notifications as $notification): ?>
                    <?php
                    $isRead = (int)($notification['is_read'] ?? 0) === 1;
                    $severity = (string)($notification['severity'] ?? 'info');
                    ?>
                    <tr class="<?= $isRead ? '' : 'admin-notifications-unread' ?>"
                        data-search="<?= htmlspecialchars(mb_strtolower(implode(' ', array_filter([
                            (string)($notification['title'] ?? ''),
                            (string)($notification['message'] ?? ''),
                            (string)($notification['category'] ?? ''),
                            (string)($notification['source_ref'] ?? ''),
                        ])))) ?>"
                        data-severity="<?= htmlspecialchars(strtolower($severity)) ?>">
                        <td class="admin-notifications-select-col">
                            <input type="checkbox" class="form-check-input notification-select" value="<?= (int)$notification['id'] ?>" aria-label="Sélectionner cette notification">
                        </td>
                        <td class="text-nowrap"><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime((string)$notification['created_at']))) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars((string)($notification['title'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars(adminNotificationCategoryLabel((string)($notification['category'] ?? 'system'))) ?></td>
                        <td class="admin-notifications-message small text-white-50"><?= htmlspecialchars((string)($notification['message'] ?? '-')) ?></td>
                        <td><span class="badge <?= adminNotificationSeverityBadgeClass($severity) ?>"><?= htmlspecialchars(adminNotificationSeverityLabel($severity)) ?></span></td>
                        <td><?= $isRead ? '<span class="badge bg-secondary opacity-50">Lue</span>' : '<span class="badge bg-warning text-dark">Nouvelle</span>' ?></td>
                        <td>
                            <?php if (!$isRead): ?>
                            <form method="POST" class="mb-0">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="notification_action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>">
                                <button type="submit" class="btn btn-test btn-sm">
                                    <i class="fa fa-check"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-white-50">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$extraScript = "
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('adminNotificationsSearch');
    const severityFilter = document.getElementById('adminNotificationsSeverity');
    const rows = Array.from(document.querySelectorAll('#adminNotificationsTable tbody tr'));
    const selectAll = document.getElementById('selectAllNotifications');
    const deleteBtn = document.getElementById('deleteSelectedNotificationsBtn');
    const deleteInputs = document.getElementById('adminNotificationsDeleteInputs');
    const deleteForm = document.getElementById('adminNotificationsDeleteForm');

    function applyFilters() {
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const severity = severityFilter ? severityFilter.value.trim().toLowerCase() : '';

        rows.forEach((row) => {
            const haystack = String(row.dataset.search || '').toLowerCase();
            const rowSeverity = String(row.dataset.severity || '').toLowerCase();
            const matchesSearch = query === '' || haystack.includes(query);
            const matchesSeverity = severity === '' || rowSeverity === severity;
            row.style.display = matchesSearch && matchesSeverity ? '' : 'none';
        });

        updateSelectionState();
    }

    function getVisibleCheckboxes() {
        return rows
            .filter((row) => row.style.display !== 'none')
            .map((row) => row.querySelector('.notification-select'))
            .filter(Boolean);
    }

    function getSelectedCheckboxes() {
        return rows
            .map((row) => row.querySelector('.notification-select'))
            .filter((checkbox) => checkbox && checkbox.checked);
    }

    function updateSelectionState() {
        const selected = getSelectedCheckboxes();
        if (deleteBtn) {
            deleteBtn.disabled = selected.length === 0;
        }

        if (deleteInputs) {
            deleteInputs.innerHTML = selected
                .map((checkbox) => `<input type=\"hidden\" name=\"notification_ids[]\" value=\"\${String(checkbox.value).replace(/\"/g, '&quot;')}\">`)
                .join('');
        }

        if (selectAll) {
            const visible = getVisibleCheckboxes();
            selectAll.checked = visible.length > 0 && visible.every((checkbox) => checkbox.checked);
        }
    }

    searchInput?.addEventListener('input', applyFilters);
    severityFilter?.addEventListener('change', applyFilters);
    selectAll?.addEventListener('change', () => {
        getVisibleCheckboxes().forEach((checkbox) => {
            checkbox.checked = selectAll.checked;
        });
        updateSelectionState();
    });

    rows.forEach((row) => {
        row.querySelector('.notification-select')?.addEventListener('change', updateSelectionState);
    });

    deleteForm?.addEventListener('submit', (event) => {
        if (getSelectedCheckboxes().length === 0) {
            event.preventDefault();
            return;
        }

        if (!window.confirm('Supprimer les notifications sélectionnées ?')) {
            event.preventDefault();
        }
    });

    applyFilters();
});
";
require_once __DIR__ . '/../includes/layout_footer.php';
?>
