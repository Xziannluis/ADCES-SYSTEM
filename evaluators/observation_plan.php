<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);

// AJAX handler: update evaluation subject_area or observation_room
if (isset($_GET['ajax_update']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $eval_id = (int)($_POST['eval_id'] ?? 0);
    $field = $_POST['field'] ?? '';
    $value = trim($_POST['value'] ?? '');
    $allowed = ['subject_area', 'observation_room'];
    if ($eval_id > 0 && in_array($field, $allowed, true) && $value !== '') {
        $stmt = $db->prepare("UPDATE evaluations SET {$field} = :val WHERE id = :id");
        $stmt->bindValue(':val', $value);
        $stmt->bindValue(':id', $eval_id, PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
    }
    exit();
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

$raw_department = (string)($_SESSION['department'] ?? '');
$department_display = $department_map[$raw_department] ?? $raw_department;

$is_coordinator = in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator']);

// Get semester filter
$semester = $_GET['semester'] ?? '1st';
$academic_year = $_GET['academic_year'] ?? '';
$filter_month = $_GET['month'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Auto-detect academic year if not set
if (empty($academic_year)) {
    $month = (int)date('n');
    $year = (int)date('Y');
    if ($month >= 6) {
        $academic_year = $year . '-' . ($year + 1);
    } else {
        $academic_year = ($year - 1) . '-' . $year;
    }
}

// Get teachers who have evaluations OR a scheduled observation
// Coordinators see teachers assigned to them + teachers in their department
// Deans/principals see teachers in their department + those they personally evaluated
if ($is_coordinator) {
    // 1) Teachers with evaluations — assigned to this coordinator or personally evaluated by them
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     e.id as eval_id, e.observation_date, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              LEFT JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :assigned_evaluator_id
              WHERE (ta.evaluator_id IS NOT NULL OR e.evaluator_id = :evaluator_id)
              AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              AND e.academic_year = :academic_year
              AND e.semester = :semester
              ORDER BY t.name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':assigned_evaluator_id', $_SESSION['user_id']);
    $stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->bindParam(':academic_year', $academic_year);
    $stmt->bindParam(':semester', $semester);
} else {
    // Dean/principal query
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     e.id as eval_id, e.observation_date, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              WHERE (t.department = :department OR e.evaluator_id = :evaluator_id)
              AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              AND e.academic_year = :academic_year
              AND e.semester = :semester
              ORDER BY t.name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $raw_department);
    $stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->bindParam(':academic_year', $academic_year);
    $stmt->bindParam(':semester', $semester);
}
$stmt->execute();
$eval_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Teachers with a schedule set but no evaluation yet (upcoming observations)
//    Also include teachers who DO have evaluations but also have a fresh schedule set
//    (e.g. re-scheduled for another observation in the same semester)
if ($is_coordinator) {
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester
                    FROM teachers t
                    LEFT JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :assigned_evaluator_id
                    WHERE (ta.evaluator_id IS NOT NULL OR t.department = :department)
                      AND t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND t.evaluation_schedule != ''
                      AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                      AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                    ORDER BY t.name ASC";
    $sched_stmt = $db->prepare($sched_query);
    $sched_stmt->bindParam(':assigned_evaluator_id', $_SESSION['user_id']);
    $sched_stmt->bindParam(':department', $raw_department);
    $sched_stmt->bindParam(':filter_semester', $semester);
    $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
} else {
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester
                    FROM teachers t
                    WHERE t.department = :department
                      AND t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND t.evaluation_schedule != ''
                      AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                      AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                    ORDER BY t.name ASC";
    $sched_stmt = $db->prepare($sched_query);
    $sched_stmt->bindParam(':department', $raw_department);
    $sched_stmt->bindParam(':filter_semester', $semester);
    $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
}
$sched_stmt->execute();
$scheduled_teachers = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build combined teachers list and data maps
$eval_data = [];
$observer_map = [];
$schedule_data = [];
// For dean/principal: use their own name. For coordinators: look up the dean/principal who supervises them.
if (in_array($_SESSION['role'], ['dean', 'principal'])) {
    $dean_name = $_SESSION['name'] ?? '';
} else {
    $dean_name = '';
    $dean_lookup = $db->prepare("SELECT u.name FROM evaluator_assignments ea JOIN users u ON ea.supervisor_id = u.id WHERE ea.evaluator_id = :eid LIMIT 1");
    $dean_lookup->bindParam(':eid', $_SESSION['user_id']);
    $dean_lookup->execute();
    $dean_name = $dean_lookup->fetchColumn() ?: '';
}
$seen_ids = [];
$teachers_list = [];

// Focus label mapping
$focus_labels = [
    'communications' => 'Communication Competence',
    'management' => 'Management and Presentation of the Lesson',
    'assessment' => "Assessment of Students' Learning"
];

// Use row-based keys: "tid" for evaluation rows, "tid_sched" for upcoming schedule rows
// This allows a teacher to appear twice: once for their completed eval and once for their new schedule

// Process teachers with evaluations
$seen_teacher_ids = []; // track which teacher IDs we've seen from query 1
foreach ($eval_teachers as $t) {
    $tid = $t['id'];
    $row_key = $tid; // default row key
    if (!isset($seen_ids[$row_key])) {
        $seen_ids[$row_key] = true;
        $seen_teacher_ids[$tid] = true;
        $teachers_list[] = array_merge($t, ['_row_key' => $row_key]);
    }

    $obs_date = $t['observation_date'] ?? '';
    $is_done = ($t['eval_status'] === 'completed');
    $faculty_sig = $t['faculty_signature'] ?? '';

    if (!isset($eval_data[$row_key])) {
        $eval_data[$row_key] = ['date' => $obs_date, 'done' => $is_done, 'faculty_signature' => $faculty_sig, 'eval_id' => $t['eval_id'] ?? null];
    }

    // Schedule details from evaluation record
    $focus_raw = $t['eval_focus'] ?? $t['evaluation_focus'] ?? '';
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);

    if (!isset($schedule_data[$row_key])) {
        $schedule_data[$row_key] = [
            'semester' => $t['eval_semester'] ?? $t['evaluation_semester'] ?? '',
            'focus' => implode(', ', $focus_display),
            'day_time' => '',
            'subject_area' => $t['eval_subject_area'] ?? $t['evaluation_subject_area'] ?? '',
            'subject' => $t['subject_observed'] ?? $t['evaluation_subject'] ?? '',
            'room' => $t['eval_room'] ?? $t['evaluation_room'] ?? '',
        ];
        if (!empty($obs_date)) {
            $schedule_data[$row_key]['day_time'] = date('D', strtotime($obs_date));
        }
    }

    // Get observers
    $obs_query = "SELECT DISTINCT u.name FROM evaluations e JOIN users u ON e.evaluator_id = u.id WHERE e.teacher_id = :teacher_id AND e.academic_year = :academic_year AND e.semester = :semester ORDER BY u.name";
    $obs_stmt = $db->prepare($obs_query);
    $obs_stmt->execute([':teacher_id' => $tid, ':academic_year' => $academic_year, ':semester' => $semester]);
    $observers = $obs_stmt->fetchAll(PDO::FETCH_COLUMN);

    $assign_query = "SELECT DISTINCT u.name FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :teacher_id ORDER BY u.name";
    $assign_stmt = $db->prepare($assign_query);
    $assign_stmt->execute([':teacher_id' => $tid]);
    $assigned = $assign_stmt->fetchAll(PDO::FETCH_COLUMN);

    $all_observers = array_unique(array_merge($observers, $assigned));
    if (!empty($dean_name) && !in_array($dean_name, $all_observers)) {
        array_unshift($all_observers, $dean_name);
    }
    $observer_map[$row_key] = $all_observers;
    // Also store observers by teacher ID for reuse in schedule rows
    $observer_map_by_tid[$tid] = $all_observers;
}

// Now check: for teachers from query 1 who ALSO have a NEW schedule (different date from eval),
// add a second row for the upcoming schedule
foreach ($eval_teachers as $t) {
    $tid = $t['id'];
    $sched_dt = $t['evaluation_schedule'] ?? '';
    $obs_date = $t['observation_date'] ?? '';
    if (empty($sched_dt)) continue;

    $sched_date_only = date('Y-m-d', strtotime($sched_dt));
    if ($sched_date_only === $obs_date) continue; // same date, no separate row needed

    $sched_key = $tid . '_sched';
    if (isset($seen_ids[$sched_key])) continue;
    $seen_ids[$sched_key] = true;

    $teachers_list[] = array_merge($t, ['_row_key' => $sched_key]);
    $eval_data[$sched_key] = ['date' => $sched_date_only, 'done' => false, 'faculty_signature' => '', 'eval_id' => null];

    // Use schedule details from the teachers table (the new schedule)
    $focus_raw = $t['evaluation_focus'] ?? '';
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);

    $ts = strtotime($sched_dt);
    $schedule_data[$sched_key] = [
        'semester' => $t['evaluation_semester'] ?? '',
        'focus' => implode(', ', $focus_display),
        'day_time' => date('D', $ts) . "\n" . date('g:ia', $ts),
        'subject_area' => $t['evaluation_subject_area'] ?? '',
        'subject' => $t['evaluation_subject'] ?? '',
        'room' => $t['evaluation_room'] ?? '',
    ];
    $observer_map[$sched_key] = $observer_map_by_tid[$tid] ?? [];
}

// Process scheduled-only teachers (no evaluation yet)
foreach ($scheduled_teachers as $t) {
    $tid = $t['id'];
    if (isset($seen_teacher_ids[$tid])) continue; // already handled above (eval + schedule rows)
    if (isset($seen_ids[$tid])) continue;
    $seen_ids[$tid] = true;
    $teachers_list[] = array_merge($t, ['_row_key' => $tid]);

    $sched_dt = $t['evaluation_schedule'] ?? '';
    $sched_date = !empty($sched_dt) ? date('Y-m-d', strtotime($sched_dt)) : '';
    $eval_data[$tid] = ['date' => $sched_date, 'done' => false, 'faculty_signature' => '', 'eval_id' => null];

    $focus_raw = $t['evaluation_focus'] ?? '';
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);

    $schedule_data[$tid] = [
        'semester' => $t['evaluation_semester'] ?? '',
        'focus' => implode(', ', $focus_display),
        'day_time' => '',
        'subject_area' => $t['evaluation_subject_area'] ?? '',
        'subject' => $t['evaluation_subject'] ?? '',
        'room' => $t['evaluation_room'] ?? '',
    ];
    if (!empty($sched_dt)) {
        $ts = strtotime($sched_dt);
        $schedule_data[$tid]['day_time'] = date('D', $ts) . "\n" . date('g:ia', $ts);
    }

    $assign_query = "SELECT DISTINCT u.name FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :teacher_id ORDER BY u.name";
    $assign_stmt = $db->prepare($assign_query);
    $assign_stmt->execute([':teacher_id' => $tid]);
    $assigned = $assign_stmt->fetchAll(PDO::FETCH_COLUMN);
    $all_observers = $assigned;
    if (!empty($dean_name) && !in_array($dean_name, $all_observers)) {
        array_unshift($all_observers, $dean_name);
    }
    $observer_map[$tid] = $all_observers;
}

// Sort teachers_list by date ascending
usort($teachers_list, function($a, $b) use ($eval_data) {
    $da = $eval_data[$a['_row_key']]['date'] ?? '';
    $db_date = $eval_data[$b['_row_key']]['date'] ?? '';
    return strcmp($da, $db_date);
});

// Filter by month if selected
if (!empty($filter_month)) {
    $teachers_list = array_filter($teachers_list, function($t) use ($eval_data, $filter_month) {
        $rk = $t['_row_key'] ?? $t['id'];
        $date = $eval_data[$rk]['date'] ?? '';
        if (empty($date)) return false;
        return date('n', strtotime($date)) == $filter_month;
    });
    $teachers_list = array_values($teachers_list);
}

// Filter by status (done / undone)
if ($filter_status === 'done') {
    $teachers_list = array_filter($teachers_list, function($t) use ($eval_data) {
        $rk = $t['_row_key'] ?? $t['id'];
        return !empty($eval_data[$rk]['done']);
    });
    $teachers_list = array_values($teachers_list);
} elseif ($filter_status === 'undone') {
    $teachers_list = array_filter($teachers_list, function($t) use ($eval_data) {
        $rk = $t['_row_key'] ?? $t['id'];
        return empty($eval_data[$rk]['done']);
    });
    $teachers_list = array_values($teachers_list);
}

$dean_role_display = ucfirst(str_replace('_', ' ', $_SESSION['role']));

// Get dean's signature from most recent evaluation
$dean_signature = '';
try {
    $sig_query = "SELECT rater_signature FROM evaluations WHERE evaluator_id = :evaluator_id AND rater_signature IS NOT NULL AND rater_signature != '' ORDER BY created_at DESC LIMIT 1";
    $sig_stmt = $db->prepare($sig_query);
    $sig_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $sig_stmt->execute();
    $sig_row = $sig_stmt->fetch(PDO::FETCH_ASSOC);
    if ($sig_row) {
        $dean_signature = $sig_row['rater_signature'];
    }
} catch (Exception $e) {
    // ignore
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classroom Observation Plan - <?php echo htmlspecialchars($raw_department); ?></title>
    <?php include '../includes/header.php'; ?>
    <style>
        .plan-table {
            width: 100%;
            min-width: 620px;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .plan-table th, .plan-table td {
            border: 1px solid #333;
            padding: 8px 10px;
            vertical-align: middle;
        }
        .plan-table th {
            background: #2c3e50;
            color: white;
            font-weight: 600;
            text-align: center;
        }
        .plan-table td {
            font-size: 0.9rem;
        }
        .plan-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        .plan-header {
            text-align: center;
            margin-bottom: 15px;
        }
        .plan-header h4 {
            font-weight: 700;
            margin-bottom: 2px;
        }
        .plan-header p {
            margin: 2px 0;
            font-size: 0.95rem;
        }
        .prepared-by {
            margin-top: 40px;
            font-size: 0.95rem;
        }
        .prepared-by p:first-child {
            margin-bottom: 0;
        }
        .prepared-by .sig-img {
            display: block;
            max-height: 50px;
            max-width: 200px;
            margin-top: 5px;
            margin-bottom: -10px;
        }
        .prepared-by .name-line {
            font-weight: 700;
            text-decoration: underline;
            margin: 0;
        }
        .prepared-by .role-dept {
            margin: 2px 0 0 0;
            font-size: 0.9rem;
        }
        .print-only { display: none; }
        .no-print {}

        @media print {
            @page {
                size: landscape;
                margin: 8mm;
            }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .sidebar, .sidebar-backdrop, .mobile-sidebar-toggle,
            .mobile-sidebar-header, .dashboard-topbar, .dashboard-bg-layer {
                display: none !important;
            }
            .main-content, .container-fluid {
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
            }
            body { background: #fff !important; }
            .plan-table th {
                background: #fff !important;
                color: #000 !important;
                border: 1.5px solid #000 !important;
                print-color-adjust: exact;
            }
            .plan-table td {
                border: 1.5px solid #000 !important;
            }
            .plan-table tr:nth-child(even) {
                background: #fff !important;
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
            <div class="ms-auto d-flex align-items-center gap-3">
                <div class="no-print">
                    <button class="btn btn-primary" onclick="openPrintPlan()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
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

            <!-- Filters (screen only) -->
            <div class="card mb-3 no-print">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Academic Year</label>
                            <select name="academic_year" class="form-select">
                                <option value="2025-2026" <?php echo $academic_year === '2025-2026' ? 'selected' : ''; ?>>2025-2026</option>
                                <option value="2026-2027" <?php echo $academic_year === '2026-2027' ? 'selected' : ''; ?>>2026-2027</option>
                                <option value="2027-2028" <?php echo $academic_year === '2027-2028' ? 'selected' : ''; ?>>2027-2028</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Semester</label>
                            <select name="semester" class="form-select">
                                <option value="1st" <?php echo $semester === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                                <option value="2nd" <?php echo $semester === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Month</label>
                            <select name="month" class="form-select">
                                <option value="">All Months</option>
                                <?php
                                $months = ['1'=>'January','2'=>'February','3'=>'March','4'=>'April','5'=>'May','6'=>'June','7'=>'July','8'=>'August','9'=>'September','10'=>'October','11'=>'November','12'=>'December'];
                                foreach ($months as $num => $name):
                                ?>
                                <option value="<?php echo $num; ?>" <?php echo $filter_month == $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All</option>
                                <option value="done" <?php echo $filter_status === 'done' ? 'selected' : ''; ?>>Done</option>
                                <option value="undone" <?php echo $filter_status === 'undone' ? 'selected' : ''; ?>>Undone</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Printable Observation Plan -->
            <div style="background: white; padding: 30px;">
                
                <!-- Print Header -->
                <div class="print-only" style="padding: 8px 0 10px; border-bottom: 1px solid #000; margin-bottom: 0;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap: 10px;">
                        <div style="width: 170px; text-align:left;">
                            <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC" style="max-width: 80px; height:auto;" />
                        </div>
                        <div style="flex:1; text-align:center; line-height: 1.2;">
                            <div style="font-weight:700; font-size: 13px;">SAINT MICHAEL COLLEGE OF CARAGA</div>
                            <div style="font-size: 11px;">Brgy. 4, Nasipit, Agusan del Norte, Caraga Region</div>
                            <div style="font-size: 11px;">Tel. Nos: (085) 343-2232 / (085) 283-3113</div>
                            <div style="font-size: 11px;">www.smccnasipit.edu.ph</div>
                        </div>
                        <div style="width: 170px; text-align:right;">
                            <div style="display:flex; gap: 8px; justify-content:flex-end; align-items:center;">
                                <img src="../assets/img/socotec.jpg" alt="SOCOTEC ISO 9001" style="max-width: 95px; height:auto;" />
                                <img src="../assets/img/pab_ab.png" alt="PAB AB" style="max-width: 80px; height:auto;" onerror="this.style.display='none'" />
                            </div>
                        </div>
                    </div>
                    <div style="text-align:center; margin-top: 8px;">
                        <strong style="font-size: 12px; text-transform: uppercase;"><?php echo htmlspecialchars($department_display); ?></strong>
                    </div>
                </div>

                <!-- Screen Header -->
                <div class="plan-header">
                    <h4 class="no-print">Classroom Observation Plan</h4>
                    <div class="print-only" style="text-align:center; margin-top: 8px; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: bold;">Classroom Observation Plan</span><br>
                        <span style="font-size: 11px;"><?php echo htmlspecialchars($semester); ?> semester SY <?php echo htmlspecialchars($academic_year); ?></span>
                    </div>
                    <p class="no-print"><strong><?php echo htmlspecialchars($department_display); ?></strong></p>
                    <p class="no-print"><?php echo htmlspecialchars($semester); ?> Semester SY <?php echo htmlspecialchars($academic_year); ?></p>
                </div>

                <!-- Observation Plan Table -->
                <div class="table-responsive">
                    <table class="plan-table">
                        <thead>
                            <tr>
                                <th style="width: 12%;">Teacher</th>
                                <th style="width: 7%;">Semester</th>
                                <th style="width: 13%;">Focus of Observation</th>
                                <th style="width: 9%;">Date</th>
                                <th style="width: 8%;">Day &amp; Time</th>
                                <th style="width: 9%;">Subject Area</th>
                                <th style="width: 10%;">Subject</th>
                                <th style="width: 6%;">Room</th>
                                <th style="width: 13%;">Name of Observers</th>
                                <th style="width: 6%;">Teacher's Signature</th>
                                <th style="width: 7%; min-width: 60px;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($teachers_list) > 0): ?>
                                <?php $counter = 1; foreach ($teachers_list as $t): ?>
                                <?php $rk = $t['_row_key'] ?? $t['id']; $tid = $t['id']; $sd = $schedule_data[$rk] ?? []; $is_done = !empty($eval_data[$rk]['done']); ?>
                                <tr>
                                    <td><?php echo $counter++ . '. ' . htmlspecialchars($t['name']); ?></td>
                                    <td class="text-center"><?php $sem = $sd['semester'] ?? ''; echo htmlspecialchars($sem ? $sem . ' Semester' : ''); ?></td>
                                    <td style="font-size:0.8rem;"><?php echo htmlspecialchars($sd['focus'] ?? ''); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $date = $eval_data[$rk]['date'] ?? '';
                                        if (!empty($date)) {
                                            echo htmlspecialchars(date('m-d-y', strtotime($date)));
                                        }
                                        ?>
                                    </td>
                                    <td class="text-center" style="white-space:pre-line; font-size:0.8rem;">
                                        <?php echo htmlspecialchars($sd['day_time'] ?? ''); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $subj_area_val = $sd['subject_area'] ?? '';
                                        $eval_id = $eval_data[$rk]['eval_id'] ?? null;
                                        if (!empty($subj_area_val)): ?>
                                            <span><?php echo htmlspecialchars($subj_area_val); ?></span>
                                        <?php elseif ($eval_id): ?>
                                            <input type="text" class="form-control form-control-sm inline-edit no-print" data-eval-id="<?php echo (int)$eval_id; ?>" data-field="subject_area" placeholder="Enter subject area" style="min-width:90px;font-size:0.8rem;">
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($sd['subject'] ?? ''); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $room_val = $sd['room'] ?? '';
                                        if (!empty($room_val)): ?>
                                            <span><?php echo htmlspecialchars($room_val); ?></span>
                                        <?php elseif ($eval_id): ?>
                                            <input type="text" class="form-control form-control-sm inline-edit no-print" data-eval-id="<?php echo (int)$eval_id; ?>" data-field="observation_room" placeholder="Room" style="min-width:60px;font-size:0.8rem;">
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.8rem;">
                                        <?php 
                                        $observers = $observer_map[$rk] ?? [];
                                        echo htmlspecialchars(implode(', ', $observers));
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $faculty_sig = $eval_data[$rk]['faculty_signature'] ?? '';
                                        if (!empty($faculty_sig)): ?>
                                            <img src="<?php echo $faculty_sig; ?>" alt="Teacher Signature" style="max-height: 40px; max-width: 80px;">
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" style="font-size:0.8rem;">
                                        <?php if ($is_done): ?>
                                            <span class="badge bg-success" style="font-size:0.75rem;">Done</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark" style="font-size:0.75rem;">Undone</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No active teachers found in this department.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Prepared By (print only) -->
                <div class="prepared-by print-only">
                    <p><em>Prepared by:</em></p>
                    <?php if (!empty($dean_signature)): ?>
                    <img class="sig-img" src="<?php echo $dean_signature; ?>" alt="Signature">
                    <?php endif; ?>
                    <p class="name-line"><?php echo htmlspecialchars(strtoupper($dean_name)); ?></p>
                    <p class="role-dept"><?php echo htmlspecialchars($dean_role_display); ?>, <?php echo htmlspecialchars($raw_department); ?></p>
                </div>
            </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
<script>
function openPrintPlan() {
    const params = new URLSearchParams(window.location.search);
    params.set('auto_print', '1');
    window.open('observation_plan_print.php?' + params.toString(), '_blank');
}

// Inline edit: save on blur or Enter
document.querySelectorAll('.inline-edit').forEach(input => {
    const save = () => {
        const evalId = input.dataset.evalId;
        const field = input.dataset.field;
        const value = input.value.trim();
        if (!value) return;
        fetch('observation_plan.php?ajax_update=1', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `eval_id=${encodeURIComponent(evalId)}&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}`
        }).then(r => r.json()).then(data => {
            if (data.success) {
                const span = document.createElement('span');
                span.textContent = value;
                input.replaceWith(span);
            }
        }).catch(() => {});
    };
    input.addEventListener('blur', save);
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); save(); } });
});
</script>
</body>
</html>
