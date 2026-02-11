<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDB();
$stmt = $pdo->query("DESCRIBE project_ahsp_rap");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Columns in project_ahsp_rap:\n";
print_r($columns);

$stmt = $pdo->query("DESCRIBE project_ahsp_details_rap");
echo "Columns in project_ahsp_details_rap:\n";
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
