<?php
require_once 'includes/auth.php';

$hotelId = (int) ($_GET['hotel_id'] ?? 0);
if ($hotelId <= 0) {
    http_response_code(400);
    exit('A hotel is required.');
}

$hotelStmt = $pdo->prepare('SELECT name, address FROM hotels WHERE id = ? LIMIT 1');
$hotelStmt->execute([$hotelId]);
$hotel = $hotelStmt->fetch(PDO::FETCH_ASSOC);
if (!$hotel) {
    http_response_code(404);
    exit('Hotel not found.');
}

$guestStmt = $pdo->prepare("SELECT
    u.country_name,
    a.first_name,
    a.last_name,
    a.participant_type,
    a.gender,
    c.title AS championship_title,
    b.booking_start_date,
    b.booking_end_date,
    rt.name AS room_type_name,
    ra.room_number
    FROM room_assignments ra
    JOIN bookings b ON b.id = ra.booking_id
    JOIN athletes a ON a.id = ra.athlete_id
    JOIN users u ON u.id = b.country_id
    JOIN championships c ON c.id = b.championship_id
    JOIN room_types rt ON rt.id = b.room_type_id
    WHERE b.hotel_id = ? AND b.status <> 'Cancelled'
    ORDER BY CASE WHEN ra.room_number IS NULL OR ra.room_number = '' THEN 1 ELSE 0 END,
        ra.room_number ASC, u.country_name ASC, a.last_name ASC, a.first_name ASC");
$guestStmt->execute([$hotelId]);
$guests = $guestStmt->fetchAll(PDO::FETCH_ASSOC);

function reportPdfText(string $value): string
{
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $value);
}

function reportPdfClip(string $value, int $length): string
{
    if (strlen($value) <= $length) {
        return $value;
    }
    return substr($value, 0, max(0, $length - 3)) . '...';
}

function reportPdfCell(string $text, float $x, float $y, float $width, bool $bold = false): string
{
    $font = $bold ? 'F2' : 'F1';
    $maxCharacters = max(1, (int) floor($width / 4.7));
    return "BT /{$font} 7 Tf {$x} {$y} Td (" . reportPdfText(reportPdfClip($text, $maxCharacters)) . ") Tj ET\n";
}

$pageWidth = 842;
$pageHeight = 595;
$left = 26;
$top = 558;
$bottom = 30;
$rowHeight = 17;
$columns = [
    ['Room', 45],
    ['Guest name', 115],
    ['Country', 82],
    ['Type', 55],
    ['Gender', 43],
    ['Championship', 120],
    ['Stay dates', 100],
    ['Room type', 80],
];

$pages = [];
$content = '';
$y = $top;
$pageNumber = 0;

$startPage = static function () use (&$content, &$y, &$pageNumber, $left, $top, $columns, $rowHeight, $hotel, $guests): void {
    $pageNumber++;
    $content = "BT /F2 15 Tf {$left} {$top} Td (" . reportPdfText($hotel['name'] . ' - Rooming List') . ") Tj ET\n";
    $content .= "BT /F1 8 Tf {$left} " . ($top - 17) . " Td (" . reportPdfText($hotel['address']) . ") Tj ET\n";
    $content .= "BT /F1 8 Tf 700 {$top} Td (Guests: " . count($guests) . ") Tj ET\n";
    $content .= "BT /F1 7 Tf 700 " . ($top - 13) . " Td (Page {$pageNumber}) Tj ET\n";
    $y = $top - 43;
    $content .= "0.90 0.94 0.98 rg {$left} " . ($y - 4) . " 790 {$rowHeight} re f\n0 0 0 rg\n";
    $x = $left + 3;
    foreach ($columns as [$label, $width]) {
        $content .= reportPdfCell($label, $x, $y, $width, true);
        $x += $width;
    }
    $y -= $rowHeight;
};

$finishPage = static function () use (&$pages, &$content): void {
    $pages[] = $content;
};

$startPage();
if ($guests === []) {
    $content .= reportPdfCell('No assigned guests for this hotel.', $left + 3, $y, 500);
} else {
    foreach ($guests as $index => $guest) {
        if ($y < $bottom + $rowHeight) {
            $finishPage();
            $startPage();
        }
        if ($index % 2 === 1) {
            $content .= "0.97 0.97 0.97 rg {$left} " . ($y - 4) . " 790 {$rowHeight} re f\n0 0 0 rg\n";
        }
        $stayDates = date('d M Y', strtotime($guest['booking_start_date'])) . ' - ' . date('d M Y', strtotime($guest['booking_end_date']));
        $values = [
            $guest['room_number'] ?: 'Not set',
            trim($guest['first_name'] . ' ' . $guest['last_name']),
            $guest['country_name'],
            ucfirst($guest['participant_type'] ?? 'athlete'),
            $guest['gender'],
            $guest['championship_title'],
            $stayDates,
            $guest['room_type_name'],
        ];
        $x = $left + 3;
        foreach ($columns as $columnIndex => [, $width]) {
            $content .= reportPdfCell((string) $values[$columnIndex], $x, $y, $width, $columnIndex === 0);
            $x += $width;
        }
        $content .= "0.85 0.85 0.85 RG {$left} " . ($y - 6) . " m 816 " . ($y - 6) . " l S\n";
        $y -= $rowHeight;
    }
}
$finishPage();

$objects = [];
$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
$pageObjectIds = [];
$nextObjectId = 5;
foreach ($pages as $pageContent) {
    $pageId = $nextObjectId++;
    $contentId = $nextObjectId++;
    $pageObjectIds[] = $pageId . ' 0 R';
    $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
    $objects[$contentId] = '<< /Length ' . strlen($pageContent) . ">>\nstream\n" . $pageContent . "endstream";
}
$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageObjectIds) . '] /Count ' . count($pages) . ' >>';
$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
ksort($objects);

$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $id => $body) {
    $offsets[$id] = strlen($pdf);
    $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
}
$xref = strlen($pdf);
$maxObjectId = max(array_keys($objects));
$pdf .= "xref\n0 " . ($maxObjectId + 1) . "\n0000000000 65535 f \n";
for ($id = 1; $id <= $maxObjectId; $id++) {
    $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
}
$pdf .= "trailer << /Size " . ($maxObjectId + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

$safeHotelName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $hotel['name']);
$filename = trim((string) $safeHotelName, '-') . '-rooming-list.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
