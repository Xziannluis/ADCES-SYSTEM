<?php
require_once '../auth/session-check.php';
// Only president and vice_president use the leaders evaluation page
if(!in_array($_SESSION['role'], ['president', 'vice_president'])) {
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

// All evaluators can evaluate across all departments (cross-department)
// Exclude self from the list — evaluators should not evaluate themselves
$exclude_query = "SELECT * FROM teachers WHERE status = 'active' AND (user_id IS NULL OR user_id != :current_user_id) ORDER BY name ASC";
$exclude_stmt = $db->prepare($exclude_query);
$exclude_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
$exclude_stmt->execute();
$teachers = $exclude_stmt;

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
                            $scheduleRaw = $teacher_row['evaluation_schedule'] ?? '';
                            $scheduleRoom = $teacher_row['evaluation_room'] ?? '';
                            $has_schedule = !empty($scheduleRaw) || !empty($scheduleRoom);
                            $can_evaluate_now = false;
                            $schedule_message = 'No schedule set';
                            $schedule_badge_class = 'bg-secondary';
                            $schedule_badge_text = 'Schedule required';
                            $schedule_display = trim((string)$scheduleRaw);
                            $schedule_block_message = 'No schedule is set. Please set a schedule from the Teachers page first.';

                            if (!empty($scheduleRaw)) {
                                try {
                                    $timezone = new DateTimeZone('Asia/Manila');
                                    $scheduledAt = new DateTime($scheduleRaw, $timezone);
                                    $scheduledAt->setTimezone($timezone);
                                    $now = new DateTime('now', $timezone);
                                    $expiredAt = clone $scheduledAt;
                                    $expiredAt->modify('+24 hours');
                                    $schedule_display = $scheduledAt->format('F d, Y \a\t h:i A');
                                    $schedule_message = $scheduledAt->format('F d, Y \a\t h:i A');
                                    if ($now >= $expiredAt) {
                                        // More than 24 hours past schedule — expired
                                        $can_evaluate_now = false;
                                        $schedule_badge_class = 'bg-danger';
                                        $schedule_badge_text = 'Schedule expired';
                                        $schedule_block_message = 'This schedule has expired (24 hours have passed). Please set a new schedule from the Teachers page.';
                                    } elseif ($now >= $scheduledAt) {
                                        // Within the 24-hour evaluation window
                                        $can_evaluate_now = true;
                                        $schedule_badge_class = 'bg-success';
                                        $schedule_badge_text = 'Evaluate this teacher';
                                        $schedule_block_message = '';
                                    } else {
                                        $schedule_badge_class = 'bg-warning text-dark';
                                        $schedule_badge_text = 'Not yet time';
                                        $schedule_block_message = 'Evaluation opens on ' . $schedule_message . '.';
                                    }
                                } catch (Exception $e) {
                                    $schedule_message = 'Invalid schedule';
                                    $schedule_badge_class = 'bg-danger';
                                    $schedule_badge_text = 'Invalid schedule';
                                    $schedule_block_message = 'The saved schedule is invalid. Please reschedule from the Teachers page.';
                                }
                            } elseif (!empty($scheduleRoom)) {
                                $schedule_message = 'Room assigned, waiting for date/time';
                                $schedule_badge_class = 'bg-warning text-dark';
                                $schedule_badge_text = 'Schedule incomplete';
                                $schedule_block_message = 'A room is assigned, but the evaluation date and time are still missing.';
                            }
                        ?>
                        <div class="list-group-item teacher-item <?php echo $can_evaluate_now ? '' : 'disabled'; ?>" data-teacher-id="<?php echo $teacher_row['id']; ?>" data-has-schedule="<?php echo $has_schedule ? '1' : '0'; ?>" data-can-evaluate-now="<?php echo $can_evaluate_now ? '1' : '0'; ?>" data-schedule-message="<?php echo htmlspecialchars($schedule_message, ENT_QUOTES); ?>" data-block-reason="<?php echo htmlspecialchars($schedule_block_message, ENT_QUOTES); ?>" data-subject="<?php echo htmlspecialchars($teacher_row['evaluation_subject'] ?? '', ENT_QUOTES); ?>" data-subject-area="<?php echo htmlspecialchars($teacher_row['evaluation_subject_area'] ?? '', ENT_QUOTES); ?>" data-room="<?php echo htmlspecialchars($teacher_row['evaluation_room'] ?? '', ENT_QUOTES); ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($teacher_row['name']); ?></h6>
                                    <p class="mb-0 text-muted"><?php echo htmlspecialchars($teacher_row['department']); ?></p>
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
                                <div>
                                    <span class="badge <?php echo $schedule_badge_class; ?> p-2"><?php echo htmlspecialchars($schedule_badge_text); ?></span>
                                    <i class="fas fa-chevron-right ms-2"></i>
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
                                        <input type="date" class="form-control" id="observationDate" name="observation_date" required>
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
                                <div class="row mt-4">
                                    <div class="col-md-6">
                                        <div class="border p-3">
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
                                            <div class="mb-3">
                                                <label class="form-label">Date</label>
                                                <input type="date" class="form-control" id="raterDate" name="rater_date" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="border p-3">
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
                                            <div class="mb-3">
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
                    if (isHidden) getPad(key);
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
                item.addEventListener('click', function() {
                    const canEvaluateNow = this.getAttribute('data-can-evaluate-now');
                    const blockReason = this.getAttribute('data-block-reason') || '';
                    if (canEvaluateNow !== '1') {
                        alert(blockReason || 'No schedule is set. Please set a schedule from the Teachers page first.');
                        return;
                    }
                    const teacherId = this.getAttribute('data-teacher-id');
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
            
            // Set current date
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('observationDate').value = today;
            document.getElementById('raterDate').value = today;
            document.getElementById('facultyDate').value = today;

            // Auto-fill printed names
            document.getElementById('raterPrintedName').value = <?= json_encode($_SESSION['name'] ?? '') ?>;
            const facultyName = document.getElementById('facultyName')?.value || '';
            document.getElementById('facultyPrintedName').value = facultyName;

            // Auto-fill Subject/Time of Observation from schedule
            const teacherItem = document.querySelector(`.teacher-item[data-teacher-id="${teacherId}"]`);
            const subjectData = teacherItem?.getAttribute('data-subject') || '';
            if (subjectData) {
                const subjectTimeInput = document.getElementById('subjectTime');
                if (subjectTimeInput) subjectTimeInput.value = subjectData;
            }
        }

        function showTeacherSelection() {
            document.getElementById('teacherSelection').classList.remove('d-none');
            document.getElementById('evaluationFormContainer').classList.add('d-none');
        }
                function calculateAverages() {
            // Communications average
            let commTotal = 0;
            let commCount = 0;
            
            for (let i = 0; i < 5; i++) {
                const selected = document.querySelector(`input[name="communications${i}"]:checked`);
                if (selected) {
                    commTotal += parseInt(selected.value);
                    commCount++;
                }
            }
            
            const commAvg = commCount > 0 ? (commTotal / commCount).toFixed(1) : '0.0';
            document.getElementById('communicationsAverage').textContent = commAvg;
            
            // Management average
            let mgmtTotal = 0;
            let mgmtCount = 0;
            
            for (let i = 0; i < 12; i++) {
                const selected = document.querySelector(`input[name="management${i}"]:checked`);
                if (selected) {
                    mgmtTotal += parseInt(selected.value);
                    mgmtCount++;
                }
            }
            
            const mgmtAvg = mgmtCount > 0 ? (mgmtTotal / mgmtCount).toFixed(1) : '0.0';
            document.getElementById('managementAverage').textContent = mgmtAvg;
            
            // Assessment average
            let assessTotal = 0;
            let assessCount = 0;
            
            for (let i = 0; i < 6; i++) {
                const selected = document.querySelector(`input[name="assessment${i}"]:checked`);
                if (selected) {
                    assessTotal += parseInt(selected.value);
                    assessCount++;
                }
            }
            
            const assessAvg = assessCount > 0 ? (assessTotal / assessCount).toFixed(1) : '0.0';
            document.getElementById('assessmentAverage').textContent = assessAvg;
            
            // Overall average
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
                communications: parseFloat(commAvg),
                management: parseFloat(mgmtAvg),
                assessment: parseFloat(assessAvg),
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
            const totalRequired = 5 + 12 + 6; // communications + management + assessment
            const checkedRatings = document.querySelectorAll(
                'input[name^="communications"]:checked, input[name^="management"]:checked, input[name^="assessment"]:checked'
            ).length;

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
                const checked = document.querySelectorAll('input[name^="communications"]:checked, input[name^="management"]:checked, input[name^="assessment"]:checked').length;
                const msg = `Please complete all 23 rating indicators before generating AI recommendations (${checked}/23 completed).`;
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
            const formData = {
                teacher_id: document.getElementById('selected_teacher_id').value,
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
                subject_area: (() => { const ti = document.querySelector(`.teacher-item[data-teacher-id="${document.getElementById('selected_teacher_id').value}"]`); return ti?.getAttribute('data-subject-area') || ''; })(),
                observation_room: (() => { const ti = document.querySelector(`.teacher-item[data-teacher-id="${document.getElementById('selected_teacher_id').value}"]`); return ti?.getAttribute('data-room') || ''; })(),
                ratings: {}
            };
            
            // Collect all ratings
            const tbodyIds = { communications: 'communicationsCompetence', management: 'managementPresentation', assessment: 'assessmentLearning' };
            ['communications', 'management', 'assessment'].forEach(category => {
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
            
            // Add calculated averages
            const averages = calculateAverages();
            formData.averages = averages;
            
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

        // Initialize when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Set current date
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('observationDate').value = today;
            document.getElementById('raterDate').value = today;
            document.getElementById('facultyDate').value = today;

            initializeTeacherSelection();
            setupTeacherSearch();
        });

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