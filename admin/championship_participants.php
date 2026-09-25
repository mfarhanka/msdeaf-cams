<?php
require_once 'includes/auth.php';

$championships = $pdo->query(
    "SELECT id, title, start_date, end_date, location
     FROM championships
     ORDER BY start_date DESC, title ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$selectedChampionshipId = isset($_GET['championship_id']) ? (int) $_GET['championship_id'] : 0;
$selectedChampionship = null;

foreach ($championships as $championship) {
    if ((int) $championship['id'] === $selectedChampionshipId) {
        $selectedChampionship = $championship;
        break;
    }
}

if ($selectedChampionship === null && $championships !== []) {
    $selectedChampionship = $championships[0];
    $selectedChampionshipId = (int) $selectedChampionship['id'];
}

$participants = [];
if ($selectedChampionshipId > 0) {
    $participantsStmt = $pdo->prepare(
        "SELECT DISTINCT
            a.id,
            a.first_name,
            a.last_name,
            a.gender,
            a.participant_type,
            u.country_name
         FROM room_assignments ra
         JOIN athletes a ON a.id = ra.athlete_id
         JOIN bookings b ON b.id = ra.booking_id
         JOIN users u ON u.id = a.country_id
         WHERE b.championship_id = ?
           AND b.status <> 'Cancelled'
           AND b.country_id = a.country_id
         ORDER BY u.country_name ASC,
            CASE WHEN a.participant_type = 'athlete' THEN 0 ELSE 1 END ASC,
            a.gender ASC,
            a.last_name ASC,
            a.first_name ASC"
    );
    $participantsStmt->execute([$selectedChampionshipId]);
    $participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$athleteCount = 0;
$officialCount = 0;
$countries = [];
foreach ($participants as $participant) {
    if (($participant['participant_type'] ?? 'athlete') === 'official') {
        $officialCount++;
    } else {
        $athleteCount++;
    }
    $countries[(string) $participant['country_name']] = true;
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Participants by Championship</h1>
        <p class="text-muted mb-0">View athletes and officials assigned to each championship.</p>
    </div>
</div>

<?php if ($championships === []): ?>
    <div class="alert alert-info">No championships have been created yet.</div>
<?php else: ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-8">
                    <label for="championship_id" class="form-label fw-semibold">Championship</label>
                    <select id="championship_id" name="championship_id" class="form-select" required>
                        <?php foreach ($championships as $championship): ?>
                            <option value="<?php echo (int) $championship['id']; ?>" <?php echo (int) $championship['id'] === $selectedChampionshipId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($championship['title'] . ' (' . date('M d, Y', strtotime($championship['start_date'])) . ' - ' . date('M d, Y', strtotime($championship['end_date'])) . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-4">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>View Participants</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card h-100 shadow-sm"><div class="card-body"><div class="text-muted small">Total Participants</div><div class="fs-3 fw-bold"><?php echo count($participants); ?></div></div></div></div>
        <div class="col-md-3"><div class="card h-100 shadow-sm"><div class="card-body"><div class="text-muted small">Athletes</div><div class="fs-3 fw-bold text-success"><?php echo $athleteCount; ?></div></div></div></div>
        <div class="col-md-3"><div class="card h-100 shadow-sm"><div class="card-body"><div class="text-muted small">Officials</div><div class="fs-3 fw-bold text-primary"><?php echo $officialCount; ?></div></div></div></div>
        <div class="col-md-3"><div class="card h-100 shadow-sm"><div class="card-body"><div class="text-muted small">Countries</div><div class="fs-3 fw-bold"><?php echo count($countries); ?></div></div></div></div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold"><?php echo htmlspecialchars($selectedChampionship['title']); ?></div>
                <div class="small text-muted">
                    <?php echo htmlspecialchars(date('M d, Y', strtotime($selectedChampionship['start_date'])) . ' - ' . date('M d, Y', strtotime($selectedChampionship['end_date']))); ?>
                    <?php if (trim((string) $selectedChampionship['location']) !== ''): ?>
                        · <?php echo htmlspecialchars($selectedChampionship['location']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <a class="btn btn-outline-danger btn-sm" href="championship_participants_export.php?championship_id=<?php echo $selectedChampionshipId; ?>">
                <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
            </a>
        </div>
        <div class="card-body">
            <?php if ($participants !== []): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>#</th><th>Participant</th><th>Country</th><th>Type</th><th>Gender</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($participants as $index => $participant): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars(trim($participant['first_name'] . ' ' . $participant['last_name'])); ?></td>
                                    <td><?php echo htmlspecialchars($participant['country_name'] ?: 'Unassigned Country'); ?></td>
                                    <td>
                                        <?php if (($participant['participant_type'] ?? 'athlete') === 'official'): ?>
                                            <span class="badge bg-primary">Official</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Athlete</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($participant['gender']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info mb-0">No participants have been assigned to this championship yet.</div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
