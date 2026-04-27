<?php
session_start();
$suppressDbErrors = true;
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/activity.php';

$error = '';

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');

    if (!empty($user) && !empty($pass) && isset($pdo)) {
        // Find user in database based on roles
        $stmt = $pdo->prepare("SELECT id, username, password, role, status FROM users WHERE username = :username");
        $stmt->bindParam(':username', $user);
        $stmt->execute();

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Validate password using password_verify()
            if (password_verify($pass, $row['password'])) {
                if (($row['status'] ?? 'active') !== 'active') {
                    recordActivity(
                        $pdo,
                        'login_blocked',
                        'user',
                        (int) $row['id'],
                        'Suspended user attempted to sign in.',
                        ['username_input' => $user],
                        (int) $row['id'],
                        (string) $row['role'],
                        (string) $row['username']
                    );
                    $error = "Your account has been suspended. Please contact the system administrator.";
                } else {
                // Password matches, start user session
                    $_SESSION['loggedin'] = true;
                    $_SESSION['id'] = $row['id'];
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['role'] = $row['role']; // e.g. 'admin' or 'country_manager'

                    $telegramMessage = formatTelegramActivityMessage(
                        'CAMS login',
                        [
                            'User: ' . $row['username'],
                            'Role: ' . $row['role'],
                            'Status: success',
                        ]
                    );
                    recordActivity(
                        $pdo,
                        'login_success',
                        'user',
                        (int) $row['id'],
                        'User signed in successfully.',
                        [],
                        (int) $row['id'],
                        (string) $row['role'],
                        (string) $row['username'],
                        $telegramMessage
                    );

                    // Redirect depending on role
                    if ($row['role'] === 'admin') {
                        header("location: admin/dashboard.php");
                    } else {
                        header("location: country/dashboard.php");
                    }
                    exit;
                }
            } else {
                recordActivity(
                    $pdo,
                    'login_failed',
                    'user',
                    (int) $row['id'],
                    'Password verification failed during sign-in.',
                    ['username_input' => $user],
                    null,
                    null,
                    $user
                );
                $error = "Invalid username or password.";
            }
        } else {
            recordActivity(
                $pdo,
                'login_failed',
                'user',
                null,
                'Unknown username attempted to sign in.',
                ['username_input' => $user],
                null,
                null,
                $user
            );
            $error = "Invalid username or password.";
        }
    } elseif(empty($pdo)) {
        $error = "Service unavailable due to database connection issue.";
    } else {
        $error = "Please enter both username and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAMS Login - World Deaf Sports Championship</title>
    <!-- Bootstrap CSS for responsive and clean design -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-blue: #004a99;
            --secondary-blue: #e6f0ff;
            --accent-blue: #007bff;
        }

        body { 
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            background-color: #ffffff; /* White card */
        }
        .login-header {
            background-color: var(--primary-blue);
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
        }
        .login-header h2 {
            font-weight: 700;
            margin-bottom: 5px;
            font-size: 1.5rem;
        }
        .login-header p {
            margin: 0;
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .login-body {
            padding: 40px 30px;
        }
        .btn-primary {
            background-color: var(--primary-blue);
            border: none;
            font-weight: 600;
            padding: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-primary:hover {
            background-color: #003366;
        }
        .form-floating:focus-within label {
            color: var(--primary-blue);
        }
        .input-group-text {
            background-color: transparent;
            border-right: none;
            color: var(--primary-blue);
        }
        .form-control {
            border-left: none;
        }
        .form-control:focus {
            box-shadow: none;
            border-color: #dee2e6;
        }
        .input-wrapper {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        .input-wrapper:focus-within {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.25rem rgba(0, 74, 153, 0.25);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5 col-xl-4">
            <div class="card login-card">
                <div class="login-header">
                    <h2><i class="bi bi-trophy me-2"></i> CAMS</h2>
                    <p>Championship Accommodation Management System</p>
                </div>
                <div class="login-body">
                    
                    <?php if(!empty($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-circle me-1"></i> <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if(!empty($db_error)): ?>
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <i class="bi bi-database me-1"></i> <?php echo htmlspecialchars($db_error); ?>
                        </div>
                    <?php endif; ?>

                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <!-- Username Field -->
                        <div class="mb-4">
                            <label class="form-label text-muted fw-bold small">Username / Delegation ID</label>
                            <div class="input-group input-wrapper">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" name="username" placeholder="Enter your username" required>
                            </div>
                        </div>

                        <!-- Password Field -->
                        <div class="mb-4">
                            <label class="form-label text-muted fw-bold small">Password</label>
                            <div class="input-group input-wrapper">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" name="password" placeholder="Enter your password" required>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                Secure Login <i class="bi bi-box-arrow-in-right ms-1"></i>
                            </button>
                        </div>
                    </form>
                    
                    <div class="text-center mt-4">
                        <small class="text-muted">Need help logging in? <br>Contact the <b>System Administrator</b>.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS bundle (Includes Popper for alerts) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>