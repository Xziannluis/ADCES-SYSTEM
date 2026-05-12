<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] != 'edp') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Fetch form settings
$formSettings = [];
try {
    $fsStmt = $db->query("SELECT setting_key, setting_value FROM form_settings");
    while ($row = $fsStmt->fetch(PDO::FETCH_ASSOC)) {
        $formSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // table may not exist yet
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['form_code_no', 'issue_status', 'revision_no', 'date_effective', 'approved_by'];
    $updateStmt = $db->prepare("INSERT INTO form_settings (setting_key, setting_value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = :val2, updated_at = NOW()");
    $ok = true;
    foreach ($fields as $f) {
        $val = trim($_POST[$f] ?? '');
        if ($val === '') continue;
        try {
            $updateStmt->execute([':key' => $f, ':val' => $val, ':val2' => $val]);
        } catch (PDOException $e) {
            $ok = false;
        }
    }
    $_SESSION[$ok ? 'success' : 'error'] = $ok ? "Form settings updated successfully." : "Failed to update form settings.";
    header("Location: form_settings.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Form Settings - EDP Admin</title>
    <?php include '../includes/header.php'; ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content" style="padding:0;">
        <div class="dashboard-bg-layer"><div class="bg-img"></div></div>
        <div class="dashboard-topbar">
            <h2>Saint Michael College of Caraga</h2>
            <div class="ms-auto">
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="evaluatorMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="evaluatorMenu">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header" style="background-color:#1a1a2e; color:#fff;">
                    <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Evaluation Form Settings</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">These values appear on the Classroom Evaluation Form header.</p>
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="form_code_no" class="form-label fw-bold">Form Code No.</label>
                            <input type="text" class="form-control" id="form_code_no" name="form_code_no" value="<?php echo htmlspecialchars($formSettings['form_code_no'] ?? 'FM-DPM-SMCC-RTH-04'); ?>" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="issue_status" class="form-label fw-bold">Issue Status</label>
                                <input type="text" class="form-control" id="issue_status" name="issue_status" value="<?php echo htmlspecialchars($formSettings['issue_status'] ?? '02'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="revision_no" class="form-label fw-bold">Revision No.</label>
                                <input type="text" class="form-control" id="revision_no" name="revision_no" value="<?php echo htmlspecialchars($formSettings['revision_no'] ?? '02'); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="date_effective" class="form-label fw-bold">Date Effective</label>
                                <input type="text" class="form-control" id="date_effective" name="date_effective" value="<?php echo htmlspecialchars($formSettings['date_effective'] ?? '13 September 2023'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="approved_by" class="form-label fw-bold">Approved By</label>
                                <input type="text" class="form-control" id="approved_by" name="approved_by" value="<?php echo htmlspecialchars($formSettings['approved_by'] ?? 'President'); ?>" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Form Settings
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
</body>
</html>
