<?php
session_start();
$suppressDbErrors = true;
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/volunteers.php';
require_once __DIR__ . '/includes/activity.php';

$language = ($_GET['lang'] ?? $_POST['lang'] ?? 'ms') === 'en' ? 'en' : 'ms';
$t = static function (string $ms, string $en) use ($language): string { return $language === 'en' ? $en : $ms; };
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    try {
        verifyVolunteerCsrf($_POST['csrf_token'] ?? null);
        if (trim($_POST['website'] ?? '') !== '') {
            throw new RuntimeException($t('Permohonan tidak dapat dihantar.', 'The application could not be submitted.'));
        }
        $lastSubmission = (int) ($_SESSION['volunteer_last_submission'] ?? 0);
        if ($lastSubmission > 0 && time() - $lastSubmission < 60) {
            throw new RuntimeException($t('Sila tunggu sebentar sebelum menghantar semula.', 'Please wait before submitting again.'));
        }

        $data = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'age' => (int) ($_POST['age'] ?? 0),
            'gender' => $_POST['gender'] ?? '',
            'identity_type' => $_POST['identity_type'] ?? '',
            'identity' => normalizeVolunteerIdentity($_POST['identity'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'occupation' => trim($_POST['occupation'] ?? ''),
            'whatsapp' => trim($_POST['whatsapp'] ?? ''),
            'email' => strtolower(trim($_POST['email'] ?? '')),
            'tshirt_size' => $_POST['tshirt_size'] ?? '',
            'accommodation_required' => ($_POST['accommodation_required'] ?? '') === '1' ? 1 : 0,
            'department' => $_POST['department'] ?? '',
            'available_from' => $_POST['available_from'] ?? '',
            'available_until' => $_POST['available_until'] ?? '',
            'medical' => trim($_POST['medical'] ?? ''),
            'skills' => trim($_POST['skills'] ?? ''),
            'motivation' => trim($_POST['motivation'] ?? ''),
        ];
        if ($data['full_name'] === '' || $data['age'] < 16 || $data['age'] > 100 || !in_array($data['gender'], ['male', 'female'], true)
            || !in_array($data['identity_type'], ['ic', 'passport'], true) || strlen($data['identity']) < 6 || $data['address'] === ''
            || $data['occupation'] === '' || !preg_match('/^[+0-9()\-\s]{8,30}$/', $data['whatsapp'])
            || !filter_var($data['email'], FILTER_VALIDATE_EMAIL) || !in_array($data['tshirt_size'], ['S','M','L','XL','2XL','3XL','4XL'], true)
            || !in_array($data['department'], ['technical', 'secretariat'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['available_from'])
            || $data['medical'] === '' || $data['skills'] === '' || $data['motivation'] === '') {
            throw new RuntimeException($t('Sila lengkapkan semua ruangan wajib dengan maklumat yang sah.', 'Please complete every required field with valid information.'));
        }
        if ($data['available_until'] !== '' && ($data['available_until'] < $data['available_from'] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['available_until']))) {
            throw new RuntimeException($t('Tarikh akhir mesti selepas tarikh mula.', 'The end date must be on or after the start date.'));
        }
        $identityHash = hashVolunteerIdentity($data['identity']);
        $duplicate = $pdo->prepare('SELECT id FROM volunteer_applications WHERE identity_hash = ? OR (email = ? AND status IN (\'pending\',\'approved\')) LIMIT 1');
        $duplicate->execute([$identityHash, $data['email']]);
        if ($duplicate->fetchColumn()) {
            throw new RuntimeException($t('Permohonan menggunakan pengenalan atau e-mel ini telah wujud.', 'An application using this identification or email already exists.'));
        }
        $stmt = $pdo->prepare('INSERT INTO volunteer_applications (full_name, age, gender, identity_type, identity_encrypted, identity_hash, address_encrypted, occupation, whatsapp, email, tshirt_size, accommodation_required, department, available_from, available_until, medical_encrypted, skills, motivation) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$data['full_name'], $data['age'], $data['gender'], $data['identity_type'], encryptVolunteerValue($data['identity']), $identityHash, encryptVolunteerValue($data['address']), $data['occupation'], $data['whatsapp'], $data['email'], $data['tshirt_size'], $data['accommodation_required'], $data['department'], $data['available_from'], $data['available_until'] ?: null, encryptVolunteerValue($data['medical']), $data['skills'], $data['motivation']]);
        $applicationId = (int) $pdo->lastInsertId();
        recordActivity($pdo, 'volunteer_application_submitted', 'volunteer_application', $applicationId, 'Volunteer application submitted.');
        try {
            sendVolunteerEmail($data['email'], 'Permohonan sukarelawan diterima / Volunteer application received', '<p>Salam ' . htmlspecialchars($data['full_name']) . ',</p><p>Permohonan sukarelawan anda telah diterima dan sedang disemak.</p><hr><p>Your volunteer application has been received and is under review.</p>');
        } catch (Throwable $mailError) {
            error_log('Volunteer confirmation email failed: ' . $mailError->getMessage());
        }
        $_SESSION['volunteer_last_submission'] = time();
        $_SESSION['volunteer_csrf'] = bin2hex(random_bytes(32));
        $success = true;
        $_POST = [];
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = $t('Perkhidmatan pangkalan data tidak tersedia.', 'The database service is unavailable.');
}

function oldVolunteer(string $key): string { return htmlspecialchars((string) ($_POST[$key] ?? '')); }
?>
<!doctype html><html lang="<?php echo $language; ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $t('Permohonan Sukarelawan', 'Volunteer Application'); ?> - MSDeaf</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<style>body{background:#f3f6fb;font-family:Segoe UI,sans-serif}.hero{background:linear-gradient(135deg,#004a99,#007bff);color:#fff}.form-card{border:0;border-radius:16px;box-shadow:0 10px 35px rgba(0,38,82,.1)}.required:after{content:' *';color:#dc3545}.section-title{color:#004a99;border-bottom:2px solid #e6f0ff;padding-bottom:.5rem}.honeypot{position:absolute;left:-10000px}</style></head><body>
<header class="hero py-4"><div class="container d-flex justify-content-between align-items-center"><div><h1 class="h3 mb-1"><i class="bi bi-people-fill me-2"></i><?php echo $t('Permohonan Sukarelawan', 'Volunteer Application'); ?></h1><p class="mb-0 opacity-75">Malaysian Deaf Sports Association</p></div><div><a class="btn btn-sm btn-light" href="?lang=<?php echo $language === 'ms' ? 'en' : 'ms'; ?>"><?php echo $language === 'ms' ? 'English' : 'Bahasa Melayu'; ?></a> <a class="btn btn-sm btn-outline-light" href="login.php"><?php echo $t('Log Masuk', 'Login'); ?></a></div></div></header>
<main class="container py-4" style="max-width:900px">
<?php if ($success): ?><div class="alert alert-success form-card p-4"><h2 class="h4"><i class="bi bi-check-circle-fill me-2"></i><?php echo $t('Permohonan diterima', 'Application received'); ?></h2><p class="mb-0"><?php echo $t('Terima kasih. Kami akan menghubungi anda melalui e-mel selepas semakan.', 'Thank you. We will contact you by email after review.'); ?></p></div><?php else: ?>
<div class="card form-card"><div class="card-body p-4 p-md-5"><h2 class="h4 section-title"><?php echo $t('Syarat Permohonan', 'Application Requirements'); ?></h2><ul class="text-muted"><li><?php echo $t('Mempunyai kemahiran bahasa isyarat sekurang-kurangnya pada tahap asas.', 'Have at least basic sign-language skills.'); ?></li><li><?php echo $t('Ceria, bertanggungjawab dan mempunyai komitmen yang tinggi.', 'Be positive, responsible, and highly committed.'); ?></li><li><?php echo $t('Bersedia bertugas apabila diperlukan.', 'Be available to serve when needed.'); ?></li></ul>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post" novalidate><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(volunteerCsrfToken()); ?>"><input type="hidden" name="lang" value="<?php echo $language; ?>"><div class="honeypot"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
<h3 class="h5 section-title mt-4"><?php echo $t('Maklumat Peribadi', 'Personal Information'); ?></h3><div class="row g-3">
<div class="col-md-8"><label class="form-label required"><?php echo $t('Nama penuh', 'Full name'); ?></label><input class="form-control" name="full_name" maxlength="150" value="<?php echo oldVolunteer('full_name'); ?>" required></div>
<div class="col-md-4"><label class="form-label required"><?php echo $t('Umur', 'Age'); ?></label><input class="form-control" type="number" name="age" min="16" max="100" value="<?php echo oldVolunteer('age'); ?>" required></div>
<div class="col-md-4"><label class="form-label required"><?php echo $t('Jantina', 'Gender'); ?></label><select class="form-select" name="gender" required><option value=""></option><option value="male" <?php echo oldVolunteer('gender') === 'male'?'selected':''; ?>><?php echo $t('Lelaki','Male'); ?></option><option value="female" <?php echo oldVolunteer('gender') === 'female'?'selected':''; ?>><?php echo $t('Perempuan','Female'); ?></option></select></div>
<div class="col-md-3"><label class="form-label required"><?php echo $t('Jenis pengenalan', 'ID type'); ?></label><select class="form-select" name="identity_type"><option value="ic">IC</option><option value="passport" <?php echo oldVolunteer('identity_type') === 'passport'?'selected':''; ?>>Passport</option></select></div>
<div class="col-md-5"><label class="form-label required"><?php echo $t('Nombor IC / Pasport', 'IC / Passport number'); ?></label><input class="form-control" name="identity" maxlength="30" value="<?php echo oldVolunteer('identity'); ?>" required></div>
<div class="col-12"><label class="form-label required"><?php echo $t('Alamat rumah', 'Home address'); ?></label><textarea class="form-control" name="address" rows="2" required><?php echo oldVolunteer('address'); ?></textarea></div>
<div class="col-md-6"><label class="form-label required"><?php echo $t('Pekerjaan', 'Occupation'); ?></label><input class="form-control" name="occupation" maxlength="150" value="<?php echo oldVolunteer('occupation'); ?>" required></div>
<div class="col-md-6"><label class="form-label required"><?php echo $t('Nombor telefon / WhatsApp', 'Phone / WhatsApp'); ?></label><input class="form-control" type="tel" name="whatsapp" value="<?php echo oldVolunteer('whatsapp'); ?>" required></div>
<div class="col-12"><label class="form-label required"><?php echo $t('Alamat e-mel', 'Email address'); ?></label><input class="form-control" type="email" name="email" maxlength="190" value="<?php echo oldVolunteer('email'); ?>" required></div></div>
<h3 class="h5 section-title mt-4"><?php echo $t('Pilihan & Ketersediaan', 'Preferences & Availability'); ?></h3><div class="row g-3">
<div class="col-md-4"><label class="form-label required"><?php echo $t('Saiz baju T-shirt', 'T-shirt size'); ?></label><select class="form-select" name="tshirt_size" required><option value=""></option><?php foreach(['S','M','L','XL','2XL','3XL','4XL'] as $size): ?><option <?php echo oldVolunteer('tshirt_size')===$size?'selected':''; ?>><?php echo $size; ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label required"><?php echo $t('Perlu penginapan?', 'Accommodation needed?'); ?></label><select class="form-select" name="accommodation_required"><option value="0"><?php echo $t('Tidak','No'); ?></option><option value="1" <?php echo oldVolunteer('accommodation_required')==='1'?'selected':''; ?>><?php echo $t('Ya','Yes'); ?></option></select></div>
<div class="col-md-4"><label class="form-label required"><?php echo $t('Bahagian', 'Department'); ?></label><select class="form-select" name="department"><option value="technical"><?php echo $t('Teknikal','Technical'); ?></option><option value="secretariat" <?php echo oldVolunteer('department')==='secretariat'?'selected':''; ?>><?php echo $t('Sekretariat','Secretariat'); ?></option></select></div>
<div class="col-md-6"><label class="form-label required"><?php echo $t('Boleh mula bertugas', 'Available from'); ?></label><input class="form-control" type="date" name="available_from" value="<?php echo oldVolunteer('available_from'); ?>" required></div><div class="col-md-6"><label class="form-label"><?php echo $t('Boleh bertugas sehingga', 'Available until'); ?></label><input class="form-control" type="date" name="available_until" value="<?php echo oldVolunteer('available_until'); ?>"></div>
<div class="col-12"><label class="form-label required"><?php echo $t('Penyakit atau alahan (tulis “Tiada” jika tiada)', 'Medical conditions or allergies (enter “None” if none)'); ?></label><textarea class="form-control" name="medical" rows="2" required><?php echo oldVolunteer('medical'); ?></textarea></div>
<div class="col-12"><label class="form-label required"><?php echo $t('Kemahiran atau pengalaman berkaitan', 'Relevant skills or experience'); ?></label><textarea class="form-control" name="skills" rows="3" required><?php echo oldVolunteer('skills'); ?></textarea></div>
<div class="col-12"><label class="form-label required"><?php echo $t('Mengapa anda ingin menjadi sukarelawan?', 'Why do you want to volunteer?'); ?></label><textarea class="form-control" name="motivation" rows="3" required><?php echo oldVolunteer('motivation'); ?></textarea></div></div>
<div class="d-grid mt-4"><button class="btn btn-primary btn-lg" style="background:#004a99"><i class="bi bi-send me-2"></i><?php echo $t('Hantar Permohonan','Submit Application'); ?></button></div></form></div></div><?php endif; ?></main></body></html>
