<?php
session_start(); // Démarre la session pour gérer l'état de connexion

// Déconnexion si le paramètre 'logout' est présent
if (isset($_GET['logout'])) {
    session_unset(); // Supprime toutes les variables de session
    session_destroy(); // Détruit la session
    header('Location: index.php'); // Redirige vers la page de connexion propre
    exit();
}

// Redirige vers le tableau de bord si l'utilisateur est déjà connecté
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: /pages/dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container container-custom">
        <img src="assets/images/logo.png" alt="Logo Androndra" class="logo">
        <h1>Connexion</h1>
        
        <?php
        // Afficher les messages de statut de connexion
        if (isset($_GET['error'])) {
            $errorMessage = '';
            switch ($_GET['error']) {
                case 'invalid_credentials':
                    $errorMessage = 'Nom d\'utilisateur ou mot de passe incorrect.';
                    break;
                case 'not_logged_in':
                    $errorMessage = 'Veuillez vous connecter pour accéder à cette page.';
                    break;
                default:
                    $errorMessage = 'Une erreur inattendue est survenue.';
                    break;
            }
            echo '<div class="alert alert-danger" role="alert">Erreur de connexion : ' . htmlspecialchars($errorMessage) . '</div>';
        }
        ?>

        <form action="api_proxy.php" method="POST">
            <div class="mb-3">
                <input type="text" class="form-control form-field-half" id="username" name="username" placeholder="Identifiant" required>
            </div>
            <div class="mb-3">
                <input type="password" class="form-control form-field-half" id="password" name="password" placeholder="Mot de passe" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg">Se connecter</button>
        </form>
        <div class="footer">
            <p>&copy; 2026. Tous droits réservés.</p>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>