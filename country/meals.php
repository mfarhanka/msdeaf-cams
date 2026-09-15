<?php
require_once 'includes/auth.php';

$countryId = (int) $_SESSION['id'];
$mealOptions = ['vegetarian', 'non_vegetarian'];

foreach ([
    'meal_preference' => "ALTER TABLE athletes ADD COLUMN meal_preference ENUM('vegetarian', 'non_vegetarian') NULL AFTER tshirt_size",
    'allergy_details' => "ALTER TABLE athletes ADD COLUMN allergy_details VARCHAR(500) NULL AFTER meal_preference",
] as $column => $alterSql) {
    $columnStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'athletes' AND COLUMN_NAME = ?");
    $columnStmt->execute([$column]);
    if (!(int) $columnStmt->fetchColumn()) {
        $pdo->exec($alterSql);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_meal_preferences') {
    $preferences = is_array($_POST['meal_preferences'] ?? null) ? $_POST['meal_preferences'] : [];
    $hasAllergies = is_array($_POST['has_allergies'] ?? null) ? $_POST['has_allergies'] : [];
    $allergyDetails = is_array($_POST['allergy_details'] ?? null) ? $_POST['allergy_details'] : [];
    $validationErrors = [];

    $participantStmt = $pdo->prepare('SELECT id FROM athletes WHERE country_id = ?');
    $participantStmt->execute([$countryId]);
    $participantIds = array_map('intval', $participantStmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($participantIds as $participantId) {
        $mealPreference = trim((string) ($preferences[$participantId] ?? ''));
        $allergic = isset($hasAllergies[$participantId]) && (string) $hasAllergies[$participantId] === '1';
        $details = trim((string) ($allergyDetails[$participantId] ?? ''));

        if ($mealPreference !== '' && !in_array($mealPreference, $mealOptions, true)) {
            $validationErrors[] = 'An invalid meal preference was submitted.';
            break;
        }
        if ($allergic && $details === '') {
            $validationErrors[] = 'Please describe every selected participant allergy.';
            break;
        }
        if (strlen($details) > 500) {
            $validationErrors[] = 'Allergy details must be 500 characters or fewer.';
            break;
        }
    }

    if ($validationErrors === []) {
        $pdo->beginTransaction();
        try {
            $updateStmt = $pdo->prepare('UPDATE athletes SET meal_preference = ?, allergy_details = ? WHERE id = ? AND country_id = ?');
            foreach ($participantIds as $participantId) {
                $mealPreference = trim((string) ($preferences[$participantId] ?? ''));
                $allergic = isset($hasAllergies[$participantId]) && (string) $hasAllergies[$participantId] === '1';
                $details = trim((string) ($allergyDetails[$participantId] ?? ''));
                $updateStmt->execute([$mealPreference !== '' ? $mealPreference : null, $allergic ? $details : null, $participantId, $countryId]);
            }
            $pdo->commit();
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-check-circle me-1'></i>Meal preferences updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "<div class='alert alert-danger'>Meal preferences could not be saved. Please try again.</div>";
        }
    } else {
        $msg = "<div class='alert alert-warning'>" . htmlspecialchars($validationErrors[0]) . "</div>";
    }
}

$participantsStmt = $pdo->prepare('SELECT id, first_name, last_name, gender, participant_type, meal_preference, allergy_details FROM athletes WHERE country_id = ? ORDER BY last_name, first_name');
$participantsStmt->execute([$countryId]);
$participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap align-items-center pb-2 mb-3 border-bottom">
    <div><h1 class="h2 mb-1">Meal Preferences</h1><p class="text-muted mb-0">Record meal choices and food allergies for every delegation member.</p></div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if ($participants): ?>
            <form method="POST" id="mealPreferencesForm">
                <input type="hidden" name="action" value="save_meal_preferences">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Participant</th><th>Type</th><th style="min-width:210px">Meal Preference</th><th style="min-width:300px">Food Allergies</th></tr></thead>
                        <tbody>
                        <?php foreach ($participants as $participant): $id = (int) $participant['id']; $hasAllergy = trim((string) ($participant['allergy_details'] ?? '')) !== ''; ?>
                            <tr>
                                <td><span class="fw-semibold"><?php echo htmlspecialchars($participant['first_name'] . ' ' . $participant['last_name']); ?></span><div class="small text-muted"><?php echo htmlspecialchars($participant['gender']); ?></div></td>
                                <td><?php echo htmlspecialchars(ucfirst($participant['participant_type'] ?? 'athlete')); ?></td>
                                <td>
                                    <select class="form-select form-select-sm" name="meal_preferences[<?php echo $id; ?>]">
                                        <option value="">-- Not Set --</option>
                                        <option value="non_vegetarian" <?php echo $participant['meal_preference'] === 'non_vegetarian' ? 'selected' : ''; ?>>Non-vegetarian</option>
                                        <option value="vegetarian" <?php echo $participant['meal_preference'] === 'vegetarian' ? 'selected' : ''; ?>>Vegetarian</option>
                                    </select>
                                </td>
                                <td>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input allergy-toggle" type="checkbox" name="has_allergies[<?php echo $id; ?>]" value="1" id="allergy<?php echo $id; ?>" <?php echo $hasAllergy ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="allergy<?php echo $id; ?>">Has allergies</label>
                                    </div>
                                    <input type="text" class="form-control form-control-sm allergy-details <?php echo $hasAllergy ? '' : 'd-none'; ?>" name="allergy_details[<?php echo $id; ?>]" value="<?php echo htmlspecialchars($participant['allergy_details'] ?? ''); ?>" maxlength="500" placeholder="Describe the allergy" <?php echo $hasAllergy ? 'required' : 'disabled'; ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save Meal Preferences</button></div>
            </form>
        <?php else: ?>
            <p class="text-muted mb-0">No participants registered yet. Add athletes or officials before updating meal preferences.</p>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.allergy-toggle').forEach(function (toggle) {
        function syncAllergyField() {
            var field = toggle.closest('td').querySelector('.allergy-details');
            field.classList.toggle('d-none', !toggle.checked);
            field.disabled = !toggle.checked;
            field.required = toggle.checked;
            if (!toggle.checked) field.value = '';
        }
        toggle.addEventListener('change', syncAllergyField);
        syncAllergyField();
    });
});
</script>
<?php require_once 'includes/footer.php'; ?>
