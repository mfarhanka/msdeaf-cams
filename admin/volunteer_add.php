<?php
require_once 'includes/auth.php';
require_once '../includes/volunteers.php';

$actor = getActorDetailsFromSession();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyVolunteerCsrf($_POST['csrf_token'] ?? null);
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

        if ($data['full_name'] === '' || $data['age'] < 16 || $data['age'] > 100
            || !in_array($data['gender'], ['male', 'female'], true)
            || !in_array($data['identity_type'], ['ic', 'passport'], true) || strlen($data['identity']) < 6
            || $data['address'] === '' || $data['occupation'] === ''
            || !preg_match('/^[+0-9()\-\s]{8,30}$/', $data['whatsapp'])
            || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)
            || !in_array($data['tshirt_size'], ['S','M','L','XL','2XL','3XL','4XL'], true)
            || !in_array($data['department'], ['technical', 'secretariat'], true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['available_from'])
            || $data['medical'] === '' || $data['skills'] === '' || $data['motivation'] === '') {
            throw new RuntimeException('Please complete every required field with valid information.');
        }
        if ($data['available_until'] !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['available_until']) || $data['available_until'] < $data['available_from'])) {
            throw new RuntimeException('The end date must be on or after the start date.');
        }

        $identityHash = hashVolunteerIdentity($data['identity']);
        $identityDuplicate = $pdo->prepare('SELECT id FROM volunteer_applications WHERE identity_hash = ? LIMIT 1');
        $identityDuplicate->execute([$identityHash]);
        if ($identityDuplicate->fetchColumn()) {
            throw new RuntimeException('A volunteer with this IC/passport number already exists.');
        }

        $normalizePhone = static function (string $phone): string {
            $normalized = (string) preg_replace('/\D+/', '', $phone);
            if (str_starts_with($normalized, '0060')) { return '0' . substr($normalized, 4); }
            if (str_starts_with($normalized, '60')) { return '0' . substr($normalized, 2); }
            return $normalized;
        };
        $phoneKey = $normalizePhone($data['whatsapp']);
        foreach ($pdo->query('SELECT whatsapp FROM volunteer_applications')->fetchAll(PDO::FETCH_COLUMN) as $existingPhone) {
            if ($phoneKey !== '' && $normalizePhone((string) $existingPhone) === $phoneKey) {
                throw new RuntimeException('A volunteer with this phone number already exists.');
            }
        }

        $stmt = $pdo->prepare('INSERT INTO volunteer_applications (full_name, age, gender, identity_type, identity_encrypted, identity_hash, address_encrypted, occupation, whatsapp, email, tshirt_size, accommodation_required, department, available_from, available_until, medical_encrypted, skills, motivation) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$data['full_name'], $data['age'], $data['gender'], $data['identity_type'], encryptVolunteerValue($data['identity']), $identityHash, encryptVolunteerValue($data['address']), $data['occupation'], $data['whatsapp'], $data['email'], $data['tshirt_size'], $data['accommodation_required'], $data['department'], $data['available_from'], $data['available_until'] ?: null, encryptVolunteerValue($data['medical']), $data['skills'], $data['motivation']]);
        $applicationId = (int) $pdo->lastInsertId();
        recordActivity($pdo, 'volunteer_application_added_by_admin', 'volunteer_application', $applicationId, 'Volunteer application added manually by admin.', [], $actor['id'], $actor['role'], $actor['username']);
        header('Location: volunteers.php?added=1');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$old = static function (string $key): string { return htmlspecialchars((string) ($_POST[$key] ?? '')); };
require_once 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center border-bottom mb-3 pb-2"><div><h1 class="h2 mb-1">Add Volunteer</h1><p class="text-muted mb-0">Manually create a pending volunteer application.</p></div><a class="btn btn-outline-secondary" href="volunteers.php">Back to Volunteers</a></div>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post" class="card"><div class="card-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(volunteerCsrfToken()); ?>">
<h2 class="h5 text-primary border-bottom pb-2">Personal Information</h2><div class="row g-3">
<div class="col-md-8"><label class="form-label">Full name *</label><input class="form-control" name="full_name" maxlength="150" value="<?php echo $old('full_name'); ?>" required></div>
<div class="col-md-4"><label class="form-label">Age *</label><input class="form-control" type="number" name="age" min="16" max="100" value="<?php echo $old('age'); ?>" required></div>
<div class="col-md-4"><label class="form-label">Gender *</label><select class="form-select" name="gender" required><option value="">Select</option><option value="male" <?php echo $old('gender') === 'male' ? 'selected' : ''; ?>>Male</option><option value="female" <?php echo $old('gender') === 'female' ? 'selected' : ''; ?>>Female</option></select></div>
<div class="col-md-3"><label class="form-label">ID type *</label><select class="form-select" name="identity_type"><option value="ic">IC</option><option value="passport" <?php echo $old('identity_type') === 'passport' ? 'selected' : ''; ?>>Passport</option></select></div>
<div class="col-md-5"><label class="form-label">IC / Passport number *</label><input class="form-control" name="identity" maxlength="30" value="<?php echo $old('identity'); ?>" required></div>
<div class="col-12"><label class="form-label">Home address *</label><textarea class="form-control" name="address" rows="2" required><?php echo $old('address'); ?></textarea></div>
<div class="col-md-6"><label class="form-label">Occupation *</label><input class="form-control" name="occupation" maxlength="150" value="<?php echo $old('occupation'); ?>" required></div>
<div class="col-md-6"><label class="form-label">Phone / WhatsApp *</label><input class="form-control" type="tel" name="whatsapp" value="<?php echo $old('whatsapp'); ?>" required></div>
<div class="col-12"><label class="form-label">Email *</label><input class="form-control" type="email" name="email" maxlength="190" value="<?php echo $old('email'); ?>" required></div></div>
<h2 class="h5 text-primary border-bottom pb-2 mt-4">Preferences &amp; Availability</h2><div class="row g-3">
<div class="col-md-4"><label class="form-label">Shirt size *</label><select class="form-select" name="tshirt_size" required><option value="">Select</option><?php foreach (['S','M','L','XL','2XL','3XL','4XL'] as $size): ?><option value="<?php echo $size; ?>" <?php echo $old('tshirt_size') === $size ? 'selected' : ''; ?>><?php echo $size; ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Accommodation needed? *</label><select class="form-select" name="accommodation_required"><option value="0">No</option><option value="1" <?php echo $old('accommodation_required') === '1' ? 'selected' : ''; ?>>Yes</option></select></div>
<div class="col-md-4"><label class="form-label">Department *</label><select class="form-select" name="department"><option value="technical">Technical</option><option value="secretariat" <?php echo $old('department') === 'secretariat' ? 'selected' : ''; ?>>Secretariat</option></select></div>
<div class="col-md-6"><label class="form-label">Available from *</label><input class="form-control" type="date" name="available_from" value="<?php echo $old('available_from'); ?>" required></div>
<div class="col-md-6"><label class="form-label">Available until</label><input class="form-control" type="date" name="available_until" value="<?php echo $old('available_until'); ?>"></div>
<div class="col-12"><label class="form-label">Medical conditions / allergies *</label><textarea class="form-control" name="medical" rows="2" required><?php echo $old('medical'); ?></textarea></div>
<div class="col-12"><label class="form-label">Skills / experience *</label><textarea class="form-control" name="skills" rows="3" required><?php echo $old('skills'); ?></textarea></div>
<div class="col-12"><label class="form-label">Motivation *</label><textarea class="form-control" name="motivation" rows="3" required><?php echo $old('motivation'); ?></textarea></div></div>
<div class="d-flex justify-content-end gap-2 mt-4"><a class="btn btn-outline-secondary" href="volunteers.php">Cancel</a><button class="btn btn-primary" type="submit">Add Volunteer</button></div>
</div></form>
<?php require_once 'includes/footer.php'; ?>
