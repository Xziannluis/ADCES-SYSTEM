<?php
session_start();

// Check if teacher is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../models/Evaluation.php';

$database = new Database();
$db = $database->getConnection();

// remove any past schedules so teacher doesn't see outdated entries
// Clear schedules that are more than 24 hours past their scheduled time
try {
    $db->exec("UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, evaluation_focus = NULL, evaluation_subject_area = NULL, evaluation_subject = NULL, evaluation_semester = NULL, evaluation_form_type = 'iso', scheduled_by = NULL, scheduled_department = NULL, updated_at = NOW() WHERE evaluation_schedule IS NOT NULL AND evaluation_schedule < NOW() - INTERVAL 24 HOUR");
} catch (Exception $e) {
    error_log('Failed to clear expired schedules: ' . $e->getMessage());
}

// Clear schedules for teachers where ALL assigned evaluators AND the dean/principal have completed
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
            t.evaluation_form_type = 'iso', t.scheduled_by = NULL, t.scheduled_department = NULL, t.updated_at = NOW()
        WHERE t.evaluation_schedule IS NOT NULL
          AND (t.evaluation_form_type IS NULL OR t.evaluation_form_type != 'both'
               OR (SELECT COUNT(*) FROM evaluations e2 WHERE e2.teacher_id = t.id AND e2.status = 'completed'
                   AND e2.academic_year = :ay2 AND e2.semester = :sem2 AND e2.evaluation_form_type = 'peac') > 0)
          AND NOT EXISTS (
              SELECT 1 FROM teacher_assignments ta
              WHERE ta.teacher_id = t.id
              AND NOT EXISTS (
                  SELECT 1 FROM evaluations e3
                  WHERE e3.teacher_id = t.id
                  AND e3.evaluator_id = ta.evaluator_id
                  AND e3.status = 'completed'
                  AND e3.academic_year = :ay3
                  AND e3.semester = :sem3
              )
          )
          AND (
              NOT EXISTS (
                  SELECT 1 FROM users u
                  WHERE u.role IN ('dean', 'principal')
                  AND u.status = 'active'
                  AND u.department = t.department
              )
              OR EXISTS (
                  SELECT 1 FROM evaluations e4
                  JOIN users u2 ON e4.evaluator_id = u2.id
                  WHERE e4.teacher_id = t.id
                  AND e4.status = 'completed'
                  AND e4.academic_year = :ay4
                  AND e4.semester = :sem4
                  AND u2.role IN ('dean', 'principal')
              )
          )")
        ->execute([':ay' => $curAY, ':sem' => $curSem, ':ay2' => $curAY, ':sem2' => $curSem,
                   ':ay3' => $curAY, ':sem3' => $curSem, ':ay4' => $curAY, ':sem4' => $curSem]);
} catch (Exception $e) {
    error_log('Error clearing completed-eval schedules: ' . $e->getMessage());
}

$teacher = new Teacher($db);
$evaluation = new Evaluation($db);

// Get teacher info
$teacher_data = $teacher->getById($_SESSION['teacher_id']);
if(!$teacher_data) {
    $_SESSION['error'] = "Teacher not found.";
    header("Location: ../login.php");
    exit();
}

// Teacher notifications (same dropdown style as evaluator dashboards)
$notifications = [];
$unread_count = 0;
try {
    $notif_q = "SELECT * FROM notifications
                WHERE user_id = :user_id
                  AND type IN ('schedule', 'reschedule_request', 'reschedule_accepted', 'observation_signed', 'observer_accept', 'observer_request')
                  AND is_read = 0
                ORDER BY created_at DESC
                LIMIT 10";
    $notif_stmt = $db->prepare($notif_q);
    $notif_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $notif_stmt->execute();
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notifications as $n) {
        if (empty($n['is_read'])) $unread_count++;
    }
} catch (Exception $e) {
    $notifications = [];
    $unread_count = 0;
}

// Collect filter values
$filter_academic_year = $_GET['academic_year'] ?? '';
$filter_semester = $_GET['semester'] ?? '';
$filter_month = $_GET['month'] ?? '';
$filter_form_type = $_GET['form_type'] ?? '';
$filter_department = trim((string)($_GET['department'] ?? ''));

// Department options for filter: primary + secondary/additional departments
$department_options = [];
if (!empty($teacher_data['department'])) {
    $department_options[] = $teacher_data['department'];
}
try {
    $sec_dept_stmt = $db->prepare("SELECT department FROM teacher_departments WHERE teacher_id = :tid");
    $sec_dept_stmt->execute([':tid' => $_SESSION['teacher_id']]);
    while ($sec_dept = $sec_dept_stmt->fetchColumn()) {
        $sec_dept = trim((string)$sec_dept);
        if ($sec_dept !== '' && !in_array($sec_dept, $department_options, true)) {
            $department_options[] = $sec_dept;
        }
    }
} catch (Exception $e) {
    // Keep dashboard functional if optional table is not present.
}

// Build filtered query
$where_clauses = ['e.teacher_id = :teacher_id'];
$params = [':teacher_id' => $_SESSION['teacher_id']];

if (!empty($filter_academic_year)) {
    $where_clauses[] = 'e.academic_year = :academic_year';
    $params[':academic_year'] = $filter_academic_year;
}
if (!empty($filter_semester)) {
    $where_clauses[] = 'e.semester = :semester';
    $params[':semester'] = $filter_semester;
}
if (!empty($filter_month)) {
    $where_clauses[] = 'MONTH(e.observation_date) = :obs_month';
    $params[':obs_month'] = (int) $filter_month;
}
if (!empty($filter_form_type)) {
    $where_clauses[] = 'e.evaluation_form_type = :form_type';
    $params[':form_type'] = $filter_form_type;
}
if (!empty($filter_department)) {
    $where_clauses[] = 'u.department = :department';
    $params[':department'] = $filter_department;
}

$query = "SELECT e.*, u.name as evaluator_name, u.role as evaluator_role, u.department as evaluator_department
          FROM evaluations e
          JOIN users u ON e.evaluator_id = u.id
          WHERE " . implode(' AND ', $where_clauses) . "
          ORDER BY e.created_at DESC";
$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Normalize subject text to prevent duplicate slot cards when subject strings
// include appended time text (e.g. "DBMS 8:00 AM - 10:00 AM").
$normalize_subject_slot = static function(string $subject): string {
    $s = strtolower(trim($subject));
    if ($s === '') return '';
    $s = preg_replace('/\s+\d{1,2}:\d{2}\s*(am|pm)(?:\s*-\s*(?:\d{1,2}:\d{2}\s*(am|pm))?)?\s*$/i', '', $s);
    return trim((string)$s);
};

// Group evaluations by observation date (merge multiple evaluators into one card per date)
$display_evaluations = [];
try {
    $date_groups = [];
    
    // Group all evaluations by observation_date
    foreach ($evaluations as $row) {
        $obs_date = (string)($row['observation_date'] ?? '');
        if ($obs_date === '') continue;
        
        $dateKey = date('Y-m-d', strtotime($obs_date));
        
        if (!isset($date_groups[$dateKey])) {
            $date_groups[$dateKey] = [];
        }
        $date_groups[$dateKey][] = $row;
    }
    
    // For each date, create one merged card showing the date and all evaluators
    foreach ($date_groups as $dateKey => $evals_for_date) {
        if (empty($evals_for_date)) continue;
        
        // Use the first evaluation as the base for the card
        $base = $evals_for_date[0];
        
        // Check completion for this date. The expected observer count should
        // include evaluator rows plus assigned observers that appear in the
        // observation plan, even if they do not yet have an evaluation row.
        $has_completed = false;
        $has_observer_unbalanced = false;
        $completed_evals = [];
        $completed_evaluator_ids = [];
        $expected_observer_ids = [];
        $expected_observer_names = [];
        $group_eval_ids = [];
        $pending_count = 0;
        
        foreach ($evals_for_date as $eval) {
            $eval_id = (int)($eval['id'] ?? 0);
            if ($eval_id > 0) {
                $group_eval_ids[] = $eval_id;
            }
            $eval_evaluator_id = (int)($eval['evaluator_id'] ?? 0);
            if ($eval_evaluator_id > 0) {
                $expected_observer_ids[$eval_evaluator_id] = true;
                $eval_name = trim((string)($eval['evaluator_name'] ?? ''));
                if ($eval_name !== '') {
                    $expected_observer_names[$eval_evaluator_id] = $eval_name;
                }
            }
            $eval_status = strtolower(trim((string)($eval['status'] ?? '')));
            if ($eval_status === 'observer_unbalanced') {
                $has_observer_unbalanced = true;
            }
            if ($eval_status === 'completed') {
                $has_completed = true;
                $completed_evals[] = $eval;
                if ($eval_evaluator_id > 0) {
                    $completed_evaluator_ids[$eval_evaluator_id] = true;
                }
            } else {
                $pending_count++;
            }
        }

        try {
            $assignment_params = [':teacher_id' => (int)$_SESSION['teacher_id']];
            $assignment_sql = "SELECT DISTINCT ta.evaluator_id, u.name
                               FROM teacher_assignments ta
                               INNER JOIN users u ON u.id = ta.evaluator_id
                               WHERE ta.teacher_id = :teacher_id
                                 AND u.status = 'active'
                                 AND (ta.eval_id IS NULL";
            if (!empty($group_eval_ids)) {
                $eval_placeholders = [];
                foreach (array_values(array_unique($group_eval_ids)) as $idx => $group_eval_id) {
                    $ph = ':eval_id_' . $idx;
                    $eval_placeholders[] = $ph;
                    $assignment_params[$ph] = $group_eval_id;
                }
                $assignment_sql .= " OR ta.eval_id IN (" . implode(',', $eval_placeholders) . ")";
            }
            $assignment_sql .= ")";
            $assignment_stmt = $db->prepare($assignment_sql);
            $assignment_stmt->execute($assignment_params);
            while ($observer_row = $assignment_stmt->fetch(PDO::FETCH_ASSOC)) {
                $observer_id = (int)($observer_row['evaluator_id'] ?? 0);
                if ($observer_id <= 0) continue;
                $expected_observer_ids[$observer_id] = true;
                $observer_name = trim((string)($observer_row['name'] ?? ''));
                if ($observer_name !== '') {
                    $expected_observer_names[$observer_id] = $observer_name;
                }
            }
        } catch (Exception $e) {
            // Keep the dashboard usable even if assignment lookup fails.
        }

        $expected_count = max(count($expected_observer_ids), count($evals_for_date));
        $completed_count = count($completed_evaluator_ids);
        
        // Create merged card that shows date and observer count
        $merged_eval = $base;
        $merged_eval['observation_date'] = $dateKey;
        $merged_eval['status'] = $has_observer_unbalanced ? 'observer_unbalanced' : (($expected_count > 0 && $completed_count >= $expected_count) ? 'completed' : ($has_completed ? 'completed' : 'pending'));
        $merged_eval['evaluator_names'] = [];
        $merged_eval['evaluator_count'] = $expected_count;
        $merged_eval['completed_count'] = $completed_count;
        
        // Collect all expected observer names
        foreach ($expected_observer_names as $name) {
            $safe_name = htmlspecialchars($name);
            if (!in_array($safe_name, $merged_eval['evaluator_names'], true)) {
                $merged_eval['evaluator_names'][] = $safe_name;
            }
        }
        
        $display_evaluations[] = $merged_eval;
    }
} catch (Exception $e) {
    $display_evaluations = $evaluations;
}
if (empty($display_evaluations)) {
    $display_evaluations = $evaluations;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - AI Classroom Evaluation</title>
    <?php include '../includes/header.php'; ?>
    <style>
        .teacher-info-card {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .teacher-info-card h3 {
            margin: 0;
            font-weight: 700;
        }
        .teacher-info-card p {
            margin: 5px 0 0 0;
            opacity: 0.9;
        }
        .content-area {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .evaluation-card {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .evaluation-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .evaluation-card .card-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .observers-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 10px 12px;
            margin: 10px 0;
        }
        .observers-card .label {
            font-size: 0.82rem;
            color: #6c757d;
            margin-bottom: 4px;
        }
        .observers-card .value {
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.92rem;
        }
        .btn-view {
            background: #3498db;
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }
        .btn-view:hover {
            background: #2980b9;
            color: white;
            transform: translateY(-2px);
        }
        .no-evaluations {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            color: #6c757d;
        }
        .no-evaluations i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-completed { background: #d4edda; color: #155724; }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content" style="padding:0;">
        <div class="dashboard-bg-layer"><div class="bg-img"></div></div>
        <div class="dashboard-topbar">
            <h2>Saint Michael College of Caraga</h2>
            <div class="ms-auto d-flex align-items-center gap-2">
                <!-- Notification Bell -->
                <div class="dropdown">
                    <button class="btn position-relative" type="button" id="notifBell" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle" style="font-size:0;">
                            <span class="visually-hidden">New notifications</span>
                        </span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="notifBell">
                        <div class="d-flex justify-content-between align-items-center notif-head">
                            <strong><i class="fas fa-bell me-2"></i>Notifications</strong>
                            <?php if ($unread_count > 0): ?>
                            <button class="notif-mark-all" onclick="event.stopPropagation();markAllRead()">Mark all as read</button>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($notifications)): ?>
                            <div id="notificationList">
                            <?php foreach ($notifications as $notif): ?>
                            <?php $isObserverRequest = (($notif['type'] ?? '') === 'observer_request'); ?>
                            <div class="notif-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>" id="notif-<?php echo (int)$notif['id']; ?>" data-notif-type="<?php echo htmlspecialchars((string)($notif['type'] ?? ''), ENT_QUOTES); ?>" <?php if (!empty($notif['link'])): ?>onclick="window.location.href='<?php echo htmlspecialchars($notif['link'], ENT_QUOTES); ?>'" style="cursor:pointer;"<?php endif; ?>>
                                <div class="notif-avatar">
                                    <?php if (!empty($notif['avatar'])): ?>
                                        <img src="<?php echo htmlspecialchars($notif['avatar']); ?>" alt="avatar">
                                    <?php else: ?>
                                        <i class="fas fa-user-circle fa-lg text-secondary"></i>
                                    <?php endif; ?>
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div class="d-flex align-items-start justify-content-between">
                                        <div class="notif-title">
                                            <?php echo htmlspecialchars($notif['title']); ?>
                                        </div>
                                        <?php if (!$notif['is_read']): ?>
                                            <span class="notif-unread-dot" aria-hidden="true"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notif-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                    <div class="d-flex align-items-center justify-content-between mt-2">
                                        <small class="text-muted"><i class="far fa-clock me-1"></i><?php echo date('M j, Y g:i A', strtotime($notif['created_at'])); ?></small>
                                        <div class="text-end">
                                            <span class="badge bg-light text-dark me-2"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$notif['type']))); ?></span>
                                            <?php if (!$notif['is_read'] && !$isObserverRequest): ?>
                                                <button class="btn btn-sm btn-outline-primary notif-read-btn" onclick="event.stopPropagation();markRead(<?php echo (int)$notif['id']; ?>)" title="Mark as read">
                                                    <i class="fas fa-check me-1"></i>Read
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                            <div class="dropdown-footer">
                                <button class="btn btn-link p-0" onclick="event.stopPropagation();markAllRead()"><i class="fas fa-check-double me-1"></i>Mark all as read</button>
                            </div>
                        <?php else: ?>
                            <div class="notif-empty"><i class="far fa-bell-slash me-2"></i>No notifications</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn user-menu-btn dropdown-toggle" type="button" id="teacherMenu" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="teacherMenu">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid" style="padding:24px;">

            <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Teacher Info Card -->
            <div class="teacher-info-card">
                <h3>
                    Welcome, <?php echo htmlspecialchars($teacher_data['name']); ?>!
                </h3>
                <p><i class="fas fa-check-circle me-2"></i>Status: <span class="badge bg-success">Active</span></p>
            </div>

            <!-- Filters -->
            <div class="content-area">
                <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filter Evaluations</h5>
                <form method="GET" id="filterForm" class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label for="academic_year" class="form-label fw-semibold">Academic Year</label>
                        <select name="academic_year" id="academic_year" class="form-select">
                            <option value="">All Academic Years</option>
                            <option value="2025-2026" <?php echo ($filter_academic_year === '2025-2026') ? 'selected' : ''; ?>>2025-2026</option>
                            <option value="2026-2027" <?php echo ($filter_academic_year === '2026-2027') ? 'selected' : ''; ?>>2026-2027</option>
                            <option value="2027-2028" <?php echo ($filter_academic_year === '2027-2028') ? 'selected' : ''; ?>>2027-2028</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="semester" class="form-label fw-semibold">Semester</label>
                        <select name="semester" id="semester" class="form-select">
                            <option value="">All Semesters</option>
                            <option value="1st" <?php echo ($filter_semester === '1st') ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2nd" <?php echo ($filter_semester === '2nd') ? 'selected' : ''; ?>>2nd Semester</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="month" class="form-label fw-semibold">Month</label>
                        <select name="month" id="month" class="form-select">
                            <option value="">All Months</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="form_type" class="form-label fw-semibold">Form Type</label>
                        <select name="form_type" id="form_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="iso" <?php echo ($filter_form_type === 'iso') ? 'selected' : ''; ?>>ISO</option>
                            <option value="peac" <?php echo ($filter_form_type === 'peac') ? 'selected' : ''; ?>>PEAC</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="department" class="form-label fw-semibold">Department</label>
                        <select name="department" id="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php foreach ($department_options as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo ($filter_department === $dept) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Filter</button>
                        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-undo me-1"></i>Reset</a>
                    </div>
                </form>
            </div>

            <!-- Evaluations List -->
            <div class="content-area">
                <h4 class="mb-4">
                    <i class="fas fa-file-pdf me-2"></i>My Evaluations
                    <?php if(!empty($filter_academic_year) || !empty($filter_semester) || !empty($filter_month) || !empty($filter_form_type) || !empty($filter_department)): ?>
                    <small class="text-muted fs-6">
                        (Filtered: <?php
                            $parts = [];
                            if(!empty($filter_academic_year)) $parts[] = $filter_academic_year;
                            if(!empty($filter_semester)) $parts[] = $filter_semester . ' Semester';
                            if(!empty($filter_month)) {
                                $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                                $parts[] = $monthNames[(int)$filter_month] ?? '';
                            }
                            if(!empty($filter_form_type)) $parts[] = strtoupper($filter_form_type);
                            if(!empty($filter_department)) $parts[] = $filter_department;
                            echo htmlspecialchars(implode(' / ', $parts));
                        ?>)
                    </small>
                    <?php endif; ?>
                </h4>

                <?php if(count($display_evaluations) > 0): ?>
                    <?php foreach($display_evaluations as $eval): ?>
                    <div class="evaluation-card">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h6 class="card-title">
                                    <i class="fas fa-calendar me-2"></i>Observation Date: <?php echo date('F d, Y', strtotime($eval['observation_date'])); ?>
                                    <span class="ms-2">
                                        <?php if($eval['status'] === 'completed'): ?>
                                            <span class="badge-status badge-completed">
                                                <i class="fas fa-check-circle me-1"></i>Completed
                                            </span>
                                        <?php elseif($eval['status'] === 'observer_unbalanced'): ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-exclamation-triangle me-1"></i>Observer Imbalance
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status badge-pending">
                                                <i class="fas fa-clock me-1"></i>Pending
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </h6>
                                <p class="text-muted mb-2">
                                    <i class="fas fa-users me-2"></i>
                                    <?php 
                                        if ($eval['status'] === 'observer_unbalanced') {
                                            echo 'Evaluation cannot proceed until another observer is assigned.';
                                        } elseif ($eval['completed_count'] > 0) {
                                            echo $eval['completed_count'] . ' of ' . $eval['evaluator_count'] . ' evaluations completed';
                                        } else {
                                            echo 'Waiting for evaluator' . ($eval['evaluator_count'] > 1 ? 's' : '') . ' to complete evaluation';
                                        }
                                    ?>
                                </p>
                                
                            </div>
                            <div class="col-md-4 text-md-end">
                                <?php if($eval['status'] === 'completed' && !empty($eval['observation_date'])): ?>
                                <a href="view-evaluation.php?date=<?php echo urlencode(date('Y-m-d', strtotime($eval['observation_date']))); ?>" class="btn-view">
                                    <i class="fas fa-eye me-2"></i>View 
                                </a>
                                <?php else: ?>
                                <span class="text-muted">
                                    <i class="fas fa-hourglass-half me-2"></i>Awaiting Completion
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-evaluations">
                        <i class="fas fa-inbox"></i>
                        <h5>No Evaluations Yet</h5>
                        <p>You don't have any evaluations yet. Once evaluators complete your evaluation, it will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
    function syncNotificationUI() {
        var list = document.getElementById('notificationList');
        var itemCount = list ? list.querySelectorAll('.notif-item').length : 0;
        var badge = document.querySelector('#notifBell .bg-danger');
        if (itemCount === 0) {
            if (badge) badge.remove();
            if (list) {
                list.innerHTML = '<div class="notif-empty"><i class="far fa-bell-slash me-2"></i>No notifications</div>';
            }
            document.querySelectorAll('.notif-mark-all, .dropdown-footer button[onclick*="markAllRead"]').forEach(function(btn) {
                btn.remove();
            });
            var footer = document.querySelector('.dropdown-footer');
            if (footer && footer.querySelectorAll('button, a').length === 0) {
                footer.remove();
            }
        }
    }

    function markRead(id) {
        fetch('../includes/notification_mark_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + id
        }).then(r => r.json()).then(d => {
            if (d.success) {
                var el = document.getElementById('notif-' + id);
                if (el) {
                    el.style.opacity = '0';
                    el.style.maxHeight = '0';
                    el.style.padding = '0';
                    el.style.overflow = 'hidden';
                    setTimeout(function() {
                        el.remove();
                        syncNotificationUI();
                    }, 300);
                } else {
                    syncNotificationUI();
                }
            }
        });
    }

    function markAllRead() {
        fetch('../includes/notification_mark_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'mark_all=1'
        }).then(r => r.json()).then(d => {
            if (d.success) {
                document.querySelectorAll('#notificationList .notif-item').forEach(function(el) {
                    if (el.dataset.notifType === 'observer_request') return;
                    el.style.opacity = '0';
                    el.style.maxHeight = '0';
                    el.style.padding = '0';
                    el.style.overflow = 'hidden';
                    setTimeout(function() { el.remove(); }, 300);
                });
                setTimeout(syncNotificationUI, 320);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', syncNotificationUI);
    </script>
    <?php include '../includes/email_verify_prompt.php'; ?>
    <script>
    (function() {
        const semesterMonths = {
            '1st': [6,7,8,9,10],
            '2nd': [11,12,1,2,3],
            'Summer': [4,5]
        };
        const allMonths = [
            {value: 1, label: 'January'}, {value: 2, label: 'February'}, {value: 3, label: 'March'},
            {value: 4, label: 'April'}, {value: 5, label: 'May'}, {value: 6, label: 'June'},
            {value: 7, label: 'July'}, {value: 8, label: 'August'}, {value: 9, label: 'September'},
            {value: 10, label: 'October'}, {value: 11, label: 'November'}, {value: 12, label: 'December'}
        ];

        const semesterSelect = document.getElementById('semester');
        const monthSelect = document.getElementById('month');
        const selectedMonth = <?php echo json_encode($filter_month); ?>;

        function updateMonths() {
            const sem = semesterSelect.value;
            monthSelect.innerHTML = '<option value="">All Months</option>';
            let months = allMonths;
            if (sem && semesterMonths[sem]) {
                const allowed = semesterMonths[sem];
                months = allMonths.filter(m => allowed.includes(m.value));
            }
            months.forEach(function(m) {
                const opt = document.createElement('option');
                opt.value = m.value;
                opt.textContent = m.label;
                if (String(m.value) === String(selectedMonth)) opt.selected = true;
                monthSelect.appendChild(opt);
            });
        }

        semesterSelect.addEventListener('change', function() {
            monthSelect.innerHTML = '<option value="">All Months</option>';
            const sem = this.value;
            let months = allMonths;
            if (sem && semesterMonths[sem]) {
                const allowed = semesterMonths[sem];
                months = allMonths.filter(m => allowed.includes(m.value));
            }
            months.forEach(function(m) {
                const opt = document.createElement('option');
                opt.value = m.value;
                opt.textContent = m.label;
                monthSelect.appendChild(opt);
            });
        });

        updateMonths();
    })();
    </script>
</body>
</html>
