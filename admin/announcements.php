<?php
require_once 'includes/auth.php';

ensureAnnouncementsTable($pdo);

function getAnnouncementUploadDirectory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'announcements';
}

function normalizeAnnouncementImagePath(?string $path): ?string
{
    if (!is_string($path) || $path === '') {
        return null;
    }

    $normalized = str_replace('\\', '/', $path);
    return str_starts_with($normalized, 'uploads/announcements/') ? $normalized : null;
}

function handleAnnouncementPhotoUpload(string $fieldName, ?string $existingPath = null): ?string
{
    if (!isset($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existingPath;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The photo could not be uploaded. Please try again.');
    }

    if ((int) $_FILES[$fieldName]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Photo must be 5 MB or smaller.');
    }

    $tmpName = (string) $_FILES[$fieldName]['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);
    $extensionsByMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensionsByMime[$mimeType])) {
        throw new RuntimeException('Photo must be a JPG, PNG, WEBP, or GIF image.');
    }

    $uploadDirectory = getAnnouncementUploadDirectory();
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
        throw new RuntimeException('Unable to prepare announcement upload folder.');
    }

    $filename = 'announcement-' . bin2hex(random_bytes(12)) . '.' . $extensionsByMime[$mimeType];
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Unable to save the uploaded photo.');
    }

    return 'uploads/announcements/' . $filename;
}

function deleteAnnouncementImage(?string $path): void
{
    $normalizedPath = normalizeAnnouncementImagePath($path);
    if ($normalizedPath === null) {
        return;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
    $uploadRoot = realpath(getAnnouncementUploadDirectory());
    $resolvedPath = realpath($absolutePath);

    if ($uploadRoot !== false && $resolvedPath !== false && str_starts_with($resolvedPath, $uploadRoot) && is_file($resolvedPath)) {
        unlink($resolvedPath);
    }
}

function fetchAnnouncement(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

    return $announcement ?: null;
}

$actor = getActorDetailsFromSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'add_announcement') {
            $title = trim($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $displayOnLogin = isset($_POST['display_on_login']) ? 1 : 0;

            if ($title === '' || $body === '') {
                throw new RuntimeException('Title and announcement text are required.');
            }

            $imagePath = handleAnnouncementPhotoUpload('photo');

            if ($imagePath === null) {
                throw new RuntimeException('Photo is required for a new announcement.');
            }

            if ($displayOnLogin === 1) {
                $pdo->exec('UPDATE announcements SET display_on_login = 0');
            }

            $stmt = $pdo->prepare('INSERT INTO announcements (title, body, image_path, is_enabled, display_on_login, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, $body, $imagePath, $isEnabled, $displayOnLogin, $actor['id']]);
            $announcementId = (int) $pdo->lastInsertId();

            recordActivity($pdo, 'announcement_created', 'announcement', $announcementId, 'Announcement created.', ['title' => $title, 'display_on_login' => $displayOnLogin], $actor['id'], $actor['role'], $actor['username']);
            $msg = "<div class='alert alert-success alert-dismissible fade show'>Announcement added successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif ($_POST['action'] === 'edit_announcement') {
            $id = (int) ($_POST['id'] ?? 0);
            $announcement = fetchAnnouncement($pdo, $id);
            $title = trim($_POST['title'] ?? '');
            $body = trim($_POST['body'] ?? '');
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $displayOnLogin = isset($_POST['display_on_login']) ? 1 : 0;

            if (!$announcement) {
                throw new RuntimeException('Announcement not found.');
            }

            if ($title === '' || $body === '') {
                throw new RuntimeException('Title and announcement text are required.');
            }

            $oldImagePath = $announcement['image_path'] ?? null;
            $imagePath = handleAnnouncementPhotoUpload('photo', $oldImagePath);

            if ($displayOnLogin === 1) {
                $pdo->prepare('UPDATE announcements SET display_on_login = 0 WHERE id <> ?')->execute([$id]);
            }

            $stmt = $pdo->prepare('UPDATE announcements SET title = ?, body = ?, image_path = ?, is_enabled = ?, display_on_login = ? WHERE id = ?');
            $stmt->execute([$title, $body, $imagePath, $isEnabled, $displayOnLogin, $id]);

            if ($imagePath !== $oldImagePath) {
                deleteAnnouncementImage($oldImagePath);
            }

            recordActivity($pdo, 'announcement_updated', 'announcement', $id, 'Announcement updated.', ['title' => $title, 'display_on_login' => $displayOnLogin], $actor['id'], $actor['role'], $actor['username']);
            $msg = "<div class='alert alert-success alert-dismissible fade show'>Announcement updated successfully.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif ($_POST['action'] === 'select_announcement') {
            $id = (int) ($_POST['id'] ?? 0);
            $announcement = fetchAnnouncement($pdo, $id);

            if (!$announcement) {
                throw new RuntimeException('Announcement not found.');
            }

            $pdo->beginTransaction();
            $pdo->exec('UPDATE announcements SET display_on_login = 0');
            $stmt = $pdo->prepare('UPDATE announcements SET display_on_login = 1, is_enabled = 1 WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->commit();

            recordActivity($pdo, 'announcement_selected', 'announcement', $id, 'Announcement selected for delegate login modal.', ['title' => $announcement['title']], $actor['id'], $actor['role'], $actor['username']);
            $msg = "<div class='alert alert-success alert-dismissible fade show'>Selected announcement will display once after delegate login.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } elseif ($_POST['action'] === 'delete_announcement') {
            $id = (int) ($_POST['id'] ?? 0);
            $announcement = fetchAnnouncement($pdo, $id);

            if (!$announcement) {
                throw new RuntimeException('Announcement not found.');
            }

            $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = ?');
            $stmt->execute([$id]);
            deleteAnnouncementImage($announcement['image_path'] ?? null);

            recordActivity($pdo, 'announcement_deleted', 'announcement', $id, 'Announcement deleted.', ['title' => $announcement['title']], $actor['id'], $actor['role'], $actor['username']);
            $msg = "<div class='alert alert-success alert-dismissible fade show'>Announcement deleted.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = "<div class='alert alert-warning alert-dismissible fade show'>" . htmlspecialchars($exception->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

$announcementsStmt = $pdo->query(
    "SELECT a.*, u.username AS created_by_username
    FROM announcements a
    LEFT JOIN users u ON u.id = a.created_by
    ORDER BY a.display_on_login DESC, a.updated_at DESC, a.id DESC"
);
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-1">Announcement Management</h1>
        <p class="text-muted mb-0">Create photo and text announcements, then choose one to show once after each delegate login.</p>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">
        <i class="bi bi-plus-lg me-1"></i> Add Announcement
    </button>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if ($announcements): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Announcement</th>
                            <th>Status</th>
                            <th>Selected</th>
                            <th>Updated</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($announcements as $announcement): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if (!empty($announcement['image_path'])): ?>
                                            <img src="../<?php echo htmlspecialchars($announcement['image_path']); ?>" alt="" class="rounded" style="width: 72px; height: 48px; object-fit: cover;">
                                        <?php else: ?>
                                            <div class="rounded bg-light border d-flex align-items-center justify-content-center text-muted" style="width: 72px; height: 48px;">
                                                <i class="bi bi-image"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($announcement['title']); ?></div>
                                            <div class="small text-muted text-truncate" style="max-width: 520px;"><?php echo htmlspecialchars($announcement['body']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge rounded-pill <?php echo (int) $announcement['is_enabled'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                        <?php echo (int) $announcement['is_enabled'] === 1 ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ((int) $announcement['display_on_login'] === 1): ?>
                                        <span class="badge rounded-pill text-bg-primary">Login modal</span>
                                    <?php else: ?>
                                        <span class="text-muted small">Not selected</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small text-muted"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($announcement['updated_at']))); ?></div>
                                    <div class="small text-muted">By <?php echo htmlspecialchars($announcement['created_by_username'] ?: 'System'); ?></div>
                                </td>
                                <td class="text-end">
                                    <?php if ((int) $announcement['display_on_login'] !== 1): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="select_announcement">
                                            <input type="hidden" name="id" value="<?php echo (int) $announcement['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success">
                                                <i class="bi bi-check2-circle"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editAnnouncementModal<?php echo (int) $announcement['id']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this announcement?');">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="id" value="<?php echo (int) $announcement['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>

                            <div class="modal fade" id="editAnnouncementModal<?php echo (int) $announcement['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form method="POST" enctype="multipart/form-data">
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title">Edit Announcement</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="edit_announcement">
                                                <input type="hidden" name="id" value="<?php echo (int) $announcement['id']; ?>">
                                                <?php renderAnnouncementFormFields($announcement); ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center mb-0">No announcements have been created yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php
function renderAnnouncementFormFields(array $announcement = []): void
{
    $title = (string) ($announcement['title'] ?? '');
    $body = (string) ($announcement['body'] ?? '');
    $imagePath = (string) ($announcement['image_path'] ?? '');
    $isEnabled = !isset($announcement['is_enabled']) || (int) $announcement['is_enabled'] === 1;
    $displayOnLogin = isset($announcement['display_on_login']) && (int) $announcement['display_on_login'] === 1;
    $photoRequired = $imagePath === '';
    ?>
    <div class="mb-3">
        <label class="form-label text-muted fw-bold">Title</label>
        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($title); ?>" maxlength="150" required>
    </div>
    <div class="mb-3">
        <label class="form-label text-muted fw-bold">Announcement Text</label>
        <textarea name="body" class="form-control" rows="5" required><?php echo htmlspecialchars($body); ?></textarea>
    </div>
    <div class="mb-3">
        <label class="form-label text-muted fw-bold">Photo</label>
        <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" <?php echo $photoRequired ? 'required' : ''; ?>>
        <?php if ($imagePath !== ''): ?>
            <div class="small text-muted mt-2">Current photo: <?php echo htmlspecialchars(basename($imagePath)); ?></div>
        <?php endif; ?>
    </div>
    <div class="row">
        <div class="col-md-6">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="is_enabled" id="enabled<?php echo htmlspecialchars((string) ($announcement['id'] ?? 'new')); ?>" <?php echo $isEnabled ? 'checked' : ''; ?>>
                <label class="form-check-label" for="enabled<?php echo htmlspecialchars((string) ($announcement['id'] ?? 'new')); ?>">Enabled</label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="display_on_login" id="display<?php echo htmlspecialchars((string) ($announcement['id'] ?? 'new')); ?>" <?php echo $displayOnLogin ? 'checked' : ''; ?>>
                <label class="form-check-label" for="display<?php echo htmlspecialchars((string) ($announcement['id'] ?? 'new')); ?>">Show once after delegate login</label>
            </div>
        </div>
    </div>
    <?php
}
?>

<div class="modal fade" id="addAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add Announcement</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_announcement">
                    <?php renderAnnouncementFormFields(); ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Announcement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
