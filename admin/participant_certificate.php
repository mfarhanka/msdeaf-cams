<?php
require_once 'includes/auth.php';
require_once '../includes/participant_subtypes.php';

ensureParticipantSubtypeSchema($pdo);
$participantId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT a.first_name, a.last_name, a.participant_type,
            ps.name AS participant_subtype_name, u.country_name
     FROM athletes a
     JOIN users u ON u.id = a.country_id AND u.role = 'country_manager'
     LEFT JOIN participant_subtypes ps
       ON ps.id = a.participant_subtype_id AND ps.country_id = a.country_id
     WHERE a.id = ?
     LIMIT 1"
);
$stmt->execute([$participantId]);
$participant = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$participant) {
    http_response_code(404);
    exit('Participant not found.');
}

$participantName = trim((string) $participant['first_name'] . ' ' . (string) $participant['last_name']);
$participantRole = trim((string) ($participant['participant_subtype_name'] ?? ''));
if ($participantRole === '') {
    $participantRole = ($participant['participant_type'] ?? 'athlete') === 'official' ? 'Official' : 'Athlete';
}
$countryName = trim((string) ($participant['country_name'] ?? '')) ?: 'Country not specified';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Participant Certificate - <?php echo htmlspecialchars($participantName); ?></title>
<style>
@page { size: A4 portrait; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; }
.toolbar { position: fixed; z-index: 10; top: 16px; right: 16px; }
.toolbar button { border: 0; border-radius: 6px; padding: 10px 18px; color: #fff; background: #0d6efd; font-size: 15px; font-weight: 700; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
.certificate { position: relative; width: 210mm; height: 297mm; margin: 20px auto; overflow: hidden; background: #fff; box-shadow: 0 4px 20px rgba(0,0,0,.18); }
.certificate-image { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: fill; }
.recipient { position: absolute; z-index: 2; left: 33mm; right: 33mm; top: 118mm; min-height: 27mm; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1.2mm; background: #fff; color: #073b7f; text-align: center; font-weight: 400; text-transform: uppercase; }
.recipient-line { width: 100%; line-height: 1.06; white-space: nowrap; }
.recipient-name { font-size: 17pt; }
.recipient-role, .recipient-country { font-size: 15pt; }
@media print {
    html, body { width: 210mm; height: 297mm; background: #fff; }
    .toolbar { display: none; }
    .certificate { margin: 0; box-shadow: none; page-break-after: avoid; }
}
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Print Certificate</button></div>
<main class="certificate">
    <img class="certificate-image" src="../volunteer-certificate-template.jpg" alt="">
    <div class="recipient">
        <div class="recipient-line recipient-name"><?php echo htmlspecialchars($participantName); ?></div>
        <div class="recipient-line recipient-role"><?php echo htmlspecialchars($participantRole); ?></div>
        <div class="recipient-line recipient-country"><?php echo htmlspecialchars($countryName); ?></div>
    </div>
</main>
<script>
window.addEventListener('load', function () {
    document.querySelectorAll('.recipient-line').forEach(function (line) {
        var size = parseFloat(window.getComputedStyle(line).fontSize);
        while (line.scrollWidth > line.clientWidth && size > 12) {
            size -= 0.5;
            line.style.fontSize = size + 'px';
        }
    });
    window.setTimeout(function () { window.print(); }, 250);
});
</script>
</body>
</html>
