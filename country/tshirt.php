<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];
$msg = '';
$sizeOptions = ['XS', 'S', 'M', 'L', 'XL'];
for ($size = 2; $size <= 10; $size++) {
    $sizeOptions[] = $size . 'XL';
}

$columnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'athletes' AND COLUMN_NAME = 'tshirt_size'");
$columnExistsStmt->execute();
if (!intval($columnExistsStmt->fetchColumn())) {
    $pdo->exec("ALTER TABLE athletes ADD COLUMN tshirt_size VARCHAR(10) NULL AFTER gender");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_tshirt_sizes') {
    $sizes = $_POST['sizes'] ?? [];
    $updateStmt = $pdo->prepare("UPDATE athletes SET tshirt_size = ? WHERE id = ? AND country_id = ?");

    foreach ($sizes as $athleteId => $size) {
        $normalizedSize = trim((string) $size);
        if ($normalizedSize !== '' && !in_array($normalizedSize, $sizeOptions, true)) {
            continue;
        }
        $updateStmt->execute([$normalizedSize !== '' ? $normalizedSize : null, intval($athleteId), $countryId]);
    }

    $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-check-circle me-1'></i>T-shirt sizes updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
}

$athletesStmt = $pdo->prepare("SELECT id, first_name, last_name, gender, tshirt_size FROM athletes WHERE country_id = ? ORDER BY last_name ASC, first_name ASC");
$athletesStmt->execute([$countryId]);
$athletes = $athletesStmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">T-Shirt Sizes</h1>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-1">Update Athlete Sizes</h5>
                        <p class="text-muted small mb-0">Select the required T-shirt size for each athlete, then save the full list.</p>
                    </div>
                </div>

                <?php if (count($athletes) > 0): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_tshirt_sizes">

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Gender</th>
                                        <th style="width: 220px;">T-Shirt Size</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($athletes as $athlete): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($athlete['first_name'] . ' ' . $athlete['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($athlete['gender']); ?></td>
                                            <td>
                                                <select name="sizes[<?php echo $athlete['id']; ?>]" class="form-select form-select-sm">
                                                    <option value="">-- Not Set --</option>
                                                    <?php foreach ($sizeOptions as $sizeOption): ?>
                                                        <option value="<?php echo htmlspecialchars($sizeOption); ?>" <?php echo $athlete['tshirt_size'] === $sizeOption ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($sizeOption); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i> Save T-Shirt Sizes
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">No athletes registered yet. Add athletes first before updating T-shirt sizes.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-3">T-Shirt Chart</h5>
                <img src="../tshirt-chart.png" alt="T-shirt size chart" class="img-fluid rounded border">
                <p class="text-muted small mt-3 mb-0">Use this chart when selecting sizes from <strong>XS</strong> to <strong>10XL</strong>.</p>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>