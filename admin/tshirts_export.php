<?php
require_once 'includes/auth.php';

$sizeOptions = ['XS', 'S', 'M', 'L', 'XL'];
for ($size = 2; $size <= 10; $size++) {
    $sizeOptions[] = $size . 'XL';
}

$rowsStmt = $pdo->query("SELECT
    u.id,
    COALESCE(NULLIF(u.country_name, ''), 'Unassigned Country') AS country_name,
    COUNT(a.id) AS athlete_count,
    SUM(CASE WHEN a.tshirt_size IS NOT NULL AND a.tshirt_size <> '' THEN 1 ELSE 0 END) AS submitted_count,
    SUM(CASE WHEN a.tshirt_size IS NULL OR a.tshirt_size = '' THEN 1 ELSE 0 END) AS pending_count
    FROM users u
    LEFT JOIN athletes a ON a.country_id = u.id
    WHERE u.role = 'country_manager'
    GROUP BY u.id, u.country_name
    ORDER BY u.country_name ASC");
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$sizeRowsStmt = $pdo->query("SELECT a.country_id, a.tshirt_size, COUNT(*) AS total
    FROM athletes a
    JOIN users u ON u.id = a.country_id
    WHERE u.role = 'country_manager'
      AND a.tshirt_size IS NOT NULL
      AND a.tshirt_size <> ''
    GROUP BY a.country_id, a.tshirt_size");
$sizesByCountry = [];
$grandSizeTotals = array_fill_keys($sizeOptions, 0);
foreach ($sizeRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $sizeRow) {
    $countryId = (int) $sizeRow['country_id'];
    $sizeName = (string) $sizeRow['tshirt_size'];
    $total = (int) $sizeRow['total'];
    if (in_array($sizeName, $sizeOptions, true)) {
        $sizesByCountry[$countryId][$sizeName] = $total;
        $grandSizeTotals[$sizeName] += $total;
    }
}

function tshirtPdfText(string $value): string
{
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $value);
}

function tshirtPdfClip(string $value, int $length): string
{
    return strlen($value) <= $length ? $value : substr($value, 0, max(0, $length - 3)) . '...';
}

function tshirtPdfCell(string $text, float $x, float $y, float $width, bool $bold = false): string
{
    $font = $bold ? 'F2' : 'F1';
    $maxCharacters = max(1, (int) floor($width / 5.1));
    return "BT /{$font} 8 Tf {$x} {$y} Td (" . tshirtPdfText(tshirtPdfClip($text, $maxCharacters)) . ") Tj ET\n";
}

$totalAthletes = array_sum(array_column($rows, 'athlete_count'));
$totalSubmitted = array_sum(array_column($rows, 'submitted_count'));
$totalPending = array_sum(array_column($rows, 'pending_count'));
$grandSizeParts = [];
foreach ($sizeOptions as $sizeOption) {
    if ($grandSizeTotals[$sizeOption] > 0) {
        $grandSizeParts[] = $sizeOption . ': ' . $grandSizeTotals[$sizeOption];
    }
}

$pageWidth = 595;
$pageHeight = 842;
$left = 36;
$top = 802;
$bottom = 42;
$rowHeight = 23;
$columns = [['Country', 155], ['Athletes', 60], ['Submitted', 68], ['Pending', 55], ['Size groups', 180]];
$pages = [];
$content = '';
$y = $top;
$pageNumber = 0;

$startPage = static function () use (&$content, &$y, &$pageNumber, $left, $top, $columns, $rowHeight, $totalAthletes, $totalSubmitted, $totalPending, $grandSizeParts): void {
    $pageNumber++;
    $content = "BT /F2 18 Tf {$left} {$top} Td (T-Shirt Size Report) Tj ET\n";
    $content .= "BT /F1 8 Tf {$left} " . ($top - 20) . " Td (Generated: " . date('d M Y H:i') . ") Tj ET\n";
    $content .= "BT /F1 8 Tf 500 {$top} Td (Page {$pageNumber}) Tj ET\n";
    $content .= "BT /F2 9 Tf {$left} " . ($top - 43) . " Td (Athletes: {$totalAthletes}   Submitted: {$totalSubmitted}   Pending: {$totalPending}) Tj ET\n";
    $content .= "BT /F1 8 Tf {$left} " . ($top - 59) . " Td (Overall sizes: " . tshirtPdfText(tshirtPdfClip(implode(' | ', $grandSizeParts) ?: 'No sizes submitted', 105)) . ") Tj ET\n";
    $y = $top - 88;
    $content .= "0.90 0.94 0.98 rg {$left} " . ($y - 6) . " 518 {$rowHeight} re f\n0 0 0 rg\n";
    $x = $left + 4;
    foreach ($columns as [$label, $width]) {
        $content .= tshirtPdfCell($label, $x, $y, $width, true);
        $x += $width;
    }
    $y -= $rowHeight;
};
$finishPage = static function () use (&$pages, &$content): void {
    $pages[] = $content;
};

$startPage();
if ($rows === []) {
    $content .= tshirtPdfCell('No country delegations found.', $left + 4, $y, 400);
} else {
    foreach ($rows as $index => $row) {
        if ($y < $bottom + $rowHeight) {
            $finishPage();
            $startPage();
        }
        if ($index % 2 === 1) {
            $content .= "0.97 0.97 0.97 rg {$left} " . ($y - 6) . " 518 {$rowHeight} re f\n0 0 0 rg\n";
        }
        $sizeParts = [];
        $countrySizes = $sizesByCountry[(int) $row['id']] ?? [];
        foreach ($sizeOptions as $sizeOption) {
            if (!empty($countrySizes[$sizeOption])) {
                $sizeParts[] = $sizeOption . ': ' . $countrySizes[$sizeOption];
            }
        }
        $values = [
            (string) $row['country_name'],
            (string) $row['athlete_count'],
            (string) $row['submitted_count'],
            (string) $row['pending_count'],
            implode(' | ', $sizeParts) ?: 'No sizes submitted',
        ];
        $x = $left + 4;
        foreach ($columns as $columnIndex => [, $width]) {
            $content .= tshirtPdfCell($values[$columnIndex], $x, $y, $width, $columnIndex === 0);
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

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="tshirt-size-report-' . date('Y-m-d') . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
