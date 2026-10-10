<?php

function ensureParticipantSubtypeSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS participant_subtypes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        country_id INT NOT NULL,
        name VARCHAR(80) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_participant_subtype_country_name (country_id, name),
        CONSTRAINT fk_participant_subtype_country FOREIGN KEY (country_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='athletes' AND COLUMN_NAME='participant_subtype_id'")->fetchColumn();
    if (!$exists) {
        try { $pdo->exec("ALTER TABLE athletes ADD COLUMN participant_subtype_id INT NULL AFTER participant_type"); }
        catch (PDOException $e) { if ($e->getCode() !== '42S21') throw $e; }
    }
    if (!$pdo->query("SHOW INDEX FROM athletes WHERE Key_name='idx_athletes_participant_subtype'")->fetch()) {
        $pdo->exec("ALTER TABLE athletes ADD INDEX idx_athletes_participant_subtype (participant_subtype_id)");
    }
    $hasFk = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='athletes' AND CONSTRAINT_NAME='fk_athletes_participant_subtype'")->fetchColumn();
    if (!$hasFk) {
        $pdo->exec("ALTER TABLE athletes ADD CONSTRAINT fk_athletes_participant_subtype FOREIGN KEY (participant_subtype_id) REFERENCES participant_subtypes(id) ON DELETE SET NULL");
    }
}
