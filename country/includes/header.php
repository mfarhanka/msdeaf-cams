<?php
require_once __DIR__ . '/../../includes/delegate_menu.php';

$current_page = basename($_SERVER['PHP_SELF']);
$delegateMenuItems = isset($pdo) ? getVisibleDelegateMenuItems($pdo) : getDelegateMenuItems();
$loginAnnouncement = null;

if (!empty($_SESSION['show_login_announcement']) && isset($pdo)) {
    ensureAnnouncementsTable($pdo);

    $announcementStmt = $pdo->query(
        "SELECT title, body, image_path
        FROM announcements
        WHERE display_on_login = 1 AND is_enabled = 1
        ORDER BY updated_at DESC, id DESC
        LIMIT 1"
    );
    $loginAnnouncement = $announcementStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    unset($_SESSION['show_login_announcement']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Country Dashboard - CAMS</title>
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background-color: var(--primary-blue);
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            padding: 0.5rem 1rem;
        }

        .navbar-brand, .nav-link {
            color: white !important;
        }

        .sidebar {
            background-color: white;
            min-height: calc(100vh - 56px);
            border-right: 1px solid #dee2e6;
            max-width: 220px;
            padding-top: 0.75rem;
        }

        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            margin-bottom: 12px;
        }

        .card-body {
            padding: 0.9rem 1rem;
        }

        .card-header {
            background-color: white;
            border-bottom: 1px solid var(--secondary-blue);
            font-weight: bold;
            color: var(--primary-blue);
            padding: 0.75rem 1rem;
        }

        .btn-primary {
            background-color: var(--primary-blue);
            border: none;
            padding: 0.55rem 1rem;
        }

        .btn-primary:hover {
            background-color: #003366;
        }

        .badge-status {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 20px;
        }

        .hotel-price {
            font-size: 1.25rem;
            color: var(--primary-blue);
            font-weight: bold;
        }

        .nav-pills .nav-link {
            color: #333 !important;
            padding: 0.55rem 0.9rem;
            font-size: 0.92rem;
        }

        .nav-pills .nav-link:hover {
            background-color: var(--secondary-blue);
            color: var(--primary-blue) !important;
        }

        .nav-pills .nav-link.active {
            background-color: var(--primary-blue) !important;
            color: white !important;
        }

        .container-fluid {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }

        main {
            padding-top: 1rem;
            padding-bottom: 1rem;
        }

        .border-bottom {
            margin-bottom: 0.8rem;
        }

        .mobile-menu-button {
            border-color: rgba(255,255,255,0.65);
            color: white;
        }

        .mobile-menu-button:hover,
        .mobile-menu-button:focus {
            background-color: rgba(255,255,255,0.12);
            color: white;
        }

        @media (max-width: 767.98px) {
            .navbar {
                padding: 0.5rem 0.25rem;
            }

            .navbar-brand {
                max-width: calc(100vw - 72px);
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 1rem;
            }

            main {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg">
    <div class="container-fluid">
        <div class="d-flex align-items-center min-w-0">
            <button class="btn mobile-menu-button d-md-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNavigation" aria-controls="mobileNavigation" aria-label="Open navigation menu">
                <i class="bi bi-list fs-4"></i>
            </button>
            <a class="navbar-brand" href="dashboard.php"><i class="bi bi-building-check me-2"></i>CAMS | World Deaf Sports</a>
        </div>
        <div class="d-none d-md-flex align-items-center">
            <span class="text-white small me-3"><i class="bi bi-person-circle me-1"></i> Delegation: <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>
</nav>

<div class="offcanvas offcanvas-start d-md-none" tabindex="-1" id="mobileNavigation" aria-labelledby="mobileNavigationLabel">
    <div class="offcanvas-header bg-primary text-white">
        <h5 class="offcanvas-title" id="mobileNavigationLabel">Delegation Menu</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-3">
        <div class="small text-muted mb-3"><i class="bi bi-person-circle me-1"></i>Delegation: <?php echo htmlspecialchars($_SESSION['username']); ?></div>
        <div class="nav flex-column nav-pills">
            <?php foreach ($delegateMenuItems as $menuItem): ?>
                <a class="nav-link w-100 text-start <?php echo $current_page == $menuItem['href'] ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($menuItem['href']); ?>"><i class="bi <?php echo htmlspecialchars($menuItem['icon']); ?> me-2"></i><?php echo htmlspecialchars($menuItem['label']); ?></a>
            <?php endforeach; ?>
        </div>
        <a href="../logout.php" class="btn btn-outline-danger mt-auto"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav class="col-md-2 d-none d-md-block sidebar py-3">
            <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                <div class="manager-only">
                    <h6 class="px-3 mb-2 text-muted small text-uppercase fw-bold">Delegation</h6>
                    <?php foreach ($delegateMenuItems as $menuItem): ?>
                        <a class="nav-link w-100 text-start <?php echo $current_page == $menuItem['href'] ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($menuItem['href']); ?>"><i class="bi <?php echo htmlspecialchars($menuItem['icon']); ?> me-2"></i><?php echo htmlspecialchars($menuItem['label']); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="col-md-10 ms-sm-auto px-md-4 py-4">
            <?php if ($loginAnnouncement !== null): ?>
                <div class="modal fade" id="delegateLoginAnnouncementModal" tabindex="-1" aria-labelledby="delegateLoginAnnouncementTitle" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content">
                            <?php if (!empty($loginAnnouncement['image_path'])): ?>
                                <img src="../<?php echo htmlspecialchars($loginAnnouncement['image_path']); ?>" class="img-fluid rounded-top" alt="" style="max-height: 360px; object-fit: cover;">
                            <?php endif; ?>
                            <div class="modal-header">
                                <h5 class="modal-title" id="delegateLoginAnnouncementTitle"><?php echo htmlspecialchars($loginAnnouncement['title']); ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="mb-0" style="white-space: pre-wrap;"><?php echo htmlspecialchars($loginAnnouncement['body']); ?></p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var announcementModal = document.getElementById('delegateLoginAnnouncementModal');
                        if (announcementModal && window.bootstrap) {
                            new bootstrap.Modal(announcementModal).show();
                        }
                    });
                </script>
            <?php endif; ?>
            <?php if(isset($msg) && !empty($msg)) echo $msg; ?>
