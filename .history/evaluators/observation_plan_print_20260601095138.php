<?php
/**
 * Standalone print page for Classroom Observation Plan (evaluators).
 * Opened in a new tab from observation_plan.php.
 */
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../includes/program_assignments.php';

function normalizeSubjectDisplay($subject) {
    $s = trim((string)$subject);
    if ($s === '') return '';
    $s = preg_replace('/\s+\d{1,2}:\d{2}\s*(AM|PM)(?:\s*-\s*(?:\d{1,2}:\d{2}\s*(AM|PM))?)?\s*$/i', '', $s);
    return trim((string)$s);
}

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);

$department_map = [
    'CCIS'  => 'College of Computing and Information Sciences',
    'CBM'   => 'College of Business and Management',
    'CAS'   => 'College of Arts and Sciences',
    'CCJE'  => 'College of Criminal Justice Education',
    'CTHM'  => 'College of Tourism and Hospitality Management',
    'CTEAS' => 'College of Teacher Education, Arts and Sciences',
    'ELEM'  => 'Elementary Department',
    'JHS'   => 'Junior High School Department',
    'SHS'   => 'Senior High School Department',
];

$is_leader = in_array($_SESSION['role'], ['president', 'vice_president']);
$is_coordinator = in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator']);
$all_departments = array_keys($department_map);
$session_department = trim((string)($_SESSION['department'] ?? ''));
$requested_department = trim((string)($_GET['department'] ?? ''));
$available_filter_departments = [];
if ($is_leader) {
    $available_filter_departments = $all_departments;
    $raw_department = in_array($requested_department, $available_filter_departments, true) ? $requested_department : '';
} elseif ($is_coordinator) {
    $programs = resolveEvaluatorPrograms($db, $_SESSION['user_id'], $session_department);
    foreach ($programs as $p) {
        $p = trim((string)$p);
        if (in_array($p, $all_departments, true) && !in_array($p, $available_filter_departments, true)) {
            $available_filter_departments[] = $p;
        }
    }
    if (empty($available_filter_departments) && $session_department !== '' && in_array($session_department, $all_departments, true)) {
        $available_filter_departments[] = $session_department;
    }
    // Hard guard: block manual URL edits to departments outside assigned programs.
    $raw_department = guardCoordinatorDepartment($requested_department, $available_filter_departments, $session_department);
} else {
    $available_filter_departments = $session_department !== '' ? [$session_department] : [];
    $raw_department = $session_department;
}
$department_display = $department_map[$raw_department] ?? ($raw_department ?: 'All Departments');

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

if ($is_leader) {
    // Leaders see ALL evaluated teachers across all departments (or filtered by GET department)
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     e.id as eval_id, e.observation_date, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester, e.department as eval_department,
                     t.scheduled_by, t.scheduled_department
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              LEFT JOIN users eu ON eu.id = e.evaluator_id
              WHERE e.academic_year = :academic_year
              AND e.semester = :semester";
    if ($raw_department !== '') {
        $query .= " AND (
                        (
                            t.scheduled_department IS NOT NULL
                            AND t.scheduled_department <> ''
                            AND t.scheduled_department = :department_sched
                        )
                        OR
                        (
                            (t.scheduled_department IS NULL OR t.scheduled_department = '')
                            AND eu.department = :department_primary
                        )
                      )";
    }
    $query .= " ORDER BY t.name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':academic_year', $academic_year);
    $stmt->bindParam(':semester', $semester);
    if ($raw_department !== '') {
        $stmt->bindParam(':department_sched', $raw_department);
        $stmt->bindParam(':department_primary', $raw_department);
    }
} elseif ($is_coordinator) {
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     e.id as eval_id, e.observation_date, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester, e.department as eval_department,
                     t.scheduled_by, t.scheduled_department
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              LEFT JOIN teacher_departments td ON td.teacher_id = t.id
              LEFT JOIN users tu ON tu.id = t.user_id
              LEFT JOIN users eu ON eu.id = e.evaluator_id
              WHERE (
                    e.department = :department_match1
                    OR (
                        (e.department IS NULL OR e.department = '')
                        AND t.scheduled_department = :department_match2
                    )
                    OR (
                        (e.department IS NULL OR e.department = '')
                        AND (t.scheduled_department IS NULL OR t.scheduled_department = '')
                        AND eu.department = :department_match3
                    )
              )
              AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              AND (tu.id IS NULL OR tu.role NOT IN ('dean','principal','president','vice_president'))
              AND e.academic_year = :academic_year
              AND e.semester = :semester
              ORDER BY t.name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department_match1', $raw_department);
    $stmt->bindParam(':department_match2', $raw_department);
    $stmt->bindParam(':department_match3', $raw_department);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->bindParam(':academic_year', $academic_year);
    $stmt->bindParam(':semester', $semester);
} else {
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     e.id as eval_id, e.observation_date, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester, e.department as eval_department,
                     t.scheduled_by, t.scheduled_department
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              LEFT JOIN teacher_departments td ON td.teacher_id = t.id
              WHERE (
                    (
                        t.scheduled_department IS NOT NULL
                        AND t.scheduled_department <> ''
                        AND t.scheduled_department = :department_sched
                    )
                    OR
                    (
                        t.department = :department_primary
                        AND e.evaluator_id = :self_eval_id
                    )
                    OR
                    (
                        e.evaluator_id = :self_eval_id2
                        AND e.department = :self_eval_dept
                    )
              )
              AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              AND e.academic_year = :academic_year
              AND e.semester = :semester
              ORDER BY t.name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department_sched', $raw_department);
    $stmt->bindParam(':department_primary', $raw_department);
    $stmt->bindParam(':self_eval_id', $_SESSION['user_id']);
    $stmt->bindParam(':self_eval_id2', $_SESSION['user_id']);
    $stmt->bindParam(':self_eval_dept', $raw_department);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->bindParam(':academic_year', $academic_year);
    $stmt->bindParam(':semester', $semester);
}
$stmt->execute();
$eval_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Scheduled-only teachers (also picks up re-scheduled teachers who already have evaluations)
if ($is_leader) {
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                           t.scheduled_by, t.scheduled_department
                    FROM teachers t
                    WHERE t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND t.evaluation_schedule != ''
                      AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')";
    if ($raw_department !== '') {
        $sched_query .= " AND (
                            (
                                t.scheduled_department IS NOT NULL
                                AND t.scheduled_department <> ''
                                AND t.scheduled_department = :department_sched
                            )
                            OR
                            (
                                (t.scheduled_department IS NULL OR t.scheduled_department = '')
                                AND t.department = :department_primary
                            )
                          )";
    }
    $sched_query .= " ORDER BY t.name ASC";
    $sched_stmt = $db->prepare($sched_query);
    $sched_stmt->bindParam(':filter_semester', $semester);
    if ($raw_department !== '') {
        $sched_stmt->bindParam(':department_sched', $raw_department);
        $sched_stmt->bindParam(':department_primary', $raw_department);
    }
} elseif ($is_coordinator) {
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                           t.scheduled_by, t.scheduled_department
                    FROM teachers t
                    LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                    LEFT JOIN users tu ON tu.id = t.user_id
                    WHERE
                      (
                            (
                                t.scheduled_department IS NOT NULL
                                AND t.scheduled_department <> ''
                                AND t.scheduled_department = :department_match_sched
                            )
                            OR
                            (
                                (t.scheduled_department IS NULL OR t.scheduled_department = '')
                                AND (t.department = :department_match_primary OR td.department = :department_match_secondary)
                            )
                      )
                      AND t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND t.evaluation_schedule != ''
                      AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                      AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                      AND (tu.id IS NULL OR tu.role NOT IN ('dean','principal','president','vice_president'))
                    ORDER BY t.name ASC";
    $sched_stmt = $db->prepare($sched_query);
    $sched_stmt->bindParam(':department_match_sched', $raw_department);
    $sched_stmt->bindParam(':department_match_primary', $raw_department);
    $sched_stmt->bindParam(':department_match_secondary', $raw_department);
    $sched_stmt->bindParam(':filter_semester', $semester);
    $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
} else {
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                           t.scheduled_by, t.scheduled_department
                    FROM teachers t
                    LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                    WHERE (
                        (
                            t.scheduled_department IS NOT NULL
                            AND t.scheduled_department <> ''
                            AND t.scheduled_department = :department_sched
                        )
                        OR
                        (
                            (t.scheduled_department IS NULL OR t.scheduled_department = '')
                            AND (t.department = :department_primary OR td.department = :department_secondary)
                        )
                    )
                      AND t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND t.evaluation_schedule != ''
                      AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                      AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                    ORDER BY t.name ASC";
    $sched_stmt = $db->prepare($sched_query);
    $sched_stmt->bindParam(':department_sched', $raw_department);
    $sched_stmt->bindParam(':department_primary', $raw_department);
    $sched_stmt->bindParam(':department_secondary', $raw_department);
    $sched_stmt->bindParam(':filter_semester', $semester);
    $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
}
$sched_stmt->execute();
$scheduled_teachers = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);

$eval_data = [];
$observer_map = [];
$observer_map_by_tid = [];
$enforce_observer_for_teacher = function(array $observer_list, string $teacher_name): array {
    // No forced observer injection.
    return $observer_list;
};
$get_required_observers = function(int $teacher_id, int $eval_id, string $dept, int $scheduled_by_id, string $teacher_name) use ($db): array {
    $required = [];
    $dept = trim((string)$dept);
    $coordinator_id = $scheduled_by_id;

    // Backward-compat fallback for older rows where scheduled_by was not saved.
    if ($coordinator_id <= 0 && $eval_id > 0) {
        try {
            $fb_stmt = $db->prepare("SELECT evaluator_id FROM evaluations WHERE id = :eval_id LIMIT 1");
            $fb_stmt->execute([':eval_id' => $eval_id]);
            $coordinator_id = (int)($fb_stmt->fetchColumn() ?: 0);
        } catch (Exception $e) {}
    }

    if ($dept !== '') {
        try {
            $dean_stmt = $db->prepare("SELECT DISTINCT name FROM users WHERE department = :department AND role IN ('dean','principal') AND status = 'active' ORDER BY name");
            $dean_stmt->execute([':department' => $dept]);
            while ($dn = $dean_stmt->fetchColumn()) {
                $dn = trim((string)$dn);
                if ($dn !== '' && !in_array($dn, $required, true)) {
                    $required[] = $dn;
                }
            }
        } catch (Exception $e) {}
    }

    if ($coordinator_id > 0 && $dept !== '') {
        try {
            $coord_stmt = $db->prepare(
                "SELECT name
                 FROM users
                 WHERE id = :id
                   AND department = :department
                   AND status = 'active'
                   AND role IN ('chairperson','subject_coordinator','grade_level_coordinator')
                 LIMIT 1"
            );
            $coord_stmt->execute([':id' => $coordinator_id, ':department' => $dept]);
            $coord_name = trim((string)$coord_stmt->fetchColumn());
            if ($coord_name !== '' && !in_array($coord_name, $required, true)) {
                $required[] = $coord_name;
            }
        } catch (Exception $e) {}
    }

    // Include teacher's assigned coordinator(s) in this department.
    if ($teacher_id > 0 && $dept !== '') {
        try {
            $assigned_coord_stmt = $db->prepare(
                "SELECT DISTINCT u.name
                 FROM teacher_assignments ta
                 JOIN users u ON u.id = ta.evaluator_id
                 WHERE ta.teacher_id = :teacher_id
                   AND u.department = :department
                   AND u.status = 'active'
                   AND u.role IN ('chairperson','subject_coordinator','grade_level_coordinator')
                 ORDER BY u.name"
            );
            $assigned_coord_stmt->execute([
                ':teacher_id' => $teacher_id,
                ':department' => $dept
            ]);
            while ($cn = $assigned_coord_stmt->fetchColumn()) {
                $cn = trim((string)$cn);
                if ($cn !== '' && !in_array($cn, $required, true)) {
                    $required[] = $cn;
                }
            }
        } catch (Exception $e) {}
    }

    // Include President/VP only when they explicitly accepted as observer.
    if ($teacher_id > 0) {
        try {
            $pvp_stmt = $db->prepare(
                "SELECT DISTINCT u.name
                 FROM teacher_assignments ta
                 JOIN users u ON u.id = ta.evaluator_id
                 WHERE ta.teacher_id = :teacher_id
                   AND (
                        (:eval_id > 0 AND (ta.eval_id = :eval_id OR ta.eval_id IS NULL))
                        OR (:eval_id = 0 AND ta.eval_id IS NULL)
                   )
                   AND u.status = 'active'
                   AND u.role IN ('president','vice_president')
                 ORDER BY u.name"
            );
            $pvp_stmt->execute([
                ':teacher_id' => $teacher_id,
                ':eval_id' => $eval_id
            ]);
            while ($pn = $pvp_stmt->fetchColumn()) {
                $pn = trim((string)$pn);
                if ($pn !== '' && !in_array($pn, $required, true)) {
                    $required[] = $pn;
                }
            }
        } catch (Exception $e) {}
    }

    return array_values(array_filter($required, function($n) use ($teacher_name) {
        $name = trim((string)$n);
        return $name !== '' && strcasecmp($name, trim((string)$teacher_name)) !== 0;
    }));
};
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

// Process teachers with evaluations (key each row by evaluation ID so
// multiple schedules for the same teacher remain independent).
foreach ($eval_teachers as $t) {
    $tid = $t['id'];
    $eval_id = (int)($t['eval_id'] ?? 0);
    $row_key = $eval_id > 0 ? ('eval_' . $eval_id) : ('teacher_' . $tid . '_' . uniqid());
    $seen_ids[$row_key] = true;
    $seen_teacher_ids[$tid] = true;
    $teachers_list[] = array_merge($t, ['_row_key' => $row_key]);
    $obs_date = $t['observation_date'] ?? '';
    $is_done = ($t['eval_status'] === 'completed');
    $faculty_sig = $t['faculty_signature'] ?? '';
    $eval_id_ref = $t['eval_id'] ?? null;
    $eval_data[$row_key] = [
        'date' => $obs_date,
        'done' => $is_done,
        'faculty_signature' => $faculty_sig,
        'eval_id' => $eval_id_ref,
        'status' => strtolower(trim((string)($t['eval_status'] ?? '')))
    ];

    // Use eval-level data for the evaluation row
    $focus_raw = $t['eval_focus'] ?? $t['evaluation_focus'] ?? '';
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);
    $day_time = '';
    $sched_dt = $t['evaluation_schedule'] ?? '';
    $sched_dt_end = $t['evaluation_schedule_end'] ?? '';
    if (!empty($obs_date)) {
        $day_time = date('l', strtotime($obs_date));
        $obs_time_fmt = trim((string)($t['observation_time'] ?? ''));
        if (($obs_time_fmt === '' || $obs_time_fmt === '00:00:00' || $obs_time_fmt === '00:00') && !empty($sched_dt)) {
            // Permanent fallback: use teacher schedule start when eval row time is blank.
            $obs_time_fmt = date('H:i:s', strtotime($sched_dt));
        }
        if ($obs_time_fmt !== '' && $obs_time_fmt !== '00:00:00' && $obs_time_fmt !== '00:00') {
            $start_fmt = date('g:i A', strtotime($obs_time_fmt));
            $end_fmt = '';
            // Use teacher-level end time only when this eval row matches the
            // currently active teacher schedule; avoids applying latest end time
            // to older evaluation rows in print.
            $obs_dt_key = date('Y-m-d H:i', strtotime($obs_date . ' ' . $obs_time_fmt));
            $sched_dt_key = !empty($sched_dt) ? date('Y-m-d H:i', strtotime($sched_dt)) : '';
            if (!empty($sched_dt_end) && $sched_dt_key !== '' && $sched_dt_key === $obs_dt_key) {
                $end_fmt = date('g:i A', strtotime($sched_dt_end));
            } elseif (!empty($sched_dt_end) && !empty($sched_dt)) {
                // Fallback: if the schedule date matches the observation date,
                // use schedule end time so print can still display a time range.
                $obs_date_key = date('Y-m-d', strtotime($obs_date));
                $sched_date_key = date('Y-m-d', strtotime($sched_dt));
                if ($obs_date_key === $sched_date_key) {
                    $end_fmt = date('g:i A', strtotime($sched_dt_end));
                }
            }
            $day_time .= "\n" . $start_fmt . ($end_fmt !== '' ? (' - ' . $end_fmt) : '');
        }
    } elseif (!empty($sched_dt)) {
        $ts = strtotime($sched_dt);
        $day_str = date('l', $ts);
        $start_time = date('g:i A', $ts);
        if (!empty($sched_dt_end)) {
            $ts_end = strtotime($sched_dt_end);
            $end_time = date('g:i A', $ts_end);
            $day_time = $day_str . "\n" . $start_time . ' - ' . $end_time;
        } else {
            $day_time = $day_str . "\n" . $start_time;
        }
    }
    $schedule_data[$row_key] = [
        'semester' => $t['eval_semester'] ?? $t['evaluation_semester'] ?? '',
        'focus' => implode(', ', $focus_display),
        'day_time' => $day_time,
        'subject_area' => $t['eval_subject_area'] ?? $t['evaluation_subject_area'] ?? '',
        'subject' => normalizeSubjectDisplay($t['subject_observed'] ?? $t['evaluation_subject'] ?? ''),
        'room' => $t['eval_room'] ?? $t['evaluation_room'] ?? '',
    ];

    $teacher_primary_dept = $t['teacher_department'] ?? '';
    $owning_dept = trim((string)($t['eval_department'] ?? ''));
    if ($owning_dept === '') {
        $owning_dept = $t['scheduled_department'] ?? '';
    }
    if ($owning_dept === '') {
        $owning_dept = ($teacher_primary_dept !== '') ? $teacher_primary_dept : $raw_department;
    }

    $teacher_name = $t['name'] ?? '';
    $scheduled_by_id = (int)($t['scheduled_by'] ?? 0);
    $all_observers = $get_required_observers((int)$tid, (int)$eval_id, (string)$owning_dept, (int)$scheduled_by_id, (string)$teacher_name);
    $all_observers = $enforce_observer_for_teacher($all_observers, (string) ($t['name'] ?? ''));
    $observer_map[$row_key] = $all_observers;
    $observer_map_by_tid[$tid] = $all_observers;
}

// For teachers with evaluations who ALSO have a NEW schedule (different datetime), add a second row
foreach ($eval_teachers as $t) {
    $tid = $t['id'];
    $sched_dt = $t['evaluation_schedule'] ?? '';
    $obs_date = $t['observation_date'] ?? '';
    if (empty($sched_dt)) continue;

    $sched_dt_key = date('Y-m-d H:i', strtotime($sched_dt));
    $obs_dt_key = !empty($obs_date) ? date('Y-m-d H:i', strtotime($obs_date)) : '';
    if ($sched_dt_key === $obs_dt_key) continue;

    $sched_key = $tid . '_sched';
    if (isset($seen_ids[$sched_key])) continue;
    $seen_ids[$sched_key] = true;

    $teachers_list[] = array_merge($t, ['_row_key' => $sched_key]);
    $eval_data[$sched_key] = ['date' => $sched_dt_key, 'done' => false, 'faculty_signature' => '', 'eval_id' => null, 'status' => 'scheduled'];

    $focus_raw = $t['evaluation_focus'] ?? '';
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);

    $ts = strtotime($sched_dt);
    $sched_dt_end = $t['evaluation_schedule_end'] ?? '';
    $sched_day_time = date('l', $ts) . "\n" . date('g:i A', $ts);
    if (!empty($sched_dt_end)) {
        $sched_day_time .= ' - ' . date('g:i A', strtotime($sched_dt_end));
    }
    $schedule_data[$sched_key] = [
        'semester' => $t['evaluation_semester'] ?? '',
        'focus' => implode(', ', $focus_display),
        'day_time' => $sched_day_time,
        'subject_area' => $t['evaluation_subject_area'] ?? '',
        'subject' => normalizeSubjectDisplay($t['evaluation_subject'] ?? ''),
        'room' => $t['evaluation_room'] ?? '',
    ];
    $active_eval_id_for_schedule = 0;
    try {
        $activeEvalStmt = $db->prepare(
            "SELECT id
             FROM evaluations
             WHERE teacher_id = :tid
               AND academic_year = :ay
               AND semester = :sem
               AND status <> 'completed'
               AND DATE_FORMAT(CONCAT(observation_date, ' ', COALESCE(observation_time, '00:00:00')), '%Y-%m-%d %H:%i') = DATE_FORMAT(:sched_dt, '%Y-%m-%d %H:%i')
             ORDER BY id DESC
             LIMIT 1"
        );
        $activeEvalStmt->execute([':tid' => $tid, ':ay' => $academic_year, ':sem' => $semester, ':sched_dt' => $sched_dt]);
        $active_eval_id_for_schedule = (int)($activeEvalStmt->fetchColumn() ?: 0);
    } catch (Exception $e) {}
    $owning_dept_sched = trim((string)($t['scheduled_department'] ?? ''));
    if ($owning_dept_sched === '') $owning_dept_sched = trim((string)($t['teacher_department'] ?? ''));
    $scheduled_by_id = (int)($t['scheduled_by'] ?? 0);
    $sched_observers = $get_required_observers($tid, $active_eval_id_for_schedule, $owning_dept_sched, $scheduled_by_id, (string)($t['name'] ?? ''));
    $observer_map[$sched_key] = $sched_observers;
}

// Process scheduled-only teachers (no evaluation yet)
foreach ($scheduled_teachers as $t) {
    $tid = $t['id'];
    if (isset($seen_teacher_ids[$tid])) continue;
    if (isset($seen_ids[$tid])) continue;
    $seen_ids[$tid] = true;
    $teachers_list[] = array_merge($t, ['_row_key' => $tid]);

    $sched_dt = $t['evaluation_schedule'] ?? '';
    $sched_date = !empty($sched_dt) ? date('Y-m-d H:i', strtotime($sched_dt)) : '';
    $eval_data[$tid] = ['date' => $sched_date, 'done' => false, 'faculty_signature' => '', 'eval_id' => null, 'status' => 'scheduled'];

    $focus_raw = $t['evaluation_focus'] ?? '';
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);
    $day_time = '';
    if (!empty($sched_dt)) { 
        $ts = strtotime($sched_dt);
        $day_str = date('l', $ts);
        $start_time = date('g:i A', $ts);
        $sched_dt_end = $t['evaluation_schedule_end'] ?? '';
        if (!empty($sched_dt_end)) {
            $ts_end = strtotime($sched_dt_end);
            $end_time = date('g:i A', $ts_end);
            $day_time = $day_str . "\n" . $start_time . ' - ' . $end_time;
        } else {
            $day_time = $day_str . "\n" . $start_time;
        }
    }
    $schedule_data[$tid] = [
        'semester' => $t['evaluation_semester'] ?? '',
        'focus' => implode(', ', $focus_display),
        'day_time' => $day_time,
        'subject_area' => $t['evaluation_subject_area'] ?? '',
        'subject' => normalizeSubjectDisplay($t['evaluation_subject'] ?? ''),
        'room' => $t['evaluation_room'] ?? '',
    ];

    $owning_dept = $t['scheduled_department'] ?? '';
    if ($owning_dept === '') {
        $owning_dept = ($t['teacher_department'] ?? '') ?: $raw_department;
    }
    $teacher_name = $t['name'] ?? '';
    $scheduled_by_id = (int)($t['scheduled_by'] ?? 0);
    $all_observers = $get_required_observers($tid, 0, $owning_dept, $scheduled_by_id, $teacher_name);
    $all_observers = $enforce_observer_for_teacher($all_observers, (string) ($t['name'] ?? ''));
    $observer_map[$tid] = $all_observers;
}

// Consolidate duplicate rows for print output.
// Use stable slot identity and normalize subject text to ignore trailing time
// strings (e.g., "GEC 9 8:00 PM" vs "GEC 9").
if (!empty($teachers_list)) {
    $normalize_subject_slot = static function(string $subject): string {
        $s = strtolower(trim($subject));
        if ($s === '') return '';
        $s = preg_replace('/\s+\d{1,2}:\d{2}\s*(am|pm)\s*$/i', '', $s);
        return trim((string)$s);
    };

    $merged_rows = [];
    $merged_index = [];
    foreach ($teachers_list as $t) {
        $rk = $t['_row_key'] ?? $t['id'];
        $ed = $eval_data[$rk] ?? [];
        $sd = $schedule_data[$rk] ?? [];
        $date_raw = trim((string)($ed['date'] ?? ''));
        $date_norm = $date_raw !== '' ? date('Y-m-d', strtotime($date_raw)) : '';
        $key = implode('|', [
            (string)($t['id'] ?? ''),
            $date_norm,
            strtolower(trim((string)($sd['subject_area'] ?? ''))),
            $normalize_subject_slot((string)($sd['subject'] ?? '')),
            strtolower(trim((string)($sd['room'] ?? ''))),
        ]);

        if (!isset($merged_index[$key])) {
            $merged_index[$key] = count($merged_rows);
            $merged_rows[] = $t;
            continue;
        }

        $keep_idx = $merged_index[$key];
        $keep = $merged_rows[$keep_idx];
        $keep_rk = $keep['_row_key'] ?? $keep['id'];

        // Merge observers for print row.
        $keep_obs = $observer_map[$keep_rk] ?? [];
        $new_obs = $observer_map[$rk] ?? [];
        $observer_map[$keep_rk] = array_values(array_unique(array_merge($keep_obs, $new_obs)));

        // Prefer "done" row as representative when duplicates conflict.
        $keep_done = !empty($eval_data[$keep_rk]['done']);
        $new_done = !empty($eval_data[$rk]['done']);
        if (!$keep_done && $new_done) {
            $merged_rows[$keep_idx] = $t;
        }
    }
    $teachers_list = array_values($merged_rows);
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

// Filter by status (scheduled / rescheduled / done)
if (!empty($filter_status)) {
    $teachers_list = array_filter($teachers_list, function($t) use ($eval_data, $filter_status) {
        $rk = $t['_row_key'] ?? $t['id'];
        $is_done = !empty($eval_data[$rk]['done']);
        $row_status = strtolower(trim((string)($eval_data[$rk]['status'] ?? '')));
        $has_sched = !empty($t['evaluation_schedule']);
        if ($filter_status === 'done') return $is_done;
        if ($filter_status === 'rescheduled') return ($row_status === 'rescheduled');
        if ($filter_status === 'scheduled') return $has_sched && !$is_done;
        return true;
    });
    $teachers_list = array_values($teachers_list);
}

// Teacher signature in print comes from observation acknowledgments.
// Primary match is evaluation_id; fallback is teacher+date slot.
$ack_eval_map = [];
$ack_teacher_date_map = [];
$ack_teacher_latest_map = [];
try {
    $eval_ids_for_ack = [];
    foreach ($teachers_list as $tt) {
        $rk = $tt['_row_key'] ?? ($tt['id'] ?? null);
        $eid = (int)($eval_data[$rk]['eval_id'] ?? 0);
        if ($eid > 0) $eval_ids_for_ack[$eid] = true;
    }
    $eval_ids_for_ack = array_keys($eval_ids_for_ack);
    if (!empty($eval_ids_for_ack)) {
        $ph = implode(',', array_fill(0, count($eval_ids_for_ack), '?'));
        $sem_alt = $semester . ' Semester';
        $ack_sql = "SELECT evaluation_id, signature, acknowledged_at, id
                    FROM observation_plan_acknowledgments
                    WHERE academic_year = ?
                      AND semester IN (?, ?)
                      AND evaluation_id IN ($ph)
                    ORDER BY acknowledged_at DESC, id DESC";
        $ack_stmt = $db->prepare($ack_sql);
        $ack_stmt->execute(array_merge([$academic_year, $semester, $sem_alt], $eval_ids_for_ack));
        while ($ack = $ack_stmt->fetch(PDO::FETCH_ASSOC)) {
            $aeid = (int)($ack['evaluation_id'] ?? 0);
            if ($aeid > 0 && !isset($ack_eval_map[$aeid])) {
                $ack_eval_map[$aeid] = $ack;
            }
        }
    }

    // Fallback map for legacy/null/relinked evaluation_id cases in print.
    $ack_slot_sql = "SELECT a.teacher_id, DATE(COALESCE(e.observation_date, a.acknowledged_at)) AS slot_date,
                            a.signature, a.acknowledged_at, a.id
                     FROM observation_plan_acknowledgments a
                     LEFT JOIN evaluations e ON e.id = a.evaluation_id
                     WHERE a.academic_year = ?
                       AND a.semester IN (?, ?)
                     ORDER BY a.acknowledged_at DESC, a.id DESC";
    $ack_slot_stmt = $db->prepare($ack_slot_sql);
    $ack_slot_stmt->execute([$academic_year, $semester, $sem_alt]);
    while ($ack = $ack_slot_stmt->fetch(PDO::FETCH_ASSOC)) {
        $teacher_id = (int)($ack['teacher_id'] ?? 0);
        $slot_date = trim((string)($ack['slot_date'] ?? ''));
        $sig_val = trim((string)($ack['signature'] ?? ''));
        if ($teacher_id <= 0 || $slot_date === '') {
            continue;
        }
        $slot_key = $teacher_id . '|' . $slot_date;
        if (!isset($ack_teacher_date_map[$slot_key])) {
            $ack_teacher_date_map[$slot_key] = $sig_val;
        }
        if ($sig_val !== '' && !isset($ack_teacher_latest_map[$teacher_id])) {
            $ack_teacher_latest_map[$teacher_id] = $sig_val;
        }
    }
} catch (Exception $e) {}

$dean_role_display = ucfirst(str_replace('_', ' ', $_SESSION['role']));

$dean_signature = '';
$dean_signature_user_id = (int)($_SESSION['user_id'] ?? 0);
if ($is_leader && $raw_department !== '') {
    try {
        $dept_lead_stmt = $db->prepare("SELECT id, name, role FROM users WHERE department = :department AND role IN ('dean','principal') AND status = 'active' ORDER BY role = 'dean' DESC, name ASC LIMIT 1");
        $dept_lead_stmt->execute([':department' => $raw_department]);
        $dept_lead = $dept_lead_stmt->fetch(PDO::FETCH_ASSOC);
        if ($dept_lead) {
            $dean_name = $dept_lead['name'] ?? $dean_name;
            $dean_role_display = ucfirst(str_replace('_', ' ', $dept_lead['role'] ?? 'dean'));
            $dean_signature_user_id = (int)($dept_lead['id'] ?? 0);
        }
    } catch (Exception $e) {}
}
try {
    if ($dean_signature_user_id > 0) {
        $sig_query = "SELECT rater_signature FROM evaluations WHERE evaluator_id = :evaluator_id AND rater_signature IS NOT NULL AND rater_signature != '' ORDER BY created_at DESC LIMIT 1";
        $sig_stmt = $db->prepare($sig_query);
        $sig_stmt->bindValue(':evaluator_id', $dean_signature_user_id, PDO::PARAM_INT);
        $sig_stmt->execute();
        $sig_row = $sig_stmt->fetch(PDO::FETCH_ASSOC);
        if ($sig_row) {
            $dean_signature = $sig_row['rater_signature'];
        }
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
            border: 1px solid #000;
        }
        .plan-table th {
            background: #E3A15A;
            color: #000;
            font-weight: 700;
            font-size: 10px;
            padding: 5px 6px;
            border: 1px solid #000;
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
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
                    <th style="width: 10%;"><?php echo in_array($raw_department, ['JHS', 'ELEM']) ? 'Grade Level/Section' : 'Subject Area'; ?></th>
                    <th style="width: 11%;"><?php echo in_array($raw_department, ['JHS', 'ELEM']) ? 'Subject of Instruction' : 'Subject'; ?></th>
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
                            $row_eval_id = (int)($eval_data[$rk]['eval_id'] ?? 0);
                            $ack_sig = ($row_eval_id > 0) ? trim((string)($ack_eval_map[$row_eval_id]['signature'] ?? '')) : '';
                            if ($ack_sig === '') {
                                $row_date_raw = $eval_data[$rk]['date'] ?? '';
                                $row_date = !empty($row_date_raw) ? date('Y-m-d', strtotime($row_date_raw)) : '';
                                $slot_key = ((int)$tid) . '|' . $row_date;
                                $ack_sig = trim((string)($ack_teacher_date_map[$slot_key] ?? ''));
                            }
                            // Keep print consistent with report rows:
                            // if acknowledgment signature is missing, fallback to
                            // evaluation-row faculty signature for this slot.
                            if ($ack_sig === '') {
                                $ack_sig = trim((string)($eval_data[$rk]['faculty_signature'] ?? ''));
                            }
                            // Final fallback: latest teacher acknowledgment signature
                            // (covers legacy rows where evaluation_id/date linkage differs).
                            if ($ack_sig === '') {
                                $ack_sig = trim((string)($ack_teacher_latest_map[(int)$tid] ?? ''));
                            }
                            if ($ack_sig !== ''): ?>
                                <img src="<?php echo htmlspecialchars($ack_sig); ?>" alt="Teacher Signature" style="max-height: 35px; max-width: 80px;">
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
            <img
                id="preparedBySignatureImage"
                class="sig-img"
                src="<?php echo !empty($dean_signature) ? htmlspecialchars($dean_signature) : ''; ?>"
                alt="Signature"
                style="<?php echo !empty($dean_signature) ? '' : 'display:none;'; ?>"
            >
            <p class="name-line"><?php echo htmlspecialchars(strtoupper($dean_name)); ?></p>
            <p class="role-dept"><?php echo htmlspecialchars($dean_role_display); ?>, <?php echo htmlspecialchars($raw_department); ?></p>
        </div>
    </div>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const usePreparedSig = urlParams.get('prepared_sig') === '1';
        if (usePreparedSig) {
            const sig = sessionStorage.getItem('prepared_by_signature_data') || '';
            const sigImg = document.getElementById('preparedBySignatureImage');
            if (sig && sigImg) {
                sigImg.src = sig;
                sigImg.style.display = 'block';
            }
        }
        if (urlParams.get('auto_print') === '1') {
            window.onload = function() { window.print(); };
        }
    </script>
</body>
</html>

