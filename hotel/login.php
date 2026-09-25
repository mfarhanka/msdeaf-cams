<?php
session_start();
$suppressDbErrors = true;
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/activity.php';

if (!empty($_SESSION['loggedin']) && ($_SESSION['role'] ?? '') === 'hotel') {
    header('location: rooms.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (!isset($pdo)) {
        $error = 'Service unavailable due to a database connection issue.';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password, role, status, hotel_id FROM users WHERE username = ? AND role = 'hotel' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Invalid hotel username or password.';
            recordActivity($pdo, 'login_failed', 'user', $user ? (int) $user['id'] : null,
                'Hotel portal sign-in failed.', [], null, null, substr($username, 0, 100));
        } elseif ($user['status'] !== 'active' || empty($user['hotel_id'])) {
            $error = 'This hotel account is unavailable. Please contact the administrator.';
            recordActivity($pdo, 'login_blocked', 'user', (int) $user['id'],
                'Unavailable hotel account attempted to sign in.', [], (int) $user['id'], 'hotel', $user['username']);
        } else {
            session_regenerate_id(true);
            $_SESSION['loggedin'] = true;
            $_SESSION['id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = 'hotel';
            recordActivity($pdo, 'login_success', 'user', (int) $user['id'],
                'Hotel user signed in successfully.', [], (int) $user['id'], 'hotel', $user['username']);
            header('location: rooms.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hotel Login - CAMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light min-vh-100 d-flex align-items-center">
<main class="container"><div class="row justify-content-center"><div class="col-md-6 col-lg-4">
    <div class="card border-0 shadow"><div class="card-header text-white text-center py-4" style="background:#004a99"><i class="bi bi-building fs-1"></i><h1 class="h4 mt-2 mb-1">CAMS Hotel Portal</h1><p class="small mb-0 opacity-75">Room number entry only</p></div>
    <div class="card-body p-4">
        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3"><label class="form-label fw-semibold">Hotel username</label><input class="form-control" name="username" autocomplete="username" required autofocus></div>
            <div class="mb-4"><label class="form-label fw-semibold">Password</label><input class="form-control" type="password" name="password" autocomplete="current-password" required></div>
            <button class="btn btn-primary w-100" style="background:#004a99">Sign in</button>
        </form>
    </div></div>
</div></div></main>
</body>
</html>
