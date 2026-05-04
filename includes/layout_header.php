<?php
// On s'assure que la session est démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérification de connexion globale
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php?error=not_logged_in');
    exit();
}

// Initialisation du contexte d'application si non fait
if (!isset($appContext)) {
    require_once __DIR__ . '/app_context.php';
    $appContext = buildAppContext();
}

// Titre par défaut
$pageTitle = $pageTitle ?? 'Radius Manager';
$htmlClass = trim((string)($htmlClass ?? ''));
$bodyClass = trim((string)($bodyClass ?? ''));
$contentClass = trim((string)($contentClass ?? ''));
$bodyAttributes = is_array($bodyAttributes ?? null) ? $bodyAttributes : [];
?>
<!DOCTYPE html>
<html lang="fr"<?= $htmlClass !== '' ? ' class="' . htmlspecialchars($htmlClass, ENT_QUOTES) . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Frameworks -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Global CSS -->
    <link rel="stylesheet" href="../css/theme.css">
    <link rel="stylesheet" href="../css/toast.css?v=20260430a">
    
    <!-- Page Specific CSS -->
    <?php if (isset($extraCss) && is_array($extraCss)): ?>
        <?php foreach ($extraCss as $cssFile): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($cssFile) ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Head JS -->
    <?php if (isset($extraHeadJs) && is_array($extraHeadJs)): ?>
        <?php foreach ($extraHeadJs as $jsFile): ?>
            <script src="<?= htmlspecialchars($jsFile) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES) . '"' : '' ?>
    data-active-device-id="<?= htmlspecialchars((string)($appContext['device']['id'] ?? ''), ENT_QUOTES) ?>"
    data-active-device-type="<?= htmlspecialchars((string)($appContext['device']['type'] ?? 'other'), ENT_QUOTES) ?>"
    <?php foreach ($bodyAttributes as $attributeName => $attributeValue): ?>
        <?= htmlspecialchars((string)$attributeName, ENT_QUOTES) ?>="<?= htmlspecialchars((string)$attributeValue, ENT_QUOTES) ?>"
    <?php endforeach; ?>
>

<!-- Visual Background (Identity: Glassmorphism + Deep Navy + Original Wave PNG) -->
<div class="bg-scene" style="position: fixed; inset: 0; z-index: -1; background: url('../assets/images/background_wave.png') no-repeat center center fixed; background-size: cover; background-color: #030820;"></div>
<canvas id="particles-canvas" style="position: fixed; inset: 0; z-index: -1; pointer-events: none;"></canvas>

<div class="d-flex" id="wrapper">
    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Page Content Wrapper -->
    <div id="page-content-wrapper">
        <div class="container-fluid py-3 page-content<?= $contentClass !== '' ? ' ' . htmlspecialchars($contentClass, ENT_QUOTES) : '' ?>">
            <!-- Messages System -->
            <?php 
            require_once __DIR__ . '/message.php';
            display_message(); 
            ?>
            <div id="messageArea" style="display: none;"></div>
