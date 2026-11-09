<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$stmt = $db->query('SELECT AVG(k.rating) as avg_rating, COUNT(DISTINCT k.activity_id) as events_evaluated FROM kpi_ratings k');
print_r($stmt->fetch(PDO::FETCH_ASSOC));

