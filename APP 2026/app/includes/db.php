<?php
/**
 * BOUS'TACOM — Connexion base de données (PDO)
 *
 * Reconnexion automatique si MySQL a coupe la connexion
 * (erreur 2006 "MySQL server has gone away") — courant apres
 * les longs polling DataForSEO (30-120s de sleep).
 */

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }

    /**
     * Force la reconnexion MySQL.
     * A appeler apres un long sleep (polling DataForSEO, etc.)
     * pour eviter "MySQL server has gone away" (erreur 2006).
     */
    public static function reconnect(): void {
        self::$instance = null;
        self::$instance = self::createConnection();
    }

    /**
     * Verifie si la connexion est vivante, reconnecte si besoin.
     */
    public static function ensureConnected(): void {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
            return;
        }
        try {
            self::$instance->query('SELECT 1');
        } catch (PDOException $e) {
            error_log("MySQL connexion perdue, reconnexion... (" . $e->getMessage() . ")");
            self::$instance = null;
            self::$instance = self::createConnection();
        }
    }

    private static function createConnection(): PDO {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            return new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
                PDO::MYSQL_ATTR_INIT_COMMAND  => "SET NAMES utf8mb4"
            ]);
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                die('Erreur BDD : ' . $e->getMessage());
            }
            die('Erreur de connexion à la base de données.');
        }
    }
}

/**
 * Raccourci global
 */
function db(): PDO {
    return Database::getConnection();
}

/**
 * Raccourci : verifier/reconnecter MySQL apres un long polling.
 * Usage : dbEnsureConnected() avant toute operation DB apres un sleep.
 */
function dbEnsureConnected(): void {
    Database::ensureConnected();
}
