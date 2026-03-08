<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$stmt = db()->query("SELECT id, business_name, place_id FROM audits WHERE place_id IS NOT NULL AND place_id != '' LIMIT 1");
$audit = $stmt->fetch();

$bizInfo = dataforseoMyBusinessInfo($audit['place_id'], $audit['business_name']);

echo json_encode([
    'prospect' => $audit['business_name'],
    'result' => $bizInfo,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
