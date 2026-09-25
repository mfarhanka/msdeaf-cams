<?php
require_once 'includes/auth.php';
require_once '../includes/volunteers.php';

$actor = getActorDetailsFromSession();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyVolunteerCsrf($_POST['csrf_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $note = trim($_POST['review_note'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM volunteer_applications WHERE id = ? AND status = \'pending\' LIMIT 1');
        $stmt->execute([$id]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$application) {
            throw new RuntimeException('Pending application not found.');
        }
        if ($action === 'approve') {
            $identity = decryptVolunteerValue($application['identity_encrypted']);
            $username = normalizeVolunteerIdentity($identity);
            $temporaryPassword = generateTemporaryVolunteerPassword();
            $pdo->beginTransaction();
            $userStmt = $pdo->prepare("INSERT INTO users (username, password, role, status, must_change_password) VALUES (?, ?, 'volunteer', 'active', 1)");
            $userStmt->execute([$username, password_hash($temporaryPassword, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();
            $update = $pdo->prepare("UPDATE volunteer_applications SET status='approved', user_id=?, review_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
            $update->execute([$userId, $note ?: null, $actor['id'], $id]);
            sendVolunteerEmail($application['email'], 'Permohonan sukarelawan diluluskan / Volunteer application approved',
                '<p>Salam ' . htmlspecialchars($application['full_name']) . ',</p><p>Permohonan anda telah diluluskan.</p><p><strong>ID log masuk / Login ID:</strong> ' . htmlspecialchars($username) . '<br><strong>Kata laluan sementara / Temporary password:</strong> ' . htmlspecialchars($temporaryPassword) . '</p><p>Sila tukar kata laluan selepas log masuk pertama. / Please change your password after your first login.</p>');
            $pdo->commit();
            recordActivity($pdo, 'volunteer_application_approved', 'volunteer_application', $id, 'Volunteer application approved and account created.', [], $actor['id'], $actor['role'], $actor['username']);
            $msg = "<div class='alert alert-success'>Application approved and credentials emailed.</div>";
        } elseif ($action === 'reject') {
            sendVolunteerEmail($application['email'], 'Keputusan permohonan sukarelawan / Volunteer application decision', '<p>Salam ' . htmlspecialchars($application['full_name']) . ',</p><p>Terima kasih atas minat anda. Permohonan sukarelawan anda tidak berjaya pada masa ini.</p><hr><p>Thank you for your interest. Your volunteer application was not successful at this time.</p>' . ($note !== '' ? '<p><strong>Catatan / Note:</strong> ' . nl2br(htmlspecialchars($note)) . '</p>' : ''));
            $update = $pdo->prepare("UPDATE volunteer_applications SET status='rejected', review_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
            $update->execute([$note ?: null, $actor['id'], $id]);
            recordActivity($pdo, 'volunteer_application_rejected', 'volunteer_application', $id, 'Volunteer application rejected.', [], $actor['id'], $actor['role'], $actor['username']);
            $msg = "<div class='alert alert-success'>Application rejected and applicant notified.</div>";
        } else {
            throw new RuntimeException('Unsupported review action.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $msg = "<div class='alert alert-warning'>" . htmlspecialchars($exception->getMessage()) . "</div>";
    }
}

$status = in_array($_GET['status'] ?? '', ['pending','approved','rejected'], true) ? $_GET['status'] : '';
$search = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if ($status !== '') { $where[] = 'v.status = ?'; $params[] = $status; }
if ($search !== '') { $where[] = '(v.full_name LIKE ? OR v.email LIKE ? OR v.whatsapp LIKE ?)'; $params = array_merge($params, array_fill(0, 3, '%' . $search . '%')); }
$sql = 'SELECT v.*, u.username AS reviewer_name FROM volunteer_applications v LEFT JOIN users u ON u.id=v.reviewed_by' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY v.created_at DESC';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
require_once 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center border-bottom mb-3 pb-2"><div><h1 class="h2 mb-1">Volunteer Applications</h1><p class="text-muted mb-0">Review general volunteer-pool applications and issue approved accounts.</p></div><span class="badge bg-primary fs-6"><?php echo count($applications); ?> shown</span></div>
<form class="card card-body mb-3" method="get"><div class="row g-2"><div class="col-md-7"><input class="form-control" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, email, or WhatsApp"></div><div class="col-md-3"><select class="form-select" name="status"><option value="">All statuses</option><?php foreach(['pending','approved','rejected'] as $value): ?><option value="<?php echo $value; ?>" <?php echo $status===$value?'selected':''; ?>><?php echo ucfirst($value); ?></option><?php endforeach; ?></select></div><div class="col-md-2 d-grid"><button class="btn btn-primary">Filter</button></div></div></form>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Applicant</th><th>Preferences</th><th>Availability</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($applications as $item): $identity = decryptVolunteerValue($item['identity_encrypted']); ?><tr><td><strong><?php echo htmlspecialchars($item['full_name']); ?></strong><div class="small text-muted"><?php echo htmlspecialchars($item['email']); ?><br><?php echo htmlspecialchars($item['whatsapp']); ?> · <?php echo strtoupper($item['identity_type']); ?> <?php echo htmlspecialchars(maskVolunteerIdentity($identity)); ?></div></td><td><?php echo ucfirst(htmlspecialchars($item['department'])); ?><div class="small text-muted"><?php echo htmlspecialchars($item['tshirt_size']); ?> · Accommodation: <?php echo $item['accommodation_required']?'Yes':'No'; ?></div></td><td><?php echo htmlspecialchars($item['available_from']); ?><?php echo $item['available_until'] ? '<br><span class="small text-muted">to ' . htmlspecialchars($item['available_until']) . '</span>' : ''; ?></td><td><span class="badge <?php echo $item['status']==='approved'?'bg-success':($item['status']==='rejected'?'bg-danger':'bg-warning text-dark'); ?>"><?php echo ucfirst($item['status']); ?></span></td><td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#application<?php echo $item['id']; ?>">View</button></td></tr>
<div class="modal fade" id="application<?php echo $item['id']; ?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5"><?php echo htmlspecialchars($item['full_name']); ?></h2><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><strong>Age / Gender</strong><br><?php echo (int)$item['age']; ?> / <?php echo ucfirst($item['gender']); ?></div><div class="col-md-6"><strong>Occupation</strong><br><?php echo htmlspecialchars($item['occupation']); ?></div><div class="col-12"><strong>Address</strong><br><?php echo nl2br(htmlspecialchars(decryptVolunteerValue($item['address_encrypted']))); ?></div><div class="col-12"><strong>Medical conditions / allergies</strong><br><?php echo nl2br(htmlspecialchars(decryptVolunteerValue($item['medical_encrypted']))); ?></div><div class="col-12"><strong>Skills / experience</strong><br><?php echo nl2br(htmlspecialchars($item['skills'])); ?></div><div class="col-12"><strong>Motivation</strong><br><?php echo nl2br(htmlspecialchars($item['motivation'])); ?></div><?php if($item['review_note']): ?><div class="col-12"><strong>Review note</strong><br><?php echo nl2br(htmlspecialchars($item['review_note'])); ?></div><?php endif; ?></div></div>
<?php if ($item['status']==='pending'): ?><div class="modal-footer"><form method="post" class="w-100"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(volunteerCsrfToken()); ?>"><input type="hidden" name="id" value="<?php echo $item['id']; ?>"><label class="form-label">Review note (optional)</label><textarea class="form-control mb-3" name="review_note" rows="2"></textarea><div class="d-flex justify-content-end gap-2"><button class="btn btn-outline-danger" name="action" value="reject" onclick="return confirm('Reject this application?')">Reject</button><button class="btn btn-success" name="action" value="approve" onclick="return confirm('Approve and email login credentials?')">Approve &amp; Email</button></div></form></div><?php endif; ?></div></div></div>
<?php endforeach; ?><?php if(!$applications): ?><tr><td colspan="5" class="text-center text-muted py-4">No applications found.</td></tr><?php endif; ?></tbody></table></div></div></div>
<?php require_once 'includes/footer.php'; ?>
