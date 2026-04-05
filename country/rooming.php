<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];

$roomingStmt = $pdo->prepare("SELECT
	b.id AS booking_id,
	c.title AS championship_title,
	c.start_date,
	c.end_date,
	h.name AS hotel_name,
	rt.name AS room_type_name,
	rt.capacity,
	ra.room_number,
	a.first_name,
	a.last_name,
	a.gender
	FROM room_assignments ra
	JOIN bookings b ON b.id = ra.booking_id
	JOIN athletes a ON a.id = ra.athlete_id
	JOIN championships c ON c.id = b.championship_id
	JOIN hotels h ON h.id = b.hotel_id
	JOIN room_types rt ON rt.id = b.room_type_id
	WHERE b.country_id = ?
		AND b.status <> 'Cancelled'
		AND ra.room_number IS NOT NULL
		AND ra.room_number <> ''
	ORDER BY c.start_date ASC, h.name ASC, rt.name ASC, ra.room_number ASC, a.last_name ASC, a.first_name ASC");
$roomingStmt->execute([$countryId]);
$roomingRows = $roomingStmt->fetchAll(PDO::FETCH_ASSOC);

$roomGroups = [];
foreach ($roomingRows as $roomingRow) {
	$groupKey = $roomingRow['booking_id'] . '|' . $roomingRow['room_number'];

	if (!isset($roomGroups[$groupKey])) {
		$roomGroups[$groupKey] = [
			'championship_title' => $roomingRow['championship_title'],
			'start_date' => $roomingRow['start_date'],
			'end_date' => $roomingRow['end_date'],
			'hotel_name' => $roomingRow['hotel_name'],
			'room_type_name' => $roomingRow['room_type_name'],
			'capacity' => (int) $roomingRow['capacity'],
			'room_number' => $roomingRow['room_number'],
			'occupants' => [],
		];
	}

	$roomGroups[$groupKey]['occupants'][] = [
		'name' => trim($roomingRow['first_name'] . ' ' . $roomingRow['last_name']),
		'gender' => $roomingRow['gender'],
	];
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
	<h1 class="h2">Room Grouping</h1>
</div>

<div class="card shadow-sm">
	<div class="card-body">
		<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
			<div>
				<h5 class="card-title mb-1">Assigned Rooms</h5>
				<p class="text-muted small mb-0">Athletes are grouped by their assigned room.</p>
			</div>
		</div>

		<?php if (count($roomGroups) > 0): ?>
			<div class="row g-3">
				<?php foreach ($roomGroups as $roomGroup): ?>
					<div class="col-lg-6">
						<div class="border rounded p-3 h-100">
							<div class="d-flex justify-content-between align-items-start gap-2 mb-2">
								<div>
									<div class="fw-semibold"><?php echo htmlspecialchars($roomGroup['hotel_name']); ?> - <?php echo htmlspecialchars($roomGroup['room_number']); ?></div>
									<div class="small text-muted"><?php echo htmlspecialchars($roomGroup['room_type_name'] . ' (' . $roomGroup['capacity'] . ' pax/room)'); ?></div>
								</div>
								<span class="badge text-bg-primary"><?php echo count($roomGroup['occupants']); ?> / <?php echo $roomGroup['capacity']; ?> Pax</span>
							</div>
							<div class="small text-muted mb-2">
								<?php echo htmlspecialchars($roomGroup['championship_title']); ?>
								<br>
								<?php echo htmlspecialchars(date('M d, Y', strtotime($roomGroup['start_date'])) . ' - ' . date('M d, Y', strtotime($roomGroup['end_date']))); ?>
							</div>
							<ul class="list-group list-group-flush">
								<?php foreach ($roomGroup['occupants'] as $occupant): ?>
									<li class="list-group-item px-0 d-flex justify-content-between align-items-center">
										<span><?php echo htmlspecialchars($occupant['name']); ?></span>
										<span class="text-muted small"><?php echo htmlspecialchars($occupant['gender']); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php else: ?>
			<p class="text-muted text-center py-4 mb-0">No room groupings available yet.</p>
		<?php endif; ?>
	</div>
</div>

<?php
require_once 'includes/footer.php';
?>