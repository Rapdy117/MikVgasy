<?php
// C:\wamp64\www\androndra\includes\message.php

/**
 * Définit un message de session à afficher à l'utilisateur.
 *
 * @param string $message Le texte du message.
 * @param string $type Le type de message (ex: 'success', 'error', 'warning', 'info').
 */
function set_message($message, $type = 'info') {
    // Si la session n'est pas déjà démarrée, on la démarre
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['message'] = [
        'text' => $message,
        'type' => $type
    ];
}

/**
 * Affiche un message de session s'il existe et le supprime de la session.
 */
function display_message() {
    // Si la session n'est pas déjà démarrée, on la démarre
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        // Utilise l'ID 'messageArea' que dashboard.js peut aussi cibler
        echo '<div class="alert alert-' . htmlspecialchars($message['type']) . '" role="alert" id="messageArea" style="display: block;">';
        echo htmlspecialchars($message['text']);
        echo '</div>';
        // Supprime le message de la session après l'avoir affiché
        unset($_SESSION['message']);
    }
}
?>