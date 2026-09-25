<?php

function ensureVolunteerSchema(PDO $pdo): void
{
    $roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    if ($roleColumn && strpos((string) $roleColumn['Type'], "'volunteer'") === false) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','country_manager','volunteer') NOT NULL");
    }

    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('must_change_password', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS volunteer_applications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL UNIQUE,
        full_name VARCHAR(150) NOT NULL,
        age TINYINT UNSIGNED NOT NULL,
        gender ENUM('male','female') NOT NULL,
        identity_type ENUM('ic','passport') NOT NULL,
        identity_encrypted TEXT NOT NULL,
        identity_hash CHAR(64) NOT NULL UNIQUE,
        address_encrypted TEXT NOT NULL,
        occupation VARCHAR(150) NOT NULL,
        whatsapp VARCHAR(30) NOT NULL,
        email VARCHAR(190) NOT NULL,
        tshirt_size ENUM('S','M','L','XL','2XL','3XL','4XL') NOT NULL,
        accommodation_required TINYINT(1) NOT NULL DEFAULT 0,
        department ENUM('technical','secretariat') NOT NULL,
        available_from DATE NOT NULL,
        available_until DATE NULL,
        medical_encrypted TEXT NOT NULL,
        skills TEXT NOT NULL,
        motivation TEXT NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        review_note TEXT NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_volunteer_status_created (status, created_at),
        INDEX idx_volunteer_email (email),
        CONSTRAINT fk_volunteer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_volunteer_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function volunteerEnv(string $key): string
{
    $value = getenv($key);
    return $value === false ? '' : trim((string) $value);
}

function normalizeVolunteerIdentity(string $value): string
{
    return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($value)));
}

function volunteerSecret(string $name): string
{
    $value = volunteerEnv($name);
    if ($value === '') {
        throw new RuntimeException('Volunteer security configuration is incomplete. Please contact the administrator.');
    }
    return hash('sha256', $value, true);
}

function hashVolunteerIdentity(string $value): string
{
    return hash_hmac('sha256', normalizeVolunteerIdentity($value), volunteerSecret('VOLUNTEER_LOOKUP_KEY'));
}

function encryptVolunteerValue(string $value): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', volunteerSecret('VOLUNTEER_ENCRYPTION_KEY'), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Unable to protect volunteer information.');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function decryptVolunteerValue(string $encoded): string
{
    $payload = base64_decode($encoded, true);
    if ($payload === false || strlen($payload) < 29) {
        return '';
    }
    $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', volunteerSecret('VOLUNTEER_ENCRYPTION_KEY'), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
    return $plain === false ? '' : $plain;
}

function maskVolunteerIdentity(string $value): string
{
    $normalized = normalizeVolunteerIdentity($value);
    return str_repeat('*', max(0, strlen($normalized) - 4)) . substr($normalized, -4);
}

function volunteerCsrfToken(): string
{
    if (empty($_SESSION['volunteer_csrf'])) {
        $_SESSION['volunteer_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['volunteer_csrf'];
}

function verifyVolunteerCsrf(?string $token): void
{
    if (!is_string($token) || !hash_equals(volunteerCsrfToken(), $token)) {
        throw new RuntimeException('Your session expired. Please refresh and try again.');
    }
}

function generateTemporaryVolunteerPassword(): string
{
    return 'V!' . bin2hex(random_bytes(6)) . 'a9';
}

function sendVolunteerEmail(string $to, string $subject, string $html): void
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Email service is not installed. Run Composer install before approving applications.');
    }
    require_once $autoload;
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = volunteerEnv('SMTP_HOST');
    $mail->Port = (int) (volunteerEnv('SMTP_PORT') ?: 587);
    $mail->SMTPAuth = true;
    $mail->Username = volunteerEnv('SMTP_USERNAME');
    $mail->Password = volunteerEnv('SMTP_PASSWORD');
    $mail->SMTPSecure = volunteerEnv('SMTP_ENCRYPTION') ?: PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $from = volunteerEnv('SMTP_FROM_ADDRESS');
    if ($mail->Host === '' || $mail->Username === '' || $from === '') {
        throw new RuntimeException('SMTP configuration is incomplete.');
    }
    $mail->setFrom($from, volunteerEnv('SMTP_FROM_NAME') ?: 'MSDeaf CAMS');
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = $subject;
    $mail->Body = $html;
    $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
    $mail->send();
}
