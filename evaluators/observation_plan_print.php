<?php
/**
 * Standalone print page for Classroom Observation Plan (evaluators).
 * Opened in a new tab from observation_plan.php.
 */
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

$semester = $_GET['semester'] ?? '1st';
$academic_year = $_GET['academic_year'] ?? '';
$filter_month = $_GET['month'] ?? '';
$filter_status = $_GET['status'] ?? '';

if (empty($academic_year)) {
    $month = (int)date('n');
    $year = (int)date('Y');
    if ($month >= 6) {
        $academic_year = $year . '-' . ($year + 1);
    } else {
        $academic_year = ($year - 1) . '-' . $year;
    }
}

if ($is_coordinator) {
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
              WHERE (t.department = :department OR ta.evaluator_id IS NOT NULL OR e.evaluator_id = :evaluator_id)
              AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              AND e.academic_year = :academic_year
              AND e.semester = :semester
              ORDER BY t.name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':assigned_evaluator_id', $_SESSION['user_id']);
    $stmt->bindParam(':department', $raw_department);
    $stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->bindParam(':academic_year', $academic_year);
    $stmt->bindParam(':semester', $semester);
} else {
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

// Scheduled-only teachers (also picks up re-scheduled teachers who already have evaluations)
if ($is_coordinator) {
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester
                    FROM teachers t
                    LEFT JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :assigned_evaluator_id
                    WHERE (t.department = :department OR ta.evaluator_id IS NOT NULL)
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

$eval_data = [];
$observer_map = [];
$observer_map_by_tid = [];
$schedule_data = [];
$dean_name = $_SESSION['name'] ?? '';
$seen_ids = [];
$seen_teacher_ids = [];
$teachers_list = [];

$focus_labels = [
    'communications' => 'Communication Competence',
    'management' => 'Management and Presentation of the Lesson',
    'assessment' => "Assessment of Students' Learning"
];

// Process teachers with evaluations (each row is keyed by teacher ID)
foreach ($eval_teachers as $t) {
    $tid = $t['id'];
    $row_key = (string)$tid;
    if (!isset($seen_ids[$row_key])) {
        $seen_ids[$row_key] = true;
        $seen_teacher_ids[$tid] = true;
        $teachers_list[] = array_merge($t, ['_row_key' => $row_key]);
    }
    $obs_date = $t['observation_date'] ?? '';
    $is_done = ($t['eval_status'] === 'completed');
    $faculty_sig = $t['faculty_signature'] ?? '';
    $eval_id = $t['eval_id'] ?? null;

    if (!isset($eval_data[$row_key])) {
        $eval_data[$row_key] = [
            'date' => $obs_date,
            'done' => $is_done,
            'faculty_signature' => $faculty_sig,
            'eval_id' => $eval_id
        ];
    }

    // Use eval-level data for the evaluation row
    $focus_raw = $t['eval_focus'] ?? $t['evaluation_focus'] ?? '';
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);
    $day_time = '';
    if (!empty($obs_date)) { $day_time = date('D', strtotime($obs_date)); }
    if (!isset($schedule_data[$row_key])) {
        $schedule_data[$row_key] = [
            'semester' => $t['eval_semester'] ?? $t['evaluation_semester'] ?? '',
            'focus' => implode(', ', $focus_display),
            'day_time' => $day_time,
            'subject_area' => $t['eval_subject_area'] ?? $t['evaluation_subject_area'] ?? '',
            'subject' => $t['subject_observed'] ?? $t['evaluation_subject'] ?? '',
            'room' => $t['eval_room'] ?? $t['evaluation_room'] ?? '',
        ];
    }

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
    $observer_map_by_tid[$tid] = $all_observers;
}

// For teachers with evaluations who ALSO have a NEW schedule (different date), add a second row
foreach ($eval_teachers as $t) {
    $tid = $t['id'];
    $sched_dt = $t['evaluation_schedule'] ?? '';
    $obs_date = $t['observation_date'] ?? '';
    if (empty($sched_dt)) continue;

    $sched_date_only = date('Y-m-d', strtotime($sched_dt));
    if ($sched_date_only === $obs_date) continue;

    $sched_key = $tid . '_sched';
    if (isset($seen_ids[$sched_key])) continue;
    $seen_ids[$sched_key] = true;

    $teachers_list[] = array_merge($t, ['_row_key' => $sched_key]);
    $eval_data[$sched_key] = ['date' => $sched_date_only, 'done' => false, 'faculty_signature' => '', 'eval_id' => null];

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
    if (isset($seen_teacher_ids[$tid])) continue;
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
    $day_time = '';
    if (!empty($sched_dt)) { $ts = strtotime($sched_dt); $day_time = date('D', $ts) . "\n" . date('g:ia', $ts); }
    $schedule_data[$tid] = [
        'semester' => $t['evaluation_semester'] ?? '',
        'focus' => implode(', ', $focus_display),
        'day_time' => $day_time,
        'subject_area' => $t['evaluation_subject_area'] ?? '',
        'subject' => $t['evaluation_subject'] ?? '',
        'room' => $t['evaluation_room'] ?? '',
    ];

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

// Sort by date ascending
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
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Classroom Observation Plan - <?php echo htmlspecialchars($raw_department); ?></title>
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            color: #000;
            background: #f0f0f0;
            margin: 0;
            padding: 20px;
        }
        .print-page {
            width: 190mm;
            max-width: 190mm;
            margin: 0 auto;
            background: #fff;
            padding: 8mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.15);
        }
        @media print {
            body { background: #fff; padding: 0; }
            .print-page { width: 100%; max-width: 100%; box-shadow: none; padding: 0; margin: 0; }
        }
        .print-header {
            padding: 8px 0 10px;
            border-bottom: 1px solid #000;
            margin-bottom: 0;
        }
        .print-header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .print-header-left { width: 170px; text-align: left; }
        .print-header-center { flex: 1; text-align: center; line-height: 1.2; }
        .print-header-right { width: 170px; text-align: right; }
        .print-header-right-inner {
            display: flex; gap: 8px; justify-content: flex-end; align-items: center;
        }
        .plan-title {
            text-align: center;
            margin-top: 10px;
            margin-bottom: 10px;
        }
        .plan-title .dept { font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .plan-title .title { font-size: 12px; font-weight: 700; }
        .plan-title .sem { font-size: 11px; }
        .plan-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .plan-table th {
            background: #fff;
            color: #000;
            font-weight: 700;
            font-size: 10px;
            padding: 5px 6px;
            border: 1px solid #000;
            text-align: center;
        }
        .plan-table td {
            font-size: 10px;
            line-height: 1.3;
            padding: 5px 6px;
            border: 1px solid #000;
            vertical-align: middle;
        }
        .text-center { text-align: center; }
        .prepared-by {
            margin-top: 30px;
            font-size: 10px;
        }
        .prepared-by .label { margin-bottom: 0; font-style: italic; }
        .sig-img {
            display: block;
            max-height: 40px;
            max-width: 160px;
            margin-top: 4px;
            margin-bottom: -8px;
        }
        .name-line {
            font-weight: 700;
            text-decoration: underline;
            font-size: 10px;
            margin: 0;
        }
        .role-dept {
            font-size: 9px;
            margin: 2px 0 0 0;
        }
        .no-data {
            text-align: center;
            padding: 15px;
            font-size: 11px;
            color: #666;
        }
        .print-btn-bar {
            text-align: center;
            padding: 12px 0;
            margin-bottom: 8px;
        }
        .print-btn-bar button {
            padding: 8px 24px;
            font-size: 14px;
            cursor: pointer;
            background: #2c3e50;
            color: #fff;
            border: none;
            border-radius: 4px;
        }
        @media print {
            .print-btn-bar { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="print-btn-bar">
        <button onclick="window.print()">🖨️ Print Observation Plan</button>
    </div>

    <div class="print-page">
        <!-- Header -->
        <div class="print-header">
            <div class="print-header-inner">
                <div class="print-header-left">
                    <img src="../assets/img/SMCC_LOGO.webp" alt="SMCC" style="max-width: 80px; height:auto;" />
                </div>
                <div class="print-header-center">
                    <div style="font-weight:700; font-size: 13px;">SAINT MICHAEL COLLEGE OF CARAGA</div>
                    <div style="font-size: 11px;">Brgy. 4, Nasipit, Agusan del Norte, Caraga Region</div>
                    <div style="font-size: 11px;">Tel. Nos: (085) 343-2232 / (085) 283-3113</div>
                    <div style="font-size: 11px;">www.smccnasipit.edu.ph</div>
                </div>
                <div class="print-header-right">
                    <div class="print-header-right-inner">
                        <img src="../assets/img/socotec.jpg" alt="SOCOTEC ISO 9001" style="max-width: 95px; height:auto;" />
                        <img src="../assets/img/pab_ab.png" alt="PAB AB" style="max-width: 80px; height:auto;" onerror="this.style.display='none'" />
                    </div>
                </div>
            </div>
            <div style="text-align:center; margin-top: 8px;">
                <strong style="font-size: 12px; text-transform: uppercase;"><?php echo htmlspecialchars($department_display); ?></strong>
            </div>
        </div>

        <!-- Title -->
        <div class="plan-title">
            <div class="title">Classroom Observation Plan</div>
            <div class="sem"><?php echo htmlspecialchars($semester); ?> Semester SY <?php echo htmlspecialchars($academic_year); ?></div>
        </div>

        <!-- Table -->
        <table class="plan-table">
            <thead>
                <tr>
                    <th style="width: 11%;">Teacher</th>
                    <th style="width: 6%;">Semester</th>
                    <th style="width: 12%;">Focus of Observation</th>
                    <th style="width: 8%;">Date</th>
                    <th style="width: 8%;">Day &amp; Time</th>
                    <th style="width: 10%;">Subject Area</th>
                    <th style="width: 11%;">Subject</th>
                    <th style="width: 7%;">Room</th>
                    <th style="width: 13%;">Name of Observers</th>
                    <th style="width: 7%;">Teacher's Signature</th>
                    <th style="width: 7%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($teachers_list) > 0): ?>
                    <?php $counter = 1; foreach ($teachers_list as $t): ?>
                    <?php $rk = $t['_row_key'] ?? $t['id']; $tid = $t['id']; $sd = $schedule_data[$rk] ?? []; $is_done = !empty($eval_data[$rk]['done']); ?>
                    <tr>
                        <td><?php echo $counter++ . '. ' . htmlspecialchars($t['name']); ?></td>
                        <td class="text-center"><?php $sem = $sd['semester'] ?? ''; echo htmlspecialchars($sem ? $sem . ' Semester' : ''); ?></td>
                        <td style="font-size:9px;"><?php echo htmlspecialchars($sd['focus'] ?? ''); ?></td>
                        <td class="text-center">
                            <?php 
                            $date = $eval_data[$rk]['date'] ?? '';
                            if (!empty($date)) {
                                echo htmlspecialchars(date('m-d-y', strtotime($date)));
                            }
                            ?>
                        </td>
                        <td class="text-center" style="white-space:pre-line;">
                            <?php echo htmlspecialchars($sd['day_time'] ?? ''); ?>
                        </td>
                        <td><?php echo htmlspecialchars($sd['subject_area'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($sd['subject'] ?? ''); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($sd['room'] ?? ''); ?></td>
                        <td style="font-size:9px;"><?php echo htmlspecialchars(implode(', ', $observer_map[$rk] ?? [])); ?></td>
                        <td class="text-center">
                            <?php 
                            $faculty_sig = $eval_data[$rk]['faculty_signature'] ?? '';
                            if (!empty($faculty_sig)): ?>
                                <img src="<?php echo htmlspecialchars($faculty_sig); ?>" alt="Teacher Signature" style="max-height: 35px; max-width: 80px;">
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php echo $is_done ? '<em>done</em>' : '<em>undone</em>'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="11" class="no-data">No teachers found for the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Prepared By -->
        <div class="prepared-by">
            <p class="label">Prepared by:</p>
            <?php if (!empty($dean_signature)): ?>
            <img class="sig-img" src="<?php echo htmlspecialchars($dean_signature); ?>" alt="Signature">
            <?php endif; ?>
            <p class="name-line"><?php echo htmlspecialchars(strtoupper($dean_name)); ?></p>
            <p class="role-dept"><?php echo htmlspecialchars($dean_role_display); ?>, <?php echo htmlspecialchars($raw_department); ?></p>
        </div>
    </div>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto_print') === '1') {
            window.onload = function() { window.print(); };
        }
    </script>
</body>
</html>
