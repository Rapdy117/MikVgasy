<?php

/**
 * Définit un message de session à afficher à l'utilisateur.
 * La session doit être démarrée par la page appelante.
 */
function set_message($message, $type = 'info')
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('set_message() requiert une session PHP active.');
    }

    $_SESSION['message'] = [
        'text' => $message,
        'type' => $type,
    ];
}

/**
 * Affiche un message de session s'il existe et le supprime de la session.
 * La session doit être démarrée par la page appelante.
 */
function display_message()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if (!isset($_SESSION['message'])) {
        return;
    }

    $message = $_SESSION['message'];

    echo '<div class="alert alert-' . htmlspecialchars($message['type']) . '" role="alert" id="messageArea" style="display: block;">';
    echo htmlspecialchars($message['text']);
    echo '</div>';

    unset($_SESSION['message']);
}
