<?php
require_once 'includes/auth.php';
require_once '../includes/invoices.php';
ensureInvoiceSchema($pdo);

$countryId = $_SESSION['id'];
if(empty($_SESSION['payment_csrf']))$_SESSION['payment_csrf']=bin2hex(random_bytes(32));
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='upload_payment_slip'){
    try{
        $token=$_POST['csrf_token']??'';if(!is_string($token)||!hash_equals($_SESSION['payment_csrf'],$token))throw new RuntimeException('The request expired. Please try again.');
        $invoiceId=(int)($_POST['invoice_id']??0);$paymentType=(string)($_POST['payment_type']??'');if(!in_array($paymentType,['deposit','balance','full'],true))throw new RuntimeException('Please choose a valid payment type.');$invoiceStmt=$pdo->prepare('SELECT snapshot_json FROM invoices WHERE id=? AND country_id=?');$invoiceStmt->execute([$invoiceId,$countryId]);$snapshotJson=$invoiceStmt->fetchColumn();if($snapshotJson===false)throw new RuntimeException('Invoice not found.');
        $file=$_FILES['payment_slip']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Please select a payment slip to upload.');if((int)$file['size']>8*1024*1024)throw new RuntimeException('Payment slip must not exceed 8 MB.');
        $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);$allowed=['application/pdf','image/jpeg','image/png'];if(!in_array($mime,$allowed,true))throw new RuntimeException('Payment slip must be a PDF, JPEG, or PNG file.');
        $state=getInvoicePaymentState($pdo,$invoiceId);if($state['status']==='Fully Paid')throw new RuntimeException('This invoice is already fully paid.');if(isset($state['pending'][$paymentType])||isset($state['accepted'][$paymentType]))throw new RuntimeException('A pending or accepted '.$paymentType.' payment already exists.');if($paymentType==='balance'&&!isset($state['accepted']['deposit']))throw new RuntimeException('The deposit must be accepted before uploading the balance slip.');if($paymentType==='full'&&(isset($state['accepted']['deposit'])||isset($state['pending']['deposit'])))throw new RuntimeException('After choosing the deposit path, upload the balance payment later.');if($paymentType==='deposit'&&(isset($state['accepted']['full'])||isset($state['pending']['full'])))throw new RuntimeException('A full payment is already pending or accepted.');
        $snapshot=json_decode($snapshotJson,true,512,JSON_THROW_ON_ERROR);$amounts=getInvoicePaymentAmounts($snapshot);$amount=$amounts[$paymentType];$data=file_get_contents($file['tmp_name']);$stmt=$pdo->prepare('INSERT INTO invoice_payments(invoice_id,country_id,amount,payment_type,original_filename,mime_type,slip_data) VALUES(?,?,?,?,?,?,?)');$stmt->bindValue(1,$invoiceId,PDO::PARAM_INT);$stmt->bindValue(2,$countryId,PDO::PARAM_INT);$stmt->bindValue(3,$amount);$stmt->bindValue(4,$paymentType);$stmt->bindValue(5,substr(basename($file['name']),0,255));$stmt->bindValue(6,$mime);$stmt->bindValue(7,$data,PDO::PARAM_LOB);$stmt->execute();
        $actor=getActorDetailsFromSession();recordActivity($pdo,'upload_payment_slip','invoice_payment',(int)$pdo->lastInsertId(),'Uploaded a '.$paymentType.' payment slip',['invoice_id'=>$invoiceId,'payment_type'=>$paymentType,'amount'=>$amount],$actor['id'],$actor['role'],$actor['username']);$msg='<div class="alert alert-success">'.htmlspecialchars(ucfirst($paymentType)).' payment slip uploaded for admin review.</div>';
    }catch(Throwable $e){$msg='<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>';}
}

$roomStmt = $pdo->prepare("SELECT COALESCE(SUM(rooms_reserved), 0) FROM bookings WHERE country_id = ? AND status <> 'Cancelled'");
$roomStmt->execute([$countryId]);
$roomsBooked = $roomStmt->fetchColumn();

$financeStmt = $pdo->prepare("SELECT
    b.id,
    b.status,
    b.rooms_reserved,
    c.title AS championship_title,
    c.start_date,
    c.end_date,
    b.booking_start_date,
    b.booking_end_date,
    h.name AS hotel_name,
    rt.name AS room_type_name,
    rt.capacity,
    rt.price_per_night,
    COALESCE(assignment_totals.assigned_athletes, 0) AS assigned_athletes,
    DATEDIFF(b.booking_end_date, b.booking_start_date) AS booking_days
    FROM bookings b
    JOIN championships c ON b.championship_id = c.id
    JOIN hotels h ON b.hotel_id = h.id
    JOIN room_types rt ON b.room_type_id = rt.id
    LEFT JOIN (
        SELECT booking_id, COUNT(*) AS assigned_athletes
        FROM room_assignments
        GROUP BY booking_id
    ) assignment_totals ON assignment_totals.booking_id = b.id
    WHERE b.country_id = ?
        AND b.status <> 'Cancelled'
    ORDER BY c.start_date ASC, h.name ASC, rt.name ASC");
$financeStmt->execute([$countryId]);
$financeRows = $financeStmt->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = 0;
foreach ($financeRows as &$financeRow) {
    $chargedPax = max(0, intval($financeRow['rooms_reserved'])) * max(1, intval($financeRow['capacity']));
    $lineTotal = $chargedPax * floatval($financeRow['price_per_night']) * max(1, intval($financeRow['booking_days']));
    $financeRow['charged_pax'] = $chargedPax;
    $financeRow['line_total'] = $lineTotal;
    $grandTotal += $lineTotal;
}
unset($financeRow);

$paymentAccount = [
    'Bank Account' => 'CIMB Bank Berhad',
    'Bank Name' => 'PERSATUAN SUKAN ORANG PEKAK MALAYSIA',
    'Account No' => '8000852319',
    'Branch Name' => 'WISMA KOPONAS, KUALA LUMPUR',
    'Swift Code' => 'CIBBMYKL',
    'Branch Code' => '1426',
];

$participantsStmt = $pdo->prepare("SELECT a.first_name, a.last_name, a.gender,
    ra.room_number,
    c.title AS championship_title,
    h.name AS hotel_name,
    rt.name AS room_type_name,
    b.rooms_reserved
    FROM athletes a
    LEFT JOIN room_assignments ra ON ra.athlete_id = a.id
    LEFT JOIN bookings b ON b.id = ra.booking_id AND b.status <> 'Cancelled'
    LEFT JOIN championships c ON c.id = b.championship_id
    LEFT JOIN hotels h ON h.id = b.hotel_id
    LEFT JOIN room_types rt ON rt.id = b.room_type_id
    WHERE a.country_id = ?
    ORDER BY a.last_name ASC, a.first_name ASC");
$participantsStmt->execute([$countryId]);
$participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);

$invoiceStmt = $pdo->prepare("SELECT id, invoice_number, issued_at, currency, total_amount, revision_of_id, snapshot_json FROM invoices WHERE country_id = ? ORDER BY issued_at DESC, id DESC");
$invoiceStmt->execute([$countryId]);
$invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
foreach($invoices as &$issuedInvoice){$issuedSnapshot=json_decode($issuedInvoice['snapshot_json'],true);$issuedInvoice['payment_amounts']=is_array($issuedSnapshot)?getInvoicePaymentAmounts($issuedSnapshot):['deposit'=>round((float)$issuedInvoice['total_amount']*.70,2),'balance'=>round((float)$issuedInvoice['total_amount']*.30,2),'full'=>(float)$issuedInvoice['total_amount']];$issuedInvoice['deposit_percent']=(float)$issuedInvoice['total_amount']>0?round($issuedInvoice['payment_amounts']['deposit']/(float)$issuedInvoice['total_amount']*100):70;$issuedInvoice['payment_state']=getInvoicePaymentState($pdo,(int)$issuedInvoice['id']);}unset($issuedInvoice);
$paymentStmt=$pdo->prepare('SELECT id,invoice_id,amount,payment_type,status,admin_note,created_at,paid_invoice_generated_at FROM invoice_payments WHERE country_id=? ORDER BY created_at DESC,id DESC');$paymentStmt->execute([$countryId]);$paymentsByInvoice=[];foreach($paymentStmt->fetchAll(PDO::FETCH_ASSOC) as $payment){$paymentsByInvoice[(int)$payment['invoice_id']][]=$payment;}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h2">Financial Summary</h1>
    <?php if ($invoices !== []): ?><a class="btn btn-primary" href="invoice_download.php?id=<?php echo (int)$invoices[0]['id']; ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Download Latest Invoice</a><?php endif; ?>
</div>

<?php if ($invoices !== []): ?>
<div class="card shadow-sm mb-3"><div class="card-header">Issued Invoices and Payments</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Invoice</th><th>Amounts</th><th>Payment Status</th><th>Payment History</th><th>Upload / Downloads</th></tr></thead><tbody>
<?php foreach($invoices as $invoice):$payments=$paymentsByInvoice[(int)$invoice['id']]??[];$state=$invoice['payment_state'];$amounts=$invoice['payment_amounts'];$depositPercent=$invoice['deposit_percent'];$available=[];if($state['status']==='Unpaid'&&!isset($state['pending']['deposit'])&&!isset($state['pending']['full'])){$available['deposit']='Deposit '.number_format($depositPercent,0).'% - USD $ '.number_format($amounts['deposit'],2);$available['full']='Full Payment - USD $ '.number_format($amounts['full'],2);}elseif($state['status']==='Deposit Paid'&&!isset($state['pending']['balance'])){$available['balance']='Balance '.number_format(100-$depositPercent,0).'% - USD $ '.number_format($amounts['balance'],2);} ?>
<tr><td><div class="fw-semibold"><?php echo htmlspecialchars($invoice['invoice_number']); ?></div><div class="small text-muted"><?php echo htmlspecialchars(date('d M Y H:i',strtotime($invoice['issued_at']))); ?></div></td><td><div>Total: USD $ <?php echo number_format((float)$invoice['total_amount'],2); ?></div><div class="small text-muted">Deposit: $ <?php echo number_format($amounts['deposit'],2); ?> / Balance: $ <?php echo number_format($amounts['balance'],2); ?></div></td><td><span class="badge <?php echo $state['status']==='Fully Paid'?'text-bg-success':($state['status']==='Deposit Paid'?'text-bg-info':'text-bg-secondary'); ?>"><?php echo htmlspecialchars($state['status']); ?></span><div class="small text-muted">Accepted: USD $ <?php echo number_format((float)$state['total_paid'],2); ?></div></td><td><?php if($payments===[]):?><span class="text-muted">No slips submitted</span><?php endif;?><?php foreach($payments as $payment):?><div class="mb-1"><strong><?php echo htmlspecialchars(ucfirst($payment['payment_type'])); ?></strong> <span class="badge <?php echo $payment['status']==='Accepted'?'text-bg-success':($payment['status']==='Rejected'?'text-bg-danger':'text-bg-warning'); ?>"><?php echo htmlspecialchars($payment['status']); ?></span> <a class="small" href="payment_slip_download.php?id=<?php echo (int)$payment['id']; ?>">Slip</a><?php if($payment['admin_note']):?><div class="small text-muted"><?php echo htmlspecialchars($payment['admin_note']); ?></div><?php endif;?></div><?php endforeach;?></td><td><div class="d-flex flex-column gap-1"><a class="btn btn-sm btn-outline-primary" href="invoice_download.php?id=<?php echo (int)$invoice['id']; ?>">Proforma PDF</a><?php foreach($payments as $payment):if(!$payment['paid_invoice_generated_at'])continue;?><a class="btn btn-sm btn-success" href="paid_invoice_download.php?id=<?php echo (int)$payment['id']; ?>"><?php echo htmlspecialchars(ucfirst($payment['payment_type'])); ?> Paid Invoice</a><?php endforeach;?><?php if($available!==[]):?><form method="post" enctype="multipart/form-data" class="border rounded p-2 mt-1"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['payment_csrf']); ?>"><input type="hidden" name="action" value="upload_payment_slip"><input type="hidden" name="invoice_id" value="<?php echo (int)$invoice['id']; ?>"><select class="form-select form-select-sm mb-1" name="payment_type" required><?php foreach($available as $value=>$label):?><option value="<?php echo $value; ?>"><?php echo htmlspecialchars($label); ?></option><?php endforeach;?></select><input class="form-control form-control-sm mb-1" type="file" name="payment_slip" accept="application/pdf,image/jpeg,image/png" required><button class="btn btn-sm btn-primary w-100">Upload Payment Slip</button></form><?php endif;?></div></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm border-success mb-3">
            <div class="card-body">
                <div class="text-muted small mb-1">Total Amount</div>
                <div class="fs-4 fw-bold text-success">$<?php echo number_format($grandTotal, 2); ?></div>
                <div class="small text-muted">Calculated as reserved rooms x room capacity x rate per pax per day x selected stay days</div>
            </div>
        </div>
        <div class="card shadow-sm border-primary">
            <div class="card-body">
                <div class="text-muted small mb-1">Total Rooms Booked</div>
                <div class="fs-4 fw-bold text-primary"><?php echo (int) $roomsBooked; ?></div>
                <div class="small text-muted">All active room reservations for this delegation</div>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card shadow-sm border-info h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-bank text-info fs-5" aria-hidden="true"></i>
                    <div class="fw-bold">Payment Account</div>
                </div>
                <dl class="row small mb-0">
                    <?php foreach ($paymentAccount as $label => $value): ?>
                        <dt class="col-5 text-muted fw-semibold"><?php echo htmlspecialchars($label); ?></dt>
                        <dd class="col-7 mb-1"><?php echo htmlspecialchars($value); ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if (count($financeRows) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Championship</th>
                            <th>Stay Dates</th>
                            <th>Hotel / Room Type</th>
                            <th>Charged Pax</th>
                            <th>Assigned Pax</th>
                            <th>Days</th>
                            <th>Rate / Pax / Day</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($financeRows as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['championship_title']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars(date('M d, Y', strtotime($row['start_date'])) . ' - ' . date('M d, Y', strtotime($row['end_date']))); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars(date('M d, Y', strtotime($row['booking_start_date'])) . ' - ' . date('M d, Y', strtotime($row['booking_end_date']))); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['hotel_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['room_type_name'] . ' (' . $row['capacity'] . ' pax/room)'); ?></div>
                                </td>
                                <td><?php echo intval($row['charged_pax']); ?></td>
                                <td><?php echo intval($row['assigned_athletes']); ?></td>
                                <td><?php echo max(1, intval($row['booking_days'])); ?></td>
                                <td>$<?php echo number_format($row['price_per_night'], 2); ?></td>
                                <td class="fw-bold text-success">$<?php echo number_format($row['line_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted text-center py-5 mb-0">No active reservations yet, so there is no charge to calculate.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="card-title mb-1">Participating Athletes</h5>
                <p class="text-muted small mb-0">Delegation participants with their current room booking status.</p>
            </div>
            <a href="rooming.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-door-open me-1"></i>Manage Rooming
            </a>
        </div>

        <?php if (count($participants) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Participant</th>
                            <th>Gender</th>
                            <th>Room Booked</th>
                            <th>Total Rooms Booked</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participants as $participant): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(trim($participant['first_name'] . ' ' . $participant['last_name'])); ?></td>
                                <td><?php echo htmlspecialchars($participant['gender']); ?></td>
                                <td>
                                    <?php if (!empty($participant['room_number'])): ?>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($participant['room_number']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($participant['championship_title'] ?? ''); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars(trim(($participant['hotel_name'] ?? '') . ' / ' . ($participant['room_type_name'] ?? ''))); ?></div>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($participant['rooms_reserved']) ? (int) $participant['rooms_reserved'] : 0; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No participants registered yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>
