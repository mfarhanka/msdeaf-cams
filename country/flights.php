<?php
require_once 'includes/auth.php';
require_once '../includes/flights.php';
ensureDelegationFlightsTable($pdo);
$countryId = (int) $_SESSION['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_flights') {
    $movements = [];
    $validationError = '';
    foreach (['arrival', 'departure'] as $direction) {
        $assignedParticipantIds = [];
        $participantValues = $_POST[$direction . '_participants'] ?? [];
        $flightNumbers = $_POST[$direction . '_flight_number'] ?? [];
        $dateTimes = $_POST[$direction . '_datetime'] ?? [];
        if ($participantValues === []) continue;
        foreach ($participantValues as $index => $participantValue) {
            $participantIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $participantValue)))));
            $flightNumber = strtoupper(trim((string) ($flightNumbers[$index] ?? '')));
            $timestamp = strtotime((string) ($dateTimes[$index] ?? ''));
            if ($participantIds === [] || $flightNumber === '' || $timestamp === false) {
                $validationError = 'Select at least one participant and complete every flight field.';
                break 2;
            }
            if (array_intersect($assignedParticipantIds, $participantIds) !== []) {
                $validationError = 'A delegate cannot appear in more than one ' . $direction . ' group.';
                break 2;
            }
            $assignedParticipantIds = array_merge($assignedParticipantIds, $participantIds);
            $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
            $ownershipStmt = $pdo->prepare("SELECT COUNT(*) FROM athletes WHERE country_id = ? AND id IN ($placeholders)");
            $ownershipStmt->execute(array_merge([$countryId], $participantIds));
            if ((int) $ownershipStmt->fetchColumn() !== count($participantIds)) {
                $validationError = 'One or more selected participants are invalid.';
                break 2;
            }
            $movements[] = [$direction, $participantIds, $flightNumber, date('Y-m-d H:i:s', $timestamp)];
        }
    }

    if ($movements === [] && $validationError === '') {
        $validationError = 'Add at least one arrival or departure group.';
    }

    if ($validationError !== '') {
        $msg = "<div class='alert alert-warning alert-dismissible fade show'>" . htmlspecialchars($validationError) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM delegation_flight_movements WHERE country_id = ?')->execute([$countryId]);
            $insert = $pdo->prepare('INSERT INTO delegation_flight_movements (country_id, direction, pax, flight_number, flight_datetime) VALUES (?, ?, ?, ?, ?)');
            $insertMember = $pdo->prepare('INSERT INTO delegation_flight_movement_members (movement_id, athlete_id) VALUES (?, ?)');
            foreach ($movements as $movement) {
                [$direction, $participantIds, $flightNumber, $flightDatetime] = $movement;
                $insert->execute([$countryId, $direction, count($participantIds), $flightNumber, $flightDatetime]);
                $movementId = (int) $pdo->lastInsertId();
                foreach ($participantIds as $participantId) $insertMember->execute([$movementId, $participantId]);
            }
            $pdo->commit();
            $msg = "<div class='alert alert-success alert-dismissible fade show'><i class='bi bi-check-circle me-1'></i>Flight details updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } catch (Throwable $error) {
            $pdo->rollBack();
            error_log('Flight details save failed: ' . $error->getMessage());
            $msg = "<div class='alert alert-danger alert-dismissible fade show'>Unable to save flight details. Please try again or contact the administrator.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    }
}

$participantsStmt = $pdo->prepare("SELECT id, first_name, last_name, participant_type FROM athletes WHERE country_id = ? ORDER BY participant_type, last_name, first_name");
$participantsStmt->execute([$countryId]);
$participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);
$participantNames = [];
foreach ($participants as $participant) $participantNames[(int) $participant['id']] = trim($participant['first_name'] . ' ' . $participant['last_name']);

$stmt = $pdo->prepare("SELECT f.*, GROUP_CONCAT(fm.athlete_id ORDER BY fm.athlete_id) AS participant_ids
    FROM delegation_flight_movements f LEFT JOIN delegation_flight_movement_members fm ON fm.movement_id=f.id
    WHERE f.country_id=? GROUP BY f.id ORDER BY f.direction, f.flight_datetime, f.id");
$stmt->execute([$countryId]);
$saved = ['arrival' => [], 'departure' => []];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $saved[$row['direction']][] = $row;
function flightInputDate(?string $value): string { return empty($value) ? '' : date('Y-m-d\TH:i', strtotime($value)); }
require_once 'includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap align-items-center pb-2 mb-3 border-bottom"><div><h1 class="h2 mb-1">Flight Details</h1><p class="text-muted mb-0">Select the delegation members travelling in each group.</p></div></div>
<?php if (!$participants): ?><div class="alert alert-warning">Add athletes or officials before creating flight groups.</div><?php endif; ?>
<form method="POST" id="flightDetailsForm"><input type="hidden" name="action" value="save_flights">
<?php foreach (['arrival' => ['Arrival Groups', 'bi-airplane-engines', 'primary'], 'departure' => ['Departure Groups', 'bi-airplane', 'success']] as $direction => $details): ?>
<section class="card shadow-sm mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi <?php echo $details[1]; ?> me-2"></i><?php echo $details[0]; ?></span><button type="button" class="btn btn-sm btn-outline-<?php echo $details[2]; ?> open-movement-modal" data-direction="<?php echo $direction; ?>" <?php echo !$participants ? 'disabled' : ''; ?>><i class="bi bi-plus-lg me-1"></i>Add Group</button></div><div class="card-body"><div class="accordion" id="<?php echo $direction; ?>Groups">
<?php foreach ($saved[$direction] as $index => $row): $ids=array_values(array_filter(array_map('intval',explode(',',(string)$row['participant_ids'])))); $names=array_values(array_intersect_key($participantNames,array_flip($ids))); $collapseId=$direction.'Flight'.$index; ?><div class="accordion-item movement-group"><h2 class="accordion-header"><button class="accordion-button<?php echo $index?' collapsed':''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>"><span class="me-2">Group <span class="group-number"><?php echo $index+1; ?></span>:</span><strong class="flight-display me-3"><?php echo htmlspecialchars($row['flight_number']?:'Not entered'); ?></strong><span class="datetime-display text-muted small me-3"><?php echo empty($row['flight_datetime'])?'Not entered':date('d M Y, H:i',strtotime($row['flight_datetime'])); ?></span><span class="badge text-bg-secondary"><span class="pax-display"><?php echo count($ids); ?></span> pax</span></button></h2><div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse<?php echo $index===0?' show':''; ?>"><div class="accordion-body"><input type="hidden" class="participants-value" name="<?php echo $direction; ?>_participants[]" value="<?php echo htmlspecialchars(implode(',',$ids)); ?>"><input type="hidden" class="flight-value" name="<?php echo $direction; ?>_flight_number[]" value="<?php echo htmlspecialchars($row['flight_number']); ?>"><input type="hidden" class="datetime-value" name="<?php echo $direction; ?>_datetime[]" value="<?php echo htmlspecialchars(flightInputDate($row['flight_datetime'])); ?>"><div class="small text-muted mb-1">Selected delegates</div><div class="participants-display mb-3"><?php echo htmlspecialchars($names?implode(', ',$names):'Select delegates'); ?></div><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-primary edit-movement"><i class="bi bi-pencil me-1"></i>Edit</button><button type="button" class="btn btn-sm btn-outline-danger remove-movement"><i class="bi bi-trash me-1"></i>Remove</button></div></div></div></div><?php endforeach; ?><?php if ($saved[$direction] === []): ?><div class="text-muted text-center py-3 empty-groups">No <?php echo $direction; ?> groups added yet.</div><?php endif; ?>
</div></div></section><?php endforeach; ?><div class="d-flex justify-content-end"><button class="btn btn-primary" type="submit" <?php echo !$participants ? 'disabled' : ''; ?>><i class="bi bi-save me-1"></i>Save Flight Details</button></div></form>

<div class="modal fade" id="movementModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="movementModalTitle">Add Flight Group</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="movementModalForm"><div class="row g-3"><div class="col-md-6"><label class="form-label">Flight Number</label><input type="text" class="form-control" id="modalFlight" maxlength="30" placeholder="e.g. MH123" required></div><div class="col-md-6"><label class="form-label" id="modalDatetimeLabel">Date &amp; Time</label><input type="datetime-local" class="form-control" id="modalDatetime" required></div><div class="col-12"><div class="d-flex justify-content-between"><label class="form-label">Delegates</label><span class="badge text-bg-primary" id="selectedPax">0 pax</span></div><div class="accordion" id="participantAccordion">
<?php foreach (['athlete'=>'Athletes','official'=>'Officials'] as $type=>$typeLabel): $typeParticipants=array_filter($participants,fn($p)=>($p['participant_type']??'athlete')===$type); ?><div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button<?php echo $type==='official'?' collapsed':''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#participants<?php echo ucfirst($type); ?>"><?php echo $typeLabel; ?> (<?php echo count($typeParticipants); ?>)</button></h2><div id="participants<?php echo ucfirst($type); ?>" class="accordion-collapse collapse<?php echo $type==='athlete'?' show':''; ?>" data-bs-parent="#participantAccordion"><div class="accordion-body"><div class="row g-2"><?php foreach($typeParticipants as $participant): ?><div class="col-sm-6"><label class="border rounded p-2 w-100"><input class="form-check-input me-2 participant-check" type="checkbox" value="<?php echo (int)$participant['id']; ?>" data-name="<?php echo htmlspecialchars(trim($participant['first_name'].' '.$participant['last_name'])); ?>"><?php echo htmlspecialchars(trim($participant['first_name'].' '.$participant['last_name'])); ?><span class="assignment-note badge text-bg-secondary ms-2 d-none"></span></label></div><?php endforeach; ?><?php if(!$typeParticipants): ?><div class="text-muted small">No <?php echo strtolower($typeLabel); ?> registered.</div><?php endif; ?></div></div></div></div><?php endforeach; ?>
</div><div class="invalid-feedback d-block d-none" id="participantError">Select at least one delegate.</div></div></div></form></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="saveMovement">Save Group</button></div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded',function(){var modal=new bootstrap.Modal(document.getElementById('movementModal')),direction='',editingRow=null,flight=document.getElementById('modalFlight'),datetime=document.getElementById('modalDatetime'),checks=Array.from(document.querySelectorAll('.participant-check')),modalForm=document.getElementById('movementModalForm'),nextId=1000;function label(v){return v.charAt(0).toUpperCase()+v.slice(1);}function selected(){return checks.filter(function(c){return c.checked;});}function updatePax(){document.getElementById('selectedPax').textContent=selected().length+' pax';document.getElementById('participantError').classList.add('d-none');}checks.forEach(function(c){c.addEventListener('change',updatePax);});function renumber(c){c.querySelectorAll('.group-number').forEach(function(n,i){n.textContent=i+1;});}function showEmpty(c){if(!c.querySelector('.movement-group')&&!c.querySelector('.empty-groups')){var empty=document.createElement('div');empty.className='text-muted text-center py-3 empty-groups';empty.textContent='No '+c.id.replace('Groups','')+' groups added yet.';c.appendChild(empty);}}function formatDate(v){return new Date(v).toLocaleString('en-MY',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:false});}function openEditor(d,row){direction=d;editingRow=row||null;var ids=row?row.querySelector('.participants-value').value.split(','):[],assigned=new Map();document.getElementById(d+'Groups').querySelectorAll('.movement-group').forEach(function(group){if(group===row)return;var assignedFlight=group.querySelector('.flight-value').value||'another flight';group.querySelector('.participants-value').value.split(',').filter(Boolean).forEach(function(id){assigned.set(id,assignedFlight);});});checks.forEach(function(c){c.checked=ids.includes(c.value);c.disabled=assigned.has(c.value);var wrapper=c.closest('label'),note=wrapper.querySelector('.assignment-note');wrapper.classList.toggle('text-muted',c.disabled);wrapper.classList.toggle('bg-light',c.disabled);note.textContent=c.disabled?'- '+assigned.get(c.value):'';note.classList.toggle('d-none',!c.disabled);});document.querySelectorAll('#participantAccordion .row').forEach(function(list){var items=Array.from(list.querySelectorAll('.col-sm-6'));items.sort(function(a,b){return Number(a.querySelector('.participant-check').disabled)-Number(b.querySelector('.participant-check').disabled);});items.forEach(function(item){list.appendChild(item);});});flight.value=row?row.querySelector('.flight-value').value:'';datetime.value=row?row.querySelector('.datetime-value').value:'';document.getElementById('movementModalTitle').textContent=(row?'Edit ':'Add ')+label(d)+' Group';document.getElementById('modalDatetimeLabel').textContent=label(d)+' Date & Time';updatePax();modal.show();}document.querySelectorAll('.open-movement-modal').forEach(function(b){b.addEventListener('click',function(){openEditor(this.dataset.direction,null);});});document.addEventListener('click',function(e){var edit=e.target.closest('.edit-movement');if(edit){var item=edit.closest('.movement-group');openEditor(item.closest('[id$="Groups"]').id.replace('Groups',''),item);return;}var remove=e.target.closest('.remove-movement');if(!remove)return;var item=remove.closest('.movement-group'),container=item.closest('[id$="Groups"]');item.remove();renumber(container);showEmpty(container);});document.getElementById('saveMovement').addEventListener('click',function(){var chosen=selected();if(!modalForm.reportValidity())return;if(!chosen.length){document.getElementById('participantError').classList.remove('d-none');return;}var item=editingRow;if(!item){var collapseId='newFlight'+nextId++,container=document.getElementById(direction+'Groups'),empty=container.querySelector('.empty-groups');if(empty)empty.remove();item=document.createElement('div');item.className='accordion-item movement-group';item.innerHTML='<h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#'+collapseId+'"><span class="me-2">Group <span class="group-number"></span>:</span><strong class="flight-display me-3"></strong><span class="datetime-display text-muted small me-3"></span><span class="badge text-bg-secondary"><span class="pax-display"></span> pax</span></button></h2><div id="'+collapseId+'" class="accordion-collapse collapse show"><div class="accordion-body"><input type="hidden" class="participants-value"><input type="hidden" class="flight-value"><input type="hidden" class="datetime-value"><div class="small text-muted mb-1">Selected delegates</div><div class="participants-display mb-3"></div><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-primary edit-movement"><i class="bi bi-pencil me-1"></i>Edit</button><button type="button" class="btn btn-sm btn-outline-danger remove-movement"><i class="bi bi-trash me-1"></i>Remove</button></div></div></div>';container.appendChild(item);}var ids=chosen.map(function(c){return c.value;}),names=chosen.map(function(c){return c.dataset.name;});item.querySelector('.participants-value').name=direction+'_participants[]';item.querySelector('.flight-value').name=direction+'_flight_number[]';item.querySelector('.datetime-value').name=direction+'_datetime[]';item.querySelector('.participants-value').value=ids.join(',');item.querySelector('.flight-value').value=flight.value.trim().toUpperCase();item.querySelector('.datetime-value').value=datetime.value;item.querySelector('.participants-display').textContent=names.join(', ');item.querySelector('.pax-display').textContent=ids.length;item.querySelector('.flight-display').textContent=flight.value.trim().toUpperCase();item.querySelector('.datetime-display').textContent=formatDate(datetime.value);renumber(item.closest('[id$="Groups"]'));modal.hide();});});
</script>
<?php require_once 'includes/footer.php'; ?>
