<?php
require_once __DIR__ . '/includes/db.php';

function upsertCountryManager(PDO $pdo, string $username, string $countryName, string $password): int
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $userId = $stmt->fetchColumn();

    if ($userId) {
        $updateStmt = $pdo->prepare("UPDATE users SET country_name = ?, role = 'country_manager', status = 'active' WHERE id = ?");
        $updateStmt->execute([$countryName, $userId]);
        return (int) $userId;
    }

    $insertStmt = $pdo->prepare("INSERT INTO users (username, password, role, status, country_name) VALUES (?, ?, 'country_manager', 'active', ?)");
    $insertStmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $countryName]);
    return (int) $pdo->lastInsertId();
}

function upsertChampionship(PDO $pdo, array $championship): int
{
    $stmt = $pdo->prepare("SELECT id FROM championships WHERE title = ? LIMIT 1");
    $stmt->execute([$championship['title']]);
    $championshipId = $stmt->fetchColumn();

    if ($championshipId) {
        $updateStmt = $pdo->prepare("UPDATE championships SET start_date = ?, end_date = ?, location = ? WHERE id = ?");
        $updateStmt->execute([$championship['start_date'], $championship['end_date'], $championship['location'], $championshipId]);
        return (int) $championshipId;
    }

    $insertStmt = $pdo->prepare("INSERT INTO championships (title, start_date, end_date, location) VALUES (?, ?, ?, ?)");
    $insertStmt->execute([
        $championship['title'],
        $championship['start_date'],
        $championship['end_date'],
        $championship['location'],
    ]);
    return (int) $pdo->lastInsertId();
}

function upsertHotel(PDO $pdo, array $hotel): int
{
    $stmt = $pdo->prepare("SELECT id FROM hotels WHERE name = ? LIMIT 1");
    $stmt->execute([$hotel['name']]);
    $hotelId = $stmt->fetchColumn();

    if ($hotelId) {
        $updateStmt = $pdo->prepare("UPDATE hotels SET address = ? WHERE id = ?");
        $updateStmt->execute([$hotel['address'], $hotelId]);
        return (int) $hotelId;
    }

    $insertStmt = $pdo->prepare("INSERT INTO hotels (name, address, total_rooms) VALUES (?, ?, 0)");
    $insertStmt->execute([$hotel['name'], $hotel['address']]);
    return (int) $pdo->lastInsertId();
}

function upsertRoomType(PDO $pdo, int $hotelId, array $roomType): int
{
    $stmt = $pdo->prepare("SELECT id FROM room_types WHERE hotel_id = ? AND name = ? LIMIT 1");
    $stmt->execute([$hotelId, $roomType['name']]);
    $roomTypeId = $stmt->fetchColumn();

    if ($roomTypeId) {
        $updateStmt = $pdo->prepare("UPDATE room_types SET capacity = ?, price_per_night = ?, total_allotment = ? WHERE id = ?");
        $updateStmt->execute([$roomType['capacity'], $roomType['price_per_night'], $roomType['total_allotment'], $roomTypeId]);
        return (int) $roomTypeId;
    }

    $insertStmt = $pdo->prepare("INSERT INTO room_types (hotel_id, name, capacity, price_per_night, total_allotment) VALUES (?, ?, ?, ?, ?)");
    $insertStmt->execute([$hotelId, $roomType['name'], $roomType['capacity'], $roomType['price_per_night'], $roomType['total_allotment']]);
    return (int) $pdo->lastInsertId();
}

function linkChampionshipHotel(PDO $pdo, int $championshipId, int $hotelId): void
{
    $stmt = $pdo->prepare("INSERT IGNORE INTO championship_hotels (championship_id, hotel_id) VALUES (?, ?)");
    $stmt->execute([$championshipId, $hotelId]);
}

function syncHotelRoomTotal(PDO $pdo, int $hotelId): void
{
    $stmt = $pdo->prepare("UPDATE hotels SET total_rooms = (SELECT COALESCE(SUM(total_allotment), 0) FROM room_types WHERE hotel_id = ?) WHERE id = ?");
    $stmt->execute([$hotelId, $hotelId]);
}

function upsertAthlete(PDO $pdo, int $countryId, array $athlete): int
{
    $stmt = $pdo->prepare("SELECT id FROM athletes WHERE country_id = ? AND first_name = ? AND last_name = ? LIMIT 1");
    $stmt->execute([$countryId, $athlete['first_name'], $athlete['last_name']]);
    $athleteId = $stmt->fetchColumn();

    if ($athleteId) {
        $updateStmt = $pdo->prepare("UPDATE athletes SET gender = ?, tshirt_size = ?, sport_category = ?, passport_number = ? WHERE id = ?");
        $updateStmt->execute([
            $athlete['gender'],
            $athlete['tshirt_size'],
            $athlete['sport_category'],
            $athlete['passport_number'],
            $athleteId,
        ]);
        return (int) $athleteId;
    }

    $insertStmt = $pdo->prepare("INSERT INTO athletes (country_id, first_name, last_name, gender, tshirt_size, sport_category, passport_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $insertStmt->execute([
        $countryId,
        $athlete['first_name'],
        $athlete['last_name'],
        $athlete['gender'],
        $athlete['tshirt_size'],
        $athlete['sport_category'],
        $athlete['passport_number'],
    ]);
    return (int) $pdo->lastInsertId();
}

function upsertBooking(PDO $pdo, int $countryId, int $championshipId, int $hotelId, int $roomTypeId, int $roomsReserved, string $status): int
{
    $stmt = $pdo->prepare("SELECT id FROM bookings WHERE country_id = ? AND championship_id = ? AND hotel_id = ? AND room_type_id = ? LIMIT 1");
    $stmt->execute([$countryId, $championshipId, $hotelId, $roomTypeId]);
    $bookingId = $stmt->fetchColumn();

    if ($bookingId) {
        $updateStmt = $pdo->prepare("UPDATE bookings SET rooms_reserved = ?, status = ? WHERE id = ?");
        $updateStmt->execute([$roomsReserved, $status, $bookingId]);
        return (int) $bookingId;
    }

    $insertStmt = $pdo->prepare("INSERT INTO bookings (championship_id, country_id, hotel_id, room_type_id, rooms_reserved, status) VALUES (?, ?, ?, ?, ?, ?)");
    $insertStmt->execute([$championshipId, $countryId, $hotelId, $roomTypeId, $roomsReserved, $status]);
    return (int) $pdo->lastInsertId();
}

function upsertRoomAssignment(PDO $pdo, int $bookingId, int $athleteId, string $roomNumber): void
{
    $stmt = $pdo->prepare("SELECT id FROM room_assignments WHERE athlete_id = ? LIMIT 1");
    $stmt->execute([$athleteId]);
    $assignmentId = $stmt->fetchColumn();

    if ($assignmentId) {
        $updateStmt = $pdo->prepare("UPDATE room_assignments SET booking_id = ?, room_number = ? WHERE id = ?");
        $updateStmt->execute([$bookingId, $roomNumber, $assignmentId]);
        return;
    }

    $insertStmt = $pdo->prepare("INSERT INTO room_assignments (booking_id, room_number, athlete_id) VALUES (?, ?, ?)");
    $insertStmt->execute([$bookingId, $roomNumber, $athleteId]);
}

$championships = [
    ['title' => 'Asia Pacific Deaf Athletics Championship 2026', 'start_date' => '2026-10-03', 'end_date' => '2026-10-10', 'location' => 'Penang State Stadium, Batu Kawan'],
    ['title' => 'Asia Pacific Deaf Badminton Championship 2026', 'start_date' => '2026-10-03', 'end_date' => '2026-10-10', 'location' => 'Penang Badminton Association'],
    ['title' => 'Asia Pacific Deaf Tenpin Bowling Championship 2026', 'start_date' => '2026-10-03', 'end_date' => '2026-10-10', 'location' => 'Megalane Bowling Centre'],
    ['title' => 'Asia Pacific Deaf Table Tennis Championship 2026', 'start_date' => '2026-10-03', 'end_date' => '2026-10-09', 'location' => 'Penang Table Tennis Association (PTTA)'],
    ['title' => 'Asia Pacific Deaf Beach Volleyball Championship 2026', 'start_date' => '2026-10-03', 'end_date' => '2026-10-10', 'location' => 'Bola Tampar Pantai di Guar Perahu, Penanti'],
    ['title' => 'Asia Pacific Deaf Karate Championship 2026', 'start_date' => '2026-10-03', 'end_date' => '2026-10-09', 'location' => 'Dewan Sri Pinang'],
    ['title' => 'Asia Pacific Deaf Orienteering Championship 2026', 'start_date' => '2026-10-03', 'end_date' => '2026-10-08', 'location' => 'Penang Botanical Gardens'],
    ['title' => 'Asia Pacific Deaf Chess Championship 2026', 'start_date' => '2026-10-03', 'end_date' => '2026-10-10', 'location' => 'Dewan Sri Pinang'],
];

$hotels = [
    [
        'name' => 'JEN Penang Georgetown by Shangri-La',
        'address' => 'Magazine Rd, George Town, 10300 George Town, Pulau Pinang, Malaysia',
        'room_types' => [
            ['name' => 'Single Room', 'capacity' => 1, 'price_per_night' => 90.00, 'total_allotment' => 18],
            ['name' => 'Twin Room', 'capacity' => 2, 'price_per_night' => 65.00, 'total_allotment' => 26],
        ],
    ],
    [
        'name' => 'Hotel Neo+ Penang',
        'address' => '68 Jalan Gurdwara, 10300 Georgetown, Pulau Pinang, Malaysia',
        'room_types' => [
            ['name' => 'Single Room', 'capacity' => 1, 'price_per_night' => 72.00, 'total_allotment' => 14],
            ['name' => 'Twin Room', 'capacity' => 2, 'price_per_night' => 52.00, 'total_allotment' => 22],
        ],
    ],
    [
        'name' => 'Merchant Hotel',
        'address' => '55, Jalan Penang, Georgetown, 10000 Penang',
        'room_types' => [
            ['name' => 'Twin Room', 'capacity' => 2, 'price_per_night' => 48.00, 'total_allotment' => 20],
            ['name' => 'King Room', 'capacity' => 2, 'price_per_night' => 55.00, 'total_allotment' => 10],
        ],
    ],
    [
        'name' => 'Kimberley Hotel',
        'address' => 'No.36 G-02 Jalan Sungai Ujong, 10100 Georgetown, Penang',
        'room_types' => [
            ['name' => 'Single Room', 'capacity' => 1, 'price_per_night' => 58.00, 'total_allotment' => 12],
            ['name' => 'Twin Room', 'capacity' => 2, 'price_per_night' => 44.00, 'total_allotment' => 18],
        ],
    ],
    [
        'name' => 'Hotel Malaysia',
        'address' => '7, Penang Road, 10000 Penang, Malaysia',
        'room_types' => [
            ['name' => 'Twin Room', 'capacity' => 2, 'price_per_night' => 46.00, 'total_allotment' => 20],
            ['name' => 'Superior King', 'capacity' => 2, 'price_per_night' => 60.00, 'total_allotment' => 8],
            ['name' => 'Standard Queen', 'capacity' => 2, 'price_per_night' => 50.00, 'total_allotment' => 10],
        ],
    ],
    [
        'name' => 'Loop On Leith Hotel',
        'address' => '29 Leith Street, 10200 Georgetown, Penang, Malaysia',
        'room_types' => [
            ['name' => 'Twin Room', 'capacity' => 2, 'price_per_night' => 54.00, 'total_allotment' => 18],
            ['name' => 'King Room', 'capacity' => 2, 'price_per_night' => 59.00, 'total_allotment' => 12],
        ],
    ],
];

$delegations = [
    [
        'username' => 'malaysia',
        'country_name' => 'Malaysia',
        'password' => 'malaysia123',
        'athletes' => [
            ['first_name' => 'Aiman', 'last_name' => 'Rahman', 'gender' => 'M', 'tshirt_size' => 'M', 'sport_category' => 'Athletics', 'passport_number' => 'MYS-A-1001'],
            ['first_name' => 'Farah', 'last_name' => 'Aziz', 'gender' => 'F', 'tshirt_size' => 'S', 'sport_category' => 'Athletics', 'passport_number' => 'MYS-A-1002'],
            ['first_name' => 'Hakim', 'last_name' => 'Ismail', 'gender' => 'M', 'tshirt_size' => 'L', 'sport_category' => 'Athletics', 'passport_number' => 'MYS-A-1003'],
            ['first_name' => 'Nadia', 'last_name' => 'Salleh', 'gender' => 'F', 'tshirt_size' => 'M', 'sport_category' => 'Athletics', 'passport_number' => 'MYS-A-1004'],
        ],
        'bookings' => [
            [
                'championship_title' => 'Asia Pacific Deaf Athletics Championship 2026',
                'hotel_name' => 'JEN Penang Georgetown by Shangri-La',
                'room_type_name' => 'Twin Room',
                'rooms_reserved' => 2,
                'status' => 'Confirmed',
                'assignments' => [
                    ['athlete_name' => 'Aiman Rahman', 'room_number' => 'Room 1'],
                    ['athlete_name' => 'Hakim Ismail', 'room_number' => 'Room 1'],
                    ['athlete_name' => 'Farah Aziz', 'room_number' => 'Room 2'],
                    ['athlete_name' => 'Nadia Salleh', 'room_number' => 'Room 2'],
                ],
            ],
        ],
    ],
    [
        'username' => 'japan',
        'country_name' => 'Japan',
        'password' => 'japan123',
        'athletes' => [
            ['first_name' => 'Haruto', 'last_name' => 'Sato', 'gender' => 'M', 'tshirt_size' => 'M', 'sport_category' => 'Badminton', 'passport_number' => 'JPN-B-2001'],
            ['first_name' => 'Yui', 'last_name' => 'Tanaka', 'gender' => 'F', 'tshirt_size' => 'S', 'sport_category' => 'Badminton', 'passport_number' => 'JPN-B-2002'],
            ['first_name' => 'Riku', 'last_name' => 'Kobayashi', 'gender' => 'M', 'tshirt_size' => 'L', 'sport_category' => 'Badminton', 'passport_number' => 'JPN-B-2003'],
        ],
        'bookings' => [
            [
                'championship_title' => 'Asia Pacific Deaf Badminton Championship 2026',
                'hotel_name' => 'Loop On Leith Hotel',
                'room_type_name' => 'Twin Room',
                'rooms_reserved' => 2,
                'status' => 'Confirmed',
                'assignments' => [
                    ['athlete_name' => 'Haruto Sato', 'room_number' => 'Room 1'],
                    ['athlete_name' => 'Riku Kobayashi', 'room_number' => 'Room 1'],
                    ['athlete_name' => 'Yui Tanaka', 'room_number' => 'Room 2'],
                ],
            ],
        ],
    ],
    [
        'username' => 'korea',
        'country_name' => 'Korea',
        'password' => 'korea123',
        'athletes' => [
            ['first_name' => 'Minjun', 'last_name' => 'Lee', 'gender' => 'M', 'tshirt_size' => 'L', 'sport_category' => 'Chess', 'passport_number' => 'KOR-C-3001'],
            ['first_name' => 'Sora', 'last_name' => 'Kim', 'gender' => 'F', 'tshirt_size' => 'M', 'sport_category' => 'Chess', 'passport_number' => 'KOR-C-3002'],
        ],
        'bookings' => [
            [
                'championship_title' => 'Asia Pacific Deaf Chess Championship 2026',
                'hotel_name' => 'Hotel Malaysia',
                'room_type_name' => 'Standard Queen',
                'rooms_reserved' => 1,
                'status' => 'Confirmed',
                'assignments' => [
                    ['athlete_name' => 'Minjun Lee', 'room_number' => 'Room 1'],
                    ['athlete_name' => 'Sora Kim', 'room_number' => 'Room 1'],
                ],
            ],
        ],
    ],
    [
        'username' => 'thailand',
        'country_name' => 'Thailand',
        'password' => 'thailand123',
        'athletes' => [
            ['first_name' => 'Narin', 'last_name' => 'Somchai', 'gender' => 'M', 'tshirt_size' => 'M', 'sport_category' => 'Table Tennis', 'passport_number' => 'THA-T-4001'],
            ['first_name' => 'Anong', 'last_name' => 'Kanya', 'gender' => 'F', 'tshirt_size' => 'S', 'sport_category' => 'Table Tennis', 'passport_number' => 'THA-T-4002'],
            ['first_name' => 'Preecha', 'last_name' => 'Wong', 'gender' => 'M', 'tshirt_size' => 'L', 'sport_category' => 'Table Tennis', 'passport_number' => 'THA-T-4003'],
        ],
        'bookings' => [
            [
                'championship_title' => 'Asia Pacific Deaf Table Tennis Championship 2026',
                'hotel_name' => 'Hotel Neo+ Penang',
                'room_type_name' => 'Twin Room',
                'rooms_reserved' => 2,
                'status' => 'Pending',
                'assignments' => [
                    ['athlete_name' => 'Narin Somchai', 'room_number' => 'Room 1'],
                    ['athlete_name' => 'Preecha Wong', 'room_number' => 'Room 1'],
                ],
            ],
        ],
    ],
    [
        'username' => 'australia',
        'country_name' => 'Australia',
        'password' => 'australia123',
        'athletes' => [
            ['first_name' => 'Liam', 'last_name' => 'Cooper', 'gender' => 'M', 'tshirt_size' => 'XL', 'sport_category' => 'Beach Volleyball', 'passport_number' => 'AUS-V-5001'],
            ['first_name' => 'Chloe', 'last_name' => 'Martin', 'gender' => 'F', 'tshirt_size' => 'M', 'sport_category' => 'Beach Volleyball', 'passport_number' => 'AUS-V-5002'],
            ['first_name' => 'Noah', 'last_name' => 'Scott', 'gender' => 'M', 'tshirt_size' => 'L', 'sport_category' => 'Beach Volleyball', 'passport_number' => 'AUS-V-5003'],
        ],
        'bookings' => [
            [
                'championship_title' => 'Asia Pacific Deaf Beach Volleyball Championship 2026',
                'hotel_name' => 'Merchant Hotel',
                'room_type_name' => 'King Room',
                'rooms_reserved' => 2,
                'status' => 'Confirmed',
                'assignments' => [
                    ['athlete_name' => 'Liam Cooper', 'room_number' => 'Room 1'],
                    ['athlete_name' => 'Noah Scott', 'room_number' => 'Room 1'],
                    ['athlete_name' => 'Chloe Martin', 'room_number' => 'Room 2'],
                ],
            ],
        ],
    ],
];

$championshipIds = [];
$hotelIds = [];
$roomTypeIds = [];

try {
    $pdo->beginTransaction();

    foreach ($championships as $championship) {
        $championshipIds[$championship['title']] = upsertChampionship($pdo, $championship);
    }

    foreach ($hotels as $hotel) {
        $hotelId = upsertHotel($pdo, $hotel);
        $hotelIds[$hotel['name']] = $hotelId;

        foreach ($hotel['room_types'] as $roomType) {
            $roomTypeId = upsertRoomType($pdo, $hotelId, $roomType);
            $roomTypeIds[$hotel['name'] . '|' . $roomType['name']] = $roomTypeId;
        }

        syncHotelRoomTotal($pdo, $hotelId);
    }

    foreach ($championshipIds as $championshipId) {
        foreach ($hotelIds as $hotelId) {
            linkChampionshipHotel($pdo, $championshipId, $hotelId);
        }
    }

    foreach ($delegations as $delegation) {
        $countryId = upsertCountryManager($pdo, $delegation['username'], $delegation['country_name'], $delegation['password']);
        $athleteIdsByName = [];

        foreach ($delegation['athletes'] as $athlete) {
            $athleteId = upsertAthlete($pdo, $countryId, $athlete);
            $athleteIdsByName[$athlete['first_name'] . ' ' . $athlete['last_name']] = $athleteId;
        }

        foreach ($delegation['bookings'] as $booking) {
            $championshipId = $championshipIds[$booking['championship_title']] ?? null;
            $hotelId = $hotelIds[$booking['hotel_name']] ?? null;
            $roomTypeId = $roomTypeIds[$booking['hotel_name'] . '|' . $booking['room_type_name']] ?? null;

            if (!$championshipId || !$hotelId || !$roomTypeId) {
                throw new RuntimeException('Seed reference mismatch for booking: ' . $delegation['country_name']);
            }

            $bookingId = upsertBooking(
                $pdo,
                $countryId,
                $championshipId,
                $hotelId,
                $roomTypeId,
                $booking['rooms_reserved'],
                $booking['status']
            );

            foreach ($booking['assignments'] as $assignment) {
                $athleteId = $athleteIdsByName[$assignment['athlete_name']] ?? null;
                if (!$athleteId) {
                    throw new RuntimeException('Missing athlete for assignment: ' . $assignment['athlete_name']);
                }
                upsertRoomAssignment($pdo, $bookingId, $athleteId, $assignment['room_number']);
            }
        }
    }

    $pdo->commit();

    echo "Bulletin-based seed data completed successfully.\n";
    echo "Sample delegation logins:\n";
    foreach ($delegations as $delegation) {
        echo '- ' . $delegation['country_name'] . ' => ' . $delegation['username'] . ' / ' . $delegation['password'] . "\n";
    }
    echo "Note: hotel prices and room allotments are demo values because the bulletin page shows room labels but not machine-readable rates. Adjust them in Admin > Hotels if needed.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
?>