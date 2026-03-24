<?php
// Fichier: disconnect_session.php

// Chemin vers le fichier de configuration
// Assurez-vous que ce chemin est correct par rapport à l'emplacement de disconnect_session.php
// disconnect_session.php est dans 'api' et config.php est dans 'config', donc '../../config/config.php'
// est le chemin correct si 'api' et 'config' sont des sous-dossiers de 'androndra'.
// Si 'api' est dans le dossier racine 'androndra', alors c'est '/config/config.php'
// Non, selon notre discussion, 'api' est un dossier dans 'androndra', et 'config' est aussi dans 'androndra'.
// Le chemin correct est donc __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/config.php';

// Activer le rapport d'erreurs pour le débogage
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Enregistrement des logs pour le débogage
function log_api_disconnect_response($message, $data = null) {
    // Utilise la constante LOG_DIRECTORY définie dans config.php
    $logFile = LOG_DIRECTORY . 'debug_api_disconnect_response.log';
    $timestamp = date('Y-m-d H:i:s');
    $logContent = "--- Déconnexion API Log ---\n";
    $logContent .= "Timestamp: " . $timestamp . "\n";
    $logContent .= "Message: " . $message . "\n";
    if ($data !== null) {
        $logContent .= "Data: " . print_r($data, true) . "\n";
    }
    $logContent .= "--- Fin Déconnexion API Log ---\n\n";
    file_put_contents($logFile, $logContent, FILE_APPEND);
}

header('Content-Type: application/json');

// Vérifier que la méthode de requête est POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Méthode non autorisée
    echo json_encode(['status' => 'error', 'message' => 'Méthode non autorisée. Seules les requêtes POST sont acceptées.']);
    log_api_disconnect_response('Méthode HTTP non autorisée', $_SERVER['REQUEST_METHOD']);
    exit();
}

// Récupérer le corps de la requête JSON
$input = file_get_contents('php://input');

// NOUVEAU LOG : Afficher le contenu brut de l'entrée PHP
log_api_disconnect_response('Contenu brut de php://input', $input);

$data = json_decode($input, true);

// NOUVEAU LOG : Afficher le résultat de json_decode()
log_api_disconnect_response('Résultat de json_decode()', $data);
log_api_disconnect_response('Dernière erreur JSON', json_last_error_msg());


// Vérifier si sessionId est présent dans les données reçues
if (!isset($data['sessionId'])) {
    http_response_code(400); // Mauvaise requête
    echo json_encode(['status' => 'error', 'message' => 'ID de session manquant dans la requête.']);
    log_api_disconnect_response('ID de session manquant (après vérification)', $data);
    exit();
}

$sessionId = $data['sessionId'];
$opnsense_url = OPN_SENSE_URL;     // Défini dans config.php
$api_key = OPN_SENSE_API_KEY;         // Défini dans config.php
$api_secret = OPN_SENSE_API_SECRET; // Défini dans config.php

$api_endpoint = '/api/captiveportal/session/disconnect'; // Endpoint pour déconnecter une session
$full_url = $opnsense_url . $api_endpoint;

// Préparer le corps de la requête pour OPNsense
$request_body = json_encode(['sessionid' => $sessionId]); // Notez 'sessionid' en minuscules ici si l'API l'attend ainsi.


log_api_disconnect_response('Tentative de déconnexion via OPNsense API', [
    'sessionId' => $sessionId,
    'URL' => $full_url,
    'Request Body' => $request_body
]);

// Initialiser cURL
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $full_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true); // C'est une requête POST
curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
]);
// Correction : Ajout du handle $ch aux lignes suivantes
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_USERPWD, $api_key . ":" . $api_secret);

// Pour le débogage (à désactiver en production)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, CURL_VERIFY_SSL); // Utilise la constante de config.php
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, CURL_VERIFY_SSL); // Utilise la constante de config.php

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

curl_close($ch);

log_api_disconnect_response('Réponse OPNsense pour déconnexion', [
    'HTTP Code' => $http_code,
    'cURL Error' => $curl_error,
    'Response Body' => $response
]);

if ($curl_error) {
    http_response_code(500); // Erreur interne du serveur
    echo json_encode(['status' => 'error', 'message' => 'Erreur cURL lors de la communication avec OPNsense: ' . $curl_error]);
} else {
    // Tenter de décoder la réponse JSON d'OPNsense
    $opnsense_response_data = json_decode($response, true);

    if ($http_code === 200) {
        // L'API OPNsense pour déconnecter retourne généralement un statut 'success'
        // ou un message de confirmation, ou un tableau vide [] pour une suppression réussie.
        if (
            (isset($opnsense_response_data['result']) && $opnsense_response_data['result'] === 'deleted') ||
            (isset($opnsense_response_data['status']) && $opnsense_response_data['status'] === 'success') ||
            (is_array($opnsense_response_data) && empty($opnsense_response_data)) // Cette ligne est CRUCIALE pour gérer la réponse vide []
        ) {
            echo json_encode(['status' => 'success', 'message' => 'Session déconnectée avec succès.']);
        }
        else {
            // Cas où OPNsense renvoie 200 mais avec un message d'erreur inattendu dans le corps
            http_response_code(500); // Erreur interne du serveur, car l'action n'est pas "success"
            echo json_encode([
                'status' => 'error',
                'message' => 'La déconnexion a échoué selon OPNsense. Réponse: ' . ($response ? $response : 'Vide'),
                'opnsense_response' => $opnsense_response_data
            ]);
        }
    } else {
        // Gérer les codes HTTP d'erreur d'OPNsense (ex: 400 Bad Request, 404 Not Found, 401 Unauthorized)
        http_response_code($http_code);
        echo json_encode([
            'status' => 'error',
            'message' => 'Erreur de l\'API OPNsense (' . $http_code . '). Réponse: ' . ($response ? $response : 'Vide'),
            'opnsense_response' => $opnsense_response_data
        ]);
    }
}
?>