<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
$cols = db()->query('SHOW COLUMNS FROM audits')->fetchAll(PDO::FETCH_COLUMN, 0);
echo json_encode(['columns' => $cols, 'count' => count($cols)]);
