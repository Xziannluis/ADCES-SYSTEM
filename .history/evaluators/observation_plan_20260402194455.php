<?php
require_once '../auth/session-check.php';
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../includes/mailer.php';

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);

$hasTeacherDepartments = false;
try {
    $teacherDepartmentsCheck = $db ? $db->query("SHOW TABLES LIKE 'teacher_departments'") : false;
    $hasTeacherDepartments = $teacherDepartmentsCheck && $teacherDepartmentsCheck->fetch(PDO::FETCH_NUM);
} catch (PDOException $e) {
    $hasTeacherDepartments = false;
}

// Clear schedules for teachers where ALL assigned evaluators AND the dean/principal have completed
try {
    $month = (int)date('n');
    $year = (int)date('Y');
    $curAY = ($month >= 6) ? ($year . '-' . ($year + 1)) : (($year - 1) . '-' . $year);
    $db->prepare("UPDATE teachers t
        INNER JOIN evaluations e ON e.teacher_id = t.id AND e.status = 'completed'
            AND e.academic_year = :ay AND e.semester = t.evaluation_semester
        SET t.evaluation_schedule = NULL, t.evaluation_room = NULL, t.evaluation_focus = NULL,
            t.evaluation_subject_area = NULL, t.evaluation_subject = NULL, t.evaluation_semester = NULL,
            t.evaluation_form_type = 'iso', t.scheduled_by = NULL, t.scheduled_department = NULL, t.updated_at = NOW()
        WHERE t.evaluation_schedule IS NOT NULL
          AND t.evaluation_semester IS NOT NULL
          AND (t.evaluation_form_type IS NULL OR t.evaluation_form_type != 'both'
               OR (SELECT COUNT(*) FROM evaluations e2 WHERE e2.teacher_id = t.id AND e2.status = 'completed'
                   AND e2.academic_year = :ay2 AND e2.semester = t.evaluation_semester AND e2.evaluation_form_type = 'peac') > 0)
          AND NOT EXISTS (
              SELECT 1 FROM teacher_assignments ta
              WHERE ta.teacher_id = t.id
              AND NOT EXISTS (
                  SELECT 1 FROM evaluations e3
                  WHERE e3.teacher_id = t.id
                  AND e3.evaluator_id = ta.evaluator_id
                  AND e3.status = 'completed'
                  AND e3.academic_year = :ay3
                  AND e3.semester = t.evaluation_semester
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
                  AND e4.semester = t.evaluation_semester
                  AND u2.role IN ('dean', 'principal')
              )
          )")
        ->execute([':ay' => $curAY, ':ay2' => $curAY, ':ay3' => $curAY, ':ay4' => $curAY]);
} catch (Exception $e) {
    error_log('Error clearing completed-eval schedules: ' . $e->getMessage());
}

$success_message = '';
$error_message = '';

// Cancel / clear evaluation schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_schedule') {
    $teacher_ids = [];
    if (!empty($_POST['teacher_ids'])) {
        $decoded = json_decode($_POST['teacher_ids'], true);
        if (is_array($decoded)) {
            $teacher_ids = array_map('intval', $decoded);
        }
    } elseif (!empty($_POST['teacher_id'])) {
        $teacher_ids = [intval($_POST['teacher_id'])];
    }

    $cancelled = 0;
    foreach ($teacher_ids as $teacher_id) {
        if ($teacher_id <= 0) continue;
        $query = "UPDATE teachers SET evaluation_schedule = NULL, evaluation_room = NULL, evaluation_focus = NULL, evaluation_subject_area = NULL, evaluation_subject = NULL, evaluation_semester = NULL, evaluation_form_type = 'iso', scheduled_by = NULL, scheduled_department = NULL, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $teacher_id);
        if ($stmt->execute()) {
            $cancelled++;
            try {
                $tq = $db->prepare("SELECT user_id, name FROM teachers WHERE id = :id LIMIT 1");
                $tq->bindParam(':id', $teacher_id);
                $tq->execute();
                $tdata = $tq->fetch(PDO::FETCH_ASSOC);
                $uid = $tdata['user_id'] ?? null;
                $description = sprintf("Schedule cancelled for %s. Cancelled by %s (user_id=%d)", $tdata['name'] ?? ('teacher_id=' . $teacher_id), $_SESSION['name'], $_SESSION['user_id']);
                $log_q = $db->prepare("INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:user_id, :action, :description, :ip)");
                $log_q->bindValue(':user_id', $uid ?: $_SESSION['user_id']);
                $log_q->bindValue(':action', 'SCHEDULE_CANCELLED');
                $log_q->bindParam(':description', $description);
                $log_q->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? '');
                $log_q->execute();
            } catch (Exception $e) {
                error_log('Schedule cancel log error: ' . $e->getMessage());
            }
        }
    }
    if ($cancelled > 0) {
        $success_message = "Evaluation schedule cancelled for {$cancelled} teacher(s).";
    } else {
        $error_message = "Failed to cancel schedule.";
    }
    $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
    if ($success_message) $_SESSION['success'] = $success_message;
    if ($error_message) $_SESSION['error'] = $error_message;
    header("Location: $redirect");
    exit();
}

// Handle leader opt-in as observer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'join_observer') {
    if (in_array($_SESSION['role'], ['president', 'vice_president'])) {
        $teacher_ids = json_decode($_POST['teacher_ids'] ?? '[]', true);
        if (is_array($teacher_ids) && count($teacher_ids) > 0) {
            $joined = 0;
            $leaderName = $_SESSION['name'] ?? 'President';
            $leaderRole = $_SESSION['role'] ?? 'president';
            $leaderRoleDisplay = ucfirst(str_replace('_', ' ', $leaderRole));

            // Prepare notification insert
            $notifInsert = $db->prepare(
                "INSERT INTO notifications (user_id, teacher_id, type, title, message, link) VALUES (:user_id, :teacher_id, 'schedule', :title, :message, :link)"
            );

            foreach ($teacher_ids as $tid) {
                $tid = (int)$tid;
                if ($tid <= 0) continue;
                // Check if already assigned
                $chk = $db->prepare("SELECT id FROM teacher_assignments WHERE evaluator_id = :eid AND teacher_id = :tid LIMIT 1");
                $chk->execute([':eid' => $_SESSION['user_id'], ':tid' => $tid]);
                if (!$chk->fetch()) {
                    $ins = $db->prepare("INSERT INTO teacher_assignments (evaluator_id, teacher_id, assigned_at) VALUES (:eid, :tid, NOW())");
                    $ins->execute([':eid' => $_SESSION['user_id'], ':tid' => $tid]);
                    $joined++;

                    // Get teacher info
                    $tStmt = $db->prepare("SELECT t.name, t.email, t.department, t.user_id FROM teachers t WHERE t.id = :tid LIMIT 1");
                    $tStmt->execute([':tid' => $tid]);
                    $tInfo = $tStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$tInfo) continue;

                    $teacherName = $tInfo['name'] ?? 'Teacher';
                    $teacherDept = $tInfo['department'] ?? '';
                    $notifTitle = "$leaderRoleDisplay accepted as Observer";
                    $notifMessage = "$leaderName ($leaderRoleDisplay) has accepted to observe $teacherName.";
                    $notifLink = 'evaluators/observation_plan.php';

                    // Notify the teacher (in-app + email)
                    if (!empty($tInfo['user_id'])) {
                        try {
                            $notifInsert->execute([
                                ':user_id' => $tInfo['user_id'],
                                ':teacher_id' => $tid,
                                ':title' => $notifTitle,
                                ':message' => $notifMessage,
                                ':link' => 'teachers/observation_plan.php'
                            ]);
                        } catch (Exception $e) {}
                    }
                    if (!empty($tInfo['email'])) {
                        try {
                            sendGenericNotificationEmail($tInfo['email'], $teacherName, $notifTitle, $notifMessage);
                        } catch (Exception $e) {}
                    }

                    // Notify dean/principal + coordinators in the teacher's department
                    if (!empty($teacherDept)) {
                        $deptUsersStmt = $db->prepare(
                            "SELECT DISTINCT u.id, u.name, u.email FROM users u
                             WHERE u.department = :dept
                               AND u.role IN ('dean','principal','chairperson','subject_coordinator','grade_level_coordinator')
                               AND u.status = 'active'
                               AND u.id != :leader_id
                               AND u.email IS NOT NULL AND u.email != ''"
                        );
                        $deptUsersStmt->execute([':dept' => $teacherDept, ':leader_id' => $_SESSION['user_id']]);
                        $deptUsers = $deptUsersStmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($deptUsers as $du) {
                            // In-app notification
                            try {
                                $notifInsert->execute([
                                    ':user_id' => $du['id'],
                                    ':teacher_id' => $tid,
                                    ':title' => $notifTitle,
                                    ':message' => $notifMessage,
                                    ':link' => $notifLink
                                ]);
                            } catch (Exception $e) {}

                            // Email notification
                            if (!empty($du['email'])) {
                                try {
                                    sendGenericNotificationEmail($du['email'], $du['name'], $notifTitle, $notifMessage);
                                } catch (Exception $e) {}
                            }
                        }
                    }
                }
            }
            $_SESSION['success'] = "Accepted as observer for $joined teacher(s). Notifications sent.";
        }
    }
    $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
    if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
    header("Location: $redirect");
    exit();
}

// Handle schedule setting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_schedule') {
    $teacher_id = $_POST['teacher_id'] ?? ($_POST['reschedule_teacher_id'] ?? '');
    $schedule = $_POST['evaluation_schedule'] ?? '';
    $room = $_POST['evaluation_room'] ?? '';
    $focus = isset($_POST['evaluation_focus']) && is_array($_POST['evaluation_focus']) ? $_POST['evaluation_focus'] : [];
    $subject_area = trim($_POST['evaluation_subject_area'] ?? '');
    $subject = trim($_POST['evaluation_subject'] ?? '');
    $post_semester = trim($_POST['evaluation_semester'] ?? '');
    $post_semester = in_array($post_semester, ['1st', '2nd']) ? $post_semester : null;
    $form_type = trim($_POST['evaluation_form_type'] ?? 'iso');
    $form_type = in_array($form_type, ['iso', 'peac', 'both']) ? $form_type : 'iso';
    // PEAC is exclusive to JHS department
    $scheduled_department = trim($_POST['scheduled_department'] ?? '');
    $peac_check_dept = !empty($scheduled_department) ? $scheduled_department : ($_SESSION['department'] ?? '');
    if (($form_type === 'peac' || $form_type === 'both') && $peac_check_dept !== 'JHS') {
        $form_type = 'iso';
    }

    $valid_focus = ['communications', 'management', 'assessment', 'teacher_actions', 'student_learning_actions'];
    $focus = array_values(array_intersect($focus, $valid_focus));
    $focus_json = !empty($focus) ? json_encode($focus) : null;

    if (!empty($teacher_id)) {
        $query = "UPDATE teachers SET evaluation_schedule = :schedule, evaluation_room = :room, evaluation_focus = :focus, evaluation_subject_area = :subject_area, evaluation_subject = :subject, evaluation_semester = :semester, evaluation_form_type = :form_type, scheduled_by = :scheduled_by, scheduled_department = :scheduled_department, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':schedule', $schedule);
        $stmt->bindParam(':room', $room);
        $stmt->bindParam(':focus', $focus_json);
        $stmt->bindParam(':subject_area', $subject_area);
        $stmt->bindParam(':subject', $subject);
        $stmt->bindParam(':semester', $post_semester);
        $stmt->bindParam(':form_type', $form_type);
        $is_leader_role = in_array($_SESSION['role'], ['president', 'vice_president']);
        $stmt->bindValue(':scheduled_by', $is_leader_role ? $_SESSION['user_id'] : null, $is_leader_role ? PDO::PARAM_INT : PDO::PARAM_NULL);
        // Always store scheduled_department: leaders use selected dept, deans/coordinators use their own dept
        if ($is_leader_role && !empty($scheduled_department)) {
            $sched_dept_val = $scheduled_department;
        } else {
            // Use the teacher's department so the correct dean can see the schedule
            $teacher_dept_stmt = $db->prepare("SELECT department FROM teachers WHERE id = :id LIMIT 1");
            $teacher_dept_stmt->bindParam(':id', $teacher_id);
            $teacher_dept_stmt->execute();
            $teacher_dept_val = $teacher_dept_stmt->fetchColumn();
            $sched_dept_val = ($teacher_dept_val !== false && trim($teacher_dept_val) !== '')
                ? trim($teacher_dept_val)
                : ($_SESSION['department'] ?? null);
        }
        $stmt->bindValue(':scheduled_department', $sched_dept_val);
        $stmt->bindParam(':id', $teacher_id);

        if ($stmt->execute()) {
            // Clear signatures for this department only when schedule is set/updated — teacher must re-sign
            $del_sem = $_POST['filter_semester'] ?? ($_GET['semester'] ?? '1st');
            $del_ay = $_POST['filter_academic_year'] ?? ($_GET['academic_year'] ?? '');
            if (!empty($del_ay)) {
                $del_ack = $db->prepare("DELETE FROM observation_plan_acknowledgments WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem AND (department = :dept OR department IS NULL)");
                $del_ack->execute([':tid' => $teacher_id, ':ay' => $del_ay, ':sem' => $del_sem, ':dept' => $sched_dept_val]);
            }
            $is_reschedule = !empty($_POST['is_reschedule']);
            $success_message = $is_reschedule ? "Schedule updated. Teacher will need to sign again." : "Evaluation schedule set successfully!";
            notifyScheduleParticipants($db, $teacher_id, $schedule, $room, $_SESSION['user_id'], $_SESSION['name'] ?? 'Evaluator', $_SESSION['role'] ?? '', $sched_dept_val ?? '');
        } else {
            $error_message = "Failed to set schedule.";
        }
    } else {
        $error_message = "Teacher ID is required.";
    }

    // Redirect to avoid resubmission, preserving filters
    $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
    if ($success_message) $_SESSION['success'] = $success_message;
    if ($error_message) $_SESSION['error'] = $error_message;
    header("Location: $redirect");
    exit();
}

// Flash messages from redirect
if (!empty($_SESSION['success'])) { $success_message = $_SESSION['success']; unset($_SESSION['success']); }
if (!empty($_SESSION['error'])) { $error_message = $_SESSION['error']; unset($_SESSION['error']); }

// View toggle: "plan" (default) or "my_observation"
$view_mode = $_GET['view'] ?? 'plan';
$my_teacher_id = $_SESSION['teacher_id'] ?? null;

// If teacher_id not in session, try to resolve it now (e.g. teacher record linked after login)
if (empty($my_teacher_id) && !empty($_SESSION['user_id'])) {
    $resolve_stmt = $db->prepare("SELECT id FROM teachers WHERE user_id = :uid LIMIT 1");
    $resolve_stmt->execute([':uid' => $_SESSION['user_id']]);
    $resolved = $resolve_stmt->fetch(PDO::FETCH_ASSOC);
    if ($resolved) {
        $my_teacher_id = $resolved['id'];
        $_SESSION['teacher_id'] = $my_teacher_id;
    } elseif (!empty($_SESSION['name']) && !empty($_SESSION['department'])) {
        // Fallback: match by name and department, then link
        $name_stmt = $db->prepare("SELECT id FROM teachers WHERE name = :name AND department = :dept AND user_id IS NULL LIMIT 1");
        $name_stmt->execute([':name' => $_SESSION['name'], ':dept' => $_SESSION['department']]);
        $name_match = $name_stmt->fetch(PDO::FETCH_ASSOC);
        if ($name_match) {
            $link_stmt = $db->prepare("UPDATE teachers SET user_id = :uid WHERE id = :tid");
            $link_stmt->execute([':uid' => $_SESSION['user_id'], ':tid' => $name_match['id']]);
            $my_teacher_id = $name_match['id'];
            $_SESSION['teacher_id'] = $my_teacher_id;
        }
    }
}

$has_teacher_record = !empty($my_teacher_id);

// "My Observation" data
$my_teacher_data = null;
$my_evaluations = [];
$my_acknowledgment = null;
$my_observer_names = [];

if ($view_mode === 'my_observation' && $has_teacher_record) {
    // Handle signature POST — per-schedule signing
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_plan') {
        $ack_semester = trim($_POST['semester'] ?? '');
        $ack_academic_year = trim($_POST['academic_year'] ?? '');
        $signed_items = $_POST['signed_items'] ?? [];
        if (in_array($ack_semester, ['1st', '2nd']) && !empty($ack_academic_year) && is_array($signed_items) && count($signed_items) > 0) {
            $sig_data = $_POST['signature_data'] ?? null;
            if ($sig_data && !preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $sig_data)) {
                $sig_data = null;
            }
            $signed_count = 0;
            $signed_eval_ids = [];
            $has_upcoming = false;
            foreach ($signed_items as $item) {
                $eval_id = ($item === 'upcoming') ? null : (int)$item;
                // Check if already signed
                // Determine department for this signature
                $sign_dept = null;
                if ($eval_id !== null) {
                    $dStmt2 = $db->prepare("SELECT u.department FROM evaluations e JOIN users u ON u.id = e.evaluator_id WHERE e.id = :eid LIMIT 1");
                    $dStmt2->execute([':eid' => $eval_id]);
                    $sign_dept = $dStmt2->fetchColumn() ?: null;
                }
                if (empty($sign_dept)) {
                    // Use the teacher's own department so the correct dean can see the signature
                    $tdStmt = $db->prepare("SELECT department FROM teachers WHERE id = :tid LIMIT 1");
                    $tdStmt->execute([':tid' => $my_teacher_id]);
                    $tRow = $tdStmt->fetch(PDO::FETCH_ASSOC);
                    $sign_dept = $tRow['department'] ?? null;
                }
                if ($eval_id === null) {
                    $check = $db->prepare("SELECT id FROM observation_plan_acknowledgments WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem AND evaluation_id IS NULL AND (department = :dept OR (department IS NULL AND :dept2 IS NULL)) LIMIT 1");
                    $check->execute([':tid' => $my_teacher_id, ':ay' => $ack_academic_year, ':sem' => $ack_semester, ':dept' => $sign_dept, ':dept2' => $sign_dept]);
                } else {
                    $check = $db->prepare("SELECT id FROM observation_plan_acknowledgments WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem AND evaluation_id = :eid LIMIT 1");
                    $check->execute([':tid' => $my_teacher_id, ':ay' => $ack_academic_year, ':sem' => $ack_semester, ':eid' => $eval_id]);
                }
                if ($check->rowCount() === 0) {
                    $ins = $db->prepare("INSERT INTO observation_plan_acknowledgments (teacher_id, academic_year, semester, department, evaluation_id, acknowledged_at, signature) VALUES (:tid, :ay, :sem, :dept, :eid, NOW(), :sig)");
                    $ins->execute([':tid' => $my_teacher_id, ':ay' => $ack_academic_year, ':sem' => $ack_semester, ':dept' => $sign_dept, ':eid' => $eval_id, ':sig' => $sig_data]);
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
                    $pdStmt = $db->prepare("SELECT department FROM teachers WHERE id = :id LIMIT 1");
                    $pdStmt->execute([':id' => $my_teacher_id]);
                    $pd = $pdStmt->fetchColumn();
                    if (!empty($pd)) $signed_depts[] = $pd;
                }
                notifyObservationPlanSigned($db, $my_teacher_id, $_SESSION['name'] ?? 'Teacher', $signed_depts);
            } else {
                $success_message = "Selected schedules were already signed.";
            }
        }
    }
}

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

$all_departments = ['ELEM', 'JHS', 'SHS', 'CCIS', 'CAS', 'CTEAS', 'CBM', 'CTHM', 'CCJE'];
if ($is_leader) {
    $filter_department = trim((string)($_GET['department'] ?? ''));
    $raw_department = in_array($filter_department, $all_departments) ? $filter_department : '';
} else {
    $raw_department = (string)($_SESSION['department'] ?? '');
}
$department_display = $raw_department === '' ? 'All Departments' : ($department_map[$raw_department] ?? $raw_department);

// Get semester filter
$semester = $_GET['semester'] ?? '1st';
$academic_year = $_GET['academic_year'] ?? '';
$filter_month = $_GET['month'] ?? '';

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

// Load "My Observation" data if in that view mode
if ($view_mode === 'my_observation' && $has_teacher_record) {
    $focus_labels_my = [
        'communications' => 'Communication Competence',
        'management' => 'Management and Presentation of the Lesson',
        'assessment' => "Assessment of Students' Learning",
        'teacher_actions' => 'Teacher Actions',
        'student_learning_actions' => 'Student Learning Actions'
    ];

    $t_stmt = $db->prepare("SELECT t.* FROM teachers t WHERE t.id = :id LIMIT 1");
    $t_stmt->execute([':id' => $my_teacher_id]);
    $my_teacher_data = $t_stmt->fetch(PDO::FETCH_ASSOC);

    if ($my_teacher_data) {
        // Get evaluators assigned to this teacher
        $obs_query = "SELECT DISTINCT u.name, u.role FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :tid ORDER BY u.name";
        $obs_stmt = $db->prepare($obs_query);
        $obs_stmt->execute([':tid' => $my_teacher_id]);
        $my_observers = $obs_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get deans/principals from ALL departments this teacher belongs to (primary + secondary), excluding self
        $my_all_depts = [$my_teacher_data['department']];
        try {
            $sec_stmt = $db->prepare("SELECT department FROM teacher_departments WHERE teacher_id = :tid");
            $sec_stmt->execute([':tid' => $my_teacher_id]);
            while ($sd = $sec_stmt->fetchColumn()) {
                if (!in_array($sd, $my_all_depts)) $my_all_depts[] = $sd;
            }
        } catch (Exception $e) {}
        $ph_depts = implode(',', array_fill(0, count($my_all_depts), '?'));
        $dean_query = "SELECT DISTINCT name FROM users WHERE department IN ($ph_depts) AND role IN ('dean','principal') AND status = 'active' AND id != ? ORDER BY name";
        $dean_stmt = $db->prepare($dean_query);
        $dean_stmt->execute(array_merge($my_all_depts, [$_SESSION['user_id']]));
        while ($dean_name_row = $dean_stmt->fetchColumn()) {
            if (!in_array($dean_name_row, $my_observer_names)) $my_observer_names[] = $dean_name_row;
        }

        // Add assigned coordinators (unless current user is a coordinator — then only deans observe)
        if (!in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
            foreach ($my_observers as $obs) {
                if ($obs['name'] === ($_SESSION['name'] ?? '')) continue; // exclude self
                if (!in_array($obs['name'], $my_observer_names)) $my_observer_names[] = $obs['name'];
            }
        }
        // If President/VP scheduled this teacher, show them + department evaluators
        $my_sched_by = $my_teacher_data['scheduled_by'] ?? null;
        if ($my_sched_by) {
            $sb_stmt = $db->prepare("SELECT name FROM users WHERE id = :id AND role IN ('president','vice_president') AND status = 'active' LIMIT 1");
            $sb_stmt->execute([':id' => $my_sched_by]);
            $sb_name = $sb_stmt->fetchColumn();
            if ($sb_name) {
                $my_observer_names = [$sb_name];
                $my_sched_dept = $my_teacher_data['scheduled_department'] ?? '';
                if (!empty($my_sched_dept)) {
                    $dept_obs_stmt = $db->prepare("SELECT DISTINCT name FROM users WHERE department = :dept AND role IN ('dean','principal','chairperson','subject_coordinator','grade_level_coordinator') AND status = 'active' AND id != :setter_id ORDER BY name");
                    $dept_obs_stmt->execute([':dept' => $my_sched_dept, ':setter_id' => $my_sched_by]);
                    while ($dn = $dept_obs_stmt->fetchColumn()) {
                        if (!in_array($dn, $my_observer_names)) $my_observer_names[] = $dn;
                    }
                }
                // Exclude self from observer list
                $my_own_name = $_SESSION['name'] ?? '';
                $my_observer_names = array_values(array_filter($my_observer_names, function($n) use ($my_own_name) {
                    return $n !== $my_own_name;
                }));
            }
        }
        // If NOT scheduled by president/VP, add president/VP who have evaluated this teacher
        if (empty($my_sched_by) || empty($sb_name)) {
            $pv_stmt = $db->prepare("SELECT DISTINCT u.name FROM evaluations e JOIN users u ON e.evaluator_id = u.id WHERE e.teacher_id = :tid AND e.academic_year = :ay AND e.semester = :sem AND u.role IN ('president','vice_president') ORDER BY u.name");
            $pv_stmt->execute([':tid' => $my_teacher_id, ':ay' => $academic_year, ':sem' => $semester]);
            while ($pv_name = $pv_stmt->fetchColumn()) {
                if (!in_array($pv_name, $my_observer_names)) {
                    $my_observer_names[] = $pv_name;
                }
            }
        }

        // Get completed evaluations
        $eval_query = "SELECT e.id, e.observation_date, e.status, e.subject_area, e.subject_observed, e.observation_room, e.semester, e.evaluation_focus, u.name as evaluator_name
                       FROM evaluations e JOIN users u ON e.evaluator_id = u.id
                       WHERE e.teacher_id = :tid AND e.academic_year = :ay AND e.semester = :sem
                       ORDER BY e.observation_date ASC";
        $eval_stmt = $db->prepare($eval_query);
        $eval_stmt->execute([':tid' => $my_teacher_id, ':ay' => $academic_year, ':sem' => $semester]);
        $my_evaluations = $eval_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check acknowledgment/signature status — per-item
        $ack_stmt = $db->prepare("SELECT * FROM observation_plan_acknowledgments WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem");
        $ack_stmt->execute([':tid' => $my_teacher_id, ':ay' => $academic_year, ':sem' => $semester]);
        $my_acknowledgments_raw = $ack_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build lookup: evaluation_id => acknowledgment row (null key for upcoming)
        $my_signed_map = [];
        foreach ($my_acknowledgments_raw as $ack) {
            $key = $ack['evaluation_id'] === null ? 'upcoming' : (int)$ack['evaluation_id'];
            $my_signed_map[$key] = $ack;
        }
        // Legacy: if there's an old blanket signature (no evaluation_id column value), treat as all signed
        $my_has_legacy_signature = false;
        if (count($my_acknowledgments_raw) === 1 && $my_acknowledgments_raw[0]['evaluation_id'] === null && count($my_evaluations) > 0) {
            // Could be a legacy blanket signature — check if it was created before this feature
            $my_has_legacy_signature = true;
            $my_acknowledgment = $my_acknowledgments_raw[0]; // keep for legacy display
        } else {
            $my_acknowledgment = null;
        }
    }
}

// Get teachers who have evaluations OR a scheduled observation
// Coordinators see teachers assigned to them + teachers in their department
// Deans/principals see teachers in their department + those they personally evaluated
// President/VP see teachers in the selected department (all departments available)
if ($is_leader) {
    // President/VP: show evaluations they personally made OR all evaluations in the selected department
    if (!empty($raw_department)) {
        $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                         t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                         t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                         t.scheduled_by, t.scheduled_department,
                         e.id as eval_id, e.observation_date, e.status as eval_status, e.faculty_signature,
                         e.subject_observed, e.observation_room as eval_room,
                         e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                         e.semester as eval_semester
                  FROM teachers t
                  JOIN evaluations e ON e.teacher_id = t.id
                  LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                  WHERE (e.evaluator_id = :evaluator_id OR t.department = :dept1 OR td.department = :dept2 OR t.scheduled_department = :dept3)
                  AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                  AND e.academic_year = :academic_year
                  AND e.semester = :semester
                  ORDER BY t.name ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
        $stmt->bindParam(':dept1', $raw_department);
        $stmt->bindParam(':dept2', $raw_department);
        $stmt->bindParam(':dept3', $raw_department);
        $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
        $stmt->bindParam(':academic_year', $academic_year);
        $stmt->bindParam(':semester', $semester);
    } else {
        $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                         t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                         t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                         t.scheduled_by, t.scheduled_department,
                         e.id as eval_id, e.observation_date, e.status as eval_status, e.faculty_signature,
                         e.subject_observed, e.observation_room as eval_room,
                         e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                         e.semester as eval_semester
                  FROM teachers t
                  JOIN evaluations e ON e.teacher_id = t.id
                  WHERE (t.user_id IS NULL OR t.user_id != :current_user_id)
                  AND e.academic_year = :academic_year
                  AND e.semester = :semester
                  ORDER BY t.name ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
        $stmt->bindParam(':academic_year', $academic_year);
        $stmt->bindParam(':semester', $semester);
    }
} elseif ($is_coordinator) {
    // 1) Teachers with evaluations — assigned to this coordinator or personally evaluated by them
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     t.scheduled_by, t.scheduled_department,
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
    // Dean/principal query — show evaluations for teachers who belong to THIS department (primary or secondary)
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     t.scheduled_by, t.scheduled_department,
                     e.id as eval_id, e.observation_date, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              LEFT JOIN teacher_departments td ON td.teacher_id = t.id
              WHERE (t.department = :dept2 OR td.department = :dept3)
              AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              AND e.academic_year = :academic_year
              AND e.semester = :semester
              ORDER BY t.name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':dept2', $raw_department);
    $stmt->bindParam(':dept3', $raw_department);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->bindParam(':academic_year', $academic_year);
    $stmt->bindParam(':semester', $semester);
}
$stmt->execute();
$eval_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Teachers with a schedule set but no evaluation yet (upcoming observations)
if ($is_leader) {
    // President/VP: show ALL scheduled teachers (optionally filtered by department) so they can accept as observer
    if (!empty($raw_department)) {
        $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                               t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                               t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                               t.scheduled_by, t.scheduled_department
                        FROM teachers t
                        LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                        WHERE t.status = 'active'
                          AND t.evaluation_schedule IS NOT NULL
                          AND (t.department = :filter_dept OR td.department = :filter_dept2 OR t.scheduled_department = :filter_dept3)
                          AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                          AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                          AND t.id NOT IN (
                              SELECT DISTINCT e2.teacher_id FROM evaluations e2
                              WHERE e2.academic_year = :academic_year AND e2.semester = :semester
                          )
                        ORDER BY t.name ASC";
        $sched_stmt = $db->prepare($sched_query);
        $sched_stmt->bindParam(':filter_dept', $raw_department);
        $sched_stmt->bindParam(':filter_dept2', $raw_department);
        $sched_stmt->bindParam(':filter_dept3', $raw_department);
        $sched_stmt->bindParam(':filter_semester', $semester);
        $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
        $sched_stmt->bindParam(':academic_year', $academic_year);
        $sched_stmt->bindParam(':semester', $semester);
    } else {
        $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                               t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                               t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                               t.scheduled_by, t.scheduled_department
                        FROM teachers t
                        WHERE t.status = 'active'
                          AND t.evaluation_schedule IS NOT NULL
                          AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                          AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                          AND t.id NOT IN (
                              SELECT DISTINCT e2.teacher_id FROM evaluations e2
                              WHERE e2.academic_year = :academic_year AND e2.semester = :semester
                          )
                        ORDER BY t.name ASC";
        $sched_stmt = $db->prepare($sched_query);
        $sched_stmt->bindParam(':filter_semester', $semester);
        $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
        $sched_stmt->bindParam(':academic_year', $academic_year);
        $sched_stmt->bindParam(':semester', $semester);
    }
} elseif ($is_coordinator) {
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                           t.scheduled_by, t.scheduled_department
                    FROM teachers t
                    JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :assigned_evaluator_id
                    WHERE t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                      AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                      AND t.id NOT IN (
                          SELECT DISTINCT e2.teacher_id FROM evaluations e2
                          WHERE e2.academic_year = :academic_year AND e2.semester = :semester
                      )
                    ORDER BY t.name ASC";
    $sched_stmt = $db->prepare($sched_query);
    $sched_stmt->bindParam(':assigned_evaluator_id', $_SESSION['user_id']);
    $sched_stmt->bindParam(':filter_semester', $semester);
    $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $sched_stmt->bindParam(':academic_year', $academic_year);
    $sched_stmt->bindParam(':semester', $semester);
} else {
    // Dean/principal: show scheduled teachers in this department (primary or secondary)
    // Only show if the schedule was set FOR this department, or if no scheduled_department (legacy/own dept)
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                           t.scheduled_by, t.scheduled_department
                    FROM teachers t
                    LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                    WHERE (t.department = :department OR td.department = :department2)
                      AND t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND (t.scheduled_department IS NULL OR t.scheduled_department = '' OR t.scheduled_department = :department3)
                      AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                      AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                      AND t.id NOT IN (
                          SELECT DISTINCT e2.teacher_id FROM evaluations e2
                          WHERE e2.academic_year = :academic_year AND e2.semester = :semester
                      )
                    ORDER BY t.name ASC";
    $sched_stmt = $db->prepare($sched_query);
    $sched_stmt->bindParam(':department', $raw_department);
    $sched_stmt->bindParam(':department2', $raw_department);
    $sched_stmt->bindParam(':department3', $raw_department);
    $sched_stmt->bindParam(':filter_semester', $semester);
    $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $sched_stmt->bindParam(':academic_year', $academic_year);
    $sched_stmt->bindParam(':semester', $semester);
}
$sched_stmt->execute();
$scheduled_teachers = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build combined teachers list and data maps
$eval_data = [];
$observer_map = [];
$schedule_data = [];

// For leaders: build a set of teacher IDs they have opted into as observer
$leader_opted_teachers = [];
if ($is_leader) {
    $opt_stmt = $db->prepare("SELECT teacher_id FROM teacher_assignments WHERE evaluator_id = :eid");
    $opt_stmt->execute([':eid' => $_SESSION['user_id']]);
    while ($opt_row = $opt_stmt->fetch(PDO::FETCH_ASSOC)) {
        $leader_opted_teachers[(int)$opt_row['teacher_id']] = true;
    }
}
// For dean/principal: use their own name. For president/VP: don't auto-add (they must "Accept as Observer"). For coordinators: look up the dean/principal who supervises them.
if (in_array($_SESSION['role'], ['dean', 'principal'])) {
    $dean_name = $_SESSION['name'] ?? '';
} elseif (in_array($_SESSION['role'], ['president', 'vice_president'])) {
    $dean_name = ''; // President/VP only appear after accepting as observer
} else {
    $dean_name = '';
    $dean_lookup = $db->prepare("SELECT u.name FROM evaluator_assignments ea JOIN users u ON ea.supervisor_id = u.id WHERE ea.evaluator_id = :eid LIMIT 1");
    $dean_lookup->bindParam(':eid', $_SESSION['user_id']);
    $dean_lookup->execute();
    $dean_name = $dean_lookup->fetchColumn() ?: '';
}
$seen_ids = [];
$teachers_list = [];

// Build teacher role map: teacher_id → user role (for filtering observers)
$teacher_role_map = [];
try {
    $role_stmt = $db->query("SELECT t.id, u.role FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.user_id IS NOT NULL");
    while ($rr = $role_stmt->fetch(PDO::FETCH_ASSOC)) {
        $teacher_role_map[$rr['id']] = $rr['role'];
    }
} catch (Exception $e) {}

// Build teacher secondary departments map
$teacher_sec_depts = [];
try {
    $tsd_stmt = $db->query("SELECT teacher_id, department FROM teacher_departments");
    while ($tsd = $tsd_stmt->fetch(PDO::FETCH_ASSOC)) {
        $teacher_sec_depts[$tsd['teacher_id']][] = $tsd['department'];
    }
} catch (Exception $e) {}

// Focus label mapping
$focus_labels = [
    'communications' => 'Communication Competence',
    'management' => 'Management and Presentation of the Lesson',
    'assessment' => "Assessment of Students' Learning",
    'teacher_actions' => 'Teacher Actions',
    'student_learning_actions' => 'Student Learning Actions'
];

// Process teachers with evaluations
foreach ($eval_teachers as $t) {
    $tid = $t['id'];
    if (!isset($seen_ids[$tid])) {
        $seen_ids[$tid] = true;
        $teachers_list[] = $t;
    }

    $obs_date = $t['observation_date'] ?? '';
    $is_done = ($t['eval_status'] === 'completed');
    $faculty_sig = $t['faculty_signature'] ?? '';
    $eval_data[$tid] = ['date' => $obs_date, 'done' => $is_done, 'faculty_signature' => $faculty_sig, 'eval_id' => $t['eval_id'] ?? null];

    // Schedule details: prefer teachers table, fallback to evaluation record for completed evals
    $focus_raw = $t['evaluation_focus'] ?? $t['eval_focus'] ?? '';
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);

    $schedule_data[$tid] = [
        'semester' => $t['evaluation_semester'] ?? $t['eval_semester'] ?? '',
        'focus' => implode(', ', $focus_display),
        'day_time' => '',
        'subject_area' => $t['evaluation_subject_area'] ?? $t['eval_subject_area'] ?? '',
        'subject' => $t['evaluation_subject'] ?? $t['subject_observed'] ?? '',
        'room' => $t['evaluation_room'] ?? $t['eval_room'] ?? '',
    ];
    // Day & Time from schedule or observation_date
    $sched_dt = $t['evaluation_schedule'] ?? '';
    if (!empty($sched_dt)) {
        $ts = strtotime($sched_dt);
        $schedule_data[$tid]['day_time'] = date('D', $ts) . "\n" . date('g:ia', $ts);
    } elseif (!empty($obs_date)) {
        $schedule_data[$tid]['day_time'] = date('D', strtotime($obs_date));
    }

    // Get observers
    if ($is_leader) {
        // Leaders see all evaluators who have evaluated this teacher
        $obs_query = "SELECT DISTINCT u.name FROM evaluations e JOIN users u ON e.evaluator_id = u.id WHERE e.teacher_id = :teacher_id AND e.academic_year = :academic_year AND e.semester = :semester ORDER BY u.name";
        $obs_stmt = $db->prepare($obs_query);
        $obs_stmt->execute([':teacher_id' => $tid, ':academic_year' => $academic_year, ':semester' => $semester]);
        $observers = $obs_stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $obs_query = "SELECT DISTINCT u.name FROM evaluations e JOIN users u ON e.evaluator_id = u.id WHERE e.teacher_id = :teacher_id AND e.academic_year = :academic_year AND e.semester = :semester AND u.department = :department ORDER BY u.name";
        $obs_stmt = $db->prepare($obs_query);
        $obs_stmt->execute([':teacher_id' => $tid, ':academic_year' => $academic_year, ':semester' => $semester, ':department' => $raw_department]);
        $observers = $obs_stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // If teacher is from a different primary department (secondary dept view), only include observers from current department
    $is_secondary_dept = !empty($raw_department) && ($t['teacher_department'] ?? '') !== $raw_department;
    if ($is_secondary_dept) {
        $assign_query = "SELECT DISTINCT u.name FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :teacher_id AND u.department = :dept ORDER BY u.name";
        $assign_stmt = $db->prepare($assign_query);
        $assign_stmt->execute([':teacher_id' => $tid, ':dept' => $raw_department]);
    } else {
        $assign_query = "SELECT DISTINCT u.name FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :teacher_id ORDER BY u.name";
        $assign_stmt = $db->prepare($assign_query);
        $assign_stmt->execute([':teacher_id' => $tid]);
    }
    $assigned = $assign_stmt->fetchAll(PDO::FETCH_COLUMN);

    $all_observers = array_unique(array_merge($observers, $assigned));
    if (!empty($dean_name) && !in_array($dean_name, $all_observers)) {
        array_unshift($all_observers, $dean_name);
    }
    // For leaders: also add the dean/principal of the teacher's department
    if ($is_leader) {
        $teacher_dept = $t['teacher_department'] ?? $t['department'] ?? '';
        if (!empty($teacher_dept)) {
            $dept_dean_stmt = $db->prepare("SELECT DISTINCT name FROM users WHERE department = :dept AND role IN ('dean','principal') AND status = 'active' ORDER BY name");
            $dept_dean_stmt->execute([':dept' => $teacher_dept]);
            while ($dd_name = $dept_dean_stmt->fetchColumn()) {
                if (!in_array($dd_name, $all_observers)) {
                    $all_observers[] = $dd_name;
                }
            }
        }
    }
    // If President/VP scheduled this teacher, show them + department evaluators
    $sched_by_id = $t['scheduled_by'] ?? null;
    if ($sched_by_id) {
        $sb_stmt = $db->prepare("SELECT name FROM users WHERE id = :id AND role IN ('president','vice_president') AND status = 'active' LIMIT 1");
        $sb_stmt->execute([':id' => $sched_by_id]);
        $sb_name = $sb_stmt->fetchColumn();
        if ($sb_name) {
            $sched_dept = $t['scheduled_department'] ?? '';
            $dept_observers = [$sb_name];
            if (!empty($sched_dept)) {
                $dept_obs_stmt = $db->prepare("SELECT DISTINCT name FROM users WHERE department = :dept AND role IN ('dean','principal','chairperson','subject_coordinator','grade_level_coordinator') AND status = 'active' AND id != :setter_id ORDER BY name");
                $dept_obs_stmt->execute([':dept' => $sched_dept, ':setter_id' => $sched_by_id]);
                while ($dn = $dept_obs_stmt->fetchColumn()) {
                    if (!in_array($dn, $dept_observers)) $dept_observers[] = $dn;
                }
            }
            // Exclude the teacher themselves
            $teacher_name = $t['name'] ?? '';
            $dept_observers = array_values(array_filter($dept_observers, function($n) use ($teacher_name) {
                return $n !== $teacher_name;
            }));
            $all_observers = $dept_observers;
            $observer_map[$tid] = $all_observers;
            continue;
        }
    }
    // Exclude the teacher themselves from their own observer list
    $teacher_name = $t['name'] ?? '';
    $all_observers = array_values(array_filter($all_observers, function($n) use ($teacher_name) {
        return $n !== $teacher_name;
    }));
    // If teacher is a chairperson/coordinator, keep deans + explicitly assigned evaluators
    $teacher_user_role = $teacher_role_map[$tid] ?? '';
    if (in_array($teacher_user_role, ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
        $assigned_names_set = array_flip($assigned);
        $filtered = array_values(array_filter($all_observers, function($name) use ($db, $assigned_names_set) {
            if (isset($assigned_names_set[$name])) return true;
            static $dean_cache = null;
            if ($dean_cache === null) {
                $dean_cache = [];
                try {
                    $ds = $db->query("SELECT DISTINCT name FROM users WHERE role IN ('dean','principal','president','vice_president') AND status = 'active'");
                    while ($r = $ds->fetchColumn()) $dean_cache[] = $r;
                } catch (Exception $e) {}
            }
            return in_array($name, $dean_cache);
        }));
        if (!empty($filtered)) $all_observers = $filtered;
    }
    $observer_map[$tid] = $all_observers;
}

// Process scheduled-only teachers (no evaluation yet)
foreach ($scheduled_teachers as $t) {
    $tid = $t['id'];
    if (isset($seen_ids[$tid])) continue;
    $seen_ids[$tid] = true;
    $teachers_list[] = $t;

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

    // If teacher is from a different primary department (secondary dept view), only include observers from current department
    $is_secondary_dept = !empty($raw_department) && ($t['teacher_department'] ?? '') !== $raw_department;
    if ($is_secondary_dept) {
        $assign_query = "SELECT DISTINCT u.name FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :teacher_id AND u.department = :dept ORDER BY u.name";
        $assign_stmt = $db->prepare($assign_query);
        $assign_stmt->execute([':teacher_id' => $tid, ':dept' => $raw_department]);
    } else {
        $assign_query = "SELECT DISTINCT u.name FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :teacher_id ORDER BY u.name";
        $assign_stmt = $db->prepare($assign_query);
        $assign_stmt->execute([':teacher_id' => $tid]);
    }
    $assigned = $assign_stmt->fetchAll(PDO::FETCH_COLUMN);
    $all_observers = $assigned;
    if (!empty($dean_name) && !in_array($dean_name, $all_observers)) {
        array_unshift($all_observers, $dean_name);
    }
    // If President/VP scheduled this teacher, show them + department evaluators
    $sched_by_id = $t['scheduled_by'] ?? null;
    if ($sched_by_id) {
        $sb_stmt = $db->prepare("SELECT name FROM users WHERE id = :id AND role IN ('president','vice_president') AND status = 'active' LIMIT 1");
        $sb_stmt->execute([':id' => $sched_by_id]);
        $sb_name = $sb_stmt->fetchColumn();
        if ($sb_name) {
            $sched_dept = $t['scheduled_department'] ?? '';
            $dept_observers = [$sb_name];
            if (!empty($sched_dept)) {
                $dept_obs_stmt = $db->prepare("SELECT DISTINCT name FROM users WHERE department = :dept AND role IN ('dean','principal','chairperson','subject_coordinator','grade_level_coordinator') AND status = 'active' AND id != :setter_id ORDER BY name");
                $dept_obs_stmt->execute([':dept' => $sched_dept, ':setter_id' => $sched_by_id]);
                while ($dn = $dept_obs_stmt->fetchColumn()) {
                    if (!in_array($dn, $dept_observers)) $dept_observers[] = $dn;
                }
            }
            // Exclude the teacher themselves
            $teacher_name = $t['name'] ?? '';
            $dept_observers = array_values(array_filter($dept_observers, function($n) use ($teacher_name) {
                return $n !== $teacher_name;
            }));
            $all_observers = $dept_observers;
            $observer_map[$tid] = $all_observers;
            continue;
        }
    }
    // Exclude the teacher themselves from their own observer list
    $teacher_name = $t['name'] ?? '';
    $all_observers = array_values(array_filter($all_observers, function($n) use ($teacher_name) {
        return $n !== $teacher_name;
    }));
    // If teacher is a chairperson/coordinator, keep deans + explicitly assigned evaluators
    $teacher_user_role = $teacher_role_map[$tid] ?? '';
    if (in_array($teacher_user_role, ['chairperson', 'subject_coordinator', 'grade_level_coordinator'])) {
        $assigned_names_set = array_flip($assigned);
        $filtered = array_values(array_filter($all_observers, function($name) use ($db, $assigned_names_set) {
            if (isset($assigned_names_set[$name])) return true;
            static $dean_cache2 = null;
            if ($dean_cache2 === null) {
                $dean_cache2 = [];
                try {
                    $ds = $db->query("SELECT DISTINCT name FROM users WHERE role IN ('dean','principal','president','vice_president') AND status = 'active'");
                    while ($r = $ds->fetchColumn()) $dean_cache2[] = $r;
                } catch (Exception $e) {}
            }
            return in_array($name, $dean_cache2);
        }));
        if (!empty($filtered)) $all_observers = $filtered;
    }
    $observer_map[$tid] = $all_observers;
}

// Filter by month if selected
if (!empty($filter_month)) {
    $teachers_list = array_filter($teachers_list, function($t) use ($eval_data, $filter_month) {
        $date = $eval_data[$t['id']]['date'] ?? '';
        if (empty($date)) return false;
        return date('n', strtotime($date)) == $filter_month;
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

// Get all assigned teachers who don't have a schedule yet (for "Set Schedule" button)
$schedulable_teachers = [];
if ($is_leader) {
    // President/VP: show ALL active teachers system-wide
    $st_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                        t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                        t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                        t.evaluation_form_type
                 FROM teachers t
                 WHERE t.status = 'active'
                   AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                 ORDER BY t.department, t.name ASC";
    $st_stmt = $db->prepare($st_query);
    $st_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
} elseif ($is_coordinator) {
    $st_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                        t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                        t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                        t.evaluation_form_type
                 FROM teachers t
                 JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :evaluator_id
                 WHERE t.status = 'active'
                   AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                 ORDER BY t.name ASC";
    $st_stmt = $db->prepare($st_query);
    $st_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $st_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
} else {
    $st_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                        t.evaluation_schedule, t.evaluation_room, t.evaluation_focus,
                        t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                        t.evaluation_form_type
                 FROM teachers t
                 LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                 WHERE (t.department = :department OR td.department = :department2)
                   AND t.status = 'active'
                   AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                 ORDER BY t.name ASC";
    $st_stmt = $db->prepare($st_query);
    $st_stmt->bindParam(':department', $raw_department);
    $st_stmt->bindParam(':department2', $raw_department);
    $st_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
}
$st_stmt->execute();
$schedulable_teachers = $st_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build teacher departments map for modal (primary + secondary departments per teacher)
$teacher_depts_map = [];
if ($is_leader) {
    foreach ($schedulable_teachers as $st) {
        $tid = (int)$st['id'];
        $depts = [$st['teacher_department']];
        if (isset($teacher_sec_depts[$tid])) {
            $depts = array_unique(array_merge($depts, $teacher_sec_depts[$tid]));
        }
        $teacher_depts_map[$tid] = array_values(array_filter($depts));
    }
}

// Unscheduled teachers are only shown in the modal dropdown, not in the table

// Load acknowledgment data for current semester/year
$ack_map = [];
try {
    if ($is_leader) {
        $ack_query = "SELECT teacher_id, department, acknowledged_at, signature FROM observation_plan_acknowledgments WHERE academic_year = :ay AND semester = :sem";
        $ack_stmt = $db->prepare($ack_query);
        $ack_stmt->bindParam(':ay', $academic_year);
        $ack_stmt->bindParam(':sem', $semester);
    } else {
        $ack_query = "SELECT teacher_id, department, acknowledged_at, signature FROM observation_plan_acknowledgments WHERE academic_year = :ay AND semester = :sem AND (department = :dept OR department IS NULL)";
        $ack_stmt = $db->prepare($ack_query);
        $ack_stmt->bindParam(':ay', $academic_year);
        $ack_stmt->bindParam(':sem', $semester);
        $ack_stmt->bindParam(':dept', $raw_department);
    }
    $ack_stmt->execute();
    while ($ack_row = $ack_stmt->fetch(PDO::FETCH_ASSOC)) {
        $ack_map[$ack_row['teacher_id']] = $ack_row;
    }
} catch (Exception $e) {
    // table may not exist yet
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
            /* Badges render as plain text in print */
            .badge {
                background: none !important;
                color: #000 !important;
                padding: 0 !important;
                font-size: 0.85rem !important;
                font-style: italic;
                border: none !important;
            }
            /* Ensure all table cells have borders in print */
            .plan-table,
            .plan-table th,
            .plan-table td {
                border: 1.5px solid #000 !important;
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
                <?php if ($view_mode === 'plan'): ?>
                <div class="no-print">
                    <button class="btn btn-primary" onclick="openPrintPlan()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
                <?php endif; ?>
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
                        <?php if ($is_leader): ?>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Department</label>
                            <select name="department" class="form-select">
                                <option value="" <?php echo $raw_department === '' ? 'selected' : ''; ?>>All Departments</option>
                                <?php foreach($all_departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $raw_department === $dept ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if ($has_teacher_record && !$is_leader): ?>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">View</label>
                            <select name="view" class="form-select">
                                <option value="plan" <?php echo $view_mode === 'plan' ? 'selected' : ''; ?>>Observation Plan</option>
                                <option value="my_observation" <?php echo $view_mode === 'my_observation' ? 'selected' : ''; ?>>My Observation</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-<?php echo $has_teacher_record ? '2' : '3'; ?>">
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
                        <div class="col-md-3">
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
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Printable Observation Plan -->
            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($view_mode === 'my_observation' && $has_teacher_record): ?>
            <!-- My Observation View -->
            <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08);">
                <div class="text-center mb-3">
                    <h5 class="fw-bold">My Observation Plan</h5>
                    <p class="text-muted mb-1"><?php echo htmlspecialchars($department_display); ?></p>
                    <p class="text-muted"><?php echo htmlspecialchars($semester); ?> Semester SY <?php echo htmlspecialchars($academic_year); ?></p>
                </div>

                <?php
                $my_has_schedule = !empty($my_teacher_data['evaluation_schedule']);
                $my_has_matching = $my_has_schedule && ($my_teacher_data['evaluation_semester'] === $semester || empty($my_teacher_data['evaluation_semester']));

                // Apply month filter to My Observation view
                $my_show_upcoming = $my_has_matching;
                if (!empty($filter_month) && $my_has_matching) {
                    $sched_month = (int)date('n', strtotime($my_teacher_data['evaluation_schedule']));
                    if ($sched_month != (int)$filter_month) $my_show_upcoming = false;
                }
                if (!empty($filter_month)) {
                    $my_evaluations = array_filter($my_evaluations, function($ev) use ($filter_month) {
                        $date = $ev['observation_date'] ?? '';
                        if (empty($date)) return false;
                        return (int)date('n', strtotime($date)) == (int)$filter_month;
                    });
                    $my_evaluations = array_values($my_evaluations);
                }

                // Count unsigned items
                $unsigned_count = 0;
                if ($my_show_upcoming && !isset($my_signed_map['upcoming'])) $unsigned_count++;
                foreach ($my_evaluations as $ev) {
                    if (!isset($my_signed_map[(int)$ev['id']])) $unsigned_count++;
                }
                $all_signed = ($unsigned_count === 0) && ($my_show_upcoming || count($my_evaluations) > 0);
                ?>

                <?php if ($my_show_upcoming || count($my_evaluations) > 0): ?>

                <div class="mb-3 d-flex justify-content-end no-print">
                    <button class="btn btn-primary" id="myObsSignToggleBtn" disabled onclick="toggleMyObsSignPanel()">
                        <i class="fas fa-signature me-1"></i>Sign <span id="myObsSignBadge" class="badge bg-light text-dark ms-1" style="display:none;">0</span>
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="plan-table" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:50px;">
                                    <i class="fas fa-check-square"></i>
                                </th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;">Semester</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;">Focus of Observation</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;">Date</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;">Day &amp; Time</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;"><?php echo in_array($raw_department, ['JHS', 'ELEM']) ? 'Grade Level/Section' : 'Subject Area'; ?></th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;"><?php echo in_array($raw_department, ['JHS', 'ELEM']) ? 'Subject of Instruction' : 'Subject'; ?></th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;">Room</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;">Observers</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($my_show_upcoming): ?>
                            <?php
                                $focus_raw = $my_teacher_data['evaluation_focus'] ?? '';
                                $focus_arr = [];
                                if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
                                $focus_display = array_map(function($f) use ($focus_labels_my) { return $focus_labels_my[$f] ?? $f; }, $focus_arr);
                                $ts = strtotime($my_teacher_data['evaluation_schedule']);
                                $upcoming_signed = isset($my_signed_map['upcoming']);
                            ?>
                            <tr>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <?php if ($upcoming_signed): ?>
                                        <i class="fas fa-check-circle text-success" title="Signed on <?php echo date('M d, Y g:i A', strtotime($my_signed_map['upcoming']['acknowledged_at'])); ?>"></i>
                                    <?php else: ?>
                                        <input type="checkbox" class="form-check-input sign-item-check" value="upcoming" style="width:20px;height:20px;">
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars(($my_teacher_data['evaluation_semester'] ?? '') . ' Semester'); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $focus_display)); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo date('M d, Y', $ts); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo date('D', $ts) . '<br>' . date('g:i A', $ts); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($my_teacher_data['evaluation_subject_area'] ?? ''); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($my_teacher_data['evaluation_subject'] ?? ''); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($my_teacher_data['evaluation_room'] ?? ''); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $my_observer_names)); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <?php if ($upcoming_signed): ?>
                                        <span class="badge bg-success">Signed</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Upcoming</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <?php foreach ($my_evaluations as $ev): ?>
                            <?php
                                $ev_focus_raw = $ev['evaluation_focus'] ?? '';
                                $ev_focus_arr = [];
                                if ($ev_focus_raw) { try { $ev_focus_arr = json_decode($ev_focus_raw, true) ?: []; } catch (\Exception $e) {} }
                                $ev_focus_display = array_map(function($f) use ($focus_labels_my) { return $focus_labels_my[$f] ?? $f; }, $ev_focus_arr);
                                $ev_signed = isset($my_signed_map[(int)$ev['id']]);
                            ?>
                            <tr>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <?php if ($ev_signed): ?>
                                        <i class="fas fa-check-circle text-success" title="Signed on <?php echo date('M d, Y g:i A', strtotime($my_signed_map[(int)$ev['id']]['acknowledged_at'])); ?>"></i>
                                    <?php else: ?>
                                        <input type="checkbox" class="form-check-input sign-item-check" value="<?php echo (int)$ev['id']; ?>" style="width:20px;height:20px;">
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars(($ev['semester'] ?? '') . ' Semester'); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $ev_focus_display)); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo !empty($ev['observation_date']) ? date('M d, Y', strtotime($ev['observation_date'])) : ''; ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo !empty($ev['observation_date']) ? date('D', strtotime($ev['observation_date'])) : ''; ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($ev['subject_area'] ?? ''); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($ev['subject_observed'] ?? ''); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($ev['observation_room'] ?? ''); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars($ev['evaluator_name'] ?? ''); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
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
                <div id="myObsSignPanel" style="display:none;" class="mt-3">
                    <div style="background:#fff3e0;border:2px solid #ff9800;border-radius:10px;padding:20px;text-align:center;">
                        <h5>Draw Your Signature</h5>
                        <p class="text-muted small" id="myObsSelectedCount">0 schedule(s) selected</p>
                        <div class="mb-3" style="display:inline-block;">
                            <canvas id="myObsSigCanvas" width="400" height="150" style="border: 2px solid #333; border-radius: 8px; background: #fff; cursor: crosshair;"></canvas>
                            <div class="mt-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearMyObsSig()"><i class="fas fa-eraser me-1"></i>Clear</button>
                            </div>
                        </div>
                        <form method="POST" id="myObsSigForm">
                            <input type="hidden" name="action" value="sign_plan">
                            <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                            <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                            <input type="hidden" name="signature_data" id="myObsSigData">
                            <div id="myObsSignedItemsContainer"></div>
                            <button type="submit" class="btn btn-success btn-lg" id="myObsSignBtn" onclick="return submitMyObsSig();">
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

            <?php else: ?>
            <!-- Normal Observation Plan View -->
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

                <!-- Action Buttons -->
                <div class="mb-3 d-flex justify-content-end align-items-center gap-2 no-print">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#scheduleModal" onclick="openScheduleModal()">
                        <i class="fas fa-calendar-plus me-1"></i>Set Schedule
                    </button>
                    <button class="btn btn-primary" id="bulkRescheduleBtn" disabled onclick="openRescheduleModal()">
                        <i class="fas fa-redo me-1"></i>Reschedule
                    </button>
                    <?php if ($is_leader): ?>
                    <button class="btn btn-success" id="joinObserverBtn" disabled onclick="joinAsObserver()">
                        <i class="fas fa-user-plus me-1"></i>Accept as Observer
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-outline-danger" id="bulkCancelBtn" disabled onclick="cancelSelectedSchedules()">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                </div>

                <!-- Observation Plan Table -->
                <div class="table-responsive">
                    <table class="plan-table">
                        <thead>
                            <tr>
                                <th style="width: 14%;">Teacher</th>
                                <th style="width: 6%;">Semester</th>
                                <th style="width: 12%;">Focus of Observation</th>
                                <th style="width: 8%;">Date</th>
                                <th style="width: 7%;">Day &amp; Time</th>
                                <th style="width: 8%;" id="th_subject_area"><?php echo in_array($raw_department, ['JHS', 'ELEM']) ? 'Grade Level/Section' : 'Subject Area'; ?></th>
                                <th style="width: 9%;" id="th_subject"><?php echo in_array($raw_department, ['JHS', 'ELEM']) ? 'Subject of Instruction' : 'Subject'; ?></th>
                                <th style="width: 5%;">Room</th>
                                <th style="width: 10%;">Name of Observers</th>
                                <th style="width: 6%;">Teacher Signature</th>
                                <th style="width: 7%; min-width: 60px;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($teachers_list) > 0): ?>
                                <?php $counter = 1; foreach ($teachers_list as $t): ?>
                                <?php $tid = $t['id']; $sd = $schedule_data[$tid] ?? []; $has_schedule = !empty($t['evaluation_schedule']); $is_done = $eval_data[$tid]['done'] ?? false; ?>
                                <tr>
                                    <td>
                                        <?php if ($has_schedule && !$is_done): ?>
                                            <input type="checkbox" class="form-check-input reschedule-check no-print" value="<?php echo (int)$tid; ?>" style="width:16px;height:16px;cursor:pointer;margin-right:6px;vertical-align:middle;">
                                        <?php endif; ?>
                                        <?php if ($is_leader && $has_schedule && !$is_done): ?>
                                            <?php $is_opted = isset($leader_opted_teachers[$tid]); ?>
                                            <input type="checkbox" class="form-check-input observer-opt-check no-print" value="<?php echo (int)$tid; ?>" <?php echo $is_opted ? 'checked' : ''; ?> data-opted="<?php echo $is_opted ? '1' : '0'; ?>" style="width:16px;height:16px;cursor:pointer;margin-right:4px;vertical-align:middle;accent-color:green;" title="<?php echo $is_opted ? 'You are an observer' : 'Check to join as observer'; ?>">
                                        <?php endif; ?>
                                        <?php echo $counter++ . '. ' . htmlspecialchars($t['name']); ?>
                                    </td>
                                    <td class="text-center"><?php $sem = $sd['semester'] ?? ''; echo htmlspecialchars($sem ? $sem . ' Semester' : ''); ?></td>
                                    <td style="font-size:0.8rem;"><?php echo htmlspecialchars($sd['focus'] ?? ''); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $date = $eval_data[$tid]['date'] ?? '';
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
                                        $eval_id = $eval_data[$tid]['eval_id'] ?? null;
                                        if (!empty($subj_area_val)): ?>
                                            <span><?php echo htmlspecialchars($subj_area_val); ?></span>
                                        <?php elseif ($eval_id): ?>
                                            <input type="text" class="form-control form-control-sm inline-edit no-print" data-eval-id="<?php echo (int)$eval_id; ?>" data-field="subject_area" placeholder="<?php echo in_array($raw_department, ['JHS', 'ELEM']) ? 'Enter grade level/section' : 'Enter subject area'; ?>" style="min-width:90px;font-size:0.8rem;">
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
                                        $observers = $observer_map[$tid] ?? [];
                                        echo htmlspecialchars(implode(', ', $observers));
                                        ?>
                                    </td>
                                    <td class="text-center" style="font-size:0.8rem;">
                                        <?php 
                                        $ack = $ack_map[$tid] ?? null;
                                        $faculty_sig = $eval_data[$tid]['faculty_signature'] ?? '';
                                        if ($ack && !empty($ack['signature'])): ?>
                                            <img src="<?php echo $ack['signature']; ?>" alt="Signature" style="max-height: 30px; max-width: 60px;" title="Signed on <?php echo htmlspecialchars(date('M d, Y g:ia', strtotime($ack['acknowledged_at']))); ?>">
                                        <?php elseif ($ack): ?>
                                            <span class="text-success no-print" title="Signed on <?php echo htmlspecialchars(date('M d, Y g:ia', strtotime($ack['acknowledged_at']))); ?>">
                                                <i class="fas fa-check-circle"></i>
                                            </span>
                                            <span class="print-only">Signed</span>
                                        <?php elseif (!empty($faculty_sig)): ?>
                                            <img src="<?php echo $faculty_sig; ?>" alt="Signature" style="max-height: 30px; max-width: 60px;" title="Faculty signature from evaluation">
                                        <?php elseif ($has_schedule): ?>
                                            <span class="text-warning no-print"><i class="fas fa-clock"></i> Pending</span>
                                            <span class="print-only">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" style="font-size:0.8rem;">
                                        <?php 
                                        if ($eval_data[$tid]['done'] ?? false) {
                                            echo '<span class="badge bg-success">Done</span>';
                                        } elseif ($has_schedule) {
                                            echo '<span class="badge bg-info">Scheduled</span>';
                                        } else {
                                            echo '<span class="badge bg-secondary">Not set</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">No teachers found for this semester.</td>
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
            <?php endif; ?>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <!-- Schedule Modal -->
    <div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-check me-2"></i>Set Evaluation Schedule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_schedule">
                        <input type="hidden" name="is_reschedule" id="modal_is_reschedule" value="">
                        <input type="hidden" name="reschedule_teacher_id" id="reschedule_teacher_id" value="">
                        <!-- Hidden mirror inputs for disabled fields in reschedule mode -->
                        <input type="hidden" id="mirror_semester" name="" value="">
                        <input type="hidden" id="mirror_form_type" name="" value="">
                        <div id="mirror_focus_container"></div>
                        <input type="hidden" name="filter_semester" value="<?php echo htmlspecialchars($semester); ?>">
                        <input type="hidden" name="filter_academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">


                        <!-- Teacher Selection -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Teacher <span class="text-danger">*</span></label>
                            <select class="form-select" name="teacher_id" id="schedule_teacher_id" required>
                                <option value="">-- Choose a teacher --</option>
                                <?php foreach ($schedulable_teachers as $st): ?>
                                <option value="<?php echo (int)$st['id']; ?>"
                                    data-schedule="<?php echo htmlspecialchars($st['evaluation_schedule'] ?? '', ENT_QUOTES); ?>"
                                    data-room="<?php echo htmlspecialchars($st['evaluation_room'] ?? '', ENT_QUOTES); ?>"
                                    data-focus="<?php echo htmlspecialchars($st['evaluation_focus'] ?? '', ENT_QUOTES); ?>"
                                    data-subject-area="<?php echo htmlspecialchars($st['evaluation_subject_area'] ?? '', ENT_QUOTES); ?>"
                                    data-subject="<?php echo htmlspecialchars($st['evaluation_subject'] ?? '', ENT_QUOTES); ?>"
                                    data-semester="<?php echo htmlspecialchars($st['evaluation_semester'] ?? '', ENT_QUOTES); ?>"
                                    data-form-type="<?php echo htmlspecialchars($st['evaluation_form_type'] ?? 'iso', ENT_QUOTES); ?>"
                                    <?php echo !empty($st['evaluation_schedule']) ? 'data-has-schedule="1"' : ''; ?>
                                    <?php if ($is_leader && isset($teacher_depts_map[(int)$st['id']])): ?>
                                    data-departments="<?php echo htmlspecialchars(json_encode($teacher_depts_map[(int)$st['id']]), ENT_QUOTES); ?>"
                                    <?php endif; ?>
                                >
                                    <?php echo htmlspecialchars($st['name']); ?>
                                    <?php echo !empty($st['evaluation_schedule']) ? ' (Scheduled)' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($is_leader): ?>
                        <!-- Department for evaluation (president/VP only) -->
                        <div class="mb-3" id="modal_department_group">
                            <label class="form-label fw-bold">Department <span class="text-danger">*</span></label>
                            <select class="form-select" name="scheduled_department" id="modal_scheduled_department" required>
                                <option value="">-- Select department --</option>
                            </select>
                            <small class="text-muted">Select which department this evaluation is for.</small>
                        </div>
                        <?php endif; ?>

                        <div class="alert alert-info py-2">
                            <i class="fas fa-circle-info me-1"></i>
                            <strong>This schedule unlocks evaluation access.</strong>
                            <small class="d-block">All fields are required so evaluators can plan the observation.</small>
                        </div>

                        <!-- Form Type -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Evaluation Form <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="evaluation_form_type" value="iso" id="modal_form_iso" required checked>
                                    <label class="form-check-label" for="modal_form_iso"><i class="fas fa-file-alt me-1"></i>ISO</label>
                                </div>
                                <?php if (($_SESSION['department'] ?? '') === 'JHS' || $is_leader): ?>
                                <div class="form-check" id="modal_peac_radio_wrap">
                                    <input class="form-check-input" type="radio" name="evaluation_form_type" value="peac" id="modal_form_peac">
                                    <label class="form-check-label" for="modal_form_peac"><i class="fas fa-clipboard-check me-1"></i>PEAC</label>
                                </div>
                                <div class="form-check" id="modal_both_radio_wrap">
                                    <input class="form-check-input" type="radio" name="evaluation_form_type" value="both" id="modal_form_both">
                                    <label class="form-check-label" for="modal_form_both"><i class="fas fa-layer-group me-1"></i>Both</label>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Semester -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Semester <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="evaluation_semester" value="1st" id="modal_semester_1st" required>
                                    <label class="form-check-label" for="modal_semester_1st">1st Semester</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="evaluation_semester" value="2nd" id="modal_semester_2nd">
                                    <label class="form-check-label" for="modal_semester_2nd">2nd Semester</label>
                                </div>
                            </div>
                        </div>

                        <!-- Focus of Observation (ISO) -->
                        <div class="mb-3" id="focusObservationGroup">
                            <label class="form-label fw-bold">Focus of Observation <span class="text-danger">*</span></label>
                            <div id="isoFocusCheckboxes">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="communications" id="modal_focus_communications">
                                    <label class="form-check-label" for="modal_focus_communications">Communication Competence</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="management" id="modal_focus_management">
                                    <label class="form-check-label" for="modal_focus_management">Management and Presentation of the Lesson</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="assessment" id="modal_focus_assessment">
                                    <label class="form-check-label" for="modal_focus_assessment">Assessment of Students' Learning</label>
                                </div>
                            </div>
                            <div id="peacFocusCheckboxes" style="display:none;">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="teacher_actions" id="modal_focus_teacher_actions">
                                    <label class="form-check-label" for="modal_focus_teacher_actions">Teacher Actions</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="evaluation_focus[]" value="student_learning_actions" id="modal_focus_student_learning">
                                    <label class="form-check-label" for="modal_focus_student_learning">Student Learning Actions</label>
                                </div>
                            </div>
                        </div>

                        <!-- Date & Time -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Evaluation Schedule <span class="text-danger">*</span></label>
                            <input type="hidden" id="modal_evaluation_schedule" name="evaluation_schedule" required>
                            <div class="row g-2">
                                <div class="col-7">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                        <input type="date" class="form-control" id="modal_evaluation_date" required>
                                    </div>
                                </div>
                                <div class="col-5">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                        <input type="time" class="form-control" id="modal_evaluation_time" step="900" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Subject Area & Subject (labels change for JHS/ELEM) -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold" id="modal_label_subject_area">Subject Area <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="modal_evaluation_subject_area" name="evaluation_subject_area" required placeholder="e.g., Social Sciences, English">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold" id="modal_label_subject">Subject <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="modal_evaluation_subject" name="evaluation_subject" required placeholder="e.g., GEC 9 – Ethics">
                            </div>
                        </div>

                        <!-- Room -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Classroom/Room <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="modal_evaluation_room" name="evaluation_room" required placeholder="e.g., Room 101, Laboratory B">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
function openPrintPlan() {
    const params = new URLSearchParams(window.location.search);
    params.set('auto_print', '1');
    window.open('observation_plan_print.php?' + params.toString(), '_blank');
}

function setModalRescheduleMode(enabled) {
    // Hidden flag
    document.getElementById('modal_is_reschedule').value = enabled ? '1' : '';
    document.getElementById('reschedule_teacher_id').value = enabled ? (document.getElementById('schedule_teacher_id').value || '') : '';

    // Update modal title and submit button
    var titleEl = document.querySelector('#scheduleModal .modal-title');
    var submitBtn = document.querySelector('#scheduleModal .modal-footer .btn-primary');
    if (enabled) {
        if (titleEl) titleEl.innerHTML = '<i class="fas fa-redo me-2"></i>Reschedule Evaluation';
        if (submitBtn) submitBtn.innerHTML = '<i class="fas fa-save me-1"></i>Save New Schedule';
    } else {
        if (titleEl) titleEl.innerHTML = '<i class="fas fa-calendar-check me-2"></i>Set Evaluation Schedule';
        if (submitBtn) submitBtn.innerHTML = '<i class="fas fa-save me-1"></i>Save Schedule';
    }
}

function openScheduleModal() {
    // Reset form
    const select = document.getElementById('schedule_teacher_id');
    select.value = '';
    document.getElementById('modal_evaluation_room').value = '';
    document.getElementById('modal_evaluation_subject_area').value = '';
    document.getElementById('modal_evaluation_subject').value = '';
    document.getElementById('modal_evaluation_date').value = '';
    document.getElementById('modal_evaluation_time').value = '';
    document.getElementById('modal_focus_communications').checked = false;
    document.getElementById('modal_focus_management').checked = false;
    document.getElementById('modal_focus_assessment').checked = false;
    document.getElementById('modal_focus_teacher_actions').checked = false;
    document.getElementById('modal_focus_student_learning').checked = false;
    document.getElementById('modal_form_iso').checked = true;
    if (document.getElementById('modal_form_peac')) document.getElementById('modal_form_peac').checked = false;
    if (document.getElementById('modal_form_both')) document.getElementById('modal_form_both').checked = false;
    // Show ISO focus by default
    document.getElementById('focusObservationGroup').style.display = '';
    document.getElementById('isoFocusCheckboxes').style.display = '';
    document.getElementById('peacFocusCheckboxes').style.display = 'none';

    // Reset department dropdown for leaders
    const deptSelect = document.getElementById('modal_scheduled_department');
    if (deptSelect) {
        deptSelect.innerHTML = '<option value="">-- Select department --</option>';
    }
    // Hide PEAC/Both for leaders until department is selected
    updateFormTypeVisibility();
    updateSubjectLabels();

    // Default semester to filter
    const filterSem = '<?php echo htmlspecialchars($semester, ENT_QUOTES); ?>';
    document.getElementById('modal_semester_1st').checked = (filterSem === '1st');
    document.getElementById('modal_semester_2nd').checked = (filterSem === '2nd');

    // Ensure normal mode (not reschedule)
    setModalRescheduleMode(false);
}

// Toggle PEAC/Both radio visibility based on selected department (for leaders)
function updateFormTypeVisibility() {
    const deptSelect = document.getElementById('modal_scheduled_department');
    const peacWrap = document.getElementById('modal_peac_radio_wrap');
    const bothWrap = document.getElementById('modal_both_radio_wrap');
    if (!deptSelect || !peacWrap || !bothWrap) return;
    const selectedDept = deptSelect.value;
    if (selectedDept === 'JHS' || selectedDept === '') {
        peacWrap.style.display = '';
        bothWrap.style.display = '';
    } else {
        peacWrap.style.display = 'none';
        bothWrap.style.display = 'none';
        // Reset to ISO if PEAC/Both was selected
        const checkedType = document.querySelector('input[name="evaluation_form_type"]:checked');
        if (checkedType && (checkedType.value === 'peac' || checkedType.value === 'both')) {
            document.getElementById('modal_form_iso').checked = true;
            document.getElementById('isoFocusCheckboxes').style.display = '';
            document.getElementById('peacFocusCheckboxes').style.display = 'none';
        }
    }
}

// Update Subject Area / Subject labels based on department (JHS/ELEM get Grade Level/Section & Subject of Instruction)
function updateSubjectLabels() {
    var dept = '';
    var deptSelect = document.getElementById('modal_scheduled_department');
    if (deptSelect && deptSelect.value) {
        dept = deptSelect.value;
    } else {
        dept = '<?php echo addslashes($raw_department); ?>';
    }
    var isBasicEd = (dept === 'JHS' || dept === 'ELEM');
    var labelArea = document.getElementById('modal_label_subject_area');
    var labelSubj = document.getElementById('modal_label_subject');
    var inputArea = document.getElementById('modal_evaluation_subject_area');
    var inputSubj = document.getElementById('modal_evaluation_subject');
    if (labelArea) labelArea.innerHTML = isBasicEd ? 'Grade Level/Section <span class="text-danger">*</span>' : 'Subject Area <span class="text-danger">*</span>';
    if (labelSubj) labelSubj.innerHTML = isBasicEd ? 'Subject of Instruction <span class="text-danger">*</span>' : 'Subject <span class="text-danger">*</span>';
    if (inputArea) inputArea.placeholder = isBasicEd ? 'e.g., Grade 7 - Section A' : 'e.g., Social Sciences, English';
    if (inputSubj) inputSubj.placeholder = isBasicEd ? 'e.g., Mathematics, Science' : 'e.g., GEC 9 \u2013 Ethics';
    // Also update table headers if visible
    var thArea = document.getElementById('th_subject_area');
    var thSubj = document.getElementById('th_subject');
    if (thArea) thArea.textContent = isBasicEd ? 'Grade Level/Section' : 'Subject Area';
    if (thSubj) thSubj.textContent = isBasicEd ? 'Subject of Instruction' : 'Subject';
}

function cancelSelectedSchedules() {
    var checked = document.querySelectorAll('.reschedule-check:checked');
    if (checked.length === 0) return;
    var count = checked.length;
    if (!confirm('Cancel schedule for ' + count + ' selected teacher(s)?')) return;

    // Submit one form per selected teacher sequentially via hidden form
    var ids = [];
    checked.forEach(function(cb) { ids.push(cb.value); });

    // Create a hidden form and submit for each teacher
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    var actionInput = document.createElement('input');
    actionInput.name = 'action';
    actionInput.value = 'cancel_schedule';
    form.appendChild(actionInput);
    var teacherInput = document.createElement('input');
    teacherInput.name = 'teacher_ids';
    teacherInput.value = JSON.stringify(ids);
    form.appendChild(teacherInput);
    document.body.appendChild(form);
    form.submit();
}

function openRescheduleModal() {
    var checked = document.querySelectorAll('.reschedule-check:checked');
    if (checked.length === 0) return;
    if (checked.length > 1) {
        alert('Please select only one teacher at a time to reschedule.');
        return;
    }
    var teacherId = checked[0].value;
    var select = document.getElementById('schedule_teacher_id');

    // Select the teacher in dropdown
    select.value = teacherId;

    // Trigger change to fill in existing data
    select.dispatchEvent(new Event('change'));

    // Set reschedule mode (read-only except date/time)
    setModalRescheduleMode(true);

    // Clear only date/time so evaluator must pick new ones
    document.getElementById('modal_evaluation_date').value = '';
    document.getElementById('modal_evaluation_time').value = '';

    // Open the modal
    var modal = new bootstrap.Modal(document.getElementById('scheduleModal'));
    modal.show();
}

// When a teacher is selected from dropdown, populate their existing schedule data
document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('schedule_teacher_id');
    if (select) {
        select.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) return;

            const schedule = opt.dataset.schedule || '';
            const room = opt.dataset.room || '';
            const focus = opt.dataset.focus || '';
            const subjectArea = opt.dataset.subjectArea || '';
            const subject = opt.dataset.subject || '';
            const semester = opt.dataset.semester || '';
            const formType = opt.dataset.formType || 'iso';

            document.getElementById('modal_evaluation_room').value = room;
            document.getElementById('modal_evaluation_subject_area').value = subjectArea;
            document.getElementById('modal_evaluation_subject').value = subject;

            // Set form type radio
            document.getElementById('modal_form_iso').checked = (formType === 'iso');
            document.getElementById('modal_form_peac').checked = (formType === 'peac');
            document.getElementById('modal_form_both').checked = (formType === 'both');
            // Toggle focus visibility
            document.getElementById('focusObservationGroup').style.display = '';
            document.getElementById('isoFocusCheckboxes').style.display = (formType === 'peac') ? 'none' : '';
            document.getElementById('peacFocusCheckboxes').style.display = (formType === 'iso') ? 'none' : (formType === 'peac' || formType === 'both') ? '' : 'none';

            // Set semester radio
            if (semester) {
                document.getElementById('modal_semester_1st').checked = (semester === '1st');
                document.getElementById('modal_semester_2nd').checked = (semester === '2nd');
            }

            // Set focus checkboxes
            document.getElementById('modal_focus_communications').checked = false;
            document.getElementById('modal_focus_management').checked = false;
            document.getElementById('modal_focus_assessment').checked = false;
            document.getElementById('modal_focus_teacher_actions').checked = false;
            document.getElementById('modal_focus_student_learning').checked = false;
            if (focus) {
                try {
                    const focusArr = JSON.parse(focus);
                    if (Array.isArray(focusArr)) {
                        focusArr.forEach(f => {
                            const el = document.getElementById('modal_focus_' + f);
                            if (el) el.checked = true;
                        });
                    }
                } catch(e) {}
            }

            // Set date & time
            const dateInput = document.getElementById('modal_evaluation_date');
            const timeInput = document.getElementById('modal_evaluation_time');
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

            // Populate department selector for leaders
            const deptSelect = document.getElementById('modal_scheduled_department');
            if (deptSelect) {
                deptSelect.innerHTML = '<option value="">-- Select department --</option>';
                const deptsJson = opt.dataset.departments || '[]';
                try {
                    const depts = JSON.parse(deptsJson);
                    depts.forEach(d => {
                        const o = document.createElement('option');
                        o.value = d;
                        o.textContent = d;
                        deptSelect.appendChild(o);
                    });
                    // Auto-select if only one department
                    if (depts.length === 1) {
                        deptSelect.value = depts[0];
                    }
                } catch(e) {}
                // Update PEAC/Both visibility based on department
                updateFormTypeVisibility();
                updateSubjectLabels();
            }
        });
    }

    // Department change handler for leaders: toggle PEAC/Both visibility
    const deptSelect = document.getElementById('modal_scheduled_department');
    if (deptSelect) {
        deptSelect.addEventListener('change', function() {
            updateFormTypeVisibility();
            updateSubjectLabels();
        });
    }
});

// Combine date + time into hidden field on submit
document.addEventListener('DOMContentLoaded', () => {
    // Toggle Focus of Observation based on form type selection
    document.querySelectorAll('input[name="evaluation_form_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const isoFocus = document.getElementById('isoFocusCheckboxes');
            const peacFocus = document.getElementById('peacFocusCheckboxes');
            const focusGroup = document.getElementById('focusObservationGroup');
            focusGroup.style.display = '';
            if (this.value === 'peac') {
                isoFocus.style.display = 'none';
                peacFocus.style.display = '';
                // Uncheck ISO focus
                document.getElementById('modal_focus_communications').checked = false;
                document.getElementById('modal_focus_management').checked = false;
                document.getElementById('modal_focus_assessment').checked = false;
            } else if (this.value === 'iso') {
                isoFocus.style.display = '';
                peacFocus.style.display = 'none';
                // Uncheck PEAC focus
                document.getElementById('modal_focus_teacher_actions').checked = false;
                document.getElementById('modal_focus_student_learning').checked = false;
            } else {
                // Both - show both sets
                isoFocus.style.display = '';
                peacFocus.style.display = '';
            }
        });
    });

    const scheduleForm = document.querySelector('#scheduleModal form');
    if (scheduleForm) {
        scheduleForm.addEventListener('submit', (e) => {
            // Validate department selection for leaders
            const deptSelect = document.getElementById('modal_scheduled_department');
            if (deptSelect && !deptSelect.value) {
                e.preventDefault();
                alert('Please select a department.');
                return false;
            }
            // Only require focus for ISO and Both
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
            const dateVal = document.getElementById('modal_evaluation_date')?.value || '';
            const timeVal = document.getElementById('modal_evaluation_time')?.value || '';
            document.getElementById('modal_evaluation_schedule').value = (dateVal && timeVal) ? `${dateVal} ${timeVal}:00` : '';
        });
    }
});

// Reschedule checkboxes
(function() {
    var checkAll = document.getElementById('checkAllTeachers');
    var rescheduleBtn = document.getElementById('bulkRescheduleBtn');
    var cancelBtn = document.getElementById('bulkCancelBtn');
    var countBadge = document.getElementById('rescheduleCount');

    function updateRescheduleState() {
        var checked = document.querySelectorAll('.reschedule-check:checked');
        var count = checked.length;
        if (rescheduleBtn) rescheduleBtn.disabled = (count === 0);
        if (cancelBtn) cancelBtn.disabled = (count === 0);
        if (countBadge) {
            countBadge.textContent = count;
            countBadge.style.display = count > 0 ? 'inline' : 'none';
        }
    }

    document.querySelectorAll('.reschedule-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            updateRescheduleState();
            // Update "check all" state
            if (checkAll) {
                var total = document.querySelectorAll('.reschedule-check').length;
                var checked = document.querySelectorAll('.reschedule-check:checked').length;
                checkAll.checked = (total > 0 && checked === total);
                checkAll.indeterminate = (checked > 0 && checked < total);
            }
        });
    });

    if (checkAll) {
        checkAll.addEventListener('change', function() {
            document.querySelectorAll('.reschedule-check').forEach(function(cb) {
                cb.checked = checkAll.checked;
            });
            updateRescheduleState();
        });
    }
})();

<?php if ($is_leader): ?>
// Observer opt-in checkboxes for President/VP
(function() {
    var joinBtn = document.getElementById('joinObserverBtn');

    function updateObserverBtnState() {
        var checks = document.querySelectorAll('.observer-opt-check');
        var toJoin = 0;
        checks.forEach(function(cb) {
            var wasOpted = cb.dataset.opted === '1';
            if (cb.checked && !wasOpted) toJoin++;
        });
        if (joinBtn) joinBtn.disabled = (toJoin === 0);
    }

    document.querySelectorAll('.observer-opt-check').forEach(function(cb) {
        cb.addEventListener('change', updateObserverBtnState);
    });
    updateObserverBtnState();
})();

function joinAsObserver() {
    var ids = [];
    document.querySelectorAll('.observer-opt-check').forEach(function(cb) {
        if (cb.checked && cb.dataset.opted !== '1') ids.push(parseInt(cb.value));
    });
    if (ids.length === 0) return;
    if (!confirm('Accept as observer/evaluator for ' + ids.length + ' teacher(s)? This will notify the teacher, dean/principal, and coordinators.')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;
    var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'join_observer'; form.appendChild(a);
    var t = document.createElement('input'); t.type = 'hidden'; t.name = 'teacher_ids'; t.value = JSON.stringify(ids); form.appendChild(t);
    document.body.appendChild(form);
    form.submit();
}
<?php endif; ?>


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

// My Observation Signature Canvas + Checklist
(function() {
    var canvas = null;
    var ctx = null;
    var drawing = false;
    var hasDrawn = false;
    var countEl = null;
    var container = null;
    var canvasInited = false;
    var toggleBtn = document.getElementById('myObsSignToggleBtn');
    var badgeEl = document.getElementById('myObsSignBadge');
    var panelEl = document.getElementById('myObsSignPanel');

    window.toggleMyObsSignPanel = function() {
        if (!panelEl) return;
        if (panelEl.style.display === 'none') {
            panelEl.style.display = '';
            initCanvas();
        } else {
            panelEl.style.display = 'none';
        }
    };

    function initCanvas() {
        if (canvasInited) return;
        canvas = document.getElementById('myObsSigCanvas');
        if (!canvas) return;
        canvasInited = true;
        ctx = canvas.getContext('2d');
        countEl = document.getElementById('myObsSelectedCount');
        container = document.getElementById('myObsSignedItemsContainer');

        canvas.addEventListener('mousedown', function(e) {
            drawing = true; ctx.beginPath(); ctx.moveTo(e.offsetX, e.offsetY);
        });
        canvas.addEventListener('mousemove', function(e) {
            if (!drawing) return; hasDrawn = true;
            ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#000';
            ctx.lineTo(e.offsetX, e.offsetY); ctx.stroke();
        });
        canvas.addEventListener('mouseup', function() { drawing = false; });
        canvas.addEventListener('mouseleave', function() { drawing = false; });
        canvas.addEventListener('touchstart', function(e) {
            e.preventDefault(); var rect = canvas.getBoundingClientRect(); var touch = e.touches[0];
            drawing = true; ctx.beginPath(); ctx.moveTo(touch.clientX - rect.left, touch.clientY - rect.top);
        });
        canvas.addEventListener('touchmove', function(e) {
            e.preventDefault(); if (!drawing) return; hasDrawn = true;
            var rect = canvas.getBoundingClientRect(); var touch = e.touches[0];
            ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#000';
            ctx.lineTo(touch.clientX - rect.left, touch.clientY - rect.top); ctx.stroke();
        });
        canvas.addEventListener('touchend', function() { drawing = false; });
        // Update hidden inputs now that container exists
        updateMyObsCheckboxState();
    }

    function updateMyObsCheckboxState() {
        var checks = document.querySelectorAll('.sign-item-check:checked');
        var count = checks.length;
        if (countEl) countEl.textContent = count + ' schedule(s) selected';
        if (container) {
            container.innerHTML = '';
            checks.forEach(function(cb) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'signed_items[]'; input.value = cb.value;
                container.appendChild(input);
            });
        }
        // Update top Sign button
        if (toggleBtn) {
            toggleBtn.disabled = (count === 0);
            if (count > 0) {
                badgeEl.textContent = count;
                badgeEl.style.display = '';
            } else {
                badgeEl.style.display = 'none';
                if (panelEl) panelEl.style.display = 'none';
            }
        }
    }

    document.querySelectorAll('.sign-item-check').forEach(function(cb) {
        cb.addEventListener('change', updateMyObsCheckboxState);
    });

    window.clearMyObsSig = function() {
        if (!canvas || !ctx) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasDrawn = false;
    };

    window.submitMyObsSig = function() {
        var checks = document.querySelectorAll('.sign-item-check:checked');
        if (checks.length === 0) {
            alert('Please select at least one schedule to sign.');
            return false;
        }
        if (!hasDrawn) {
            alert('Please draw your signature before submitting.');
            return false;
        }
        document.getElementById('myObsSigData').value = canvas.toDataURL('image/png');
        return true;
    };
})();
</script>
</body>
</html>
