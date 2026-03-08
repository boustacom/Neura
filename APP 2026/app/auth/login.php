<?php
/**
 * Neura — Page de connexion
 */
require_once __DIR__ . '/../config.php';
startSecureSession();

// Mode maintenance : bloquer l'acces (sauf admin deja connecte)
if (file_exists(__DIR__ . '/../maintenance.flag') && ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(503);
    header('Retry-After: 600');
    include __DIR__ . '/../views/maintenance.php';
    exit;
}

// Deja connecte ?
if (isLoggedIn()) {
    redirect(APP_URL . '/');
}

$error = '';
$success = $_GET['msg'] ?? '';

// Traitement du formulaire de login classique
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'Token de securite invalide.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Rate limiting
        $rateCheck = checkLoginRateLimit($ip);
        if ($rateCheck['blocked']) {
            $error = 'Trop de tentatives. Reessayez dans ' . $rateCheck['minutes'] . ' minute(s).';
        } else {
            $result = login($email, $password);
            if ($result['success']) {
                clearLoginAttempts($ip);
                redirect(APP_URL . '/');
            } else {
                recordLoginAttempt($ip, $email);
                $error = $result['error'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion — Neura</title>
<link rel="icon" type="image/svg+xml" href="../assets/brand/favicon.svg">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0A0A0A;--bg2:#111;--card:#161616;--inp:#0D0D0D;--bdr:rgba(255,255,255,.1);--ba:rgba(0,229,255,.3);--t1:#fff;--t2:#b8c5d6;--t3:#6b7a8f;--acc:#00E5FF;--grad:linear-gradient(135deg,#00E5FF,#00B8D4);--gh:linear-gradient(90deg,#00E5FF,#00B8D4);--gw:rgba(0,229,255,.25);--r:#EF4444;--g:#22C55E;--rd:12px;--tr:.3s ease}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.login-card{background:var(--card);border:1px solid var(--bdr);border-radius:var(--rd);padding:40px;width:100%;max-width:420px;box-shadow:0 4px 24px rgba(0,0,0,.3)}
.logo{text-align:center;margin-bottom:32px}
.logo-mark{width:56px;height:56px;margin:0 auto 12px}
.logo-lockup{display:flex;align-items:center;justify-content:center;gap:3px}
.logo-lockup .logo-n{height:28px;width:auto}
.logo-lockup .logo-wordmark{font-family:'Inter',sans-serif;font-weight:600;font-size:28px;color:var(--t1);letter-spacing:-1.5px;line-height:1}
.logo-sub{color:var(--t3);font-size:14px;margin-top:4px}
.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--r);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--g);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--t2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.8px}
.form-input{width:100%;background:var(--inp);border:1px solid var(--bdr);color:var(--t1);padding:12px 14px;border-radius:8px;font-family:'Inter';font-size:14px;transition:var(--tr)}
.form-input:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--gw)}
.btn-login{width:100%;padding:14px;background:var(--grad);color:#000;border:none;border-radius:var(--rd);font-family:'Inter';font-size:15px;font-weight:700;cursor:pointer;transition:var(--tr);margin-bottom:8px}
.btn-login:hover{box-shadow:0 0 24px var(--gw);transform:translateY(-1px)}
.forgot-link{display:block;text-align:right;font-size:12px;color:var(--acc);text-decoration:none;margin-bottom:16px}
.forgot-link:hover{text-decoration:underline}
.divider{display:flex;align-items:center;gap:12px;margin:20px 0;color:var(--t3);font-size:12px}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--bdr)}
.btn-google{width:100%;padding:12px;background:transparent;color:var(--t1);border:1px solid var(--bdr);border-radius:var(--rd);font-family:'Inter';font-size:14px;font-weight:600;cursor:pointer;transition:var(--tr);display:flex;align-items:center;justify-content:center;gap:10px;text-decoration:none}
.btn-google:hover{border-color:var(--ba);background:rgba(0,212,255,.04)}
.btn-google svg{width:20px;height:20px}
.register-link{display:block;text-align:center;margin-top:20px;font-size:13px;color:var(--t3)}
.register-link a{color:var(--acc);text-decoration:none;font-weight:600}
.register-link a:hover{text-decoration:underline}
.legal-links{text-align:center;margin-top:24px;padding-top:16px;border-top:1px solid var(--bdr);font-size:11px;color:var(--t3)}
.legal-links a{color:var(--t3);text-decoration:none}
.legal-links a:hover{color:var(--acc)}
</style>
</head>
<body>
<div class="login-card">
    <div class="logo">
        <svg class="logo-mark" viewBox="0 0 60 80" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 14L14 2L14 66L2 78Z" fill="#00E5FF"/><path d="M46 14L58 2L58 66L46 78Z" fill="#00E5FF"/><path d="M8 4A65 65 0 0 0 52 76" stroke="#00E5FF" stroke-width="12" stroke-linecap="butt"/></svg>
        <div class="logo-lockup">
            <svg class="logo-n" viewBox="0 0 60 80" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 14L14 2L14 66L2 78Z" fill="#00E5FF"/><path d="M46 14L58 2L58 66L46 78Z" fill="#00E5FF"/><path d="M8 4A65 65 0 0 0 52 76" stroke="#00E5FF" stroke-width="12" stroke-linecap="butt"/></svg>
            <span class="logo-wordmark">eura</span>
        </div>
        <div class="logo-sub">SEO Local — par BOUS'TACOM</div>
    </div>

    <?php if ($error): ?>
        <div class="error"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <?php if ($success === 'password_reset'): ?>
        <div class="success">Mot de passe modifie avec succes. Connectez-vous.</div>
    <?php elseif ($success === 'registered'): ?>
        <div class="success">Compte cree ! Un administrateur va valider votre acces.</div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-input" placeholder="email@exemple.fr" required autofocus>
        </div>
        <div class="form-group">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="password" class="form-input" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;" required>
        </div>
        <button type="submit" class="btn-login">Se connecter</button>
        <a href="forgot-password.php" class="forgot-link">Mot de passe oublie ?</a>
    </form>

    <div class="divider">ou</div>

    <a href="<?= sanitize(getGoogleAuthUrl()) ?>" class="btn-google">
        <svg viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
        Connecter Google Business Profile
    </a>

    <div class="register-link">
        Pas encore de compte ? <a href="register.php">Creer un compte</a>
    </div>

    <div class="legal-links">
        <a href="<?= APP_URL ?>/?view=legal">Mentions legales</a> &middot;
        <a href="<?= APP_URL ?>/?view=privacy">Confidentialite</a> &middot;
        <a href="<?= APP_URL ?>/?view=cgu">CGU</a>
    </div>
</div>
</body>
</html>
