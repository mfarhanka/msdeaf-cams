<?php
require_once __DIR__.'/includes/auth.php';
$message='';
$categories=['Transport','Meals','Accommodation','Medical','Equipment','Other'];
$currencies=['MYR','USD','EUR','GBP','SGD'];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)($_SESSION['delegate_csrf']??''),(string)($_POST['csrf_token']??''))) $message='<div class="alert alert-danger">Your session expired. Please try again.</div>';
    elseif (($_POST['action'] ?? 'submit_claim') === 'delete_claim') {
        $claimId=(int)($_POST['claim_id']??0);
        $stmt=$pdo->prepare('SELECT receipt_path,status FROM delegate_expense_claims WHERE id=? AND athlete_id=? LIMIT 1');
        $stmt->execute([$claimId,$delegateId]);$claimToDelete=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$claimToDelete) $message='<div class="alert alert-warning">Claim not found.</div>';
        elseif(in_array($claimToDelete['status'],['Approved','Paid'],true)) $message='<div class="alert alert-warning">Approved or paid claims cannot be deleted.</div>';
        else {
            $delete=$pdo->prepare("DELETE FROM delegate_expense_claims WHERE id=? AND athlete_id=? AND status NOT IN ('Approved','Paid')");
            $delete->execute([$claimId,$delegateId]);
            if($delete->rowCount()===1){
                if($claimToDelete['receipt_path']){ $receipt=__DIR__.'/../uploads/delegate_claims/'.basename($claimToDelete['receipt_path']); if(is_file($receipt)) unlink($receipt); }
                recordActivity($pdo,'delegate_expense_claim_deleted','delegate_expense_claim',$claimId,'Delegate deleted an expense claim.',[],null,'delegate','delegate-'.$delegateId);
                $message='<div class="alert alert-success">Claim deleted.</div>';
            } else $message='<div class="alert alert-warning">This claim can no longer be deleted.</div>';
        }
    }
    else {
        $date=trim((string)($_POST['expense_date']??''));$category=trim((string)($_POST['category']??''));$description=trim((string)($_POST['description']??''));$currency=strtoupper(trim((string)($_POST['currency']??'')));$amount=filter_var($_POST['amount']??null,FILTER_VALIDATE_FLOAT);
        $validDate=DateTime::createFromFormat('Y-m-d',$date);$validDate=$validDate&&$validDate->format('Y-m-d')===$date;
        if(!$validDate||!in_array($category,$categories,true)||!in_array($currency,$currencies,true)||$description===''||mb_strlen($description)>500||$amount===false||$amount<=0||$amount>9999999999.99) $message='<div class="alert alert-warning">Complete all claim fields with valid values.</div>';
        else {
            $receiptPath=null;$receiptName=null;
            try {
                if(isset($_FILES['receipt'])&&($_FILES['receipt']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                    if($_FILES['receipt']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('The receipt could not be uploaded.');
                    if((int)$_FILES['receipt']['size']>5*1024*1024) throw new RuntimeException('Receipt files must be 5 MB or smaller.');
                    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($_FILES['receipt']['tmp_name']);$extensions=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
                    if(!isset($extensions[$mime])) throw new RuntimeException('Receipt must be a PDF, JPG or PNG file.');
                    $uploadDir=__DIR__.'/../uploads/delegate_claims';if(!is_dir($uploadDir)&&!mkdir($uploadDir,0750,true)&&!is_dir($uploadDir)) throw new RuntimeException('Receipt storage is unavailable.');
                    $storedName=bin2hex(random_bytes(16)).'.'.$extensions[$mime];if(!move_uploaded_file($_FILES['receipt']['tmp_name'],$uploadDir.'/'.$storedName)) throw new RuntimeException('The receipt could not be saved.');
                    $receiptPath=$storedName;$receiptName=substr(basename((string)$_FILES['receipt']['name']),0,255);
                }
                $stmt=$pdo->prepare('INSERT INTO delegate_expense_claims (athlete_id,expense_date,category,description,currency,amount,receipt_path,receipt_original_name) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$delegateId,$date,$category,$description,$currency,$amount,$receiptPath,$receiptName]);
                recordActivity($pdo,'delegate_expense_claim_submitted','delegate_expense_claim',(int)$pdo->lastInsertId(),'Delegate submitted an expense claim.',['amount'=>$amount,'currency'=>$currency],null,'delegate','delegate-'.$delegateId);
                $message='<div class="alert alert-success">Expense claim submitted successfully.</div>';
            } catch(Throwable $e){if($receiptPath)@unlink(__DIR__.'/../uploads/delegate_claims/'.$receiptPath);$message='<div class="alert alert-danger">'.htmlspecialchars($e->getMessage()).'</div>';}
        }
    }
}
$stmt=$pdo->prepare('SELECT * FROM delegate_expense_claims WHERE athlete_id=? ORDER BY created_at DESC,id DESC');$stmt->execute([$delegateId]);$claims=$stmt->fetchAll(PDO::FETCH_ASSOC);
$claimCount=count($claims);$pendingClaimCount=0;$claimTotals=[];
foreach($claims as $claim){if($claim['status']==='Pending')$pendingClaimCount++;$currency=$claim['currency'];$claimTotals[$currency]=($claimTotals[$currency]??0)+(float)$claim['amount'];}
function badgeClass(string $status):string{return match($status){'Approved'=>'success','Rejected'=>'danger','Paid'=>'primary',default=>'warning text-dark'};}
register_shutdown_function(static function (): void { echo <<<'HTML'
<script>
(() => {
 const input=document.querySelector('input[type="file"][name="receipt"]'); if(!input)return;
 input.accept='application/pdf,image/jpeg,image/png';
 const help=input.nextElementSibling; if(help)help.textContent='Photos are resized to a maximum of 1600 px before upload. PDF, JPG or PNG; maximum 5 MB.';
 const status=document.createElement('div'); status.className='small mt-1'; status.setAttribute('aria-live','polite'); input.parentNode.appendChild(status);
 const submit=input.form.querySelector('button[type="submit"]');
 const size=n=>n<1048576?`${Math.round(n/1024)} KB`:`${(n/1048576).toFixed(1)} MB`;
 input.addEventListener('change',async()=>{
  const file=input.files[0]; status.textContent=''; status.className='small mt-1';
  if(!file||file.type==='application/pdf')return;
  if(!['image/jpeg','image/png'].includes(file.type)){status.textContent='Choose a PDF, JPG or PNG file.';status.classList.add('text-danger');input.value='';return;}
  submit.disabled=true;status.textContent='Resizing photo…';
  try{
   const bitmap=await createImageBitmap(file),scale=Math.min(1,1600/Math.max(bitmap.width,bitmap.height));
   const width=Math.max(1,Math.round(bitmap.width*scale)),height=Math.max(1,Math.round(bitmap.height*scale));
   const canvas=document.createElement('canvas');canvas.width=width;canvas.height=height;canvas.getContext('2d').drawImage(bitmap,0,0,width,height);bitmap.close();
   const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',0.82));if(!blob)throw new Error('Resize failed');
   const resized=new File([blob],(file.name.replace(/\.[^.]+$/,'')||'receipt')+'.jpg',{type:'image/jpeg',lastModified:Date.now()});
   const transfer=new DataTransfer();transfer.items.add(resized);input.files=transfer.files;
   status.textContent=`Ready: ${width} × ${height}px, ${size(resized.size)} (was ${size(file.size)})`;status.classList.add('text-success');
  }catch(error){status.textContent='This photo could not be resized. Please choose another JPG or PNG.';status.classList.add('text-danger');input.value='';}
  finally{submit.disabled=false;}
 });
})();
</script>
HTML; });
$deleteRows=[];
foreach($claims as $index=>$claim){if(!in_array($claim['status'],['Approved','Paid'],true))$deleteRows[]=['row'=>$index,'id'=>(int)$claim['id']];}
register_shutdown_function(static function () use ($deleteRows): void {
    $config=json_encode($deleteRows,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $csrf=json_encode((string)$_SESSION['delegate_csrf'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    echo "<script>(()=>{const rows=document.querySelectorAll('table tbody tr');const claims={$config};const csrf={$csrf};claims.forEach(c=>{const cell=rows[c.row]?.lastElementChild;if(!cell)return;const form=document.createElement('form');form.method='post';form.className='mt-2';form.innerHTML='<input type=\"hidden\" name=\"csrf_token\"><input type=\"hidden\" name=\"action\" value=\"delete_claim\"><input type=\"hidden\" name=\"claim_id\"><button class=\"btn btn-sm btn-outline-danger\" type=\"submit\"><i class=\"bi bi-trash\"></i> Delete</button>';form.elements.csrf_token.value=csrf;form.elements.claim_id.value=c.id;form.addEventListener('submit',e=>{if(!confirm('Delete this claim and its receipt?'))e.preventDefault();});cell.appendChild(form);});})();</script>";
});
register_shutdown_function(static function () use ($claimCount,$pendingClaimCount,$claimTotals): void {
    $totals=[];foreach($claimTotals as $currency=>$amount)$totals[]=$currency.' '.number_format($amount,2);
    $amountHtml='';foreach($totals?:['MYR 0.00'] as $total)$amountHtml.='<div>'.htmlspecialchars($total).'</div>';
    $html='<div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Total Claims</div><div class="fs-3 fw-bold">'.$claimCount.'</div></div></div></div><div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Pending Claims</div><div class="fs-3 fw-bold text-warning">'.$pendingClaimCount.'</div></div></div></div><div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Total Claimed</div><div class="fw-bold">'.$amountHtml.'</div></div></div></div>';
    echo '<script>(()=>{const cards=document.querySelectorAll("main>.card");if(cards.length<2)return;const box=document.createElement("div");box.className="row g-3 mb-4";box.innerHTML='.json_encode($html).';cards[1].before(box);})();</script>';
});
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Expense Claims - CAMS</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css"></head><body class="bg-light"><nav class="navbar navbar-dark bg-primary"><div class="container"><a class="navbar-brand" href="dashboard.php"><i class="bi bi-person-vcard me-2"></i>CAMS Delegate</a><div class="d-flex gap-2"><a class="btn btn-sm btn-light" href="dashboard.php">My Details</a><a class="btn btn-sm btn-outline-light" href="logout.php">Logout</a></div></div></nav><main class="container py-4"><div class="mb-4"><h1 class="h3 mb-1">Expense Claims</h1><p class="text-muted mb-0">Submit an expense now and follow its status here later.</p></div><?php echo $message;?><div class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5 mb-3">New Claim</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['delegate_csrf']);?>"><div class="row g-3"><div class="col-md-3"><label class="form-label">Expense date</label><input class="form-control" type="date" name="expense_date" max="<?php echo date('Y-m-d');?>" required></div><div class="col-md-3"><label class="form-label">Category</label><select class="form-select" name="category" required><option value="">Select</option><?php foreach($categories as $item):?><option><?php echo htmlspecialchars($item);?></option><?php endforeach;?></select></div><div class="col-md-2"><label class="form-label">Currency</label><select class="form-select" name="currency"><?php foreach($currencies as $item):?><option<?php echo $item==='MYR'?' selected':'';?>><?php echo $item;?></option><?php endforeach;?></select></div><div class="col-md-4"><label class="form-label">Amount</label><input class="form-control" type="number" name="amount" min="0.01" step="0.01" required></div><div class="col-md-8"><label class="form-label">Description</label><textarea class="form-control" name="description" maxlength="500" rows="2" required></textarea></div><div class="col-md-4"><label class="form-label">Receipt <span class="text-muted">(optional)</span></label><input class="form-control" type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png"><div class="form-text">PDF, JPG or PNG; maximum 5 MB.</div></div><div class="col-12 text-end"><button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i>Submit Claim</button></div></div></form></div></div><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5 mb-3">My Claims</h2><div class="table-responsive"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>Submitted</th><th>Expense</th><th>Category</th><th>Description</th><th class="text-end">Amount</th><th>Receipt</th><th>Status</th></tr></thead><tbody><?php foreach($claims as $claim):?><tr><td class="text-nowrap"><?php echo htmlspecialchars(date('d M Y',strtotime($claim['created_at'])));?></td><td class="text-nowrap"><?php echo htmlspecialchars(date('d M Y',strtotime($claim['expense_date'])));?></td><td><?php echo htmlspecialchars($claim['category']);?></td><td><?php echo htmlspecialchars($claim['description']);?><?php if($claim['admin_note']):?><div class="small text-muted">Note: <?php echo htmlspecialchars($claim['admin_note']);?></div><?php endif;?></td><td class="text-end text-nowrap"><?php echo htmlspecialchars($claim['currency']);?> <?php echo number_format((float)$claim['amount'],2);?></td><td><?php if($claim['receipt_path']):?><a href="receipt_download.php?id=<?php echo (int)$claim['id'];?>">View</a><?php else:?><span class="text-muted">—</span><?php endif;?></td><td><span class="badge text-bg-<?php echo badgeClass($claim['status']);?>"><?php echo htmlspecialchars($claim['status']);?></span></td></tr><?php endforeach;?><?php if(!$claims):?><tr><td colspan="7" class="text-center text-muted py-4">No expense claims submitted yet.</td></tr><?php endif;?></tbody></table></div></div></div></main></body></html>
