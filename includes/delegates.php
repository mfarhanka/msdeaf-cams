<?php
function ensureDelegateSelfServiceSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $columns = [
        'emergency_contact_name' => "VARCHAR(150) NULL AFTER passport_number",
        'emergency_contact_phone' => "VARCHAR(30) NULL AFTER emergency_contact_name",
        'emergency_contact_relationship' => "VARCHAR(100) NULL AFTER emergency_contact_phone",
    ];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='athletes' AND COLUMN_NAME=?");
    foreach ($columns as $name => $definition) {
        $stmt->execute([$name]);
        if (!(int)$stmt->fetchColumn()) $pdo->exec("ALTER TABLE athletes ADD COLUMN {$name} {$definition}");
    }
}

function ensureDelegateClaimsSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS delegate_expense_claims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        athlete_id INT NOT NULL,
        expense_date DATE NOT NULL,
        category VARCHAR(80) NOT NULL,
        description VARCHAR(500) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'MYR',
        amount DECIMAL(12,2) NOT NULL,
        receipt_path VARCHAR(255) NULL,
        receipt_original_name VARCHAR(255) NULL,
        status ENUM('Pending','Approved','Rejected','Paid') NOT NULL DEFAULT 'Pending',
        admin_note VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_delegate_claims_athlete (athlete_id, created_at),
        INDEX idx_delegate_claims_status (status),
        CONSTRAINT fk_delegate_claims_athlete FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function normalizeDelegateIdentity(string $identity): string
{
    return strtoupper(preg_replace('/[\s-]+/', '', trim($identity)) ?? '');
}
