<?php
session_start();

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit();
}

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
    <title>Radius Manager — Connexion</title>
    <meta name="description" content="Interface d'administration Radius Manager. Connexion sécurisée.">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --accent: #17a2b8;
            --accent-glow: rgba(23, 162, 184, 0.25);
            --accent-soft: rgba(23, 162, 184, 0.15);
            --bg-dark: #030820;
            --glass-bg: rgba(26, 26, 46, 0.30);
            --glass-border: rgba(60, 60, 88, 0.50);
            --text-primary: #e0e0e0;
            --text-muted: #b0b0b0;
            --input-bg: rgba(31, 32, 56, 0.50);
            --input-border: rgba(60, 60, 88, 0.50);
            --radius: 6px;
            --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html, body {
            height: 100%;
            font-family: 'Inter', sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            overflow: hidden;
        }

        /* ── Animated background ── */
        .bg-scene {
            position: fixed; inset: 0; z-index: 0;
            background: url('assets/images/background_wave.png') no-repeat center center fixed;
            background-size: cover;
            background-color: #030820;
        }

        /* ── Floating orbs ── */
        .orb {
            position: fixed; border-radius: 50%; filter: blur(80px); z-index: 0; pointer-events: none;
            animation: float-orb linear infinite;
        }
        .orb-1 { width: 500px; height: 500px; background: radial-gradient(circle, rgba(23,162,184,0.10), transparent 70%); top: -15%; left: -10%; animation-duration: 20s; }
        .orb-2 { width: 400px; height: 400px; background: radial-gradient(circle, rgba(23,162,184,0.08), transparent 70%); bottom: -10%; right: -8%; animation-duration: 26s; animation-direction: reverse; }
        .orb-3 { width: 300px; height: 300px; background: radial-gradient(circle, rgba(224,224,224,0.05), transparent 70%); top: 40%; left: 55%; animation-duration: 32s; }

        @keyframes float-orb {
            0%   { transform: translate(0, 0) scale(1); }
            33%  { transform: translate(30px, -20px) scale(1.05); }
            66%  { transform: translate(-20px, 30px) scale(0.97); }
            100% { transform: translate(0, 0) scale(1); }
        }

        #particles-canvas { position: fixed; inset: 0; z-index: 0; pointer-events: none; }
        #wave-canvas      { position: fixed; inset: 0; width: 100%; height: 100%; z-index: 1; pointer-events: none; }

        /* ── Layout ── */
        .login-wrapper {
            position: relative; z-index: 10;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        /* ── Card ── */
        .login-card {
            width: 100%;
            max-width: 420px;
            background: var(--glass-bg);
            backdrop-filter: blur(48px) saturate(180%);
            -webkit-backdrop-filter: blur(48px) saturate(180%);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 44px 40px 36px;
            box-shadow:
                0 32px 80px rgba(0,0,0,0.5),
                0 0 0 1px rgba(255,255,255,0.04) inset,
                0 1px 0 rgba(255,255,255,0.08) inset;
            animation: card-in 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        @keyframes card-in {
            from { opacity: 0; transform: translateY(28px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Logo / Brand ── */
        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 36px;
        }

        .brand-icon-wrap {
            position: relative;
            width: 56px; height: 56px;
            flex-shrink: 0;
        }

        .brand-icon-ring {
            position: absolute; inset: -4px;
            border-radius: 50%;
            border: 1.5px solid transparent;
            background: linear-gradient(135deg, rgba(23,162,184,0.6), transparent 60%) border-box;
            -webkit-mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: destination-out;
            mask-composite: exclude;
            animation: ring-spin 8s linear infinite;
        }

        @keyframes ring-spin {
            to { transform: rotate(360deg); }
        }

        .brand-icon {
            width: 56px; height: 56px;
            border-radius: var(--radius);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            color: var(--accent);
            background: var(--accent-soft);
            border: 1px solid rgba(23,162,184,0.2);
            box-shadow: 0 0 24px rgba(23,162,184,0.15);
            transition: box-shadow var(--transition), transform var(--transition);
        }

        .login-card:hover .brand-icon {
            box-shadow: 0 0 36px rgba(23,162,184,0.28);
            transform: translateY(-1px);
        }

        .brand-text {}
        .brand-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: 0.02em;
            line-height: 1.2;
        }
        .brand-sub {
            font-size: 0.73rem;
            color: var(--text-muted);
            margin-top: 2px;
            letter-spacing: 0.01em;
        }

        /* ── Headline ── */
        .login-headline {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
            letter-spacing: -0.02em;
        }
        .login-tagline {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        /* ── Alert ── */
        .login-alert {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #fca5a5;
            border-radius: var(--radius);
            padding: 10px 14px;
            font-size: 0.82rem;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            animation: shake 0.4s cubic-bezier(.36,.07,.19,.97) both;
        }

        @keyframes shake {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-3px); }
            40%, 60% { transform: translateX(3px); }
        }

        /* ── Form ── */
        .form-group {
            margin-bottom: 16px;
        }

        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-muted);
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 7px;
        }

        .field-wrap {
            position: relative;
        }

        .field-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(23,162,184,0.5);
            font-size: 0.85rem;
            pointer-events: none;
            transition: color var(--transition);
        }

        .field-input {
            width: 100%;
            height: 46px;
            padding: 0 42px 0 40px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: var(--radius);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            outline: none;
            transition: border-color var(--transition), background var(--transition), box-shadow var(--transition);
        }

        .field-input::placeholder { color: rgba(255,255,255,0.28); }

        .field-input:hover {
            border-color: rgba(23,162,184,0.3);
            background: rgba(255,255,255,0.08);
        }

        .field-input:focus {
            border-color: rgba(23,162,184,0.6);
            background: rgba(23,162,184,0.05);
            box-shadow: 0 0 0 3px rgba(23,162,184,0.12);
        }

        .field-input:focus + .field-border-animate::after {
            width: 100%;
        }

        .field-wrap:focus-within .field-icon {
            color: var(--accent);
        }

        /* Toggle password */
        .toggle-pw {
            position: absolute;
            right: 13px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--text-muted); cursor: pointer;
            font-size: 0.85rem;
            padding: 4px;
            transition: color var(--transition);
        }
        .toggle-pw:hover { color: var(--accent); }

        /* ── Submit button ── */
        .btn-login {
            width: 100%;
            height: 48px;
            margin-top: 8px;
            background: linear-gradient(135deg, rgba(23,162,184,0.9) 0%, rgba(14,130,150,0.95) 100%);
            border: 1px solid rgba(23,162,184,0.4);
            border-radius: var(--radius);
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform var(--transition), box-shadow var(--transition), filter var(--transition);
            box-shadow: 0 4px 20px rgba(23,162,184,0.25), 0 1px 0 rgba(255,255,255,0.1) inset;
        }

        .btn-login::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(255,255,255,0.08) 0%, transparent 100%);
            transition: opacity var(--transition);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(23,162,184,0.4), 0 1px 0 rgba(255,255,255,0.12) inset;
            filter: brightness(1.08);
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: 0 2px 12px rgba(23,162,184,0.2);
        }

        .btn-login.loading .btn-text { opacity: 0; }
        .btn-login.loading .btn-spinner { display: block; }

        .btn-inner { position: relative; display: flex; align-items: center; justify-content: center; gap: 8px; }

        .btn-spinner {
            display: none;
            position: absolute;
            width: 18px; height: 18px;
            border: 2px solid rgba(255,255,255,0.25);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Divider ── */
        .login-divider {
            display: flex; align-items: center; gap: 12px;
            margin: 24px 0 0;
            color: var(--text-muted);
            font-size: 0.72rem;
        }
        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--glass-border);
        }

        /* ── Footer ── */
        .login-footer {
            margin-top: 18px;
            text-align: center;
            font-size: 0.73rem;
            color: var(--text-muted);
        }

        /* ── Status dots (top right corner) ── */
        .status-bar {
            position: absolute;
            top: -1px; right: -1px;
            background: var(--accent-soft);
            border: 1px solid var(--glass-border);
            border-top-right-radius: 8px;
            border-bottom-left-radius: var(--radius);
            padding: 6px 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.68rem;
            color: var(--text-primary);
            font-weight: 500;
            letter-spacing: 0.04em;
        }

        .status-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 6px var(--accent);
            animation: pulse-dot 2s ease-in-out infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(0.85); }
        }

        @media (max-width: 480px) {
            .login-card { padding: 36px 24px 28px; }
            .login-headline { font-size: 1.3rem; }
        }
    </style>
</head>
<body>

<div class="bg-scene"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<canvas id="particles-canvas"></canvas>

<div class="login-wrapper">
    <div class="login-card">

        <!-- Status indicator -->
        <div class="status-bar">
            <span class="status-dot"></span>
            Système opérationnel
        </div>

        <!-- Brand -->
        <div class="brand">
            <div class="brand-icon-wrap">
                <div class="brand-icon-ring"></div>
                <div class="brand-icon">
                    <i class="fas fa-network-wired"></i>
                </div>
            </div>
            <div class="brand-text">
                <div class="brand-name">Radius Manager</div>
                <div class="brand-sub">Portail administrateur</div>
            </div>
        </div>

        <!-- Headline -->
        <h1 class="login-headline">Bon retour 👋</h1>
        <p class="login-tagline">Connectez-vous pour accéder au tableau de bord</p>

        <!-- Error alert -->
        <?php
        if (isset($_GET['error'])) {
            $errorMessage = '';
            switch ($_GET['error']) {
                case 'invalid_credentials':
                    $errorMessage = 'Identifiant ou mot de passe incorrect.';
                    break;
                case 'not_logged_in':
                    $errorMessage = 'Veuillez vous connecter pour accéder à cette page.';
                    break;
                default:
                    $errorMessage = 'Une erreur inattendue est survenue.';
            }
            echo '<div class="login-alert"><i class="fas fa-circle-exclamation"></i>' . htmlspecialchars($errorMessage) . '</div>';
        }
        ?>

        <!-- Form -->
        <form action="api_proxy.php" method="POST" autocomplete="off" id="loginForm">

            <div class="form-group">
                <label class="field-label" for="username">Identifiant</label>
                <div class="field-wrap">
                    <i class="fas fa-user field-icon"></i>
                    <input
                        type="text"
                        class="field-input"
                        id="username"
                        name="username"
                        placeholder="Votre identifiant"
                        required
                        autocomplete="username"
                    >
                </div>
            </div>

            <div class="form-group">
                <label class="field-label" for="password">Mot de passe</label>
                <div class="field-wrap">
                    <i class="fas fa-lock field-icon"></i>
                    <input
                        type="password"
                        class="field-input"
                        id="password"
                        name="password"
                        placeholder="••••••••"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="toggle-pw" id="togglePw" aria-label="Afficher le mot de passe" tabindex="-1">
                        <i class="fas fa-eye" id="togglePwIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-login" id="submitBtn">
                <div class="btn-inner">
                    <span class="btn-spinner"></span>
                    <span class="btn-text">
                        <i class="fas fa-arrow-right-to-bracket"></i>
                        Se connecter
                    </span>
                </div>
            </button>

        </form>

        <div class="login-divider">Connexion sécurisée HTTPS</div>
        <div class="login-footer">© 2026 Radius Manager — Tous droits réservés</div>

    </div>
</div>

<script>
/* ── Toggle password visibility ── */
document.getElementById('togglePw').addEventListener('click', function () {
    const pw = document.getElementById('password');
    const icon = document.getElementById('togglePwIcon');
    if (pw.type === 'password') {
        pw.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        pw.type = 'password';
        icon.className = 'fas fa-eye';
    }
});

/* ── Loading state on submit ── */
document.getElementById('loginForm').addEventListener('submit', function () {
    const btn = document.getElementById('submitBtn');
    btn.classList.add('loading');
    btn.disabled = true;
});
/* ── Animation de fond externalisée ── */
</script>
<script src="js/background_animation.js"></script>

</body>
</html>
