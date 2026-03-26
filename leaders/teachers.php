<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../includes/mailer.php';
require_once '../includes/photo_helper.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);

// Clear schedules that are more than 24 hours past
try {
    $db->exec("UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, evaluation_focus = NULL, evaluation_subject_area = NULL, evaluation_subject = NULL, evaluation_semester = NULL, updated_at = NOW() WHERE evaluation_schedule IS NOT NULL AND evaluation_schedule < NOW() - INTERVAL 24 HOUR");
} catch (Exception $e) {
    error_log('Error clearing expired schedules: ' . $e->getMessage());
}

$success_message = '';
$error_message = '';

$all_departments = ['ELEM', 'JHS', 'SHS', 'CCIS', 'CAS', 'CTEAS', 'CBM', 'CTHM', 'CCJE'];
$filter_department = isset($_GET['department']) ? trim($_GET['department']) : '';

// Toggle teacher status (activate/deactivate)
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status') {
    $teacher_id = $_GET['teacher_id'] ?? '';
    if (!empty($teacher_id)) {
        if ($teacher->toggleStatus($teacher_id)) {
            $success_message = "Teacher status updated successfully!";
        } else {
            $error_message = "Failed to update teacher status.";
        }
    }
    $redirect = 'teachers.php';
    if ($filter_department !== '') $redirect .= '?department=' . urlencode($filter_department);
    header("Location: $redirect");
    exit();
}

// Update evaluation schedule and room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_schedule') {
    $teacher_id = $_POST['teacher_id'] ?? '';
    $schedule = $_POST['evaluation_schedule'] ?? '';
    $room = $_POST['evaluation_room'] ?? '';
    $focus = isset($_POST['evaluation_focus']) && is_array($_POST['evaluation_focus']) ? $_POST['evaluation_focus'] : [];
    $subject_area = trim($_POST['evaluation_subject_area'] ?? '');
    $subject = trim($_POST['evaluation_subject'] ?? '');
    $semester = trim($_POST['evaluation_semester'] ?? '');
    $semester = in_array($semester, ['1st', '2nd']) ? $semester : null;
    $form_type = trim($_POST['evaluation_form_type'] ?? 'iso');
    if (!in_array($form_type, ['iso', 'peac', 'both'])) $form_type = 'iso';

    // Validate focus values
    $valid_focus = ['communications', 'management', 'assessment', 'teacher_actions', 'student_learning_actions'];
    $focus = array_values(array_intersect($focus, $valid_focus));
    $focus_json = !empty($focus) ? json_encode($focus) : null;

    if (!empty($teacher_id)) {
        $query = "UPDATE teachers SET evaluation_schedule = :schedule, evaluation_room = :room, evaluation_focus = :focus, evaluation_subject_area = :subject_area, evaluation_subject = :subject, evaluation_semester = :semester, evaluation_form_type = :form_type, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':schedule', $schedule);
        $stmt->bindParam(':room', $room);
        $stmt->bindParam(':focus', $focus_json);
        $stmt->bindParam(':subject_area', $subject_area);
        $stmt->bindParam(':subject', $subject);
        $stmt->bindParam(':semester', $semester);
        $stmt->bindParam(':form_type', $form_type);
        $stmt->bindParam(':id', $teacher_id);

        if ($stmt->execute()) {
            $success_message = "Evaluation schedule updated successfully!";
            notifyScheduleParticipants($db, $teacher_id, $schedule, $room, $_SESSION['user_id'], $_SESSION['name'] ?? 'Evaluator');
        } else {
            $error_message = "Failed to update schedule.";
        }
    }
}

// Cancel / clear evaluation schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_schedule') {
    $teacher_id = $_POST['teacher_id'] ?? '';
    if (!empty($teacher_id)) {
        $query = "UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, evaluation_focus = NULL, evaluation_subject_area = NULL, evaluation_subject = NULL, evaluation_semester = NULL, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $teacher_id);
        if ($stmt->execute()) {
            $success_message = "Evaluation schedule cancelled.";
        } else {
            $error_message = "Failed to cancel schedule.";
        }
    }
}

// AJAX: Set teaching semester for a teacher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_teaching_semester') {
    $teacher_id = $_POST['teacher_id'] ?? '';
    $teaching_sem = trim($_POST['teaching_semester'] ?? '');
    $valid_values = ['1st', '2nd', 'Both'];
    $teaching_sem = in_array($teaching_sem, $valid_values) ? $teaching_sem : null;

    header('Content-Type: application/json');
    if (!empty($teacher_id)) {
        $stmt = $db->prepare("UPDATE teachers SET teaching_semester = :sem, updated_at = NOW() WHERE id = :id");
        $stmt->bindParam(':sem', $teaching_sem);
        $stmt->bindParam(':id', $teacher_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'semester' => $teaching_sem]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Teacher ID required.']);
    }
    exit();
}

// Get teachers, filtered by department if selected
$allTeachersStmt = $teacher->getAllTeachers('active');
$teachers_list = [];
while ($t = $allTeachersStmt->fetch(PDO::FETCH_ASSOC)) {
    $dept = $t['department'] ?? 'Unassigned';
    if ($filter_department !== '' && $dept !== $filter_department) {
        continue;
    }
    $teachers_list[] = $t;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .teacher-photo-section {
            position: relative;
            height: 180px;
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: visible;
        }
        .sem-gear-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(255,255,255,0.25);
            border: none;
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.85rem;
            z-index: 2;
            transition: background 0.2s;
        }
        .sem-gear-btn:hover {
            background: rgba(255,255,255,0.45);
        }
        .sem-dropdown {
            position: absolute;
            top: 38px;
            right: 8px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            min-width: 150px;
            z-index: 10;
            display: none;
            overflow: hidden;
        }
        .sem-dropdown.show {
            display: block;
        }
        .sem-dropdown .sem-title {
            font-size: 0.7rem;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            padding: 8px 14px 4px;
            letter-spacing: 0.5px;
        }
        .sem-dropdown .sem-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            font-size: 0.82rem;
            color: #333;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }
        .sem-dropdown .sem-option:hover {
            background: #f0f4ff;
        }
        .sem-dropdown .sem-option.active {
            color: #1b66c9;
            font-weight: 600;
        }
        .sem-dropdown .sem-option .fa-check {
            font-size: 0.7rem;
            visibility: hidden;
        }
        .sem-dropdown .sem-option.active .fa-check {
            visibility: visible;
        }
        .sem-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            font-size: 0.65rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            background: rgba(255,255,255,0.9);
            color: #333;
            z-index: 2;
        }
        .teacher-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .default-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 4px solid white;
        }
        .default-photo i {
            font-size: 2.5rem;
            color: white;
        }
        .teacher-info {
            padding: 20px;
            text-align: center;
        }
        .teacher-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: #2c3e50;
        }
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .teacher-actions {
            justify-content: center;
            margin-top: 15px;
        }
        .teacher-actions .btn {
            min-width: 80px;
            font-size: 0.75rem;
            padding: 5px 10px;
        }
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
        }
        .schedule-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-top: 8px;
        }
        .schedule-modal .modal-dialog {
            max-width: 640px;
            margin: 1rem auto;
        }
        @media (max-width: 768px) {
            .schedule-modal .modal-dialog {
                max-width: calc(100vw - 1rem);
                margin: 0.5rem auto;
            }
        }
        .schedule-modal .modal-header {
            background: linear-gradient(135deg, #1b66c9, #0f4fa8);
            color: #fff;
            border-bottom: none;
        }
        .schedule-modal .modal-title { font-weight: 600; }
        .schedule-modal .modal-body { padding: 20px 22px 8px; }
        .schedule-modal .modal-footer { border-top: none; padding: 12px 22px 20px; }
        .schedule-card {
            border: 1px solid #e6eef9;
            background: #f7fbff;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }
        .schedule-field label { font-weight: 600; }
        .schedule-help { color: #6c757d; font-size: 0.8rem; margin-top: 4px; }
        .schedule-preview {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .schedule-preview .preview-chip {
            background: #fff;
            border: 1px solid #d7e3f7;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.8rem;
            color: #2a3b4f;
        }
        .schedule-preview .preview-chip i {
            color: #1b66c9;
            margin-right: 6px;
        }
        @media (max-width: 767.98px) {
            .teacher-photo-section { height: 160px; }
            .teacher-info { padding: 16px; }
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
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="leaderMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?> (<?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="leaderMenu">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

            <?php if(!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if(!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Department Filter -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="fas fa-users me-2"></i>All Teachers<?php echo $filter_department !== '' ? ' &mdash; ' . htmlspecialchars($filter_department) : ''; ?></h5>
                <select id="departmentFilter" class="form-select w-auto" onchange="window.location.href='teachers.php?department='+this.value">
                    <option value="">All Departments</option>
                    <?php foreach ($all_departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filter_department === $dept ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Teacher Cards -->
            <div class="teacher-cards-container">
                <?php if (!empty($teachers_list)): ?>
                    <?php foreach ($teachers_list as $teacher_row): ?>
                    <?php $currentSem = $teacher_row['teaching_semester'] ?? ''; ?>
                    <div class="teacher-card">
                        <div class="teacher-photo-section">
                            <button class="sem-gear-btn" onclick="toggleSemDropdown(event, this)" title="Set teaching semester">
                                <i class="fas fa-ellipsis-vertical"></i>
                            </button>
                            <div class="sem-dropdown" data-teacher-id="<?php echo $teacher_row['id']; ?>">
                                <div class="sem-title">Teaching Semester</div>
                                <button class="sem-option<?php echo $currentSem === '' ? ' active' : ''; ?>" onclick="setTeachingSemester(event, <?php echo $teacher_row['id']; ?>, '')">
                                    <i class="fas fa-check"></i> Not Set
                                </button>
                                <button class="sem-option<?php echo $currentSem === '1st' ? ' active' : ''; ?>" onclick="setTeachingSemester(event, <?php echo $teacher_row['id']; ?>, '1st')">
                                    <i class="fas fa-check"></i> 1st Semester
                                </button>
                                <button class="sem-option<?php echo $currentSem === '2nd' ? ' active' : ''; ?>" onclick="setTeachingSemester(event, <?php echo $teacher_row['id']; ?>, '2nd')">
                                    <i class="fas fa-check"></i> 2nd Semester
                                </button>
                                <button class="sem-option<?php echo $currentSem === 'Both' ? ' active' : ''; ?>" onclick="setTeachingSemester(event, <?php echo $teacher_row['id']; ?>, 'Both')">
                                    <i class="fas fa-check"></i> Both Semesters
                                </button>
                            </div>
                            <?php
                                $teacherPhotoUrl = getPhotoUrl('teacher', $teacher_row['id'], $teacher_row['photo_path'] ?? '');
                            ?>
                            <?php if($teacherPhotoUrl): ?>
                                <img src="<?php echo htmlspecialchars($teacherPhotoUrl); ?>"
                                     alt="<?php echo htmlspecialchars($teacher_row['name']); ?>"
                                     class="teacher-photo"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="default-photo" style="display: none;">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php else: ?>
                                <div class="default-photo">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="teacher-info">
                            <div class="teacher-name"><?php echo htmlspecialchars($teacher_row['name']); ?></div>

                            <div class="status-badge badge bg-<?php echo $teacher_row['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo ucfirst($teacher_row['status']); ?>
                            </div>

                            <?php if(!empty($teacher_row['evaluation_schedule']) || !empty($teacher_row['evaluation_room'])): ?>
                            <div class="schedule-info">
                                <?php if(!empty($teacher_row['evaluation_schedule'])): ?>
                                    <?php $scheduleFormatted = date('F d, Y \a\t h:i A', strtotime($teacher_row['evaluation_schedule'])); ?>
                                    <div><i class="fas fa-calendar me-2"></i><?php echo htmlspecialchars($scheduleFormatted); ?></div>
                                <?php endif; ?>
                                <?php if(!empty($teacher_row['evaluation_room'])): ?>
                                    <div><i class="fas fa-door-open me-2"></i><?php echo htmlspecialchars($teacher_row['evaluation_room']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div class="teacher-actions">
                                <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#scheduleModal" onclick="editSchedule(<?php echo $teacher_row['id']; ?>, '<?php echo htmlspecialchars($teacher_row['evaluation_schedule'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($teacher_row['evaluation_room'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($teacher_row['evaluation_focus'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($teacher_row['evaluation_subject_area'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($teacher_row['evaluation_subject'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($teacher_row['evaluation_semester'] ?? '', ENT_QUOTES); ?>')">
                                    <i class="fas fa-calendar"></i> Schedule
                                </button>

                                <a href="?action=toggle_status&teacher_id=<?php echo $teacher_row['id']; ?><?php echo $filter_department !== '' ? '&department=' . urlencode($filter_department) : ''; ?>" class="btn btn-sm btn-outline-dark" onclick="return confirm('Are you sure you want to deactivate this teacher?');">
                                    <i class="fas fa-ban"></i> Deactivate
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>No Teachers Found</h5>
                        <p class="text-muted">No active teachers found<?php echo $filter_department !== '' ? ' in ' . htmlspecialchars($filter_department) : ''; ?>.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <!-- Schedule and Room Modal -->
    <div class="modal fade schedule-modal" id="scheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calendar-check me-2"></i>Set Evaluation Schedule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_schedule">
                        <input type="hidden" name="teacher_id" id="schedule_teacher_id">

                        <div class="schedule-card">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fas fa-circle-info text-primary mt-1"></i>
                                <div>
                                    <div class="fw-semibold">This schedule unlocks evaluation access</div>
                                    <div class="text-muted small">All fields are required so evaluators can plan the observation.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Evaluation Form Type -->
                        <div class="form-group schedule-field">
                            <label class="form-label">Evaluation Form <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="evaluation_form_type" value="iso" id="form_iso" required checked>
                                    <label class="form-check-label" for="form_iso"><i class="fas fa-file-alt me-1"></i>ISO</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="evaluation_form_type" value="peac" id="form_peac">
                                    <label class="form-check-label" for="form_peac"><i class="fas fa-clipboard-check me-1"></i>PEAC</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="evaluation_form_type" value="both" id="form_both">
                                    <label class="form-check-label" for="form_both"><i class="fas fa-layer-group me-1"></i>Both</label>
                                </div>
                            </div>
                        </div>

                        <!-- Semester -->
                        <div class="form-group schedule-field">
                            <label class="form-label">Semester <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3" id="semesterRadios">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="evaluation_semester" value="1st" id="semester_1st" required>
                                    <label class="form-check-label" for="semester_1st">1st Semester</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="evaluation_semester" value="2nd" id="semester_2nd">
                                    <label class="form-check-label" for="semester_2nd">2nd Semester</label>
                                </div>
                            </div>
                            <div class="schedule-help">Select the semester for this evaluation.</div>
                        </div>

                        <!-- Focus of Observation -->
                        <div class="form-group schedule-field" id="focusObservationGroup">
                            <label class="form-label">Focus of Observation <span class="text-danger">*</span></label>
                            <!-- ISO Focus Options -->
                            <div class="d-flex flex-column gap-2" id="isoFocusCheckboxes">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="communications" id="focus_communications">
                                    <label class="form-check-label" for="focus_communications">Communication Competence</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="management" id="focus_management">
                                    <label class="form-check-label" for="focus_management">Management and Presentation of the Lesson</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="assessment" id="focus_assessment">
                                    <label class="form-check-label" for="focus_assessment">Assessment of Students' Learning</label>
                                </div>
                            </div>
                            <!-- PEAC Focus Options (Hidden by default) -->
                            <div class="d-flex flex-column gap-2" id="peacFocusCheckboxes" style="display:none;">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="teacher_actions" id="focus_teacher_actions">
                                    <label class="form-check-label" for="focus_teacher_actions">Teacher Actions</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="student_learning_actions" id="focus_student_learning">
                                    <label class="form-check-label" for="focus_student_learning">Student Learning Actions</label>
                                </div>
                            </div>
                            <div class="schedule-help">Select at least one focus area for the observation.</div>
                        </div>

                        <div class="form-group schedule-field">
                            <label class="form-label">Evaluation Schedule <span class="text-danger">*</span></label>
                            <input type="hidden" id="evaluation_schedule" name="evaluation_schedule" required>
                            <div class="row g-2">
                                <div class="col-7">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                        <input type="date" class="form-control" id="evaluation_date" required>
                                    </div>
                                </div>
                                <div class="col-5">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                        <input type="time" class="form-control" id="evaluation_time" step="900" required>
                                    </div>
                                </div>
                            </div>
                            <div class="schedule-help">Pick a date and time for the classroom observation.</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-group schedule-field">
                                    <label class="form-label">Subject Area <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-book-open"></i></span>
                                        <input type="text" class="form-control" id="evaluation_subject_area" name="evaluation_subject_area" required placeholder="e.g., Social Sciences, English, Physical Education">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group schedule-field">
                                    <label class="form-label">Subject <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-chalkboard"></i></span>
                                        <input type="text" class="form-control" id="evaluation_subject" name="evaluation_subject" required placeholder="e.g., GEC 9 – Ethics, PE 4 PATHFIT 4">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group schedule-field">
                            <label class="form-label">Classroom/Room <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-location-dot"></i></span>
                                <input type="text" class="form-control" id="evaluation_room" name="evaluation_room" required placeholder="e.g., Room 101, Laboratory B">
                            </div>
                            <div class="schedule-help">Location where the evaluation will take place.</div>
                        </div>

                        <div class="schedule-preview" id="schedulePreview">
                            <span class="preview-chip" id="schedulePreviewTime"><i class="fas fa-calendar"></i>No date selected</span>
                            <span class="preview-chip" id="schedulePreviewRoom"><i class="fas fa-door-open"></i>No room selected</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Schedule & Room</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleSemDropdown(e, btn) {
            e.stopPropagation();
            const dropdown = btn.nextElementSibling;
            document.querySelectorAll('.sem-dropdown.show').forEach(d => { if (d !== dropdown) d.classList.remove('show'); });
            dropdown.classList.toggle('show');
        }

        document.addEventListener('click', () => {
            document.querySelectorAll('.sem-dropdown.show').forEach(d => d.classList.remove('show'));
        });

        function setTeachingSemester(e, teacherId, value) {
            e.stopPropagation();
            const formData = new FormData();
            formData.append('action', 'set_teaching_semester');
            formData.append('teacher_id', teacherId);
            formData.append('teaching_semester', value);

            fetch('teachers.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const dropdown = e.target.closest('.sem-dropdown');
                        dropdown.querySelectorAll('.sem-option').forEach(o => o.classList.remove('active'));
                        e.target.closest('.sem-option').classList.add('active');
                        dropdown.classList.remove('show');
                        const photoSection = dropdown.closest('.teacher-photo-section');
                        dropdown.closest('.teacher-card').setAttribute('data-semester', value);
                    } else {
                        alert('Failed: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(() => alert('Network error. Please try again.'));
        }

        function updateSchedulePreview() {
            const dateInput = document.getElementById('evaluation_date');
            const timeInput = document.getElementById('evaluation_time');
            const roomInput = document.getElementById('evaluation_room');
            const previewTime = document.getElementById('schedulePreviewTime');
            const previewRoom = document.getElementById('schedulePreviewRoom');

            if (previewTime) {
                const dateVal = dateInput?.value || '';
                const timeVal = timeInput?.value || '';
                if (dateVal && timeVal) {
                    const date = new Date(`${dateVal}T${timeVal}`);
                    const formatter = new Intl.DateTimeFormat('en-US', {
                        month: 'short', day: '2-digit', year: 'numeric',
                        hour: 'numeric', minute: '2-digit', hour12: true
                    });
                    const formatted = isNaN(date.getTime()) ? `${dateVal} ${timeVal}` : formatter.format(date);
                    previewTime.innerHTML = '<i class="fas fa-calendar"></i>' + formatted;
                } else if (dateVal) {
                    const date = new Date(`${dateVal}T00:00`);
                    const formatter = new Intl.DateTimeFormat('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                    const formatted = isNaN(date.getTime()) ? dateVal : formatter.format(date);
                    previewTime.innerHTML = '<i class="fas fa-calendar"></i>' + formatted;
                } else {
                    previewTime.innerHTML = '<i class="fas fa-calendar"></i>No date selected';
                }
            }

            if (previewRoom) {
                const room = roomInput?.value.trim() || '';
                previewRoom.innerHTML = '<i class="fas fa-door-open"></i>' + (room || 'No room selected');
            }
        }

        function editSchedule(teacherId, schedule, room, focus, subjectArea, subject, semester, formType) {
            document.getElementById('schedule_teacher_id').value = teacherId;
            const scheduleInput = document.getElementById('evaluation_schedule');
            const dateInput = document.getElementById('evaluation_date');
            const timeInput = document.getElementById('evaluation_time');
            scheduleInput.value = schedule || '';
            document.getElementById('evaluation_room').value = room || '';
            document.getElementById('evaluation_subject_area').value = subjectArea || '';
            document.getElementById('evaluation_subject').value = subject || '';

            // Set semester radio
            document.getElementById('semester_1st').checked = (semester === '1st');
            document.getElementById('semester_2nd').checked = (semester === '2nd');

            // Set form type radio
            formType = formType || 'iso';
            document.getElementById('form_iso').checked = (formType === 'iso');
            document.getElementById('form_peac').checked = (formType === 'peac');
            document.getElementById('form_both').checked = (formType === 'both');

            // Toggle focus visibility
            document.getElementById('isoFocusCheckboxes').style.display = (formType === 'peac') ? 'none' : '';
            document.getElementById('peacFocusCheckboxes').style.display = (formType === 'iso') ? 'none' : '';

            // Set focus checkboxes
            document.getElementById('focus_communications').checked = false;
            document.getElementById('focus_management').checked = false;
            document.getElementById('focus_assessment').checked = false;
            document.getElementById('focus_teacher_actions').checked = false;
            document.getElementById('focus_student_learning').checked = false;
            if (focus) {
                try {
                    const focusArr = typeof focus === 'string' ? JSON.parse(focus) : focus;
                    if (Array.isArray(focusArr)) {
                        focusArr.forEach(f => {
                            const el = document.getElementById('focus_' + f);
                            if (el) el.checked = true;
                        });
                    }
                } catch(e) {}
            }

            if (schedule) {
                const normalized = schedule.replace(' ', 'T');
                const parsed = new Date(normalized);
                if (!isNaN(parsed.getTime())) {
                    dateInput.value = parsed.toISOString().slice(0, 10);
                    timeInput.value = parsed.toTimeString().slice(0, 5);
                } else if (normalized.includes('T')) {
                    const parts = normalized.split('T');
                    dateInput.value = parts[0] || '';
                    timeInput.value = (parts[1] || '').slice(0, 5);
                }
            } else {
                dateInput.value = '';
                timeInput.value = '';
            }
            updateSchedulePreview();
        }

        document.addEventListener('DOMContentLoaded', () => {
            const scheduleInput = document.getElementById('evaluation_schedule');
            const dateInput = document.getElementById('evaluation_date');
            const timeInput = document.getElementById('evaluation_time');
            const roomInput = document.getElementById('evaluation_room');
            if (dateInput) dateInput.addEventListener('input', updateSchedulePreview);
            if (timeInput) timeInput.addEventListener('input', updateSchedulePreview);
            if (roomInput) roomInput.addEventListener('input', updateSchedulePreview);

            // Toggle Focus of Observation based on form type selection
            document.querySelectorAll('input[name="evaluation_form_type"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    const isoFocus = document.getElementById('isoFocusCheckboxes');
                    const peacFocus = document.getElementById('peacFocusCheckboxes');
                    if (this.value === 'peac') {
                        isoFocus.style.display = 'none';
                        peacFocus.style.display = '';
                        document.getElementById('focus_communications').checked = false;
                        document.getElementById('focus_management').checked = false;
                        document.getElementById('focus_assessment').checked = false;
                    } else if (this.value === 'iso') {
                        isoFocus.style.display = '';
                        peacFocus.style.display = 'none';
                        document.getElementById('focus_teacher_actions').checked = false;
                        document.getElementById('focus_student_learning').checked = false;
                    } else {
                        isoFocus.style.display = '';
                        peacFocus.style.display = '';
                    }
                });
            });

            const scheduleForm = document.querySelector('#scheduleModal form');
            if (scheduleForm) {
                scheduleForm.addEventListener('submit', (e) => {
                    const selectedFormType = document.querySelector('input[name="evaluation_form_type"]:checked')?.value || 'iso';
                    const isoChecked = document.querySelectorAll('#isoFocusCheckboxes input[type="checkbox"]:checked');
                    const peacChecked = document.querySelectorAll('#peacFocusCheckboxes input[type="checkbox"]:checked');

                    if (selectedFormType === 'iso' && isoChecked.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one Focus of Observation.');
                        return false;
                    }
                    if (selectedFormType === 'peac' && peacChecked.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one Focus of Observation.');
                        return false;
                    }
                    if (selectedFormType === 'both' && isoChecked.length === 0 && peacChecked.length === 0) {
                        e.preventDefault();
                        alert('Please select at least one Focus of Observation.');
                        return false;
                    }
                    const dateVal = dateInput?.value || '';
                    const timeVal = timeInput?.value || '';
                    scheduleInput.value = (dateVal && timeVal) ? `${dateVal} ${timeVal}:00` : '';
                });
            }
        });
    </script>
</body>
</html>
