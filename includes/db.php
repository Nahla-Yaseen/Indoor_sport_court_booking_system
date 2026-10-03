<?php
$host   = 'bupf2j2tw3yoqw67ttoo-mysql.services.clever-cloud.com';
$dbname = 'bupf2j2tw3yoqw67ttoo';
$dbuser = 'ummednqxhyr8zmkr';
$dbpass = 'gep9TH7GUgO1bezkVs62';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>