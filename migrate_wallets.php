<?php
require_once __DIR__ . '/includes/db.php';

echo "Starting wallet balance synchronization...\n";

// Get all users
$stmt = $pdo->query("SELECT id, name FROM users");
$users = $stmt->fetchAll();

foreach ($users as $user) {
    // Sum Available pending transactions
    $txStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total 
        FROM pending_transactions 
        WHERE user_id = ? AND status = 'Available'
    ");
    $txStmt->execute([$user['id']]);
    $availableTotal = floatval($txStmt->fetchColumn());

    // Update users.wallet_balance
    $updateStmt = $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
    $updateStmt->execute([$availableTotal, $user['id']]);

    echo "User #{$user['id']} ({$user['name']}): Wallet balance synced to LKR " . number_format($availableTotal, 2) . "\n";
}

echo "Synchronization complete!\n";
?>
