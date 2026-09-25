<?php
require_once 'includes/auth.php';

$championshipId = (int) ($_GET['championship_id'] ?? 0);
if ($championshipId <= 0) {
    http_response_code(400);
    exit('A championship is required.');
}

$championshipStmt = $pdo->prepare(
    "SELECT title, start_date, end_date, location FROM championships WHERE id = ? LIMIT 1"
);
$championshipStmt->execute([$championshipId]);
$championship = $championshipStmt->fetch(PDO::FETCH_ASSOC);
if (!$championship) {
    http_response_code(404);
    exit('Championship not found.');
}

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
$participantsStmt->execute([$championshipId]);
$participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);

function championshipParticipantPdfText(string $value): string
{
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $value);
}

function championshipParticipantPdfClip(string $value, int $length): string
{
    return strlen($value) <= $length ? $value : substr($value, 0, max(0, $length - 3)) . '...';
}

function championshipParticipantPdfCell(string $text, float $x, float $y, float $width, bool $bold = false): string
{
    $font = $bold ? 'F2' : 'F1';
    $maxCharacters = max(1, (int) floor($width / 5.2));
    return "BT /{$font} 9 Tf {$x} {$y} Td (" . championshipParticipantPdfText(championshipParticipantPdfClip($text, $maxCharacters)) . ") Tj ET\n";
}

$athleteCount = 0;
$officialCount = 0;
foreach ($participants as $participant) {
    if (($participant['participant_type'] ?? 'athlete') === 'official') {
        $officialCount++;
    } else {
        $athleteCount++;
    }
}

$pageWidth = 595;
$pageHeight = 842;
$left = 36;
$top = 802;
$bottom = 42;
$rowHeight = 21;
$columns = [['No.', 38], ['Country', 130], ['Type', 70], ['Gender', 60], ['Participant', 220]];
$pages = [];
$content = '';
$y = $top;
$pageNumber = 0;
$championshipTitle = (string) $championship['title'];
$championshipDetails = date('d M Y', strtotime($championship['start_date'])) . ' - ' . date('d M Y', strtotime($championship['end_date']));
if (trim((string) $championship['location']) !== '') {
    $championshipDetails .= ' | ' . trim((string) $championship['location']);
}

$startPage = static function () use (&$content, &$y, &$pageNumber, $left, $top, $columns, $rowHeight, $championshipTitle, $championshipDetails, $participants, $athleteCount, $officialCount): void {
    $pageNumber++;
    $content = "BT /F2 17 Tf {$left} {$top} Td (Championship Participants) Tj ET\n";
    $content .= "BT /F2 12 Tf {$left} " . ($top - 23) . " Td (" . championshipParticipantPdfText(championshipParticipantPdfClip($championshipTitle, 75)) . ") Tj ET\n";
    $content .= "BT /F1 8 Tf {$left} " . ($top - 40) . " Td (" . championshipParticipantPdfText(championshipParticipantPdfClip($championshipDetails, 100)) . ") Tj ET\n";
    $content .= "BT /F1 8 Tf {$left} " . ($top - 55) . " Td (Participants: " . count($participants) . " | Athletes: {$athleteCount} | Officials: {$officialCount}) Tj ET\n";
    $content .= "BT /F1 8 Tf 500 {$top} Td (Page {$pageNumber}) Tj ET\n";
    $content .= "BT /F1 7 Tf 452 " . ($top - 14) . " Td (Generated " . date('d M Y H:i') . ") Tj ET\n";
    $y = $top - 82;
    $content .= "0.90 0.94 0.98 rg {$left} " . ($y - 6) . " 518 {$rowHeight} re f\n0 0 0 rg\n";
    $x = $left + 4;
    foreach ($columns as [$label, $width]) {
        $content .= championshipParticipantPdfCell($label, $x, $y, $width, true);
        $x += $width;
    }
    $y -= $rowHeight;
};

$finishPage = static function () use (&$pages, &$content): void {
    $pages[] = $content;
};

$startPage();
if ($participants === []) {
    $content .= championshipParticipantPdfCell('No participants have been assigned to this championship.', $left + 4, $y, 490);
} else {
    foreach ($participants as $index => $participant) {
        if ($y < $bottom + $rowHeight) {
            $finishPage();
            $startPage();
        }
        if ($index % 2 === 1) {
            $content .= "0.97 0.97 0.97 rg {$left} " . ($y - 6) . " 518 {$rowHeight} re f\n0 0 0 rg\n";
        }
        $values = [
            (string) ($index + 1),
            trim((string) ($participant['country_name'] ?? '')) ?: 'Unassigned Country',
            ucfirst((string) ($participant['participant_type'] ?? 'athlete')),
            (string) $participant['gender'],
            trim((string) $participant['first_name'] . ' ' . (string) $participant['last_name']),
        ];
        $x = $left + 4;
        foreach ($columns as $columnIndex => [, $width]) {
            $content .= championshipParticipantPdfCell($values[$columnIndex], $x, $y, $width, $columnIndex === 0);
            $x += $width;
        }
        $content .= "0.85 0.85 0.85 RG {$left} " . ($y - 8) . " m 554 " . ($y - 8) . " l S\n";
        $y -= $rowHeight;
    }
}
$finishPage();

$objects = [
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
];
$pageObjectIds = [];
$nextObjectId = 5;
foreach ($pages as $pageContent) {
    $pageId = $nextObjectId++;
    $contentId = $nextObjectId++;
    $pageObjectIds[] = $pageId . ' 0 R';
    $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
    $objects[$contentId] = '<< /Length ' . strlen($pageContent) . ">>\nstream\n" . $pageContent . 'endstream';
}
$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageObjectIds) . '] /Count ' . count($pages) . ' >>';
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

$safeTitle = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $championshipTitle), '-');
$filename = ($safeTitle !== '' ? $safeTitle : 'championship') . '-participants.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
