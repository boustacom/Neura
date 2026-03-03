<?php
/**
 * BOUS'TACOM — Script d'installation initial
 * 
 * INSTRUCTIONS:
 * 1. Upload ce fichier sur app.boustacom.fr/setup.php
 * 2. Ouvre-le dans ton navigateur
 * 3. Définis ton mot de passe admin
 * 4. SUPPRIME CE FICHIER IMMÉDIATEMENT APRÈS
 */
require_once __DIR__ . '/config.php';

$message = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    
    if (strlen($password) < 8) {
        $message = 'Le mot de passe doit faire au moins 8 caractères.';
    } elseif ($password !== $confirm) {
        $message = 'Les mots de passe ne correspondent pas.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
        $stmt->execute([$hash, 'contact@boustacom.fr']);
        $message = '✅ Mot de passe configuré ! Tu peux maintenant te connecter. SUPPRIME CE FICHIER (setup.php) !';
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Installation — Neura</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui;background:#000;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#0a1628;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:40px;max-width:400px;width:100%}
h1{font-size:22px;margin-bottom:8px}
p{color:#b8c5d6;font-size:14px;margin-bottom:24px}
.msg{padding:12px;border-radius:8px;margin-bottom:16px;font-size:14px}
.msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#EF4444}
.msg.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#22C55E}
label{display:block;font-size:12px;font-weight:600;color:#6b7a8f;margin-bottom:6px;text-transform:uppercase}
input{width:100%;background:#060e1e;border:1px solid rgba(255,255,255,.1);color:#fff;padding:12px;border-radius:8px;font-size:14px;margin-bottom:16px}
input:focus{outline:none;border-color:#00d4ff}
button{width:100%;padding:14px;background:linear-gradient(135deg,#00f4ff,#1e7eff);color:#000;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer}
a{color:#00d4ff}
</style>
</head>
<body>
<div class="card">
    <h1>⚙️ Installation Neura</h1>
    <p>Configure ton mot de passe administrateur.</p>
    
    <?php if ($message): ?>
        <div class="msg <?= $done ? 'ok' : 'err' ?>"><?= $message ?></div>
    <?php endif; ?>
    
    <?php if (!$done): ?>
    <form method="POST">
        <label>Nouveau mot de passe</label>
        <input type="password" name="password" placeholder="Minimum 8 caractères" required minlength="8">
        <label>Confirmer</label>
        <input type="password" name="confirm" placeholder="Retapez le mot de passe" required>
        <button type="submit">Configurer</button>
    </form>
    <?php else: ?>
        <p><a href="<?= APP_URL ?>/auth/login.php">→ Aller à la page de connexion</a></p>
    <?php endif; ?>
</div>
</body>
</html>
