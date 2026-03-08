<?php
/**
 * Neura — Reinitialisation du mot de passe
 */
require_once __DIR__ . '/../config.php';
startSecureSession();

if (isLoggedIn()) {
    redirect(APP_URL . '/');
}

$error = '';
$tokenValid = false;
$rawToken = $_GET['token'] ?? $_POST['token'] ?? '';

if ($rawToken) {
    $hashedToken = hash('sha256', $rawToken);
    $stmt = db()->prepare('SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$hashedToken]);
    $resetRecord = $stmt->fetch();

    if ($resetRecord) {
        $tokenValid = true;
    } else {
        $error = 'Ce lien est invalide ou a expire. Veuillez refaire une demande.';
    }
} else {
    $error = 'Lien de reinitialisation invalide.';
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    if (!verifyCsrfToken()) {
        $error = 'Token de securite invalide.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caracteres.';
        } elseif ($password !== $confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            // Mettre a jour le mot de passe
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
            $stmt->execute([$hash, $resetRecord['email']]);

            // Marquer le token comme utilise
            $stmt = db()->prepare('UPDATE password_resets SET used = 1 WHERE id = ?');
            $stmt->execute([$resetRecord['id']]);

            // Rediriger vers login avec message de succes
            header('Location: login.php?msg=password_reset');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nouveau mot de passe — Neura</title>
<link rel="icon" type="image/svg+xml" href="../assets/brand/favicon.svg">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0A0A0A;--card:#161616;--inp:#0D0D0D;--bdr:rgba(255,255,255,.1);--t1:#fff;--t2:#b8c5d6;--t3:#6b7a8f;--acc:#00E5FF;--grad:linear-gradient(135deg,#00E5FF,#00B8D4);--gh:linear-gradient(90deg,#00E5FF,#00B8D4);--gw:rgba(0,229,255,.25);--r:#EF4444;--rd:12px;--tr:.3s ease}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:var(--card);border:1px solid var(--bdr);border-radius:var(--rd);padding:40px;width:100%;max-width:420px;box-shadow:0 4px 24px rgba(0,0,0,.3)}
.logo{text-align:center;margin-bottom:32px}
.logo-mark{width:56px;height:56px;margin:0 auto 12px}
.logo-lockup{display:flex;align-items:center;justify-content:center;gap:3px}
.logo-lockup .logo-n{height:28px;width:auto}
.logo-lockup .logo-wordmark{font-family:'Inter',sans-serif;font-weight:600;font-size:28px;color:var(--t1);letter-spacing:-1.5px;line-height:1}
h2{font-size:18px;margin-bottom:8px;text-align:center}
.subtitle{color:var(--t3);font-size:13px;text-align:center;margin-bottom:24px}
.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--r);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--t2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.8px}
.form-input{width:100%;background:var(--inp);border:1px solid var(--bdr);color:var(--t1);padding:12px 14px;border-radius:8px;font-family:'Inter';font-size:14px;transition:var(--tr)}
.form-input:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--gw)}
.btn{width:100%;padding:14px;background:var(--grad);color:#000;border:none;border-radius:var(--rd);font-family:'Inter';font-size:15px;font-weight:700;cursor:pointer;transition:var(--tr)}
.btn:hover{box-shadow:0 0 24px var(--gw);transform:translateY(-1px)}
.back-link{display:block;text-align:center;margin-top:20px;font-size:13px;color:var(--acc);text-decoration:none}
.back-link:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <svg class="logo-mark" viewBox="0 0 60 80" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 14L14 2L14 66L2 78Z" fill="#00E5FF"/><path d="M46 14L58 2L58 66L46 78Z" fill="#00E5FF"/><path d="M8 4A65 65 0 0 0 52 76" stroke="#00E5FF" stroke-width="12" stroke-linecap="butt"/></svg>
        <div class="logo-lockup">
            <svg class="logo-n" viewBox="0 0 60 80" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 14L14 2L14 66L2 78Z" fill="#00E5FF"/><path d="M46 14L58 2L58 66L46 78Z" fill="#00E5FF"/><path d="M8 4A65 65 0 0 0 52 76" stroke="#00E5FF" stroke-width="12" stroke-linecap="butt"/></svg>
            <span class="logo-wordmark">eura</span>
        </div>
    </div>

    <h2>Nouveau mot de passe</h2>
    <p class="subtitle">Choisissez un nouveau mot de passe securise.</p>

    <?php if ($error): ?>
        <div class="error"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <?php if ($tokenValid): ?>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= sanitize($rawToken) ?>">
        <div class="form-group">
            <label class="form-label">Nouveau mot de passe</label>
            <input type="password" name="password" class="form-input" placeholder="Minimum 8 caracteres" required minlength="8">
        </div>
        <div class="form-group">
            <label class="form-label">Confirmer</label>
            <input type="password" name="confirm" class="form-input" placeholder="Retapez le mot de passe" required>
        </div>
        <button type="submit" class="btn">Modifier mon mot de passe</button>
    </form>
    <?php endif; ?>

    <a href="login.php" class="back-link">&larr; Retour a la connexion</a>
</div>
</body>
</html>
