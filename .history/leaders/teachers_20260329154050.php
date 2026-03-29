<?php
// Redirect to evaluators/teachers.php (consolidated)
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}
$qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: ../evaluators/teachers.php" . $qs);
exit();
require_once '../models/Teacher.php';
require_once '../includes/mailer.php';
require_once '../includes/photo_helper.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);

// Clear schedules that are more than 24 hours past
try {
    $db->exec("UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, evaluation_focus = NULL, evaluation_subject_area = NULL, evaluation_subject = NULL, evaluation_semester = NULL, evaluation_form_type = 'iso', scheduled_by = NULL, updated_at = NOW() WHERE evaluation_schedule IS NOT NULL AND evaluation_schedule < NOW() - INTERVAL 24 HOUR");
} catch (Exception $e) {
    error_log('Error clearing expired schedules: ' . $e->getMessage());
}

// Also clear schedules for teachers who already have a completed evaluation this period
try {
    $month = (int)date('n');
    $year = (int)date('Y');
    $curAY = ($month >= 6) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
    $curSem = ($month >= 6 && $month <= 10) ? '1st' : '2nd';
    $db->prepare("UPDATE teachers t
        INNER JOIN evaluations e ON e.teacher_id = t.id AND e.status = 'completed'
            AND e.academic_year = :ay AND e.semester = :sem
        SET t.evaluation_schedule = NULL, t.evaluation_room = NULL, t.evaluation_focus = NULL,
            t.evaluation_subject_area = NULL, t.evaluation_subject = NULL, t.evaluation_semester = NULL,
            t.evaluation_form_type = 'iso', t.scheduled_by = NULL, t.updated_at = NOW()
        WHERE t.evaluation_schedule IS NOT NULL
          AND (t.evaluation_form_type IS NULL OR t.evaluation_form_type != 'both'
               OR (SELECT COUNT(*) FROM evaluations e2 WHERE e2.teacher_id = t.id AND e2.status = 'completed'
                   AND e2.academic_year = :ay2 AND e2.semester = :sem2 AND e2.evaluation_form_type = 'peac') > 0)")
        ->execute([':ay' => $curAY, ':sem' => $curSem, ':ay2' => $curAY, ':sem2' => $curSem]);
} catch (Exception $e) {
    error_log('Error clearing completed-eval schedules: ' . $e->getMessage());
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
// Cancel / clear evaluation schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_schedule') {
    $teacher_id = $_POST['teacher_id'] ?? '';
    if (!empty($teacher_id)) {
        $query = "UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, evaluation_focus = NULL, evaluation_subject_area = NULL, evaluation_subject = NULL, evaluation_semester = NULL, scheduled_by = NULL, scheduled_department = NULL, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $teacher_id);
        if ($stmt->execute()) {
            $success_message = "Evaluation schedule cancelled.";
        } else {
            $error_message = "Failed to cancel schedule.";
        }
    }
}

// Mark evaluation done
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_done') {
    $teacher_id = $_POST['teacher_id'] ?? '';
    if (!empty($teacher_id)) {
        try {
            $db->beginTransaction();

            $query = "UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, evaluation_focus = NULL, evaluation_subject_area = NULL, evaluation_subject = NULL, evaluation_semester = NULL, scheduled_by = NULL, scheduled_department = NULL, updated_at = NOW() WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $teacher_id);
            $stmt->execute();

            $latestEvalStmt = $db->prepare("SELECT id, status FROM evaluations WHERE teacher_id = :teacher_id ORDER BY created_at DESC, id DESC LIMIT 1");
            $latestEvalStmt->bindParam(':teacher_id', $teacher_id);
            $latestEvalStmt->execute();
            $latestEval = $latestEvalStmt->fetch(PDO::FETCH_ASSOC);

            if ($latestEval) {
                $updateEvalStmt = $db->prepare("UPDATE evaluations SET status = 'completed' WHERE id = :id AND (status IS NULL OR status <> 'completed')");
                $updateEvalStmt->bindParam(':id', $latestEval['id']);
                $updateEvalStmt->execute();
            }

            $tq = $db->prepare("SELECT user_id, name FROM teachers WHERE id = :id LIMIT 1");
            $tq->bindParam(':id', $teacher_id);
            $tq->execute();
            $tdata = $tq->fetch(PDO::FETCH_ASSOC);
            $uid = $tdata['user_id'] ?? null;

            $description = sprintf(
                "Evaluation marked done for %s by %s (user_id=%d)%s",
                $tdata['name'] ?? ('teacher_id=' . $teacher_id),
                $_SESSION['name'],
                $_SESSION['user_id'],
                $latestEval ? (sprintf("; evaluation_id=%d", (int)$latestEval['id'])) : '; no evaluation record found'
            );

            $log_q = $db->prepare("INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, :action, :description, :ip)");
            $action = 'EVALUATION_MARKED_DONE';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $log_q->bindValue(':user_id', $uid ?: $_SESSION['user_id']);
            $log_q->bindParam(':action', $action);
            $log_q->bindParam(':description', $description);
            $log_q->bindParam(':ip', $ip);
            $log_q->execute();

            $db->commit();
            $success_message = $latestEval ? "Marked as evaluated. Evaluation status updated." : "Marked as evaluated. Schedule cleared.";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Mark done error: ' . $e->getMessage());
            $error_message = "Failed to mark as done.";
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

// Build teacher departments map (primary + secondary) for schedule modal
$teacher_depts_map = [];
foreach ($teachers_list as $t) {
    $tid = $t['id'];
    $depts = [$t['department']];
    $sec = $teacher->getSecondaryDepartments($tid);
    foreach ($sec as $sd) {
        if (!in_array($sd, $depts)) $depts[] = $sd;
    }
    $teacher_depts_map[$tid] = $depts;
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

        document.addEventListener('DOMContentLoaded', () => {
        });
    </script>
</body>
</html>
