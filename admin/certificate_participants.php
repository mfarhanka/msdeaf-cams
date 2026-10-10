<?php
require_once 'includes/auth.php';
require_once '../includes/participant_subtypes.php';
ensureParticipantSubtypeSchema($pdo);

$countries = $pdo->query("SELECT u.id, COALESCE(NULLIF(u.country_name, ''), u.username) AS country_name, COUNT(a.id) AS participant_count FROM users u LEFT JOIN athletes a ON a.country_id = u.id WHERE u.role = 'country_manager' GROUP BY u.id, u.country_name, u.username ORDER BY country_name")->fetchAll(PDO::FETCH_ASSOC);
$selectedCountryId = (int) ($_GET['country_id'] ?? 0);
if ($selectedCountryId <= 0 && $countries !== []) { $selectedCountryId = (int) $countries[0]['id']; }
$selectedCountry = null;
foreach ($countries as $country) { if ((int) $country['id'] === $selectedCountryId) { $selectedCountry = $country; break; } }
$participants = [];
if ($selectedCountry !== null) {
    $stmt = $pdo->prepare("SELECT a.id, a.first_name, a.last_name, a.gender, a.participant_type, ps.name AS participant_subtype_name FROM athletes a LEFT JOIN participant_subtypes ps ON ps.id = a.participant_subtype_id AND ps.country_id = a.country_id WHERE a.country_id = ? ORDER BY CASE WHEN a.participant_type = 'athlete' THEN 0 ELSE 1 END, COALESCE(ps.name, ''), a.last_name, a.first_name");
    $stmt->execute([$selectedCountryId]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
require_once 'includes/header.php';
?>
<style>
@media print {
    .no-print, nav, aside, footer { display: none !important; }
    main, .container-fluid { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    .card { border: 0 !important; box-shadow: none !important; }
    .card-body { padding: 0 !important; }
    .table { font-size: 11pt; }
    .table th, .table td { padding: 7px 8px !important; }
    .certificate-action { display: none !important; }
}
</style>
<div class="d-flex justify-content-between flex-wrap align-items-center gap-2 pb-2 mb-3 border-bottom">
    <div><h1 class="h2 mb-1">Certificate Participants by Country</h1><p class="text-muted mb-0">Complete registered participant list, independent of accommodation and championship bookings.</p></div>
    <div class="d-flex gap-2 no-print"><a class="btn btn-outline-secondary" href="championship_participants.php"><i class="bi bi-arrow-left me-1"></i>Championship List</a><?php if ($selectedCountry !== null): ?><button class="btn btn-outline-primary" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print Name List</button><?php endif; ?></div>
</div>
<?php if ($countries === []): ?>
<div class="alert alert-info">No country delegations have been created.</div>
<?php else: ?>
<form method="get" class="card card-body mb-4 no-print"><div class="row g-3 align-items-end"><div class="col-lg-9"><label class="form-label fw-semibold" for="country_id">Country</label><select class="form-select" id="country_id" name="country_id"><?php foreach ($countries as $country): ?><option value="<?php echo (int) $country['id']; ?>" <?php echo (int) $country['id'] === $selectedCountryId ? 'selected' : ''; ?>><?php echo htmlspecialchars($country['country_name']); ?> (<?php echo (int) $country['participant_count']; ?>)</option><?php endforeach; ?></select></div><div class="col-lg-3 d-grid"><button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>View Country</button></div></div></form>
<?php if ($selectedCountry === null): ?>
<div class="alert alert-warning">The selected country was not found.</div>
<?php else: ?>
<div class="card shadow-sm"><div class="card-header bg-white d-flex justify-content-between align-items-center"><div><div class="fw-bold fs-5"><?php echo htmlspecialchars($selectedCountry['country_name']); ?></div><div class="small text-muted"><?php echo count($participants); ?> registered participants</div></div><span class="badge bg-primary fs-6"><?php echo count($participants); ?></span></div><div class="card-body">
<?php if ($participants !== []): ?>
<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>#</th><th>Name</th><th>Role</th><th>Gender</th><th class="text-end certificate-action">Certificate</th></tr></thead><tbody>
<?php foreach ($participants as $index => $participant): $role = trim((string) ($participant['participant_subtype_name'] ?? '')); if ($role === '') { $role = ($participant['participant_type'] ?? 'athlete') === 'official' ? 'Official' : 'Athlete'; } ?>
<tr><td><?php echo $index + 1; ?></td><td class="fw-semibold"><?php echo htmlspecialchars(trim($participant['first_name'] . ' ' . $participant['last_name'])); ?></td><td><?php echo htmlspecialchars($role); ?></td><td><?php echo htmlspecialchars($participant['gender']); ?></td><td class="text-end certificate-action"><a class="btn btn-sm btn-outline-success" href="participant_certificate.php?id=<?php echo (int) $participant['id']; ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Print Certificate</a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php else: ?><div class="alert alert-info mb-0">No participants are registered for this country.</div><?php endif; ?>
</div></div>
<?php endif; ?>
<?php endif; ?>
<?php require_once 'includes/footer.php'; ?>