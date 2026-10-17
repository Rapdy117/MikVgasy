<?php

/* Detection mobile simple (sniff User-Agent), sans dependance JS. Sert a alleger/desactiver
   certaines fonctions couteuses sur mobile (gros tableaux, auto-refresh), voir docs/project_rules.md
   "Regle Mobile / Performance". Faux positif/negatif sans consequence grave : c'est une optimisation,
   pas une restriction d'acces. */
function isMobileRequest(): bool
{
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($userAgent === '') {
        return false;
    }
    return (bool)preg_match('/Mobi|Android|iPhone|iPad|iPod|Windows Phone/i', $userAgent);
}
