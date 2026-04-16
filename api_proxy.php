<?php
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/local_admins.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header('Location: index.php?error=invalid_credentials');
    exit();
}

try {
    $admin = verifyLocalAdminCredentials($pdo, $username, $password);
    if (!$admin) {
        header('Location: index.php?error=invalid_credentials');
        exit();
    }

    $_SESSION['logged_in'] = true;
    $_SESSION['username'] = (string)$admin['username'];
    $_SESSION['local_admin_id'] = (int)$admin['id'];
    $_SESSION['user_role'] = trim((string)($admin['role'] ?? 'administrator')) === 'reseller' ? 'reseller' : 'administrator';

    header('Location: /pages/dashboard.php');
    exit();
} catch (Throwable $e) {
    header('Location: index.php?error=invalid_credentials');
    exit();
}
