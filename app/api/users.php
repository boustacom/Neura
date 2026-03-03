<?php
/**
 * Neura — API Gestion des utilisateurs (admin uniquement)
 * Actions : list, validate, suspend, activate, delete
 */
require_once __DIR__ . '/../config.php';
startSecureSession();
requireLogin();
requireCsrf();

// Verifier que l'utilisateur est admin
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acces refuse']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {

        // ====== LISTER TOUS LES UTILISATEURS ======
        case 'list':
            $stmt = db()->query('SELECT id, name, email, role, status, cgu_accepted_at, created_at FROM users ORDER BY created_at DESC');
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'users' => $users]);
            break;

        // ====== VALIDER UN COMPTE (pending → active) ======
        case 'validate':
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId) {
                echo json_encode(['error' => 'ID utilisateur manquant']);
                break;
            }
            // Verifier que le user existe et est en pending
            $stmt = db()->prepare('SELECT id, name, email, status FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$targetUser) {
                echo json_encode(['error' => 'Utilisateur introuvable']);
                break;
            }
            if ($targetUser['status'] !== 'pending') {
                echo json_encode(['error' => 'Ce compte n\'est pas en attente de validation']);
                break;
            }
            $stmt = db()->prepare('UPDATE users SET status = ? WHERE id = ?');
            $stmt->execute(['active', $userId]);

            // Envoyer un email de notification au user
            $activationHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;">
            <div style="max-width:500px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1);">
                <div style="background:linear-gradient(135deg,#00f4ff,#1e7eff);padding:24px 30px;">
                    <h1 style="margin:0;font-size:22px;color:#000;font-weight:700;">NEURA</h1>
                    <p style="margin:4px 0 0;font-size:13px;color:rgba(0,0,0,.6);">Votre compte a ete active</p>
                </div>
                <div style="padding:30px;">
                    <p style="font-size:14px;color:#333;line-height:1.8;margin:0 0 16px;">Bonjour <strong>' . htmlspecialchars($targetUser['name']) . '</strong>,</p>
                    <p style="font-size:14px;color:#333;line-height:1.8;margin:0 0 20px;">Votre compte Neura a ete valide par un administrateur. Vous pouvez desormais vous connecter et utiliser la plateforme.</p>
                    <div style="text-align:center;margin:24px 0;">
                        <a href="' . APP_URL . '/auth/login.php" style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#00f4ff,#1e7eff);color:#000;font-weight:700;text-decoration:none;border-radius:8px;font-size:15px;">Se connecter</a>
                    </div>
                </div>
                <div style="padding:16px 30px;background:#f8f9fa;border-top:1px solid #eee;font-size:11px;color:#999;text-align:center;">
                    Neura &mdash; une solution developpee par BOUS\'TACOM
                </div>
            </div>
            </body></html>';

            sendEmail($targetUser['email'], $targetUser['name'], 'Votre compte Neura est active !', $activationHtml);

            echo json_encode(['success' => true, 'message' => 'Compte valide et notification envoyee']);
            break;

        // ====== SUSPENDRE UN COMPTE ======
        case 'suspend':
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId) {
                echo json_encode(['error' => 'ID utilisateur manquant']);
                break;
            }
            // Ne pas se suspendre soi-meme
            if ($userId === (int)$_SESSION['user_id']) {
                echo json_encode(['error' => 'Vous ne pouvez pas suspendre votre propre compte']);
                break;
            }
            $stmt = db()->prepare('UPDATE users SET status = ? WHERE id = ? AND role != ?');
            $stmt->execute(['suspended', $userId, 'admin']);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['error' => 'Impossible de suspendre cet utilisateur (admin ou introuvable)']);
                break;
            }
            echo json_encode(['success' => true, 'message' => 'Compte suspendu']);
            break;

        // ====== REACTIVER UN COMPTE SUSPENDU ======
        case 'activate':
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId) {
                echo json_encode(['error' => 'ID utilisateur manquant']);
                break;
            }
            $stmt = db()->prepare('UPDATE users SET status = ? WHERE id = ?');
            $stmt->execute(['active', $userId]);
            echo json_encode(['success' => true, 'message' => 'Compte reactive']);
            break;

        // ====== SUPPRIMER UN COMPTE ======
        case 'delete':
            $userId = (int)($_POST['user_id'] ?? 0);
            if (!$userId) {
                echo json_encode(['error' => 'ID utilisateur manquant']);
                break;
            }
            // Ne pas se supprimer soi-meme
            if ($userId === (int)$_SESSION['user_id']) {
                echo json_encode(['error' => 'Vous ne pouvez pas supprimer votre propre compte']);
                break;
            }
            // Ne pas supprimer un autre admin
            $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $targetRole = $stmt->fetchColumn();
            if ($targetRole === 'admin') {
                echo json_encode(['error' => 'Impossible de supprimer un compte administrateur']);
                break;
            }
            $stmt = db()->prepare('DELETE FROM users WHERE id = ? AND role != ?');
            $stmt->execute([$userId, 'admin']);
            echo json_encode(['success' => true, 'message' => 'Compte supprime']);
            break;

        // ====== MODE MAINTENANCE : TOGGLE ======
        case 'maintenance_status':
            $active = file_exists(__DIR__ . '/../maintenance.flag');
            echo json_encode(['success' => true, 'maintenance' => $active]);
            break;

        case 'maintenance_on':
            file_put_contents(__DIR__ . '/../maintenance.flag', date('Y-m-d H:i:s') . ' — Active par ' . ($_SESSION['user_name'] ?? 'admin'));
            echo json_encode(['success' => true, 'message' => 'Mode maintenance active']);
            break;

        case 'maintenance_off':
            $flagFile = __DIR__ . '/../maintenance.flag';
            if (file_exists($flagFile)) unlink($flagFile);
            echo json_encode(['success' => true, 'message' => 'Mode maintenance desactive']);
            break;

        default:
            echo json_encode(['error' => 'Action inconnue']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
