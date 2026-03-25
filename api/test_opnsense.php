<?php
header('Content-Type: application/json');

function post_string_or_null(string $key): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

// =========================
// GET INPUT
// =========================
$host = post_string_or_null('host');
$key = post_string_or_null('api_key');
$secret = post_string_or_null('api_secret');
$verify_ssl = ($_POST['verify_ssl'] ?? 'false') === 'true';
$statusOnly = isset($_POST['status_only']);

// =========================
// VALIDATION
// =========================
if ($host === null || $key === null || $secret === null) {
    echo json_encode([
        'success' => false,
        'log' => "❌ Missing API credentials"
    ]);
    exit;
}

// normalize URL
$host = rtrim($host, '/');

// =========================
// BUILD URL
// =========================
$url = $host . '/api/core/system/status';

// =========================
// CURL INIT
// =========================
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $key . ":" . $secret,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_SSL_VERIFYPEER => $verify_ssl,
    CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// =========================
// CURL ERROR
// =========================
if ($error) {
    echo json_encode([
        'success' => false,
        'log' => "❌ CURL ERROR:\n" . $error
    ]);
    exit;
}

// =========================
// RESPONSE ANALYSIS
// =========================
$decoded = json_decode($response, true);

// =========================
// SUCCESS CHECK
// =========================
if ($http_code === 200 && $decoded) {

    if ($statusOnly) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode([
            'success' => true,
            'log' => "✔ Connected successfully\nHTTP: $http_code"
        ]);
    }

    exit;
}

// =========================
// FAILED
// =========================
if ($statusOnly) {
    echo json_encode(['success' => false]);
} else {
    echo json_encode([
        'success' => false,
        'log' => "❌ Failed\nHTTP: $http_code\nResponse: " . substr($response, 0, 200)
    ]);
}
