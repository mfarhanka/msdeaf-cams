<?php
session_start();
$suppressDbErrors = true;
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/delegates.php';
require_once __DIR__.'/includes/activity.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = normalizeDelegateIdentity((string)($_POST['identity'] ?? ''));
    if ($identity === '') $error = 'Enter your IC or passport number.';
    elseif (!isset($pdo)) $error = 'Service unavailable due to a database connection issue.';
    else {
        ensureDelegateSelfServiceSchema($pdo);
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM athletes WHERE UPPER(REPLACE(REPLACE(TRIM(passport_number),'-',''),' ',''))=? LIMIT 2");
        $stmt->execute([$identity]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) {
            session_regenerate_id(true);
            $_SESSION = ['delegate_loggedin'=>true,'delegate_id'=>(int)$matches[0]['id'],'delegate_name'=>trim($matches[0]['first_name'].' '.$matches[0]['last_name']),'delegate_csrf'=>bin2hex(random_bytes(32))];
            recordActivity($pdo,'delegate_login_success','athlete',(int)$matches[0]['id'],'Delegate signed in using an IC/passport identifier.',[],null,'delegate','delegate-'.(int)$matches[0]['id']);
            header('location: delegate/dashboard.php'); exit;
        }
        $error = count($matches)>1 ? 'This IC/passport number is assigned to more than one delegate. Please contact your country manager.' : 'IC/passport number not found. Please check the number or contact your country manager.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delegate Login - CAMS</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css"></head><body class="bg-light"><main class="container py-5" style="max-width:560px"><div class="text-center mb-4"><i class="bi bi-person-vcard text-primary display-4"></i><h1 class="h3 mt-2">Delegate Login</h1><p class="text-muted">View your event details and manage your emergency contact.</p></div><div class="card border-0 shadow-sm"><div class="card-body p-4"><?php if($error!==''):?><div class="alert alert-danger"><?php echo htmlspecialchars($error);?></div><?php endif;?><form method="post"><label class="form-label fw-semibold" for="identity">IC / Passport number</label><input class="form-control form-control-lg" id="identity" name="identity" autocomplete="username" maxlength="255" required autofocus><div class="form-text">Enter the number registered by your country manager.</div><button class="btn btn-primary btn-lg w-100 mt-4" type="submit">Login <i class="bi bi-box-arrow-in-right ms-1"></i></button></form></div></div><div class="text-center mt-3"><a href="login.php" class="text-decoration-none">Country manager, volunteer, hotel or admin login</a></div></main></body></html>
