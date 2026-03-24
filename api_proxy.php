<?php
session_start(); // Démarre une session PHP pour gérer l'état de connexion
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Inclure le fichier de configuration pour accéder aux clés API centralisées
// Le chemin est corrigé car api_proxy.php est dans le dossier racine
require_once __DIR__ . '/config/config.php';

// Les clés API et l'URL d'OPNsense sont maintenant définies dans config.php
// Elles ne sont plus codées en dur ici.
// Si d'autres parties de api_proxy.php faisaient référence à $opnsense_url, $apiKey, $apiSecret
// elles devront être remplacées par les constantes OPN_SENSE_URL, OPN_SENSE_API_KEY, OPN_SENSE_API_SECRET.

// Gère les requêtes POST (venant du formulaire de connexion)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? $_POST['username'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : ''; // Assurez-vous que c'est bien $_POST['password']

    // --- LOGIQUE D'AUTHENTIFICATION DE VOTRE APPLICATION (EXEMPLE TRÈS SIMPLE) ---
    $valid_username = 'monutilisateur';
    $valid_password = 'monmotdepasse'; 

    if ($username === $valid_username && $password === $valid_password) {
        // Authentification réussie pour votre application
        $_SESSION['logged_in'] = true; 
        $_SESSION['username'] = $username; 

        // Redirection vers le tableau de bord
        header('Location: dashboard/dashboard.php');
        exit();
    } else {
        // Authentification échouée pour votre application
        header('Location: index.php?error=invalid_credentials');
        exit();
    }
} else {
    // Si la page est accédée directement sans méthode POST, redirige vers la page de connexion
    header('Location: index.php');
    exit();
}
?>