<?php

function ensureDelegationFlightsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS delegation_flight_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        country_id INT NOT NULL,
        direction ENUM('arrival', 'departure') NOT NULL,
        pax INT UNSIGNED NOT NULL,
        flight_number VARCHAR(30) NOT NULL,
        flight_datetime DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_flight_movements_country (country_id, direction),
        CONSTRAINT fk_flight_movements_country FOREIGN KEY (country_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS delegation_flight_movement_members (
        movement_id INT NOT NULL,
        athlete_id INT NOT NULL,
        PRIMARY KEY (movement_id, athlete_id),
        CONSTRAINT fk_flight_member_movement FOREIGN KEY (movement_id) REFERENCES delegation_flight_movements(id) ON DELETE CASCADE,
        CONSTRAINT fk_flight_member_athlete FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    )");

    // Remove pre-selection records created before flight groups used named delegates.
    $pdo->exec("DELETE movement FROM delegation_flight_movements movement
        LEFT JOIN delegation_flight_movement_members member ON member.movement_id = movement.id
        WHERE member.movement_id IS NULL");
}
