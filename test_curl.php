<?php
session_start(); // Démarre une session PHP pour gérer l'état de connexion
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- CONFIGURATION OPNsense API (utilisé plus tard pour interagir) ---
$opnsense_url = 'https://11.11.11.1';
$apiKey = 'D9J66tX6yHAHYJbqVkqox95+JW0uGVcOwM8IpHpIq9s4yNUqMPbGGNrRpuid7gA70S4f03JxWdCE7tPZ'; // CLÉ API DE api_user_test
$apiSecret = 'IQhD/1cXdSxKL/45nTsC2L8ojAiVzbKWkm0q3FzqU2Si3MDrL9qm+YdR0W6+x+EP0giiOXzqRqv1jc4B'; // SECRET API DE api_user_test

// --- Lignes de débogage (à conserver pour l'instant) ---
file_put_contents('debug_api_proxy_request.log', '--- Nouvelle Requête ---' . PHP_EOL, FILE_APPEND);
file_put_contents('debug_api_proxy_request.log', 'Timestamp: ' . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND);
file_put_contents('debug_api_proxy_request.log', 'Méthode HTTP: ' . ($_SERVER['REQUEST_METHOD'] ?? 'N/A') . PHP_EOL, FILE_APPEND);
file_put_contents('debug_api_proxy_request.log', 'URL Reçue: ' . ($_SERVER['REQUEST_URI'] ?? 'N/A') . PHP_EOL, FILE_APPEND);
file_put_contents('debug_api_proxy_request.log', '$_GET Contenu: ' . print_r($_GET, true) . PHP_EOL, FILE_APPEND);
file_put_contents('debug_api_proxy_request.log', '$_POST Contenu: ' . print_r($_POST, true) . PHP_EOL, FILE_APPEND);
file_put_contents('debug_api_proxy_request.log', '$_REQUEST Contenu: ' . print_r($_REQUEST, true) . PHP_EOL, FILE_APPEND);
$raw_input_data = file_get_contents("php://input");
file_put_contents('debug_api_proxy_request.log', 'php://input (Corps brut de la requête): ' . $raw_input_data . PHP_EOL, FILE_APPEND);
file_put_contents('debug_api_proxy_request.log', '--- Fin Debug Initial ---' . PHP_EOL, FILE_APPEND);
// --- FIN Lignes de débogage ---

// Gère les requêtes POST (venant du formulaire de connexion)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? $_POST['username'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    // --- LOGIQUE D'AUTHENTIFICATION DE VOTRE APPLICATION (EXEMPLE TRÈS SIMPLE) ---
    // Remplacez 'monutilisateur' et 'monmotdepasse' par les identifiants que vous voulez pour votre application
    $valid_username = 'monutilisateur';
    $valid_password = 'monmotdepasse'; // Dans une vraie application, le mot de passe doit être haché !

    if ($username === $valid_username && $password === $valid_password) {
        // Authentification réussie pour votre application
        $_SESSION['logged_in'] = true; // Marque l'utilisateur comme connecté dans la session
        $_SESSION['username'] = $username; // Stocke le nom d'utilisateur dans la session

        // Redirection vers le tableau de bord
        header('Location: dashboard/index.php');
        exit();
    } else {
        // Authentification échouée pour votre application
        header('Location: index.html?error=invalid_credentials');
        exit();
    }
} else {
    // Si la page est accédée directement sans méthode POST, redirige vers la page de connexion
    header('Location: index.html');
    exit();
}
?>