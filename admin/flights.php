<?php
require_once 'includes/auth.php';
require_once '../includes/flights.php';
ensureDelegationFlightsTable($pdo);
$stmt = $pdo->query("SELECT u.id AS country_id, u.country_name, u.username, f.*,
    (SELECT GROUP_CONCAT(CONCAT(a.first_name, ' ', a.last_name) ORDER BY a.last_name, a.first_name SEPARATOR ', ')
     FROM delegation_flight_movement_members fm JOIN athletes a ON a.id=fm.athlete_id WHERE fm.movement_id=f.id) AS delegate_names
    FROM users u LEFT JOIN delegation_flight_movements f ON f.country_id=u.id
    WHERE u.role='country_manager' ORDER BY u.country_name, f.direction, f.flight_datetime, f.id");
$delegations=[]; foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){$id=(int)$row['country_id'];if(!isset($delegations[$id]))$delegations[$id]=['country_name'=>$row['country_name'],'username'=>$row['username'],'arrival'=>[],'departure'=>[]];if(!empty($row['id']))$delegations[$id][$row['direction']][]=$row;}
$submitted=count(array_filter($delegations,fn($d)=>$d['arrival']!==[]&&$d['departure']!==[]));
require_once 'includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap align-items-center pb-2 mb-3 border-bottom"><div><h1 class="h2 mb-1">Flight Details</h1><p class="text-muted mb-0">Separate arrival and departure groups submitted by each delegation.</p></div></div>
<div class="row g-3 mb-3"><div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-muted small text-uppercase fw-bold">Complete</div><div class="display-6 text-success"><?php echo $submitted; ?></div></div></div></div><div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-muted small text-uppercase fw-bold">Pending</div><div class="display-6 text-warning"><?php echo count($delegations)-$submitted; ?></div></div></div></div></div>
<?php foreach($delegations as $delegation): ?><div class="card shadow-sm mb-3"><div class="card-header d-flex justify-content-between"><span><?php echo htmlspecialchars($delegation['country_name']?:'Unassigned Country'); ?> <small class="text-muted fw-normal">(<?php echo htmlspecialchars($delegation['username']); ?>)</small></span><span><span class="badge text-bg-primary"><?php echo array_sum(array_column($delegation['arrival'],'pax')); ?> arriving</span> <span class="badge text-bg-success"><?php echo array_sum(array_column($delegation['departure'],'pax')); ?> departing</span></span></div><div class="card-body"><div class="row g-3">
<?php foreach(['arrival'=>'Arrival Groups','departure'=>'Departure Groups'] as $direction=>$label): ?><div class="col-xl-6"><h6><?php echo $label; ?></h6><?php if($delegation[$direction]): ?><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Group</th><th>Delegates</th><th>Pax</th><th>Flight</th><th>Date &amp; Time</th></tr></thead><tbody><?php foreach($delegation[$direction] as $index=>$movement): ?><tr><td><?php echo $index+1; ?></td><td><?php echo htmlspecialchars($movement['delegate_names'] ?: 'Not assigned'); ?></td><td class="fw-semibold"><?php echo (int)$movement['pax']; ?></td><td class="fw-semibold"><?php echo htmlspecialchars($movement['flight_number']); ?></td><td><?php echo date('d M Y, H:i',strtotime($movement['flight_datetime'])); ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="text-muted small">Not submitted.</div><?php endif; ?></div><?php endforeach; ?>
</div></div></div><?php endforeach; ?>
<?php if(!$delegations): ?><div class="card"><div class="card-body text-muted text-center">No delegations found.</div></div><?php endif; ?>
<?php require_once 'includes/footer.php'; ?>
