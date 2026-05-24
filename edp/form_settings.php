<?php
require_once '../auth/session-check.php';
if($_SESSION['role'] != 'edp') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

function defaultIsoIndicators(): array {
    return [
        'communications' => [
            'Uses an audible voice that can be heard at the back of the room.',
            'Speaks fluently in the language of instruction.',
            'Facilitates a dynamic discussion.',
            'Uses engaging non-verbal cues (facial expression, gestures).',
            "Uses words & expressions suited to the level of the students."
        ],
        'management' => [
            'The TILO (Topic Intended Learning Outcomes) are clearly presented.',
            'Recall and connects previous lessons to the new lessons.',
            'Uses varied and suitable teaching methods.',
            'Presents lesson in an organized and logical sequence.',
            'Uses examples and illustrations to clarify lessons.',
            'Uses instructional materials/technology effectively.',
            'Asks thought-provoking questions.',
            'Encourages students to participate in the discussion.',
            'Provides opportunities for collaborative/cooperative learning.',
            'Maintains discipline and a learning-conducive environment.',
            'Manages class time effectively.',
            'Summarizes key points before ending the class.'
        ],
        'assessment' => [
            'Construct test questions and activities that align to intended outcomes.',
            'Uses assessment tool that relates specific course competencies stated in the syllabus.',
            'Design test/quarter/assignments and other assessment tasks that are corrector-based.',
            'Provides timely feedback to students on their performance.',
            "Conducts normative assessment before evaluating and grading the learner's performance outcome.",
            'Monitors the formative assessment results and find ways to ensure learning for the learners.'
        ]
    ];
}

// Fetch form settings
$formSettings = [];
$isoIndicators = defaultIsoIndicators();
try {
    $fsStmt = $db->query("SELECT setting_key, setting_value FROM form_settings");
    while ($row = $fsStmt->fetch(PDO::FETCH_ASSOC)) {
        $formSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // table may not exist yet
}

try {
    $isoStmt = $db->prepare("SELECT category, criterion_text FROM evaluation_criteria
                             WHERE category IN ('communications','management','assessment')
                             ORDER BY category, criterion_index ASC");
    $isoStmt->execute();
    $tmp = ['communications' => [], 'management' => [], 'assessment' => []];
    while ($row = $isoStmt->fetch(PDO::FETCH_ASSOC)) {
        $cat = (string)($row['category'] ?? '');
        $txt = trim((string)($row['criterion_text'] ?? ''));
        if (isset($tmp[$cat]) && $txt !== '') $tmp[$cat][] = $txt;
    }
    foreach ($tmp as $cat => $items) {
        if (!empty($items)) $isoIndicators[$cat] = $items;
    }
} catch (PDOException $e) {
    // fallback to defaults
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = ['form_code_no', 'issue_status', 'revision_no', 'date_effective', 'approved_by'];
    $updateStmt = $db->prepare("INSERT INTO form_settings (setting_key, setting_value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = :val2, updated_at = NOW()");
    $ok = true;

    $postedIndicators = [];
    foreach (['communications', 'management', 'assessment'] as $cat) {
        $rows = $_POST['iso_' . $cat . '_rows'] ?? [];
        if (!is_array($rows)) $rows = [];
        $clean = [];
        foreach ($rows as $line) {
            $line = trim((string)$line);
            if ($line !== '') $clean[] = $line;
        }
        $postedIndicators[$cat] = $clean;
        if (empty($postedIndicators[$cat])) {
            $ok = false;
        }
    }

    if (!$ok) {
        $_SESSION['error'] = "Each ISO category must have at least one indicator.";
        header("Location: form_settings.php");
        exit();
    }

    try {
        $db->beginTransaction();
        foreach ($fields as $f) {
            $val = trim($_POST[$f] ?? '');
            if ($val === '') continue;
            $updateStmt->execute([':key' => $f, ':val' => $val, ':val2' => $val]);
        }

        $delStmt = $db->prepare("DELETE FROM evaluation_criteria WHERE category IN ('communications','management','assessment')");
        $delStmt->execute();
        $insStmt = $db->prepare("INSERT INTO evaluation_criteria (category, criterion_index, criterion_text)
                                 VALUES (:category, :idx, :txt)");
        foreach ($postedIndicators as $cat => $items) {
            foreach (array_values($items) as $idx => $txt) {
                $insStmt->execute([
                    ':category' => $cat,
                    ':idx' => $idx,
                    ':txt' => $txt
                ]);
            }
        }
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $ok = false;
    }

    $_SESSION[$ok ? 'success' : 'error'] = $ok ? "Form settings and ISO indicators updated successfully." : "Failed to update form settings.";
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

                        <hr class="my-4">
                        <h6 class="fw-bold mb-3">ISO Indicators (Dynamic)</h6>
                        <p class="text-muted small">Edit indicators per row (one sentence each). These will be used in the ISO evaluation form.</p>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Communications Indicators</label>
                            <div id="communicationsRows" class="indicator-rows">
                                <?php foreach (($isoIndicators['communications'] ?? []) as $item): ?>
                                    <div class="input-group mb-2">
                                        <span class="input-group-text">-</span>
                                        <input type="text" class="form-control" name="iso_communications_rows[]" value="<?php echo htmlspecialchars($item); ?>" required>
                                        <button type="button" class="btn btn-outline-danger" onclick="removeIndicatorRow(this)"><i class="fas fa-times"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addIndicatorRow('communicationsRows','iso_communications_rows[]')">
                                <i class="fas fa-plus me-1"></i>Add Row
                            </button>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Management Indicators</label>
                            <div id="managementRows" class="indicator-rows">
                                <?php foreach (($isoIndicators['management'] ?? []) as $item): ?>
                                    <div class="input-group mb-2">
                                        <span class="input-group-text">-</span>
                                        <input type="text" class="form-control" name="iso_management_rows[]" value="<?php echo htmlspecialchars($item); ?>" required>
                                        <button type="button" class="btn btn-outline-danger" onclick="removeIndicatorRow(this)"><i class="fas fa-times"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addIndicatorRow('managementRows','iso_management_rows[]')">
                                <i class="fas fa-plus me-1"></i>Add Row
                            </button>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Assessment Indicators</label>
                            <div id="assessmentRows" class="indicator-rows">
                                <?php foreach (($isoIndicators['assessment'] ?? []) as $item): ?>
                                    <div class="input-group mb-2">
                                        <span class="input-group-text">-</span>
                                        <input type="text" class="form-control" name="iso_assessment_rows[]" value="<?php echo htmlspecialchars($item); ?>" required>
                                        <button type="button" class="btn btn-outline-danger" onclick="removeIndicatorRow(this)"><i class="fas fa-times"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addIndicatorRow('assessmentRows','iso_assessment_rows[]')">
                                <i class="fas fa-plus me-1"></i>Add Row
                            </button>
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
    <script>
        function addIndicatorRow(containerId, inputName) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const row = document.createElement('div');
            row.className = 'input-group mb-2';
            row.innerHTML = `
                <span class="input-group-text">-</span>
                <input type="text" class="form-control" name="${inputName}" required>
                <button type="button" class="btn btn-outline-danger" onclick="removeIndicatorRow(this)"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(row);
        }

        function removeIndicatorRow(btn) {
            const row = btn.closest('.input-group');
            const container = row ? row.parentElement : null;
            if (!row || !container) return;
            if (container.querySelectorAll('.input-group').length <= 1) {
                row.querySelector('input').value = '';
                row.querySelector('input').focus();
                return;
            }
            row.remove();
        }
    </script>
</body>
</html>
