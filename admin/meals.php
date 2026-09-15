<?php
require_once 'includes/auth.php';

foreach ([
    'meal_preference' => "ALTER TABLE athletes ADD COLUMN meal_preference ENUM('vegetarian', 'non_vegetarian') NULL AFTER tshirt_size",
    'allergy_details' => "ALTER TABLE athletes ADD COLUMN allergy_details VARCHAR(500) NULL AFTER meal_preference",
] as $column => $alterSql) {
    $columnStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'athletes' AND COLUMN_NAME = ?");
    $columnStmt->execute([$column]);
    if (!(int) $columnStmt->fetchColumn()) $pdo->exec($alterSql);
}

$totals = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(meal_preference = 'vegetarian') AS vegetarian_count,
    SUM(meal_preference = 'non_vegetarian') AS non_vegetarian_count,
    SUM(meal_preference IS NULL OR meal_preference = '') AS pending_count,
    SUM(allergy_details IS NOT NULL AND TRIM(allergy_details) <> '') AS allergy_count
    FROM athletes")->fetch(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->query("SELECT u.country_name, COUNT(a.id) AS participant_count,
    SUM(a.meal_preference IS NOT NULL AND a.meal_preference <> '') AS submitted_count,
    SUM(a.id IS NOT NULL AND a.meal_preference IS NULL) AS pending_count,
    SUM(a.id IS NOT NULL AND a.allergy_details IS NOT NULL AND TRIM(a.allergy_details) <> '') AS allergy_count
    FROM users u LEFT JOIN athletes a ON a.country_id = u.id
    WHERE u.role = 'country_manager' GROUP BY u.id, u.country_name ORDER BY u.country_name");
$delegationSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

$participantsStmt = $pdo->query("SELECT u.country_name, a.first_name, a.last_name, a.gender, a.participant_type, a.meal_preference, a.allergy_details
    FROM athletes a JOIN users u ON u.id = a.country_id WHERE u.role = 'country_manager'
    ORDER BY u.country_name, a.last_name, a.first_name");
$participantsByCountry = [];
foreach ($participantsStmt->fetchAll(PDO::FETCH_ASSOC) as $participant) {
    $participantsByCountry[$participant['country_name'] ?: 'Unassigned Country'][] = $participant;
}

require_once 'includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap align-items-center pb-2 mb-3 border-bottom">
    <div><h1 class="h2 mb-1">Meal Preferences</h1><p class="text-muted mb-0">Read-only catering overview for all delegations.</p></div>
</div>

<div class="row row-cols-2 row-cols-lg-4 g-2 mb-4">
    <?php foreach ([
        ['Vegetarian', 'vegetarian_count', 'success'],
        ['Non-vegetarian', 'non_vegetarian_count', 'primary'],
        ['Food Allergies', 'allergy_count', 'danger'],
        ['Pending', 'pending_count', 'warning'],
    ] as [$label, $key, $color]): ?>
        <div class="col"><div class="card h-100 border-<?php echo $color; ?>"><div class="card-body"><div class="text-muted small"><?php echo $label; ?></div><div class="fs-3 fw-semibold"><?php echo (int) ($totals[$key] ?? 0); ?></div></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm mb-3"><div class="card-body">
    <h5 class="card-title">Completion by Country</h5>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Country</th><th>Participants</th><th>Submitted</th><th>Pending</th><th>With Allergies</th></tr></thead><tbody>
    <?php foreach ($delegationSummary as $row): ?><tr><td class="fw-semibold"><?php echo htmlspecialchars($row['country_name'] ?: 'Unassigned Country'); ?></td><td><?php echo (int) $row['participant_count']; ?></td><td><span class="badge text-bg-success"><?php echo (int) $row['submitted_count']; ?></span></td><td><span class="badge text-bg-warning text-dark"><?php echo (int) $row['pending_count']; ?></span></td><td><span class="badge text-bg-danger"><?php echo (int) $row['allergy_count']; ?></span></td></tr><?php endforeach; ?>
    <?php if (!$delegationSummary): ?><tr><td colspan="5" class="text-center text-muted">No delegations found.</td></tr><?php endif; ?>
    </tbody></table></div>
</div></div>

<?php if ($participantsByCountry): ?>
<div class="accordion" id="countryMealAccordion">
<?php $countryIndex = 0; foreach ($participantsByCountry as $countryName => $participants): $collapseId = 'countryMeal' . $countryIndex; ?>
    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button<?php echo $countryIndex === 0 ? '' : ' collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>"><span class="fw-semibold me-2"><?php echo htmlspecialchars($countryName); ?></span><span class="badge text-bg-light border text-dark"><?php echo count($participants); ?> participant(s)</span></button></h2>
        <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse<?php echo $countryIndex === 0 ? ' show' : ''; ?>" data-bs-parent="#countryMealAccordion"><div class="accordion-body">
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Participant</th><th>Type</th><th>Gender</th><th>Meal Preference</th><th>Food Allergies</th></tr></thead><tbody>
            <?php foreach ($participants as $participant): ?><tr>
                <td class="fw-semibold"><?php echo htmlspecialchars($participant['first_name'] . ' ' . $participant['last_name']); ?></td>
                <td><?php echo htmlspecialchars(ucfirst($participant['participant_type'] ?? 'athlete')); ?></td>
                <td><?php echo htmlspecialchars($participant['gender']); ?></td>
                <td><?php if ($participant['meal_preference']): ?><span class="badge text-bg-<?php echo $participant['meal_preference'] === 'vegetarian' ? 'success' : 'primary'; ?>"><?php echo $participant['meal_preference'] === 'vegetarian' ? 'Vegetarian' : 'Non-vegetarian'; ?></span><?php else: ?><span class="text-muted">Not set</span><?php endif; ?></td>
                <td><?php if (trim((string) ($participant['allergy_details'] ?? '')) !== ''): ?><span class="text-danger fw-semibold"><?php echo htmlspecialchars($participant['allergy_details']); ?></span><?php else: ?><span class="text-muted">None reported</span><?php endif; ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
        </div></div>
    </div>
<?php $countryIndex++; endforeach; ?>
</div>
<?php else: ?><div class="card shadow-sm"><div class="card-body text-muted">No participant meal data available yet.</div></div><?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
