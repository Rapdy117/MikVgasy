<?php

$host = 'localhost';
$dbname = 'radius_manager';
$user = 'radius_app';
$pass = 'StrongPass123!';
$charset = 'utf8';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset={$charset}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );

    $pdo->exec("SET NAMES utf8");

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur DB: ' . $e->getMessage()
    ]);
    exit;
}