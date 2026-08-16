<?php
require_once 'includes/auth.php';
require_once '../includes/invoices.php';
ensureInvoiceSchema($pdo);

if (empty($_SESSION['invoice_csrf'])) $_SESSION['invoice_csrf'] = bin2hex(random_bytes(32));

function requireInvoiceCsrf(): void {
    $token=$_POST['csrf_token']??'';
    if(!is_string($token)||!hash_equals($_SESSION['invoice_csrf']??'',$token)) throw new RuntimeException('The request expired. Please try again.');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try {
        requireInvoiceCsrf(); $action=$_POST['action']??''; $countryId=(int)($_POST['country_id']??0); $actor=getActorDetailsFromSession();
        if($action==='issue'||$action==='revise'){
            $revision=$action==='revise'?(int)($_POST['revision_of_id']??0):null;
            $invoiceId=issueDelegationInvoice($pdo,$countryId,(int)$_SESSION['id'],$revision?:null);
            recordActivity($pdo,'issue_invoice','invoice',$invoiceId,'Issued a delegation invoice',['country_id'=>$countryId,'revision_of'=>$revision],$actor['id'],$actor['role'],$actor['username']);
            $_SESSION['invoice_notice']='Invoice issued successfully.'; header('Location: invoices.php'); exit;
        }
        if($action==='issue_all'){
            if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZIP extension is not available.');
            $eligible=$pdo->query("SELECT u.id FROM users u WHERE u.role='country_manager' AND (EXISTS(SELECT 1 FROM athletes a WHERE a.country_id=u.id) OR EXISTS(SELECT 1 FROM bookings b WHERE b.country_id=u.id AND b.status<>'Cancelled')) ORDER BY u.country_name")->fetchAll(PDO::FETCH_COLUMN);
            $tmp=tempnam(sys_get_temp_dir(),'cams_invoice_');$zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create invoice archive.');$count=0;
            foreach($eligible as $id){$invoiceId=issueDelegationInvoice($pdo,(int)$id,(int)$_SESSION['id']);$q=$pdo->prepare('SELECT invoice_number,pdf_data FROM invoices WHERE id=?');$q->execute([$invoiceId]);$inv=$q->fetch(PDO::FETCH_ASSOC);$zip->addFromString(preg_replace('/[^A-Za-z0-9._-]/','-',$inv['invoice_number']).'.pdf',$inv['pdf_data']);$count++;}
            $zip->close();recordActivity($pdo,'issue_invoices_bulk','invoice',null,'Issued delegation invoices in bulk',['count'=>$count],$actor['id'],$actor['role'],$actor['username']);
            header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="delegation-invoices-'.date('Y-m-d-His').'.zip"');header('Content-Length: '.filesize($tmp));readfile($tmp);unlink($tmp);exit;
        }
        if($action==='delete'){
            $invoiceId=(int)($_POST['invoice_id']??0);
            $find=$pdo->prepare('SELECT invoice_number,country_id FROM invoices WHERE id=?');$find->execute([$invoiceId]);$invoice=$find->fetch(PDO::FETCH_ASSOC);
            if(!$invoice)throw new RuntimeException('Invoice not found.');
            $delete=$pdo->prepare('DELETE FROM invoices WHERE id=?');$delete->execute([$invoiceId]);
            recordActivity($pdo,'delete_invoice','invoice',$invoiceId,'Deleted a generated delegation invoice',['invoice_number'=>$invoice['invoice_number'],'country_id'=>(int)$invoice['country_id']],$actor['id'],$actor['role'],$actor['username']);
            $_SESSION['invoice_notice']='Invoice '.$invoice['invoice_number'].' was removed.';header('Location: invoices.php');exit;
        }
    }catch(Throwable $e){$msg='<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>';}
}
if(isset($_SESSION['invoice_notice'])){$msg='<div class="alert alert-success">'.htmlspecialchars($_SESSION['invoice_notice']).'</div>';unset($_SESSION['invoice_notice']);}
$delegations=$pdo->query("SELECT u.id,u.country_name,u.username,(SELECT COUNT(*) FROM athletes a WHERE a.country_id=u.id) participant_count,(SELECT COUNT(*) FROM bookings b WHERE b.country_id=u.id AND b.status<>'Cancelled') booking_count,(SELECT i.id FROM invoices i WHERE i.country_id=u.id ORDER BY i.issued_at DESC,i.id DESC LIMIT 1) latest_invoice_id,(SELECT i.invoice_number FROM invoices i WHERE i.country_id=u.id ORDER BY i.issued_at DESC,i.id DESC LIMIT 1) latest_invoice_number FROM users u WHERE u.role='country_manager' ORDER BY u.country_name,u.username")->fetchAll(PDO::FETCH_ASSOC);
$history=$pdo->query("SELECT i.id,i.invoice_number,i.country_id,i.issued_at,i.total_amount,i.currency,i.revision_of_id,u.country_name,u.username FROM invoices i JOIN users u ON u.id=i.country_id ORDER BY i.issued_at DESC,i.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
require_once 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3"><div><h1 class="h2 mb-1">Proforma Invoices</h1><p class="text-muted mb-0">Issue immutable invoice snapshots for country delegations.</p></div><form method="post" onsubmit="return confirm('Issue a new invoice for every eligible delegation?');"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['invoice_csrf'])?>"><input type="hidden" name="action" value="issue_all"><button class="btn btn-primary"><i class="bi bi-file-earmark-zip me-1"></i>Issue All &amp; Download ZIP</button></form></div>
<?=$msg??''?>
<div class="card shadow-sm mb-3"><div class="card-header">Delegations</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Delegation</th><th>Participants</th><th>Bookings</th><th>Latest Invoice</th><th class="text-end">Action</th></tr></thead><tbody>
<?php foreach($delegations as $d):$eligible=(int)$d['participant_count']>0||(int)$d['booking_count']>0;?><tr><td class="fw-semibold"><?=htmlspecialchars($d['country_name']?:$d['username'])?></td><td><?=(int)$d['participant_count']?></td><td><?=(int)$d['booking_count']?></td><td><?php if($d['latest_invoice_id']):?><a href="invoice_download.php?id=<?=(int)$d['latest_invoice_id']?>"><?=htmlspecialchars($d['latest_invoice_number'])?></a><?php else:?><span class="text-muted">Not issued</span><?php endif;?></td><td class="text-end"><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['invoice_csrf'])?>"><input type="hidden" name="country_id" value="<?=(int)$d['id']?>"><?php if($d['latest_invoice_id']):?><input type="hidden" name="action" value="revise"><input type="hidden" name="revision_of_id" value="<?=(int)$d['latest_invoice_id']?>"><button class="btn btn-sm btn-outline-primary" <?=$eligible?'':'disabled'?>>Issue Revision</button><?php else:?><input type="hidden" name="action" value="issue"><button class="btn btn-sm btn-primary" <?=$eligible?'':'disabled'?>>Issue Invoice</button><?php endif;?></form></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="card shadow-sm"><div class="card-header">Invoice History</div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Invoice</th><th>Delegation</th><th>Issued</th><th>Type</th><th>Total</th><th></th></tr></thead><tbody><?php foreach($history as $i):?><tr><td class="fw-semibold"><?=htmlspecialchars($i['invoice_number'])?></td><td><?=htmlspecialchars($i['country_name']?:$i['username'])?></td><td><?=htmlspecialchars(date('d M Y H:i',strtotime($i['issued_at'])))?></td><td><?=$i['revision_of_id']?'<span class="badge text-bg-info">Revision</span>':'Original'?></td><td><?=htmlspecialchars($i['currency'])?> <?=number_format((float)$i['total_amount'],2)?></td><td class="text-end"><div class="d-inline-flex gap-1"><a class="btn btn-sm btn-outline-secondary" href="invoice_download.php?id=<?=(int)$i['id']?>">Download PDF</a><form method="post" onsubmit="return confirm('Permanently remove invoice <?=htmlspecialchars(addslashes($i['invoice_number']))?>? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['invoice_csrf'])?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="invoice_id" value="<?=(int)$i['id']?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Remove</button></form></div></td></tr><?php endforeach;?></tbody></table></div></div>
<?php require_once 'includes/footer.php'; ?>
