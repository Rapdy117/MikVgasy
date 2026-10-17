<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notification_service.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !isAdministrator()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? 'get'));

/* GET — retourne la config actuelle */
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'get') {
    $config = loadNotificationConfig();
    /* Ne pas exposer le mot de passe SMTP en clair */
    if (!empty($config['email']['smtp_pass'])) {
        $config['email']['smtp_pass'] = '••••••••';
    }
    echo json_encode(['success' => true, 'config' => $config]);
    exit;
}

/* POST — test ou sauvegarde */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'test') {
        $results = triggerTestNotification();
        echo json_encode([
            'success' => true,
            'results' => $results,
        ]);
        exit;
    }

    if ($action === 'save') {
        $current = loadNotificationConfig();

        $smtpPass = trim((string)($_POST['smtp_pass'] ?? ''));
        if ($smtpPass === '' || $smtpPass === '••••••••') {
            $smtpPass = (string)($current['email']['smtp_pass'] ?? '');
        }

        $newConfig = [
            'email' => [
                'enabled'    => ($_POST['email_enabled'] ?? '0') === '1',
                'smtp_host'  => trim((string)($_POST['smtp_host']   ?? '')),
                'smtp_port'  => (int)($_POST['smtp_port']   ?? 587),
                'smtp_user'  => trim((string)($_POST['smtp_user']   ?? '')),
                'smtp_pass'  => $smtpPass,
                'from_name'  => trim((string)($_POST['from_name']   ?? 'Radius Manager')),
                'from_email' => trim((string)($_POST['from_email']  ?? '')),
                'to_email'   => trim((string)($_POST['to_email']    ?? '')),
            ],
            'whatsapp' => [
                'admin_phone' => trim((string)($_POST['admin_phone'] ?? '')),
            ],
        ];

        saveNotificationConfig($newConfig);
        echo json_encode(['success' => true, 'message' => 'Configuration sauvegardée.']);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Action inconnue']);
