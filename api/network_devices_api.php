<?php
header('Content-Type: application/json');

$file = __DIR__ . '/../config/opnsense.json';

// =========================
// INIT FILE
// =========================
if (!file_exists($file)) {
    file_put_contents($file, json_encode(['devices' => []], JSON_PRETTY_PRINT));
}

$data = json_decode(file_get_contents($file), true);

if (!$data || !isset($data['devices'])) {
    $data = ['devices' => []];
}

// =========================
// POST ACTIONS
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? 'save';

    // =========================
    // DELETE
    // =========================
    if ($action === 'delete') {

        $id = $_POST['id'] ?? '';

        $data['devices'] = array_values(array_filter($data['devices'], function ($d) use ($id) {
            return ($d['id'] ?? '') !== $id;
        }));

        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);

        echo json_encode(['success' => true]);
        exit;
    }

    // =========================
    // SAVE / UPDATE
    // =========================
    $id = $_POST['id'] ?? '';
    $name = trim($_POST['device_name'] ?? '');
    $host = trim($_POST['host'] ?? '');
    $api_key = trim($_POST['api_key'] ?? '');
    $api_secret = trim($_POST['api_secret'] ?? '');
    $verify_ssl = ($_POST['verify_ssl'] ?? 'false') === 'true';

    if (!$name || !$host || !$api_key || !$api_secret) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing fields'
        ]);
        exit;
    }

    // generate ID if not exists
    if (!$id) {
        $id = 'dev_' . time();
    }

    $found = false;

    foreach ($data['devices'] as &$device) {
        if (($device['id'] ?? '') === $id) {

            $device = [
                'id' => $id,
                'name' => $name,
                'type' => 'opnsense',
                'host' => $host,
                'api_key' => $api_key,
                'api_secret' => $api_secret,
                'verify_ssl' => $verify_ssl,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $found = true;
            break;
        }
    }

    if (!$found) {
        $data['devices'][] = [
            'id' => $id,
            'name' => $name,
            'type' => 'opnsense',
            'host' => $host,
            'api_key' => $api_key,
            'api_secret' => $api_secret,
            'verify_ssl' => $verify_ssl,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);

    echo json_encode([
        'success' => true,
        'id' => $id
    ]);
    exit;
}

// =========================
// GET DEVICES
// =========================
echo json_encode($data);