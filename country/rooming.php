<?php
require_once 'includes/auth.php';

$countryId = $_SESSION['id'];
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
	if ($_POST['action'] === 'assign_room') {
		$athleteId = (int) ($_POST['athlete_id'] ?? 0);
		$bookingId = (int) ($_POST['booking_id'] ?? 0);
		$roomGrouping = trim((string) ($_POST['room_grouping'] ?? ''));

		if ($athleteId <= 0 || $bookingId <= 0 || $roomGrouping === '') {
			$msg = "<div class='alert alert-warning'>Please select an athlete, reservation, and room grouping.</div>";
		} else {
			$athleteStmt = $pdo->prepare("SELECT id FROM athletes WHERE id = ? AND country_id = ?");
			$athleteStmt->execute([$athleteId, $countryId]);
			$athlete = $athleteStmt->fetch(PDO::FETCH_ASSOC);

			$bookingStmt = $pdo->prepare("SELECT b.id, b.rooms_reserved, rt.capacity, c.title AS championship_title, h.name AS hotel_name, rt.name AS room_type_name
				FROM bookings b
				JOIN room_types rt ON rt.id = b.room_type_id
				JOIN championships c ON c.id = b.championship_id
				JOIN hotels h ON h.id = b.hotel_id
				WHERE b.id = ? AND b.country_id = ? AND b.status <> 'Cancelled'
				LIMIT 1");
			$bookingStmt->execute([$bookingId, $countryId]);
			$booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

			if (!$athlete) {
				$msg = "<div class='alert alert-danger'>Invalid athlete selection.</div>";
			} elseif (!$booking) {
				$msg = "<div class='alert alert-danger'>Invalid reservation selection.</div>";
			} else {
				$capacity = max(1, (int) $booking['capacity']);
				$reservedRooms = max(0, (int) $booking['rooms_reserved']);
				$capacityLimit = $reservedRooms * $capacity;

				$existingAssignmentStmt = $pdo->prepare("SELECT booking_id, room_number FROM room_assignments WHERE athlete_id = ? LIMIT 1");
				$existingAssignmentStmt->execute([$athleteId]);
				$existingAssignment = $existingAssignmentStmt->fetch(PDO::FETCH_ASSOC);

				$assignedCountStmt = $pdo->prepare("SELECT COUNT(*) FROM room_assignments WHERE booking_id = ?");
				$assignedCountStmt->execute([$bookingId]);
				$assignedCount = (int) $assignedCountStmt->fetchColumn();

				if ((!$existingAssignment || (int) $existingAssignment['booking_id'] !== $bookingId) && $assignedCount >= $capacityLimit) {
					$msg = "<div class='alert alert-warning'>This reservation is already at full athlete capacity.</div>";
				} else {
					$occupancyStmt = $pdo->prepare("SELECT room_number, COUNT(*) AS occupants FROM room_assignments WHERE booking_id = ? GROUP BY room_number");
					$occupancyStmt->execute([$bookingId]);
					$roomOccupancy = [];
					foreach ($occupancyStmt->fetchAll(PDO::FETCH_ASSOC) as $occupancyRow) {
						$roomOccupancy[$occupancyRow['room_number']] = (int) $occupancyRow['occupants'];
					}

					$currentRoomNumber = $existingAssignment && (int) $existingAssignment['booking_id'] === $bookingId
						? trim((string) $existingAssignment['room_number'])
						: '';
					$targetRoomNumber = '';

					if ($roomGrouping === '__new__') {
						for ($index = 1; $index <= $reservedRooms; $index++) {
							$candidate = 'Room ' . $index;
							if (!isset($roomOccupancy[$candidate]) || $roomOccupancy[$candidate] === 0) {
								$targetRoomNumber = $candidate;
								break;
							}
						}

						if ($targetRoomNumber === '') {
							$msg = "<div class='alert alert-warning'>No empty reserved room group is available. Choose an existing room group instead.</div>";
						}
					} else {
						$selectedOccupants = $roomOccupancy[$roomGrouping] ?? null;
						$isCurrentRoomSelection = $currentRoomNumber !== '' && $currentRoomNumber === $roomGrouping;

						if ($selectedOccupants === null) {
							$msg = "<div class='alert alert-warning'>The selected room group is no longer available.</div>";
						} elseif ($selectedOccupants >= $capacity && !$isCurrentRoomSelection) {
							$msg = "<div class='alert alert-warning'>The selected room group is already full.</div>";
						} else {
							$targetRoomNumber = $roomGrouping;
						}
					}

					if ($targetRoomNumber !== '') {
						if ($existingAssignment) {
							$updateStmt = $pdo->prepare("UPDATE room_assignments SET booking_id = ?, room_number = ? WHERE athlete_id = ?");
							$updateStmt->execute([$bookingId, $targetRoomNumber, $athleteId]);
						} else {
							$insertStmt = $pdo->prepare("INSERT INTO room_assignments (booking_id, room_number, athlete_id) VALUES (?, ?, ?)");
							$insertStmt->execute([$bookingId, $targetRoomNumber, $athleteId]);
						}

						$msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-check-circle me-1'></i>Athlete assigned to room group successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
					}
				}
			}
		}
	} elseif ($_POST['action'] === 'unassign_room') {
		$athleteId = (int) ($_POST['athlete_id'] ?? 0);
		if ($athleteId <= 0) {
			$msg = "<div class='alert alert-warning'>Please select an athlete to unassign.</div>";
		} else {
			$deleteStmt = $pdo->prepare("DELETE ra FROM room_assignments ra JOIN athletes a ON a.id = ra.athlete_id WHERE ra.athlete_id = ? AND a.country_id = ?");
			$deleteStmt->execute([$athleteId, $countryId]);
			$msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-x-circle me-1'></i>Room assignment removed successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
		}
	}
}

$athletesStmt = $pdo->prepare("SELECT a.id, a.first_name, a.last_name, a.gender,
	ra.room_number,
	b.id AS booking_id,
	c.title AS championship_title,
	h.name AS hotel_name,
	rt.name AS room_type_name
	FROM athletes a
	LEFT JOIN room_assignments ra ON ra.athlete_id = a.id
	LEFT JOIN bookings b ON b.id = ra.booking_id
	LEFT JOIN championships c ON c.id = b.championship_id
	LEFT JOIN hotels h ON h.id = b.hotel_id
	LEFT JOIN room_types rt ON rt.id = b.room_type_id
	WHERE a.country_id = ?
	ORDER BY a.last_name ASC, a.first_name ASC");
$athletesStmt->execute([$countryId]);
$athletes = $athletesStmt->fetchAll(PDO::FETCH_ASSOC);

$reservationsStmt = $pdo->prepare("SELECT b.id, b.rooms_reserved, c.title AS championship_title, c.start_date, c.end_date,
	h.name AS hotel_name, rt.name AS room_type_name, rt.capacity, rt.price_per_night,
	(SELECT COUNT(*) FROM room_assignments ra WHERE ra.booking_id = b.id) AS assigned_athletes,
	(SELECT COUNT(DISTINCT ra.room_number) FROM room_assignments ra WHERE ra.booking_id = b.id AND ra.room_number IS NOT NULL AND ra.room_number <> '') AS used_room_groups
	FROM bookings b
	JOIN championships c ON c.id = b.championship_id
	JOIN hotels h ON h.id = b.hotel_id
	JOIN room_types rt ON rt.id = b.room_type_id
	WHERE b.country_id = ? AND b.status <> 'Cancelled' AND b.rooms_reserved > 0
	ORDER BY c.start_date ASC, h.name ASC, rt.name ASC");
$reservationsStmt->execute([$countryId]);
$reservations = $reservationsStmt->fetchAll(PDO::FETCH_ASSOC);

$roomingRowsStmt = $pdo->prepare("SELECT
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
$roomingRowsStmt->execute([$countryId]);
$roomingRows = $roomingRowsStmt->fetchAll(PDO::FETCH_ASSOC);

$roomGroups = [];
$roomGroupPreview = [];
foreach ($roomingRows as $roomingRow) {
	$groupKey = $roomingRow['booking_id'] . '|' . $roomingRow['room_number'];
	if (!isset($roomGroups[$groupKey])) {
		$roomGroups[$groupKey] = [
			'booking_id' => (int) $roomingRow['booking_id'],
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

	$occupant = [
		'name' => trim($roomingRow['first_name'] . ' ' . $roomingRow['last_name']),
		'gender' => $roomingRow['gender'],
	];
	$roomGroups[$groupKey]['occupants'][] = $occupant;

	$bookingIdKey = (string) $roomingRow['booking_id'];
	if (!isset($roomGroupPreview[$bookingIdKey])) {
		$roomGroupPreview[$bookingIdKey] = [];
	}
	if (!isset($roomGroupPreview[$bookingIdKey][$roomingRow['room_number']])) {
		$roomGroupPreview[$bookingIdKey][$roomingRow['room_number']] = [
			'room_number' => $roomingRow['room_number'],
			'occupants' => [],
		];
	}
	$roomGroupPreview[$bookingIdKey][$roomingRow['room_number']]['occupants'][] = $occupant;
}

foreach ($roomGroupPreview as $bookingIdKey => $roomGroupsForBooking) {
	$roomGroupPreview[$bookingIdKey] = array_values($roomGroupsForBooking);
}

$reservationOptions = [];
foreach ($reservations as $reservation) {
	$capacity = max(1, (int) $reservation['capacity']);
	$totalCapacity = (int) $reservation['rooms_reserved'] * $capacity;
	$reservationOptions[] = [
		'id' => (int) $reservation['id'],
		'championship_title' => $reservation['championship_title'],
		'hotel_name' => $reservation['hotel_name'],
		'room_type_name' => $reservation['room_type_name'],
		'capacity' => $capacity,
		'rooms_reserved' => (int) $reservation['rooms_reserved'],
		'assigned_athletes' => (int) $reservation['assigned_athletes'],
		'used_room_groups' => (int) $reservation['used_room_groups'],
		'remaining_slots' => max(0, $totalCapacity - (int) $reservation['assigned_athletes']),
		'empty_room_groups' => max(0, (int) $reservation['rooms_reserved'] - (int) $reservation['used_room_groups']),
		'price_per_night' => (float) $reservation['price_per_night'],
	];
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
	<div>
		<h1 class="h2 mb-1">Room Grouping</h1>
		<p class="text-muted mb-0">Assign athletes into the room groups covered by your existing reservations.</p>
	</div>
	<a href="book.php" class="btn btn-outline-primary btn-sm">
		<i class="bi bi-calendar-check me-1"></i> Manage Reservations
	</a>
</div>

<div class="row g-3 mb-3">
	<div class="col-lg-8">
		<div class="card shadow-sm">
			<div class="card-body">
				<h5 class="card-title mb-3">Athlete Assignment</h5>
				<?php if (count($athletes) > 0): ?>
					<div class="table-responsive">
						<table class="table table-hover align-middle mb-0">
							<thead class="table-light">
								<tr>
									<th>Name</th>
									<th>Gender</th>
									<th>Current Assignment</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($athletes as $athlete): ?>
									<tr>
										<td><?php echo htmlspecialchars($athlete['first_name'] . ' ' . $athlete['last_name']); ?></td>
										<td><?php echo htmlspecialchars($athlete['gender']); ?></td>
										<td>
											<?php if (!empty($athlete['room_number'])): ?>
												<div class="fw-semibold"><?php echo htmlspecialchars($athlete['room_number']); ?></div>
												<div class="small text-muted"><?php echo htmlspecialchars(($athlete['championship_title'] ?? '') . ' / ' . ($athlete['hotel_name'] ?? '') . ' / ' . ($athlete['room_type_name'] ?? '')); ?></div>
											<?php else: ?>
												<span class="badge bg-warning text-dark">Unassigned</span>
											<?php endif; ?>
										</td>
										<td>
											<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignModal<?php echo (int) $athlete['id']; ?>">
												<i class="bi bi-door-open"></i> Assign Group
											</button>
											<?php if (!empty($athlete['room_number'])): ?>
												<form method="POST" class="d-inline" onsubmit="return confirm('Unassign this athlete from the room group?');">
													<input type="hidden" name="action" value="unassign_room">
													<input type="hidden" name="athlete_id" value="<?php echo (int) $athlete['id']; ?>">
													<button type="submit" class="btn btn-sm btn-outline-danger ms-1"><i class="bi bi-x-lg"></i></button>
												</form>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else: ?>
					<p class="text-muted mb-0">No athletes registered yet. Add athletes before creating room group assignments.</p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="col-lg-4">
		<div class="card shadow-sm">
			<div class="card-body">
				<h5 class="card-title mb-1">Reserved Capacity</h5>
				<p class="text-muted small mb-3">Only reserved room groups can be used for athlete assignments.</p>
				<?php if (count($reservationOptions) > 0): ?>
					<?php foreach ($reservationOptions as $reservationOption): ?>
						<div class="mb-3 p-3 border rounded">
							<div class="fw-semibold"><?php echo htmlspecialchars($reservationOption['championship_title']); ?></div>
							<div class="small text-muted mb-1"><?php echo htmlspecialchars($reservationOption['hotel_name'] . ' / ' . $reservationOption['room_type_name']); ?></div>
							<div class="small text-muted">Reserved rooms: <?php echo $reservationOption['rooms_reserved']; ?></div>
							<div class="small text-muted">Assigned athletes: <?php echo $reservationOption['assigned_athletes']; ?></div>
							<div class="small text-muted">Empty room groups: <?php echo $reservationOption['empty_room_groups']; ?></div>
							<div class="small text-muted">Remaining slots: <?php echo $reservationOption['remaining_slots']; ?></div>
						</div>
					<?php endforeach; ?>
				<?php else: ?>
					<p class="text-muted mb-0">No reserved rooms found. Reserve rooms first on the booking page.</p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<div class="card shadow-sm">
	<div class="card-body">
		<h5 class="card-title mb-3">Current Room Groups</h5>
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
			<p class="text-muted text-center py-4 mb-0">No room groupings available yet. Reserve rooms first, then assign athletes here.</p>
		<?php endif; ?>
	</div>
</div>

<?php foreach ($athletes as $athlete): ?>
	<div class="modal fade" id="assignModal<?php echo (int) $athlete['id']; ?>" tabindex="-1">
		<div class="modal-dialog modal-lg">
			<div class="modal-content">
				<form method="POST" class="assign-room-form" data-current-booking-id="<?php echo htmlspecialchars((string) ($athlete['booking_id'] ?? '')); ?>" data-current-room-number="<?php echo htmlspecialchars((string) ($athlete['room_number'] ?? '')); ?>">
					<div class="modal-header bg-primary text-white">
						<h5 class="modal-title">Assign Room Group - <?php echo htmlspecialchars($athlete['first_name'] . ' ' . $athlete['last_name']); ?></h5>
						<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
					</div>
					<div class="modal-body">
						<input type="hidden" name="action" value="assign_room">
						<input type="hidden" name="athlete_id" value="<?php echo (int) $athlete['id']; ?>">

						<div class="mb-3">
							<label class="form-label">Select Reservation</label>
							<select name="booking_id" class="form-select js-booking-select" required>
								<option value="">-- Choose Reserved Booking --</option>
							</select>
						</div>

						<div class="mb-3">
							<label class="form-label">Select Room Grouping</label>
							<select name="room_grouping" class="form-select js-room-grouping-select" required>
								<option value="">-- Choose Room Grouping --</option>
							</select>
						</div>

						<div class="border rounded p-3 bg-light-subtle mb-3 js-booking-summary text-muted small">
							Select a reservation to review room group availability.
						</div>

						<div class="border rounded p-3 js-room-group-preview">
							<div class="fw-semibold mb-2">Current Room Groups</div>
							<p class="text-muted small mb-0">Select a reservation to preview existing room groups.</p>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
						<button type="submit" class="btn btn-primary">Save Assignment</button>
					</div>
				</form>
			</div>
		</div>
	</div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
	var reservationOptions = <?php echo json_encode($reservationOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
	var roomGroupPreview = <?php echo json_encode($roomGroupPreview, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

	function formatMoney(value) {
		return '$' + Number(value).toFixed(2);
	}

	function renderBookingOptions(form) {
		var bookingSelect = form.querySelector('.js-booking-select');
		var currentBookingId = form.getAttribute('data-current-booking-id') || '';
		bookingSelect.innerHTML = '<option value="">-- Choose Reserved Booking --</option>';

		reservationOptions.forEach(function (reservation) {
			var option = document.createElement('option');
			option.value = String(reservation.id);
			option.textContent = reservation.championship_title + ' / ' + reservation.hotel_name + ' / ' + reservation.room_type_name + ' - ' + reservation.remaining_slots + ' slot(s) left';
			if (currentBookingId && currentBookingId === String(reservation.id)) {
				option.selected = true;
			}
			bookingSelect.appendChild(option);
		});

		bookingSelect.disabled = bookingSelect.options.length === 1;
	}

	function renderRoomGroupingOptions(form) {
		var bookingSelect = form.querySelector('.js-booking-select');
		var roomGroupingSelect = form.querySelector('.js-room-grouping-select');
		var currentRoomNumber = form.getAttribute('data-current-room-number') || '';
		var groups = roomGroupPreview[String(bookingSelect.value)] || [];
		var selectedReservation = null;

		reservationOptions.forEach(function (reservation) {
			if (String(reservation.id) === String(bookingSelect.value)) {
				selectedReservation = reservation;
			}
		});

		roomGroupingSelect.innerHTML = '<option value="">-- Choose Room Grouping --</option>';

		if (!selectedReservation) {
			roomGroupingSelect.disabled = true;
			return;
		}

		groups.forEach(function (group) {
			var option = document.createElement('option');
			option.value = group.room_number;
			option.textContent = group.room_number + ' - ' + group.occupants.length + '/' + selectedReservation.capacity + ' occupied';
			if (currentRoomNumber && currentRoomNumber === group.room_number) {
				option.selected = true;
			}
			roomGroupingSelect.appendChild(option);
		});

		if (selectedReservation.empty_room_groups > 0) {
			var newOption = document.createElement('option');
			newOption.value = '__new__';
			newOption.textContent = 'Start New Room Group (' + selectedReservation.empty_room_groups + ' empty room group(s) left)';
			roomGroupingSelect.appendChild(newOption);
		}

		roomGroupingSelect.disabled = roomGroupingSelect.options.length === 1;
	}

	function renderBookingSummary(form) {
		var bookingSelect = form.querySelector('.js-booking-select');
		var roomGroupingSelect = form.querySelector('.js-room-grouping-select');
		var summary = form.querySelector('.js-booking-summary');
		var submitButton = form.querySelector('button[type="submit"]');
		var selectedReservation = null;

		reservationOptions.forEach(function (reservation) {
			if (String(reservation.id) === String(bookingSelect.value)) {
				selectedReservation = reservation;
			}
		});

		if (!selectedReservation) {
			summary.className = 'border rounded p-3 bg-light-subtle mb-3 js-booking-summary text-muted small';
			summary.textContent = 'Select a reservation to review room group availability.';
			submitButton.disabled = true;
			return;
		}

		summary.className = 'border rounded p-3 bg-light-subtle mb-3 js-booking-summary';
		summary.innerHTML =
			'<div class="d-flex justify-content-between align-items-start gap-2 mb-2">' +
				'<div>' +
					'<div class="fw-semibold">' + selectedReservation.championship_title + '</div>' +
					'<div class="small text-muted">' + selectedReservation.hotel_name + ' / ' + selectedReservation.room_type_name + '</div>' +
				'</div>' +
				'<span class="badge text-bg-primary">' + selectedReservation.rooms_reserved + ' room(s) reserved</span>' +
			'</div>' +
			'<div class="row row-cols-2 g-2 small">' +
				'<div class="col"><div class="border rounded p-2 bg-white">Assigned Athletes<br><strong>' + selectedReservation.assigned_athletes + '</strong></div></div>' +
				'<div class="col"><div class="border rounded p-2 bg-white">Remaining Slots<br><strong>' + selectedReservation.remaining_slots + '</strong></div></div>' +
				'<div class="col"><div class="border rounded p-2 bg-white">Empty Room Groups<br><strong>' + selectedReservation.empty_room_groups + '</strong></div></div>' +
				'<div class="col"><div class="border rounded p-2 bg-white">Rate / Pax / Day<br><strong>' + formatMoney(selectedReservation.price_per_night) + '</strong></div></div>' +
			'</div>';

		submitButton.disabled = !roomGroupingSelect.value;
	}

	function renderRoomPreview(form) {
		var bookingSelect = form.querySelector('.js-booking-select');
		var roomGroupingSelect = form.querySelector('.js-room-grouping-select');
		var preview = form.querySelector('.js-room-group-preview');
		var groups = roomGroupPreview[String(bookingSelect.value)] || [];

		if (!bookingSelect.value) {
			preview.innerHTML = '<div class="fw-semibold mb-2">Current Room Groups</div><p class="text-muted small mb-0">Select a reservation to preview existing room groups.</p>';
			return;
		}

		if (groups.length === 0 && roomGroupingSelect.value !== '__new__') {
			preview.innerHTML = '<div class="fw-semibold mb-2">Current Room Groups</div><p class="text-muted small mb-0">No room groups exist yet for this reservation. Choose “Start New Room Group” to begin.</p>';
			return;
		}

		var selectedGrouping = roomGroupingSelect.value;
		var html = '<div class="fw-semibold mb-2">Current Room Groups</div><div class="d-grid gap-2">';
		groups.forEach(function (group) {
			var isSelected = selectedGrouping && selectedGrouping === group.room_number;
			html += '<div class="border rounded p-2 ' + (isSelected ? 'border-primary bg-primary-subtle' : 'bg-light-subtle') + '">';
			html += '<div class="d-flex justify-content-between align-items-center mb-1"><span class="fw-semibold">' + group.room_number + '</span><span class="badge text-bg-secondary">' + group.occupants.length + ' occupant(s)</span></div>';
			html += '<ul class="list-unstyled small mb-0">';
			group.occupants.forEach(function (occupant) {
				html += '<li class="d-flex justify-content-between py-1 border-top"><span>' + occupant.name + '</span><span class="text-muted">' + occupant.gender + '</span></li>';
			});
			html += '</ul></div>';
		});
		if (selectedGrouping === '__new__') {
			html += '<div class="border rounded p-2 border-success bg-success-subtle"><div class="fw-semibold text-success">New Room Group</div><div class="small text-muted">A new reserved room group will be used for this athlete.</div></div>';
		}
		html += '</div>';
		preview.innerHTML = html;
	}

	function syncForm(form) {
		renderBookingOptions(form);
		renderRoomGroupingOptions(form);
		renderBookingSummary(form);
		renderRoomPreview(form);
	}

	document.querySelectorAll('.assign-room-form').forEach(function (form) {
		var bookingSelect = form.querySelector('.js-booking-select');
		var roomGroupingSelect = form.querySelector('.js-room-grouping-select');

		syncForm(form);

		bookingSelect.addEventListener('change', function () {
			renderRoomGroupingOptions(form);
			renderBookingSummary(form);
			renderRoomPreview(form);
		});

		roomGroupingSelect.addEventListener('change', function () {
			renderBookingSummary(form);
			renderRoomPreview(form);
		});
	});
});
</script>

<?php
require_once 'includes/footer.php';
?>