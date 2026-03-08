<?php
/**
 * Neura — Page d'inscription
 * Inscription ouverte avec validation admin obligatoire
 */
require_once __DIR__ . '/../config.php';
startSecureSession();

if (isLoggedIn()) {
    redirect(APP_URL . '/');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'Token de securite invalide.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';
        $cgu = !empty($_POST['cgu']);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Rate limit : 3 inscriptions / heure / IP
        $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $stmt->execute();
        // Utiliser login_attempts pour tracker aussi les inscriptions
        $stmt2 = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = 'register' AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt2->execute([$ip]);
        $registerCount = (int)$stmt2->fetchColumn();

        if ($registerCount >= 3) {
            $error = 'Trop de tentatives d\'inscription. Reessayez plus tard.';
        } elseif (!$name || strlen($name) < 2) {
            $error = 'Veuillez entrer votre nom (2 caracteres minimum).';
        } elseif (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Veuillez entrer une adresse email valide.';
        } elseif (strlen($password) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caracteres.';
        } elseif ($password !== $confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } elseif (!$cgu) {
            $error = 'Vous devez accepter les CGU et la politique de confidentialite.';
        } else {
            // Verifier si l'email est deja pris
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Cette adresse email est deja utilisee.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, status, cgu_accepted_at, created_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
                $stmt->execute([$name, $email, $hash, 'user', 'pending']);

                // Tracker la tentative d'inscription
                $stmt = db()->prepare("INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, 'register', NOW())");
                $stmt->execute([$ip]);

                // Notifier l'admin
                $adminHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;">
                <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1);">
                    <div style="background:linear-gradient(135deg,#00f4ff,#1e7eff);padding:24px 30px;">
                        <h1 style="margin:0;font-size:22px;color:#000;font-weight:700;">NEURA</h1>
                        <p style="margin:4px 0 0;font-size:13px;color:rgba(0,0,0,.6);">Nouvelle inscription en attente</p>
                    </div>
                    <div style="padding:30px;">
                        <p style="font-size:14px;color:#333;line-height:1.8;margin:0 0 12px;">Un nouvel utilisateur s\'est inscrit et attend votre validation :</p>
                        <table style="font-size:14px;color:#333;line-height:1.8;">
                            <tr><td style="font-weight:600;padding-right:12px;">Nom :</td><td>' . htmlspecialchars($name) . '</td></tr>
                            <tr><td style="font-weight:600;padding-right:12px;">Email :</td><td>' . htmlspecialchars($email) . '</td></tr>
                        </table>
                        <p style="margin-top:20px;font-size:13px;color:#999;">Connectez-vous a Neura pour valider ou refuser cet acces.</p>
                    </div>
                    <div style="padding:16px 30px;background:#f8f9fa;border-top:1px solid #eee;font-size:11px;color:#999;text-align:center;">
                        Neura &mdash; une solution developpee par BOUS\'TACOM
                    </div>
                </div>
                </body></html>';

                sendEmail('contact@boustacom.fr', 'Mathieu', 'Nouvelle inscription Neura — ' . $name, $adminHtml);

                // Rediriger vers login avec message
                header('Location: login.php?msg=registered');
                exit;
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
<title>Inscription — Neura</title>
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
.subtitle{color:var(--t3);font-size:13px;text-align:center;margin-bottom:24px;line-height:1.5}
.error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--r);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--t2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.8px}
.form-input{width:100%;background:var(--inp);border:1px solid var(--bdr);color:var(--t1);padding:12px 14px;border-radius:8px;font-family:'Inter';font-size:14px;transition:var(--tr)}
.form-input:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--gw)}
.checkbox-group{display:flex;align-items:flex-start;gap:10px;margin-bottom:20px}
.checkbox-group input[type="checkbox"]{margin-top:3px;accent-color:var(--acc);width:16px;height:16px;flex-shrink:0}
.checkbox-group label{font-size:12px;color:var(--t2);line-height:1.5}
.checkbox-group a{color:var(--acc);text-decoration:none}
.checkbox-group a:hover{text-decoration:underline}
.btn{width:100%;padding:14px;background:var(--grad);color:#000;border:none;border-radius:var(--rd);font-family:'Inter';font-size:15px;font-weight:700;cursor:pointer;transition:var(--tr)}
.btn:hover{box-shadow:0 0 24px var(--gw);transform:translateY(-1px)}
.back-link{display:block;text-align:center;margin-top:20px;font-size:13px;color:var(--t3)}
.back-link a{color:var(--acc);text-decoration:none}
.back-link a:hover{text-decoration:underline}
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

    <h2>Creer un compte</h2>
    <p class="subtitle">Inscrivez-vous pour acceder a Neura. Votre compte sera valide par un administrateur.</p>

    <?php if ($error): ?>
        <div class="error"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <div class="form-group">
            <label class="form-label">Nom complet</label>
            <input type="text" name="name" class="form-input" placeholder="Votre nom" required minlength="2" value="<?= sanitize($_POST['name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-input" placeholder="email@exemple.fr" required value="<?= sanitize($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="password" class="form-input" placeholder="Minimum 8 caracteres" required minlength="8">
        </div>
        <div class="form-group">
            <label class="form-label">Confirmer le mot de passe</label>
            <input type="password" name="confirm" class="form-input" placeholder="Retapez le mot de passe" required>
        </div>
        <div class="checkbox-group">
            <input type="checkbox" name="cgu" id="cgu" required>
            <label for="cgu">J'accepte les <a href="<?= APP_URL ?>/?view=cgu" target="_blank">CGU</a> et la <a href="<?= APP_URL ?>/?view=privacy" target="_blank">politique de confidentialite</a></label>
        </div>
        <button type="submit" class="btn">Creer mon compte</button>
    </form>

    <div class="back-link">
        Deja un compte ? <a href="login.php">Se connecter</a>
    </div>
</div>
</body>
</html>
