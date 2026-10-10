<?php
require_once 'includes/auth.php';

$volunteerId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT full_name FROM volunteer_applications WHERE id = ? LIMIT 1');
$stmt->execute([$volunteerId]);
$volunteer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$volunteer) {
    http_response_code(404);
    exit('Volunteer not found.');
}

$volunteerName = trim((string) $volunteer['full_name']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Volunteer Certificate - <?php echo htmlspecialchars($volunteerName); ?></title>
<style>
@page { size: A4 portrait; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; }
.toolbar { position: fixed; z-index: 10; top: 16px; right: 16px; display: flex; gap: 8px; }
.toolbar button { border: 0; border-radius: 6px; padding: 10px 18px; color: #fff; background: #0d6efd; font-size: 15px; font-weight: 700; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
.certificate { position: relative; width: 210mm; height: 297mm; margin: 20px auto; overflow: hidden; background: #fff; box-shadow: 0 4px 20px rgba(0,0,0,.18); }
.certificate-image { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: fill; }
.recipient { position: absolute; z-index: 2; left: 33mm; right: 33mm; top: 118mm; min-height: 27mm; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1.2mm; background: #fff; color: #073b7f; text-align: center; font-weight: 400; text-transform: uppercase; }
.recipient-name { width: 100%; font-size: 17pt; line-height: 1.08; overflow-wrap: anywhere; }
.recipient-role, .recipient-country { font-size: 15pt; line-height: 1.05; }
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
        <div class="recipient-name"><?php echo htmlspecialchars($volunteerName); ?></div>
        <div class="recipient-role">Volunteer</div>
        <div class="recipient-country">Malaysia</div>
    </div>
</main>
<script>
window.addEventListener('load', function () {
    var name = document.querySelector('.recipient-name');
    var size = 17;
    while (name.scrollWidth > name.clientWidth && size > 10) {
        size -= 0.5;
        name.style.fontSize = size + 'pt';
    }
    window.setTimeout(function () { window.print(); }, 250);
});
</script>
</body>
</html>
