<?php

function translateHotspotStatus(string $status): string
{
    return match (strtolower(trim($status))) {
        'login' => 'Connexion',
        'logout' => 'Deconnexion',
        'fail' => 'Echec',
        'limit' => 'Limite',
        default => 'Info',
    };
}

function translateHotspotAction(string $action): string
{
    $value = trim($action);
    return $value !== '' ? $value : '-';
}
