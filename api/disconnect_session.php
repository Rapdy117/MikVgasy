<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/device_manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

function json_error(string $message, int $statusCode = 400, array $extra = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'status' => 'error',
        'message' => $message,
    ], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Methode non autorisee. Seules les requetes POST sont acceptees.', 405);
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    json_error('Unauthorized', 403);
}

$payload = json_decode((string)file_get_contents('php://input'), true);

if (!is_array($payload)) {
    json_error('Payload JSON invalide.');
}

$token = trim((string)($payload['csrf_token'] ?? ''));
if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    json_error('CSRF invalide', 403);
}

$sessionId = trim((string)($payload['sessionId'] ?? ''));
if ($sessionId === '') {
    json_error('ID de session manquant dans la requete.');
}

$device = requireActiveDevice();

if (($device['type'] ?? '') !== 'opnsense') {
    json_error(
        'La deconnexion distante n\'est pas disponible pour le device actif ' . getDeviceDisplayLabel($device) . '.',
        409,
        [
            'device_type' => (string)($device['type'] ?? 'other'),
            'backend' => (string)($device['backend'] ?? 'generic'),
        ]
    );
}

$fullUrl = $device['host'] . '/api/captiveportal/session/disconnect';
$requestBody = json_encode(['sessionid' => $sessionId]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $fullUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $requestBody,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $device['api_key'] . ':' . $device['api_secret'],
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_SSL_VERIFYPEER => (bool)$device['verify_ssl'],
    CURLOPT_SSL_VERIFYHOST => !empty($device['verify_ssl']) ? 2 : 0,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    json_error('Erreur cURL lors de la communication avec le device actif: ' . $curlError, 500);
}

$decoded = json_decode((string)$response, true);

if ($httpCode === 200) {
    $isSuccess =
        (isset($decoded['result']) && $decoded['result'] === 'deleted') ||
        (isset($decoded['status']) && $decoded['status'] === 'success') ||
        (is_array($decoded) && $decoded === []);

    if ($isSuccess) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Session deconnectee avec succes.'
        ]);
        exit;
    }

    json_error(
        'La deconnexion a echoue selon le backend actif.',
        500,
        ['opnsense_response' => $decoded]
    );
}

json_error(
    'Erreur de l\'API du device actif (' . $httpCode . ').',
    $httpCode > 0 ? $httpCode : 500,
    ['opnsense_response' => $decoded]
);
