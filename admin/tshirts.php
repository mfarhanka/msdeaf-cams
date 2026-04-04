<?php
require_once 'includes/auth.php';

$msg = '';
$sizeOptions = ['XS', 'S', 'M', 'L', 'XL'];
for ($size = 2; $size <= 10; $size++) {
    $sizeOptions[] = $size . 'XL';
}

$tshirtColumnExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'athletes' AND COLUMN_NAME = 'tshirt_size'");
$tshirtColumnExistsStmt->execute();
if (!intval($tshirtColumnExistsStmt->fetchColumn())) {
    $pdo->exec("ALTER TABLE athletes ADD COLUMN tshirt_size VARCHAR(10) NULL AFTER gender");
}

$summaryStmt = $pdo->query("SELECT
    u.id,
    u.country_name,
    COUNT(a.id) AS athlete_count,
    SUM(CASE WHEN a.tshirt_size IS NOT NULL AND a.tshirt_size <> '' THEN 1 ELSE 0 END) AS sized_count,
    SUM(CASE WHEN a.tshirt_size IS NULL OR a.tshirt_size = '' THEN 1 ELSE 0 END) AS pending_count
    FROM users u
    LEFT JOIN athletes a ON a.country_id = u.id
    WHERE u.role = 'country_manager'
    GROUP BY u.id, u.country_name
    ORDER BY u.country_name ASC");
$delegationSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

$sizeTotals = array_fill_keys($sizeOptions, 0);
$sizeTotalsStmt = $pdo->query("SELECT tshirt_size, COUNT(*) AS total FROM athletes WHERE tshirt_size IS NOT NULL AND tshirt_size <> '' GROUP BY tshirt_size");
foreach ($sizeTotalsStmt->fetchAll(PDO::FETCH_ASSOC) as $sizeRow) {
    $sizeKey = $sizeRow['tshirt_size'];
    if (array_key_exists($sizeKey, $sizeTotals)) {
        $sizeTotals[$sizeKey] = intval($sizeRow['total']);
    }
}

$pendingSizesStmt = $pdo->query("SELECT COUNT(*) FROM athletes WHERE tshirt_size IS NULL OR tshirt_size = ''");
$pendingSizes = intval($pendingSizesStmt->fetchColumn());

$athletesStmt = $pdo->query("SELECT
    u.country_name,
    a.first_name,
    a.last_name,
    a.gender,
    a.tshirt_size
    FROM athletes a
    JOIN users u ON u.id = a.country_id
    WHERE u.role = 'country_manager'
    ORDER BY u.country_name ASC, a.last_name ASC, a.first_name ASC");
$athletes = $athletesStmt->fetchAll(PDO::FETCH_ASSOC);

$athletesByCountry = [];
foreach ($athletes as $athlete) {
    $countryName = $athlete['country_name'] ?: 'Unassigned Country';
    if (!isset($athletesByCountry[$countryName])) {
        $athletesByCountry[$countryName] = [];
    }
    $athletesByCountry[$countryName][] = $athlete;
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">T-Shirt Size Overview</h1>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="card-title mb-1">Size Totals by Category</h5>
                <p class="text-muted small mb-0">Ordered summary of all submitted T-shirt sizes across delegations.</p>
            </div>
            <span class="badge bg-warning text-dark px-3 py-2">Pending: <?php echo $pendingSizes; ?></span>
        </div>

        <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-xl-5 g-2">
            <?php foreach ($sizeOptions as $sizeOption): ?>
                <div class="col">
                    <div class="border rounded p-3 h-100 bg-light">
                        <div class="small text-muted mb-1">Size</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($sizeOption); ?></div>
                        <div class="fs-5 fw-bold text-primary"><?php echo $sizeTotals[$sizeOption]; ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <?php if (count($delegationSummary) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Country</th>
                            <th>Total Athletes</th>
                            <th>Sizes Submitted</th>
                            <th>Pending</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($delegationSummary as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($row['country_name']); ?></td>
                                <td><?php echo intval($row['athlete_count']); ?></td>
                                <td><?php echo intval($row['sized_count']); ?></td>
                                <td><?php echo intval($row['pending_count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No delegations or athletes found yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php if (count($athletesByCountry) > 0): ?>
    <?php foreach ($athletesByCountry as $countryName => $countryAthletes): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><?php echo htmlspecialchars($countryName); ?></span>
                <span class="text-muted small"><?php echo count($countryAthletes); ?> athlete(s)</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Gender</th>
                                <th>T-Shirt Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($countryAthletes as $athlete): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($athlete['first_name'] . ' ' . $athlete['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($athlete['gender']); ?></td>
                                    <td>
                                        <?php if (!empty($athlete['tshirt_size'])): ?>
                                            <span class="badge bg-primary-subtle text-primary border"><?php echo htmlspecialchars($athlete['tshirt_size']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <p class="text-muted mb-0">No athlete T-shirt data available yet.</p>
        </div>
    </div>
<?php endif; ?>

<?php
require_once 'includes/footer.php';
?>