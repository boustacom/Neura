<?php
/**
 * Neura — Mot de passe oublie
 */
require_once __DIR__ . '/../config.php';
startSecureSession();

if (isLoggedIn()) {
    redirect(APP_URL . '/');
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $message = 'Token de securite invalide.';
        $messageType = 'error';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Veuillez entrer une adresse email valide.';
            $messageType = 'error';
        } else {
            // Rate limit : 3 demandes / heure / email
            $stmt = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $stmt->execute([$email]);
            $recentCount = (int)$stmt->fetchColumn();

            if ($recentCount >= 3) {
                // Message identique pour ne pas reveler si l'email existe
                $message = 'Si cette adresse est associee a un compte, un email de reinitialisation a ete envoye.';
                $messageType = 'success';
            } else {
                // Verifier si l'utilisateur existe
                $stmt = db()->prepare('SELECT id, email FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    // Generer un token
                    $rawToken = bin2hex(random_bytes(32));
                    $hashedToken = hash('sha256', $rawToken);
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 heure

                    // Invalider les tokens precedents pour cet email
                    $stmt = db()->prepare('UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0');
                    $stmt->execute([$email]);

                    // Sauvegarder le token hashe
                    $stmt = db()->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)');
                    $stmt->execute([$email, $hashedToken, $expiresAt]);

                    // Envoyer l'email
                    sendPasswordResetEmail($email, $rawToken);
                }

                // Message identique que l'email existe ou non (anti-enumeration)
                $message = 'Si cette adresse est associee a un compte, un email de reinitialisation a ete envoye.';
                $messageType = 'success';
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
<title>Mot de passe oublie — Neura</title>
<link rel="icon" type="image/svg+xml" href="../assets/brand/favicon.svg">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0A0A0A;--card:#161616;--inp:#0D0D0D;--bdr:rgba(255,255,255,.1);--t1:#fff;--t2:#b8c5d6;--t3:#6b7a8f;--acc:#00E5FF;--grad:linear-gradient(135deg,#00E5FF,#00B8D4);--gh:linear-gradient(90deg,#00E5FF,#00B8D4);--gw:rgba(0,229,255,.25);--r:#EF4444;--g:#22C55E;--rd:12px;--tr:.3s ease}
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
.success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--g);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
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

    <h2>Mot de passe oublie</h2>
    <p class="subtitle">Entrez votre adresse email. Si un compte existe, vous recevrez un lien de reinitialisation.</p>

    <?php if ($message): ?>
        <div class="<?= $messageType ?>"><?= sanitize($message) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-input" placeholder="email@exemple.fr" required autofocus>
        </div>
        <button type="submit" class="btn">Envoyer le lien</button>
    </form>

    <a href="login.php" class="back-link">&larr; Retour a la connexion</a>
</div>
</body>
</html>
