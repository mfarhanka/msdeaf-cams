-- database.sql
-- Run this in phpMyAdmin or your MySQL client

-- Select the target database before importing this file.
-- Example: USE your_database_name;

-- Users Table (Admin & Country Managers)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'country_manager') NOT NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    suspended_at TIMESTAMP NULL DEFAULT NULL,
    country_name VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Championships Table
CREATE TABLE IF NOT EXISTS championships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    location VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Hotels Table
CREATE TABLE IF NOT EXISTS hotels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    address TEXT NOT NULL,
    star_rating TINYINT UNSIGNED NOT NULL DEFAULT 0,
    total_rooms INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Championship_Hotels (Linking hotels to specific championships)
CREATE TABLE IF NOT EXISTS championship_hotels (
    championship_id INT,
    hotel_id INT,
    PRIMARY KEY (championship_id, hotel_id),
    FOREIGN KEY (championship_id) REFERENCES championships(id) ON DELETE CASCADE,
    FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
);

-- Room Types Table (Tiered Pricing based on capacity)
CREATE TABLE IF NOT EXISTS room_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id INT NOT NULL,
    name VARCHAR(50) NOT NULL, -- e.g., Single, Double, Triple
    capacity INT NOT NULL,
    price_per_night DECIMAL(10,2) NOT NULL,
    total_allotment INT NOT NULL,
    FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
);

-- Athletes/Participants Table
CREATE TABLE IF NOT EXISTS athletes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    country_id INT NOT NULL, -- Links to users.id where role='country_manager'
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    gender ENUM('M', 'F', 'Other') NOT NULL,
    participant_type ENUM('athlete', 'official') NOT NULL DEFAULT 'athlete',
    tshirt_size VARCHAR(10) NULL,
    sport_category VARCHAR(100) NOT NULL,
    passport_number VARCHAR(255) NOT NULL, -- In real app, this should be encrypted
    FOREIGN KEY (country_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Bookings Table
CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    championship_id INT NOT NULL,
    country_id INT NOT NULL,
    hotel_id INT NOT NULL,
    room_type_id INT NOT NULL,
    rooms_reserved INT NOT NULL,
    booking_start_date DATE NOT NULL,
    booking_end_date DATE NOT NULL,
    status ENUM('Pending', 'Confirmed', 'Cancelled') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (championship_id) REFERENCES championships(id),
    FOREIGN KEY (country_id) REFERENCES users(id),
    FOREIGN KEY (hotel_id) REFERENCES hotels(id),
    FOREIGN KEY (room_type_id) REFERENCES room_types(id)
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    actor_role VARCHAR(50) NULL,
    actor_username VARCHAR(100) NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT NULL,
    description TEXT NULL,
    metadata_json LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_logs_created_at (created_at),
    INDEX idx_activity_logs_actor_user_id (actor_user_id),
    INDEX idx_activity_logs_entity (entity_type, entity_id),
    CONSTRAINT fk_activity_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    display_on_login TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_announcements_display (display_on_login, is_enabled),
    CONSTRAINT fk_announcements_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Room Assignments
CREATE TABLE IF NOT EXISTS room_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    room_number VARCHAR(20) NULL,
    athlete_id INT NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
);

-- Independent arrival and departure groups per delegation
CREATE TABLE IF NOT EXISTS delegation_flight_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    country_id INT NOT NULL,
    direction ENUM('arrival', 'departure') NOT NULL,
    pax INT UNSIGNED NOT NULL,
    flight_number VARCHAR(30) NOT NULL,
    flight_datetime DATETIME NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_flight_movements_country (country_id, direction),
    FOREIGN KEY (country_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS delegation_flight_movement_members (
    movement_id INT NOT NULL,
    athlete_id INT NOT NULL,
    PRIMARY KEY (movement_id, athlete_id),
    FOREIGN KEY (movement_id) REFERENCES delegation_flight_movements(id) ON DELETE CASCADE,
    FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
);

INSERT INTO app_settings (setting_key, setting_value)
VALUES
    ('delegate_menu_dashboard_visible', '1'),
    ('delegate_menu_athletes_visible', '1'),
    ('delegate_menu_passport_visible', '1'),
    ('delegate_menu_flights_visible', '1'),
    ('delegate_menu_tshirt_visible', '1'),
    ('delegate_menu_book_visible', '1'),
    ('delegate_menu_rooming_visible', '1'),
    ('delegate_menu_finance_visible', '1')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
