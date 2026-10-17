<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../includes/auth.php';
if (empty($_SESSION['logged_in']) || !isSuperAdministrator()) { http_response_code(403); exit('Accès réservé au superadmin.'); }
$root = realpath(__DIR__ . '/../../');
$tmp = tempnam(sys_get_temp_dir(), 'rmzip_');
$zip = new ZipArchive();
if (!$root || $zip->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('Création ZIP impossible.'); }
$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO);
$filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $file): bool { return $file->getFilename() !== '.git' && $file->getFilename() !== 'uploads'; });
$it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
foreach ($it as $file) { if (!$file->isFile()) continue; $zip->addFile($file->getPathname(), ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR)); }
$zip->close();
$name = 'radius_manager_files_' . gmdate('Ymd_His') . '.zip';
header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$name.'"'); header('Content-Length: '.filesize($tmp)); readfile($tmp); unlink($tmp);
