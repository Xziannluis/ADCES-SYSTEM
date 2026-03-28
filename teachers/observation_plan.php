<?php
session_start();

// Allow teachers and evaluators who are also teachers
$allowed_roles = ['teacher', 'dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$teacher_id = $_SESSION['teacher_id'] ?? null;

// If teacher_id not in session, try to resolve it now (e.g. teacher record linked after login)
if (empty($teacher_id) && !empty($_SESSION['user_id'])) {
    $resolve_stmt = $db->prepare("SELECT id FROM teachers WHERE user_id = :uid LIMIT 1");
    $resolve_stmt->execute([':uid' => $_SESSION['user_id']]);
    $resolved = $resolve_stmt->fetch(PDO::FETCH_ASSOC);
    if ($resolved) {
        $teacher_id = $resolved['id'];
        $_SESSION['teacher_id'] = $teacher_id;
    } elseif (!empty($_SESSION['name']) && !empty($_SESSION['department'])) {
        // Fallback: match by name and department, then link
        $name_stmt = $db->prepare("SELECT id FROM teachers WHERE name = :name AND department = :dept AND user_id IS NULL LIMIT 1");
        $name_stmt->execute([':name' => $_SESSION['name'], ':dept' => $_SESSION['department']]);
        $name_match = $name_stmt->fetch(PDO::FETCH_ASSOC);
        if ($name_match) {
            $link_stmt = $db->prepare("UPDATE teachers SET user_id = :uid WHERE id = :tid");
            $link_stmt->execute([':uid' => $_SESSION['user_id'], ':tid' => $name_match['id']]);
            $teacher_id = $name_match['id'];
            $_SESSION['teacher_id'] = $teacher_id;
        }
    }
}

if (!$teacher_id) {
    $_SESSION['error'] = "Teacher record not found.";
    header("Location: dashboard.php");
    exit();
}

$success_message = '';
$error_message = '';

// Handle acknowledgment POST — per-schedule signing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'acknowledge') {
    $ack_semester = trim($_POST['semester'] ?? '');
    $ack_academic_year = trim($_POST['academic_year'] ?? '');
    $signed_items = $_POST['signed_items'] ?? [];

    if (in_array($ack_semester, ['1st', '2nd']) && !empty($ack_academic_year) && is_array($signed_items) && count($signed_items) > 0) {
        $signature_data = $_POST['signature_data'] ?? null;
        if ($signature_data && !preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $signature_data)) {
            $signature_data = null;
        }
        $signed_count = 0;
        $signed_eval_ids = [];
        $has_upcoming = false;
        foreach ($signed_items as $item) {
            $eval_id = ($item === 'upcoming') ? null : (int)$item;
            if ($eval_id === null) {
                $check = $db->prepare("SELECT id FROM observation_plan_acknowledgments WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem AND evaluation_id IS NULL LIMIT 1");
                $check->execute([':tid' => $teacher_id, ':ay' => $ack_academic_year, ':sem' => $ack_semester]);
            } else {
                $check = $db->prepare("SELECT id FROM observation_plan_acknowledgments WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem AND evaluation_id = :eid LIMIT 1");
                $check->execute([':tid' => $teacher_id, ':ay' => $ack_academic_year, ':sem' => $ack_semester, ':eid' => $eval_id]);
            }
            if ($check->rowCount() === 0) {
                $ins = $db->prepare("INSERT INTO observation_plan_acknowledgments (teacher_id, academic_year, semester, evaluation_id, acknowledged_at, signature) VALUES (:tid, :ay, :sem, :eid, NOW(), :sig)");
                $ins->execute([':tid' => $teacher_id, ':ay' => $ack_academic_year, ':sem' => $ack_semester, ':eid' => $eval_id, ':sig' => $signature_data]);
                $signed_count++;
                if ($eval_id !== null) {
                    $signed_eval_ids[] = $eval_id;
                } else {
                    $has_upcoming = true;
                }
            }
        }
        if ($signed_count > 0) {
            $success_message = "Successfully signed {$signed_count} observation schedule(s).";
            // Determine departments of signed schedules
            $signed_depts = [];
            if (!empty($signed_eval_ids)) {
                $ph = implode(',', array_fill(0, count($signed_eval_ids), '?'));
                $dStmt = $db->prepare("SELECT DISTINCT u.department FROM evaluations e JOIN users u ON u.id = e.evaluator_id WHERE e.id IN ($ph) AND u.department IS NOT NULL AND u.department != ''");
                $dStmt->execute(array_values($signed_eval_ids));
                while ($r = $dStmt->fetch(PDO::FETCH_ASSOC)) {
                    $signed_depts[] = $r['department'];
                }
            }
            if ($has_upcoming && empty($signed_depts)) {
                // Upcoming schedule belongs to teacher's primary department
                $pdStmt = $db->prepare("SELECT department FROM teachers WHERE id = :id LIMIT 1");
                $pdStmt->execute([':id' => $teacher_id]);
                $pd = $pdStmt->fetchColumn();
                if (!empty($pd)) $signed_depts[] = $pd;
            }
            // Notify evaluators in those specific departments
            require_once __DIR__ . '/../includes/mailer.php';
            notifyObservationPlanSigned($db, $teacher_id, $_SESSION['name'] ?? 'Teacher', $signed_depts);
        } else {
            $success_message = "Selected schedules were already signed.";
        }
    } else {
        $error_message = "Please select at least one schedule to sign.";
    }
}

// Flash messages
if (!empty($_SESSION['success'])) { $success_message = $_SESSION['success']; unset($_SESSION['success']); }
if (!empty($_SESSION['error'])) { $error_message = $_SESSION['error']; unset($_SESSION['error']); }

// Filters
$semester = $_GET['semester'] ?? '1st';
$academic_year = $_GET['academic_year'] ?? '';
$filter_month = $_GET['month'] ?? '';

if (empty($academic_year)) {
    $month = (int)date('n');
    $year = (int)date('Y');
    if ($month >= 6) {
        $academic_year = $year . '-' . ($year + 1);
    } else {
        $academic_year = ($year - 1) . '-' . $year;
    }
}

// Get teacher info
$t_stmt = $db->prepare("SELECT t.*, t.evaluation_schedule, t.evaluation_room, t.evaluation_focus, t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester FROM teachers t WHERE t.id = :id LIMIT 1");
$t_stmt->execute([':id' => $teacher_id]);
$teacher_data = $t_stmt->fetch(PDO::FETCH_ASSOC);

if (!$teacher_data) {
    $_SESSION['error'] = "Teacher not found.";
    header("Location: dashboard.php");
    exit();
}

// Focus label mapping
$focus_labels = [
    'communications' => 'Communication Competence',
    'management' => 'Management and Presentation of the Lesson',
    'assessment' => "Assessment of Students' Learning",
];
// PEAC focus labels are exclusive to JHS department
if (($teacher_data['department'] ?? '') === 'JHS') {
    $focus_labels['teacher_actions'] = 'Teacher Actions';
    $focus_labels['student_learning_actions'] = 'Student Learning Actions';
}

// Get evaluators assigned to this teacher
$obs_query = "SELECT DISTINCT u.name, u.role FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :tid ORDER BY u.name";
$obs_stmt = $db->prepare($obs_query);
$obs_stmt->execute([':tid' => $teacher_id]);
$observers = $obs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get deans/principals from ALL departments this teacher belongs to (primary + secondary), excluding self
$t_all_depts = [$teacher_data['department']];
try {
    $sec_stmt = $db->prepare("SELECT department FROM teacher_departments WHERE teacher_id = :tid");
    $sec_stmt->execute([':tid' => $teacher_id]);
    while ($sd = $sec_stmt->fetchColumn()) {
        if (!in_array($sd, $t_all_depts)) $t_all_depts[] = $sd;
    }
} catch (Exception $e) {}
$ph_depts = implode(',', array_fill(0, count($t_all_depts), '?'));
$dean_query = "SELECT DISTINCT name FROM users WHERE department IN ($ph_depts) AND role IN ('dean','principal') AND status = 'active' AND id != ? ORDER BY name";
$dean_stmt = $db->prepare($dean_query);
$dean_stmt->execute(array_merge($t_all_depts, [$_SESSION['user_id']]));

$all_observer_names = [];
while ($dean_name_row = $dean_stmt->fetchColumn()) {
    $all_observer_names[] = $dean_name_row;
}
// If current user is a coordinator, only the deans observe them
if (!in_array($_SESSION['role'] ?? '', ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
    foreach ($observers as $obs) {
        if ($obs['name'] === ($_SESSION['name'] ?? '')) continue; // exclude self
        if (!in_array($obs['name'], $all_observer_names)) {
            $all_observer_names[] = $obs['name'];
        }
    }
}

// Build observation plan data
$has_schedule = !empty($teacher_data['evaluation_schedule']);
$has_matching_schedule = $has_schedule && ($teacher_data['evaluation_semester'] === $semester || empty($teacher_data['evaluation_semester']));

// Get completed evaluations for this semester
$eval_query = "SELECT e.id, e.observation_date, e.status, e.subject_area, e.subject_observed, e.observation_room, e.semester, e.evaluation_focus, u.name as evaluator_name
               FROM evaluations e
               JOIN users u ON e.evaluator_id = u.id
               WHERE e.teacher_id = :tid AND e.academic_year = :ay AND e.semester = :sem
               ORDER BY e.observation_date ASC";
$eval_stmt = $db->prepare($eval_query);
$eval_stmt->execute([':tid' => $teacher_id, ':ay' => $academic_year, ':sem' => $semester]);
$evaluations = $eval_stmt->fetchAll(PDO::FETCH_ASSOC);

// Check acknowledgment status — per-item
$ack_query = "SELECT * FROM observation_plan_acknowledgments WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem";
$ack_stmt = $db->prepare($ack_query);
$ack_stmt->execute([':tid' => $teacher_id, ':ay' => $academic_year, ':sem' => $semester]);
$ack_rows = $ack_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build lookup: evaluation_id => acknowledgment row (null/'upcoming' for upcoming schedule)
$signed_map = [];
foreach ($ack_rows as $ack) {
    $key = $ack['evaluation_id'] === null ? 'upcoming' : (int)$ack['evaluation_id'];
    $signed_map[$key] = $ack;
}

// Apply month filter
$show_upcoming = $has_matching_schedule;
if (!empty($filter_month) && $has_matching_schedule) {
    $sched_month = (int)date('n', strtotime($teacher_data['evaluation_schedule']));
    if ($sched_month != (int)$filter_month) $show_upcoming = false;
}
if (!empty($filter_month)) {
    $evaluations = array_filter($evaluations, function($ev) use ($filter_month) {
        $date = $ev['observation_date'] ?? '';
        if (empty($date)) return false;
        return (int)date('n', strtotime($date)) == (int)$filter_month;
    });
    $evaluations = array_values($evaluations);
}

// Count unsigned items
$unsigned_count = 0;
if ($show_upcoming && !isset($signed_map['upcoming'])) $unsigned_count++;
foreach ($evaluations as $ev) {
    if (!isset($signed_map[(int)$ev['id']])) $unsigned_count++;
}

$department_map = [
    'CCIS' => 'College of Computing and Information Sciences',
    'COE'  => 'College of Education',
    'CBA'  => 'College of Business Administration',
    'CCJE' => 'College of Criminal Justice Education',
    'CAS'  => 'College of Arts and Sciences',
    'CHM'  => 'College of Hospitality Management',
    'CTE'  => 'College of Teacher Education',
    'BASIC ED' => 'Basic Education Department',
    'ELEM' => 'Elementary Department',
    'JHS'  => 'Junior High School Department',
    'SHS'  => 'Senior High School Department',
];
$department_display = $department_map[$teacher_data['department']] ?? $teacher_data['department'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Observation Plan</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .plan-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            padding: 30px;
            margin-bottom: 20px;
        }
        .plan-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .plan-table th, .plan-table td {
            border: 1px solid #dee2e6;
            padding: 10px 12px;
            vertical-align: middle;
        }
        .plan-table th {
            background: #2c3e50;
            color: white;
            font-weight: 600;
            text-align: center;
            font-size: 0.85rem;
        }
        .plan-table td {
            font-size: 0.9rem;
        }
        .plan-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        .ack-section {
            background: #e8f5e9;
            border: 2px solid #4caf50;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            margin-top: 20px;
        }
        .ack-section.pending {
            background: #fff3e0;
            border-color: #ff9800;
        }
        .schedule-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 0;
        }
        .schedule-detail i {
            width: 20px;
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content" style="padding:0;">
        <div class="dashboard-bg-layer"><div class="bg-img"></div></div>
        <div class="dashboard-topbar">
            <h2>Saint Michael College of Caraga</h2>
            <div class="ms-auto d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (Teacher)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

            <h4 class="mb-3"><i class="fas fa-clipboard-list me-2"></i>My Observation Plan</h4>

            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Academic Year</label>
                            <select name="academic_year" class="form-select">
                                <option value="2025-2026" <?php echo $academic_year === '2025-2026' ? 'selected' : ''; ?>>2025-2026</option>
                                <option value="2026-2027" <?php echo $academic_year === '2026-2027' ? 'selected' : ''; ?>>2026-2027</option>
                                <option value="2027-2028" <?php echo $academic_year === '2027-2028' ? 'selected' : ''; ?>>2027-2028</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Semester</label>
                            <select name="semester" class="form-select">
                                <option value="1st" <?php echo $semester === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                                <option value="2nd" <?php echo $semester === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Month</label>
                            <select name="month" class="form-select">
                                <option value="">All Months</option>
                                <?php
                                $months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                                foreach ($months as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php echo $filter_month == $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Observation Plan Details -->
            <div class="plan-card">
                <div class="text-center mb-3">
                    <h5 class="fw-bold">Classroom Observation Plan</h5>
                    <p class="text-muted mb-1"><?php echo htmlspecialchars($department_display); ?></p>
                    <p class="text-muted"><?php echo htmlspecialchars($semester); ?> Semester SY <?php echo htmlspecialchars($academic_year); ?></p>
                </div>

                <?php if ($show_upcoming || count($evaluations) > 0): ?>

                <div class="mb-3 d-flex justify-content-end no-print">
                    <button class="btn btn-primary" id="signToggleBtn" disabled onclick="toggleSignPanel()">
                        <i class="fas fa-signature me-1"></i>Sign <span id="signBadgeCount" class="badge bg-light text-dark ms-1" style="display:none;">0</span>
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="plan-table">
                        <thead>
                            <tr>
                                <th style="width:50px;"><i class="fas fa-check-square"></i></th>
                                <th>Semester</th>
                                <th>Focus of Observation</th>
                                <th>Date</th>
                                <th>Day &amp; Time</th>
                                <th>Subject Area</th>
                                <th>Subject</th>
                                <th>Room</th>
                                <th>Observers</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($show_upcoming): ?>
                            <?php
                                $focus_raw = $teacher_data['evaluation_focus'] ?? '';
                                $focus_arr = [];
                                if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
                                $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);
                                $ts = strtotime($teacher_data['evaluation_schedule']);
                                $upcoming_signed = isset($signed_map['upcoming']);
                            ?>
                            <tr>
                                <td class="text-center">
                                    <?php if ($upcoming_signed): ?>
                                        <i class="fas fa-check-circle text-success" title="Signed on <?php echo date('M d, Y g:i A', strtotime($signed_map['upcoming']['acknowledged_at'])); ?>"></i>
                                    <?php else: ?>
                                        <input type="checkbox" class="form-check-input sign-item-check" value="upcoming" style="width:20px;height:20px;">
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo htmlspecialchars(($teacher_data['evaluation_semester'] ?? '') . ' Semester'); ?></td>
                                <td style="font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $focus_display)); ?></td>
                                <td class="text-center"><?php echo date('M d, Y', $ts); ?></td>
                                <td class="text-center"><?php echo date('D', $ts) . '<br>' . date('g:i A', $ts); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($teacher_data['evaluation_subject_area'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($teacher_data['evaluation_subject'] ?? ''); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($teacher_data['evaluation_room'] ?? ''); ?></td>
                                <td style="font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $all_observer_names)); ?></td>
                                <td class="text-center">
                                    <?php if ($upcoming_signed): ?>
                                        <span class="badge bg-success">Signed</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Upcoming</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <?php foreach ($evaluations as $ev): ?>
                            <?php
                                $ev_focus_raw = $ev['evaluation_focus'] ?? '';
                                $ev_focus_arr = [];
                                if ($ev_focus_raw) { try { $ev_focus_arr = json_decode($ev_focus_raw, true) ?: []; } catch (\Exception $e) {} }
                                $ev_focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $ev_focus_arr);
                                $ev_signed = isset($signed_map[(int)$ev['id']]);
                            ?>
                            <tr>
                                <td class="text-center">
                                    <?php if ($ev_signed): ?>
                                        <i class="fas fa-check-circle text-success" title="Signed on <?php echo date('M d, Y g:i A', strtotime($signed_map[(int)$ev['id']]['acknowledged_at'])); ?>"></i>
                                    <?php else: ?>
                                        <input type="checkbox" class="form-check-input sign-item-check" value="<?php echo (int)$ev['id']; ?>" style="width:20px;height:20px;">
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo htmlspecialchars(($ev['semester'] ?? '') . ' Semester'); ?></td>
                                <td style="font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $ev_focus_display)); ?></td>
                                <td class="text-center"><?php echo !empty($ev['observation_date']) ? date('M d, Y', strtotime($ev['observation_date'])) : ''; ?></td>
                                <td class="text-center"><?php echo !empty($ev['observation_date']) ? date('D', strtotime($ev['observation_date'])) : ''; ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($ev['subject_area'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($ev['subject_observed'] ?? ''); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($ev['observation_room'] ?? ''); ?></td>
                                <td style="font-size:0.85rem;"><?php echo htmlspecialchars($ev['evaluator_name'] ?? ''); ?></td>
                                <td class="text-center">
                                    <?php if ($ev_signed): ?>
                                        <span class="badge bg-success">Signed</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Teacher Signature Section (hidden until Sign button clicked) -->
                <?php if ($unsigned_count > 0): ?>
                <div id="signPanel" style="display:none;" class="mt-3">
                    <div style="background:#fff3e0;border:2px solid #ff9800;border-radius:10px;padding:20px;text-align:center;">
                        <h5>Draw Your Signature</h5>
                        <p class="text-muted small" id="selectedCount">0 schedule(s) selected</p>
                        
                        <div class="mb-3" style="display:inline-block;">
                            <canvas id="signatureCanvas" width="400" height="150" style="border: 2px solid #333; border-radius: 8px; background: #fff; cursor: crosshair;"></canvas>
                            <div class="mt-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSignature()">
                                    <i class="fas fa-eraser me-1"></i>Clear
                                </button>
                            </div>
                        </div>
                        
                        <form method="POST" id="signatureForm">
                            <input type="hidden" name="action" value="acknowledge">
                            <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                            <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                            <input type="hidden" name="signature_data" id="signatureData">
                            <div id="signedItemsContainer"></div>
                            <button type="submit" class="btn btn-success btn-lg" id="signBtn" onclick="return submitSignature();">
                                <i class="fas fa-signature me-2"></i>Sign Selected Schedules
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-clipboard fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Observation Plan Yet</h5>
                    <p class="text-muted">No observation schedule has been set for you this <?php echo htmlspecialchars($semester); ?> Semester.</p>
                </div>
                <?php endif; ?>
            </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
    // Sign panel toggle
    var signPanel = document.getElementById('signPanel');
    var signToggleBtn = document.getElementById('signToggleBtn');
    var signBadgeCount = document.getElementById('signBadgeCount');

    function toggleSignPanel() {
        if (!signPanel) return;
        if (signPanel.style.display === 'none') {
            signPanel.style.display = '';
            initSignatureCanvas();
        } else {
            signPanel.style.display = 'none';
        }
    }

    // Signature Canvas + Checklist
    var sigCanvas = document.getElementById('signatureCanvas');
    var sigCtx = null;
    var sigDrawing = false;
    var sigHasDrawn = false;
    var signBtn = document.getElementById('signBtn');
    var countEl = document.getElementById('selectedCount');
    var itemsContainer = document.getElementById('signedItemsContainer');
    var sigInited = false;

    function initSignatureCanvas() {
        if (sigInited || !sigCanvas) return;
        sigInited = true;
        sigCtx = sigCanvas.getContext('2d');

        function getSigPos(e) {
            var rect = sigCanvas.getBoundingClientRect();
            var scaleX = sigCanvas.width / rect.width;
            var scaleY = sigCanvas.height / rect.height;
            if (e.touches) {
                return { x: (e.touches[0].clientX - rect.left) * scaleX, y: (e.touches[0].clientY - rect.top) * scaleY };
            }
            return { x: (e.clientX - rect.left) * scaleX, y: (e.clientY - rect.top) * scaleY };
        }

        sigCanvas.addEventListener('mousedown', function(e) {
            e.preventDefault(); sigDrawing = true;
            var pos = getSigPos(e); sigCtx.beginPath(); sigCtx.moveTo(pos.x, pos.y);
        });
        sigCanvas.addEventListener('mousemove', function(e) {
            if (!sigDrawing) return; e.preventDefault(); sigHasDrawn = true;
            var pos = getSigPos(e); sigCtx.lineWidth = 2; sigCtx.lineCap = 'round'; sigCtx.strokeStyle = '#000';
            sigCtx.lineTo(pos.x, pos.y); sigCtx.stroke();
        });
        sigCanvas.addEventListener('mouseup', function(e) { e.preventDefault(); sigDrawing = false; });
        sigCanvas.addEventListener('mouseleave', function() { sigDrawing = false; });
        sigCanvas.addEventListener('touchstart', function(e) {
            e.preventDefault(); sigDrawing = true;
            var pos = getSigPos(e); sigCtx.beginPath(); sigCtx.moveTo(pos.x, pos.y);
        }, { passive: false });
        sigCanvas.addEventListener('touchmove', function(e) {
            if (!sigDrawing) return; e.preventDefault(); sigHasDrawn = true;
            var pos = getSigPos(e); sigCtx.lineWidth = 2; sigCtx.lineCap = 'round'; sigCtx.strokeStyle = '#000';
            sigCtx.lineTo(pos.x, pos.y); sigCtx.stroke();
        }, { passive: false });
        sigCanvas.addEventListener('touchend', function(e) { e.preventDefault(); sigDrawing = false; });
    }

    function updateCheckboxState() {
        var checks = document.querySelectorAll('.sign-item-check:checked');
        var count = checks.length;
        if (countEl) countEl.textContent = count + ' schedule(s) selected';
        if (itemsContainer) {
            itemsContainer.innerHTML = '';
            checks.forEach(function(cb) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'signed_items[]';
                input.value = cb.value;
                itemsContainer.appendChild(input);
            });
        }
        // Update Sign button at top
        if (signToggleBtn) {
            signToggleBtn.disabled = (count === 0);
            if (count > 0) {
                signBadgeCount.textContent = count;
                signBadgeCount.style.display = '';
            } else {
                signBadgeCount.style.display = 'none';
                // Hide panel if no checkboxes selected
                if (signPanel) signPanel.style.display = 'none';
            }
        }
    }

    document.querySelectorAll('.sign-item-check').forEach(function(cb) {
        cb.addEventListener('change', updateCheckboxState);
    });

    function clearSignature() {
        if (!sigCanvas) return;
        sigCtx.clearRect(0, 0, sigCanvas.width, sigCanvas.height);
        sigHasDrawn = false;
    }

    function submitSignature() {
        if (!sigCanvas) return false;
        var checks = document.querySelectorAll('.sign-item-check:checked');
        if (checks.length === 0) {
            alert('Please select at least one schedule to sign.');
            return false;
        }
        if (!sigHasDrawn) {
            alert('Please draw your signature before signing.');
            return false;
        }
        if (!confirm('Sign ' + checks.length + ' selected schedule(s)?')) return false;
        document.getElementById('signatureData').value = sigCanvas.toDataURL('image/png');
        return true;
    }
    </script>
</body>
</html>
