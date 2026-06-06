<?php
session_start();

// Allow teachers and evaluators who are also teachers
$allowed_roles = ['teacher', 'dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/mailer.php';

$database = new Database();
$db = $database->getConnection();

function formatFocusItems($focusRaw, array $labels) {
    $focusRaw = trim((string)$focusRaw);
    if ($focusRaw === '') return [];

    $decoded = json_decode($focusRaw, true);
    if (is_string($decoded)) {
        $decodedAgain = json_decode($decoded, true);
        $decoded = is_array($decodedAgain) ? $decodedAgain : $decoded;
    }

    if (is_array($decoded)) {
        $items = $decoded;
    } else {
        $clean = str_replace(['[', ']', '"', "'"], '', $focusRaw);
        $items = preg_split('/\s*,\s*|\r\n|\r|\n/', $clean);
    }

    $display = [];
    foreach ($items as $item) {
        $key = trim((string)$item);
        if ($key === '') continue;
        $display[] = $labels[$key] ?? $key;
    }

    return array_values(array_unique($display));
}

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

function normalizeSemesterValue($value) {
    $v = strtolower(trim((string)$value));
    if ($v === '') return '';
    $v = str_replace('semester', '', $v);
    $v = preg_replace('/\s+/', '', $v);
    if ($v === '1st' || $v === 'first' || $v === '1') return '1st';
    if ($v === '2nd' || $v === 'second' || $v === '2') return '2nd';
    return '';
}

function semesterVariants($canonical) {
    if ($canonical !== '1st' && $canonical !== '2nd') return [];
    return [$canonical, $canonical . ' Semester'];
}

// Handle acknowledgment POST — per-schedule signing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'acknowledge') {
    $ack_semester = normalizeSemesterValue($_POST['semester'] ?? '');
    $ack_academic_year = trim($_POST['academic_year'] ?? '');
    $signed_items = $_POST['signed_items'] ?? [];

    if (in_array($ack_semester, ['1st', '2nd']) && !empty($ack_academic_year) && is_array($signed_items) && count($signed_items) > 0) {
        $ack_semester_variants = semesterVariants($ack_semester);
        $signature_data = $_POST['signature_data'] ?? null;
        if ($signature_data && !preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $signature_data)) {
            $signature_data = null;
        }
        $signed_count = 0;
        $updated_count = 0;
        $signed_eval_ids = [];
        $has_upcoming = false;
        foreach ($signed_items as $item) {
            $target_eval_ids = [];
            if ($item === 'upcoming') {
                // Strict mode bridge: when a teacher signs an "upcoming/current" row,
                // map it to concrete evaluation rows for the same schedule date if they exist.
                $schedDate = null;
                $schedStmt = $db->prepare("SELECT evaluation_schedule FROM teachers WHERE id = :tid LIMIT 1");
                $schedStmt->execute([':tid' => $teacher_id]);
                $schedRaw = trim((string)$schedStmt->fetchColumn());
                if ($schedRaw !== '' && strtotime($schedRaw) !== false) {
                    $schedDate = date('Y-m-d', strtotime($schedRaw));
                }
                if ($schedDate !== null) {
                    $evStmt = $db->prepare("SELECT id
                                            FROM evaluations
                                            WHERE teacher_id = :tid
                                              AND academic_year = :ay
                                              AND semester IN (:sem1, :sem2)
                                              AND observation_date IS NOT NULL
                                              AND DATE(observation_date) = :sdate");
                    $evStmt->execute([
                        ':tid' => $teacher_id,
                        ':ay' => $ack_academic_year,
                        ':sem1' => $ack_semester_variants[0],
                        ':sem2' => $ack_semester_variants[1],
                        ':sdate' => $schedDate
                    ]);
                    $target_eval_ids = array_map('intval', $evStmt->fetchAll(PDO::FETCH_COLUMN));
                }
                if (empty($target_eval_ids)) {
                    $target_eval_ids = [null];
                }
            } else {
                $target_eval_ids = [(int)$item];
            }
            foreach ($target_eval_ids as $eval_id) {
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
                $tdStmt->execute([':tid' => $teacher_id]);
                $tRow = $tdStmt->fetch(PDO::FETCH_ASSOC);
                $sign_dept = $tRow['department'] ?? null;
            }
            if ($eval_id === null) {
                $check = $db->prepare("SELECT id
                                       FROM observation_plan_acknowledgments
                                       WHERE teacher_id = :tid
                                         AND academic_year = :ay
                                         AND semester IN (:sem1, :sem2)
                                         AND evaluation_id IS NULL
                                         AND (department = :dept OR (department IS NULL AND :dept2 IS NULL))
                                       LIMIT 1");
                $check->execute([
                    ':tid' => $teacher_id,
                    ':ay' => $ack_academic_year,
                    ':sem1' => $ack_semester_variants[0],
                    ':sem2' => $ack_semester_variants[1],
                    ':dept' => $sign_dept,
                    ':dept2' => $sign_dept
                ]);
            } else {
                $check = $db->prepare("SELECT id
                                       FROM observation_plan_acknowledgments
                                       WHERE teacher_id = :tid
                                         AND academic_year = :ay
                                         AND semester IN (:sem1, :sem2)
                                         AND evaluation_id = :eid
                                       LIMIT 1");
                $check->execute([
                    ':tid' => $teacher_id,
                    ':ay' => $ack_academic_year,
                    ':sem1' => $ack_semester_variants[0],
                    ':sem2' => $ack_semester_variants[1],
                    ':eid' => $eval_id
                ]);
            }
            $existing_ack_id = (int)($check->fetchColumn() ?: 0);
            if ($existing_ack_id === 0) {
                $ins = $db->prepare("INSERT INTO observation_plan_acknowledgments (teacher_id, academic_year, semester, department, evaluation_id, acknowledged_at, signature) VALUES (:tid, :ay, :sem, :dept, :eid, NOW(), :sig)");
                $ins->execute([':tid' => $teacher_id, ':ay' => $ack_academic_year, ':sem' => $ack_semester, ':dept' => $sign_dept, ':eid' => $eval_id, ':sig' => $signature_data]);
                $signed_count++;
                if ($eval_id !== null) {
                    $signed_eval_ids[] = $eval_id;
                } else {
                    $has_upcoming = true;
                }
            } elseif ($signature_data !== null) {
                $upd = $db->prepare("UPDATE observation_plan_acknowledgments
                                     SET signature = :sig,
                                         acknowledged_at = NOW(),
                                         department = COALESCE(:dept, department)
                                     WHERE id = :id");
                $upd->execute([
                    ':sig' => $signature_data,
                    ':dept' => $sign_dept,
                    ':id' => $existing_ack_id
                ]);
                $updated_count++;
                if ($eval_id !== null) {
                    $signed_eval_ids[] = $eval_id;
                } else {
                    $has_upcoming = true;
                }
            }
            }
        }
        if (($signed_count + $updated_count) > 0) {
            $success_message = "Successfully signed " . ($signed_count + $updated_count) . " observation schedule(s).";
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
        } else {
            $success_message = "Selected schedules were already signed.";
        }
    } else {
        $error_message = "Please select at least one schedule to sign.";
    }

    // PRG: prevent browser Back/Refresh from resubmitting the signature form.
    $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? $ack_semester)
        . '&academic_year=' . urlencode($_GET['academic_year'] ?? $ack_academic_year);
    if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode((string)$_GET['department']);
    if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode((string)$_GET['month']);
    if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode((string)$_GET['status']);
    if (!empty($success_message)) $_SESSION['success'] = $success_message;
    if (!empty($error_message)) $_SESSION['error'] = $error_message;
    header("Location: $redirect");
    exit();
}

// Handle teacher reschedule request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_reschedule_my') {
    $req_semester = normalizeSemesterValue($_POST['semester'] ?? '');
    if ($req_semester === '') $req_semester = '1st';
    $req_semester_variants = semesterVariants($req_semester);
    $req_academic_year = trim((string)($_POST['academic_year'] ?? ''));
    $req_item = trim((string)($_POST['reschedule_item'] ?? ''));
    $req_reason = trim((string)($_POST['reschedule_reason'] ?? ''));
    $req_other_reason = trim((string)($_POST['reschedule_reason_other'] ?? ''));

    $allowed_reasons = ['emergency', 'conflict_schedule', 'others'];
    if (!in_array($req_reason, $allowed_reasons, true)) {
        $error_message = "Invalid reschedule reason.";
    } elseif ($req_reason === 'others' && $req_other_reason === '') {
        $error_message = 'Please provide details for "Others".';
    } elseif ($req_item === '') {
        $error_message = 'Please select a schedule to request reschedule.';
    } else {
        try {
            $tReqStmt = $db->prepare("SELECT id, name, department, scheduled_department, evaluation_schedule, evaluation_room, evaluation_subject, evaluation_subject_area FROM teachers WHERE id = :tid LIMIT 1");
            $tReqStmt->execute([':tid' => $teacher_id]);
            $tReq = $tReqStmt->fetch(PDO::FETCH_ASSOC);

            $teacher_name_req = $tReq['name'] ?? ($_SESSION['name'] ?? 'Teacher');
            $teacher_dept_req = trim((string)($tReq['scheduled_department'] ?? ''));
            if ($teacher_dept_req === '') $teacher_dept_req = trim((string)($tReq['department'] ?? ''));

            $req_sched = trim((string)($tReq['evaluation_schedule'] ?? ''));
            $req_room = trim((string)($tReq['evaluation_room'] ?? ''));
            $req_subject = trim((string)($tReq['evaluation_subject'] ?? ''));
            $req_subject_area = trim((string)($tReq['evaluation_subject_area'] ?? ''));
            $req_eval_id = null;

            if ($req_item !== 'upcoming') {
                $req_eval_id = (int)$req_item;
                if ($req_eval_id > 0) {
                    $eReqStmt = $db->prepare("SELECT e.observation_date, e.observation_time, e.observation_room, e.subject_observed, e.subject_area, u.department AS evaluator_department
                                              FROM evaluations e
                                              LEFT JOIN users u ON u.id = e.evaluator_id
                                              WHERE e.id = :eid AND e.teacher_id = :tid AND e.academic_year = :ay AND e.semester IN (:sem1, :sem2)
                                              LIMIT 1");
                    $eReqStmt->execute([
                        ':eid' => $req_eval_id,
                        ':tid' => $teacher_id,
                        ':ay' => $req_academic_year,
                        ':sem1' => $req_semester_variants[0],
                        ':sem2' => $req_semester_variants[1]
                    ]);
                    $eReq = $eReqStmt->fetch(PDO::FETCH_ASSOC);
                    if ($eReq) {
                        $od = trim((string)($eReq['observation_date'] ?? ''));
                        $ot = trim((string)($eReq['observation_time'] ?? ''));
                        if ($od !== '') {
                            $req_sched = $od . ($ot !== '' ? (' ' . $ot) : '');
                        }
                        $req_room = trim((string)($eReq['observation_room'] ?? $req_room));
                        $req_subject = trim((string)($eReq['subject_observed'] ?? $req_subject));
                        $req_subject_area = trim((string)($eReq['subject_area'] ?? $req_subject_area));
                        $evDept = trim((string)($eReq['evaluator_department'] ?? ''));
                        if ($evDept !== '') $teacher_dept_req = $evDept;
                    }
                }
            }

            $reason_label = $req_reason === 'emergency'
                ? 'Emergency'
                : ($req_reason === 'conflict_schedule' ? 'Conflict of Schedule' : 'Others');
            $reason_text = $reason_label . ($req_reason === 'others' ? (': ' . $req_other_reason) : '');

            // Notify all relevant observers:
            // - explicitly assigned observers for this teacher
            // - department leads (dean/principal/chairperson/coordinators) of owning department
            // - president/vice president (global)
            // Note: in-app notifications should not require an email address.
            $observerRows = [];

            // 1) Assigned observers
            $obsStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                     FROM teacher_assignments ta
                                     JOIN users u ON u.id = ta.evaluator_id
                                     WHERE ta.teacher_id = :tid
                                       AND u.status = 'active'");
            $obsStmt->execute([':tid' => (int)$teacher_id]);
            $observerRows = $obsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 2) Department leads (handle both code and full-name department values)
            if ($teacher_dept_req !== '') {
                $dept_alias_to_code = [
                    'College of Computing and Information Sciences' => 'CCIS',
                    'College of Business and Management' => 'CBM',
                    'College of Arts and Sciences' => 'CAS',
                    'College of Criminal Justice Education' => 'CCJE',
                    'College of Tourism and Hospitality Management' => 'CTHM',
                    'College of Teacher Education, Arts and Sciences' => 'CTEAS',
                    'College of Teacher Education and Arts and Sciences' => 'CTEAS',
                    'Elementary Department' => 'ELEM',
                    'Junior High School Department' => 'JHS',
                    'Senior High School Department' => 'SHS',
                ];
                $dept_code_to_alias = array_flip($dept_alias_to_code);
                $dept_variants = [$teacher_dept_req];
                if (isset($dept_alias_to_code[$teacher_dept_req])) {
                    $dept_variants[] = $dept_alias_to_code[$teacher_dept_req];
                } elseif (isset($dept_code_to_alias[$teacher_dept_req])) {
                    $dept_variants[] = $dept_code_to_alias[$teacher_dept_req];
                }
                $dept_variants = array_values(array_unique(array_filter(array_map('trim', $dept_variants))));

                $deptPlaceholders = implode(',', array_fill(0, count($dept_variants), '?'));
                $leadStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                          FROM users u
                                          WHERE u.department IN ($deptPlaceholders)
                                            AND u.status = 'active'
                                            AND u.role IN ('dean','principal','chairperson','subject_coordinator','grade_level_coordinator')");
                $leadStmt->execute($dept_variants);
                $observerRows = array_merge($observerRows, $leadStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }

            // 3) President/VP
            $pvStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                    FROM users u
                                    WHERE u.status = 'active'
                                      AND u.role IN ('president','vice_president','vice president')");
            $pvStmt->execute();
            $observerRows = array_merge($observerRows, $pvStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            // 4) If request points to a specific evaluation row, include row owner evaluator too.
            if (!empty($req_eval_id)) {
                $ownerStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                           FROM evaluations e
                                           JOIN users u ON u.id = e.evaluator_id
                                           WHERE e.id = :eid
                                             AND u.status = 'active'
                                           LIMIT 1");
                $ownerStmt->execute([':eid' => (int)$req_eval_id]);
                $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
                if ($owner) {
                    $observerRows[] = $owner;
                }
            }

            // De-duplicate and exclude teacher user/self from recipient list
            $byId = [];
            $myUserId = (int)($_SESSION['user_id'] ?? 0);
            $myName = trim((string)($_SESSION['name'] ?? ''));
            foreach ($observerRows as $r) {
                $rid = (int)($r['id'] ?? 0);
                $rname = trim((string)($r['name'] ?? ''));
                if ($rid <= 0) continue;
                if ($myUserId > 0 && $rid === $myUserId) continue;
                if ($myName !== '' && strcasecmp($rname, $myName) === 0) continue;
                $byId[$rid] = $r;
            }
            $recipients = array_values($byId);

            $formatted_sched = $req_sched ? date('F d, Y \a\t h:i A', strtotime($req_sched)) : 'TBA';
            $subject_line = "Reschedule Request - {$teacher_name_req}";
            $msg = "Teacher {$teacher_name_req} requested reschedule for {$formatted_sched}. Reason: {$reason_text}.";
            if ($req_room !== '') $msg .= " Room: {$req_room}.";
            if ($req_subject !== '') $msg .= " Subject: {$req_subject}.";
            if ($req_subject_area !== '') $msg .= " Subject Area: {$req_subject_area}.";

            $notifLink = 'observation_plan.php?' . http_build_query([
                'open_reschedule' => 1,
                'teacher_id' => (int)$teacher_id,
                'eval_id' => (int)($req_eval_id ?? 0),
                'semester' => $req_semester,
                'academic_year' => $req_academic_year
            ]);
            $req_schedule_key = '';
            if ($req_sched) {
                $req_schedule_key = implode('|', [
                    date('Y-m-d H:i', strtotime((string)$req_sched)),
                    strtolower(trim((string)$req_semester)),
                    strtolower(trim((string)$req_academic_year)),
                    strtolower(trim((string)$req_room)),
                    strtolower(trim((string)$req_subject_area)),
                    strtolower(trim((string)$req_subject))
                ]);
            }

            foreach ($recipients as $rcp) {
                if (!empty($rcp['email'])) {
                    sendGenericNotificationEmail(
                        $rcp['email'],
                        $rcp['name'] ?? 'Evaluator',
                        $subject_line,
                        $msg
                    );
                }
                try {
                    $notif = $db->prepare("INSERT INTO notifications (user_id, teacher_id, type, title, message, link, request_eval_id, request_schedule_key, is_read)
                                           VALUES (:uid, :tid, 'reschedule_request', :title, :msg, :link, :request_eval_id, :request_schedule_key, 0)");
                    $notif->execute([
                        ':uid' => (int)$rcp['id'],
                        ':tid' => (int)$teacher_id,
                        ':title' => $subject_line,
                        ':msg' => $msg,
                        ':link' => $notifLink,
                        ':request_eval_id' => (int)($req_eval_id ?? 0),
                        ':request_schedule_key' => $req_schedule_key
                    ]);
                } catch (Exception $e) {
                    // Backward compatibility fallback
                    try {
                        $notifLegacy = $db->prepare("INSERT INTO notifications (user_id, teacher_id, type, title, message, link, is_read)
                                                     VALUES (:uid, :tid, 'reschedule_request', :title, :msg, :link, 0)");
                        $notifLegacy->execute([
                            ':uid' => (int)$rcp['id'],
                            ':tid' => (int)$teacher_id,
                            ':title' => $subject_line,
                            ':msg' => $msg,
                            ':link' => $notifLink
                        ]);
                    } catch (Exception $e2) {}
                }
            }

            $success_message = 'Reschedule request sent successfully.';
        } catch (Exception $e) {
            $error_message = 'Failed to send reschedule request.';
        }
    }

    // PRG: prevent browser Back/Refresh from resubmitting reschedule request form.
    $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? $req_semester)
        . '&academic_year=' . urlencode($_GET['academic_year'] ?? $req_academic_year);
    if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode((string)$_GET['department']);
    if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode((string)$_GET['month']);
    if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode((string)$_GET['status']);
    if (!empty($success_message)) $_SESSION['success'] = $success_message;
    if (!empty($error_message)) $_SESSION['error'] = $error_message;
    header("Location: $redirect");
    exit();
}

// Flash messages
if (!empty($_SESSION['success'])) { $success_message = $_SESSION['success']; unset($_SESSION['success']); }
if (!empty($_SESSION['error'])) { $error_message = $_SESSION['error']; unset($_SESSION['error']); }

// Teacher notifications (same dropdown style as dashboard)
$notifications = [];
$unread_count = 0;
try {
    $notif_q = "SELECT * FROM notifications
                WHERE user_id = :user_id
                  AND type IN ('schedule', 'reschedule_request', 'reschedule_accepted', 'observation_signed', 'observer_accept')
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

// Filters
$semester = normalizeSemesterValue($_GET['semester'] ?? '1st');
if ($semester === '') $semester = '1st';
$semester_variants = semesterVariants($semester);
$academic_year = $_GET['academic_year'] ?? '';
$filter_month = $_GET['month'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_department = trim((string)($_GET['department'] ?? ''));

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

// Use the schedule history table when the teacher snapshot was cleared or stale.
// This keeps the teacher view aligned with schedules set in the department plan.
try {
    $schedule_fallback_stmt = $db->prepare(
        "SELECT
            ts.evaluation_id,
            ts.schedule_start,
            ts.schedule_end,
            ts.room,
            ts.focus_json,
            ts.subject_area,
            ts.subject,
            ts.semester,
            ts.form_type,
            ts.scheduled_department,
            ts.scheduled_by,
            ts.status
         FROM teacher_schedules ts
         WHERE ts.teacher_id = :tid
           AND ts.academic_year = :ay
           AND ts.semester IN (:sem1, :sem2)
           AND ts.status NOT IN ('cancelled','canceled','rescheduled')
         ORDER BY ts.schedule_start DESC, ts.id DESC
         LIMIT 1"
    );
    $schedule_fallback_stmt->execute([
        ':tid' => (int)$teacher_id,
        ':ay' => (string)$academic_year,
        ':sem1' => $semester_variants[0],
        ':sem2' => $semester_variants[1]
    ]);
    $schedule_fallback = $schedule_fallback_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($schedule_fallback) {
        $teacher_data['evaluation_schedule'] = $schedule_fallback['schedule_start'] ?? $teacher_data['evaluation_schedule'] ?? null;
        $teacher_data['evaluation_schedule_end'] = $schedule_fallback['schedule_end'] ?? $teacher_data['evaluation_schedule_end'] ?? null;
        $teacher_data['evaluation_room'] = $schedule_fallback['room'] ?? $teacher_data['evaluation_room'] ?? null;
        $teacher_data['evaluation_focus'] = $schedule_fallback['focus_json'] ?? $teacher_data['evaluation_focus'] ?? null;
        $teacher_data['evaluation_subject_area'] = $schedule_fallback['subject_area'] ?? $teacher_data['evaluation_subject_area'] ?? null;
        $teacher_data['evaluation_subject'] = $schedule_fallback['subject'] ?? $teacher_data['evaluation_subject'] ?? null;
        $teacher_data['evaluation_semester'] = $schedule_fallback['semester'] ?? $teacher_data['evaluation_semester'] ?? null;
        $teacher_data['evaluation_form_type'] = $schedule_fallback['form_type'] ?? $teacher_data['evaluation_form_type'] ?? null;
        $teacher_data['scheduled_department'] = $schedule_fallback['scheduled_department'] ?? $teacher_data['scheduled_department'] ?? null;
        $teacher_data['scheduled_by'] = $schedule_fallback['scheduled_by'] ?? $teacher_data['scheduled_by'] ?? null;
        $teacher_data['_schedule_eval_id'] = (int)($schedule_fallback['evaluation_id'] ?? 0);
        $teacher_data['_schedule_status'] = (string)($schedule_fallback['status'] ?? '');
    }
} catch (Exception $e) {}

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

// Never show the teacher's own name as observer
$self_name = $_SESSION['name'] ?? '';

$get_observer_names_for_row = static function(PDO $db, int $teacher_id, int $eval_id, string $owning_dept, string $self_name): array {
    $owning_dept = trim($owning_dept);
    $observer_names = [];

    if ($owning_dept !== '') {
        $obs_query = "SELECT DISTINCT u.name
                      FROM teacher_assignments ta
                      JOIN users u ON ta.evaluator_id = u.id
                      WHERE ta.teacher_id = :tid
                        AND u.status = 'active'";
        $params = [
            ':tid' => $teacher_id,
        ];
        if ($eval_id > 0) {
            $obs_query .= " AND (
                                u.department = :dept_match
                                OR ta.eval_id = :eval_id_leader
                            )
                            AND (
                                ta.eval_id = :eval_id
                                OR (ta.eval_id IS NULL AND LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('chairperson','subject_coordinator','grade_level_coordinator'))
                            )";
            $params[':dept_match'] = $owning_dept;
            $params[':eval_id_leader'] = $eval_id;
            $params[':eval_id'] = $eval_id;
        } else {
            $obs_query .= " AND u.department = :dept AND ta.eval_id IS NULL";
            $params[':dept'] = $owning_dept;
        }
        $obs_query .= " ORDER BY u.name";
        $obs_stmt = $db->prepare($obs_query);
        $obs_stmt->execute($params);
        $observer_names = $obs_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        try {
            $dean_stmt = $db->prepare(
                "SELECT DISTINCT name
                 FROM users
                 WHERE department = :dept
                   AND role IN ('dean','principal')
                   AND status = 'active'
                 ORDER BY name"
            );
            $dean_stmt->execute([':dept' => $owning_dept]);
            while ($dean_name = $dean_stmt->fetchColumn()) {
                if (!in_array($dean_name, $observer_names, true)) {
                    $observer_names[] = $dean_name;
                }
            }
        } catch (Exception $e) {
            // Keep page usable if user query fails unexpectedly.
        }
    }

    return array_values(array_filter(array_unique($observer_names), function($name) use ($self_name) {
        $name = trim((string)$name);
        return $name !== '' && $name !== trim((string)$self_name);
    }));
};

// Build observation plan data
$has_schedule = !empty($teacher_data['evaluation_schedule']);
$teacher_schedule_semester = normalizeSemesterValue($teacher_data['evaluation_semester'] ?? '');
$has_matching_schedule = $has_schedule && ($teacher_schedule_semester === $semester || $teacher_schedule_semester === '');
// Schedule ownership (used by department filter)
$schedule_owning_dept = trim((string)($teacher_data['scheduled_department'] ?? ''));
if ($schedule_owning_dept === '') {
    $schedule_owning_dept = trim((string)($teacher_data['department'] ?? ''));
}

// Get completed evaluations for this semester
$eval_query = "SELECT e.id, e.department, e.observation_date, e.observation_time, e.status, e.subject_area, e.subject_observed, e.observation_room, e.semester, e.evaluation_focus, u.name as evaluator_name, u.department as evaluator_department
               FROM evaluations e
               JOIN users u ON e.evaluator_id = u.id
               WHERE e.teacher_id = :tid AND e.academic_year = :ay AND e.semester IN (:sem1, :sem2)
               ORDER BY e.observation_date ASC";
$eval_stmt = $db->prepare($eval_query);
$eval_stmt->execute([
    ':tid' => $teacher_id,
    ':ay' => $academic_year,
    ':sem1' => $semester_variants[0],
    ':sem2' => $semester_variants[1]
]);
$evaluations = $eval_stmt->fetchAll(PDO::FETCH_ASSOC);

// Check acknowledgment status — per-item
$ack_query = "SELECT *
              FROM observation_plan_acknowledgments
              WHERE teacher_id = :tid
                AND academic_year = :ay
                AND semester IN (:sem1, :sem2)";
$ack_stmt = $db->prepare($ack_query);
$ack_stmt->execute([
    ':tid' => $teacher_id,
    ':ay' => $academic_year,
    ':sem1' => $semester_variants[0],
    ':sem2' => $semester_variants[1]
]);
$ack_rows = $ack_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build lookup: evaluation_id => acknowledgment row (null/'upcoming' for upcoming schedule)
$signed_map = [];
foreach ($ack_rows as $ack) {
    $key = $ack['evaluation_id'] === null ? 'upcoming' : (int)$ack['evaluation_id'];
    $signed_map[$key] = $ack;
}

// Group completed evaluations by observation date
$eval_groups = [];
foreach ($evaluations as $ev) {
    $date_key = !empty($ev['observation_date']) ? date('Y-m-d', strtotime($ev['observation_date'])) : 'unknown';
    $dept_key = trim((string)($ev['department'] ?? ($ev['evaluator_department'] ?? '')));
    $group_key = $date_key . '|' . $dept_key;
    if (!isset($eval_groups[$group_key])) {
        $eval_groups[$group_key] = [];
    }
    $eval_groups[$group_key][] = $ev;
}

// Determine if the current schedule is a genuinely new/upcoming observation
// (its date does NOT overlap with any completed evaluation date)
$schedule_date_key = $has_matching_schedule ? (date('Y-m-d', strtotime($teacher_data['evaluation_schedule'])) . '|' . $schedule_owning_dept) : null;
$show_upcoming = $has_matching_schedule && ($schedule_date_key === null || !isset($eval_groups[$schedule_date_key]));

// Apply department filter (if selected)
if ($filter_department !== '') {
    $show_upcoming = $show_upcoming && ($schedule_owning_dept === $filter_department);
    $eval_groups = array_filter($eval_groups, function($group) use ($filter_department) {
        foreach ($group as $ev) {
            if (($ev['department'] ?? ($ev['evaluator_department'] ?? '')) === $filter_department) {
                return true;
            }
        }
        return false;
    });
}

// Apply month filter
if (!empty($filter_month) && $has_matching_schedule) {
    $sched_month = (int)date('n', strtotime($teacher_data['evaluation_schedule']));
    if ($sched_month != (int)$filter_month) $show_upcoming = false;
}
if (!empty($filter_month)) {
    $eval_groups = array_filter($eval_groups, function($group) use ($filter_month) {
        $first = $group[0];
        $date = $first['observation_date'] ?? '';
        if (empty($date)) return false;
        return (int)date('n', strtotime($date)) == (int)$filter_month;
    });
}

// Filter by status
if (!empty($filter_status)) {
    if ($filter_status === 'completed') {
        $eval_groups = array_filter($eval_groups, function($group) {
            return true; // all eval_groups are completed
        });
        $show_upcoming = false;
    } elseif ($filter_status === 'upcoming') {
        $eval_groups = []; // hide completed, only show upcoming
    } elseif ($filter_status === 'observer_unbalanced') {
        $eval_groups = array_filter($eval_groups, function($group) {
            foreach ($group as $row) {
                if (strtolower(trim((string)($row['status'] ?? ''))) === 'observer_unbalanced') return true;
            }
            return false;
        });
        $show_upcoming = false;
    }
}

// Count unsigned items for actions (Sign / Request Reschedule).
// Current schedule can be represented either as:
// - an upcoming row (no evaluation group on same date), or
// - an in-progress current-date evaluation group.
$unsigned_count = 0;
$has_unsigned_current_group = false;
if ($has_matching_schedule && $schedule_date_key !== null && isset($eval_groups[$schedule_date_key])) {
    $current_group = $eval_groups[$schedule_date_key];
    // Treat legacy/upcoming signature as signed for the current group too.
    $current_group_signed = isset($signed_map['upcoming']);
    foreach ($current_group as $cev_signed) {
        $cev_id = (int)($cev_signed['id'] ?? 0);
        if ($cev_id > 0 && isset($signed_map[$cev_id])) {
            $current_group_signed = true;
            break;
        }
    }
    $current_completed_evaluators = [];
    foreach ($current_group as $cev) {
        $cev_status = strtolower(trim((string)($cev['status'] ?? '')));
        if ($cev_status === 'completed') {
            $current_completed_evaluators[] = $cev['evaluator_name'] ?? '';
        }
    }
    $current_eval_id = (int)($current_group[0]['id'] ?? 0);
    $current_owning_dept = trim((string)($current_group[0]['department'] ?? ''));
    if ($current_owning_dept === '') {
        $current_owning_dept = $schedule_owning_dept;
    }
    $current_required_observers = $get_observer_names_for_row($db, (int)$teacher_id, $current_eval_id, $current_owning_dept, $self_name);
    $current_completed_evaluators = array_values(array_unique(array_filter($current_completed_evaluators)));
    $normalize_name_key = function($name) {
        $name = strtolower(trim((string)$name));
        $name = preg_replace('/\s+/', ' ', $name);
        return $name;
    };
    $current_completed_keys = array_values(array_unique(array_filter(array_map($normalize_name_key, $current_completed_evaluators))));
    $current_required_keys = array_values(array_unique(array_filter(array_map($normalize_name_key, $current_required_observers))));
    $current_all_done = !empty($current_required_keys);
    foreach ($current_required_keys as $req_key) {
        if (!in_array($req_key, $current_completed_keys, true)) {
            $current_all_done = false;
            break;
        }
    }
    $has_unsigned_current_group = !$current_all_done && !$current_group_signed;
}
if ($show_upcoming && !isset($signed_map['upcoming'])) {
    $unsigned_count++;
} elseif ($has_unsigned_current_group) {
    $unsigned_count++;
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
$department_display = $department_map[$teacher_data['department']] ?? $teacher_data['department'];
$filter_department_display = $department_display;
if ($filter_department !== '') {
    $filter_department_display = $department_map[$filter_department] ?? $filter_department;
}

// Department options in filter: teacher primary + secondary + current schedule owner
$department_options = [];
if (!empty($teacher_data['department'])) $department_options[] = $teacher_data['department'];
if (!empty($schedule_owning_dept) && !in_array($schedule_owning_dept, $department_options, true)) $department_options[] = $schedule_owning_dept;
try {
    $sec_dept_stmt = $db->prepare("SELECT department FROM teacher_departments WHERE teacher_id = :tid");
    $sec_dept_stmt->execute([':tid' => $teacher_id]);
    while ($d = $sec_dept_stmt->fetchColumn()) {
        if (!empty($d) && !in_array($d, $department_options, true)) $department_options[] = $d;
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Evaluation Schedule</title>
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
            table-layout: fixed;
            border: 1px solid #2b2b2b;
        }
        .plan-table th, .plan-table td {
            border: 1px solid #2b2b2b;
            padding: 12px 10px;
            vertical-align: middle;
        }
        .plan-table th {
            background: #2c3e50;
            color: white;
            font-weight: 600;
            text-align: center;
            font-size: 0.85rem;
            letter-spacing: 0.2px;
        }
        .plan-table td {
            font-size: 0.9rem;
            line-height: 1.45;
        }
        .plan-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        .cell-semester { text-align: center; white-space: normal; word-break: keep-all; }
        .cell-date { text-align: center; white-space: nowrap; font-weight: 600; color: #2c3e50; }
        .cell-day-time { text-align: center; }
        .cell-day-time .day { display: block; font-weight: 700; color: #2c3e50; }
        .cell-day-time .time { display: block; color: #495057; margin-top: 2px; }
        .cell-subject-area { text-align: center; word-break: normal; overflow-wrap: normal; }
        .cell-subject { font-weight: 600; color: #2c3e50; word-break: normal; overflow-wrap: normal; }
        .cell-room { text-align: center; white-space: normal; word-break: normal; overflow-wrap: normal; }
        .cell-focus, .cell-observers { font-size: 0.86rem; color: #374151; }
        .cell-focus .focus-item { display: block; margin-bottom: 2px; }
        .cell-observers .observer-item {
            display: block;
            margin-bottom: 1px;
            line-height: 1.35;
            white-space: nowrap;
            word-break: normal;
            overflow-wrap: normal;
        }
        .cell-status { text-align: center; white-space: nowrap; }
        .cell-status .badge { min-width: 88px; }
        @media (max-width: 1200px) {
            .plan-table th, .plan-table td { padding: 10px 8px; }
            .cell-focus, .cell-observers { font-size: 0.82rem; }
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
        .resched-modal .modal-dialog {
            max-width: 560px;
        }
        .resched-modal .modal-content {
            border: 0;
            border-radius: 14px;
            overflow: hidden;
        }
        .resched-modal .modal-header {
            background: #0d6efd !important;
            color: #fff;
        }
        .resched-modal .modal-body {
            padding: 1rem 1.25rem;
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
                            <div class="notif-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>" id="notif-<?php echo (int)$notif['id']; ?>" <?php if (!empty($notif['link'])): ?>onclick="window.location.href='<?php echo htmlspecialchars($notif['link'], ENT_QUOTES); ?>'" style="cursor:pointer;"<?php endif; ?>>
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
                                            <?php if (!$notif['is_read']): ?>
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
                    <button class="btn user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
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

            <h4 class="mb-3"><i class="fas fa-clipboard-list me-2"></i>My Evaluation Schedule</h4>

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
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Department</label>
                            <select name="department" class="form-select">
                                <option value="" <?php echo $filter_department === '' ? 'selected' : ''; ?>>All Departments</option>
                                <?php foreach ($department_options as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filter_department === $dept ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($department_map[$dept] ?? $dept); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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
                                $months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
                                foreach ($months as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php echo $filter_month == $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="" <?php echo $filter_status === '' ? 'selected' : ''; ?>>All Status</option>
                                <option value="upcoming" <?php echo $filter_status === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="observer_unbalanced" <?php echo $filter_status === 'observer_unbalanced' ? 'selected' : ''; ?>>Observer Imbalance</option>
                            </select>
                        </div>
                        <div class="w-100"></div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Observation Plan Details -->
            <div class="plan-card">
                <div class="text-center mb-3">
                    <h5 class="fw-bold">My Evaluation Schedule</h5>
                    <p class="text-muted mb-1"><?php echo htmlspecialchars($filter_department_display); ?></p>
                    <p class="text-muted"><?php echo htmlspecialchars($semester); ?> Semester SY <?php echo htmlspecialchars($academic_year); ?></p>
                </div>

                <?php if ($show_upcoming || count($eval_groups) > 0): ?>

                <div class="mb-3 d-flex justify-content-end no-print">
                    <button class="btn btn-outline-primary me-2" id="reqReschedBtn" disabled onclick="openRescheduleRequestModal()">
                        <i class="fas fa-calendar-alt me-1"></i>Request Reschedule
                    </button>
                    <button class="btn btn-primary" id="signToggleBtn" disabled onclick="toggleSignPanel()">
                        <i class="fas fa-signature me-1"></i>Sign <span id="signBadgeCount" class="badge bg-light text-dark ms-1" style="display:none;">0</span>
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="plan-table">
                        <thead>
                            <tr>
                                <th style="min-width:210px;">Teacher</th>
                                <th style="min-width:92px;">Semester</th>
                                <th>Focus of Observation</th>
                                <th style="min-width:84px;">Date</th>
                                <th style="min-width:108px;">Day &amp; Time</th>
                                <th style="min-width:136px;">Subject Area</th>
                                <th style="min-width:96px;">Subject</th>
                                <th style="min-width:72px;">Room</th>
                                <th style="min-width:180px;">Name of Observers</th>
                                <th style="min-width:96px;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                                $upcoming_signed = isset($signed_map['upcoming']);

                                // Render completed evaluation groups (each observation date = one row)
                                foreach ($eval_groups as $date_key => $group):
                                    $is_current = ($schedule_date_key === $date_key);
                                    $first_ev = $group[0];

                                    // Use schedule data if this is the current schedule, else evaluation data
                                    if ($is_current && $has_matching_schedule) {
                                        $row_eval_id = (int)($first_ev['id'] ?? 0);
                                        $row_owning_dept = trim((string)($first_ev['department'] ?? ''));
                                        if ($row_owning_dept === '') {
                                            $row_owning_dept = $schedule_owning_dept;
                                        }
                                        $focus_raw = $teacher_data['evaluation_focus'] ?? '';
                                        $ts = strtotime($teacher_data['evaluation_schedule']);
                                        $row_date = date('M d, Y', $ts);
                                        $row_day_time = date('D', $ts) . '<br>' . date('g:i A', $ts);
                                        $sched_end = trim((string)($teacher_data['evaluation_schedule_end'] ?? ''));
                                        if ($sched_end !== '' && strtotime($sched_end) !== false) {
                                            $row_day_time .= ' - ' . date('g:i A', strtotime($sched_end));
                                        }
                                        $row_subject_area = $teacher_data['evaluation_subject_area'] ?? '';
                                        $row_subject = $teacher_data['evaluation_subject'] ?? '';
                                        $row_room = $teacher_data['evaluation_room'] ?? '';
                                        $row_semester_display = ($teacher_data['evaluation_semester'] ?? '') . ' Semester';
                                        $row_observers = $get_observer_names_for_row($db, (int)$teacher_id, $row_eval_id, $row_owning_dept, $self_name);
                                        $group_evaluators = array_values(array_unique(array_filter(array_map(function($g) {
                                            return trim((string)($g['evaluator_name'] ?? ''));
                                        }, $group))));
                                        foreach ($group_evaluators as $gev) {
                                            if (!in_array($gev, $row_observers, true)) {
                                                $row_observers[] = $gev;
                                            }
                                        }
                                    } else {
                                        $row_eval_id = (int)($first_ev['id'] ?? 0);
                                        $row_owning_dept = trim((string)($first_ev['department'] ?? ''));
                                        if ($row_owning_dept === '') {
                                            $row_owning_dept = trim((string)($first_ev['evaluator_department'] ?? ''));
                                        }
                                        if ($row_owning_dept === '') {
                                            $row_owning_dept = $schedule_owning_dept;
                                        }
                                        $focus_raw = $first_ev['evaluation_focus'] ?? '';
                                        $row_date = !empty($first_ev['observation_date']) ? date('M d, Y', strtotime($first_ev['observation_date'])) : '';
                                        $row_day_time = !empty($first_ev['observation_date']) ? date('D', strtotime($first_ev['observation_date'])) : '';
                                        $first_time = trim((string)($first_ev['observation_time'] ?? ''));
                                        if ($first_time !== '' && $first_time !== '00:00:00' && $first_time !== '00:00') {
                                            $row_day_time .= '<br>' . date('g:i A', strtotime($first_time));
                                            $sched_end = trim((string)($teacher_data['evaluation_schedule_end'] ?? ''));
                                            if ($sched_end !== '' && strtotime($sched_end) !== false) {
                                                $row_day_time .= ' - ' . date('g:i A', strtotime($sched_end));
                                            }
                                        }
                                        $row_subject_area = $first_ev['subject_area'] ?? '';
                                        $row_subject = $first_ev['subject_observed'] ?? '';
                                        $row_room = $first_ev['observation_room'] ?? '';
                                        $row_semester_display = ($first_ev['semester'] ?? '') . ' Semester';
                                        $row_observers = $get_observer_names_for_row($db, (int)$teacher_id, $row_eval_id, $row_owning_dept, $self_name);
                                        $group_observers = array_values(array_unique(array_filter(array_column($group, 'evaluator_name'))));
                                        foreach ($group_observers as $gobs) {
                                            $gobs = trim((string)$gobs);
                                            if ($gobs !== '' && !in_array($gobs, $row_observers, true)) {
                                                $row_observers[] = $gobs;
                                            }
                                        }
                                    }
                                    $row_observers = array_values(array_filter($row_observers, function($n) use ($self_name) {
                                        $n = trim((string)$n);
                                        return $n !== '' && $n !== trim((string)$self_name);
                                    }));

                                    $focus_display = formatFocusItems($focus_raw, $focus_labels);

                                    // Status and first-column control for this group
                                    $completed_evaluators = [];
                                    $group_all_rows_completed = true;
                                    $group_observer_unbalanced = false;
                                    foreach ($group as $g_ev) {
                                        $g_ev_status = strtolower(trim((string)($g_ev['status'] ?? '')));
                                        if ($g_ev_status === 'observer_unbalanced') {
                                            $group_observer_unbalanced = true;
                                        }
                                        if ($g_ev_status === 'completed') {
                                            $completed_evaluators[] = $g_ev['evaluator_name'] ?? '';
                                        } else {
                                            $group_all_rows_completed = false;
                                        }
                                    }
                                    $completed_evaluators = array_values(array_unique(array_filter($completed_evaluators)));
                                    $normalize_name_key = function($name) {
                                        $name = strtolower(trim((string)$name));
                                        // Collapse repeated inner spaces so minor formatting does not break matching.
                                        $name = preg_replace('/\s+/', ' ', $name);
                                        return $name;
                                    };
                                    $completed_evaluator_keys = array_values(array_unique(array_filter(array_map($normalize_name_key, $completed_evaluators))));
                                    $required_observer_keys = array_values(array_unique(array_filter(array_map($normalize_name_key, $row_observers))));
                                    $all_required_observers_completed = !empty($required_observer_keys);
                                    foreach ($required_observer_keys as $req_key) {
                                        if (!in_array($req_key, $completed_evaluator_keys, true)) {
                                            $all_required_observers_completed = false;
                                            break;
                                        }
                                    }
                                    $row_can_sign = false;
                                    $row_is_signed = false;
                                    $row_is_done = false;

                                    // Mark completed only after the schedule slot has passed.
                                    $row_end_ts = null;
                                    if ($is_current && $has_matching_schedule) {
                                        $row_start_raw = trim((string)($teacher_data['evaluation_schedule'] ?? ''));
                                        if ($row_start_raw !== '' && strtotime($row_start_raw) !== false) {
                                            $row_end_ts = strtotime($row_start_raw);
                                        }
                                        $row_end_raw = trim((string)($teacher_data['evaluation_schedule_end'] ?? ''));
                                        if ($row_end_raw !== '' && strtotime($row_end_raw) !== false) {
                                            $row_end_ts = strtotime($row_end_raw);
                                        }
                                    } else {
                                        $row_date_raw = trim((string)($first_ev['observation_date'] ?? ''));
                                        $row_time_raw = trim((string)($first_ev['observation_time'] ?? ''));
                                        if ($row_date_raw !== '') {
                                            $row_dt_raw = $row_date_raw . ' ' . (($row_time_raw !== '' && $row_time_raw !== '00:00:00' && $row_time_raw !== '00:00') ? $row_time_raw : '00:00:00');
                                            if (strtotime($row_dt_raw) !== false) {
                                                $row_end_ts = strtotime($row_dt_raw);
                                            }
                                        }
                                    }
                                    $row_time_passed = ($row_end_ts !== null && time() >= $row_end_ts);

                                    if ($is_current) {
                                        $all_done = $all_required_observers_completed;
                                        // Treat legacy/upcoming signature as signed for current group.
                                        $current_group_signed = isset($signed_map['upcoming']);
                                        foreach ($group as $g_sig_ev) {
                                            $g_sig_id = (int)($g_sig_ev['id'] ?? 0);
                                            if ($g_sig_id > 0 && isset($signed_map[$g_sig_id])) {
                                                $current_group_signed = true;
                                                break;
                                            }
                                        }
                                        if ($group_observer_unbalanced) {
                                            $status_badge = '<span class="badge bg-danger">Observer Imbalance</span>';
                                        } elseif ($all_done && $row_time_passed) {
                                            $status_badge = '<span class="badge bg-success">Completed</span>';
                                            $row_is_done = true;
                                        } elseif ($all_done || count($completed_evaluators) > 0) {
                                            $status_badge = '<span class="badge bg-info">In Progress</span>';
                                        } else {
                                            $status_badge = '<span class="badge bg-info">Upcoming</span>';
                                        }
                                        $row_is_signed = $current_group_signed;
                                        $row_can_sign = !$all_done && !$current_group_signed;
                                    } else {
                                        $group_signed = false;
                                        foreach ($group as $g_sig_ev) {
                                            $g_sig_id = (int)($g_sig_ev['id'] ?? 0);
                                            if ($g_sig_id > 0 && isset($signed_map[$g_sig_id])) {
                                                $group_signed = true;
                                                break;
                                            }
                                        }
                                        if ($group_observer_unbalanced) {
                                            $status_badge = '<span class="badge bg-danger">Observer Imbalance</span>';
                                        } elseif ($all_required_observers_completed && $row_time_passed) {
                                            $status_badge = '<span class="badge bg-success">Completed</span>';
                                            $row_is_done = true;
                                        } else {
                                            $status_badge = '<span class="badge bg-info">In Progress</span>';
                                        }
                                        $row_is_signed = $group_signed;
                                    }
                            ?>
                            <tr>
                                <td>
                                    <?php if ($is_current): ?>
                                        <?php if ($row_is_done || $row_is_signed): ?>
                                            <input type="checkbox" class="form-check-input me-2" checked disabled style="width:16px;height:16px;vertical-align:middle;" title="<?php echo $row_is_done ? 'Completed schedule' : 'Signed schedule'; ?>">
                                        <?php else: ?>
                                            <input type="checkbox" class="form-check-input me-2 schedule-item-check <?php echo $row_can_sign ? 'sign-item-check' : ''; ?>" value="upcoming" data-schedule-label="<?php echo htmlspecialchars('Current: ' . $row_date . ' ' . strip_tags($row_day_time)); ?>" style="width:16px;height:16px;vertical-align:middle;">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php
                                            $group_rep_id = (int)($first_ev['id'] ?? 0);
                                            $group_can_select = (!$group_all_rows_completed && !$group_signed && $group_rep_id > 0);
                                        ?>
                                        <?php if ($row_is_done || $row_is_signed): ?>
                                            <input type="checkbox" class="form-check-input me-2" checked disabled style="width:16px;height:16px;vertical-align:middle;" title="<?php echo $row_is_done ? 'Completed schedule' : 'Signed schedule'; ?>">
                                        <?php else: ?>
                                            <input type="checkbox" class="form-check-input me-2 schedule-item-check <?php echo $group_can_select ? 'sign-item-check' : ''; ?>" value="<?php echo $group_rep_id; ?>" data-schedule-label="<?php echo htmlspecialchars('Schedule: ' . $row_date . ' ' . strip_tags($row_day_time)); ?>" style="width:16px;height:16px;vertical-align:middle;">
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($teacher_data['name'] ?? ($_SESSION['name'] ?? 'Teacher')); ?>
                                </td>
                                <td class="cell-semester"><?php echo htmlspecialchars($row_semester_display); ?></td>
                                <td class="cell-focus">
                                    <?php if (!empty($focus_display)): ?>
                                        <?php foreach ($focus_display as $focus_item): ?>
                                            <span class="focus-item"><?php echo htmlspecialchars($focus_item); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No focus set</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-date"><?php echo !empty($first_ev['observation_date']) ? date('m-d-y', strtotime($first_ev['observation_date'])) : htmlspecialchars($row_date); ?></td>
                                <td class="cell-day-time">
                                    <?php
                                        $day_part = '';
                                        $time_part = '';
                                        $parts = explode('<br>', (string)$row_day_time, 2);
                                        if (count($parts) === 2) {
                                            $day_part = trim(strip_tags($parts[0]));
                                            $time_part = trim(strip_tags($parts[1]));
                                        } else {
                                            $day_part = trim(strip_tags((string)$row_day_time));
                                        }
                                        if (!empty($first_ev['observation_date'])) {
                                            $day_part = date('l', strtotime($first_ev['observation_date']));
                                        }
                                    ?>
                                    <span class="day"><?php echo htmlspecialchars($day_part); ?></span>
                                    <?php if ($time_part !== ''): ?><span class="time"><?php echo htmlspecialchars($time_part); ?></span><?php endif; ?>
                                </td>
                                <td class="cell-subject-area"><?php echo htmlspecialchars($row_subject_area); ?></td>
                                <td class="cell-subject"><?php echo htmlspecialchars($row_subject); ?></td>
                                <td class="cell-room"><?php echo htmlspecialchars($row_room); ?></td>
                                <td class="cell-observers">
                                    <?php foreach ($row_observers as $i => $obs_name): ?>
                                        <span class="observer-item"><?php echo htmlspecialchars($obs_name); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="cell-status">
                                    <?php if ($group_observer_unbalanced): ?>
                                        <span class="badge bg-danger">Observer Imbalance</span>
                                    <?php elseif ($row_is_done): ?>
                                        <span class="badge bg-success">Conducted</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">In Progress</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <?php if ($show_upcoming): ?>
                            <?php
                                $focus_raw = $teacher_data['evaluation_focus'] ?? '';
                                $focus_display = formatFocusItems($focus_raw, $focus_labels);
                                $ts = strtotime($teacher_data['evaluation_schedule']);
                                $row_date = date('M d, Y', $ts);
                                $row_day_time = date('D', $ts) . '<br>' . date('g:i A', $ts);
                                $row_subject_area = $teacher_data['evaluation_subject_area'] ?? '';
                                $row_subject = $teacher_data['evaluation_subject'] ?? '';
                                $row_room = $teacher_data['evaluation_room'] ?? '';
                                $row_semester_display = ($teacher_data['evaluation_semester'] ?? '') . ' Semester';
                                $upcoming_eval_id = (int)($teacher_data['_schedule_eval_id'] ?? 0);
                                $upcoming_observers = $get_observer_names_for_row($db, (int)$teacher_id, $upcoming_eval_id, $schedule_owning_dept, $self_name);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($upcoming_signed): ?>
                                        <input type="checkbox" class="form-check-input me-2" checked disabled style="width:16px;height:16px;vertical-align:middle;" title="Signed schedule">
                                    <?php else: ?>
                                        <input type="checkbox" class="form-check-input me-2 schedule-item-check sign-item-check" value="upcoming" data-schedule-label="Upcoming: <?php echo htmlspecialchars($row_date . ' ' . strip_tags($row_day_time)); ?>" style="width:16px;height:16px;vertical-align:middle;">
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($teacher_data['name'] ?? ($_SESSION['name'] ?? 'Teacher')); ?>
                                </td>
                                <td class="cell-semester"><?php echo htmlspecialchars($row_semester_display); ?></td>
                                <td class="cell-focus">
                                    <?php if (!empty($focus_display)): ?>
                                        <?php foreach ($focus_display as $focus_item): ?>
                                            <span class="focus-item"><?php echo htmlspecialchars($focus_item); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No focus set</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-date"><?php echo date('m-d-y', strtotime($teacher_data['evaluation_schedule'])); ?></td>
                                <td class="cell-day-time">
                                    <?php
                                        $day_part = '';
                                        $time_part = '';
                                        $parts = explode('<br>', (string)$row_day_time, 2);
                                        if (count($parts) === 2) {
                                            $day_part = trim(strip_tags($parts[0]));
                                            $time_part = trim(strip_tags($parts[1]));
                                        } else {
                                            $day_part = trim(strip_tags((string)$row_day_time));
                                        }
                                        $day_part = date('l', strtotime($teacher_data['evaluation_schedule']));
                                    ?>
                                    <span class="day"><?php echo htmlspecialchars($day_part); ?></span>
                                    <?php if ($time_part !== ''): ?><span class="time"><?php echo htmlspecialchars($time_part); ?></span><?php endif; ?>
                                </td>
                                <td class="cell-subject-area"><?php echo htmlspecialchars($row_subject_area); ?></td>
                                <td class="cell-subject"><?php echo htmlspecialchars($row_subject); ?></td>
                                <td class="cell-room"><?php echo htmlspecialchars($row_room); ?></td>
                                <td class="cell-observers">
                                    <?php foreach ($upcoming_observers as $i => $obs_name): ?>
                                        <span class="observer-item"><?php echo htmlspecialchars($obs_name); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="cell-status">
                                    <span class="badge bg-info">In Progress</span>
                                </td>
                            </tr>
                            <?php endif; ?>
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
                    <h5 class="text-muted">No Evaluation Schedule Yet</h5>
                    <p class="text-muted">No observation schedule has been set for you this <?php echo htmlspecialchars($semester); ?> Semester.</p>
                </div>
                <?php endif; ?>
            </div>

        </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <!-- Request Reschedule Modal -->
    <div class="modal fade resched-modal" id="rescheduleRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calendar-times me-2"></i>Request Reschedule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="request_reschedule_my">
                        <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                        <input type="hidden" name="reschedule_item" id="rescheduleItemInput" value="">

                        <div class="mb-2 small text-muted" id="rescheduleSelectedText"></div>

                        <label class="form-label fw-bold">Reason <span class="text-danger">*</span></label>
                        <select class="form-select" name="reschedule_reason" id="rescheduleReasonSelect" required>
                            <option value="">Select reason</option>
                            <option value="emergency">Emergency</option>
                            <option value="conflict_schedule">Conflict of Schedule</option>
                            <option value="others">Others</option>
                        </select>
                        <div class="mt-3" id="rescheduleOtherWrap" style="display:none;">
                            <label class="form-label fw-bold">Please specify</label>
                            <textarea class="form-control" name="reschedule_reason_other" id="rescheduleOtherText" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Send Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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

    // Sign panel toggle
var signPanel = document.getElementById('signPanel');
var signToggleBtn = document.getElementById('signToggleBtn');
var signBadgeCount = document.getElementById('signBadgeCount');
var reqReschedBtn = document.getElementById('reqReschedBtn');

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

    function getSelectedSignChecks() {
        var selected = Array.from(document.querySelectorAll('.sign-item-check:checked'));
        if (selected.length > 0) return selected;
        return Array.from(document.querySelectorAll('.sign-item-check'));
    }

    function getSelectedRescheduleChecks() {
        return Array.from(document.querySelectorAll('.schedule-item-check:checked'));
    }

    function updateCheckboxState() {
        var checkedCount = document.querySelectorAll('.sign-item-check:checked').length;
        var checks = getSelectedSignChecks();
        var count = checks.length;
        var reschedChecks = getSelectedRescheduleChecks();
        var reschedCount = reschedChecks.length;
        if (countEl) {
            if (checkedCount > 0) {
                countEl.textContent = count + ' schedule(s) selected';
            } else {
                countEl.textContent = count + ' schedule(s) ready to sign';
            }
        }
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
        if (reqReschedBtn) {
            reqReschedBtn.disabled = (reschedCount === 0);
        }
    }

    document.querySelectorAll('.schedule-item-check, .sign-item-check').forEach(function(cb) {
        cb.addEventListener('change', updateCheckboxState);
    });

    function clearSignature() {
        if (!sigCanvas) return;
        sigCtx.clearRect(0, 0, sigCanvas.width, sigCanvas.height);
        sigHasDrawn = false;
    }

    function submitSignature() {
        if (!sigCanvas) return false;
        var checks = getSelectedSignChecks();
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

    function openRescheduleRequestModal() {
        var checks = getSelectedRescheduleChecks();
        if (checks.length === 0) {
            alert('Please select a schedule first.');
            return;
        }
        // Reschedule request is per schedule item; when multiple are checked,
        // use the first checked row to keep the action workable.
        var selected = checks[0];
        var itemInput = document.getElementById('rescheduleItemInput');
        var selectedText = document.getElementById('rescheduleSelectedText');
        if (itemInput) itemInput.value = selected.value;
        if (selectedText) selectedText.textContent = selected.dataset.scheduleLabel || ('Selected schedule item: ' + selected.value);

        var reasonSelect = document.getElementById('rescheduleReasonSelect');
        var otherWrap = document.getElementById('rescheduleOtherWrap');
        var otherText = document.getElementById('rescheduleOtherText');
        if (reasonSelect) reasonSelect.value = '';
        if (otherWrap) otherWrap.style.display = 'none';
        if (otherText) { otherText.value = ''; otherText.required = false; }

        var modalEl = document.getElementById('rescheduleRequestModal');
        if (!modalEl) return;
        var modal = new bootstrap.Modal(modalEl);
        modal.show();
    }

    var reasonSelect = document.getElementById('rescheduleReasonSelect');
    if (reasonSelect) {
        reasonSelect.addEventListener('change', function() {
            var isOther = this.value === 'others';
            var otherWrap = document.getElementById('rescheduleOtherWrap');
            var otherText = document.getElementById('rescheduleOtherText');
            if (otherWrap) otherWrap.style.display = isOther ? '' : 'none';
            if (otherText) otherText.required = isOther;
        });
    }

    // Initialize action button state even before any checkbox interaction.
    updateCheckboxState();
    </script>
</body>
</html>
