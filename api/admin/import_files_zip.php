<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../includes/auth.php';
if (empty($_SESSION['logged_in']) || !isSuperAdministrator()) { http_response_code(403); exit('Accès réservé au superadmin.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Requête invalide.'); }
if (empty($_FILES['files_zip']['tmp_name']) || $_FILES['files_zip']['error'] !== UPLOAD_ERR_OK) { header('Location: /tools/install.php?error=zip'); exit; }
$root = realpath(__DIR__ . '/../../'); $zip = new ZipArchive();
if (!$root || $zip->open($_FILES['files_zip']['tmp_name']) !== true) { header('Location: /tools/install.php?error=zip'); exit; }
for ($i=0; $i<$zip->numFiles; $i++) { $name = str_replace('\\','/',(string)$zip->getNameIndex($i)); if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_ends_with($name, '/')) continue; $target = $root . DIRECTORY_SEPARATOR . $name; if (!str_starts_with(realpath(dirname($target)) ?: dirname($target), $root)) continue; @mkdir(dirname($target), 0755, true); copy('zip://'.$_FILES['files_zip']['tmp_name'].'#'.$name, $target); }
$zip->close(); header('Location: /tools/install.php?updated=1');
