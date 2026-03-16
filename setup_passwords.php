<?php
// setup_passwords.php
require_once 'includes/db.php';

try {
    // Generate fresh hashes
    $admin_hash = password_hash('admin123', PASSWORD_DEFAULT);
    $usa_hash = password_hash('usa123', PASSWORD_DEFAULT);

    // Update Admin
    $stmt1 = $pdo->prepare("UPDATE users SET password = :password WHERE username = 'admin'");
    $stmt1->execute([':password' => $admin_hash]);

    // Update USA
    $stmt2 = $pdo->prepare("UPDATE users SET password = :password WHERE username = 'usa'");
    $stmt2->execute([':password' => $usa_hash]);

    echo "Passwords updated successfully!\n";
    echo "Admin Hash: " . $admin_hash . "\n";
    echo "USA Hash: " . $usa_hash . "\n";
    
} catch(PDOException $e) {
    echo "Error updating passwords: " . $e->getMessage() . "\n";
}
?>