<?php
require_once 'includes/auth.php';
require_once '../includes/flights.php';

ensureDelegationFlightsTable($pdo);
$direction = strtolower(trim((string) ($_GET['direction'] ?? '')));
if (!in_array($direction, ['arrival', 'departure'], true)) {
    http_response_code(400);
    exit('A valid flight direction is required.');
}
$directionLabel = ucfirst($direction);
$stmt = $pdo->prepare("SELECT u.country_name, u.username, f.*,
    (SELECT GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) ORDER BY a.last_name, a.first_name SEPARATOR ', ')
     FROM delegation_flight_movement_members fm
     JOIN athletes a ON a.id = fm.athlete_id
     WHERE fm.movement_id = f.id) AS delegate_names
    FROM delegation_flight_movements f
    JOIN users u ON u.id = f.country_id
    WHERE u.role = 'country_manager' AND f.direction = ?
    ORDER BY f.airport IS NULL, FIELD(f.airport, 'BAYAN_LEPAS', 'KLIA', 'KLIA2'), f.flight_datetime, u.country_name, f.id");
$stmt->execute([$direction]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$airportGroups = [];
foreach ($rows as $row) {
    $code = trim((string) ($row['airport'] ?? '')) ?: 'NOT_ENTERED';
    if (!isset($airportGroups[$code])) {
        $airportGroups[$code] = ['label' => flightAirportLabel($row['airport'] ?? null), 'rows' => [], 'pax' => 0];
    }
    $airportGroups[$code]['rows'][] = $row;
    $airportGroups[$code]['pax'] += (int) $row['pax'];
}

function flightPdfText(string $value): string
{
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    return strtr($value, [chr(92) => chr(92) . chr(92), '(' => chr(92) . '(', ')' => chr(92) . ')', chr(13) => ' ', chr(10) => ' ']);
}
function flightPdfCell(string $value, float $x, float $y, float $width, bool $bold = false): string
{
    $limit = max(1, (int) floor($width / 4.6));
    if (strlen($value) > $limit) $value = substr($value, 0, max(0, $limit - 3)) . '...';
    return 'BT /' . ($bold ? 'F2' : 'F1') . " 7 Tf {$x} {$y} Td (" . flightPdfText($value) . ") Tj ET\n";
}

$pageWidth = 842; $pageHeight = 595; $left = 26; $top = 558; $bottom = 30; $rowHeight = 18;
$columns = [['Date',70], ['Time',45], ['Delegation',100], ['Flight',60], ['Delegates',285], ['Pax',35], ['Bus Transfer',185]];
$pages = []; $content = ''; $y = $top; $pageNumber = 0;
$startPage = static function () use (&$content, &$y, &$pageNumber, $left, $top, $rows, $directionLabel): void {
    $pageNumber++;
    $totalPax = array_sum(array_column($rows, 'pax'));
    $content = "BT /F2 15 Tf {$left} {$top} Td ({$directionLabel} Flight Schedule) Tj ET\n";
    $content .= "BT /F1 8 Tf {$left} " . ($top - 17) . " Td (Grouped by airport and sorted by date and time) Tj ET\n";
    $content .= "BT /F1 8 Tf 680 {$top} Td (Groups: " . count($rows) . " / Pax: {$totalPax}) Tj ET\n";
    $content .= "BT /F1 7 Tf 735 " . ($top - 13) . " Td (Page {$pageNumber}) Tj ET\n";
    $y = $top - 43;
};
$finishPage = static function () use (&$pages, &$content): void { $pages[] = $content; };
$printTableHeader = static function () use (&$content, &$y, $left, $columns, $rowHeight): void {
    $content .= "0.90 0.94 0.98 rg {$left} " . ($y - 4) . " 790 {$rowHeight} re f\n0 0 0 rg\n";
    $x = $left + 3;
    foreach ($columns as [$label, $width]) { $content .= flightPdfCell($label, $x, $y, $width, true); $x += $width; }
    $y -= $rowHeight;
};
$printAirportHeader = static function (array $airport, bool $continued = false) use (&$content, &$y, $left, $rowHeight): void {
    $label = $airport['label'] . ($continued ? ' (continued)' : '');
    $summary = count($airport['rows']) . ' group' . (count($airport['rows']) === 1 ? '' : 's') . ' / ' . $airport['pax'] . ' pax';
    $content .= "0.82 0.89 0.97 rg {$left} " . ($y - 4) . " 790 {$rowHeight} re f\n0 0 0 rg\n";
    $content .= flightPdfCell($label, $left + 3, $y, 590, true);
    $content .= flightPdfCell($summary, 700, $y, 110, true);
    $y -= $rowHeight;
};

$startPage();
if (!$airportGroups) {
    $content .= flightPdfCell('No ' . $direction . ' flight details have been submitted.', $left + 3, $y, 500);
} else {
    foreach ($airportGroups as $airport) {
        if ($y < $bottom + ($rowHeight * 3)) { $finishPage(); $startPage(); }
        $printAirportHeader($airport);
        $printTableHeader();
        foreach ($airport['rows'] as $index => $row) {
            if ($y < $bottom + $rowHeight) {
                $finishPage(); $startPage(); $printAirportHeader($airport, true); $printTableHeader();
            }
            if ($index % 2 === 1) $content .= "0.97 0.97 0.97 rg {$left} " . ($y - 4) . " 790 {$rowHeight} re f\n0 0 0 rg\n";
            $busCount = (int) ($row['bus_count'] ?? (!empty($row['bus_to_penang']) ? 1 : 0));
            $bus = $busCount ? $busCount . ' bus' . ($busCount === 1 ? '' : 'es') . ' / USD ' . number_format($busCount * 1000) : 'No';
            $values = [date('d M Y', strtotime($row['flight_datetime'])), date('H:i', strtotime($row['flight_datetime'])), trim((string) ($row['country_name'] ?: $row['username'])), (string) $row['flight_number'], (string) ($row['delegate_names'] ?: 'Not assigned'), (string) (int) $row['pax'], $bus];
            $x = $left + 3;
            foreach ($columns as $columnIndex => [, $width]) { $content .= flightPdfCell($values[$columnIndex], $x, $y, $width, $columnIndex === 0); $x += $width; }
            $content .= "0.85 0.85 0.85 RG {$left} " . ($y - 6) . " m 816 " . ($y - 6) . " l S\n";
            $y -= $rowHeight;
        }
        $y -= 5;
    }
}
$finishPage();

$objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>']; $pageRefs = []; $nextId = 5;
foreach ($pages as $stream) {
    $pageId = $nextId++; $contentId = $nextId++; $pageRefs[] = $pageId . ' 0 R';
    $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
    $objects[$contentId] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
}
$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pages) . ' >>';
$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>'; ksort($objects);
$pdf = "%PDF-1.4\n"; $offsets = [0];
foreach ($objects as $id => $body) { $offsets[$id] = strlen($pdf); $pdf .= "{$id} 0 obj\n{$body}\nendobj\n"; }
$xref = strlen($pdf); $maxId = max(array_keys($objects));
$pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f" . chr(32) . "\n";
for ($id = 1; $id <= $maxId; $id++) $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
$pdf .= "trailer << /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $direction . '-flight-schedule-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf)); header('X-Content-Type-Options: nosniff'); echo $pdf;