<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../includes/flights.php';

if (!isset($flightScheduleDirection) || !in_array($flightScheduleDirection, ['arrival', 'departure'], true)) {
    http_response_code(400);
    exit('A valid flight direction is required.');
}

ensureDelegationFlightsTable($pdo);
$directionLabel = $flightScheduleDirection === 'arrival' ? 'Arrivals' : 'Departures';
$directionSingular = ucfirst($flightScheduleDirection);
$stmt = $pdo->prepare("SELECT u.country_name, u.username, f.*,
    (SELECT GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) ORDER BY a.last_name, a.first_name SEPARATOR ', ')
     FROM delegation_flight_movement_members fm
     JOIN athletes a ON a.id = fm.athlete_id
     WHERE fm.movement_id = f.id) AS delegate_names
    FROM delegation_flight_movements f
    JOIN users u ON u.id = f.country_id
    WHERE u.role = 'country_manager' AND f.direction = ?
    ORDER BY f.airport IS NULL, FIELD(f.airport, 'BAYAN_LEPAS', 'KLIA', 'KLIA2'), f.flight_datetime, u.country_name, f.id");
$stmt->execute([$flightScheduleDirection]);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
$airportGroups = [];
foreach ($movements as $movement) {
    $airport = trim((string) ($movement['airport'] ?? ''));
    $airportKey = $airport !== '' ? $airport : 'NOT_ENTERED';
    if (!isset($airportGroups[$airportKey])) {
        $airportGroups[$airportKey] = ['label' => flightAirportLabel($airport ?: null), 'movements' => [], 'pax' => 0];
    }
    $airportGroups[$airportKey]['movements'][] = $movement;
    $airportGroups[$airportKey]['pax'] += (int) $movement['pax'];
}
$totalPax = array_sum(array_column($movements, 'pax'));
require __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between flex-wrap align-items-center gap-2 pb-2 mb-3 border-bottom">
    <div><h1 class="h2 mb-1"><?php echo $directionLabel; ?></h1><p class="text-muted mb-0">Grouped by airport and sorted by flight date and time.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="flights.php"><i class="bi bi-list-ul me-1"></i>All Flight Details</a><a class="btn btn-outline-danger" href="flight_details_export.php?direction=<?php echo $flightScheduleDirection; ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Export <?php echo $directionSingular; ?> PDF</a></div>
</div>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-muted small text-uppercase fw-bold">Flight Groups</div><div class="display-6"><?php echo count($movements); ?></div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-muted small text-uppercase fw-bold">Total Pax</div><div class="display-6 text-primary"><?php echo (int) $totalPax; ?></div></div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-muted small text-uppercase fw-bold">Airports</div><div class="display-6 text-success"><?php echo count($airportGroups); ?></div></div></div></div>
</div>
<?php foreach ($airportGroups as $airport): ?>
<section class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center gap-2"><span class="fw-semibold"><i class="bi bi-geo-alt-fill me-2"></i><?php echo htmlspecialchars($airport['label']); ?></span><span><span class="badge text-bg-secondary"><?php echo count($airport['movements']); ?> group<?php echo count($airport['movements']) === 1 ? '' : 's'; ?></span> <span class="badge text-bg-primary"><?php echo (int) $airport['pax']; ?> pax</span></span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Date</th><th>Time</th><th>Delegation</th><th>Flight</th><th>Delegates</th><th>Pax</th><th>Bus Transfer</th></tr></thead><tbody>
    <?php foreach ($airport['movements'] as $movement): $busCount=(int)($movement['bus_count']??(!empty($movement['bus_to_penang'])?1:0)); ?>
    <tr><td class="text-nowrap fw-semibold"><?php echo date('d M Y', strtotime($movement['flight_datetime'])); ?></td><td class="text-nowrap"><?php echo date('H:i', strtotime($movement['flight_datetime'])); ?></td><td><?php echo htmlspecialchars($movement['country_name'] ?: $movement['username']); ?></td><td class="fw-semibold"><?php echo htmlspecialchars($movement['flight_number']); ?></td><td><?php echo htmlspecialchars($movement['delegate_names'] ?: 'Not assigned'); ?></td><td><span class="badge text-bg-primary"><?php echo (int) $movement['pax']; ?></span></td><td><?php if($busCount>0): ?><span class="badge text-bg-warning"><?php echo $busCount; ?> bus<?php echo $busCount===1?'':'es'; ?> · USD <?php echo number_format($busCount*1000); ?></span><?php else: ?><span class="text-muted">No</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endforeach; ?>
<?php if (!$airportGroups): ?><div class="card"><div class="card-body py-5 text-center text-muted"><i class="bi bi-airplane fs-2 d-block mb-2"></i>No <?php echo strtolower($directionLabel); ?> have been submitted.</div></div><?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>