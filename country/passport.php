<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];
$msg = '';

$passportColumnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'athletes' AND COLUMN_NAME = 'passport_number'");
$passportColumnExistsStmt->execute();
if (!intval($passportColumnExistsStmt->fetchColumn())) {
    $pdo->exec("ALTER TABLE athletes ADD COLUMN passport_number VARCHAR(255) NOT NULL DEFAULT '' AFTER sport_category");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_passport_numbers') {
    $passportNumbers = $_POST['passport_numbers'] ?? [];
    $updateStmt = $pdo->prepare("UPDATE athletes SET passport_number = ? WHERE id = ? AND country_id = ?");

    foreach ($passportNumbers as $athleteId => $passportNumber) {
        $normalizedPassport = trim((string) $passportNumber);
        $updateStmt->execute([$normalizedPassport, intval($athleteId), $countryId]);
    }

    $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-check-circle me-1'></i>Passport details updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
}

$athletesStmt = $pdo->prepare("SELECT id, first_name, last_name, gender, passport_number FROM athletes WHERE country_id = ? ORDER BY last_name ASC, first_name ASC");
$athletesStmt->execute([$countryId]);
$athletes = $athletesStmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Passport Details</h1>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-1">Update Passport Numbers</h5>
                        <p class="text-muted small mb-0">Enter or revise the passport number for each athlete, then save the full list.</p>
                    </div>
                </div>

                <?php if (count($athletes) > 0): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_passport_numbers">

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Gender</th>
                                        <th style="width: 280px;">Passport Number</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($athletes as $athlete): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($athlete['first_name'] . ' ' . $athlete['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($athlete['gender']); ?></td>
                                            <td>
                                                <input
                                                    type="text"
                                                    name="passport_numbers[<?php echo $athlete['id']; ?>]"
                                                    class="form-control form-control-sm"
                                                    value="<?php echo htmlspecialchars($athlete['passport_number'] ?? ''); ?>"
                                                    placeholder="Enter passport number"
                                                    maxlength="255"
                                                >
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i> Save Passport Details
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">No athletes registered yet. Add athletes first before updating passport details.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-2">Guidance</h5>
                <p class="text-muted small mb-2">Keep passport numbers exactly as shown on the travel document to avoid hotel check-in issues.</p>
                <p class="text-muted small mb-0">You can leave a field blank temporarily and update the list again later.</p>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>