<?php
require_once '../auth/session-check.php';
$session_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$session_role = str_replace(' ', '_', $session_role);
if ($session_role !== '') {
    $_SESSION['role'] = $session_role;
}
if(!in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../config/database.php';
require_once '../models/Teacher.php';
require_once '../includes/mailer.php';
require_once '../includes/program_assignments.php';

$is_observer_only_role = in_array($_SESSION['role'] ?? '', ['president', 'vice_president'], true);

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);
$success_message = null;
$error_message = null;

// Ensure evaluation status supports "rescheduled" for remarks workflow.
// This keeps behavior consistent across Dean/Coordinator/President/VP views.
try {
    if ($db) {
        $statusCol = $db->query("SHOW COLUMNS FROM evaluations LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $statusType = strtolower(trim((string)($statusCol['Type'] ?? '')));
        if ($statusType !== '' && strpos($statusType, "enum(") === 0 && strpos($statusType, "'rescheduled'") === false) {
            $db->exec("ALTER TABLE evaluations MODIFY COLUMN status ENUM('draft','rescheduled','completed') DEFAULT 'draft'");
        }
        // Normalize previous invalid blank statuses created before enum update.
        $db->exec("UPDATE evaluations SET status = 'rescheduled' WHERE status = '' OR status IS NULL");
    }
} catch (Exception $e) {
    // Non-fatal: page should still load even if schema patch cannot run.
}

// Handle bulk schedule cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_schedule') {
    if ($is_observer_only_role) {
        $_SESSION['error'] = 'President/Vice President can only accept as observer.';
        $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
        if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
        if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
        if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
        header("Location: $redirect");
        exit();
    }

    $raw_ids = $_POST['teacher_ids'] ?? '[]';
    $teacher_ids = [];

    if (is_string($raw_ids)) {
        $decoded_ids = json_decode($raw_ids, true);
        if (is_array($decoded_ids)) {
            $teacher_ids = $decoded_ids;
        }
    } elseif (is_array($raw_ids)) {
        $teacher_ids = $raw_ids;
    }

    $teacher_ids = array_values(array_unique(array_filter(array_map('intval', $teacher_ids), function($id) {
        return $id > 0;
    })));

    if (!empty($teacher_ids)) {
        try {
            $db->beginTransaction();

            $cancel_sem = trim((string)($_POST['filter_semester'] ?? ($_GET['semester'] ?? '1st')));
            if (!in_array($cancel_sem, ['1st', '2nd'], true)) $cancel_sem = '1st';

            $cancel_ay = trim((string)($_POST['filter_academic_year'] ?? ($_GET['academic_year'] ?? '')));
            if ($cancel_ay === '') {
                $m = (int)date('n');
                $y = (int)date('Y');
                $cancel_ay = ($m >= 6) ? ($y . '-' . ($y + 1)) : (($y - 1) . '-' . $y);
            }

            // 1) Remove teacher-level active schedule fields.
            $ph = implode(',', array_fill(0, count($teacher_ids), '?'));
            $clr_sql = "UPDATE teachers
                        SET evaluation_schedule = NULL,
                            evaluation_schedule_end = NULL,
                            evaluation_room = NULL,
                            evaluation_focus = NULL,
                            evaluation_subject_area = NULL,
                            evaluation_subject = NULL,
                            evaluation_semester = NULL,
                            evaluation_form_type = NULL,
                            scheduled_by = NULL,
                            scheduled_department = NULL,
                            updated_at = NOW()
                        WHERE id IN ($ph)";
            $clr_stmt = $db->prepare($clr_sql);
            $clr_stmt->execute($teacher_ids);

            // 2) Remove non-completed evaluation rows for current filter AY/semester.
            $eval_ids = [];
            $ev_sel_sql = "SELECT id
                           FROM evaluations
                           WHERE teacher_id IN ($ph)
                             AND academic_year = ?
                             AND semester = ?
                             AND (status IS NULL OR status <> 'completed')";
            $ev_sel_stmt = $db->prepare($ev_sel_sql);
            $ev_sel_stmt->execute(array_merge($teacher_ids, [$cancel_ay, $cancel_sem]));
            while ($eid = $ev_sel_stmt->fetchColumn()) {
                $eval_ids[] = (int)$eid;
            }

            if (!empty($eval_ids)) {
                $ph_eval = implode(',', array_fill(0, count($eval_ids), '?'));
                $ack_del = $db->prepare("DELETE FROM observation_plan_acknowledgments WHERE evaluation_id IN ($ph_eval)");
                $ack_del->execute($eval_ids);

                $ev_del = $db->prepare("DELETE FROM evaluations WHERE id IN ($ph_eval)");
                $ev_del->execute($eval_ids);
            }

            // Also remove unsigned/schedule-level acks (evaluation_id IS NULL) for these teachers.
            $ack_sched_sql = "DELETE FROM observation_plan_acknowledgments
                              WHERE teacher_id IN ($ph)
                                AND academic_year = ?
                                AND semester = ?
                                AND evaluation_id IS NULL";
            $ack_sched_stmt = $db->prepare($ack_sched_sql);
            $ack_sched_stmt->execute(array_merge($teacher_ids, [$cancel_ay, $cancel_sem]));

            $db->commit();
            $success_message = 'Selected schedule(s) cancelled successfully.';
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error_message = 'Failed to cancel selected schedule(s).';
        }
    } else {
        $error_message = 'No valid teacher selected for cancellation.';
    }

    $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
    if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
    if ($success_message) $_SESSION['success'] = $success_message;
    if ($error_message) $_SESSION['error'] = $error_message;
    header("Location: $redirect");
    exit();
}

// Handle schedule setting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'join_observer') {
    $is_leader_role = in_array($_SESSION['role'] ?? '', ['president', 'vice_president'], true);
    if (!$is_leader_role) {
        $_SESSION['error'] = 'Only president/vice president can accept as observer.';
    } else {
        $raw_ids = $_POST['teacher_ids'] ?? '[]';
        $teacher_ids = [];
        if (is_string($raw_ids)) {
            $decoded_ids = json_decode($raw_ids, true);
            if (is_array($decoded_ids)) {
                $teacher_ids = $decoded_ids;
            }
        } elseif (is_array($raw_ids)) {
            $teacher_ids = $raw_ids;
        }

        $teacher_ids = array_values(array_unique(array_filter(array_map('intval', $teacher_ids), function($id) {
            return $id > 0;
        })));

        if (empty($teacher_ids)) {
            $_SESSION['error'] = 'No valid teacher selected.';
        } else {
            $insert_query = "INSERT INTO teacher_assignments (evaluator_id, teacher_id, assigned_at)
                             VALUES (:evaluator_id, :teacher_id, NOW())";
            $insert_stmt = $db->prepare($insert_query);
            $added_count = 0;
            foreach ($teacher_ids as $tid) {
                $exists_stmt = $db->prepare("SELECT 1 FROM teacher_assignments WHERE evaluator_id = :eid AND teacher_id = :tid LIMIT 1");
                $exists_stmt->execute([':eid' => $_SESSION['user_id'], ':tid' => $tid]);
                if ($exists_stmt->fetchColumn()) {
                    continue;
                }
                if ($insert_stmt->execute([':evaluator_id' => $_SESSION['user_id'], ':teacher_id' => $tid])) {
                    $added_count++;
                }
            }

            if ($added_count > 0) {
                $_SESSION['success'] = $added_count . ' teacher(s) accepted as observer.';
            } else {
                $_SESSION['info'] = 'Selected teacher(s) are already in your observer list.';
            }
        }
    }

    $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
    if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
    if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
    if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
    header("Location: $redirect");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_reschedule_request') {
    $allowed_accept_roles = ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'];
    if (!in_array($_SESSION['role'] ?? '', $allowed_accept_roles, true)) {
        $_SESSION['error'] = 'Only Dean/Principal/Coordinator can accept reschedule requests.';
    } else {
        $teacher_id_accept = (int)($_POST['teacher_id'] ?? 0);
        $notif_id_accept = (int)($_POST['notification_id'] ?? 0);

        if ($teacher_id_accept <= 0) {
            $_SESSION['error'] = 'Invalid teacher selected.';
        } else {
            try {
                // Mark request notification(s) as read for current approver.
                if ($notif_id_accept > 0) {
                    $readStmt = $db->prepare("UPDATE notifications
                                              SET is_read = 1
                                              WHERE id = :nid
                                                AND user_id = :uid
                                                AND type = 'reschedule_request'");
                    $readStmt->execute([
                        ':nid' => $notif_id_accept,
                        ':uid' => (int)($_SESSION['user_id'] ?? 0)
                    ]);
                } else {
                    $readAllStmt = $db->prepare("UPDATE notifications
                                                 SET is_read = 1
                                                 WHERE user_id = :uid
                                                   AND teacher_id = :tid
                                                   AND type = 'reschedule_request'");
                    $readAllStmt->execute([
                        ':uid' => (int)($_SESSION['user_id'] ?? 0),
                        ':tid' => $teacher_id_accept
                    ]);
                }

                // Notify teacher that request was accepted.
                $teacherUserStmt = $db->prepare("SELECT t.name, t.user_id, u.email
                                                 FROM teachers t
                                                 LEFT JOIN users u ON u.id = t.user_id
                                                 WHERE t.id = :tid
                                                 LIMIT 1");
                $teacherUserStmt->execute([':tid' => $teacher_id_accept]);
                $teacherUser = $teacherUserStmt->fetch(PDO::FETCH_ASSOC);

                if ($teacherUser && !empty($teacherUser['user_id'])) {
                    $approver_name = trim((string)($_SESSION['name'] ?? 'Evaluator'));
                    $approver_role = ucfirst(str_replace('_', ' ', (string)($_SESSION['role'] ?? 'evaluator')));
                    $title = 'Reschedule Request Accepted';
                    $message = "{$approver_name} ({$approver_role}) accepted your reschedule request and will set your new schedule.";
                    $teacherLink = 'observation_plan.php?view=my_observation';

                    $insNotif = $db->prepare("INSERT INTO notifications (user_id, teacher_id, type, title, message, link)
                                              VALUES (:uid, :tid, 'reschedule_accepted', :title, :msg, :lnk)");
                    $insNotif->execute([
                        ':uid' => (int)$teacherUser['user_id'],
                        ':tid' => $teacher_id_accept,
                        ':title' => $title,
                        ':msg' => $message,
                        ':lnk' => $teacherLink
                    ]);

                    $teacherEmail = trim((string)($teacherUser['email'] ?? ''));
                    if ($teacherEmail !== '') {
                        sendGenericNotificationEmail(
                            $teacherEmail,
                            trim((string)($teacherUser['name'] ?? 'Teacher')),
                            $title,
                            $message
                        );
                    }
                }

                $_SESSION['success'] = 'Reschedule request accepted. You can now set the new schedule.';
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to accept reschedule request.';
            }
        }
    }

    $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
    if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
    if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
    if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
    if (!empty($_POST['teacher_id'])) {
        $redirect .= '&open_reschedule=1&teacher_id=' . urlencode((string)((int)$_POST['teacher_id']));
    }
    header("Location: $redirect");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_schedule') {
    if ($is_observer_only_role) {
        $_SESSION['error'] = 'President/Vice President can only accept as observer.';
        $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
        if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
        if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
        if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
        header("Location: $redirect");
        exit();
    }

    $teacher_id = $_POST['teacher_id'] ?? ($_POST['reschedule_teacher_id'] ?? '');
    $schedule = $_POST['evaluation_schedule'] ?? '';
    $schedule_end = $_POST['evaluation_schedule_end'] ?? '';
    $room = $_POST['evaluation_room'] ?? '';
    $focus = isset($_POST['evaluation_focus']) && is_array($_POST['evaluation_focus']) ? $_POST['evaluation_focus'] : [];
    $subject_area = trim($_POST['evaluation_subject_area'] ?? '');
    $subject = trim($_POST['evaluation_subject'] ?? '');
    $post_semester = trim($_POST['evaluation_semester'] ?? '');
    $post_semester = in_array($post_semester, ['1st', '2nd']) ? $post_semester : null;
    $form_type = trim($_POST['evaluation_form_type'] ?? 'iso');
    $form_type = in_array($form_type, ['iso', 'peac', 'both']) ? $form_type : 'iso';
    // Requested department from schedule modal (validated below)
    $scheduled_department = trim($_POST['scheduled_department'] ?? '');

    $valid_focus = ['communications', 'management', 'assessment', 'teacher_actions', 'student_learning_actions'];
    $focus = array_values(array_intersect($focus, $valid_focus));
    $focus_json = !empty($focus) ? json_encode($focus) : null;

    // Determine academic year and semester context for this submission.
    // Modal sends hidden `filter_semester` and `filter_academic_year` to preserve current view filters.
    $filter_semester = trim((string)($_POST['filter_semester'] ?? ($_GET['semester'] ?? '1st')));
    if (!in_array($filter_semester, ['1st', '2nd'], true)) $filter_semester = '1st';
    $submitted_academic_year = trim((string)($_POST['filter_academic_year'] ?? ($_GET['academic_year'] ?? '')));
    if ($submitted_academic_year === '') {
        $m = (int)date('n');
        $y = (int)date('Y');
        $submitted_academic_year = ($m >= 6) ? ($y . '-' . ($y + 1)) : (($y - 1) . '-' . $y);
    }
    // Use these values when creating/finding evaluation records below.
    $academic_year_for_insert = $submitted_academic_year;

    if (!empty($teacher_id)) {
        // Extract observation_date from schedule string (format: "YYYY-MM-DD HH:MM:SS")
        $observation_date = null;
        $observation_time = null;
        if (!empty($schedule)) {
            try {
                $sched_dt = new DateTime($schedule);
                $observation_date = $sched_dt->format('Y-m-d');
                $observation_time = $sched_dt->format('H:i');
            } catch (Exception $e) {
                // Invalid date format
            }
        }
        
        // Get teacher info
        $teacher_stmt = $db->prepare("SELECT name AS faculty_name, department FROM teachers WHERE id = :id LIMIT 1");
        $teacher_stmt->bindParam(':id', $teacher_id);
        $teacher_stmt->execute();
        $teacher_row = $teacher_stmt->fetch(PDO::FETCH_ASSOC);
        $faculty_name = $teacher_row['faculty_name'] ?? '';
        $teacher_dept = $teacher_row['department'] ?? '';
        
        // Determine if this is an explicit reschedule operation. Only treat
        // as a reschedule when the modal sent the flag value '1' and the
        // hidden reschedule_teacher_id matches the selected teacher_id.
        // This prevents accidental updates when the modal state wasn't set
        // correctly on the client side.
        $reschedule_teacher_id_post = trim((string)($_POST['reschedule_teacher_id'] ?? ''));
        $is_reschedule = (
            isset($_POST['is_reschedule']) && (string)$_POST['is_reschedule'] === '1' &&
            $reschedule_teacher_id_post !== '' &&
            ((string)$reschedule_teacher_id_post === (string)$teacher_id)
        );
        $target_eval_status = $is_reschedule ? 'rescheduled' : 'draft';
        $eval_id = null;
        $debug_action = null;
        
        if ($is_reschedule) {
            // For explicit RESCHEDULE: find and reuse the most recent evaluation
            // (update existing record instead of creating new)
            $find_eval = $db->prepare("SELECT id, status FROM evaluations WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem ORDER BY id DESC LIMIT 1");
            $find_eval->execute([':tid' => $teacher_id, ':ay' => $academic_year_for_insert, ':sem' => ($post_semester ?: $filter_semester)]);
            $eval_row = $find_eval->fetch(PDO::FETCH_ASSOC);
            if ($eval_row && $eval_row['status'] !== 'completed') {
                $eval_id = $eval_row['id'];
            }
            // If completed or no row exists, still create new on reschedule
        } else {
            // For normal "Set Schedule": ALWAYS CREATE NEW evaluation record
            // This allows teachers to have multiple scheduled observations
            $eval_id = null;  // Force creation of new evaluation record
        }
        
        // Determine current user's name
        $current_user_name = $_SESSION['name'] ?? 'System';
        $current_evaluator_id = $_SESSION['user_id'] ?? null;
        
        // Update or create evaluation record with schedule info
        if ($eval_id) {
            // Update existing evaluation
            $upd_eval = $db->prepare("UPDATE evaluations SET 
                observation_date = :obs_date, 
                observation_time = :obs_time,
                observation_room = :room,
                subject_area = :subject_area,
                subject_observed = :subject,
                evaluation_focus = :focus,
                status = :status,
                updated_at = NOW()
                WHERE id = :eval_id");
            $upd_eval->execute([
                ':obs_date' => $observation_date,
                ':obs_time' => $observation_time,
                ':room' => $room,
                ':subject_area' => $subject_area,
                ':subject' => $subject,
                ':focus' => $focus_json,
                ':status' => $target_eval_status,
                ':eval_id' => $eval_id
            ]);
            // Debug: log update action
            try {
                $upd_count = $upd_eval->rowCount();
                $debug_action = "UPDATE eval_id={$eval_id} affected={$upd_count}";
                $log_line = date('c') . " UPDATE teacher_id={$teacher_id} eval_id={$eval_id} ay={$academic_year_for_insert} sem=" . ($post_semester ?: $filter_semester) . " affected={$upd_count} schedule=" . (string)$schedule . PHP_EOL;
                $eval_log_path = __DIR__ . DIRECTORY_SEPARATOR . 'schedule_debug.log';
                $tmp_log_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adces_schedule_debug.log';
                // Write to evaluator folder (if writable) and to system temp for broader visibility
                @file_put_contents($eval_log_path, $log_line, FILE_APPEND | LOCK_EX);
                @file_put_contents($tmp_log_path, $log_line, FILE_APPEND | LOCK_EX);
                error_log($log_line);
                $_SESSION['schedule_debug_line'] = $log_line;
            } catch (Exception $e) {
                error_log('schedule debug write failed: ' . $e->getMessage());
            }
        } else {
            // Create new evaluation record
            $ins_eval = $db->prepare("INSERT INTO evaluations 
                (teacher_id, faculty_name, department, evaluator_id, academic_year, semester, 
                 observation_date, observation_time, observation_room, subject_area, subject_observed, 
                 evaluation_focus, status, created_at, updated_at) 
                VALUES 
                (:tid, :faculty_name, :dept, :evaluator_id, :ay, :sem, 
                 :obs_date, :obs_time, :room, :subject_area, :subject, 
                 :focus, :status, NOW(), NOW())");
            $ins_eval->execute([
                ':tid' => $teacher_id,
                ':faculty_name' => $faculty_name,
                ':dept' => $teacher_dept,
                ':evaluator_id' => $current_evaluator_id,
                ':ay' => $academic_year_for_insert,
                ':sem' => ($post_semester ?: $filter_semester),
                ':obs_date' => $observation_date,
                ':obs_time' => $observation_time,
                ':room' => $room,
                ':subject_area' => $subject_area,
                ':subject' => $subject,
                ':focus' => $focus_json,
                ':status' => $target_eval_status
            ]);
            $eval_id = $db->lastInsertId();
            // Debug: log insert action
            try {
                $debug_action = "INSERT eval_id={$eval_id}";
                $log_line = date('c') . " INSERT teacher_id={$teacher_id} eval_id={$eval_id} ay={$academic_year_for_insert} sem=" . ($post_semester ?: $filter_semester) . " schedule=" . (string)$schedule . PHP_EOL;
                $eval_log_path = __DIR__ . DIRECTORY_SEPARATOR . 'schedule_debug.log';
                $tmp_log_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adces_schedule_debug.log';
                @file_put_contents($eval_log_path, $log_line, FILE_APPEND | LOCK_EX);
                @file_put_contents($tmp_log_path, $log_line, FILE_APPEND | LOCK_EX);
                error_log($log_line);
                $_SESSION['schedule_debug_line'] = $log_line;
            } catch (Exception $e) {
                error_log('schedule debug write failed: ' . $e->getMessage());
            }
        }
        
        // Also update teachers table for backwards compatibility, but avoid overwriting
        // an existing teacher-level schedule unless this is an explicit reschedule.
        // This preserves previous schedule data while we store new schedules on the
        // `evaluations` table (so teachers can have multiple scheduled evaluations).
        $teacher_info_stmt = $db->prepare("SELECT name AS faculty_name, department, evaluation_schedule FROM teachers WHERE id = :id LIMIT 1");
        $teacher_info_stmt->bindParam(':id', $teacher_id);
        $teacher_info_stmt->execute();
        $teacher_row_info = $teacher_info_stmt->fetch(PDO::FETCH_ASSOC);
        $teacher_primary_dept = trim((string)($teacher_row_info['department'] ?? ''));
        $existing_teacher_schedule = trim((string)($teacher_row_info['evaluation_schedule'] ?? ''));

        $should_update_teacher_table = $is_reschedule || $existing_teacher_schedule === '';

        if ($should_update_teacher_table) {
            $query = "UPDATE teachers SET evaluation_schedule = :schedule, evaluation_schedule_end = :schedule_end, evaluation_room = :room, evaluation_focus = :focus, evaluation_subject_area = :subject_area, evaluation_subject = :subject, evaluation_semester = :semester, evaluation_form_type = :form_type, scheduled_by = :scheduled_by, scheduled_department = :scheduled_department, updated_at = NOW() WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':schedule', $schedule);
            $stmt->bindParam(':schedule_end', $schedule_end);
            $stmt->bindParam(':room', $room);
            $stmt->bindParam(':focus', $focus_json);
            $stmt->bindParam(':subject_area', $subject_area);
            $stmt->bindParam(':subject', $subject);
            $stmt->bindParam(':semester', $post_semester);
            $stmt->bindParam(':form_type', $form_type);
            $is_leader_role = in_array($_SESSION['role'], ['president', 'vice_president']);
            $stmt->bindValue(':scheduled_by', $is_leader_role ? $_SESSION['user_id'] : null, $is_leader_role ? PDO::PARAM_INT : PDO::PARAM_NULL);

            // Resolve teacher secondary departments
            $teacher_all_depts = [];
            if ($teacher_primary_dept !== '') $teacher_all_depts[] = $teacher_primary_dept;
            try {
                if ($db) {
                    $teacher_sec_stmt = $db->prepare("SELECT department FROM teacher_departments WHERE teacher_id = :id");
                    $teacher_sec_stmt->execute([':id' => $teacher_id]);
                    while ($sec_dept = $teacher_sec_stmt->fetchColumn()) {
                        $sec_dept = trim((string)$sec_dept);
                        if ($sec_dept !== '' && !in_array($sec_dept, $teacher_all_depts, true)) {
                            $teacher_all_depts[] = $sec_dept;
                        }
                    }
                }
            } catch (Exception $e) {}

            // For non-leaders: allow selected department when evaluator is assigned to this teacher
            $is_assigned_to_teacher = false;
            if (!$is_leader_role) {
                try {
                    $assign_chk = $db->prepare("SELECT 1 FROM teacher_assignments WHERE teacher_id = :tid AND evaluator_id = :eid LIMIT 1");
                    $assign_chk->execute([':tid' => $teacher_id, ':eid' => $_SESSION['user_id']]);
                    $is_assigned_to_teacher = (bool)$assign_chk->fetchColumn();
                } catch (Exception $e) {}
            }

            $user_dept = trim((string)($_SESSION['department'] ?? ''));
            $can_use_selected_dept =
                $scheduled_department !== '' &&
                in_array($scheduled_department, $teacher_all_depts, true) &&
                (
                    $is_leader_role ||
                    $scheduled_department === $user_dept ||
                    $is_assigned_to_teacher
                );

            if ($can_use_selected_dept) {
                $sched_dept_val = $scheduled_department;
            } else {
                // Fallback to teacher's primary department
                $sched_dept_val = $teacher_primary_dept !== '' ? $teacher_primary_dept : ($user_dept !== '' ? $user_dept : null);
            }

            // PEAC is exclusive to JHS department
            if (($form_type === 'peac' || $form_type === 'both') && $sched_dept_val !== 'JHS') {
                $form_type = 'iso';
                $stmt->bindParam(':form_type', $form_type);
            }
            $stmt->bindValue(':scheduled_department', $sched_dept_val);
            $stmt->bindParam(':id', $teacher_id);

            $teacher_update_ok = $stmt->execute();
        } else {
            // Do not overwrite teacher-level schedule; preserve existing teacher data
            $teacher_update_ok = true;
            // Ensure we have a value for scheduled department for notifications
            $user_dept = trim((string)($_SESSION['department'] ?? ''));
            $sched_dept_val = $teacher_primary_dept !== '' ? $teacher_primary_dept : ($user_dept !== '' ? $user_dept : null);
        }

        // Delete all unsigned upcoming-plan acknowledgments for this teacher/AY/semester.
        $del_sem = $post_semester ?: ($_POST['filter_semester'] ?? ($_GET['semester'] ?? '1st'));
        $del_ay = $_POST['filter_academic_year'] ?? ($_GET['academic_year'] ?? '');
        if (!empty($del_ay)) {
            $del_ack = $db->prepare("DELETE FROM observation_plan_acknowledgments WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem AND evaluation_id IS NULL");
            $del_ack->execute([':tid' => $teacher_id, ':ay' => $del_ay, ':sem' => $del_sem]);
        }

        $success_message = $is_reschedule ? "Schedule updated. Teacher will need to sign again." : "Evaluation schedule set successfully!";
        if (!empty($debug_action)) {
            $success_message .= " (debug: {" . $debug_action . "})";
        }
        if (!$teacher_update_ok) {
            $error_message = "Failed to set schedule.";
        }
        notifyScheduleParticipants($db, $teacher_id, $schedule, $room, $_SESSION['user_id'], $_SESSION['name'] ?? 'Evaluator', $_SESSION['role'] ?? '', $sched_dept_val ?? '');
    } else {
        $error_message = "Teacher ID is required.";
    }

    // Redirect to avoid resubmission, preserving filters
    $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
    if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
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
    // Handle teacher reschedule request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_reschedule_my') {
        $req_semester = trim((string)($_POST['semester'] ?? ''));
        $req_academic_year = trim((string)($_POST['academic_year'] ?? ''));
        $req_item = trim((string)($_POST['reschedule_item'] ?? ''));
        $req_reason = trim((string)($_POST['reschedule_reason'] ?? ''));
        $req_other_reason = trim((string)($_POST['reschedule_reason_other'] ?? ''));

        $allowed_reasons = ['emergency', 'conflict_schedule', 'others'];
        if (!in_array($req_reason, $allowed_reasons, true)) {
            $error_message = 'Invalid reschedule reason.';
        } elseif ($req_reason === 'others' && $req_other_reason === '') {
            $error_message = 'Please provide details for "Others".';
        } elseif ($req_item === '') {
            $error_message = 'Please select a schedule to request reschedule.';
        } else {
            try {
                // Resolve teacher basic info
                $tReqStmt = $db->prepare("SELECT id, name, department, scheduled_department, evaluation_schedule, evaluation_room FROM teachers WHERE id = :tid LIMIT 1");
                $tReqStmt->execute([':tid' => $my_teacher_id]);
                $tReq = $tReqStmt->fetch(PDO::FETCH_ASSOC);

                $teacher_name_req = $tReq['name'] ?? ($_SESSION['name'] ?? 'Teacher');
                $teacher_dept_req = trim((string)($tReq['scheduled_department'] ?? ''));
                if ($teacher_dept_req === '') $teacher_dept_req = trim((string)($tReq['department'] ?? ''));

                $req_eval_id = null;
                $req_sched = trim((string)($tReq['evaluation_schedule'] ?? ''));
                $req_room = trim((string)($tReq['evaluation_room'] ?? ''));
                $req_subject = '';
                $req_subject_area = '';

                if ($req_item !== 'upcoming') {
                    $req_eval_id = (int)$req_item;
                    if ($req_eval_id > 0) {
                        $eReqStmt = $db->prepare("SELECT e.id, e.observation_date, e.observation_time, e.observation_room, e.subject_observed, e.subject_area, u.department AS evaluator_department
                                                  FROM evaluations e
                                                  LEFT JOIN users u ON u.id = e.evaluator_id
                                                  WHERE e.id = :eid AND e.teacher_id = :tid AND e.academic_year = :ay AND e.semester = :sem
                                                  LIMIT 1");
                        $eReqStmt->execute([
                            ':eid' => $req_eval_id,
                            ':tid' => $my_teacher_id,
                            ':ay' => $req_academic_year,
                            ':sem' => $req_semester
                        ]);
                        $eReq = $eReqStmt->fetch(PDO::FETCH_ASSOC);
                        if ($eReq) {
                            $od = trim((string)($eReq['observation_date'] ?? ''));
                            $ot = trim((string)($eReq['observation_time'] ?? ''));
                            if ($od !== '') {
                                $req_sched = $od . ($ot !== '' ? (' ' . $ot) : '');
                            }
                            $req_room = trim((string)($eReq['observation_room'] ?? $req_room));
                            $req_subject = trim((string)($eReq['subject_observed'] ?? ''));
                            $req_subject_area = trim((string)($eReq['subject_area'] ?? ''));
                            $evDept = trim((string)($eReq['evaluator_department'] ?? ''));
                            if ($evDept !== '') $teacher_dept_req = $evDept;
                        }
                    }
                }

                $reason_label = $req_reason === 'emergency'
                    ? 'Emergency'
                    : ($req_reason === 'conflict_schedule' ? 'Conflict of Schedule' : 'Others');
                $reason_text = $reason_label . ($req_reason === 'others' ? (': ' . $req_other_reason) : '');

                // Recipients = observers for the selected row in "My Evaluation Schedule".
                // President/VP should only receive the request when they explicitly accepted
                // this teacher as observer (have a teacher_assignments row for this teacher).
                $observerRows = [];
                try {
                    if ($teacher_dept_req !== '') {
                        if (!empty($req_eval_id)) {
                            // Evaluation row observer logic:
                            // explicitly assigned observers + dean/principal of row department + row evaluator owner.
                            $obsStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                                     FROM teacher_assignments ta
                                                     JOIN users u ON u.id = ta.evaluator_id
                                                     WHERE ta.teacher_id = :tid
                                                       AND u.status = 'active'
                                                       AND u.email IS NOT NULL
                                                       AND u.email <> ''");
                            $obsStmt->execute([
                                ':tid' => (int)$my_teacher_id
                            ]);
                            $observerRows = $obsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                            $deptLeadsStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                                           FROM users u
                                                           WHERE u.department = :dept
                                                             AND u.role IN ('dean','principal')
                                                             AND u.status = 'active'
                                                             AND u.email IS NOT NULL
                                                             AND u.email <> ''");
                            $deptLeadsStmt->execute([':dept' => $teacher_dept_req]);
                            $observerRows = array_merge($observerRows, $deptLeadsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

                            $ownerStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                                       FROM evaluations e
                                                       JOIN users u ON u.id = e.evaluator_id
                                                       WHERE e.id = :eid
                                                         AND u.status = 'active'
                                                         AND u.email IS NOT NULL
                                                         AND u.email <> ''
                                                       LIMIT 1");
                            $ownerStmt->execute([':eid' => (int)$req_eval_id]);
                            $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
                            if ($owner) $observerRows[] = $owner;
                        } else {
                            // Upcoming row observer logic:
                            // explicitly assigned observers + dean/principal of schedule dept.
                            $obsStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                                     FROM teacher_assignments ta
                                                     JOIN users u ON u.id = ta.evaluator_id
                                                     WHERE ta.teacher_id = :tid
                                                       AND u.status = 'active'
                                                       AND u.email IS NOT NULL
                                                       AND u.email <> ''");
                            $obsStmt->execute([
                                ':tid' => (int)$my_teacher_id
                            ]);
                            $observerRows = $obsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                            $deptLeadsStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                                           FROM users u
                                                           WHERE u.department = :dept
                                                             AND u.role IN ('dean','principal')
                                                             AND u.status = 'active'
                                                             AND u.email IS NOT NULL
                                                             AND u.email <> ''");
                            $deptLeadsStmt->execute([':dept' => $teacher_dept_req]);
                            $observerRows = array_merge($observerRows, $deptLeadsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
                        }
                    }
                } catch (Exception $e) {}

                // Build set of accepted observers for this teacher.
                // This is the gate for president/vice president email recipients.
                $acceptedObserverIds = [];
                try {
                    $acceptedStmt = $db->prepare("SELECT evaluator_id FROM teacher_assignments WHERE teacher_id = :tid");
                    $acceptedStmt->execute([':tid' => (int)$my_teacher_id]);
                    while ($acc = $acceptedStmt->fetch(PDO::FETCH_ASSOC)) {
                        $aid = (int)($acc['evaluator_id'] ?? 0);
                        if ($aid > 0) $acceptedObserverIds[$aid] = true;
                    }
                } catch (Exception $e) {}

                $byId = [];
                $myUserId = (int)($_SESSION['user_id'] ?? 0);
                $myUserName = trim((string)($_SESSION['name'] ?? ''));
                foreach ($observerRows as $r) {
                    $rid = (int)($r['id'] ?? 0);
                    $rrole = strtolower(trim((string)($r['role'] ?? '')));
                    $rname = trim((string)($r['name'] ?? ''));
                    if ($rid <= 0) continue;
                    if (in_array($rrole, ['president', 'vice_president', 'vice president'], true) && empty($acceptedObserverIds[$rid])) {
                        continue;
                    }
                    if ($myUserId > 0 && $rid === $myUserId) continue;
                    if ($myUserName !== '' && strcasecmp($rname, $myUserName) === 0) continue;
                    $byId[$rid] = $r;
                }
                $recipients = array_values($byId);

                $formatted_sched = $req_sched ? date('F d, Y \a\t h:i A', strtotime($req_sched)) : 'TBA';
                $subject = "Reschedule Request - {$teacher_name_req}";
                $msg = "Teacher {$teacher_name_req} requested reschedule for {$formatted_sched}. Reason: {$reason_text}.";
                if ($req_room !== '') $msg .= " Room: {$req_room}.";
                if ($req_subject !== '') $msg .= " Subject: {$req_subject}.";

                $notifLink = 'observation_plan.php?' . http_build_query([
                    'open_reschedule' => 1,
                    'teacher_id' => (int)$my_teacher_id,
                    'semester' => $req_semester,
                    'academic_year' => $req_academic_year
                ]);

                foreach ($recipients as $rcp) {
                    if (!empty($rcp['email'])) {
                        sendGenericNotificationEmail(
                            $rcp['email'],
                            $rcp['name'] ?? 'Evaluator',
                            $subject,
                            $msg
                        );
                    }
                    try {
                        $notif = $db->prepare("INSERT INTO notifications (user_id, teacher_id, type, title, message, link) VALUES (:uid, :tid, 'reschedule_request', :title, :msg, :link)");
                        $notif->execute([
                            ':uid' => (int)$rcp['id'],
                            ':tid' => (int)$my_teacher_id,
                            ':title' => $subject,
                            ':msg' => $msg,
                            ':link' => $notifLink
                        ]);
                    } catch (Exception $e) {}
                }

                $success_message = 'Reschedule request sent successfully.';
            } catch (Exception $e) {
                $error_message = 'Failed to send reschedule request.';
            }
        }
    }

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
                    // Upcoming schedules are owned by the active scheduled
                    // department. Fall back to the teacher's primary department
                    // for older records where scheduled_department is empty.
                    $tdStmt = $db->prepare("SELECT scheduled_department, department FROM teachers WHERE id = :tid LIMIT 1");
                    $tdStmt->execute([':tid' => $my_teacher_id]);
                    $tRow = $tdStmt->fetch(PDO::FETCH_ASSOC);
                    $sign_dept = $tRow['scheduled_department'] ?: ($tRow['department'] ?? null);
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
                    $pdStmt = $db->prepare("SELECT scheduled_department, department FROM teachers WHERE id = :id LIMIT 1");
                    $pdStmt->execute([':id' => $my_teacher_id]);
                    $pdRow = $pdStmt->fetch(PDO::FETCH_ASSOC);
                    $pd = ($pdRow['scheduled_department'] ?? '') ?: ($pdRow['department'] ?? '');
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
$is_observer_only = $is_leader;
$is_coordinator = in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator']);

$all_departments = ['ELEM', 'JHS', 'SHS', 'CCIS', 'CAS', 'CTEAS', 'CBM', 'CTHM', 'CCJE'];
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
    $raw_department = in_array($requested_department, $available_filter_departments, true)
        ? $requested_department
        : ($available_filter_departments[0] ?? $session_department);
} else {
    $available_filter_departments = $session_department !== '' ? [$session_department] : [];
    $raw_department = $session_department;
}
$department_display = $raw_department === '' ? 'All Departments' : ($department_map[$raw_department] ?? $raw_department);

// Get semester filter
$semester = $_GET['semester'] ?? '1st';
$academic_year = $_GET['academic_year'] ?? '';
$filter_month = $_GET['month'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Normalize department values (code <-> full label) so filters are consistent.
$department_alias_to_code = [
    'College of Computing and Information Sciences' => 'CCIS',
    'College of Business and Management' => 'CBM',
    'College of Arts and Sciences' => 'CAS',
    'College of Criminal Justice Education' => 'CCJE',
    'College of Tourism and Hospitality Management' => 'CTHM',
    'College of Teacher Education, Arts and Sciences' => 'CTEAS',
    'Elementary Department' => 'ELEM',
    'Junior High School Department' => 'JHS',
    'Senior High School Department' => 'SHS',
];
$normalize_dept = static function (string $dept) use ($department_alias_to_code): string {
    $d = trim($dept);
    if ($d === '') return '';
    return $department_alias_to_code[$d] ?? $d;
};
$my_filter_department = $normalize_dept((string)($_GET['department_my'] ?? ''));

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
        // Observer list for My Observation:
        // use schedule owning department, and include President/VP only if accepted (assigned).
        $my_sched_dept = $normalize_dept((string)($my_teacher_data['scheduled_department'] ?? ''));
        if ($my_sched_dept === '') $my_sched_dept = $normalize_dept((string)($my_teacher_data['department'] ?? ''));
        $obs_query = "SELECT DISTINCT u.name
                      FROM teacher_assignments ta
                      JOIN users u ON ta.evaluator_id = u.id
                      WHERE ta.teacher_id = :tid
                        AND (
                            u.department = :dept
                            OR u.role IN ('president','vice_president')
                        )
                        AND u.status = 'active'
                      ORDER BY u.name";
        $obs_stmt = $db->prepare($obs_query);
        $obs_stmt->execute([':tid' => $my_teacher_id, ':dept' => $my_sched_dept]);
        $my_observer_names = $obs_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Always include dean/principal of the schedule-owning department.
        if ($my_sched_dept !== '') {
            $dean_stmt = $db->prepare("SELECT DISTINCT name FROM users WHERE department = :dept AND role IN ('dean','principal') AND status = 'active' ORDER BY name");
            $dean_stmt->execute([':dept' => $my_sched_dept]);
            while ($dn = $dean_stmt->fetchColumn()) {
                if (!in_array($dn, $my_observer_names, true)) {
                    $my_observer_names[] = $dn;
                }
            }
        }
        $my_own_name = $_SESSION['name'] ?? '';
        $my_observer_names = array_values(array_filter(array_unique($my_observer_names), function($n) use ($my_own_name) {
            return trim((string)$n) !== trim((string)$my_own_name);
        }));

        // Get completed evaluations
        $eval_query = "SELECT e.id, e.observation_date, e.observation_time, e.status, e.subject_area, e.subject_observed, e.observation_room, e.semester, e.evaluation_focus, u.name as evaluator_name, u.department as evaluator_department
                       FROM evaluations e JOIN users u ON e.evaluator_id = u.id
                       WHERE e.teacher_id = :tid AND e.academic_year = :ay AND e.semester = :sem
                       ORDER BY e.observation_date ASC";
        $eval_stmt = $db->prepare($eval_query);
        $eval_stmt->execute([':tid' => $my_teacher_id, ':ay' => $academic_year, ':sem' => $semester]);
        $my_evaluations = $eval_stmt->fetchAll(PDO::FETCH_ASSOC);

        // My Observation department filter (optional)
        if ($my_filter_department !== '') {
            $my_sched_dept_local = $normalize_dept((string)($my_teacher_data['scheduled_department'] ?? ''));
            if ($my_sched_dept_local === '') $my_sched_dept_local = $normalize_dept((string)($my_teacher_data['department'] ?? ''));
            if ($my_sched_dept_local !== $my_filter_department) {
                $my_teacher_data['evaluation_schedule'] = null;
                $my_teacher_data['evaluation_semester'] = null;
            }
            $my_evaluations = array_values(array_filter($my_evaluations, function($ev) use ($my_filter_department, $db) {
                $eid = (int)($ev['id'] ?? 0);
                if ($eid <= 0) return false;
                $d = $db->prepare("SELECT u.department FROM evaluations e JOIN users u ON u.id = e.evaluator_id WHERE e.id = :eid LIMIT 1");
                $d->execute([':eid' => $eid]);
                $ev_dept = trim((string)$d->fetchColumn());
                if ($ev_dept === '') return false;
                return $ev_dept === $my_filter_department;
            }));
        }

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
    // President/VP: show evaluations in the selected department, or all if no department filter
    if (!empty($raw_department)) {
        $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                         t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                         t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                         t.scheduled_by, t.scheduled_department,
                         e.id as eval_id, e.observation_date, e.observation_time, e.status as eval_status, e.faculty_signature,
                         e.subject_observed, e.observation_room as eval_room,
                         e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                         e.semester as eval_semester
                  FROM teachers t
                  JOIN evaluations e ON e.teacher_id = t.id
                  LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                  WHERE (
                        (
                            t.scheduled_department IS NOT NULL
                            AND t.scheduled_department <> ''
                            AND t.scheduled_department = :dept3
                        )
                        OR
                        (
                            (t.scheduled_department IS NULL OR t.scheduled_department = '')
                            AND (t.department = :dept1 OR td.department = :dept2)
                        )
                  )
                  AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                  AND e.academic_year = :academic_year
                  AND e.semester = :semester
                  ORDER BY t.name ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':dept1', $raw_department);
        $stmt->bindParam(':dept2', $raw_department);
        $stmt->bindParam(':dept3', $raw_department);
        $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
        $stmt->bindParam(':academic_year', $academic_year);
        $stmt->bindParam(':semester', $semester);
    } else {
        $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                         t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                         t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                         t.scheduled_by, t.scheduled_department,
                         e.id as eval_id, e.observation_date, e.observation_time, e.status as eval_status, e.faculty_signature,
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
                     t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     t.scheduled_by, t.scheduled_department,
                     e.id as eval_id, e.observation_date, e.observation_time, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              LEFT JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :assigned_evaluator_id
              LEFT JOIN users tu ON tu.id = t.user_id
              WHERE (ta.evaluator_id IS NOT NULL OR e.evaluator_id = :evaluator_id)
              AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              AND (tu.id IS NULL OR tu.role NOT IN ('dean','principal','president','vice_president'))
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
                     t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                     t.scheduled_by, t.scheduled_department,
                     e.id as eval_id, e.observation_date, e.observation_time, e.status as eval_status, e.faculty_signature,
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

// 2) Teachers with a schedule set but no evaluation yet for that schedule date
if ($is_leader) {
    // President/VP: show ALL scheduled teachers (optionally filtered by department) so they can accept as observer
    if (!empty($raw_department)) {
        $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                               t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                               t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                               t.scheduled_by, t.scheduled_department
                        FROM teachers t
                        LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                        WHERE t.status = 'active'
                          AND t.evaluation_schedule IS NOT NULL
                          AND (
                                (
                                    t.scheduled_department IS NOT NULL
                                    AND t.scheduled_department <> ''
                                    AND t.scheduled_department = :filter_dept3
                                )
                                OR
                                (
                                    (t.scheduled_department IS NULL OR t.scheduled_department = '')
                                    AND (t.department = :filter_dept OR td.department = :filter_dept2)
                                )
                              )
                          AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                          AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                          AND NOT EXISTS (
                              SELECT 1 FROM evaluations e2
                              WHERE e2.teacher_id = t.id
                                AND e2.academic_year = :academic_year
                                AND e2.semester = :semester
                                AND e2.status = 'completed'
                                AND DATE_FORMAT(e2.observation_date, '%Y-%m-%d %H:%i') = DATE_FORMAT(t.evaluation_schedule, '%Y-%m-%d %H:%i')
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
                               t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                               t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                               t.scheduled_by, t.scheduled_department
                        FROM teachers t
                        WHERE t.status = 'active'
                          AND t.evaluation_schedule IS NOT NULL
                          AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                          AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                          AND NOT EXISTS (
                              SELECT 1 FROM evaluations e2
                              WHERE e2.teacher_id = t.id
                                AND e2.academic_year = :academic_year
                                AND e2.semester = :semester
                                AND e2.status = 'completed'
                                AND DATE_FORMAT(e2.observation_date, '%Y-%m-%d %H:%i') = DATE_FORMAT(t.evaluation_schedule, '%Y-%m-%d %H:%i')
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
                           t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                           t.scheduled_by, t.scheduled_department
                    FROM teachers t
                    JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :assigned_evaluator_id
                    LEFT JOIN users tu ON tu.id = t.user_id
                    WHERE t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                      AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                      AND (tu.id IS NULL OR tu.role NOT IN ('dean','principal','president','vice_president'))
                      AND NOT EXISTS (
                          SELECT 1 FROM evaluations e2
                          WHERE e2.teacher_id = t.id
                            AND e2.academic_year = :academic_year
                            AND e2.semester = :semester
                            AND e2.status = 'completed'
                            AND DATE_FORMAT(e2.observation_date, '%Y-%m-%d %H:%i') = DATE_FORMAT(t.evaluation_schedule, '%Y-%m-%d %H:%i')
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
                           t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
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
                      AND NOT EXISTS (
                          SELECT 1 FROM evaluations e2
                          WHERE e2.teacher_id = t.id
                            AND e2.academic_year = :academic_year
                            AND e2.semester = :semester
                            AND e2.status = 'completed'
                            AND DATE_FORMAT(e2.observation_date, '%Y-%m-%d %H:%i') = DATE_FORMAT(t.evaluation_schedule, '%Y-%m-%d %H:%i')
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
// Keep a map of evaluation datetimes per teacher so we can detect
// when a teacher-level schedule matches any existing evaluation.
$eval_dates_by_teacher = [];

// For leaders: build a set of teacher IDs they have opted into as observer
$leader_opted_teachers = [];
if ($is_leader) {
    $opt_stmt = $db->prepare("SELECT teacher_id FROM teacher_assignments WHERE evaluator_id = :eid");
    $opt_stmt->execute([':eid' => $_SESSION['user_id']]);
    while ($opt_row = $opt_stmt->fetch(PDO::FETCH_ASSOC)) {
        $leader_opted_teachers[(int)$opt_row['teacher_id']] = true;
    }
    // Backward compatibility: include teachers scheduled by this leader even if assignment row is missing.
    try {
        $sched_by_me_stmt = $db->prepare("SELECT id FROM teachers WHERE scheduled_by = :eid AND evaluation_schedule IS NOT NULL");
        $sched_by_me_stmt->execute([':eid' => $_SESSION['user_id']]);
        while ($sched_tid = $sched_by_me_stmt->fetchColumn()) {
            $leader_opted_teachers[(int)$sched_tid] = true;
        }
    } catch (Exception $e) {}
}

// If schedule was set by a President/VP, include that leader name as observer
// even without explicit "Accept as Observer" assignment.
$get_schedule_setter_name = function(int $scheduled_by_id) use ($db): string {
    static $name_cache = [];
    if ($scheduled_by_id <= 0) return '';
    if (array_key_exists($scheduled_by_id, $name_cache)) {
        return $name_cache[$scheduled_by_id];
    }
    $name_cache[$scheduled_by_id] = '';
    try {
        $u_stmt = $db->prepare("SELECT name FROM users WHERE id = :id AND role IN ('president','vice_president','vice president') AND status = 'active' LIMIT 1");
        $u_stmt->execute([':id' => $scheduled_by_id]);
        $name = trim((string)$u_stmt->fetchColumn());
        if ($name !== '') $name_cache[$scheduled_by_id] = $name;
    } catch (Exception $e) {}
    return $name_cache[$scheduled_by_id];
};
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
    $eval_id = isset($t['eval_id']) && $t['eval_id'] ? (int)$t['eval_id'] : 0;

    // Create a stable row key per evaluation so multiple evaluations for the
    // same teacher show up as separate rows in the table.
    $row_key = $eval_id > 0 ? ('eval_' . $eval_id) : ($tid . '_r' . uniqid());
    $t['_row_key'] = $row_key;
    $teachers_list[] = $t;

    // Mark teacher id seen so scheduled-only rows know this teacher already
    // has evaluation rows and we can render schedule rows as separate entries.
    $seen_ids[$tid] = true;

    $obs_date = $t['observation_date'] ?? '';
    $eval_status = strtolower(trim((string)($t['eval_status'] ?? '')));
    $is_done = ($eval_status === 'completed');
    $faculty_sig = $t['faculty_signature'] ?? '';

    // Store per-row eval data (used during rendering)
    $eval_data[$row_key] = ['date' => $obs_date, 'done' => $is_done, 'faculty_signature' => $faculty_sig, 'eval_id' => $eval_id ?: null, 'status' => $eval_status];

    // Track all evaluation datetimes for this teacher to compare against
    // teacher-level schedules later (to avoid duplicate rows when identical).
    if (!empty($obs_date)) {
        $obs_time = trim((string)($t['observation_time'] ?? ''));
        $obs_dt_raw = $obs_time !== '' ? ($obs_date . ' ' . $obs_time) : $obs_date;
        $norm = date('Y-m-d H:i', strtotime($obs_dt_raw));
        if (!isset($eval_dates_by_teacher[$tid]) || !in_array($norm, $eval_dates_by_teacher[$tid], true)) {
            $eval_dates_by_teacher[$tid][] = $norm;
        }
    }

    // Completed rows are history, so they should use the evaluation record.
    // Upcoming rows use the current schedule stored on the teacher.
    $focus_raw = $is_done ? ($t['eval_focus'] ?? $t['evaluation_focus'] ?? '') : ($t['evaluation_focus'] ?? $t['eval_focus'] ?? '');
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);

    $schedule_data[$row_key] = [
        'semester' => $is_done ? ($t['eval_semester'] ?? $t['evaluation_semester'] ?? '') : ($t['evaluation_semester'] ?? $t['eval_semester'] ?? ''),
        'focus' => implode(', ', $focus_display),
        'day_time' => '',
        'subject_area' => $is_done ? ($t['eval_subject_area'] ?? $t['evaluation_subject_area'] ?? '') : ($t['evaluation_subject_area'] ?? $t['eval_subject_area'] ?? ''),
        'subject' => $is_done ? ($t['subject_observed'] ?? $t['evaluation_subject'] ?? '') : ($t['evaluation_subject'] ?? $t['subject_observed'] ?? ''),
        'room' => $is_done ? ($t['eval_room'] ?? $t['evaluation_room'] ?? '') : ($t['evaluation_room'] ?? $t['eval_room'] ?? ''),
    ];
    // Day & Time from schedule or observation_date
    $sched_dt = $t['evaluation_schedule'] ?? '';
    $sched_dt_end = $t['evaluation_schedule_end'] ?? '';
    if (!$is_done && !empty($sched_dt)) {
        $ts = strtotime($sched_dt);
        $day_str = date('D', $ts);
        $start_time = date('g:ia', $ts);
        if (!empty($sched_dt_end)) {
            $ts_end = strtotime($sched_dt_end);
            $end_time = date('g:ia', $ts_end);
            $schedule_data[$row_key]['day_time'] = $day_str . "\n" . $start_time . ' - ' . $end_time;
        } else {
            $schedule_data[$row_key]['day_time'] = $day_str . "\n" . $start_time;
        }
    } elseif (!empty($obs_date)) {
        $schedule_data[$row_key]['day_time'] = date('D', strtotime($obs_date));
    }

    // Get observers — based on the department that owns this schedule/evaluation
    // Determine the "owning" department: scheduled_department > teacher's primary dept > evaluator's dept
    $sched_dept_val = $t['scheduled_department'] ?? '';
    $teacher_primary_dept = $t['teacher_department'] ?? '';
    $row_owning_dept = !empty($sched_dept_val) ? $sched_dept_val : $teacher_primary_dept;
    $owning_dept = $row_owning_dept;
    $is_secondary_dept = !empty($raw_department) && $teacher_primary_dept !== $raw_department;

    // For leaders: find which department this evaluation belongs to
    if ($is_leader) {
        // Determine owning dept: use scheduled_department first, then teacher's primary dept
        $owning_dept = '';
        if (!empty($sched_dept_val)) {
            $owning_dept = $sched_dept_val;
        } elseif (!empty($teacher_primary_dept)) {
            $owning_dept = $teacher_primary_dept;
        } else {
            // Fallback for legacy rows with missing teacher/scheduled department
            $eval_dept_stmt = $db->prepare("SELECT DISTINCT u.department FROM evaluations e JOIN users u ON e.evaluator_id = u.id WHERE e.teacher_id = :tid AND e.academic_year = :ay AND e.semester = :sem AND u.department IS NOT NULL LIMIT 1");
            $eval_dept_stmt->execute([':tid' => $tid, ':ay' => $academic_year, ':sem' => $semester]);
            $owning_dept = $eval_dept_stmt->fetchColumn() ?: '';
        }
        if (empty($owning_dept)) $owning_dept = $teacher_primary_dept;

        // Show evaluators from the owning department only (exclude other departments)
        $obs_query = "SELECT DISTINCT u.name FROM evaluations e JOIN users u ON e.evaluator_id = u.id WHERE e.teacher_id = :teacher_id AND e.academic_year = :academic_year AND e.semester = :semester AND u.department = :dept AND u.role NOT IN ('president', 'vice_president') ORDER BY u.name";
        $obs_stmt = $db->prepare($obs_query);
        $obs_stmt->execute([':teacher_id' => $tid, ':academic_year' => $academic_year, ':semester' => $semester, ':dept' => $owning_dept]);
        $observers = $obs_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Show all explicitly assigned observers (cross-department assignments are valid)
        $assign_query = "SELECT DISTINCT u.name FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :teacher_id ORDER BY u.name";
        $assign_stmt = $db->prepare($assign_query);
        $assign_stmt->execute([':teacher_id' => $tid]);
        $assigned = $assign_stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Non-leader views should use the row's owning department, not the
        // current page filter department, to avoid dropping valid observers.
        $owning_dept_nonleader = !empty($sched_dept_val) ? $sched_dept_val : $teacher_primary_dept;
        if (empty($owning_dept_nonleader)) {
            $owning_dept_nonleader = $raw_department;
        }

        $obs_query = "SELECT DISTINCT u.name FROM evaluations e JOIN users u ON e.evaluator_id = u.id WHERE e.teacher_id = :teacher_id AND e.academic_year = :academic_year AND e.semester = :semester AND u.department = :department ORDER BY u.name";
        $obs_stmt = $db->prepare($obs_query);
        $obs_stmt->execute([':teacher_id' => $tid, ':academic_year' => $academic_year, ':semester' => $semester, ':department' => $owning_dept_nonleader]);
        $observers = $obs_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Non-leader views: show same-department observers plus accepted President/VP observers.
        $assign_query = "SELECT DISTINCT u.name FROM teacher_assignments ta JOIN users u ON ta.evaluator_id = u.id WHERE ta.teacher_id = :teacher_id AND (u.department = :dept OR u.role IN ('president','vice_president')) ORDER BY u.name";
        $assign_stmt = $db->prepare($assign_query);
        $assign_stmt->execute([':teacher_id' => $tid, ':dept' => $owning_dept_nonleader]);
        $assigned = $assign_stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $all_observers = array_unique(array_merge($observers, $assigned));
    // Only add current dean if they belong to the same department as the teachers
    if (!empty($dean_name) && !in_array($dean_name, $all_observers) && $_SESSION['department'] === $raw_department) {
        array_unshift($all_observers, $dean_name);
    }
    // Always include dean/principal of the row-owning department.
    $dept_for_dean = !empty($row_owning_dept) ? $row_owning_dept : $owning_dept;
    if (!empty($dept_for_dean)) {
        $dept_dean_stmt = $db->prepare("SELECT DISTINCT name FROM users WHERE department = :dept AND role IN ('dean','principal') AND status = 'active' ORDER BY name");
        $dept_dean_stmt->execute([':dept' => $dept_for_dean]);
        while ($dd_name = $dept_dean_stmt->fetchColumn()) {
            if (!in_array($dd_name, $all_observers)) {
                $all_observers[] = $dd_name;
            }
        }
    }
    // Include the schedule setter if schedule was set by President/VP.
    $schedule_setter_name = $get_schedule_setter_name((int)($t['scheduled_by'] ?? 0));
    if ($schedule_setter_name !== '' && !in_array($schedule_setter_name, $all_observers, true)) {
        $all_observers[] = $schedule_setter_name;
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
    $observer_map[$row_key] = $all_observers;
}

// Post-process: if a teacher has completed evaluations but also has a NEW schedule
// (different date), override their display data to show the new schedule.
// Keep historical rows visible; the new schedule is added as its own row below.
foreach ([] as $t) {
    $tid = $t['id'];
    $sched_dt = $t['evaluation_schedule'] ?? '';
    if (empty($sched_dt)) continue;
    $sched_date = date('Y-m-d', strtotime($sched_dt));
    $eval_date = $eval_data[$tid]['date'] ?? '';
    $eval_date_formatted = !empty($eval_date) ? date('Y-m-d', strtotime($eval_date)) : '';
    if ($sched_date !== $eval_date_formatted && ($eval_data[$tid]['done'] ?? false)) {
        // Teacher has a new schedule on a different date — show schedule, not old eval
        $eval_data[$tid] = [
            'date' => $sched_date,
            'done' => false,
            'faculty_signature' => '',
            'eval_id' => null,
            'status' => 'scheduled',
        ];
        // Rebuild schedule_data from teacher's current schedule columns
        $focus_raw = $t['evaluation_focus'] ?? '';
        $focus_arr = [];
        if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
        $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);
        $ts = strtotime($sched_dt);
        $day_str = date('D', $ts);
        $start_time = date('g:ia', $ts);
        $sched_dt_end = $t['evaluation_schedule_end'] ?? '';
        $day_time_str = '';
        if (!empty($sched_dt_end)) {
            $ts_end = strtotime($sched_dt_end);
            $end_time = date('g:ia', $ts_end);
            $day_time_str = $day_str . "\n" . $start_time . ' - ' . $end_time;
        } else {
            $day_time_str = $day_str . "\n" . $start_time;
        }
        $schedule_data[$tid] = [
            'semester' => $t['evaluation_semester'] ?? '',
            'focus' => implode(', ', $focus_display),
            'day_time' => $day_time_str,
            'subject_area' => $t['evaluation_subject_area'] ?? '',
            'subject' => $t['evaluation_subject'] ?? '',
            'room' => $t['evaluation_room'] ?? '',
        ];
    }
}

// Process scheduled-only teachers (no evaluation yet)
foreach ($scheduled_teachers as $t) {
    $tid = $t['id'];
    $sched_dt = $t['evaluation_schedule'] ?? '';
    $sched_date = !empty($sched_dt) ? date('Y-m-d H:i', strtotime($sched_dt)) : '';
    // Compare scheduled datetime against any evaluation datetimes we collected
    $existing_dates = $eval_dates_by_teacher[$tid] ?? [];
    if (isset($seen_ids[$tid]) && $sched_date !== '' && in_array($sched_date, $existing_dates, true)) continue;

    $row_key = isset($seen_ids[$tid]) ? ('schedule_' . $tid) : $tid;
    $seen_ids[$tid] = true;
    $t['_row_key'] = $row_key;
    $teachers_list[] = $t;

    $eval_data[$row_key] = ['date' => $sched_date, 'done' => false, 'faculty_signature' => '', 'eval_id' => null, 'status' => 'scheduled'];

    $focus_raw = $t['evaluation_focus'] ?? '';
    $focus_arr = [];
    if ($focus_raw) { try { $focus_arr = json_decode($focus_raw, true) ?: []; } catch (\Exception $e) {} }
    $focus_display = array_map(function($f) use ($focus_labels) { return $focus_labels[$f] ?? $f; }, $focus_arr);

    $schedule_data[$row_key] = [
        'semester' => $t['evaluation_semester'] ?? '',
        'focus' => implode(', ', $focus_display),
        'day_time' => '',
        'subject_area' => $t['evaluation_subject_area'] ?? '',
        'subject' => $t['evaluation_subject'] ?? '',
        'room' => $t['evaluation_room'] ?? '',
    ];
    if (!empty($sched_dt)) {
        $ts = strtotime($sched_dt);
        $day_str = date('D', $ts);
        $start_time = date('g:ia', $ts);
        $sched_dt_end = $t['evaluation_schedule_end'] ?? '';
        if (!empty($sched_dt_end)) {
            $ts_end = strtotime($sched_dt_end);
            $end_time = date('g:ia', $ts_end);
            $schedule_data[$row_key]['day_time'] = $day_str . "\n" . $start_time . ' - ' . $end_time;
        } else {
            $schedule_data[$row_key]['day_time'] = $day_str . "\n" . $start_time;
        }
    }

    $teacher_primary_dept = $t['teacher_department'] ?? '';
    $owning_dept = $t['scheduled_department'] ?? '';
    if (empty($owning_dept)) {
        $owning_dept = $teacher_primary_dept;
    }

    if ($is_leader && !empty($owning_dept)) {
        $assign_query = "SELECT DISTINCT u.name
                         FROM teacher_assignments ta
                         JOIN users u ON ta.evaluator_id = u.id
                         WHERE ta.teacher_id = :teacher_id
                           AND (u.department = :dept OR u.role IN ('president','vice_president'))
                         ORDER BY u.name";
        $assign_stmt = $db->prepare($assign_query);
        $assign_stmt->execute([':teacher_id' => $tid, ':dept' => $owning_dept]);
    } else {
        // Non-leader views: use row owning department so observers are accurate
        // even when current page filter/program differs.
        $owning_dept_nonleader = !empty($owning_dept) ? $owning_dept : $raw_department;
        $assign_query = "SELECT DISTINCT u.name
                         FROM teacher_assignments ta
                         JOIN users u ON ta.evaluator_id = u.id
                         WHERE ta.teacher_id = :teacher_id
                           AND (u.department = :dept OR u.role IN ('president','vice_president'))
                         ORDER BY u.name";
        $assign_stmt = $db->prepare($assign_query);
        $assign_stmt->execute([':teacher_id' => $tid, ':dept' => $owning_dept_nonleader]);
    }
    $assigned = $assign_stmt->fetchAll(PDO::FETCH_COLUMN);
    $all_observers = $assigned;
    // Only add current dean if they belong to the same department as the teachers
    if (!empty($dean_name) && !in_array($dean_name, $all_observers) && $_SESSION['department'] === $raw_department) {
        array_unshift($all_observers, $dean_name);
    }
    // Always include dean/principal of the row-owning department.
    if (!empty($owning_dept)) {
        $dept_dean_stmt2 = $db->prepare("SELECT DISTINCT name FROM users WHERE department = :dept AND role IN ('dean','principal') AND status = 'active' ORDER BY name");
        $dept_dean_stmt2->execute([':dept' => $owning_dept]);
        while ($dn = $dept_dean_stmt2->fetchColumn()) {
            if (!in_array($dn, $all_observers)) $all_observers[] = $dn;
        }
    }
    // Include the schedule setter if schedule was set by President/VP.
    $schedule_setter_name = $get_schedule_setter_name((int)($t['scheduled_by'] ?? 0));
    if ($schedule_setter_name !== '' && !in_array($schedule_setter_name, $all_observers, true)) {
        $all_observers[] = $schedule_setter_name;
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
    $observer_map[$row_key] = $all_observers;
}

// Filter by month if selected
if (!empty($filter_month)) {
    $teachers_list = array_filter($teachers_list, function($t) use ($eval_data, $filter_month) {
        $row_key = $t['_row_key'] ?? $t['id'];
        $date = $eval_data[$row_key]['date'] ?? '';
        if (empty($date)) return false;
        return date('n', strtotime($date)) == $filter_month;
    });
    $teachers_list = array_values($teachers_list);
}

// Filter by status if selected
if (!empty($filter_status)) {
    $teachers_list = array_filter($teachers_list, function($t) use ($eval_data, $filter_status) {
        $row_key = $t['_row_key'] ?? $t['id'];
        $is_done = $eval_data[$row_key]['done'] ?? false;
        $row_status = strtolower(trim((string)($eval_data[$row_key]['status'] ?? '')));
        $has_sched = !empty($t['evaluation_schedule']);
        if ($filter_status === 'done') return $is_done;
        if ($filter_status === 'rescheduled') return ($row_status === 'rescheduled');
        if ($filter_status === 'scheduled') return $has_sched && !$is_done;
        return true;
    });
    $teachers_list = array_values($teachers_list);
}
$dean_role_display = ucfirst(str_replace('_', ' ', $_SESSION['role']));

// Pending reschedule requests for current evaluator (used in Observation Plan UI)
$pending_reschedule_requests = [];
try {
    $pendingStmt = $db->prepare("SELECT id, teacher_id, created_at
                                 FROM notifications
                                 WHERE user_id = :uid
                                   AND type = 'reschedule_request'
                                   AND is_read = 0
                                 ORDER BY id DESC");
    $pendingStmt->execute([':uid' => (int)($_SESSION['user_id'] ?? 0)]);
    while ($pr = $pendingStmt->fetch(PDO::FETCH_ASSOC)) {
        $ptid = (int)($pr['teacher_id'] ?? 0);
        if ($ptid <= 0) continue;
        if (!isset($pending_reschedule_requests[$ptid])) {
            $pending_reschedule_requests[$ptid] = [
                'notification_id' => (int)($pr['id'] ?? 0),
                'created_at' => $pr['created_at'] ?? null
            ];
        }
    }
} catch (Exception $e) {}

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
                        t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                        t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                        t.evaluation_form_type, t.scheduled_department
                 FROM teachers t
                 WHERE t.status = 'active'
                   AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                 ORDER BY t.department, t.name ASC";
    $st_stmt = $db->prepare($st_query);
    $st_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
} elseif ($is_coordinator) {
    $st_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                        t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                        t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                        t.evaluation_form_type, t.scheduled_department
                 FROM teachers t
                 JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :evaluator_id
                 LEFT JOIN users tu ON tu.id = t.user_id
                 WHERE t.status = 'active'
                   AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                   AND (tu.id IS NULL OR tu.role NOT IN ('dean','principal','president','vice_president'))
                 ORDER BY t.name ASC";
    $st_stmt = $db->prepare($st_query);
    $st_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $st_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
} else {
    $st_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                        t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                        t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester,
                        t.evaluation_form_type, t.scheduled_department
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
foreach ($schedulable_teachers as $st) {
    $tid = (int)$st['id'];
    $depts = [$st['teacher_department']];
    if (isset($teacher_sec_depts[$tid])) {
        $depts = array_unique(array_merge($depts, $teacher_sec_depts[$tid]));
    }
    $teacher_depts_map[$tid] = array_values(array_filter($depts));
}

// Departments current evaluator can schedule for in this modal
$schedule_available_departments = [];
if ($is_leader) {
    $schedule_available_departments = $all_departments;
} else {
    // Non-leader accounts (dean/principal/coordinators) can only schedule
    // within their own department in this modal.
    $self_dept = trim((string)($_SESSION['department'] ?? ''));
    if ($self_dept !== '') {
        $schedule_available_departments[] = $self_dept;
    }
}

// Unscheduled teachers are only shown in the modal dropdown, not in the table

// Load acknowledgment data for current semester/year
$ack_map = [];
$ack_upcoming_map = [];
try {
    // Build ack map for the exact teacher rows visible in this table.
    // This avoids false "Pending" when teacher signatures were stored under
    // a different department than the current viewer's department.
    $ack_teacher_ids = [];
    foreach ($teachers_list as $tt) {
        $tid = (int)($tt['id'] ?? 0);
        if ($tid > 0) $ack_teacher_ids[$tid] = true;
    }
    $ack_teacher_ids = array_keys($ack_teacher_ids);

    if (!empty($ack_teacher_ids)) {
        $ph = implode(',', array_fill(0, count($ack_teacher_ids), '?'));
        $ack_query = "SELECT teacher_id, department, acknowledged_at, signature, evaluation_id, id
                      FROM observation_plan_acknowledgments
                      WHERE academic_year = ?
                        AND semester = ?
                        AND teacher_id IN ($ph)
                      ORDER BY acknowledged_at DESC, id DESC";
        $ack_stmt = $db->prepare($ack_query);
        $ack_stmt->execute(array_merge([$academic_year, $semester], $ack_teacher_ids));

        while ($ack_row = $ack_stmt->fetch(PDO::FETCH_ASSOC)) {
            $tid = (int)$ack_row['teacher_id'];
            if (!isset($ack_map[$tid])) {
                $ack_map[$tid] = $ack_row;
            } else {
                // Prefer a row with an actual drawn signature if available.
                $currentHasSig = !empty($ack_map[$tid]['signature']);
                $newHasSig = !empty($ack_row['signature']);
                if (!$currentHasSig && $newHasSig) {
                    $ack_map[$tid] = $ack_row;
                }
            }

            // Track upcoming-schedule signatures specifically (evaluation_id IS NULL).
            if ($ack_row['evaluation_id'] === null || $ack_row['evaluation_id'] === '') {
                if (!isset($ack_upcoming_map[$tid])) {
                    $ack_upcoming_map[$tid] = $ack_row;
                } else {
                    $curUpcomingHasSig = !empty($ack_upcoming_map[$tid]['signature']);
                    $newUpcomingHasSig = !empty($ack_row['signature']);
                    if (!$curUpcomingHasSig && $newUpcomingHasSig) {
                        $ack_upcoming_map[$tid] = $ack_row;
                    }
                }
            }
        }
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
        .myobs-table {
            table-layout: fixed;
            min-width: 1300px;
        }
        .myobs-table th,
        .myobs-table td {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .myobs-focus-cell,
        .myobs-observers-cell {
            white-space: normal;
            line-height: 1.35;
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

        /* Keep Set Schedule modal compact for leaders and evaluators */
        #scheduleModal .modal-dialog {
            max-width: 760px;
            width: calc(100% - 1.5rem);
            margin: 1rem auto;
        }
        #scheduleModal .modal-content {
            max-height: calc(100vh - 2rem);
        }
        #scheduleModal .modal-body {
            overflow-y: auto;
        }
        .observation-plan-container {
            padding: 24px;
        }
        .observation-card {
            background: #fff;
            padding: 30px;
        }
        .observation-card-elevated {
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .action-toolbar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .myobs-sign-canvas-wrap {
            display: inline-block;
            max-width: 100%;
        }
        #myObsSigCanvas {
            max-width: 100%;
            height: auto;
            border: 2px solid #333;
            border-radius: 8px;
            background: #fff;
            cursor: crosshair;
            touch-action: none;
        }
        #scheduleModal .form-check {
            margin-bottom: 0.35rem;
        }
        .resched-modal .modal-dialog {
            max-width: 860px;
            width: calc(100% - 1.5rem);
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
            max-height: 72vh;
            overflow-y: auto;
        }

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
        @media (max-width: 768px) {
            .observation-plan-container {
                padding: 12px;
            }
            .observation-card {
                padding: 16px;
            }
            .action-toolbar .btn {
                width: 100%;
            }
            #scheduleModal .modal-dialog {
                max-width: 100%;
                width: calc(100% - 1rem);
                margin: 0.5rem auto;
            }
            #scheduleModal .modal-content {
                max-height: calc(100vh - 1rem);
            }
            #scheduleModal .modal-body {
                padding: 0.9rem;
            }
            #scheduleModal .modal-footer {
                gap: 0.5rem;
            }
            #scheduleModal .modal-footer .btn {
                width: 100%;
                margin: 0;
            }
            #scheduleModal .d-flex.gap-3 {
                flex-direction: column;
                gap: 0.4rem !important;
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
                        <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($_SESSION['name']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="evaluatorMenu">
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><a class="dropdown-item" href="change-password.php"><i class="fas fa-key me-2"></i>Change Password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="dashboard-body-wrap">
        <div class="container-fluid observation-plan-container">

            <!-- Filters (screen only) -->
            <div class="card mb-3 no-print">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <?php if ($has_teacher_record): ?>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">View</label>
                            <select name="view" class="form-select">
                                <option value="plan" <?php echo $view_mode === 'plan' ? 'selected' : ''; ?>>Observation Plan</option>
                                <option value="my_observation" <?php echo $view_mode === 'my_observation' ? 'selected' : ''; ?>>My Evaluation Schedule</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if ($is_leader): ?>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Department</label>
                            <select name="department" class="form-select">
                                <?php if ($is_leader): ?>
                                <option value="" <?php echo $raw_department === '' ? 'selected' : ''; ?>>All Departments</option>
                                <?php endif; ?>
                                <?php foreach(($is_leader ? $all_departments : $available_filter_departments) as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $raw_department === $dept ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
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
                            <label class="form-label fw-bold">Remarks</label>
                            <select name="status" class="form-select">
                                <option value="" <?php echo $filter_status === '' ? 'selected' : ''; ?>>All Remarks</option>
                                <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="rescheduled" <?php echo $filter_status === 'rescheduled' ? 'selected' : ''; ?>>Rescheduled</option>
                                <option value="done" <?php echo $filter_status === 'done' ? 'selected' : ''; ?>>Done</option>
                            </select>
                        </div>
                        <?php if ($view_mode === 'my_observation' && $has_teacher_record): ?>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Department</label>
                            <select name="department_my" class="form-select">
                                <option value="" <?php echo $my_filter_department === '' ? 'selected' : ''; ?>>All Departments</option>
                                <?php
                                    $my_dept_opts = [];
                                    $primary = $normalize_dept((string)($my_teacher_data['department'] ?? ''));
                                    $sched_d = $normalize_dept((string)($my_teacher_data['scheduled_department'] ?? ''));
                                    if ($primary !== '') $my_dept_opts[] = $primary;
                                    if ($sched_d !== '' && !in_array($sched_d, $my_dept_opts, true)) $my_dept_opts[] = $sched_d;
                                    foreach ($my_dept_opts as $dopt):
                                ?>
                                <option value="<?php echo htmlspecialchars($dopt); ?>" <?php echo $my_filter_department === $dopt ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($department_map[$dopt] ?? $dopt); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
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
            <?php if (!empty($_SESSION['schedule_debug_line'])): ?>
            <div class="alert alert-info alert-dismissible fade show no-print" role="alert">
                <i class="fas fa-bug me-2"></i><strong>Debug:</strong>
                <div style="white-space:pre-wrap;"><?php echo htmlspecialchars($_SESSION['schedule_debug_line']); ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['schedule_debug_line']); endif; ?>
            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($view_mode === 'my_observation' && $has_teacher_record): ?>
            <!-- My Observation View -->
            <div class="observation-card observation-card-elevated">
                <?php
                    $my_header_dept_code = $my_filter_department !== ''
                        ? $my_filter_department
                        : ($normalize_dept((string)($my_teacher_data['scheduled_department'] ?? '')) ?: $normalize_dept((string)($my_teacher_data['department'] ?? '')));
                    $my_header_dept_display = $department_map[$my_header_dept_code] ?? ($my_header_dept_code ?: $department_display);
                    $is_basiced_dept = in_array($my_header_dept_code, ['JHS', 'ELEM'], true);
                ?>
                <div class="text-center mb-3">
                    <h5 class="fw-bold">My Evaluation Schedule</h5>
                    <p class="text-muted mb-1"><?php echo htmlspecialchars($my_header_dept_display); ?></p>
                    <p class="text-muted"><?php echo htmlspecialchars($semester); ?> Semester SY <?php echo htmlspecialchars($academic_year); ?></p>
                </div>

                <?php
                $my_has_schedule = !empty($my_teacher_data['evaluation_schedule']);
                $my_has_matching = $my_has_schedule && ($my_teacher_data['evaluation_semester'] === $semester || empty($my_teacher_data['evaluation_semester']));

                // Group evaluations by exact datetime (minute precision) to check overlap with schedule
                $my_eval_datetime_keys = [];
                foreach ($my_evaluations as $ev) {
                    if (!empty($ev['observation_date'])) {
                        $obs_dt = trim((string)($ev['observation_date'] ?? ''));
                        $obs_tm = trim((string)($ev['observation_time'] ?? ''));
                        if ($obs_tm !== '') {
                            $my_eval_datetime_keys[date('Y-m-d H:i', strtotime($obs_dt . ' ' . $obs_tm))] = true;
                        } else {
                            $my_eval_datetime_keys[date('Y-m-d 00:00', strtotime($obs_dt))] = true;
                        }
                    }
                }
                $my_sched_datetime_key = $my_has_matching ? date('Y-m-d H:i', strtotime($my_teacher_data['evaluation_schedule'])) : null;

                // Show upcoming schedule row unless an evaluation already exists at the exact same time.
                $my_show_upcoming = $my_has_matching && ($my_sched_datetime_key === null || !isset($my_eval_datetime_keys[$my_sched_datetime_key]));

                // Apply month filter to My Observation view
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
                    <button type="button" class="btn btn-outline-primary me-2" id="myObsReschedBtn" disabled onclick="openMyObsRescheduleModal()">
                        <i class="fas fa-calendar-alt me-1"></i>Request Reschedule
                    </button>
                    <button type="button" class="btn btn-primary" id="myObsSignToggleBtn" disabled onclick="toggleMyObsSignPanel()">
                        <i class="fas fa-signature me-1"></i>Sign <span id="myObsSignBadge" class="badge bg-light text-dark ms-1" style="display:none;">0</span>
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="plan-table myobs-table" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:3%;">
                                    <i class="fas fa-check-square"></i>
                                </th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:7%;">Semester</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:19%;">Focus of Observation</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:7%;">Date</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:8%;">Day &amp; Time</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:8%;"><?php echo $is_basiced_dept ? 'Grade Level/Section' : 'Subject Area'; ?></th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:7%;"><?php echo $is_basiced_dept ? 'Subject of Instruction' : 'Subject'; ?></th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:5%;">Room</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:18%;">Observers</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:8%;">Teacher's Signature</th>
                                <th style="background:#2c3e50;color:#fff;padding:10px;border:1px solid #dee2e6;width:10%;">Remarks</th>
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
                                $my_day_time = date('D', $ts) . '<br>' . date('g:i A', $ts);
                                $my_sched_end = $my_teacher_data['evaluation_schedule_end'] ?? '';
                                if (!empty($my_sched_end)) {
                                    $ts_end = strtotime($my_sched_end);
                                    if ($ts_end) {
                                        $my_day_time .= ' - ' . date('g:i A', $ts_end);
                                    }
                                }
                                $upcoming_signed = isset($my_signed_map['upcoming']);
                                $upcoming_signature = $my_signed_map['upcoming']['signature'] ?? '';
                            ?>
                            <tr>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <input type="checkbox" class="form-check-input sign-item-check" value="upcoming" data-schedule-label="Upcoming: <?php echo htmlspecialchars(date('M d, Y g:i A', $ts)); ?>" style="width:20px;height:20px;" title="<?php echo $upcoming_signed ? 'Signed schedule (can still be rescheduled)' : 'Select schedule'; ?>">
                                </td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars(($my_teacher_data['evaluation_semester'] ?? '') . ' Semester'); ?></td>
                                <td class="myobs-focus-cell" style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $focus_display)); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo date('M d, Y', $ts); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo $my_day_time; ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($my_teacher_data['evaluation_subject_area'] ?? ''); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($my_teacher_data['evaluation_subject'] ?? ''); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($my_teacher_data['evaluation_room'] ?? ''); ?></td>
                                <td class="myobs-observers-cell" style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $my_observer_names)); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <?php if (!empty($upcoming_signature)): ?>
                                        <img src="<?php echo htmlspecialchars($upcoming_signature); ?>" alt="Teacher Signature" style="max-height:30px;max-width:90px;">
                                    <?php elseif (!empty($my_teacher_data['faculty_signature'])): ?>
                                        <img src="<?php echo htmlspecialchars($my_teacher_data['faculty_signature']); ?>" alt="Teacher Signature" style="max-height:30px;max-width:90px;">
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <span class="badge bg-info">Upcoming</span>
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
                                $ev_signature = $my_signed_map[(int)$ev['id']]['signature'] ?? '';
                                $ev_status = strtolower(trim((string)($ev['status'] ?? '')));
                                $ev_day_time = '';
                                if (!empty($ev['observation_date'])) {
                                    $ev_day_time = date('D', strtotime($ev['observation_date']));
                                    $ev_time = trim((string)($ev['observation_time'] ?? ''));
                                    if ($ev_time !== '' && $ev_time !== '00:00:00' && $ev_time !== '00:00') {
                                        $ev_day_time .= '<br>' . date('g:i A', strtotime($ev_time));
                                    }
                                }
                                // Build observer list per evaluation row:
                                // - include explicitly assigned observers for the row's department
                                // - include dean/principal of the row's department
                                // - include President/VP only when explicitly assigned (accepted)
                                $ev_observers = [];
                                $ev_dept = trim((string)($ev['evaluator_department'] ?? ''));
                                if ($ev_dept === '') {
                                    $ev_dept = trim((string)($my_teacher_data['scheduled_department'] ?? ''));
                                }
                                if ($ev_dept === '') {
                                    $ev_dept = trim((string)($my_teacher_data['department'] ?? ''));
                                }
                                if ($ev_dept !== '') {
                                    try {
                                        $ev_assign_stmt = $db->prepare("SELECT DISTINCT u.name, u.role, u.department
                                                                        FROM teacher_assignments ta
                                                                        JOIN users u ON ta.evaluator_id = u.id
                                                                        WHERE ta.teacher_id = :tid
                                                                          AND u.status = 'active'
                                                                          AND (
                                                                              u.department = :dept
                                                                              OR u.role IN ('dean','principal','president','vice_president')
                                                                          )
                                                                        ORDER BY u.name");
                                        $ev_assign_stmt->execute([':tid' => $my_teacher_id, ':dept' => $ev_dept]);
                                        while ($ev_obs = $ev_assign_stmt->fetch(PDO::FETCH_ASSOC)) {
                                            $n = trim((string)($ev_obs['name'] ?? ''));
                                            if ($n !== '' && !in_array($n, $ev_observers, true)) {
                                                $ev_observers[] = $n;
                                            }
                                        }
                                    } catch (Exception $e) {}

                                    try {
                                        $ev_dean_stmt = $db->prepare("SELECT DISTINCT name
                                                                      FROM users
                                                                      WHERE department = :dept
                                                                        AND role IN ('dean','principal')
                                                                        AND status = 'active'
                                                                      ORDER BY name");
                                        $ev_dean_stmt->execute([':dept' => $ev_dept]);
                                        while ($ev_dn = $ev_dean_stmt->fetchColumn()) {
                                            $ev_dn = trim((string)$ev_dn);
                                            if ($ev_dn !== '' && !in_array($ev_dn, $ev_observers, true)) {
                                                $ev_observers[] = $ev_dn;
                                            }
                                        }
                                    } catch (Exception $e) {}
                                }
                                $ev_eval_name = trim((string)($ev['evaluator_name'] ?? ''));
                                if ($ev_eval_name !== '' && !in_array($ev_eval_name, $ev_observers, true)) {
                                    $ev_observers[] = $ev_eval_name;
                                }
                                $ev_own_name = trim((string)($_SESSION['name'] ?? ''));
                                $ev_observers = array_values(array_filter(array_unique($ev_observers), function($n) use ($ev_own_name) {
                                    return trim((string)$n) !== '' && trim((string)$n) !== $ev_own_name;
                                }));
                            ?>
                            <tr>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <input type="checkbox" class="form-check-input sign-item-check" value="<?php echo (int)$ev['id']; ?>" data-schedule-label="Schedule: <?php echo htmlspecialchars(!empty($ev['observation_date']) ? date('M d, Y', strtotime($ev['observation_date'])) : ''); ?>" style="width:20px;height:20px;" title="<?php echo $ev_signed ? 'Signed schedule (can still be rescheduled)' : 'Select schedule'; ?>">
                                </td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars(($ev['semester'] ?? '') . ' Semester'); ?></td>
                                <td class="myobs-focus-cell" style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $ev_focus_display)); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo !empty($ev['observation_date']) ? date('M d, Y', strtotime($ev['observation_date'])) : ''; ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo $ev_day_time; ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($ev['subject_area'] ?? ''); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($ev['subject_observed'] ?? ''); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($ev['observation_room'] ?? ''); ?></td>
                                <td class="myobs-observers-cell" style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $ev_observers)); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <?php if (!empty($ev_signature)): ?>
                                        <img src="<?php echo htmlspecialchars($ev_signature); ?>" alt="Teacher Signature" style="max-height:30px;max-width:90px;">
                                    <?php elseif (!empty($ev['faculty_signature'])): ?>
                                        <img src="<?php echo htmlspecialchars($ev['faculty_signature']); ?>" alt="Teacher Signature" style="max-height:30px;max-width:90px;">
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <?php if ($ev_status === 'completed'): ?>
                                        <span class="badge bg-success">Conducted</span>
                                    <?php elseif ($ev_status === 'rescheduled'): ?>
                                        <span class="badge bg-warning text-dark">Rescheduled</span>
                                    <?php elseif ($ev_status === 'in_progress' || $ev_status === 'draft'): ?>
                                        <span class="badge bg-info">Scheduled</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($ev_status !== '' ? $ev_status : 'Pending')); ?></span>
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
                        <div class="mb-3 myobs-sign-canvas-wrap">
                            <canvas id="myObsSigCanvas" width="400" height="150"></canvas>
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

                <!-- Request Reschedule Modal -->
                <div class="modal fade resched-modal" id="myObsRescheduleModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-calendar-times me-2"></i>Request Reschedule</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" id="myObsRescheduleForm">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="request_reschedule_my">
                                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                                    <input type="hidden" name="reschedule_item" id="myObsRescheduleItem" value="">

                                    <div class="mb-2 small text-muted" id="myObsRescheduleScheduleText"></div>

                                    <label class="form-label fw-bold">Reason <span class="text-danger">*</span></label>
                                    <select class="form-select" name="reschedule_reason" id="myObsRescheduleReason" required>
                                        <option value="">Select reason</option>
                                        <option value="emergency">Emergency</option>
                                        <option value="conflict_schedule">Conflict of Schedule</option>
                                        <option value="others">Others</option>
                                    </select>

                                    <div class="mt-3" id="myObsRescheduleOtherWrap" style="display:none;">
                                        <label class="form-label fw-bold">Please specify</label>
                                        <textarea class="form-control" name="reschedule_reason_other" id="myObsRescheduleOther" rows="3" placeholder="Type your reason..."></textarea>
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

                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-clipboard fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Evaluation Schedule Yet</h5>
                    <p class="text-muted">No evaluation schedule has been set for you this <?php echo htmlspecialchars($semester); ?> Semester.</p>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- Normal Observation Plan View -->
            <div class="observation-card">
                
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
                <div class="mb-3 action-toolbar no-print">
                    <?php if (!$is_observer_only): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#scheduleModal" onclick="openScheduleModal()">
                        <i class="fas fa-calendar-plus me-1"></i>Set Schedule
                    </button>
                    <button class="btn btn-primary" onclick="openRescheduleModal()">
                        <i class="fas fa-redo me-1"></i>Reschedule
                    </button>
                    <?php if (in_array($_SESSION['role'] ?? '', ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'], true)): ?>
                    <button class="btn btn-warning" id="acceptRescheduleBtn" disabled onclick="acceptRescheduleRequest()">
                        <i class="fas fa-check-circle me-1"></i>Accept Reschedule Request
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($is_leader): ?>
                    <button class="btn btn-success" id="joinObserverBtn" disabled onclick="joinAsObserver()">
                        <i class="fas fa-user-plus me-1"></i>Accept as Observer
                    </button>
                    <?php endif; ?>
                    <?php if (!$is_observer_only): ?>
                    <button class="btn btn-outline-danger" id="bulkCancelBtn" disabled onclick="cancelSelectedSchedules()">
                        <i class="fas fa-times me-1"></i>Cancel
                    </button>
                    <?php endif; ?>
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
                                <?php
                                    $tid = $t['id'];
                                    $row_key = $t['_row_key'] ?? $tid;
                                    $sd = $schedule_data[$row_key] ?? [];
                                    $is_done = $eval_data[$row_key]['done'] ?? false;
                                    $row_has_schedule_data =
                                        !empty($t['evaluation_schedule']) ||
                                        !empty($sd['day_time']) ||
                                        !empty($eval_data[$row_key]['date'] ?? '');
                                    $has_schedule = !$is_done && $row_has_schedule_data;
                                    $ack_upcoming = $ack_upcoming_map[$tid] ?? null;
                                    $is_schedule_signed = $has_schedule && !empty($ack_upcoming);
                                    // Signature is acknowledgment only; it must not block rescheduling.
                                    $can_reschedule = $has_schedule;
                                    $pending_req_info = $pending_reschedule_requests[$tid] ?? null;
                                    $has_pending_req = !empty($pending_req_info);
                                    $pending_req_id = (int)($pending_req_info['notification_id'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($can_reschedule): ?>
                                            <?php if ($is_leader): ?>
                                                <?php
                                                    $is_opted = isset($leader_opted_teachers[$tid]);
                                                    $scheduled_by_me = ((int)($t['scheduled_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0));
                                                ?>
                                                <?php if (!$is_observer_only && $scheduled_by_me): ?>
                                                    <input type="checkbox" class="form-check-input reschedule-check no-print" value="<?php echo (int)$tid; ?>" data-has-pending-req="<?php echo $has_pending_req ? '1' : '0'; ?>" data-pending-notif-id="<?php echo $pending_req_id; ?>" style="width:16px;height:16px;cursor:pointer;margin-right:6px;vertical-align:middle;" title="Scheduled by you">
                                                <?php else: ?>
                                                    <input type="checkbox" class="form-check-input reschedule-check observer-opt-check no-print" value="<?php echo (int)$tid; ?>" <?php echo $is_opted ? 'checked' : ''; ?> data-opted="<?php echo $is_opted ? '1' : '0'; ?>" data-has-pending-req="<?php echo $has_pending_req ? '1' : '0'; ?>" data-pending-notif-id="<?php echo $pending_req_id; ?>" style="width:16px;height:16px;cursor:pointer;margin-right:6px;vertical-align:middle;accent-color:green;" title="<?php echo $is_opted ? 'You are an observer' : 'Check to join as observer'; ?>">
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <input type="checkbox" class="form-check-input reschedule-check no-print" value="<?php echo (int)$tid; ?>" data-has-pending-req="<?php echo $has_pending_req ? '1' : '0'; ?>" data-pending-notif-id="<?php echo $pending_req_id; ?>" style="width:16px;height:16px;cursor:pointer;margin-right:6px;vertical-align:middle;">
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php echo $counter++ . '. ' . htmlspecialchars($t['name']); ?>
                                        <?php if ($has_pending_req): ?>
                                            <span class="badge bg-warning text-dark ms-1">Reschedule Request</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php $sem = $sd['semester'] ?? ''; echo htmlspecialchars($sem ? $sem . ' Semester' : ''); ?></td>
                                    <td style="font-size:0.8rem;"><?php echo htmlspecialchars($sd['focus'] ?? ''); ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $date = $eval_data[$row_key]['date'] ?? '';
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
                                        $eval_id = $eval_data[$row_key]['eval_id'] ?? null;
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
                                        $observers = $observer_map[$row_key] ?? [];
                                        echo htmlspecialchars(implode(', ', $observers));
                                        ?>
                                    </td>
                                    <td class="text-center" style="font-size:0.8rem;">
                                        <?php 
                                        $ack = $ack_map[$tid] ?? null;
                                        $faculty_sig = $eval_data[$row_key]['faculty_signature'] ?? '';
                                        if ($is_schedule_signed && !empty($ack_upcoming['signature'])): ?>
                                            <img src="<?php echo $ack_upcoming['signature']; ?>" alt="Signature" style="max-height: 30px; max-width: 60px;" title="Signed on <?php echo htmlspecialchars(date('M d, Y g:ia', strtotime($ack_upcoming['acknowledged_at']))); ?>">
                                        <?php elseif ($is_schedule_signed): ?>
                                            <span class="text-success no-print" title="Signed on <?php echo htmlspecialchars(date('M d, Y g:ia', strtotime($ack_upcoming['acknowledged_at']))); ?>">
                                                <i class="fas fa-check-circle"></i>
                                            </span>
                                            <span class="print-only">Signed</span>
                                        <?php elseif ($ack && !empty($ack['signature'])): ?>
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
                                        $row_eval_status = strtolower(trim((string)($eval_data[$row_key]['status'] ?? '')));
                                        if ($eval_data[$row_key]['done'] ?? false) {
                                            echo '<span class="badge bg-success">Done</span>';
                                        } elseif ($row_eval_status === 'rescheduled') {
                                            echo '<span class="badge bg-warning text-dark">Rescheduled</span>';
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
                                    data-schedule-end="<?php echo htmlspecialchars($st['evaluation_schedule_end'] ?? '', ENT_QUOTES); ?>"
                                    data-room="<?php echo htmlspecialchars($st['evaluation_room'] ?? '', ENT_QUOTES); ?>"
                                    data-focus="<?php echo htmlspecialchars($st['evaluation_focus'] ?? '', ENT_QUOTES); ?>"
                                    data-subject-area="<?php echo htmlspecialchars($st['evaluation_subject_area'] ?? '', ENT_QUOTES); ?>"
                                    data-subject="<?php echo htmlspecialchars($st['evaluation_subject'] ?? '', ENT_QUOTES); ?>"
                                    data-semester="<?php echo htmlspecialchars($st['evaluation_semester'] ?? '', ENT_QUOTES); ?>"
                                    data-form-type="<?php echo htmlspecialchars($st['evaluation_form_type'] ?? 'iso', ENT_QUOTES); ?>"
                                    data-scheduled-department="<?php echo htmlspecialchars($st['scheduled_department'] ?? '', ENT_QUOTES); ?>"
                                    <?php echo !empty($st['evaluation_schedule']) ? 'data-has-schedule="1"' : ''; ?>
                                    <?php if (isset($teacher_depts_map[(int)$st['id']])): ?>
                                    data-departments="<?php echo htmlspecialchars(json_encode($teacher_depts_map[(int)$st['id']]), ENT_QUOTES); ?>"
                                    <?php endif; ?>
                                >
                                    <?php echo htmlspecialchars($st['name']); ?>
                                    <?php echo !empty($st['evaluation_schedule']) ? ' (Scheduled)' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php
                        // Show department field for ALL evaluators (leaders, deans, principals, coordinators)
                        $show_dept_field = in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president']);
                        ?>
                        <?php if ($show_dept_field): ?>
                        <!-- Department for evaluation -->
                        <div class="mb-3" id="modal_department_group">
                            <label class="form-label fw-bold">Department <span class="text-danger">*</span></label>
                            <select class="form-select" name="scheduled_department" id="modal_scheduled_department" required>
                                <option value="">-- Select department --</option>
                            </select>
                            <small class="text-muted">Select the department this evaluation is for.</small>
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
                            <input type="hidden" id="modal_evaluation_schedule_end" name="evaluation_schedule_end">
                            <div class="row g-2 mb-2">
                                <div class="col-12 col-md-7">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                        <input type="date" class="form-control" id="modal_evaluation_date" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-12 col-md-6">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-clock"></i> Start</span>
                                        <input type="time" class="form-control" id="modal_evaluation_start_time" step="900" required>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-clock"></i> End</span>
                                        <input type="time" class="form-control" id="modal_evaluation_end_time" step="900">
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
// Available departments for current user based on their role
const userRole = '<?php echo htmlspecialchars($_SESSION['role']); ?>';
const userDept = '<?php echo htmlspecialchars($_SESSION['department'] ?? ''); ?>';
const allDepartments = ['ELEM', 'JHS', 'SHS', 'CCIS', 'CAS', 'CTEAS', 'CBM', 'CTHM', 'CCJE'];

// Determine which departments the current user can set schedules for
let availableDepartments = <?php echo json_encode(array_values($schedule_available_departments)); ?>;

// Map departments to display names
const departmentMap = {
    'CCIS': 'College of Computing and Information Sciences',
    'CBM': 'College of Business and Management',
    'CAS': 'College of Arts and Sciences',
    'CCJE': 'College of Criminal Justice Education',
    'CTHM': 'College of Tourism and Hospitality Management',
    'CTEAS': 'College of Teacher Education, Arts and Sciences',
    'ELEM': 'Elementary Department',
    'JHS': 'Junior High School Department',
    'SHS': 'Senior High School Department'
};

function populateDepartmentDropdown() {
    const deptSelect = document.getElementById('modal_scheduled_department');
    if (!deptSelect) return;
    
    // Clear and populate with available departments
    deptSelect.innerHTML = '<option value="">-- Select department --</option>';
    availableDepartments.forEach(dept => {
        const option = document.createElement('option');
        option.value = dept;
        option.textContent = departmentMap[dept] || dept;
        deptSelect.appendChild(option);
    });
}

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
    
    // Reset all fields first
    document.getElementById('modal_evaluation_room').value = '';
    document.getElementById('modal_evaluation_subject_area').value = '';
    document.getElementById('modal_evaluation_subject').value = '';
    document.getElementById('modal_evaluation_date').value = '';
    document.getElementById('modal_evaluation_start_time').value = '';
    document.getElementById('modal_evaluation_end_time').value = '';
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

    // Populate department dropdown for current user
    populateDepartmentDropdown();

    // Hide PEAC/Both for leaders until department is selected
    updateFormTypeVisibility();
    updateSubjectLabels();

    // Default semester to filter
    const filterSem = '<?php echo htmlspecialchars($semester, ENT_QUOTES); ?>';
    document.getElementById('modal_semester_1st').checked = (filterSem === '1st');
    document.getElementById('modal_semester_2nd').checked = (filterSem === '2nd');

    // Ensure normal mode (not reschedule)
    setModalRescheduleMode(false);

    // Keep Set Schedule blank by default
    if (select) select.selectedIndex = 0;
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
    var semInput = document.createElement('input');
    semInput.type = 'hidden';
    semInput.name = 'filter_semester';
    semInput.value = '<?php echo htmlspecialchars($semester, ENT_QUOTES); ?>';
    form.appendChild(semInput);
    var ayInput = document.createElement('input');
    ayInput.type = 'hidden';
    ayInput.name = 'filter_academic_year';
    ayInput.value = '<?php echo htmlspecialchars($academic_year, ENT_QUOTES); ?>';
    form.appendChild(ayInput);
    // Preserve current query params so the redirect keeps filters
    form.action = window.location.href;
    document.body.appendChild(form);
    form.submit();
}

function openRescheduleModal() {
    // Check if a teacher checkbox is selected
    var checked = document.querySelectorAll('.reschedule-check:checked');
    if (checked.length === 0) {
        alert('Please select a teacher to reschedule.');
        return;
    }
    if (checked.length > 1) {
        alert('Please select only one teacher at a time to reschedule.');
        return;
    }
    
    var teacherId = checked[0].value;
    var hasPendingReq = (checked[0].dataset.hasPendingReq || '0') === '1';
    var acceptBtn = document.getElementById('acceptRescheduleBtn');
    if (hasPendingReq && acceptBtn) {
        alert('Please click "Accept Reschedule Request" first before setting the new schedule.');
        return;
    }
    var select = document.getElementById('schedule_teacher_id');
    
    if (!select) {
        console.error('schedule_teacher_id select element not found');
        alert('Error: Schedule form not found. Please refresh the page.');
        return;
    }

    // Reset form
    document.getElementById('modal_evaluation_room').value = '';
    document.getElementById('modal_evaluation_subject_area').value = '';
    document.getElementById('modal_evaluation_subject').value = '';
    document.getElementById('modal_evaluation_date').value = '';
    document.getElementById('modal_evaluation_start_time').value = '';
    document.getElementById('modal_evaluation_end_time').value = '';
    document.getElementById('modal_focus_communications').checked = false;
    document.getElementById('modal_focus_management').checked = false;
    document.getElementById('modal_focus_assessment').checked = false;
    document.getElementById('modal_focus_teacher_actions').checked = false;
    document.getElementById('modal_focus_student_learning').checked = false;
    document.getElementById('modal_form_iso').checked = true;
    if (document.getElementById('modal_form_peac')) document.getElementById('modal_form_peac').checked = false;
    if (document.getElementById('modal_form_both')) document.getElementById('modal_form_both').checked = false;
    
    // SET RESCHEDULE MODE before teacher change so existing data can preload
    setModalRescheduleMode(true);

    // Select the teacher in dropdown and preload existing data
    select.value = teacherId;
    select.dispatchEvent(new Event('change'));

    // Populate department dropdown for current user
    populateDepartmentDropdown();

    // Hide PEAC/Both for leaders until department is selected
    updateFormTypeVisibility();
    updateSubjectLabels();

    // Default semester to filter
    const filterSem = '<?php echo htmlspecialchars($semester, ENT_QUOTES); ?>';
    document.getElementById('modal_semester_1st').checked = (filterSem === '1st');
    document.getElementById('modal_semester_2nd').checked = (filterSem === '2nd');

    // Clear only date/time so evaluator must pick new ones
    document.getElementById('modal_evaluation_date').value = '';
    document.getElementById('modal_evaluation_start_time').value = '';
    document.getElementById('modal_evaluation_end_time').value = '';

    // Open the modal
    var scheduleModalElement = document.getElementById('scheduleModal');
    if (!scheduleModalElement) {
        console.error('scheduleModal element not found');
        alert('Error: Modal not found. Please refresh the page.');
        return;
    }
    var modal = new bootstrap.Modal(scheduleModalElement);
    modal.show();
}

function acceptRescheduleRequest() {
    var checked = document.querySelectorAll('.reschedule-check:checked');
    if (checked.length === 0) {
        alert('Please select a teacher with a reschedule request.');
        return;
    }
    if (checked.length > 1) {
        alert('Please select only one teacher at a time.');
        return;
    }

    var target = checked[0];
    var teacherId = parseInt(target.value || '0', 10);
    var hasPendingReq = (target.dataset.hasPendingReq || '0') === '1';
    var notifId = parseInt(target.dataset.pendingNotifId || '0', 10);

    if (!hasPendingReq || !teacherId) {
        alert('The selected teacher has no pending reschedule request.');
        return;
    }

    if (!confirm('Accept this teacher reschedule request and notify the teacher?')) return;

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;
    form.style.display = 'none';

    var a = document.createElement('input');
    a.type = 'hidden'; a.name = 'action'; a.value = 'accept_reschedule_request';
    form.appendChild(a);

    var t = document.createElement('input');
    t.type = 'hidden'; t.name = 'teacher_id'; t.value = String(teacherId);
    form.appendChild(t);

    if (notifId > 0) {
        var n = document.createElement('input');
        n.type = 'hidden'; n.name = 'notification_id'; n.value = String(notifId);
        form.appendChild(n);
    }

    document.body.appendChild(form);
    form.submit();
}

// When a teacher is selected from dropdown, populate their existing schedule data
document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('schedule_teacher_id');
    if (select) {
        select.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) return;
            const isRescheduleMode = document.getElementById('modal_is_reschedule')?.value === '1';
            // Keep hidden reschedule_teacher_id in sync when reschedule mode is active
            try {
                const reschedInput = document.getElementById('reschedule_teacher_id');
                if (reschedInput) {
                    if (isRescheduleMode) {
                        reschedInput.value = this.value || '';
                    } else {
                        // Clear any leftover reschedule id when not in reschedule mode
                        reschedInput.value = '';
                    }
                }
            } catch (e) {}

            const schedule = opt.dataset.schedule || '';
            const room = opt.dataset.room || '';
            const focus = opt.dataset.focus || '';
            const subjectArea = opt.dataset.subjectArea || '';
            const subject = opt.dataset.subject || '';
            const semester = opt.dataset.semester || '';
            const formType = opt.dataset.formType || 'iso';
            const scheduledDept = opt.dataset.scheduledDepartment || '';

            // Only preload existing schedule data in Reschedule mode
            if (isRescheduleMode) {
                document.getElementById('modal_evaluation_room').value = room;
                document.getElementById('modal_evaluation_subject_area').value = subjectArea;
                document.getElementById('modal_evaluation_subject').value = subject;
            } else {
                document.getElementById('modal_evaluation_room').value = '';
                document.getElementById('modal_evaluation_subject_area').value = '';
                document.getElementById('modal_evaluation_subject').value = '';
            }

            // Set form type radio
            const effectiveFormType = isRescheduleMode ? formType : 'iso';
            document.getElementById('modal_form_iso').checked = (effectiveFormType === 'iso');
            const peacRadio = document.getElementById('modal_form_peac');
            const bothRadio = document.getElementById('modal_form_both');
            if (peacRadio) peacRadio.checked = (effectiveFormType === 'peac');
            if (bothRadio) bothRadio.checked = (effectiveFormType === 'both');
            // Toggle focus visibility
            document.getElementById('focusObservationGroup').style.display = '';
            document.getElementById('isoFocusCheckboxes').style.display = (effectiveFormType === 'peac') ? 'none' : '';
            document.getElementById('peacFocusCheckboxes').style.display = (effectiveFormType === 'iso') ? 'none' : (effectiveFormType === 'peac' || effectiveFormType === 'both') ? '' : 'none';

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
            if (isRescheduleMode && focus) {
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

            // Set date & time (start and end)
            const dateInput = document.getElementById('modal_evaluation_date');
            const startTimeInput = document.getElementById('modal_evaluation_start_time');
            const endTimeInput = document.getElementById('modal_evaluation_end_time');
            if (isRescheduleMode && schedule) {
                const normalized = schedule.replace(' ', 'T');
                const parsed = new Date(normalized);
                if (!isNaN(parsed.getTime())) {
                    dateInput.value = parsed.toISOString().slice(0, 10);
                    startTimeInput.value = parsed.toTimeString().slice(0, 5);
                } else if (normalized.includes('T')) {
                    const parts = normalized.split('T');
                    dateInput.value = parts[0] || '';
                    startTimeInput.value = (parts[1] || '').slice(0, 5);
                }
            } else {
                dateInput.value = '';
                startTimeInput.value = '';
            }
            // Set end time if available
            const scheduleEnd = opt.dataset.scheduleEnd || '';
            if (isRescheduleMode && scheduleEnd) {
                const normalized = scheduleEnd.replace(' ', 'T');
                const parsed = new Date(normalized);
                if (!isNaN(parsed.getTime())) {
                    endTimeInput.value = parsed.toTimeString().slice(0, 5);
                } else if (normalized.includes('T')) {
                    const parts = normalized.split('T');
                    endTimeInput.value = (parts[1] || '').slice(0, 5);
                }
            } else {
                endTimeInput.value = '';
            }

            // Populate department selector for leaders
            const deptSelect = document.getElementById('modal_scheduled_department');
            if (deptSelect) {
                deptSelect.innerHTML = '<option value="">-- Select department --</option>';
                const deptsJson = opt.dataset.departments || '[]';
                try {
                    const teacherDepts = JSON.parse(deptsJson);
                    let finalDepts = Array.isArray(teacherDepts) ? teacherDepts.filter(d => availableDepartments.includes(d)) : [];
                    if (finalDepts.length === 0) finalDepts = availableDepartments.slice();
                    finalDepts.forEach(d => {
                        const o = document.createElement('option');
                        o.value = d;
                        o.textContent = departmentMap[d] || d;
                        deptSelect.appendChild(o);
                    });
                    // Prefer existing scheduled department if still valid
                    if (scheduledDept && finalDepts.includes(scheduledDept)) {
                        deptSelect.value = scheduledDept;
                    } else if (finalDepts.length === 1) {
                        deptSelect.value = finalDepts[0];
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
            const startTimeVal = document.getElementById('modal_evaluation_start_time')?.value || '';
            const endTimeVal = document.getElementById('modal_evaluation_end_time')?.value || '';
            document.getElementById('modal_evaluation_schedule').value = (dateVal && startTimeVal) ? `${dateVal} ${startTimeVal}:00` : '';
            document.getElementById('modal_evaluation_schedule_end').value = (dateVal && endTimeVal) ? `${dateVal} ${endTimeVal}:00` : '';
        });
    }
});

// Reschedule checkboxes
(function() {
    var checkAll = document.getElementById('checkAllTeachers');
    var rescheduleBtn = document.getElementById('bulkRescheduleBtn');
    var cancelBtn = document.getElementById('bulkCancelBtn');
    var acceptReqBtn = document.getElementById('acceptRescheduleBtn');
    var countBadge = document.getElementById('rescheduleCount');

    function updateRescheduleState() {
        var checked = document.querySelectorAll('.reschedule-check:checked');
        var count = checked.length;
        var canAcceptReq = false;
        if (count === 1) {
            canAcceptReq = (checked[0].dataset.hasPendingReq || '0') === '1';
        }
        if (rescheduleBtn) rescheduleBtn.disabled = (count === 0);
        if (cancelBtn) cancelBtn.disabled = (count === 0);
        if (acceptReqBtn) acceptReqBtn.disabled = !canAcceptReq;
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
    var reqBtn = document.getElementById('myObsReschedBtn');

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

        function getCanvasPoint(clientX, clientY) {
            var rect = canvas.getBoundingClientRect();
            var scaleX = canvas.width / rect.width;
            var scaleY = canvas.height / rect.height;
            return {
                x: (clientX - rect.left) * scaleX,
                y: (clientY - rect.top) * scaleY
            };
        }

        canvas.addEventListener('mousedown', function(e) {
            var p = getCanvasPoint(e.clientX, e.clientY);
            drawing = true; ctx.beginPath(); ctx.moveTo(p.x, p.y);
        });
        canvas.addEventListener('mousemove', function(e) {
            if (!drawing) return; hasDrawn = true;
            var p = getCanvasPoint(e.clientX, e.clientY);
            ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#000';
            ctx.lineTo(p.x, p.y); ctx.stroke();
        });
        canvas.addEventListener('mouseup', function() { drawing = false; });
        canvas.addEventListener('mouseleave', function() { drawing = false; });
        canvas.addEventListener('touchstart', function(e) {
            e.preventDefault();
            var touch = e.touches[0];
            var p = getCanvasPoint(touch.clientX, touch.clientY);
            drawing = true; ctx.beginPath(); ctx.moveTo(p.x, p.y);
        });
        canvas.addEventListener('touchmove', function(e) {
            e.preventDefault(); if (!drawing) return; hasDrawn = true;
            var touch = e.touches[0];
            var p = getCanvasPoint(touch.clientX, touch.clientY);
            ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#000';
            ctx.lineTo(p.x, p.y); ctx.stroke();
        });
        canvas.addEventListener('touchend', function() { drawing = false; });
        // Update hidden inputs now that container exists
        updateMyObsCheckboxState();
    }

    function updateMyObsCheckboxState() {
        var checks = document.querySelectorAll('.sign-item-check:checked');
        var count = checks.length;
        var canSign = !!panelEl && !!container;
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
            toggleBtn.disabled = (!canSign || count === 0);
            if (canSign && count > 0) {
                badgeEl.textContent = count;
                badgeEl.style.display = '';
            } else {
                badgeEl.style.display = 'none';
                if (panelEl) panelEl.style.display = 'none';
            }
        }
        if (reqBtn) reqBtn.disabled = (count !== 1);
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

    window.openMyObsRescheduleModal = function() {
        var checks = document.querySelectorAll('.sign-item-check:checked');
        if (checks.length !== 1) {
            alert('Please select exactly one schedule.');
            return;
        }
        var selected = checks[0];
        var itemInput = document.getElementById('myObsRescheduleItem');
        var txt = document.getElementById('myObsRescheduleScheduleText');
        if (itemInput) itemInput.value = selected.value;
        if (txt) txt.textContent = selected.dataset.scheduleLabel || ('Selected schedule item: ' + selected.value);

        var reason = document.getElementById('myObsRescheduleReason');
        var otherWrap = document.getElementById('myObsRescheduleOtherWrap');
        var otherInput = document.getElementById('myObsRescheduleOther');
        if (reason) reason.value = '';
        if (otherWrap) otherWrap.style.display = 'none';
        if (otherInput) {
            otherInput.value = '';
            otherInput.required = false;
        }

        var modalEl = document.getElementById('myObsRescheduleModal');
        if (!modalEl) return;
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    };
})();

document.addEventListener('DOMContentLoaded', function() {
    // Move reschedule modal to <body> so it is above the backdrop and fully clickable.
    // The page wrapper uses its own stacking context; keeping modal inside it can make
    // the backdrop cover the modal.
    var modalHostEl = document.getElementById('myObsRescheduleModal');
    if (modalHostEl && modalHostEl.parentElement !== document.body) {
        document.body.appendChild(modalHostEl);
    }

    var reasonSelect = document.getElementById('myObsRescheduleReason');
    var otherWrap = document.getElementById('myObsRescheduleOtherWrap');
    var otherInput = document.getElementById('myObsRescheduleOther');
    if (reasonSelect) {
        reasonSelect.addEventListener('change', function() {
            var isOther = this.value === 'others';
            if (otherWrap) otherWrap.style.display = isOther ? '' : 'none';
            if (otherInput) otherInput.required = isOther;
        });
    }

    var reschedModalEl = document.getElementById('myObsRescheduleModal');
    if (reschedModalEl) {
        reschedModalEl.addEventListener('hidden.bs.modal', function() {
            if (reasonSelect) reasonSelect.value = '';
            if (otherWrap) otherWrap.style.display = 'none';
            if (otherInput) {
                otherInput.value = '';
                otherInput.required = false;
            }
        });
    }

    // Auto-open reschedule modal when coming from notification link
    var autoOpenReschedule = '<?php echo (!empty($_GET['open_reschedule']) ? '1' : ''); ?>' === '1';
    var targetTeacherId = '<?php echo isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0; ?>';
    if (!autoOpenReschedule || !targetTeacherId) return;

    var targetCheckbox = document.querySelector('.reschedule-check[value="' + targetTeacherId + '"]');
    if (targetCheckbox) {
        document.querySelectorAll('.reschedule-check').forEach(function(cb) { cb.checked = false; });
        targetCheckbox.checked = true;
        targetCheckbox.dispatchEvent(new Event('change'));
        var hasPendingReq = (targetCheckbox.dataset.hasPendingReq || '0') === '1';
        var acceptBtn = document.getElementById('acceptRescheduleBtn');
        if (hasPendingReq && acceptBtn) {
            alert('Please click "Accept Reschedule Request" first before setting the new schedule.');
        } else {
            openRescheduleModal();
        }
        return;
    }

    var teacherSelect = document.getElementById('schedule_teacher_id');
    var modalEl = document.getElementById('scheduleModal');
    if (teacherSelect && modalEl && teacherSelect.querySelector('option[value="' + targetTeacherId + '"]')) {
        setModalRescheduleMode(true);
        teacherSelect.value = targetTeacherId;
        teacherSelect.dispatchEvent(new Event('change'));
        populateDepartmentDropdown();
        updateFormTypeVisibility();
        updateSubjectLabels();
        new bootstrap.Modal(modalEl).show();
    }
});
</script>
</body>
</html>

