<?php
require_once '../auth/session-check.php';
// Allow evaluators and leaders (president/vice_president) to access evaluation
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/Evaluation.php';
require_once '../controllers/EvaluationController.php';
require_once '../includes/program_assignments.php';

$database = new Database();
$db = $database->getConnection();

// Load form settings from database
$_formSettings = [];
try {
    $fsStmt = $db->query("SELECT setting_key, setting_value FROM form_settings");
    while ($r = $fsStmt->fetch(PDO::FETCH_ASSOC)) { $_formSettings[$r['setting_key']] = $r['setting_value']; }
} catch (PDOException $e) {}
$_fs = [
    'form_code_no'   => htmlspecialchars($_formSettings['form_code_no'] ?? 'FM-DPM-SMCC-RTH-04'),
    'issue_status'   => htmlspecialchars($_formSettings['issue_status'] ?? '02'),
    'revision_no'    => htmlspecialchars($_formSettings['revision_no'] ?? '02'),
    'date_effective' => htmlspecialchars($_formSettings['date_effective'] ?? '13 September 2023'),
    'approved_by'    => htmlspecialchars($_formSettings['approved_by'] ?? 'President'),
];

// Note: expired schedules are NOT auto-cleared here.
// Once the scheduled time passes, the evaluator can proceed to evaluate.
// Schedules are cleared only after an evaluation is submitted.

$hasTeacherDepartments = false;
try {
    $teacherDepartmentsCheck = $db ? $db->query("SHOW TABLES LIKE 'teacher_departments'") : false;
    $hasTeacherDepartments = $teacherDepartmentsCheck && $teacherDepartmentsCheck->fetch(PDO::FETCH_NUM);
} catch (PDOException $e) {
    $hasTeacherDepartments = false;
}

$teacher = new Teacher($db);
$evaluation = new Evaluation($db);
$is_leader = in_array($_SESSION['role'], ['president', 'vice_president']);

// Evaluators see teachers in their department (+ secondary departments via teacher_departments)
// plus any teachers assigned to them via teacher_assignments.
// No role exclusion: deans/principals/coordinators who also teach CAN be evaluated.
if ($is_leader) {
    // President/VP see ALL active teachers system-wide
    $query = "SELECT DISTINCT t.* FROM teachers t WHERE t.status = 'active' AND (t.user_id IS NULL OR t.user_id != :current_user_id) ORDER BY t.department, t.name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->execute();
    $teachers = $stmt;
} elseif (in_array($_SESSION['role'], ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
    // Coordinators see teachers assigned to them (cross-department through assignments)
    $assignedPrograms = resolveEvaluatorPrograms($db, $_SESSION['user_id'], $_SESSION['department'] ?? null);
    $assigned_query = "SELECT DISTINCT t.* FROM teachers t JOIN teacher_assignments ta ON ta.teacher_id = t.id WHERE ta.evaluator_id = :evaluator_id AND t.status = 'active' AND (t.user_id IS NULL OR t.user_id != :current_user_id)";
    $assigned_query .= " ORDER BY t.name";
    $stmt = $db->prepare($assigned_query);
    $stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->execute();
    $teachers = $stmt;
} else {
    // Deans/principals see teachers in their department, via secondary departments, or assigned to them
    if ($hasTeacherDepartments) {
        $query = "SELECT DISTINCT t.*
              FROM teachers t
              LEFT JOIN users u ON t.user_id = u.id
              LEFT JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :evaluator_id
              LEFT JOIN teacher_departments td ON td.teacher_id = t.id
              WHERE t.status = 'active'
                AND (t.department = :department OR td.department = :department2 OR ta.evaluator_id IS NOT NULL)
                AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              ORDER BY t.name ASC";
    } else {
        $query = "SELECT DISTINCT t.*
              FROM teachers t
              LEFT JOIN users u ON t.user_id = u.id
              LEFT JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :evaluator_id
              WHERE t.status = 'active'
                AND (t.department = :department OR ta.evaluator_id IS NOT NULL)
                AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              ORDER BY t.name ASC";
    }
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $_SESSION['department']);
    if ($hasTeacherDepartments) {
        $stmt->bindParam(':department2', $_SESSION['department']);
    }
    $stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->execute();
    $teachers = $stmt;
}

// For "both" form type: check which teachers already have a completed ISO evaluation by this evaluator
$completedIsoTeachers = [];
// General check: which teachers have already been fully evaluated by this evaluator (any form type)
$completedEvalTeachers = [];
try {
    $isoCheckStmt = $db->prepare("SELECT DISTINCT teacher_id, evaluation_form_type FROM evaluations WHERE evaluator_id = :evaluator_id AND status = 'completed'");
    $isoCheckStmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $isoCheckStmt->execute();
    while ($row = $isoCheckStmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['teacher_id'];
        $ft = $row['evaluation_form_type'] ?? 'iso';
        if ($ft === 'iso') {
            $completedIsoTeachers[$tid] = true;
        }
        if (!isset($completedEvalTeachers[$tid])) {
            $completedEvalTeachers[$tid] = [];
        }
        $completedEvalTeachers[$tid][$ft] = true;
    }
} catch (PDOException $e) {}

// Load observation plan acknowledgments to check if teachers have signed
$teacher_signed_map = [];
try {
    $eval_viewer_dept = $_SESSION['department'] ?? '';
    if (in_array($_SESSION['role'], ['president', 'vice_president'])) {
        $ack_stmt = $db->prepare("SELECT DISTINCT teacher_id FROM observation_plan_acknowledgments");
        $ack_stmt->execute();
    } else {
        $ack_stmt = $db->prepare("SELECT DISTINCT teacher_id FROM observation_plan_acknowledgments WHERE department = :dept OR department IS NULL");
        $ack_stmt->execute([':dept' => $eval_viewer_dept]);
    }
    while ($ack_row = $ack_stmt->fetch(PDO::FETCH_ASSOC)) {
        $teacher_signed_map[(int)$ack_row['teacher_id']] = true;
    }
} catch (PDOException $e) {}

// Handle form submission
if($_POST && isset($_POST['submit_evaluation'])) {
    $evalController = new EvaluationController($db);
    $result = $evalController->submitEvaluation($_POST, $_SESSION['user_id']);

    if($result['success']) {
        $_SESSION['success'] = "Evaluation submitted successfully!";
        // Go back to evaluator dashboard explicitly
        header("Location: dashboard.php");
        exit();
    } else {
        $_SESSION['error'] = "Error submitting evaluation: " . $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classroom Evaluation - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        @media (max-width: 991.98px) {
            .evaluation-section .table-responsive {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
            }
            .evaluation-section .evaluation-table {
                min-width: 900px !important;
                width: 900px !important;
                table-layout: fixed !important;
            }
        }
    </style>
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
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Teacher Selection -->
            <div class="card mb-4" id="teacherSelection">
                <div class="card-header">
                    <h5 class="mb-0">Select Teacher to Evaluate</h5>
                </div>
                <div class="card-body">
                    <?php if($teachers->rowCount() > 0): ?>
                    <div class="list-group" id="teacherList">
                        <?php while($teacher_row = $teachers->fetch(PDO::FETCH_ASSOC)): ?>
                        <?php
                            // Check if the schedule belongs to the current viewer's department
                            $viewer_dept_eval = $_SESSION['department'] ?? '';
                            $sched_dept_eval = $teacher_row['scheduled_department'] ?? '';
                            $is_leader_eval = in_array($_SESSION['role'], ['president', 'vice_president']);
                            if ($is_leader_eval) {
                                $sched_for_this_dept = true;
                            } elseif (!empty($sched_dept_eval)) {
                                $sched_for_this_dept = ($sched_dept_eval === $viewer_dept_eval);
                            } else {
                                $sched_for_this_dept = ($teacher_row['department'] === $viewer_dept_eval);
                            }

                            $scheduleRaw = $sched_for_this_dept ? ($teacher_row['evaluation_schedule'] ?? '') : '';
                            $scheduleRoom = $sched_for_this_dept ? ($teacher_row['evaluation_room'] ?? '') : '';
                            $has_schedule = !empty($scheduleRaw) || !empty($scheduleRoom);
                            $can_evaluate_now = false;
                            $schedule_message = 'No schedule set';
                            $schedule_badge_class = 'bg-secondary';
                            $schedule_badge_text = 'Schedule required';
                            $schedule_display = trim((string)$scheduleRaw);
                            $schedule_block_message = 'No schedule is set. Please ask the dean/principal to set one first.';

                            if (!empty($scheduleRaw)) {
                                try {
                                    $timezone = new DateTimeZone('Asia/Manila');
                                    $scheduledAt = new DateTime($scheduleRaw, $timezone);
                                    $scheduledAt->setTimezone($timezone);
                                    $now = new DateTime('now', $timezone);
                                    $schedule_display = $scheduledAt->format('F d, Y \a\t h:i A');
                                    $schedule_message = $scheduledAt->format('F d, Y \a\t h:i A');
                                    if ($now >= $scheduledAt) {
                                        // Check if 24-hour evaluation window has expired
                                        $expiry = clone $scheduledAt;
                                        $expiry->modify('+24 hours');
                                        if ($now > $expiry) {
                                            // Window expired — reset to schedule required
                                            $can_evaluate_now = false;
                                            $schedule_badge_class = 'bg-secondary';
                                            $schedule_badge_text = 'Schedule required';
                                            $schedule_block_message = 'The 24-hour evaluation window has expired. Please ask the dean/principal to set a new schedule.';
                                        } else {
                                            // Within the 24-hour evaluation window
                                            $can_evaluate_now = true;
                                            $schedule_badge_class = 'bg-success';
                                            $schedule_badge_text = 'Evaluate this teacher';
                                            $schedule_block_message = '';

                                            // Block if teacher hasn't signed the observation plan
                                            $teacher_has_signed = isset($teacher_signed_map[(int)$teacher_row['id']]);
                                            if (!$teacher_has_signed) {
                                                $can_evaluate_now = false;
                                                $schedule_badge_class = 'bg-warning text-dark';
                                                $schedule_badge_text = 'Awaiting signature';
                                                $schedule_block_message = 'This teacher has not yet signed the observation plan. Evaluation is blocked until they sign.';
                                            }
                                        }
                                    } else {
                                        $schedule_badge_class = 'bg-warning text-dark';
                                        $schedule_badge_text = 'Not yet time';
                                        $schedule_block_message = 'Evaluation opens on ' . $schedule_message . '.';
                                    }
                                } catch (Exception $e) {
                                    $schedule_message = 'Invalid schedule';
                                    $schedule_badge_class = 'bg-danger';
                                    $schedule_badge_text = 'Invalid schedule';
                                    $schedule_block_message = 'The saved schedule is invalid. Please ask the dean/principal to reschedule it.';
                                }
                            } elseif (!empty($scheduleRoom)) {
                                $schedule_message = 'Room assigned, waiting for date/time';
                                $schedule_badge_class = 'bg-warning text-dark';
                                $schedule_badge_text = 'Schedule incomplete';
                                $schedule_block_message = 'A room is assigned, but the evaluation date and time are still missing.';
                            }
                        ?>
                        <?php
                            $teacher_form_type = $teacher_row['evaluation_form_type'] ?? 'iso';
                            $iso_done = isset($completedIsoTeachers[(int)$teacher_row['id']]);
                            // Check if this evaluator has already completed all required evaluations for this teacher
                            $tid_check = (int)$teacher_row['id'];
                            $teacher_completed_forms = $completedEvalTeachers[$tid_check] ?? [];
                            $all_done = false;
                            if ($teacher_form_type === 'both') {
                                $all_done = !empty($teacher_completed_forms['iso']) && !empty($teacher_completed_forms['peac']);
                            } elseif ($teacher_form_type === 'peac') {
                                $all_done = !empty($teacher_completed_forms['peac']);
                            } else {
                                // iso or default
                                $all_done = !empty($teacher_completed_forms['iso']);
                            }

                            // For coordinators who completed their evaluation, reset to "Schedule required"
                            // so the dean/principal can set a new schedule for the next evaluation cycle
                            if ($all_done && in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
                                $schedule_badge_class = 'bg-secondary';
                                $schedule_badge_text = 'Schedule required';
                                $can_evaluate_now = false;
                            } elseif ($all_done) {
                                $schedule_badge_class = 'bg-info';
                                $schedule_badge_text = 'Completed';
                                $can_evaluate_now = false;
                            }
                        ?>
                        <div class="list-group-item teacher-item <?php echo ($can_evaluate_now && !$all_done) ? '' : 'disabled'; ?>" data-teacher-id="<?php echo $teacher_row['id']; ?>" data-teacher-name="<?php echo htmlspecialchars($teacher_row['name'] ?? '', ENT_QUOTES); ?>" data-has-schedule="<?php echo ($has_schedule && !$all_done) ? '1' : '0'; ?>" data-can-evaluate-now="<?php echo ($can_evaluate_now && !$all_done) ? '1' : '0'; ?>" data-schedule-message="<?php echo htmlspecialchars($schedule_message, ENT_QUOTES); ?>" data-block-reason="<?php echo htmlspecialchars($all_done ? 'Schedule required for next evaluation.' : $schedule_block_message, ENT_QUOTES); ?>" data-focus="<?php echo htmlspecialchars($teacher_row['evaluation_focus'] ?? '', ENT_QUOTES); ?>" data-semester="<?php echo htmlspecialchars($teacher_row['evaluation_semester'] ?? '', ENT_QUOTES); ?>" data-subject-area="<?php echo htmlspecialchars($teacher_row['evaluation_subject_area'] ?? '', ENT_QUOTES); ?>" data-room="<?php echo htmlspecialchars($teacher_row['evaluation_room'] ?? '', ENT_QUOTES); ?>" data-subject="<?php echo htmlspecialchars($teacher_row['evaluation_subject'] ?? '', ENT_QUOTES); ?>" data-form-type="<?php echo htmlspecialchars($teacher_form_type, ENT_QUOTES); ?>" data-iso-done="<?php echo $iso_done ? '1' : '0'; ?>" data-schedule-raw="<?php echo htmlspecialchars($teacher_row['evaluation_schedule'] ?? '', ENT_QUOTES); ?>" data-teacher-department="<?php echo htmlspecialchars($teacher_row['department'] ?? '', ENT_QUOTES); ?>" data-scheduled-department="<?php echo htmlspecialchars($teacher_row['scheduled_department'] ?? '', ENT_QUOTES); ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($teacher_row['name']); ?></h6>
                                    <p class="mb-0 text-muted"><?php
                                        // Show the teacher's actual department
                                        echo htmlspecialchars($teacher_row['department'] ?? '');
                                    ?></p>
                                    <small class="text-muted">
                                        <?php if (!empty($scheduleRaw)): ?>
                                            <i class="fas fa-calendar me-1"></i>
                                            <?php echo htmlspecialchars($schedule_display); ?>
                                        <?php elseif (!empty($scheduleRoom)): ?>
                                            <i class="fas fa-door-open me-1"></i>
                                            <?php echo htmlspecialchars($schedule_message); ?>
                                        <?php else: ?>
                                            <i class="fas fa-ban me-1"></i>No schedule set
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($teacher_form_type === 'both' && $can_evaluate_now && !$all_done): ?>
                                        <?php if ($iso_done): ?>
                                            <span class="badge bg-success p-2"><i class="fas fa-check me-1"></i>ISO Done</span>
                                            <button type="button" class="btn btn-sm btn-primary btn-peac-eval" data-teacher-id="<?php echo $teacher_row['id']; ?>">
                                                <i class="fas fa-clipboard-check me-1"></i>PEAC
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-primary btn-iso-eval" data-teacher-id="<?php echo $teacher_row['id']; ?>">
                                                <i class="fas fa-file-alt me-1"></i>ISO
                                            </button>
                                            <button type="button" class="btn btn-sm btn-secondary btn-peac-locked" disabled title="Complete the ISO evaluation first">
                                                <i class="fas fa-lock me-1"></i>PEAC
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge <?php echo $schedule_badge_class; ?> p-2"><?php echo htmlspecialchars($schedule_badge_text); ?></span>
                                        <i class="fas fa-chevron-right ms-2"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>No Active Teachers</h5>
                        <p class="text-muted">There are no active teachers available from your department or assigned list to evaluate.</p>
                        <a href="teachers.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i>Manage Teachers
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            

            <!-- Evaluation Form -->
            <div id="evaluationFormContainer" class="d-none">
                <!-- Form Code Box (matches PDF export style) -->
               
                <form id="evaluationForm" method="POST">
                    <input type="hidden" id="draft_evaluation_id" name="evaluation_id" value="">
                    <input type="hidden" name="teacher_id" id="selected_teacher_id">
                    <div class="card">
                        <div class="card-header">
                                <h5 class="mb-0 text-center">CLASSROOM EVALUATION FORM</h5>
                            <div class="row">
                                <div class="col-12 text-start">
                                    <a href="evaluation.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Back
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                            <!-- PART 1: Faculty Information -->
                            <div class="evaluation-section">
                                <h5>PART 1: Faculty Information</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Name of Faculty:</label>
                                        <input type="text" class="form-control" id="facultyName" name="faculty_name">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Academic Year:</label>
                                        <select class="form-select" id="academicYear" name="academic_year" required>
                                            <?php
                                            $m = (int)date('n'); $y = (int)date('Y');
                                            $currentAY = ($m >= 6) ? "$y-" . ($y+1) : ($y-1) . "-$y";
                                            ?>
                                            <option value="2025-2026" <?php echo $currentAY === '2025-2026' ? 'selected' : ''; ?>>2025-2026</option>
                                            <option value="2026-2027" <?php echo $currentAY === '2026-2027' ? 'selected' : ''; ?>>2026-2027</option>
                                            <option value="2027-2028" <?php echo $currentAY === '2027-2028' ? 'selected' : ''; ?>>2027-2028</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Semester:</label>
                                        <div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="semester" id="semester1" value="1st" checked required>
                                                <label class="form-check-label" for="semester1">1st</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="semester" id="semester2" value="2nd">
                                                <label class="form-check-label" for="semester2">2nd</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Department:</label>
                                        <input type="text" class="form-control" id="department" name="department">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Subject/Time of Observation:</label>
                                        <input type="text" class="form-control" id="subjectTime" name="subject_observed" placeholder="e.g., Mathematics 9:00-10:30 AM" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Date of Observation:</label>
                                        <input type="date" class="form-control" id="observationDate" name="observation_date" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Type of Classroom Observation:</label>
                                        <div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="observation_type" id="formal" value="Formal" checked required>
                                                <label class="form-check-label" for="formal">Formal</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" name="observation_type" id="informal" value="Informal">
                                                <label class="form-check-label" for="informal">Informal</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- PART 2: Mandatory Requirements -->
                            <div class="evaluation-section">
                                <h5>PART 2: Mandatory Requirements for Teachers</h5>
                                <p>Check if presented to the observer.</p>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="seatPlan" name="seat_plan" value="1">
                                            <label class="form-check-label" for="seatPlan">Seat Plan</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="courseSyllabi" name="course_syllabi" value="1">
                                            <label class="form-check-label" for="courseSyllabi">Course Syllabi</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="others" name="others_requirements" value="1">
                                            <label class="form-check-label" for="others">Others</label>
                                            <input type="text" class="form-control mt-1" id="othersSpecify" name="others_specify" placeholder="Please specify">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Rating Scale -->
                            <div class="rating-scale">
                                <h6>Rating Scale:</h6>
                                <div class="rating-scale-item">
                                    <span>5</span>
                                    <span>Excellent</span>
                                </div>
                                <div class="rating-scale-item">
                                    <span>4</span>
                                    <span>Very Satisfactory</span>
                                </div>
                                <div class="rating-scale-item">
                                    <span>3</span>
                                    <span>Satisfactory</span>
                                </div>
                                <div class="rating-scale-item">
                                    <span>2</span>
                                    <span>Below Satisfactory</span>
                                </div>
                                <div class="rating-scale-item">
                                    <span>1</span>
                                    <span>Needs Improvement</span>
                                </div>
                            </div>
                            
                            <!-- PART 3: Domains of Teaching Performance -->
                            <div class="evaluation-section">
                                <h5>PART 3: Domains of Teaching Performance</h5>
                                
                                <!-- Communications Competence -->
                                <div class="mb-4">
                                    <h6>Communications Competence</h6>
                                    <div class="table-responsive">
                                    <table class="table table-bordered evaluation-table">
                                        <thead>
                                            <tr>
                                                <th width="57%">Indicator</th>
                                                <th width="4%">5</th>
                                                <th width="4%">4</th>
                                                <th width="4%">3</th>
                                                <th width="4%">2</th>
                                                <th width="4%">1</th>
                                                <th width="23%">Comments</th>
                                            </tr>
                                        </thead>
                                        <tbody id="communicationsCompetence">
                                            <tr>
                                                <td>Uses an audible voice that can be heard at the back of the room.</td>
                                                <td><input type="radio" name="communications0" value="5" required></td>
                                                <td><input type="radio" name="communications0" value="4"></td>
                                                <td><input type="radio" name="communications0" value="3"></td>
                                                <td><input type="radio" name="communications0" value="2"></td>
                                                <td><input type="radio" name="communications0" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment0" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Speaks fluently in the language of instruction.</td>
                                                <td><input type="radio" name="communications1" value="5" required></td>
                                                <td><input type="radio" name="communications1" value="4"></td>
                                                <td><input type="radio" name="communications1" value="3"></td>
                                                <td><input type="radio" name="communications1" value="2"></td>
                                                <td><input type="radio" name="communications1" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment1" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Facilitates a dynamic discussion.</td>
                                                <td><input type="radio" name="communications2" value="5" required></td>
                                                <td><input type="radio" name="communications2" value="4"></td>
                                                <td><input type="radio" name="communications2" value="3"></td>
                                                <td><input type="radio" name="communications2" value="2"></td>
                                                <td><input type="radio" name="communications2" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment2" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses engaging non-verbal cues (facial expression, gestures).</td>
                                                <td><input type="radio" name="communications3" value="5" required></td>
                                                <td><input type="radio" name="communications3" value="4"></td>
                                                <td><input type="radio" name="communications3" value="3"></td>
                                                <td><input type="radio" name="communications3" value="2"></td>
                                                <td><input type="radio" name="communications3" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment3" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses words & expressions suited to the level of the students.</td>
                                                <td><input type="radio" name="communications4" value="5" required></td>
                                                <td><input type="radio" name="communications4" value="4"></td>
                                                <td><input type="radio" name="communications4" value="3"></td>
                                                <td><input type="radio" name="communications4" value="2"></td>
                                                <td><input type="radio" name="communications4" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="communications_comment4" placeholder="Comments"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    </div>
                                    <div class="text-end">
                                        <strong>Average: <span id="communicationsAverage">0.0</span></strong>
                                    </div>
                                </div>
                                
                                <!-- Management and Presentation of the Lesson -->
                                <div class="mb-4">
                                    <h6>Management and Presentation of the Lesson</h6>
                                    <div class="table-responsive">
                                    <table class="table table-bordered evaluation-table">
                                        <thead>
                                            <tr>
                                                <th width="57%">Indicator</th>
                                                <th width="4%">5</th>
                                                <th width="4%">4</th>
                                                <th width="4%">3</th>
                                                <th width="4%">2</th>
                                                <th width="4%">1</th>
                                                <th width="23%">Comments</th>
                                            </tr>
                                        </thead>
                                        <tbody id="managementPresentation">
                                            <tr>
                                                <td>The TILO (Topic Intended Learning Outcomes) are clearly presented.</td>
                                                <td><input type="radio" name="management0" value="5" required></td>
                                                <td><input type="radio" name="management0" value="4"></td>
                                                <td><input type="radio" name="management0" value="3"></td>
                                                <td><input type="radio" name="management0" value="2"></td>
                                                <td><input type="radio" name="management0" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment0" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Recall and connects previous lessons to the new lessons.</td>
                                                <td><input type="radio" name="management1" value="5" required></td>
                                                <td><input type="radio" name="management1" value="4"></td>
                                                <td><input type="radio" name="management1" value="3"></td>
                                                <td><input type="radio" name="management1" value="2"></td>
                                                <td><input type="radio" name="management1" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment1" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>The topic/lesson is introduced in an interesting & engaging way.</td>
                                                <td><input type="radio" name="management2" value="5" required></td>
                                                <td><input type="radio" name="management2" value="4"></td>
                                                <td><input type="radio" name="management2" value="3"></td>
                                                <td><input type="radio" name="management2" value="2"></td>
                                                <td><input type="radio" name="management2" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment2" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses current issues, real life & local examples to enrich class discussion.</td>
                                                <td><input type="radio" name="management3" value="5" required></td>
                                                <td><input type="radio" name="management3" value="4"></td>
                                                <td><input type="radio" name="management3" value="3"></td>
                                                <td><input type="radio" name="management3" value="2"></td>
                                                <td><input type="radio" name="management3" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment3" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Focuses class discussion on key concepts of the lesson.</td>
                                                <td><input type="radio" name="management4" value="5" required></td>
                                                <td><input type="radio" name="management4" value="4"></td>
                                                <td><input type="radio" name="management4" value="3"></td>
                                                <td><input type="radio" name="management4" value="2"></td>
                                                <td><input type="radio" name="management4" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment4" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Encourages active participation among students and ask questions about the topic.</td>
                                                <td><input type="radio" name="management5" value="5" required></td>
                                                <td><input type="radio" name="management5" value="4"></td>
                                                <td><input type="radio" name="management5" value="3"></td>
                                                <td><input type="radio" name="management5" value="2"></td>
                                                <td><input type="radio" name="management5" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment5" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses current instructional strategies and resources.</td>
                                                <td><input type="radio" name="management6" value="5" required></td>
                                                <td><input type="radio" name="management6" value="4"></td>
                                                <td><input type="radio" name="management6" value="3"></td>
                                                <td><input type="radio" name="management6" value="2"></td>
                                                <td><input type="radio" name="management6" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment6" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Designs teaching aids that facilitate understanding of key concepts.</td>
                                                <td><input type="radio" name="management7" value="5" required></td>
                                                <td><input type="radio" name="management7" value="4"></td>
                                                <td><input type="radio" name="management7" value="3"></td>
                                                <td><input type="radio" name="management7" value="2"></td>
                                                <td><input type="radio" name="management7" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment7" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Adapts teaching approach in the light of student feedback and reactions.</td>
                                                <td><input type="radio" name="management8" value="5" required></td>
                                                <td><input type="radio" name="management8" value="4"></td>
                                                <td><input type="radio" name="management8" value="3"></td>
                                                <td><input type="radio" name="management8" value="2"></td>
                                                <td><input type="radio" name="management8" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment8" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Aids students using thought provoking questions (Art of Questioning).</td>
                                                <td><input type="radio" name="management9" value="5" required></td>
                                                <td><input type="radio" name="management9" value="4"></td>
                                                <td><input type="radio" name="management9" value="3"></td>
                                                <td><input type="radio" name="management9" value="2"></td>
                                                <td><input type="radio" name="management9" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment9" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Integrate the institutional core values to the lessons.</td>
                                                <td><input type="radio" name="management10" value="5" required></td>
                                                <td><input type="radio" name="management10" value="4"></td>
                                                <td><input type="radio" name="management10" value="3"></td>
                                                <td><input type="radio" name="management10" value="2"></td>
                                                <td><input type="radio" name="management10" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment10" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Conduct the lesson using the principle of SMART</td>
                                                <td><input type="radio" name="management11" value="5" required></td>
                                                <td><input type="radio" name="management11" value="4"></td>
                                                <td><input type="radio" name="management11" value="3"></td>
                                                <td><input type="radio" name="management11" value="2"></td>
                                                <td><input type="radio" name="management11" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="management_comment11" placeholder="Comments"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    </div>
                                    <div class="text-end">
                                        <strong>Average: <span id="managementAverage">0.0</span></strong>
                                    </div>
                                </div>
                                
                                <!-- Assessment of Students' Learning -->
                                <div class="mb-4">
                                    <h6>Assessment of Students' Learning</h6>
                                    <div class="table-responsive">
                                    <table class="table table-bordered evaluation-table">
                                        <thead>
                                            <tr>
                                                <th width="57%">Indicator</th>
                                                <th width="4%">5</th>
                                                <th width="4%">4</th>
                                                <th width="4%">3</th>
                                                <th width="4%">2</th>
                                                <th width="4%">1</th>
                                                <th width="23%">Comments</th>
                                            </tr>
                                        </thead>
                                        <tbody id="assessmentLearning">
                                            <tr>
                                                <td>Monitors students' understanding on key concepts discussed.</td>
                                                <td><input type="radio" name="assessment0" value="5" required></td>
                                                <td><input type="radio" name="assessment0" value="4"></td>
                                                <td><input type="radio" name="assessment0" value="3"></td>
                                                <td><input type="radio" name="assessment0" value="2"></td>
                                                <td><input type="radio" name="assessment0" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment0" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Uses assessment tool that relates specific course competencies stated in the syllabus.</td>
                                                <td><input type="radio" name="assessment1" value="5" required></td>
                                                <td><input type="radio" name="assessment1" value="4"></td>
                                                <td><input type="radio" name="assessment1" value="3"></td>
                                                <td><input type="radio" name="assessment1" value="2"></td>
                                                <td><input type="radio" name="assessment1" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment1" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Design test/quarter/assignments and other assessment tasks that are corrector-based.</td>
                                                <td><input type="radio" name="assessment2" value="5" required></td>
                                                <td><input type="radio" name="assessment2" value="4"></td>
                                                <td><input type="radio" name="assessment2" value="3"></td>
                                                <td><input type="radio" name="assessment2" value="2"></td>
                                                <td><input type="radio" name="assessment2" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment2" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Introduces varied activities that will answer the differentiated needs to the learners with varied learning style.</td>
                                                <td><input type="radio" name="assessment3" value="5" required></td>
                                                <td><input type="radio" name="assessment3" value="4"></td>
                                                <td><input type="radio" name="assessment3" value="3"></td>
                                                <td><input type="radio" name="assessment3" value="2"></td>
                                                <td><input type="radio" name="assessment3" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment3" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Conducts normative assessment before evaluating and grading the learner's performance outcome.</td>
                                                <td><input type="radio" name="assessment4" value="5" required></td>
                                                <td><input type="radio" name="assessment4" value="4"></td>
                                                <td><input type="radio" name="assessment4" value="3"></td>
                                                <td><input type="radio" name="assessment4" value="2"></td>
                                                <td><input type="radio" name="assessment4" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment4" placeholder="Comments"></td>
                                            </tr>
                                            <tr>
                                                <td>Monitors the formative assessment results and find ways to ensure learning for the learners.</td>
                                                <td><input type="radio" name="assessment5" value="5" required></td>
                                                <td><input type="radio" name="assessment5" value="4"></td>
                                                <td><input type="radio" name="assessment5" value="3"></td>
                                                <td><input type="radio" name="assessment5" value="2"></td>
                                                <td><input type="radio" name="assessment5" value="1"></td>
                                                <td><input type="text" class="form-control form-control-sm" name="assessment_comment5" placeholder="Comments"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    </div>
                                    <div class="text-end">
                                        <strong>Average: <span id="assessmentAverage">0.0</span></strong>
                                    </div>
                                </div>
                                
                                <!-- Overall Rating -->
                                <div class="mb-4">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Overall Rating Interpretation</h6>
                                            <div class="rating-scale">
                                                <div class="rating-scale-item">
                                                    <span>5</span>
                                                    <span>Excellent</span>
                                                </div>
                                                <div class="rating-scale-item">
                                                    <span>4</span>
                                                    <span>Very Satisfactory</span>
                                                </div>
                                                <div class="rating-scale-item">
                                                    <span>3</span>
                                                    <span>Satisfactory</span>
                                                </div>
                                                <div class="rating-scale-item">
                                                    <span>2</span>
                                                    <span>Below Satisfactory</span>
                                                </div>
                                                <div class="rating-scale-item">
                                                    <span>1</span>
                                                    <span>Needs Improvement</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="text-center p-4">
                                                <h4>Total Average</h4>
                                                <div class="display-4 text-primary" id="totalAverage">0.0</div>
                                                <h5 id="ratingInterpretation">Not Rated</h5>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-center">
                                    <button
                                        type="button"
                                        id="generateAI"
                                        class="btn"
                                        style="color: white; background-color: #31b5c9ff;"
                                    >
                                        <i class="fas fa-magic me-2"></i> Generate AI Recommendation
                                    </button>
                                </div>

                                <div class="mt-2" id="aiDebugPanel" style="display:none; max-width: 900px; margin: 0 auto;">
                                    <div class="alert alert-info py-2 mb-0" style="font-size: 0.9rem;">
                                        <strong>AI status:</strong> <span id="aiDebugText">Idle</span>
                                    </div>
                                </div>

                                <div class="mt-3" id="aiSuggestionPanel" style="display:none; max-width: 1100px; margin: 0 auto;">
                                    <!-- Removed AI suggestion meta badge -->
                                </div>

                                <!-- Strengths and Areas for Improvement -->
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">
                                                STRENGTHS:
                                            </span>
                                            <textarea class="form-control" id="strengths" name="strengths" rows="3" placeholder="List the teacher's strengths observed during the evaluation"></textarea>
                                        </div>
                                        <div class="mt-2 ai-category-panel" id="aiStrengthsPanel" style="display:none;">
                                            <div class="ai-suggestion-wrap">
                                                <div id="aiSuggestionStrengths" class="ai-suggestion-content small"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">
                                                AREAS FOR IMPROVEMENT:
                                            </span>
                                            <textarea class="form-control" id="improvementAreas" name="improvement_areas" rows="3" placeholder="List areas where the teacher can improve"></textarea>
                                        </div>
                                        <div class="mt-2 ai-category-panel" id="aiImprovementsPanel" style="display:none;">
                                            <div class="ai-suggestion-wrap">
                                                <div id="aiSuggestionImprovements" class="ai-suggestion-content small"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">
                                                RECOMMENDATIONS:
                                            </span>
                                            <textarea class="form-control" id="recommendations" name="recommendations" rows="3" placeholder="Provide specific recommendations for improvement"></textarea>
                                        </div>
                                        <div class="mt-2 ai-category-panel" id="aiRecommendationsPanel" style="display:none;">
                                            <div class="ai-suggestion-wrap">
                                                <div id="aiSuggestionRecommendations" class="ai-suggestion-content small"></div>
                                            </div>
                                        </div>
                                    </div>
                                        <div class="col-md-6">
                                            <div class="input-group">
                                                <span class="input-group-text" style="border-color: #ccc; background: #fff; font-weight: 600;">
                                                    AGREEMENT:
                                                </span>
                                            <textarea class="form-control" id="agreement" name="agreement" rows="3" placeholder="State agreement or additional notes"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Agreement Section -->
                                <div class="row mt-4 align-items-stretch">
                                    <div class="col-md-6 d-flex">
                                        <div class="border p-3 w-100 d-flex flex-column">
                                            <h6>Rater/Observer</h6>
                                            <p class="small">I certify that this classroom evaluation represents my best judgment.</p>
                                            <div class="mb-3">
                                                <label class="form-label">Printed name</label>
                                                <input type="text" class="form-control" id="raterPrintedName" name="rater_printed_name" readonly required>
                                                <label class="form-label mt-2">Signature</label>
                                                <input type="hidden" id="raterSignature" name="rater_signature" required>
                                                <img id="raterSignaturePreview" alt="Rater signature preview" style="display:none; max-width: 100%; height: 60px; border: 1px solid #ced4da; border-radius: 4px; background: #fff;" />
                                                <div class="form-text">Sign using touchpad/mouse. Your signature will appear above after you click “Use this signature”.</div>
                                                <div class="mt-2">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle-sign="rater">Sign using touchpad/mouse</button>
                                                </div>
                                                <div class="signature-canvas-wrap mt-2" data-sign-wrap="rater" style="display:none;">
                                                    <canvas id="raterSignaturePad" class="signature-canvas" height="200"></canvas>
                                                    <div class="d-flex gap-2 mt-2">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-sign="rater">Clear</button>
                                                        <button type="button" class="btn btn-sm btn-primary" data-apply-sign="rater">Use this signature</button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-auto">
                                                <label class="form-label">Date</label>
                                                <input type="date" class="form-control" id="raterDate" name="rater_date" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 d-flex">
                                        <div class="border p-3 w-100 d-flex flex-column">
                                            <h6>Faculty</h6>
                                            <p class="small">I certify that this evaluation result has been discussed with me during the post conference/debriefing.</p>
                                            <div class="mb-3">
                                                <label class="form-label">Printed name</label>
                                                <input type="text" class="form-control" id="facultyPrintedName" name="faculty_printed_name" readonly required>
                                                <label class="form-label mt-2">Signature of Faculty</label>
                                                <input type="hidden" id="facultySignature" name="faculty_signature" required>
                                                <img id="facultySignaturePreview" alt="Faculty signature preview" style="display:none; max-width: 100%; height: 60px; border: 1px solid #ced4da; border-radius: 4px; background: #fff;" />
                                                <div class="form-text">Sign using touchpad/mouse. Your signature will appear above after you click “Use this signature”.</div>
                                                <div class="mt-2">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle-sign="faculty">Sign using touchpad/mouse</button>
                                                </div>
                                                <div class="signature-canvas-wrap mt-2" data-sign-wrap="faculty" style="display:none;">
                                                    <canvas id="facultySignaturePad" class="signature-canvas" height="200"></canvas>
                                                    <div class="d-flex gap-2 mt-2">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-sign="faculty">Clear</button>
                                                        <button type="button" class="btn btn-sm btn-primary" data-apply-sign="faculty">Use this signature</button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-auto">
                                                <label class="form-label">Date</label>
                                                <input type="date" class="form-control" id="facultyDate" name="faculty_date" required>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Form Details -->
                                <!-- New Form Code Bar (horizontal, wide, above form actions) -->
                                 <div style="border: 1.5px solid #1a237e; border-radius: 4px; padding: 0; margin-bottom: 24px; background: #fff; max-width: 340px; overflow: hidden;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="background: #1a237e; color: #fff; font-weight: bold; width: 40%; padding: 4px 10px; font-size: 12px; border: none;">Form Code No.</td>
                            <td style="padding: 4px 10px; font-size: 12px; border: none;">: <?php echo $_fs['form_code_no']; ?></td>
                        </tr>
                        <tr>
                            <td style="background: #1a237e; color: #fff; font-weight: bold; padding: 4px 10px; font-size: 12px; border: none;">Issue Status</td>
                            <td style="padding: 4px 10px; font-size: 12px; border: none;">: <?php echo $_fs['issue_status']; ?></td>
                        </tr>
                        <tr>
                            <td style="background: #1a237e; color: #fff; font-weight: bold; padding: 4px 10px; font-size: 12px; border: none;">Revision No.</td>
                            <td style="padding: 4px 10px; font-size: 12px; border: none;">: <?php echo $_fs['revision_no']; ?></td>
                        </tr>
                        <tr>
                            <td style="background: #1a237e; color: #fff; font-weight: bold; padding: 4px 10px; font-size: 12px; border: none;">Date Effective</td>
                            <td style="padding: 4px 10px; font-size: 12px; border: none;">: <?php echo $_fs['date_effective']; ?></td>
                        </tr>
                        <tr>
                            <td style="background: #1a237e; color: #fff; font-weight: bold; padding: 4px 10px; font-size: 12px; border: none;">Approved By</td>
                            <td style="padding: 4px 10px; font-size: 12px; border: none;">: <?php echo $_fs['approved_by']; ?></td>
                        </tr>
                    </table>
                </div>
                                <!-- END New Form Code Bar -->
                            </div>
                            
                            <!-- Form Actions -->
                            <div class="form-actions mt-4">
                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-success" name="submit_evaluation">
                                        <i class="fas fa-check me-2"></i> Submit Evaluation
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <script>
        // Light UI tweak for disabled teachers
        (function ensureDisabledTeacherStyles() {
            const style = document.createElement('style');
            style.textContent = `
                .teacher-item.disabled { opacity: 0.6; cursor: not-allowed; }
                .teacher-item.disabled:hover { background: inherit; }
                .signature-canvas { width: 100%; max-width: 420px; height: 200px; border: 1px solid #ced4da; border-radius: 4px; background: #fff; touch-action: none; cursor: crosshair; }
                .signature-canvas-wrap { user-select: none; }
                .ai-suggestion-wrap { margin-top: 10px; }
                .ai-suggestion-label { font-size: 0.9rem; color: #6c757d; margin-bottom: 8px; }
                .ai-suggestion-list { display: flex; flex-direction: column; gap: 8px; }
                .ai-suggestion-chip {
                    border: 1px solid #adb5bd;
                    border-radius: 6px;
                    background: #f8f9fa;
                    color: #5f6b76;
                    padding: 10px 44px 10px 12px;
                    position: relative;
                    font-size: 0.83rem;
                    line-height: 1.4;
                    cursor: pointer;
                    transition: all 0.15s ease-in-out;
                    text-align: center;
                    width: 100%;
                }
                .ai-suggestion-chip:hover {
                    border-color: #0d6efd;
                    background: #eef5ff;
                    color: #34495e;
                }
                .ai-suggestion-chip.is-selected {
                    border-color: #0d6efd;
                    background: #eaf3ff;
                    box-shadow: 0 0 0 0.15rem rgba(13,110,253,.12);
                }
                .ai-suggestion-chip-action {
                    position: absolute;
                    top: 50%;
                    right: 8px;
                    transform: translateY(-50%);
                    font-size: 0.68rem;
                    border: 1px solid #0d6efd;
                    color: #0d6efd;
                    background: #fff;
                    border-radius: 4px;
                    padding: 3px 6px;
                    white-space: nowrap;
                }
            `;
            document.head.appendChild(style);
        })();

        // Minimal signature pad: opens only when user clicks the button; writes dataURL into existing input.
        function createSignaturePad(canvas) {
            const ctx = canvas.getContext('2d');
            let drawing = false;
            let hasInk = false;

            function resizeCanvas() {
                const ratio = window.devicePixelRatio || 1;
                const rect = canvas.getBoundingClientRect();
                const w = Math.max(1, Math.floor(rect.width * ratio));
                const h = Math.max(1, Math.floor(rect.height * ratio));
                if (canvas.width !== w || canvas.height !== h) {
                    canvas.width = w;
                    canvas.height = h;
                    ctx.lineWidth = 2 * ratio;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    ctx.strokeStyle = '#000';
                }
            }
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);

            function getPoint(evt) {
                const rect = canvas.getBoundingClientRect();
                const scaleX = canvas.width / rect.width;
                const scaleY = canvas.height / rect.height;
                return { x: (evt.clientX - rect.left) * scaleX, y: (evt.clientY - rect.top) * scaleY };
            }
            function pointerDown(evt) {
                evt.preventDefault();
                drawing = true;
                const p = getPoint(evt);
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
            }
            function pointerMove(evt) {
                if (!drawing) return;
                evt.preventDefault();
                const p = getPoint(evt);
                ctx.lineTo(p.x, p.y);
                ctx.stroke();
                hasInk = true;
            }
            function pointerUp(evt) {
                if (!drawing) return;
                evt.preventDefault();
                drawing = false;
            }

            canvas.addEventListener('pointerdown', pointerDown);
            canvas.addEventListener('pointermove', pointerMove);
            canvas.addEventListener('pointerup', pointerUp);
            canvas.addEventListener('pointercancel', pointerUp);
            canvas.addEventListener('pointerleave', pointerUp);

            return {
                clear() {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    hasInk = false;
                },
                resize() {
                    resizeCanvas();
                },
                toDataUrl() {
                    if (!hasInk) return '';
                    try { return canvas.toDataURL('image/png'); } catch (e) { return ''; }
                }
            };
        }

        function initializeSignatureUi() {
            const pads = new Map();

            function getPad(key) {
                if (pads.has(key)) return pads.get(key);
                const canvas = document.getElementById(key === 'rater' ? 'raterSignaturePad' : 'facultySignaturePad');
                if (!canvas) return null;
                const pad = createSignaturePad(canvas);
                pads.set(key, pad);
                // Canvas was hidden when created, so force a resize now that it's visible
                if (typeof pad.resize === 'function') pad.resize();
                return pad;
            }

            document.querySelectorAll('[data-toggle-sign]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const key = btn.getAttribute('data-toggle-sign');
                    const printed = document.getElementById(key === 'rater' ? 'raterPrintedName' : 'facultyPrintedName');
                    if (!printed || !printed.value.trim()) {
                        alert('Please type your printed name first.');
                        if (printed) printed.focus();
                        return;
                    }
                    const wrap = document.querySelector(`[data-sign-wrap="${key}"]`);
                    if (!wrap) return;
                    const isHidden = wrap.style.display === 'none' || !wrap.style.display;
                    wrap.style.display = isHidden ? 'block' : 'none';
                    if (isHidden) {
                        const pad = getPad(key);
                        if (pad) requestAnimationFrame(() => pad.resize());
                    }
                });
            });

            document.querySelectorAll('[data-clear-sign]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const key = btn.getAttribute('data-clear-sign');
                    const pad = getPad(key);
                    if (pad) pad.clear();
                });
            });

            document.querySelectorAll('[data-apply-sign]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const key = btn.getAttribute('data-apply-sign');
                    const pad = getPad(key);
                    const dataUrl = pad ? pad.toDataUrl() : '';
                    if (!dataUrl) {
                        alert('Please sign first.');
                        return;
                    }
                    const input = document.getElementById(key === 'rater' ? 'raterSignature' : 'facultySignature');
                    if (input) input.value = dataUrl;

                    const preview = document.getElementById(key === 'rater' ? 'raterSignaturePreview' : 'facultySignaturePreview');
                    if (preview) {
                        preview.src = dataUrl;
                        preview.style.display = 'block';
                    }

                    const wrap = document.querySelector(`[data-sign-wrap="${key}"]`);
                    if (wrap) wrap.style.display = 'none';
                });
            });
        }

        // Set current date for forms
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const observationDate = document.getElementById('observationDate');
            const raterDate = document.getElementById('raterDate');
            const facultyDate = document.getElementById('facultyDate');
            
            if (observationDate) observationDate.value = today;
            if (raterDate) raterDate.value = today;
            if (facultyDate) facultyDate.value = today;
            
            // Initialize teacher selection
            initializeTeacherSelection();

            // Initialize teacher search
            setupTeacherSearch();

            // Signature UI (touchpad/mouse)
            initializeSignatureUi();

            // If a teacher_id param is provided in the URL (leaders link), auto-start evaluation
            const urlParams = new URLSearchParams(window.location.search);
            const preselectTeacher = urlParams.get('teacher_id');
            if (preselectTeacher) {
                // If the teacher list is present, try to prefill the form from that list item then start
                const preItem = document.querySelector(`.teacher-item[data-teacher-id="${preselectTeacher}"]`);
                if (preItem) {
                    const nameElem = preItem.querySelector('h6');
                    const deptElem = preItem.querySelector('p');
                    const facultyNameInput = document.getElementById('facultyName');
                    const departmentInput = document.getElementById('department');

                    if (facultyNameInput && nameElem) facultyNameInput.value = nameElem.textContent.trim();
                    if (departmentInput && deptElem) departmentInput.value = deptElem.textContent.trim();
                }
                startEvaluation(preselectTeacher);
            }
        });

        function initializeTeacherSelection() {
            // Teacher selection
            document.querySelectorAll('.teacher-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    // If a "both" button was clicked, let the button handlers deal with it
                    if (e.target.closest('.btn-iso-eval') || e.target.closest('.btn-peac-eval') || e.target.closest('.btn-peac-locked')) {
                        return;
                    }
                    const hasSchedule = this.getAttribute('data-has-schedule');
                    const canEvaluateNow = this.getAttribute('data-can-evaluate-now');
                    const blockReason = this.getAttribute('data-block-reason') || '';
                    if (hasSchedule !== '1') {
                        alert('You can\'t evaluate this teacher yet: no schedule is set. Please ask the dean/principal to set a schedule first.');
                        return;
                    }
                    if (canEvaluateNow !== '1') {
                        alert('You can\'t evaluate this teacher yet. ' + blockReason);
                        return;
                    }
                    const teacherId = this.getAttribute('data-teacher-id');
                    const formType = this.getAttribute('data-form-type') || 'iso';
                    // For "both", don't auto-navigate — buttons handle it
                    if (formType === 'both') {
                        return;
                    }
                    // If PEAC form type, redirect to PEAC evaluation page
                    if (formType === 'peac') {
                        window.location.href = 'evaluation_peac.php?teacher_id=' + encodeURIComponent(teacherId);
                        return;
                    }
                    // Auto-fill the form fields from the clicked item
                    const nameElem = this.querySelector('h6');
                    const deptElem = this.querySelector('p');
                    const facultyNameInput = document.getElementById('facultyName');
                    const departmentInput = document.getElementById('department');

                    if (facultyNameInput && nameElem) {
                        facultyNameInput.value = nameElem.textContent.trim();
                    }
                    if (departmentInput && deptElem) {
                        departmentInput.value = deptElem.textContent.trim();
                    }

                    startEvaluation(teacherId);
                });
            });

            // "Both" form type: ISO button handler
            document.querySelectorAll('.btn-iso-eval').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const item = this.closest('.teacher-item');
                    const teacherId = item.getAttribute('data-teacher-id');
                    const nameElem = item.querySelector('h6');
                    const deptElem = item.querySelector('p');
                    const facultyNameInput = document.getElementById('facultyName');
                    const departmentInput = document.getElementById('department');
                    if (facultyNameInput && nameElem) facultyNameInput.value = nameElem.textContent.trim();
                    if (departmentInput && deptElem) departmentInput.value = deptElem.textContent.trim();
                    startEvaluation(teacherId);
                });
            });

            // "Both" form type: PEAC button handler
            document.querySelectorAll('.btn-peac-eval').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const item = this.closest('.teacher-item');
                    const teacherId = item.getAttribute('data-teacher-id');
                    window.location.href = 'evaluation_peac.php?teacher_id=' + encodeURIComponent(teacherId);
                });
            });

            // Back to teachers button (guard in case element is not present)
            const backBtn = document.getElementById('backToTeachers');
            if (backBtn) {
                backBtn.addEventListener('click', function() {
                    showTeacherSelection();
                });
            }

            // Rating change listeners
            document.addEventListener('change', function(e) {
                if (e.target && e.target.type === 'radio' && (
                    e.target.name.includes('communications') ||
                    e.target.name.includes('management') ||
                    e.target.name.includes('assessment')
                )) {
                    calculateAverages();

                    // Auto-generate disabled to prevent stacking multiple pending AI requests.
                    // Use the "Generate AI Recommendation" button instead.
                }
            });

            // Download PDF button removed

            // Generate AI button
            const genBtn = document.getElementById('generateAI');
            if (genBtn) {
                genBtn.addEventListener('click', function() {
                    // Visible proof that the handler is firing
                    setAIDebugStatus('Clicked. Preparing request…', true);
                    generateAINarratives({ force: true, showAlerts: true });
                });
            }

            document.querySelectorAll('.ai-apply-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    applyAISuggestion(this.dataset.target);
                });
            });

            document.addEventListener('click', function (e) {
    const btn = e.target.closest('.ai-use-option-btn');
    if (!btn) return;

    const target = btn.getAttribute('data-target');
    const text = decodeURIComponent(btn.getAttribute('data-text') || '');

    let textarea = null;
    if (target === 'strengths') textarea = document.getElementById('strengths');
    else if (target === 'improvement_areas') textarea = document.getElementById('improvementAreas');
    else if (target === 'recommendations') textarea = document.getElementById('recommendations');

    if (textarea) textarea.value = text || textarea.value;
});
        }

        function startEvaluation(teacherId) {
            document.getElementById('teacherSelection').classList.add('d-none');
            document.getElementById('evaluationFormContainer').classList.remove('d-none');
            document.getElementById('selected_teacher_id').value = teacherId;

            const teacherItem = document.querySelector(`.teacher-item[data-teacher-id="${teacherId}"]`);

            // Auto-fill faculty name
            const teacherName = teacherItem?.getAttribute('data-teacher-name') || '';
            const facultyNameInput = document.getElementById('facultyName');
            if (facultyNameInput && teacherName) {
                facultyNameInput.value = teacherName;
            }

            // Auto-fill observation date from schedule, fallback to today
            const scheduleRaw = teacherItem?.getAttribute('data-schedule-raw') || '';
            let obsDate = new Date().toISOString().split('T')[0];
            let scheduleTime = '';
            if (scheduleRaw) {
                const schedDt = new Date(scheduleRaw);
                if (!isNaN(schedDt.getTime())) {
                    obsDate = schedDt.toISOString().split('T')[0];
                    const hours = schedDt.getHours();
                    const minutes = schedDt.getMinutes();
                    const ampm = hours >= 12 ? 'PM' : 'AM';
                    const h12 = hours % 12 || 12;
                    scheduleTime = h12 + ':' + String(minutes).padStart(2, '0') + ' ' + ampm;
                }
            }
            document.getElementById('observationDate').value = obsDate;
            // Allow past dates if schedule is in the past
            document.getElementById('observationDate').removeAttribute('min');
            document.getElementById('raterDate').value = obsDate;
            document.getElementById('facultyDate').value = obsDate;

            // Auto-fill subject/time from schedule data
            const subject = teacherItem?.getAttribute('data-subject') || '';
            const subjectTimeInput = document.getElementById('subjectTime');
            if (subjectTimeInput) {
                let subjectTimeVal = subject;
                if (scheduleTime) {
                    subjectTimeVal = subject ? subject + ' ' + scheduleTime : scheduleTime;
                }
                subjectTimeInput.value = subjectTimeVal;
            }

            // Auto-fill department: use scheduled_department, fallback to teacher's primary department
            const scheduledDept = teacherItem?.getAttribute('data-scheduled-department') || '';
            const teacherDept = teacherItem?.getAttribute('data-teacher-department') || '';
            const departmentInput = document.getElementById('department');
            if (departmentInput) {
                departmentInput.value = scheduledDept || teacherDept || departmentInput.value;
            }

            // Auto-fill printed names
            document.getElementById('raterPrintedName').value = <?= json_encode($_SESSION['name'] ?? '') ?>;
            const facultyName = document.getElementById('facultyName')?.value || '';
            document.getElementById('facultyPrintedName').value = facultyName;

            // Apply focus-of-observation locking
            const focusData = teacherItem?.getAttribute('data-focus') || '';
            applyFocusLock(focusData);

            // Auto-fill semester from schedule
            const semesterData = teacherItem?.getAttribute('data-semester') || '';
            if (semesterData === '1st' || semesterData === '2nd') {
                const semRadio = document.getElementById(semesterData === '1st' ? 'semester1' : 'semester2');
                if (semRadio) semRadio.checked = true;
            }
        }

        /**
         * Lock/disable evaluation categories that are NOT in the scheduled focus.
         * If no focus is set (legacy schedules), all categories remain enabled.
         */
        function applyFocusLock(focusJson) {
            const categories = {
                'communications': document.getElementById('communicationsCompetence'),
                'management': document.getElementById('managementPresentation'),
                'assessment': document.getElementById('assessmentLearning')
            };

            let focusArr = [];
            if (focusJson) {
                try { focusArr = JSON.parse(focusJson); } catch(e) {}
            }

            // If no focus set, unlock everything (backward compat)
            if (!Array.isArray(focusArr) || focusArr.length === 0) {
                Object.values(categories).forEach(tbody => {
                    if (!tbody) return;
                    const section = tbody.closest('.mb-4');
                    if (section) {
                        section.style.opacity = '';
                        section.style.pointerEvents = '';
                        const badge = section.querySelector('.focus-locked-badge');
                        if (badge) badge.remove();
                    }
                    tbody.querySelectorAll('input[type="radio"]').forEach(r => { r.disabled = false; r.removeAttribute('required'); });
                    tbody.querySelectorAll('input[type="text"]').forEach(t => { t.disabled = false; });
                });
                return;
            }

            Object.entries(categories).forEach(([key, tbody]) => {
                if (!tbody) return;
                const section = tbody.closest('.mb-4');
                const isEnabled = focusArr.includes(key);

                if (isEnabled) {
                    // Enable this category
                    if (section) {
                        section.style.opacity = '';
                        section.style.pointerEvents = '';
                        const badge = section.querySelector('.focus-locked-badge');
                        if (badge) badge.remove();
                    }
                    tbody.querySelectorAll('input[type="radio"]').forEach(r => { r.disabled = false; });
                    tbody.querySelectorAll('input[type="text"]').forEach(t => { t.disabled = false; });
                    // Set required on first radio of each row
                    const rows = tbody.querySelectorAll('tr');
                    rows.forEach(row => {
                        const firstRadio = row.querySelector('input[type="radio"]');
                        if (firstRadio) firstRadio.setAttribute('required', 'required');
                    });
                } else {
                    // Disable this category
                    if (section) {
                        section.style.opacity = '0.45';
                        section.style.pointerEvents = 'none';
                        // Add a locked badge if not already present
                        if (!section.querySelector('.focus-locked-badge')) {
                            const h6 = section.querySelector('h6');
                            if (h6) {
                                const badge = document.createElement('span');
                                badge.className = 'badge bg-secondary ms-2 focus-locked-badge';
                                badge.innerHTML = '<i class="fas fa-lock me-1"></i>Not in focus';
                                h6.appendChild(badge);
                            }
                        }
                    }
                    tbody.querySelectorAll('input[type="radio"]').forEach(r => {
                        r.disabled = true;
                        r.checked = false;
                        r.removeAttribute('required');
                    });
                    tbody.querySelectorAll('input[type="text"]').forEach(t => {
                        t.disabled = true;
                        t.value = '';
                    });
                }
            });
        }

        function showTeacherSelection() {
            document.getElementById('teacherSelection').classList.remove('d-none');
            document.getElementById('evaluationFormContainer').classList.add('d-none');
        }
                function calculateAverages() {
            // Determine which categories are in focus
            const teacherIdEl = document.getElementById('selected_teacher_id');
            const teacherItem = teacherIdEl ? document.querySelector(`.teacher-item[data-teacher-id="${teacherIdEl.value}"]`) : null;
            const focusRaw = teacherItem?.getAttribute('data-focus') || '';
            let focusArr = [];
            try { focusArr = JSON.parse(focusRaw); } catch(e) {}
            const hasFocus = Array.isArray(focusArr) && focusArr.length > 0;

            // Communications average
            let commTotal = 0;
            let commCount = 0;
            const commBlocked = hasFocus && !focusArr.includes('communications');
            
            if (!commBlocked) {
                for (let i = 0; i < 5; i++) {
                    const selected = document.querySelector(`input[name="communications${i}"]:checked`);
                    if (selected) {
                        commTotal += parseInt(selected.value);
                        commCount++;
                    }
                }
            }
            
            const commAvg = commBlocked ? null : (commCount > 0 ? (commTotal / commCount).toFixed(1) : '0.0');
            document.getElementById('communicationsAverage').textContent = commBlocked ? 'N/A' : commAvg;
            
            // Management average
            let mgmtTotal = 0;
            let mgmtCount = 0;
            const mgmtBlocked = hasFocus && !focusArr.includes('management');
            
            if (!mgmtBlocked) {
                for (let i = 0; i < 12; i++) {
                    const selected = document.querySelector(`input[name="management${i}"]:checked`);
                    if (selected) {
                        mgmtTotal += parseInt(selected.value);
                        mgmtCount++;
                    }
                }
            }
            
            const mgmtAvg = mgmtBlocked ? null : (mgmtCount > 0 ? (mgmtTotal / mgmtCount).toFixed(1) : '0.0');
            document.getElementById('managementAverage').textContent = mgmtBlocked ? 'N/A' : mgmtAvg;
            
            // Assessment average
            let assessTotal = 0;
            let assessCount = 0;
            const assessBlocked = hasFocus && !focusArr.includes('assessment');
            
            if (!assessBlocked) {
                for (let i = 0; i < 6; i++) {
                    const selected = document.querySelector(`input[name="assessment${i}"]:checked`);
                    if (selected) {
                        assessTotal += parseInt(selected.value);
                        assessCount++;
                    }
                }
            }
            
            const assessAvg = assessBlocked ? null : (assessCount > 0 ? (assessTotal / assessCount).toFixed(1) : '0.0');
            document.getElementById('assessmentAverage').textContent = assessBlocked ? 'N/A' : assessAvg;
            
            // Overall average (only from active categories)
            const totalCount = commCount + mgmtCount + assessCount;
            const totalSum = commTotal + mgmtTotal + assessTotal;
            const overallAvg = totalCount > 0 ? (totalSum / totalCount).toFixed(1) : '0.0';
            
            document.getElementById('totalAverage').textContent = overallAvg;
            
            // Rating interpretation
            let interpretation = '';
            let interpretationClass = '';
            const numericAvg = parseFloat(overallAvg);
            
            // round to nearest integer and map directly to the simple scale
            const rounded = Math.floor(numericAvg);
            switch (rounded) {
                case 5:
                    interpretation = 'Excellent';
                    interpretationClass = 'text-success';
                    break;
                case 4:
                    interpretation = 'Very Satisfactory';
                    interpretationClass = 'text-primary';
                    break;
                case 3:
                    interpretation = 'Satisfactory';
                    interpretationClass = 'text-info';
                    break;
                case 2:
                    interpretation = 'Below Satisfactory';
                    interpretationClass = 'text-warning';
                    break;
                case 1:
                    interpretation = 'Needs Improvement';
                    interpretationClass = 'text-danger';
                    break;
                default:
                    interpretation = 'Not Rated';
            }
            
            const ratingElement = document.getElementById('ratingInterpretation');
            ratingElement.textContent = interpretation;
            ratingElement.className = interpretationClass;
            
            return {
                communications: commAvg !== null ? parseFloat(commAvg) : null,
                management: mgmtAvg !== null ? parseFloat(mgmtAvg) : null,
                assessment: assessAvg !== null ? parseFloat(assessAvg) : null,
                overall: parseFloat(overallAvg)
            };
        }
        function exportToPDF() {
                const teacherId = document.getElementById('selected_teacher_id').value;
                const teacherName = document.getElementById('facultyName').value;
                
                if (!teacherId || !teacherName) {
                    alert('Please select a teacher and complete the evaluation form first.');
                    return;
                }
                
                if (!confirm(`Generate PDF evaluation form for ${teacherName}?`)) return;

                // Use the export script for single evaluation form
                window.open(`../controllers/export.php?type=form&evaluation_id=${teacherId}&report_type=single`, '_blank');

            const pdfBtn = document.getElementById('downloadPDF');
            const originalText = pdfBtn ? pdfBtn.innerHTML : '';
            if (pdfBtn) {
                pdfBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating PDF...';
                pdfBtn.disabled = true;
            }

            const data = getFormData();

            // Build a simple HTML report for the evaluation
            const container = document.createElement('div');
            container.style.padding = '20px';
            container.style.fontFamily = 'Arial, sans-serif';


                        // Form Code Box (styled)
                        const formCodeBox = document.createElement('div');
                        formCodeBox.style.display = 'inline-block';
                        formCodeBox.style.border = '1.5px solid #1a237e';
                        formCodeBox.style.borderRadius = '4px';
                        formCodeBox.style.background = '#fff';
                        formCodeBox.style.padding = '0';
                        formCodeBox.style.marginBottom = '18px';
                        formCodeBox.style.marginTop = '8px';
                        formCodeBox.style.fontSize = '12px';
                        formCodeBox.style.width = 'auto';
                        formCodeBox.style.maxWidth = '340px';
                        formCodeBox.style.overflow = 'hidden';
                        formCodeBox.innerHTML = `
                                <table style="border:none;border-collapse:collapse;width:100%;">
                                    <tr><td style="background:#1a237e;color:#fff;font-weight:bold;padding:4px 10px;font-size:12px;border:none;">Form Code No.</td><td style="padding:4px 10px;font-size:12px;border:none;">: <?php echo $_fs['form_code_no']; ?></td></tr>
                                    <tr><td style="background:#1a237e;color:#fff;font-weight:bold;padding:4px 10px;font-size:12px;border:none;">Issue Status</td><td style="padding:4px 10px;font-size:12px;border:none;">: <?php echo $_fs['issue_status']; ?></td></tr>
                                    <tr><td style="background:#1a237e;color:#fff;font-weight:bold;padding:4px 10px;font-size:12px;border:none;">Revision No.</td><td style="padding:4px 10px;font-size:12px;border:none;">: <?php echo $_fs['revision_no']; ?></td></tr>
                                    <tr><td style="background:#1a237e;color:#fff;font-weight:bold;padding:4px 10px;font-size:12px;border:none;">Date Effective</td><td style="padding:4px 10px;font-size:12px;border:none;">: <?php echo $_fs['date_effective']; ?></td></tr>
                                    <tr><td style="background:#1a237e;color:#fff;font-weight:bold;padding:4px 10px;font-size:12px;border:none;">Approved By</td><td style="padding:4px 10px;font-size:12px;border:none;">: <?php echo $_fs['approved_by']; ?></td></tr>
                                </table>
                        `;
                        container.appendChild(formCodeBox);

                        const title = document.createElement('h2');
                        title.textContent = 'Classroom Evaluation Report';
                        container.appendChild(title);

            const meta = document.createElement('div');
            meta.innerHTML = `
                <p><strong>Teacher:</strong> ${escapeHtml(data.faculty_name || '')}</p>
                <p><strong>Department:</strong> ${escapeHtml(data.department || '')}</p>
                <p><strong>Subject/Time:</strong> ${escapeHtml(data.subject_observed || '')}</p>
                <p><strong>Date of Observation:</strong> ${escapeHtml(data.observation_date || '')}</p>
                <p><strong>Rater:</strong> ${escapeHtml(data.rater_signature || '')} &nbsp; <strong>Rater Date:</strong> ${escapeHtml(data.rater_date || '')}</p>
            `;
            container.appendChild(meta);

            const averagesDiv = document.createElement('div');
            averagesDiv.innerHTML = `
                <h4>Averages</h4>
                <p>Communications: ${data.averages.communications}</p>
                <p>Management: ${data.averages.management}</p>
                <p>Assessment: ${data.averages.assessment}</p>
                <p><strong>Overall:</strong> ${data.averages.overall}</p>
            `;
            container.appendChild(averagesDiv);

            const sections = document.createElement('div');
            sections.innerHTML = `
                <h4>Strengths</h4>
                <p>${escapeHtml(data.strengths || '')}</p>
                <h4>Areas for Improvement</h4>
                <p>${escapeHtml(data.improvement_areas || '')}</p>
                <h4>Recommendations</h4>
                <p>${escapeHtml(data.recommendations || '')}</p>
            `;
            container.appendChild(sections);

            // Add ratings tables for each category
            const addRatingsTable = (categoryName, ratingsObj) => {
                const heading = document.createElement('h4');
                heading.textContent = categoryName;
                container.appendChild(heading);

                const table = document.createElement('table');
                table.style.width = '100%';
                table.style.borderCollapse = 'collapse';
                table.innerHTML = '<thead><tr><th style="border:1px solid #ddd;padding:8px;text-align:left;">Item</th><th style="border:1px solid #ddd;padding:8px;">Rating</th><th style="border:1px solid #ddd;padding:8px;">Comment</th></tr></thead>';
                const tbody = document.createElement('tbody');

                const keys = Object.keys(ratingsObj || {});
                keys.forEach(k => {
                    const r = ratingsObj[k];
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="border:1px solid #ddd;padding:8px;">${escapeHtml((r.label || ('Item ' + k)).toString())}</td>
                        <td style="border:1px solid #ddd;padding:8px;text-align:center;">${escapeHtml(r.rating || '')}</td>
                        <td style="border:1px solid #ddd;padding:8px;">${escapeHtml(r.comment || '')}</td>
                    `;
                    tbody.appendChild(tr);
                });

                table.appendChild(tbody);
                container.appendChild(table);
            };

            // For labels we don't have descriptive text; use index as placeholder
            addRatingsTable('Communications Competence', data.ratings.communications);
            addRatingsTable('Management and Presentation', data.ratings.management);
            addRatingsTable("Assessment of Students' Learning", data.ratings.assessment);

            // Ensure html2pdf is loaded, then generate PDF
            function generate() {
                const opt = {
                    margin:       10,
                    filename:     `${(data.faculty_name || 'evaluation').replace(/\s+/g, '_')}_report.pdf`,
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2 },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };

                // Use html2pdf
                html2pdf().set(opt).from(container).save().then(() => {
                    if (pdfBtn) {
                        pdfBtn.innerHTML = originalText;
                        pdfBtn.disabled = false;
                    }
                }).catch(err => {
                    console.error(err);
                    alert('Failed to generate PDF. See console for details.');
                    if (pdfBtn) {
                        pdfBtn.innerHTML = originalText;
                        pdfBtn.disabled = false;
                    }
                });
            }

            if (typeof html2pdf === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js';
                script.onload = generate;
                script.onerror = () => {
                    alert('Failed to load PDF library. Check your internet connection.');
                    if (pdfBtn) {
                        pdfBtn.innerHTML = originalText;
                        pdfBtn.disabled = false;
                    }
                };
                document.head.appendChild(script);
            } else {
                generate();
            }
        }

        // --- AI Generation (Python service via PHP proxy) ---
        function setAIDebugStatus(text, show = true) {
            const panel = document.getElementById('aiDebugPanel');
            const label = document.getElementById('aiDebugText');
            if (label) label.textContent = text;
            if (panel) panel.style.display = show ? 'block' : 'none';
        }

        function escapeAiSuggestionHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderAISuggestionParagraphs(text) {
            const normalized = String(text || '').trim();
            if (!normalized) {
                return '<p class="text-muted mb-0">No suggestion generated yet.</p>';
            }

            return normalized
                .split(/\n\s*\n/)
                .map(part => part.trim())
                .filter(Boolean)
                .map((paragraph, index) => `<p class="mb-3"><strong class="text-muted">Paragraph ${index + 1}:</strong><br>${escapeAiSuggestionHtml(paragraph)}</p>`)
                .join('');
        }

        function renderAIPrimarySuggestion(text, targetField) {
            const normalized = String(text || '').trim();
            if (!normalized) {
                return '<div class="text-muted small">No generated narrative yet.</div>';
            }

            return `
                <div class="ai-generated-preview card border-0 shadow-sm mb-3">
                    <div class="card-body p-3">
                        <div class="small text-muted fw-semibold mb-2">Generated narrative</div>
                        <div class="ai-generated-paragraphs">${renderAISuggestionParagraphs(normalized)}</div>
                        <div class="d-flex justify-content-end mt-2">
                            <button type="button"
                                    class="btn btn-sm btn-primary ai-use-option-btn"
                                    data-target="${targetField}"
                                    data-text="${encodeURIComponent(normalized)}">
                                Use this generated text
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        function showAISuggestions(data = {}, dbg = {}) {
            const panel = document.getElementById('aiSuggestionPanel');
            const strengthsBox = document.getElementById('aiSuggestionStrengths');
            const improvementsBox = document.getElementById('aiSuggestionImprovements');
            const recommendationsBox = document.getElementById('aiSuggestionRecommendations');
            const strengthsPanel = document.getElementById('aiStrengthsPanel');
            const improvementsPanel = document.getElementById('aiImprovementsPanel');
            const recommendationsPanel = document.getElementById('aiRecommendationsPanel');
            const meta = document.getElementById('aiSuggestionMeta');

            if (!panel || !strengthsBox || !improvementsBox || !recommendationsBox) {
                return;
            }

            strengthsBox.innerHTML = renderSuggestionCards(data.strengths_options || [data.strengths || ''], 'strengths');
            improvementsBox.innerHTML = renderSuggestionCards(data.improvement_areas_options || [data.improvement_areas || ''], 'improvement_areas');
            recommendationsBox.innerHTML = renderSuggestionCards(data.recommendations_options || [data.recommendations || ''], 'recommendations');

          

            if (meta) {
                const sourceSummary = dbg.reference_sources && typeof dbg.reference_sources === 'object'
                    ? Object.entries(dbg.reference_sources).map(([key, value]) => `${key}:${value}`).join(', ')
                    : 'n/a';
                meta.textContent = `Ready • refs ${dbg.reference_examples_used ?? 0} • ${dbg.fallback_used ? 'fallback' : 'model'} • ${sourceSummary}`;
            }

            panel.style.display = 'block';
            if (strengthsPanel) strengthsPanel.style.display = 'block';
            if (improvementsPanel) improvementsPanel.style.display = 'block';
            if (recommendationsPanel) recommendationsPanel.style.display = 'block';

            window.__aiSuggestionHistory = window.__aiSuggestionHistory || {
                strengths: [],
                areas_for_improvement: [],
                recommendations: []
            };

            const pushUnique = (key, values) => {
                const bucket = window.__aiSuggestionHistory[key] || [];
                (values || []).forEach(value => {
                    const normalized = String(value || '').trim();
                    if (normalized && !bucket.includes(normalized)) {
                        bucket.push(normalized);
                    }
                });
                window.__aiSuggestionHistory[key] = bucket.slice(-12);
            };

            pushUnique('strengths', [data.strengths, ...(data.strengths_options || [])]);
            pushUnique('areas_for_improvement', [data.improvement_areas, ...(data.improvement_areas_options || [])]);
            pushUnique('recommendations', [data.recommendations, ...(data.recommendations_options || [])]);
        }

        function applyAISuggestion(targetId) {
            const textarea = document.getElementById(targetId);
            const data = window.__lastAISuggestions || {};
            if (!textarea) return;

            if (targetId === 'strengths') {
                textarea.value = data.strengths || textarea.value;
            } else if (targetId === 'improvementAreas') {
                textarea.value = data.improvement_areas || textarea.value;
            } else if (targetId === 'recommendations') {
                textarea.value = data.recommendations || textarea.value;
            }

            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.focus();
        }

        function buildAIPayloadFromForm() {
            // Use existing getFormData() if available (keeps shapes consistent)
            if (typeof getFormData === 'function') {
                const data = getFormData();
                const ratings = data.ratings || {};
                const indicatorComments = [];
                const commentSummary = {
                    communications: [],
                    management: [],
                    assessment: []
                };

                Object.entries(ratings).forEach(([category, entries]) => {
                    if (!Array.isArray(entries)) {
                        return;
                    }

                    entries.forEach((entry, index) => {
                        const comment = (entry && typeof entry.comment === 'string') ? entry.comment.trim() : '';
                        if (!comment) {
                            return;
                        }

                        const normalized = {
                            category,
                            criterion_index: index,
                            rating: entry && entry.rating ? entry.rating : '',
                            criterion_text: entry && entry.criterion_text ? entry.criterion_text : '',
                            comment
                        };

                        indicatorComments.push(normalized);
                        if (Array.isArray(commentSummary[category])) {
                            commentSummary[category].push(comment);
                        } else {
                            commentSummary[category] = [comment];
                        }
                    });
                });

                // The AI service expects: faculty_name, department, subject_observed, observation_type, averages, ratings
                // getFormData() already returns those keys in this system.
                return {
                    faculty_name: data.faculty_name || '',
                    department: data.department || '',
                    subject_observed: data.subject_observed || '',
                    observation_type: data.observation_type || '',
                    averages: data.averages || { communications: 0, management: 0, assessment: 0, overall: 0 },
                    ratings,
                    indicator_comments: indicatorComments,
                    comments_summary: commentSummary,
                    evaluation_focus: data.evaluation_focus || '',
                    evaluation_form_type: 'iso',
                    // Allows the AI service to generate shorter/standard/detailed if you add UI later
                    style: data.style || 'standard'
                };
            }

            // Fallback minimal payload (should still work via template generation)
            const averages = (typeof calculateAverages === 'function') ? calculateAverages() : { communications: 0, management: 0, assessment: 0, overall: 0 };
            return {
                faculty_name: (document.getElementById('facultyName')?.value || ''),
                department: (document.getElementById('department')?.value || ''),
                subject_observed: (document.getElementById('subjectObserved')?.value || ''),
                observation_type: (document.querySelector('input[name="observationType"]:checked')?.value || ''),
                averages,
                ratings: {},
                indicator_comments: [],
                comments_summary: {
                    communications: [],
                    management: [],
                    assessment: []
                }
            };
        }

        function hasMeaningfulEvaluationInput() {
            // Adjust total required based on which categories are in focus
            const teacherIdEl = document.getElementById('selected_teacher_id');
            const teacherItem = teacherIdEl ? document.querySelector(`.teacher-item[data-teacher-id="${teacherIdEl.value}"]`) : null;
            const focusRaw = teacherItem?.getAttribute('data-focus') || '';
            let focusArr = [];
            try { focusArr = JSON.parse(focusRaw); } catch(e) {}
            const hasFocus = Array.isArray(focusArr) && focusArr.length > 0;

            let totalRequired = 0;
            let selectors = [];
            if (!hasFocus || focusArr.includes('communications')) {
                totalRequired += 5;
                selectors.push('input[name^="communications"]:checked');
            }
            if (!hasFocus || focusArr.includes('management')) {
                totalRequired += 12;
                selectors.push('input[name^="management"]:checked');
            }
            if (!hasFocus || focusArr.includes('assessment')) {
                totalRequired += 6;
                selectors.push('input[name^="assessment"]:checked');
            }

            const checkedRatings = selectors.length > 0
                ? document.querySelectorAll(selectors.join(', ')).length
                : 0;

            if (checkedRatings < totalRequired) {
                return false;
            }
            return true;
        }

        async function generateAINarratives(options = {}) {
            const { force = false, showAlerts = false } = options;

            const btn = document.getElementById('generateAI');
            const strengthsEl = document.getElementById('strengths');
            const improvementEl = document.getElementById('improvementAreas');
            const recEl = document.getElementById('recommendations');

            if (!strengthsEl || !improvementEl || !recEl) {
                if (showAlerts) alert('Evaluation fields not found on the page.');
                return;
            }

            if (!hasMeaningfulEvaluationInput()) {
                // Count only active (non-blocked) indicators
                const teacherItemAI = document.querySelector(`.teacher-item[data-teacher-id="${document.getElementById('selected_teacher_id')?.value}"]`);
                const focusRawAI = teacherItemAI?.getAttribute('data-focus') || '';
                let focusArrAI = [];
                try { focusArrAI = JSON.parse(focusRawAI); } catch(e) {}
                const hasFocusAI = Array.isArray(focusArrAI) && focusArrAI.length > 0;
                let totalReq = 0;
                let sels = [];
                if (!hasFocusAI || focusArrAI.includes('communications')) { totalReq += 5; sels.push('input[name^="communications"]:checked'); }
                if (!hasFocusAI || focusArrAI.includes('management')) { totalReq += 12; sels.push('input[name^="management"]:checked'); }
                if (!hasFocusAI || focusArrAI.includes('assessment')) { totalReq += 6; sels.push('input[name^="assessment"]:checked'); }
                const checked = sels.length > 0 ? document.querySelectorAll(sels.join(', ')).length : 0;
                const msg = `Please complete all ${totalReq} rating indicators before generating AI recommendations (${checked}/${totalReq} completed).`;
                setAIDebugStatus(msg, true);
                if (showAlerts) alert(msg);
                return;
            }

            // Keep a stable original label so the button never gets stuck
            const defaultBtnHtml = '<i class="fas fa-magic me-2"></i> Generate AI Recommendation';
            const restoreButton = () => {
                if (!btn) return;
                btn.disabled = false;
                const prev = btn.dataset.prevText;
                btn.innerHTML = (prev && prev.trim().length) ? prev : defaultBtnHtml;
                delete btn.dataset.prevText;
            };

            const payload = buildAIPayloadFromForm();
            payload.regeneration_nonce = force ? String(Date.now()) : '';
            payload.previously_shown = force
                ? {
                    strengths: (window.__aiSuggestionHistory && window.__aiSuggestionHistory.strengths) || [],
                    areas_for_improvement: (window.__aiSuggestionHistory && window.__aiSuggestionHistory.areas_for_improvement) || [],
                    recommendations: (window.__aiSuggestionHistory && window.__aiSuggestionHistory.recommendations) || []
                }
                : {};

            if (btn) {
                btn.disabled = true;
                if (!btn.dataset.prevText) btn.dataset.prevText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
            }
            setAIDebugStatus('Generating… first run may take a while (model load).', true);

            try {
                const res = await fetch('../controllers/ai_generate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                // Handle cases where PHP returns HTML (e.g., login redirect page) instead of JSON
                const contentType = (res.headers.get('content-type') || '').toLowerCase();
                const rawText = await res.text();

                let data = null;
                if (contentType.includes('application/json')) {
                    try {
                        data = JSON.parse(rawText);
                    } catch (e) {
                        // keep null
                    }
                }

                if (!res.ok || !data || data.success !== true) {
                    console.error('AI proxy error:', { status: res.status, data, rawText });
                    let msg = `AI generation failed (HTTP ${res.status}).`;
                    if (data && (data.message || data.error)) {
                        msg = (data.message || data.error);
                    } else if (rawText && rawText.toLowerCase().includes('login')) {
                        msg = 'Not authenticated. Please refresh the page and log in again.';
                    } else if (rawText && rawText.trim().length) {
                        msg = `AI proxy returned unexpected response. Check console for details.`;
                    }
                    setAIDebugStatus(msg, true);
                    if (showAlerts) alert(msg);
                    restoreButton();
                    return;
                }

                const out = data.data || {};
                window.__lastAISuggestions = {
                    strengths: out.strengths || '',
                    improvement_areas: out.improvement_areas || '',
                    recommendations: out.recommendations || '',
                    strengths_options: out.strengths_options || [],
                    improvement_areas_options: out.improvement_areas_options || [],
                    recommendations_options: out.recommendations_options || []
                };
                const dbg = out.debug || {};
                const dbgParts = ['Done'];
                if (typeof dbg.reference_examples_used !== 'undefined') {
                    dbgParts.push(`refs=${dbg.reference_examples_used}`);
                }
                if (dbg.reference_sources && typeof dbg.reference_sources === 'object') {
                    dbgParts.push(`sources=${JSON.stringify(dbg.reference_sources)}`);
                }
                if (typeof dbg.fallback_used !== 'undefined') {
                    dbgParts.push(`fallback=${dbg.fallback_used ? 'yes' : 'no'}`);
                }
                showAISuggestions(window.__lastAISuggestions, dbg);
                setAIDebugStatus(dbgParts.join(' | '), true);

                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-magic me-2"></i>Generate AI Recommendation Again';
                    btn.classList.remove('btn-secondary', 'btn-primary', 'btn-success');
                }
            } catch (err) {
                console.error(err);
                const msg = 'AI generation error. Is the AI server running on 127.0.0.1:8001?';
                setAIDebugStatus(msg, true);
                if (showAlerts) alert(msg);
            } finally {
                if (btn && btn.innerHTML.includes('Generating...')) {
                    restoreButton();
                }
            }
        }

        function validateForm(isDraft = false) {
            let isValid = true;
            const errorFields = [];

            if (!isDraft) {
                const requiredFields = document.querySelectorAll('[required]');
                // Check required fields
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        field.classList.add('is-invalid');
                        errorFields.push(field.name || field.id);
                        isValid = false;
                    } else {
                        field.classList.remove('is-invalid');
                    }
                });

                // Check if at least some ratings are provided
                const communicationsRatings = document.querySelectorAll('input[name^="communications"]:checked');
                const managementRatings = document.querySelectorAll('input[name^="management"]:checked');
                const assessmentRatings = document.querySelectorAll('input[name^="assessment"]:checked');

                if (communicationsRatings.length === 0 && managementRatings.length === 0 && assessmentRatings.length === 0) {
                    alert('Please provide ratings for at least one evaluation category.');
                    isValid = false;
                }
            } else {
                // Draft mode: be permissive — require at least some meaningful content (one rating or some text)
                const communicationsRatings = document.querySelectorAll('input[name^="communications"]:checked');
                const managementRatings = document.querySelectorAll('input[name^="management"]:checked');
                const assessmentRatings = document.querySelectorAll('input[name^="assessment"]:checked');
                const strengths = document.getElementById('strengths').value.trim();
                const improvements = document.getElementById('improvementAreas').value.trim();
                const recommendations = document.getElementById('recommendations').value.trim();
                const facultyName = document.getElementById('facultyName').value.trim();

                if (communicationsRatings.length === 0 && managementRatings.length === 0 && assessmentRatings.length === 0 && !strengths && !improvements && !recommendations && !facultyName) {
                    alert('Please provide at least one rating or some notes before saving a draft.');
                    isValid = false;
                }
            }
            
            // Check ratings completeness (for submission)
            if (!isDraft) {
                const categories = ['communications', 'management', 'assessment'];
                const expectedCounts = { communications: 5, management: 12, assessment: 6 };
                
                for (const category of categories) {
                    const ratings = document.querySelectorAll(`input[name^="${category}"]:checked`);
                    if (ratings.length > 0 && ratings.length < expectedCounts[category]) {
                        if (confirm(`You have only completed ${ratings.length} out of ${expectedCounts[category]} items in ${category.replace('communications', 'Communications').replace('management', 'Management').replace('assessment', 'Assessment')}. Continue anyway?`)) {
                            // User chose to continue with incomplete category
                        } else {
                            isValid = false;
                            break;
                        }
                    }
                }
            }
            
            if (!isValid && !isDraft) {
                alert('Please complete all required fields before submitting.');
                // Scroll to first error
                if (errorFields.length > 0) {
                    const firstError = document.querySelector('.is-invalid');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        firstError.focus();
                    }
                }
            }
            
            return isValid;
        }

        function getFormData() {
            // Determine which categories are in focus (empty = all enabled)
            const teacherId = document.getElementById('selected_teacher_id').value;
            const teacherItem = document.querySelector(`.teacher-item[data-teacher-id="${teacherId}"]`);
            const focusRaw = teacherItem?.getAttribute('data-focus') || '';
            let evaluationFocus = [];
            try { evaluationFocus = JSON.parse(focusRaw); } catch(e) {}
            if (!Array.isArray(evaluationFocus) || evaluationFocus.length === 0) {
                evaluationFocus = []; // empty means all categories active
            }

            const formData = {
                teacher_id: teacherId,
                faculty_name: document.getElementById('facultyName').value,
                academic_year: document.getElementById('academicYear').value,
                semester: document.querySelector('input[name="semester"]:checked')?.value,
                department: document.getElementById('department').value,
                subject_observed: document.getElementById('subjectTime').value,
                observation_date: document.getElementById('observationDate').value,
                observation_type: document.querySelector('input[name="observation_type"]:checked')?.value,
                seat_plan: document.getElementById('seatPlan').checked ? 1 : 0,
                course_syllabi: document.getElementById('courseSyllabi').checked ? 1 : 0,
                others_requirements: document.getElementById('others').checked ? 1 : 0,
                others_specify: document.getElementById('othersSpecify').value,
                strengths: document.getElementById('strengths').value,
                improvement_areas: document.getElementById('improvementAreas').value,
                recommendations: document.getElementById('recommendations').value,
                agreement: document.getElementById('agreement').value,
                rater_printed_name: document.getElementById('raterPrintedName')?.value || '',
                rater_signature: document.getElementById('raterSignature').value,
                rater_date: document.getElementById('raterDate').value,
                faculty_printed_name: document.getElementById('facultyPrintedName')?.value || '',
                faculty_signature: document.getElementById('facultySignature').value,
                faculty_date: document.getElementById('facultyDate').value,
                evaluation_focus: evaluationFocus.length > 0 ? JSON.stringify(evaluationFocus) : '',
                subject_area: teacherItem?.getAttribute('data-subject-area') || '',
                observation_room: teacherItem?.getAttribute('data-room') || '',
                ratings: {}
            };
            
            // Collect all ratings (skip blocked categories)
            const tbodyIds = { communications: 'communicationsCompetence', management: 'managementPresentation', assessment: 'assessmentLearning' };
            ['communications', 'management', 'assessment'].forEach(category => {
                // If focus is set and this category is not in focus, skip it entirely
                if (evaluationFocus.length > 0 && !evaluationFocus.includes(category)) {
                    return;
                }
                formData.ratings[category] = {};
                const count = category === 'communications' ? 5 : category === 'management' ? 12 : 6;
                const tbody = document.getElementById(tbodyIds[category]);
                const rows = tbody ? tbody.querySelectorAll('tr') : [];
                
                for (let i = 0; i < count; i++) {
                    const rating = document.querySelector(`input[name="${category}${i}"]:checked`);
                    const comment = document.querySelector(`input[name="${category}_comment${i}"]`) ||
                                    document.querySelector(`textarea[name="${category}_comment${i}"]`);
                    const criterionTd = rows[i] ? rows[i].querySelector('td:first-child') : null;
                    const criterionText = criterionTd ? criterionTd.textContent.trim() : '';
                    
                    if (rating) {
                        formData.ratings[category][i] = {
                            rating: rating.value,
                            comment: comment ? comment.value : '',
                            criterion_text: criterionText
                        };

                        // Also include flat keys because the PHP backend currently expects
                        // POST fields like communications0, communications_comment0, etc.
                        formData[`${category}${i}`] = rating.value;
                        formData[`${category}_comment${i}`] = comment ? comment.value : '';
                    }
                }
            });
            
            // Add calculated averages (coerce null → 0 for blocked categories
            // so the Python Averages model doesn't reject null values)
            const averages = calculateAverages();
            formData.averages = {
                communications: averages.communications ?? 0,
                management: averages.management ?? 0,
                assessment: averages.assessment ?? 0,
                overall: averages.overall ?? 0
            };
            
            return formData;
        }

        // Escape HTML for safe insertion into generated report
        function escapeHtml(str) {
            if (!str && str !== 0) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Flatten nested objects to key/value pairs for form POST
        function flattenObject(obj, prefix = '') {
            const pairs = {};

            for (const key in obj) {
                if (!Object.prototype.hasOwnProperty.call(obj, key)) continue;
                const value = obj[key];
                const pref = prefix ? `${prefix}[${key}]` : key;

                if (value === null || value === undefined) {
                    pairs[pref] = '';
                } else if (typeof value === 'object' && !(value instanceof Date)) {
                    const nested = flattenObject(value, pref);
                    for (const nKey in nested) {
                        if (Object.prototype.hasOwnProperty.call(nested, nKey)) {
                            pairs[nKey] = nested[nKey];
                        }
                    }
                } else {
                    pairs[pref] = value;
                }
            }

            return pairs;
        }

        // Final submit handler (AJAX)
        // We submit via AJAX so we can redirect cleanly back to the dashboard
        // and immediately see the new row in "Recent Evaluations".
        const evaluationForm = document.getElementById('evaluationForm');
        if (evaluationForm) {
            evaluationForm.addEventListener('submit', function(e) {
                e.preventDefault();

                if (!validateForm()) {
                    return false;
                }

                submitEvaluationFinal();
                return false;
            });
        }

        // Auto-save functionality (optional)
        let autoSaveTimeout;
        function setupAutoSave() {
            const inputs = document.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.addEventListener('change', function() {
                    clearTimeout(autoSaveTimeout);
                    autoSaveTimeout = setTimeout(() => {
                        if (validateForm(true)) {
                            console.log('Auto-saving draft...');
                            // In real implementation, call saveEvaluationDraft() or make AJAX call
                        }
                    }, 3000); // Save 3 seconds after last change
                });
            });
        }

        function setupTeacherSearch() {
            const teacherSearch = document.createElement('div');
            teacherSearch.className = 'mb-3';
            teacherSearch.innerHTML = `
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" id="teacherSearch" placeholder="Search teachers...">
                </div>
            `;
            
            const teacherList = document.getElementById('teacherList');
            if (teacherList) {
                teacherList.parentNode.insertBefore(teacherSearch, teacherList);
                
                const searchInput = document.getElementById('teacherSearch');
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const teacherItems = document.querySelectorAll('.teacher-item');
                    
                    teacherItems.forEach(item => {
                        const teacherName = item.querySelector('h6').textContent.toLowerCase();
                        if (teacherName.includes(searchTerm)) {
                            item.style.display = '';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
        }
                async function submitEvaluationFinal() {
                        const btn = document.querySelector('button[name="submit_evaluation"]');
                        if (!btn) return;

                        const originalText = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
                        btn.disabled = true;

                        try {
                                const payload = getFormData();
                                if (!payload.teacher_id) {
                                        alert('Please select a teacher.');
                                        return;
                                }

                                const res = await fetch('../controllers/EvaluationController.php?action=submit_evaluation', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: new URLSearchParams(flattenObject(payload)).toString()
                                });

                                const json = await res.json().catch(() => null);
                                if (!json || !json.success) {
                                        const msg = (json && json.message) ? json.message : ('Submit failed (HTTP ' + res.status + ')');
                                        throw new Error(msg);
                                }

                                alert('Evaluation submitted successfully!');
                                window.location.href = '../evaluators/dashboard.php';
                        } catch (err) {
                                console.error(err);
                                alert(err.message || 'Submit failed. See console for details.');
                        } finally {
                                btn.innerHTML = originalText;
                                btn.disabled = false;
                        }
                }

                function renderOptionCards(options, targetField, primaryText = '') {
                    const base = String(primaryText || '').trim();
                    const list = (Array.isArray(options) ? options : []).filter(txt => String(txt || '').trim() && String(txt || '').trim() !== base);
                    if (!list.length) return '<div class="text-muted small">No suggestions yet.</div>';

                    return `
                        <div>
                            <div class="small text-muted fw-semibold mb-2">Other generated versions</div>
                            <div class="ai-suggestion-list">
                            ${list.map((txt) => `
                                <button type="button"
                                        class="ai-suggestion-chip ai-use-option-btn"
                                        data-target="${targetField}"
                                        data-text="${encodeURIComponent(txt)}"
                                        style="text-align: center; justify-content: center; align-items: center; white-space: normal;">
                                    <span style="display: block; width: 100%; text-align: center; text-justify: inter-word;">${escapeHtml(txt)}</span>
                                </button>
                            `).join('')}
                            </div>
                        </div>
                    `;
                }

                function renderSuggestionCards(options, targetField) {
                    const list = (Array.isArray(options) ? options : []).filter(txt => String(txt || '').trim());
                    if (!list.length) return '<div class="text-muted small">No suggestions available.</div>';

                    return `
                        <div class="ai-suggestion-label" style="font-size: 0.9rem; color: #6c757d; margin-bottom: 8px;">Click a suggestion to add it:</div>
                        ${list.map((txt) => `
                            <div class="ai-option-card"
                                 onclick="(function(el){ var ta = document.getElementById('${targetField === 'improvement_areas' ? 'improvementAreas' : targetField}'); if(ta) ta.value = decodeURIComponent(el.getAttribute('data-text')); })(this)"
                                 data-text="${encodeURIComponent(txt)}"
                                 style="background: #f0f7ff; border: 1px solid #b8d4f0; border-radius: 20px; padding: 12px 20px; margin-bottom: 8px; cursor: pointer; text-align: center; font-size: 13px; line-height: 1.55; color: #333; transition: background 0.2s, border-color 0.2s, box-shadow 0.2s;"
                                 onmouseover="this.style.background='#dceefb'; this.style.borderColor='#7ab3e0'; this.style.boxShadow='0 2px 6px rgba(0,0,0,0.08)';"
                                 onmouseout="this.style.background='#f0f7ff'; this.style.borderColor='#b8d4f0'; this.style.boxShadow='none';">
                                ${escapeHtml(txt)}
                            </div>
                        `).join('')}
                    `;
                }
    </script>
</body>
</html>