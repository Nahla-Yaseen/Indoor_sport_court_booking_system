<?php
$host   = getenv('DB_HOST')   ?: 'bupf2j2tw3yoqw67ttoo-mysql.services.clever-cloud.com';
$dbname = getenv('DB_NAME')   ?: 'bupf2j2tw3yoqw67ttoo';
$dbuser = getenv('DB_USER')   ?: 'ummednqxhyr8zmkr';
$dbpass = getenv('DB_PASS')   ?: 'gep9TH7GUgO1bezkVs62';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>