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

$is_observer_only_role = (($_SESSION['role'] ?? '') === 'president');
$is_president_role = (($_SESSION['role'] ?? '') === 'president');

$database = new Database();
$db = $database->getConnection();
$teacher = new Teacher($db);
$success_message = null;
$error_message = null;

function normalizeSubjectDisplay($subject) {
    $s = trim((string)$subject);
    if ($s === '') return '';
    $s = preg_replace('/\s+\d{1,2}:\d{2}\s*(AM|PM)(?:\s*-\s*(?:\d{1,2}:\d{2}\s*(AM|PM))?)?\s*$/i', '', $s);
    return trim((string)$s);
}

function formatFocusDisplay($focusRaw, array $labels) {
    $focusRaw = trim((string)$focusRaw);
    if ($focusRaw === '') return '';

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

    return implode(', ', array_values(array_unique($display)));
}

// Ensure evaluation status supports "rescheduled" for remarks workflow.
// This keeps behavior consistent across Dean/Coordinator/President/VP views.
try {
    if ($db) {
        // Dedicated schedule history table for advance scheduling.
        $db->exec("
            CREATE TABLE IF NOT EXISTS teacher_schedules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                teacher_id INT NOT NULL,
                evaluation_id INT NULL,
                academic_year VARCHAR(20) NOT NULL,
                semester VARCHAR(10) NOT NULL,
                schedule_start DATETIME NOT NULL,
                schedule_end DATETIME NULL,
                room VARCHAR(255) NULL,
                focus_json TEXT NULL,
                subject_area VARCHAR(255) NULL,
                subject VARCHAR(255) NULL,
                form_type ENUM('iso','peac','both') NOT NULL DEFAULT 'iso',
                scheduled_department VARCHAR(100) NULL,
                scheduled_by INT NULL,
                status ENUM('scheduled','rescheduled','cancelled','completed','observer_unbalanced') NOT NULL DEFAULT 'scheduled',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_teacher_schedules_teacher (teacher_id),
                INDEX idx_teacher_schedules_eval (evaluation_id),
                INDEX idx_teacher_schedules_slot (teacher_id, schedule_start),
                UNIQUE KEY uniq_teacher_schedule_slot (teacher_id, schedule_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Ensure per-schedule observer acceptance support exists.
        $taEvalCol = $db->query("SHOW COLUMNS FROM teacher_assignments LIKE 'eval_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$taEvalCol) {
            $db->exec("ALTER TABLE teacher_assignments ADD COLUMN eval_id INT NULL AFTER teacher_id");
            $db->exec("CREATE INDEX idx_teacher_assignments_eval_id ON teacher_assignments (eval_id)");
        }
        $statusCol = $db->query("SHOW COLUMNS FROM evaluations LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $statusType = strtolower(trim((string)($statusCol['Type'] ?? '')));
        if ($statusType !== '' && strpos($statusType, "enum(") === 0 && strpos($statusType, "'rescheduled'") === false) {
            $db->exec("ALTER TABLE evaluations MODIFY COLUMN status ENUM('draft','pending','rescheduled','completed','observer_unbalanced') DEFAULT 'draft'");
        } elseif ($statusType !== '' && strpos($statusType, "enum(") === 0 && strpos($statusType, "'observer_unbalanced'") === false) {
            $db->exec("ALTER TABLE evaluations MODIFY COLUMN status ENUM('draft','pending','rescheduled','completed','observer_unbalanced') DEFAULT 'draft'");
        }
        $scheduleStatusCol = $db->query("SHOW COLUMNS FROM teacher_schedules LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $scheduleStatusType = strtolower(trim((string)($scheduleStatusCol['Type'] ?? '')));
        if ($scheduleStatusType !== '' && strpos($scheduleStatusType, "enum(") === 0 && strpos($scheduleStatusType, "'observer_unbalanced'") === false) {
            $db->exec("ALTER TABLE teacher_schedules MODIFY COLUMN status ENUM('scheduled','rescheduled','cancelled','completed','observer_unbalanced') NOT NULL DEFAULT 'scheduled'");
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
        $_SESSION['error'] = 'President can only observe/evaluate.';
        $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
        if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
        if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
        if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
        header("Location: $redirect");
        exit();
    }

    $raw_eval_ids = $_POST['eval_ids'] ?? '[]';
    $eval_ids = [];
    if (is_string($raw_eval_ids)) {
        $decoded_eval_ids = json_decode($raw_eval_ids, true);
        if (is_array($decoded_eval_ids)) {
            $eval_ids = $decoded_eval_ids;
        }
    } elseif (is_array($raw_eval_ids)) {
        $eval_ids = $raw_eval_ids;
    }
    $eval_ids = array_values(array_unique(array_filter(array_map('intval', $eval_ids), function($id) {
        return $id > 0;
    })));

    $raw_sched_only_ids = $_POST['schedule_only_teacher_ids'] ?? '[]';
    $schedule_only_teacher_ids = [];
    if (is_string($raw_sched_only_ids)) {
        $decoded_sched_only_ids = json_decode($raw_sched_only_ids, true);
        if (is_array($decoded_sched_only_ids)) $schedule_only_teacher_ids = $decoded_sched_only_ids;
    } elseif (is_array($raw_sched_only_ids)) {
        $schedule_only_teacher_ids = $raw_sched_only_ids;
    }
    $schedule_only_teacher_ids = array_values(array_unique(array_filter(array_map('intval', $schedule_only_teacher_ids), function($id) {
        return $id > 0;
    })));

    if (!empty($eval_ids) || !empty($schedule_only_teacher_ids)) {
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

            $affected_teacher_ids = [];
            if (!empty($eval_ids)) {
                // Cancel specific schedule row(s) via evaluation id(s).
                $ph_eval = implode(',', array_fill(0, count($eval_ids), '?'));

                // SAFETY GUARD: never delete completed evaluations.
                $completed_ids = [];
                try {
                    $completed_q = $db->prepare("SELECT id FROM evaluations WHERE id IN ($ph_eval) AND status = 'completed'");
                    $completed_q->execute($eval_ids);
                    $completed_ids = array_map('intval', $completed_q->fetchAll(PDO::FETCH_COLUMN) ?: []);
                } catch (Exception $e) {
                    $completed_ids = [];
                }

                if (!empty($completed_ids)) {
                    // Log blocked attempt for audit trail.
                    try {
                        $desc = "Blocked cancel_schedule delete for completed evaluation IDs: " . implode(',', $completed_ids)
                            . " by user_id=" . (int)($_SESSION['user_id'] ?? 0);
                        $log = $db->prepare("INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES (:uid, :act, :desc, :ip)");
                        $log->execute([
                            ':uid' => (int)($_SESSION['user_id'] ?? 0),
                            ':act' => 'CANCEL_SCHEDULE_DELETE_BLOCKED',
                            ':desc' => $desc,
                            ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                        ]);
                    } catch (Exception $e) {}
                }

                // Keep only non-completed IDs for deletion path.
                $eval_ids = array_values(array_filter($eval_ids, function($id) use ($completed_ids) {
                    return !in_array((int)$id, $completed_ids, true);
                }));
                if (empty($eval_ids)) {
                    throw new Exception('Selected evaluation(s) are already completed and cannot be deleted.');
                }

                // Rebuild placeholder list after filtering.
                $ph_eval = implode(',', array_fill(0, count($eval_ids), '?'));

                $eval_tid_stmt = $db->prepare("SELECT DISTINCT teacher_id FROM evaluations WHERE id IN ($ph_eval)");
                $eval_tid_stmt->execute($eval_ids);
                while ($tid = $eval_tid_stmt->fetchColumn()) {
                    $tid = (int)$tid;
                    if ($tid > 0) $affected_teacher_ids[] = $tid;
                }

                $ack_del = $db->prepare("DELETE FROM observation_plan_acknowledgments WHERE evaluation_id IN ($ph_eval)");
                $ack_del->execute($eval_ids);

                // Remove observer links tied specifically to cancelled schedule rows.
                try {
                    $ta_del = $db->prepare("DELETE FROM teacher_assignments WHERE eval_id IN ($ph_eval)");
                    $ta_del->execute($eval_ids);
                } catch (Exception $e) {}

                // Remove request/accept notifications tied to these eval rows.
                // Request links contain eval_id query parameter.
                try {
                    $notif_patterns = [];
                    $notif_params = [];
                    foreach ($eval_ids as $idx => $eidv) {
                        $phName = ':p' . $idx;
                        $notif_patterns[] = "link LIKE $phName";
                        $notif_params[$phName] = '%eval_id=' . (int)$eidv . '%';
                    }
                    if (!empty($notif_patterns)) {
                        $notif_sql = "DELETE FROM notifications
                                      WHERE (" . implode(' OR ', $notif_patterns) . ")
                                        AND type IN ('reschedule_request','reschedule_accepted')";
                        $notif_del = $db->prepare($notif_sql);
                        foreach ($notif_params as $k => $v) {
                            $notif_del->bindValue($k, $v);
                        }
                        $notif_del->execute();
                    }
                } catch (Exception $e) {}

                $ev_del = $db->prepare("DELETE FROM evaluations WHERE id IN ($ph_eval)");
                $ev_del->execute($eval_ids);

                // Keep teacher-level schedule fields in sync ONLY when no non-completed
                // schedule remains for that teacher in the same AY/semester.
            }

            // Also allow cancelling schedule-only rows (no eval_id) selected in UI.
            foreach ($schedule_only_teacher_ids as $tid) {
                $affected_teacher_ids[] = (int)$tid;
            }

            if (!empty($affected_teacher_ids)) {
                $affected_teacher_ids = array_values(array_unique($affected_teacher_ids));
                foreach ($affected_teacher_ids as $tid) {
                    $remaining_stmt = $db->prepare("SELECT COUNT(*) FROM evaluations WHERE teacher_id = :tid AND academic_year = :ay AND semester = :sem AND (status IS NULL OR status <> 'completed')");
                    $remaining_stmt->execute([
                        ':tid' => $tid,
                        ':ay' => $cancel_ay,
                        ':sem' => $cancel_sem
                    ]);
                    $remaining_count = (int)$remaining_stmt->fetchColumn();
                    // If there are no remaining non-completed eval rows for this AY/semester,
                    // or row was selected as schedule-only, clear teacher schedule fields.
                    if ($remaining_count === 0 || in_array($tid, $schedule_only_teacher_ids, true)) {
                        $clr_one = $db->prepare("UPDATE teachers
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
                                                 WHERE id = :id");
                        $clr_one->execute([':id' => $tid]);
                    }
                }

                $ph_tid = implode(',', array_fill(0, count($affected_teacher_ids), '?'));
                $ack_sched_sql = "DELETE FROM observation_plan_acknowledgments
                                  WHERE teacher_id IN ($ph_tid)
                                    AND academic_year = ?
                                    AND semester = ?
                                    AND evaluation_id IS NULL";
                $ack_sched_stmt = $db->prepare($ack_sched_sql);
                $ack_sched_stmt->execute(array_merge($affected_teacher_ids, [$cancel_ay, $cancel_sem]));
            }

            $db->commit();
            $success_message = 'Selected schedule(s) cancelled successfully.';
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error_message = 'Failed to cancel selected schedule(s).';
        }
    } else {
        $error_message = 'No valid evaluation selected for cancellation.';
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
        $raw_ids = $_POST['eval_ids'] ?? '[]';
        $eval_ids = [];
        if (is_string($raw_ids)) {
            $decoded_ids = json_decode($raw_ids, true);
            if (is_array($decoded_ids)) $eval_ids = $decoded_ids;
        } elseif (is_array($raw_ids)) {
            $eval_ids = $raw_ids;
        }

        $eval_ids = array_values(array_unique(array_filter(array_map('intval', $eval_ids), function($id) {
            return $id > 0;
        })));

        // Backward/row fallback: allow teacher_ids and resolve to current schedule eval row.
        $raw_teacher_ids = $_POST['teacher_ids'] ?? '[]';
        $teacher_ids_fallback = [];
        if (is_string($raw_teacher_ids)) {
            $decoded_tids = json_decode($raw_teacher_ids, true);
            if (is_array($decoded_tids)) $teacher_ids_fallback = $decoded_tids;
        } elseif (is_array($raw_teacher_ids)) {
            $teacher_ids_fallback = $raw_teacher_ids;
        }
        $teacher_ids_fallback = array_values(array_unique(array_filter(array_map('intval', $teacher_ids_fallback), function($id) {
            return $id > 0;
        })));

        if (empty($eval_ids) && !empty($teacher_ids_fallback)) {
            $join_ay = trim((string)($_POST['academic_year'] ?? ($_GET['academic_year'] ?? '')));
            $join_sem = trim((string)($_POST['semester'] ?? ($_GET['semester'] ?? '1st')));
            if (!in_array($join_sem, ['1st', '2nd'], true)) $join_sem = '1st';
            $resolve_eval_stmt_strict = $db->prepare("
                SELECT e.id
                FROM evaluations e
                WHERE e.teacher_id = :tid
                  AND e.academic_year = :ay
                  AND e.semester = :sem
                  AND e.status <> 'completed'
                ORDER BY e.id DESC
                LIMIT 1
            ");
            $resolve_eval_stmt_loose = $db->prepare("
                SELECT e.id
                FROM evaluations e
                WHERE e.teacher_id = :tid
                  AND e.semester = :sem
                  AND e.status <> 'completed'
                ORDER BY e.id DESC
                LIMIT 1
            ");
            foreach ($teacher_ids_fallback as $tidfb) {
                $rid = 0;
                if ($join_ay !== '') {
                    $resolve_eval_stmt_strict->execute([
                        ':tid' => $tidfb,
                        ':ay' => $join_ay,
                        ':sem' => $join_sem
                    ]);
                    $rid = (int)$resolve_eval_stmt_strict->fetchColumn();
                }
                if ($rid <= 0) {
                    $resolve_eval_stmt_loose->execute([
                        ':tid' => $tidfb,
                        ':sem' => $join_sem
                    ]);
                    $rid = (int)$resolve_eval_stmt_loose->fetchColumn();
                }
                if ($rid > 0) $eval_ids[] = $rid;
            }
            $eval_ids = array_values(array_unique($eval_ids));
        }

        if (empty($eval_ids)) {
            $_SESSION['error'] = 'No valid schedule selected.';
        } else {
            $insert_query = "INSERT INTO teacher_assignments (evaluator_id, teacher_id, eval_id, assigned_at)
                             VALUES (:evaluator_id, :teacher_id, :eval_id, NOW())";
            $insert_stmt = $db->prepare($insert_query);
            $eval_teacher_stmt = $db->prepare("SELECT teacher_id FROM evaluations WHERE id = :eid LIMIT 1");
            $eval_source_stmt = $db->prepare(
                "SELECT e.id, e.teacher_id, e.faculty_name, e.department, e.academic_year, e.semester,
                        e.subject_observed, e.observation_date, e.observation_time, e.observation_type,
                        e.observation_room, e.subject_area, e.evaluation_focus, e.evaluation_form_type,
                        e.seat_plan, e.course_syllabi, e.others_requirements, e.others_specify
                 FROM evaluations e
                 WHERE e.id = :eid
                 LIMIT 1"
            );
            $pending_exists_stmt = $db->prepare(
                "SELECT id
                 FROM evaluations
                 WHERE evaluator_id = :evaluator_id
                   AND teacher_id = :teacher_id
                   AND academic_year = :academic_year
                   AND semester = :semester
                   AND observation_date = :observation_date
                   AND COALESCE(observation_time, '') = COALESCE(:observation_time, '')
                   AND evaluation_form_type = :form_type
                   AND status <> 'completed'
                 LIMIT 1"
            );
            $pending_insert_stmt = $db->prepare(
                "INSERT INTO evaluations
                    (teacher_id, faculty_name, department, evaluator_id, academic_year, semester,
                     subject_observed, observation_date, observation_time, observation_type,
                     observation_room, subject_area, evaluation_focus, evaluation_form_type,
                     seat_plan, course_syllabi, others_requirements, others_specify, status, created_at, updated_at)
                 VALUES
                    (:teacher_id, :faculty_name, :department, :evaluator_id, :academic_year, :semester,
                     :subject_observed, :observation_date, :observation_time, :observation_type,
                     :observation_room, :subject_area, :evaluation_focus, :form_type,
                     :seat_plan, :course_syllabi, :others_requirements, :others_specify, 'draft', NOW(), NOW())"
            );
            $count_slot_observers_stmt = $db->prepare(
                "SELECT COUNT(DISTINCT evaluator_id)
                 FROM evaluations
                 WHERE teacher_id = :tid
                   AND academic_year = :ay
                   AND semester = :sem
                   AND observation_date = :od
                   AND COALESCE(observation_time, '') = COALESCE(:ot, '')
                   AND status <> 'completed'"
            );
            $restore_balanced_slot_stmt = $db->prepare(
                "UPDATE evaluations
                 SET status = 'draft', updated_at = NOW()
                 WHERE teacher_id = :tid
                   AND academic_year = :ay
                   AND semester = :sem
                   AND observation_date = :od
                   AND COALESCE(observation_time, '') = COALESCE(:ot, '')
                   AND status = 'observer_unbalanced'"
            );
            $restore_balanced_schedule_stmt = $db->prepare(
                "UPDATE teacher_schedules
                 SET status = 'scheduled', updated_at = NOW()
                 WHERE teacher_id = :tid
                   AND academic_year = :ay
                   AND semester = :sem
                   AND DATE(schedule_start) = :od
                   AND COALESCE(DATE_FORMAT(schedule_start, '%H:%i'), '00:00') = :ot_min
                   AND status = 'observer_unbalanced'"
            );
            $schedule_guard_stmt = $db->prepare(
                "SELECT e.id,
                        COALESCE(
                            NULLIF(t.evaluation_schedule_end, ''),
                            NULLIF(CONCAT(e.observation_date, ' ', COALESCE(NULLIF(e.observation_time, ''), '00:00:00')), ''),
                            NULLIF(t.evaluation_schedule, '')
                        ) AS cutoff_raw
                 FROM evaluations e
                 LEFT JOIN teachers t ON t.id = e.teacher_id
                 WHERE e.id = :eid
                 LIMIT 1"
            );
            $added_count = 0;
            $skipped_past_count = 0;
            foreach ($eval_ids as $eid) {
                // Past schedules can no longer be accepted as observer.
                $schedule_guard_stmt->execute([':eid' => $eid]);
                $guard_row = $schedule_guard_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($guard_row) {
                    $cutoff_raw = trim((string)($guard_row['cutoff_raw'] ?? ''));
                    if ($cutoff_raw !== '') {
                        try {
                            $tz = new DateTimeZone('Asia/Manila');
                            $cutoff_at = new DateTime($cutoff_raw, $tz);
                            $now_at = new DateTime('now', $tz);
                            if ($now_at > $cutoff_at) {
                                $skipped_past_count++;
                                continue;
                            }
                        } catch (Exception $e) {}
                    }
                }

                $eval_teacher_stmt->execute([':eid' => $eid]);
                $tid = (int)$eval_teacher_stmt->fetchColumn();
                if ($tid <= 0) continue;
                $exists_stmt = $db->prepare("SELECT 1 FROM teacher_assignments WHERE evaluator_id = :eid AND teacher_id = :tid AND eval_id = :eval_id LIMIT 1");
                $exists_stmt->execute([':eid' => $_SESSION['user_id'], ':tid' => $tid, ':eval_id' => $eid]);
                if ($exists_stmt->fetchColumn()) {
                    continue;
                }
                if ($insert_stmt->execute([':evaluator_id' => $_SESSION['user_id'], ':teacher_id' => $tid, ':eval_id' => $eid])) {
                    $added_count++;

                    // Auto-create pending evaluation row(s) for the accepted observer
                    // so teacher "My Evaluations" immediately shows pending completion.
                    $src = null;
                    try {
                        $eval_source_stmt->execute([':eid' => $eid]);
                        $src = $eval_source_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                        if ($src) {
                            $raw_ft = strtolower(trim((string)($src['evaluation_form_type'] ?? 'iso')));
                            $forms_to_create = ($raw_ft === 'both') ? ['iso', 'peac'] : [$raw_ft ?: 'iso'];
                            foreach ($forms_to_create as $ft_create) {
                                if (!in_array($ft_create, ['iso', 'peac'], true)) $ft_create = 'iso';
                                $pending_exists_stmt->execute([
                                    ':evaluator_id' => (int)($_SESSION['user_id'] ?? 0),
                                    ':teacher_id' => (int)$src['teacher_id'],
                                    ':academic_year' => (string)($src['academic_year'] ?? ''),
                                    ':semester' => (string)($src['semester'] ?? ''),
                                    ':observation_date' => (string)($src['observation_date'] ?? ''),
                                    ':observation_time' => (string)($src['observation_time'] ?? ''),
                                    ':form_type' => $ft_create
                                ]);
                                if ($pending_exists_stmt->fetchColumn()) {
                                    continue;
                                }
                                $pending_insert_stmt->execute([
                                    ':teacher_id' => (int)$src['teacher_id'],
                                    ':faculty_name' => (string)($src['faculty_name'] ?? ''),
                                    ':department' => (string)($src['department'] ?? ''),
                                    ':evaluator_id' => (int)($_SESSION['user_id'] ?? 0),
                                    ':academic_year' => (string)($src['academic_year'] ?? ''),
                                    ':semester' => (string)($src['semester'] ?? ''),
                                    ':subject_observed' => (string)($src['subject_observed'] ?? ''),
                                    ':observation_date' => (string)($src['observation_date'] ?? ''),
                                    ':observation_time' => (string)($src['observation_time'] ?? ''),
                                    ':observation_type' => (string)($src['observation_type'] ?? ''),
                                    ':observation_room' => (string)($src['observation_room'] ?? ''),
                                    ':subject_area' => (string)($src['subject_area'] ?? ''),
                                    ':evaluation_focus' => (string)($src['evaluation_focus'] ?? ''),
                                    ':form_type' => $ft_create,
                                    ':seat_plan' => (int)($src['seat_plan'] ?? 0),
                                    ':course_syllabi' => (int)($src['course_syllabi'] ?? 0),
                                    ':others_requirements' => (int)($src['others_requirements'] ?? 0),
                                    ':others_specify' => (string)($src['others_specify'] ?? ''),
                                ]);
                            }
                        }
                    } catch (Exception $e) {}

                    try {
                        if (!empty($src)) {
                            $slotParamsBalance = [
                                ':tid' => (int)$src['teacher_id'],
                                ':ay' => (string)($src['academic_year'] ?? ''),
                                ':sem' => (string)($src['semester'] ?? ''),
                                ':od' => (string)($src['observation_date'] ?? ''),
                                ':ot' => (string)($src['observation_time'] ?? '')
                            ];
                            $count_slot_observers_stmt->execute($slotParamsBalance);
                            if ((int)$count_slot_observers_stmt->fetchColumn() >= 2) {
                                $restore_balanced_slot_stmt->execute($slotParamsBalance);
                                $restore_balanced_schedule_stmt->execute([
                                    ':tid' => (int)$src['teacher_id'],
                                    ':ay' => (string)($src['academic_year'] ?? ''),
                                    ':sem' => (string)($src['semester'] ?? ''),
                                    ':od' => (string)($src['observation_date'] ?? ''),
                                    ':ot_min' => substr((string)($src['observation_time'] ?? '00:00'), 0, 5) ?: '00:00'
                                ]);
                            }
                        }
                    } catch (Exception $e) {}

                    // Notify teacher + department evaluators that President/VP accepted as observer.
                    try {
                        $acc_name = trim((string)($_SESSION['name'] ?? 'Observer'));
                        $acc_role = ucwords(str_replace('_', ' ', (string)($_SESSION['role'] ?? '')));

                        $row_stmt = $db->prepare("
                            SELECT t.name AS teacher_name, t.user_id AS teacher_user_id,
                                   COALESCE(NULLIF(e.department,''), NULLIF(t.scheduled_department,''), t.department) AS owning_dept
                            FROM evaluations e
                            JOIN teachers t ON t.id = e.teacher_id
                            WHERE e.id = :eid
                            LIMIT 1
                        ");
                        $row_stmt->execute([':eid' => $eid]);
                        $row = $row_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $teacher_name = trim((string)($row['teacher_name'] ?? 'Teacher'));
                        $teacher_uid = (int)($row['teacher_user_id'] ?? 0);
                        $owning_dept = trim((string)($row['owning_dept'] ?? ''));

                        $title = 'Observer Accepted';
                        $msg = $acc_role . ' ' . $acc_name . " accepted as observer for {$teacher_name}.";
                        $link = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');

                        $insN = $db->prepare("INSERT INTO notifications (user_id, teacher_id, type, title, message, link, is_read) VALUES (:uid, :tid, 'observer_accept', :title, :msg, :link, 0)");
                        $sent = [];
                        if ($teacher_uid > 0 && $teacher_uid !== (int)($_SESSION['user_id'] ?? 0)) {
                            $insN->execute([':uid' => $teacher_uid, ':tid' => $tid, ':title' => $title, ':msg' => $msg, ':link' => $link]);
                            $sent[$teacher_uid] = true;
                        }

                        if ($owning_dept !== '') {
                            $rcpt_stmt = $db->prepare("
                                SELECT id, name, email FROM users
                                WHERE department = :dept
                                  AND status = 'active'
                                  AND LOWER(REPLACE(TRIM(role), ' ', '_')) IN ('dean','principal','chairperson','subject_coordinator','grade_level_coordinator')
                            ");
                            $rcpt_stmt->execute([':dept' => $owning_dept]);
                            while ($rc = $rcpt_stmt->fetch(PDO::FETCH_ASSOC)) {
                                $uid = (int)($rc['id'] ?? 0);
                                if ($uid <= 0 || isset($sent[$uid]) || $uid === (int)($_SESSION['user_id'] ?? 0)) continue;
                                $insN->execute([':uid' => $uid, ':tid' => $tid, ':title' => $title, ':msg' => $msg, ':link' => $link]);
                                $rcEmail = trim((string)($rc['email'] ?? ''));
                                if ($rcEmail !== '') {
                                    sendGenericNotificationEmail(
                                        $rcEmail,
                                        trim((string)($rc['name'] ?? 'Evaluator')),
                                        $title,
                                        $msg
                                    );
                                }
                                $sent[$uid] = true;
                            }
                        }

                        // Send email to teacher if we notified them in-app.
                        if ($teacher_uid > 0 && !empty($row['teacher_name'])) {
                            $teacherEmailStmt = $db->prepare("SELECT email FROM users WHERE id = :uid LIMIT 1");
                            $teacherEmailStmt->execute([':uid' => $teacher_uid]);
                            $teacherEmail = trim((string)$teacherEmailStmt->fetchColumn());
                            if ($teacherEmail !== '') {
                                sendGenericNotificationEmail(
                                    $teacherEmail,
                                    $teacher_name,
                                    $title,
                                    $msg
                                );
                            }
                        }
                    } catch (Exception $e) {}
                }
            }

            if ($added_count > 0) {
                $msg = $added_count . ' teacher(s) accepted as observer.';
                if ($skipped_past_count > 0) {
                    $msg .= ' ' . $skipped_past_count . ' skipped because the schedule already passed.';
                }
                $_SESSION['success'] = $msg;
            } else {
                if ($skipped_past_count > 0) {
                    $_SESSION['error'] = 'Selected schedule(s) already passed and can no longer be accepted as observer.';
                } else {
                    $_SESSION['info'] = 'Selected teacher(s) are already in your observer list.';
                }
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

// Handle observer cancel/removal for President/VP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'leave_observer') {
    $is_leader_role = in_array($_SESSION['role'] ?? '', ['president', 'vice_president'], true);
    if (!$is_leader_role) {
        $_SESSION['error'] = 'Only president/vice president can cancel as observer.';
    } else {
        $observer_reason = trim((string)($_POST['observer_reason'] ?? ''));
        if ($observer_reason === '') {
            $_SESSION['error'] = 'Reason is required when cancelling as observer.';
            $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
            if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
            if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
            if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
            header("Location: $redirect");
            exit();
        }
        $raw_ids = $_POST['eval_ids'] ?? '[]';
        $eval_ids = [];
        if (is_string($raw_ids)) {
            $decoded_ids = json_decode($raw_ids, true);
            if (is_array($decoded_ids)) $eval_ids = $decoded_ids;
        } elseif (is_array($raw_ids)) {
            $eval_ids = $raw_ids;
        }
        $eval_ids = array_values(array_unique(array_filter(array_map('intval', $eval_ids), function($id) {
            return $id > 0;
        })));

        if (empty($eval_ids)) {
            $_SESSION['error'] = 'No valid schedule selected.';
        } else {
            $removed_count = 0;
            $src_stmt = $db->prepare("SELECT id, teacher_id, academic_year, semester, observation_date, observation_time, evaluation_form_type
                                      FROM evaluations
                                      WHERE id = :eid
                                      LIMIT 1");
            $teacher_user_stmt = $db->prepare("SELECT t.name, t.user_id, u.email
                                               FROM teachers t
                                               LEFT JOIN users u ON u.id = t.user_id
                                               WHERE t.id = :tid
                                               LIMIT 1");
            $del_assign_stmt = $db->prepare("DELETE FROM teacher_assignments
                                             WHERE evaluator_id = :uid
                                               AND teacher_id = :tid
                                               AND eval_id = :eid");
            $del_pending_slot_stmt = $db->prepare("DELETE FROM evaluations
                                                   WHERE evaluator_id = :uid
                                                     AND teacher_id = :tid
                                                     AND academic_year = :ay
                                                     AND semester = :sem
                                                     AND observation_date = :od
                                                     AND COALESCE(observation_time, '') = COALESCE(:ot, '')
                                                     AND evaluation_form_type = :ft
                                                     AND status <> 'completed'");
            $count_remaining_slot_stmt = $db->prepare("SELECT COUNT(*)
                                                       FROM evaluations
                                                       WHERE teacher_id = :tid
                                                         AND academic_year = :ay
                                                         AND semester = :sem
                                                         AND observation_date = :od
                                                         AND COALESCE(observation_time, '') = COALESCE(:ot, '')
                                                         AND status <> 'completed'");
            $count_remaining_observers_stmt = $db->prepare("SELECT COUNT(DISTINCT evaluator_id)
                                                            FROM evaluations
                                                            WHERE teacher_id = :tid
                                                              AND academic_year = :ay
                                                              AND semester = :sem
                                                              AND observation_date = :od
                                                              AND COALESCE(observation_time, '') = COALESCE(:ot, '')
                                                              AND status <> 'completed'");
            $balance_remaining_observers_stmt = $db->prepare(
                "SELECT
                    COUNT(DISTINCT e.evaluator_id) AS observer_count,
                    MAX(CASE WHEN LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('dean','principal') THEN 1 ELSE 0 END) AS has_head,
                    MAX(CASE WHEN LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('chairperson','subject_coordinator','grade_level_coordinator') THEN 1 ELSE 0 END) AS has_coordinator
                 FROM evaluations e
                 JOIN users u ON u.id = e.evaluator_id
                 WHERE e.teacher_id = :tid
                   AND e.academic_year = :ay
                   AND e.semester = :sem
                   AND e.observation_date = :od
                   AND COALESCE(e.observation_time, '') = COALESCE(:ot, '')
                   AND (:dept = '' OR e.department = :dept_match)
                   AND e.status <> 'completed'"
            );
            $mark_unbalanced_slot_stmt = $db->prepare("UPDATE evaluations
                                                       SET status = 'observer_unbalanced', updated_at = NOW()
                                                       WHERE teacher_id = :tid
                                                         AND academic_year = :ay
                                                         AND semester = :sem
                                                         AND observation_date = :od
                                                         AND COALESCE(observation_time, '') = COALESCE(:ot, '')
                                                         AND status <> 'completed'");
            $mark_unbalanced_schedule_stmt = $db->prepare("UPDATE teacher_schedules
                                                           SET status = 'observer_unbalanced', updated_at = NOW()
                                                           WHERE teacher_id = :tid
                                                             AND academic_year = :ay
                                                             AND semester = :sem
                                                             AND DATE(schedule_start) = :od
                                                             AND COALESCE(DATE_FORMAT(schedule_start, '%H:%i'), '00:00') = :ot_min
                                                             AND status <> 'completed'");
            $delete_remaining_slot_stmt = $db->prepare("DELETE FROM evaluations
                                                        WHERE teacher_id = :tid
                                                          AND academic_year = :ay
                                                          AND semester = :sem
                                                          AND observation_date = :od
                                                          AND COALESCE(observation_time, '') = COALESCE(:ot, '')
                                                          AND status <> 'completed'");
            $clear_teacher_slot_stmt = $db->prepare("UPDATE teachers
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
                                                     WHERE id = :tid");

            foreach ($eval_ids as $eid) {
                $src_stmt->execute([':eid' => $eid]);
                $src = $src_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$src) continue;

                $tid = (int)($src['teacher_id'] ?? 0);
                if ($tid <= 0) continue;

                $del_assign_stmt->execute([
                    ':uid' => (int)($_SESSION['user_id'] ?? 0),
                    ':tid' => $tid,
                    ':eid' => $eid
                ]);

                $raw_ft = strtolower(trim((string)($src['evaluation_form_type'] ?? 'iso')));
                $forms_to_remove = ($raw_ft === 'both') ? ['iso', 'peac'] : [$raw_ft ?: 'iso'];
                foreach ($forms_to_remove as $ft_remove) {
                    if (!in_array($ft_remove, ['iso', 'peac'], true)) $ft_remove = 'iso';
                    $del_pending_slot_stmt->execute([
                        ':uid' => (int)($_SESSION['user_id'] ?? 0),
                        ':tid' => $tid,
                        ':ay' => (string)($src['academic_year'] ?? ''),
                        ':sem' => (string)($src['semester'] ?? ''),
                        ':od' => (string)($src['observation_date'] ?? ''),
                        ':ot' => (string)($src['observation_time'] ?? ''),
                        ':ft' => $ft_remove
                    ]);
                }

                // If this action leaves the schedule slot with no active evaluator row,
                // cancel the slot so evaluation cannot proceed.
                $slotParams = [
                    ':tid' => $tid,
                    ':ay' => (string)($src['academic_year'] ?? ''),
                    ':sem' => (string)($src['semester'] ?? ''),
                    ':od' => (string)($src['observation_date'] ?? ''),
                    ':ot' => (string)($src['observation_time'] ?? ''),
                ];
                $count_remaining_slot_stmt->execute($slotParams);
                $remaining_slot_rows = (int)$count_remaining_slot_stmt->fetchColumn();
                $count_remaining_observers_stmt->execute($slotParams);
                $remaining_observers = (int)$count_remaining_observers_stmt->fetchColumn();
                $balance_remaining_observers_stmt->execute([
                    ':tid' => $tid,
                    ':ay' => (string)($src['academic_year'] ?? ''),
                    ':sem' => (string)($src['semester'] ?? ''),
                    ':od' => (string)($src['observation_date'] ?? ''),
                    ':ot' => (string)($src['observation_time'] ?? ''),
                    ':dept' => trim((string)($src['department'] ?? '')),
                    ':dept_match' => trim((string)($src['department'] ?? ''))
                ]);
                $balance_remaining = $balance_remaining_observers_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $has_required_head = (int)($balance_remaining['has_head'] ?? 0) === 1;
                $has_required_coordinator = (int)($balance_remaining['has_coordinator'] ?? 0) === 1;
                $is_observer_unbalanced = false;
                if ($remaining_slot_rows <= 0) {
                    $delete_remaining_slot_stmt->execute($slotParams);
                    $clear_teacher_slot_stmt->execute([':tid' => $tid]);
                } elseif ($remaining_observers < 2) {
                    $mark_unbalanced_slot_stmt->execute($slotParams);
                    $mark_unbalanced_schedule_stmt->execute([
                        ':tid' => $tid,
                        ':ay' => (string)($src['academic_year'] ?? ''),
                        ':sem' => (string)($src['semester'] ?? ''),
                        ':od' => (string)($src['observation_date'] ?? ''),
                        ':ot_min' => substr((string)($src['observation_time'] ?? '00:00'), 0, 5) ?: '00:00'
                    ]);
                    $is_observer_unbalanced = true;
                }

                try {
                    $teacher_user_stmt->execute([':tid' => $tid]);
                    $teacher_user = $teacher_user_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($teacher_user && !empty($teacher_user['user_id'])) {
                        $obs_name = trim((string)($_SESSION['name'] ?? 'Observer'));
                        $obs_role = ucfirst(str_replace('_', ' ', (string)($_SESSION['role'] ?? 'evaluator')));
                        $title = 'Observer Unable to Attend';
                        $message = "{$obs_role} {$obs_name} will not be able to observe/evaluate your schedule. Reason: {$observer_reason}";
                        if ($is_observer_unbalanced) {
                            $title = 'Observer Imbalance';
                            $message .= ' Only one observer remains, so the evaluation cannot proceed until another observer is assigned.';
                        }
                        $teacher_link = 'observation_plan.php?view=my_observation&eval_id=' . urlencode((string)$eid);

                        $ins_notif = $db->prepare("INSERT INTO notifications (user_id, teacher_id, type, title, message, link, is_read)
                                                   VALUES (:uid, :tid, 'observer_unavailable', :title, :msg, :lnk, 0)");
                        $ins_notif->execute([
                            ':uid' => (int)$teacher_user['user_id'],
                            ':tid' => $tid,
                            ':title' => $title,
                            ':msg' => $message,
                            ':lnk' => $teacher_link
                        ]);

                        $teacher_email = trim((string)($teacher_user['email'] ?? ''));
                        if ($teacher_email !== '') {
                            sendGenericNotificationEmail(
                                $teacher_email,
                                trim((string)($teacher_user['name'] ?? 'Teacher')),
                                $title,
                                $message
                            );
                        }
                    }
                } catch (Exception $e) {}

                $removed_count++;
            }

            if ($removed_count > 0) {
                $_SESSION['success'] = $removed_count . ' teacher(s) cancelled as observer.';
            } else {
                $_SESSION['info'] = 'No observer assignment was removed.';
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
    $eval_id_accept = (int)($_POST['eval_id'] ?? 0);
    $notif_id_accept = (int)($_POST['notification_id'] ?? 0);
    $teacher_id_accept = 0;
    $request_schedule_key_accept = '';

        // Permanent fix:
        // Use notification row as source of truth when available, because
        // displayed row eval_id can differ from request-linked eval_id.
        if ($notif_id_accept > 0) {
            try {
                $notifSrc = $db->prepare("SELECT teacher_id, link, request_eval_id, request_schedule_key
                                          FROM notifications
                                          WHERE id = :nid
                                            AND type = 'reschedule_request'
                                          LIMIT 1");
                $notifSrc->execute([
                    ':nid' => $notif_id_accept
                ]);
                $notifRow = $notifSrc->fetch(PDO::FETCH_ASSOC);
                if ($notifRow) {
                    $teacher_id_accept = (int)($notifRow['teacher_id'] ?? 0);
                    $dbEval = (int)($notifRow['request_eval_id'] ?? 0);
                    $request_schedule_key_accept = strtolower(trim((string)($notifRow['request_schedule_key'] ?? '')));
                    if ($dbEval > 0) $eval_id_accept = $dbEval;
                    $lnk = trim((string)($notifRow['link'] ?? ''));
                    if ($eval_id_accept <= 0 && $lnk !== '') {
                        $parts = parse_url($lnk);
                        if (!empty($parts['query'])) {
                            parse_str($parts['query'], $qsNotif);
                            $parsedEval = (int)($qsNotif['eval_id'] ?? 0);
                            $parsedTeacher = (int)($qsNotif['teacher_id'] ?? 0);
                            if ($parsedEval > 0) $eval_id_accept = $parsedEval;
                            if ($parsedTeacher > 0) $teacher_id_accept = $parsedTeacher;
                        }
                    }
                }
            } catch (Exception $e) {
                // Backward compatibility when request_* columns are not present yet.
                try {
                    $notifSrcLegacy = $db->prepare("SELECT teacher_id, link
                                                    FROM notifications
                                                    WHERE id = :nid
                                                      AND type = 'reschedule_request'
                                                    LIMIT 1");
                    $notifSrcLegacy->execute([
                        ':nid' => $notif_id_accept
                    ]);
                    $notifRowLegacy = $notifSrcLegacy->fetch(PDO::FETCH_ASSOC);
                    if ($notifRowLegacy) {
                        $teacher_id_accept = (int)($notifRowLegacy['teacher_id'] ?? 0);
                        $lnk = trim((string)($notifRowLegacy['link'] ?? ''));
                        if ($lnk !== '') {
                            $parts = parse_url($lnk);
                            if (!empty($parts['query'])) {
                                parse_str($parts['query'], $qsNotif);
                                $parsedEval = (int)($qsNotif['eval_id'] ?? 0);
                                $parsedTeacher = (int)($qsNotif['teacher_id'] ?? 0);
                                if ($parsedEval > 0) $eval_id_accept = $parsedEval;
                                if ($parsedTeacher > 0) $teacher_id_accept = $parsedTeacher;
                            }
                        }
                    }
                } catch (Exception $e2) {}
            }
        }

        if ($eval_id_accept <= 0 && $teacher_id_accept <= 0) {
            $_SESSION['error'] = 'Invalid reschedule request selection.';
        } else {
            try {
                if ($teacher_id_accept <= 0 && $eval_id_accept > 0) {
                    $evalTeacherStmt = $db->prepare("SELECT teacher_id FROM evaluations WHERE id = :eid LIMIT 1");
                    $evalTeacherStmt->execute([':eid' => $eval_id_accept]);
                    $teacher_id_accept = (int)$evalTeacherStmt->fetchColumn();
                }
                if ($teacher_id_accept <= 0) {
                    throw new Exception('Teacher for request not found.');
                }

                // Mark matching request notification(s) as read for ALL recipients
                // so the pending badge is consistent across evaluator accounts.
                if ($teacher_id_accept > 0) {
                    if ($eval_id_accept > 0) {
                        $readAllStmt = $db->prepare("UPDATE notifications
                                                     SET is_read = 1
                                                     WHERE teacher_id = :tid
                                                       AND type = 'reschedule_request'
                                                       AND (
                                                           request_eval_id = :eid
                                                           OR (request_eval_id IS NULL AND link LIKE :pat1)
                                                       )");
                        $readAllStmt->execute([
                            ':tid' => $teacher_id_accept,
                            ':eid' => $eval_id_accept,
                            ':pat1' => ('%eval_id=' . $eval_id_accept . '%')
                        ]);
                    } elseif ($request_schedule_key_accept !== '') {
                        $readAllBySlotStmt = $db->prepare("UPDATE notifications
                                                           SET is_read = 1
                                                           WHERE teacher_id = :tid
                                                             AND type = 'reschedule_request'
                                                             AND LOWER(TRIM(request_schedule_key)) = :slotkey");
                        $readAllBySlotStmt->execute([
                            ':tid' => $teacher_id_accept,
                            ':slotkey' => $request_schedule_key_accept
                        ]);
                    } else {
                        $readAllTeacherStmt = $db->prepare("UPDATE notifications
                                                            SET is_read = 1
                                                            WHERE teacher_id = :tid
                                                              AND type = 'reschedule_request'");
                        $readAllTeacherStmt->execute([':tid' => $teacher_id_accept]);
                    }
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
                    $message = "{$approver_name} ({$approver_role}) has accepted your reschedule request and will set a new schedule.";
                    $teacherLink = 'observation_plan.php?view=my_observation';
                    if ($eval_id_accept > 0) {
                        $teacherLink .= '&eval_id=' . urlencode((string)$eval_id_accept);
                    }

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

                // Allow exactly one follow-up reschedule save for this accepted request.
                $_SESSION['reschedule_once'] = [
                    'teacher_id' => $teacher_id_accept,
                    'eval_id' => $eval_id_accept,
                    'expires_at' => time() + 1800, // 30 minutes
                ];

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
    $redirect_eval_id = (int)($eval_id_accept ?? 0);
    if ($redirect_eval_id <= 0) {
        $redirect_eval_id = (int)($_POST['eval_id'] ?? 0);
    }
    if ($redirect_eval_id > 0) {
        $redirect .= '&open_reschedule=1&eval_id=' . urlencode((string)$redirect_eval_id);
    }
    if (!empty($teacher_id_accept)) {
        $redirect .= '&teacher_id=' . urlencode((string)$teacher_id_accept);
    }
    header("Location: $redirect");
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_schedule') {
    if ($is_president_role) {
        $_SESSION['error'] = 'President can only observe/evaluate.';
        $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
        if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
        if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
        if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
        header("Location: $redirect");
        exit();
    }

    $teacher_id = $_POST['teacher_id'] ?? ($_POST['reschedule_teacher_id'] ?? '');
    $reschedule_eval_id = (int)($_POST['reschedule_eval_id'] ?? 0);
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
        
        // Get teacher info + current active schedule
        $teacher_stmt = $db->prepare("SELECT name AS faculty_name, department, evaluation_schedule, evaluation_schedule_end FROM teachers WHERE id = :id LIMIT 1");
        $teacher_stmt->bindParam(':id', $teacher_id);
        $teacher_stmt->execute();
        $teacher_row = $teacher_stmt->fetch(PDO::FETCH_ASSOC);
        $faculty_name = $teacher_row['faculty_name'] ?? '';
        $teacher_dept = $teacher_row['department'] ?? '';
        $current_teacher_schedule = trim((string)($teacher_row['evaluation_schedule'] ?? ''));
        
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
        // Resolve requested owning department early so duplicate checks can be
        // scoped per department (a teacher may be scheduled in multiple depts).
        $requested_sched_dept = $scheduled_department !== '' ? $scheduled_department : $teacher_dept;

        // Advance scheduling is allowed; do not block just because
        // teachers.evaluation_schedule currently has a value.
        // Normal "Set Schedule" supports advance scheduling.
        // Block only exact duplicate timeslot per teacher *within the same department*.
        $existing_active_eval_id = 0;
        if (!$is_reschedule) {
            $dup_active_stmt = $db->prepare("
                SELECT id
                FROM evaluations
                WHERE teacher_id = :tid
                  AND COALESCE(NULLIF(department, ''), :teacher_primary_dept) = :owning_dept
                  AND observation_date = :obs_date
                  AND COALESCE(observation_time, '00:00:00') = COALESCE(:obs_time, '00:00:00')
                  AND COALESCE(academic_year, '') = COALESCE(:ay, '')
                  AND COALESCE(semester, '') = COALESCE(:sem, '')
                ORDER BY id DESC
                LIMIT 1
            ");
            $dup_active_stmt->execute([
                ':tid' => $teacher_id,
                ':teacher_primary_dept' => $teacher_dept,
                ':owning_dept' => $requested_sched_dept,
                ':obs_date' => $observation_date,
                ':obs_time' => $observation_time,
                ':ay' => $academic_year_for_insert,
                ':sem' => ($post_semester ?: $filter_semester),
            ]);
            $existing_active_eval_id = (int)($dup_active_stmt->fetchColumn() ?: 0);
            if ($existing_active_eval_id > 0) {
                $_SESSION['error'] = 'This exact schedule already exists for the selected teacher.';
                $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
                if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
                if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
                if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
                header("Location: $redirect");
                exit();
            }

            // Enforce the same duplicate rule at schedule-history level:
            // same teacher + date/time is blocked only within the same department.
            $dup_sched_stmt = $db->prepare("
                SELECT ts.id
                FROM teacher_schedules ts
                WHERE ts.teacher_id = :tid
                  AND ts.schedule_start = :schedule_start
                  AND COALESCE(NULLIF(ts.scheduled_department, ''), :teacher_primary_dept) = :owning_dept
                  AND COALESCE(ts.academic_year, '') = COALESCE(:ay, '')
                  AND COALESCE(ts.semester, '') = COALESCE(:sem, '')
                ORDER BY ts.id DESC
                LIMIT 1
            ");
            $dup_sched_stmt->execute([
                ':tid' => $teacher_id,
                ':schedule_start' => $schedule,
                ':teacher_primary_dept' => $teacher_dept,
                ':owning_dept' => $requested_sched_dept,
                ':ay' => $academic_year_for_insert,
                ':sem' => ($post_semester ?: $filter_semester),
            ]);
            if ((int)($dup_sched_stmt->fetchColumn() ?: 0) > 0) {
                $_SESSION['error'] = 'This exact schedule already exists for this department.';
                $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
                if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
                if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
                if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
                header("Location: $redirect");
                exit();
            }
        }
        // After saving a new schedule (including reschedule), the active row
        // should be in "Scheduled" state again in the remarks column.
        $target_eval_status = 'draft';
        $eval_id = null;
        $debug_action = null;
        
        if ($is_reschedule) {

            // For explicit RESCHEDULE: target only the selected schedule/eval row.
            if ($reschedule_eval_id > 0) {
                // Match by selected evaluation id + teacher only.
                // Do not hard-lock by AY/semester here, because UI filter values can
                // differ and would incorrectly force insert of a duplicate row.
                $find_eval = $db->prepare("SELECT id, status FROM evaluations WHERE id = :eid AND teacher_id = :tid LIMIT 1");
                $find_eval->execute([
                    ':eid' => $reschedule_eval_id,
                    ':tid' => $teacher_id
                ]);
                $eval_row = $find_eval->fetch(PDO::FETCH_ASSOC);
                if ($eval_row && $eval_row['status'] !== 'completed') {
                    $eval_id = (int)$eval_row['id'];
                }
            }
            // For accepted reschedule flow: never create a duplicate row when
            // selected evaluation id is missing/completed.
            if ($eval_id <= 0) {
                $_SESSION['error'] = 'Selected schedule row was not found (or already completed). Please refresh and try again.';
                $redirect = 'observation_plan.php?semester=' . urlencode($_GET['semester'] ?? '1st') . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
                if (!empty($_GET['department'])) $redirect .= '&department=' . urlencode($_GET['department']);
                if (!empty($_GET['month'])) $redirect .= '&month=' . urlencode($_GET['month']);
                if (!empty($_GET['status'])) $redirect .= '&status=' . urlencode($_GET['status']);
                header("Location: $redirect");
                exit();
            }
        } else {
            // For normal "Set Schedule": create a new evaluation row for each
            // valid schedule slot (advance scheduling).
            $eval_id = null;
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
        }

        // Persist schedule history row (one row per scheduled slot).
        // Reschedule updates existing linked row when available; otherwise inserts.
        try {
            $slot_status = $is_reschedule ? 'rescheduled' : 'scheduled';
            if ($is_reschedule) {
                $sched_upd = $db->prepare("UPDATE teacher_schedules
                                           SET schedule_start = :schedule_start,
                                               schedule_end = :schedule_end,
                                               room = :room,
                                               focus_json = :focus_json,
                                               subject_area = :subject_area,
                                               subject = :subject,
                                               form_type = :form_type,
                                               scheduled_department = :scheduled_department,
                                               scheduled_by = :scheduled_by,
                                               status = :status,
                                               updated_at = NOW()
                                           WHERE evaluation_id = :evaluation_id
                                             AND teacher_id = :teacher_id
                                           LIMIT 1");
                $sched_upd->execute([
                    ':schedule_start' => $schedule,
                    ':schedule_end' => ($schedule_end !== '' ? $schedule_end : null),
                    ':room' => $room,
                    ':focus_json' => $focus_json,
                    ':subject_area' => $subject_area,
                    ':subject' => $subject,
                    ':form_type' => $form_type,
                    ':scheduled_department' => ($scheduled_department !== '' ? $scheduled_department : null),
                    ':scheduled_by' => (int)($_SESSION['user_id'] ?? 0),
                    ':status' => $slot_status,
                    ':evaluation_id' => (int)$eval_id,
                    ':teacher_id' => (int)$teacher_id
                ]);
                if ((int)$sched_upd->rowCount() === 0) {
                    $sched_ins = $db->prepare("INSERT INTO teacher_schedules
                        (teacher_id, evaluation_id, academic_year, semester, schedule_start, schedule_end, room, focus_json, subject_area, subject, form_type, scheduled_department, scheduled_by, status)
                        VALUES
                        (:teacher_id, :evaluation_id, :academic_year, :semester, :schedule_start, :schedule_end, :room, :focus_json, :subject_area, :subject, :form_type, :scheduled_department, :scheduled_by, :status)");
                    $sched_ins->execute([
                        ':teacher_id' => (int)$teacher_id,
                        ':evaluation_id' => (int)$eval_id,
                        ':academic_year' => (string)$academic_year_for_insert,
                        ':semester' => (string)($post_semester ?: $filter_semester),
                        ':schedule_start' => $schedule,
                        ':schedule_end' => ($schedule_end !== '' ? $schedule_end : null),
                        ':room' => $room,
                        ':focus_json' => $focus_json,
                        ':subject_area' => $subject_area,
                        ':subject' => $subject,
                        ':form_type' => $form_type,
                        ':scheduled_department' => ($scheduled_department !== '' ? $scheduled_department : null),
                        ':scheduled_by' => (int)($_SESSION['user_id'] ?? 0),
                        ':status' => $slot_status
                    ]);
                }
            } else {
                $sched_ins = $db->prepare("INSERT INTO teacher_schedules
                    (teacher_id, evaluation_id, academic_year, semester, schedule_start, schedule_end, room, focus_json, subject_area, subject, form_type, scheduled_department, scheduled_by, status)
                    VALUES
                    (:teacher_id, :evaluation_id, :academic_year, :semester, :schedule_start, :schedule_end, :room, :focus_json, :subject_area, :subject, :form_type, :scheduled_department, :scheduled_by, :status)");
                $sched_ins->execute([
                    ':teacher_id' => (int)$teacher_id,
                    ':evaluation_id' => (int)$eval_id,
                    ':academic_year' => (string)$academic_year_for_insert,
                    ':semester' => (string)($post_semester ?: $filter_semester),
                    ':schedule_start' => $schedule,
                    ':schedule_end' => ($schedule_end !== '' ? $schedule_end : null),
                    ':room' => $room,
                    ':focus_json' => $focus_json,
                    ':subject_area' => $subject_area,
                    ':subject' => $subject,
                    ':form_type' => $form_type,
                    ':scheduled_department' => ($scheduled_department !== '' ? $scheduled_department : null),
                    ':scheduled_by' => (int)($_SESSION['user_id'] ?? 0),
                    ':status' => $slot_status
                ]);
            }
        } catch (Exception $e) {
            // Keep existing workflow alive; evaluations remain source-of-truth.
        }
        
        // Also update teachers table so row ownership (`scheduled_department`) always
        // reflects the latest schedule context, including secondary departments.
        // Evaluation history still remains in `evaluations`.
        $teacher_info_stmt = $db->prepare("SELECT name AS faculty_name, department, evaluation_schedule FROM teachers WHERE id = :id LIMIT 1");
        $teacher_info_stmt->bindParam(':id', $teacher_id);
        $teacher_info_stmt->execute();
        $teacher_row_info = $teacher_info_stmt->fetch(PDO::FETCH_ASSOC);
        $teacher_primary_dept = trim((string)($teacher_row_info['department'] ?? ''));
        $existing_teacher_schedule = trim((string)($teacher_row_info['evaluation_schedule'] ?? ''));

        $should_update_teacher_table = true;

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
            $stmt->bindValue(':scheduled_by', (int)($_SESSION['user_id'] ?? 0), PDO::PARAM_INT);

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

            $user_dept = trim((string)($_SESSION['department'] ?? ''));
            $can_use_selected_dept =
                $scheduled_department !== '' &&
                in_array($scheduled_department, $teacher_all_depts, true) &&
                (
                    $is_leader_role ||
                    $scheduled_department === $user_dept
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

        // Evaluation ownership is per-row and must follow the schedule department
        // so secondary-department schedules stay exclusive to that department.
        if (!empty($eval_id) && !empty($sched_dept_val)) {
            try {
                $own_stmt = $db->prepare("UPDATE evaluations SET department = :dept WHERE id = :eval_id");
                $own_stmt->execute([':dept' => $sched_dept_val, ':eval_id' => $eval_id]);
                $sched_own_stmt = $db->prepare("UPDATE teacher_schedules SET scheduled_department = :dept WHERE evaluation_id = :eval_id AND teacher_id = :teacher_id");
                $sched_own_stmt->execute([
                    ':dept' => $sched_dept_val,
                    ':eval_id' => (int)$eval_id,
                    ':teacher_id' => (int)$teacher_id
                ]);
            } catch (Exception $e) {}
        }

        // For reschedule: capture current accepted President/VP observers first,
        // so we can notify them to accept again after clearing assignment.
        $pvp_reschedule_recipients = [];
        if ($is_reschedule) {
            try {
                $pvp_rec_stmt = $db->prepare("
                    SELECT DISTINCT u.id, u.name, u.email
                    FROM teacher_assignments ta
                    JOIN users u ON u.id = ta.evaluator_id
                    WHERE ta.teacher_id = :tid
                      AND LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('president','vice_president')
                      AND u.status = 'active'
                      AND u.email IS NOT NULL
                      AND u.email != ''
                ");
                $pvp_rec_stmt->execute([':tid' => $teacher_id]);
                $pvp_reschedule_recipients = $pvp_rec_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {}
        }

        // New schedule/reschedule requires fresh observer acceptance for President/VP.
        // Keep teacher/evaluator signatures intact; clear only President/VP observer assignments.
        try {
            $clr_pvp = $db->prepare("
                DELETE ta
                FROM teacher_assignments ta
                JOIN users u ON u.id = ta.evaluator_id
                WHERE ta.teacher_id = :tid
                  AND LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('president','vice_president')
            ");
            $clr_pvp->execute([':tid' => $teacher_id]);
        } catch (Exception $e) {}

        // Rescheduled rows need a fresh teacher signature for the new slot.
        // Do not carry an old acknowledgment forward on the same evaluation_id.
        if ($is_reschedule && (int)$eval_id > 0) {
            try {
                $clear_ack = $db->prepare("DELETE FROM observation_plan_acknowledgments WHERE evaluation_id = :eval_id");
                $clear_ack->execute([':eval_id' => (int)$eval_id]);
            } catch (Exception $e) {}
        }

        $success_message = $is_reschedule ? "Schedule updated. Teacher will need to sign again." : "Evaluation schedule set successfully!";
        if ($is_reschedule && isset($_SESSION['reschedule_once'])) {
            unset($_SESSION['reschedule_once']);
        }
        // Clear stale pending reschedule-request notifications for this teacher
        // once a new schedule/reschedule is successfully saved.
        try {
            $clear_req = $db->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE teacher_id = :tid
                  AND type = 'reschedule_request'
                  AND is_read = 0
            ");
            $clear_req->execute([':tid' => (int)$teacher_id]);
        } catch (Exception $e) {}

        if (!$teacher_update_ok) {
            $error_message = "Failed to set schedule.";
        }
        notifyScheduleParticipants(
            $db,
            $teacher_id,
            $schedule,
            $room,
            $_SESSION['user_id'],
            $_SESSION['name'] ?? 'Evaluator',
            $_SESSION['role'] ?? '',
            $sched_dept_val ?? '',
            $is_reschedule,
            $pvp_reschedule_recipients
        );
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
                        $eReqStmt = $db->prepare("SELECT e.id, e.observation_date, e.observation_time, e.observation_room, e.subject_observed, e.subject_area, e.department AS eval_department, u.department AS evaluator_department
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
                            $evDept = trim((string)($eReq['eval_department'] ?? ''));
                            if ($evDept === '') {
                                $evDept = trim((string)($eReq['evaluator_department'] ?? ''));
                            }
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
                                                       AND (ta.eval_id = :eid OR ta.eval_id IS NULL)
                                                       AND u.status = 'active'");
                            $obsStmt->execute([
                                ':tid' => (int)$my_teacher_id,
                                ':eid' => (int)$req_eval_id
                            ]);
                            $observerRows = $obsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                            $deptLeadsStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                                           FROM users u
                                                           WHERE u.department = :dept
                                                             AND LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('dean','principal')
                                                             AND u.status = 'active'");
                            $deptLeadsStmt->execute([':dept' => $teacher_dept_req]);
                            $observerRows = array_merge($observerRows, $deptLeadsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

                            $ownerStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                                       FROM evaluations e
                                                       JOIN users u ON u.id = e.evaluator_id
                                                       WHERE e.id = :eid
                                                         AND u.status = 'active'
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
                                                       AND u.status = 'active'");
                            $obsStmt->execute([
                                ':tid' => (int)$my_teacher_id
                            ]);
                            $observerRows = $obsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                            $deptLeadsStmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email, u.role
                                                           FROM users u
                                                           WHERE u.department = :dept
                                                             AND LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('dean','principal')
                                                             AND u.status = 'active'");
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

                $formatted_sched = $req_sched ? date('F d, Y \a\t h:i A', strtotime($req_sched)) : 'To be announced';
                $subject = "Reschedule Request: {$teacher_name_req}";
                $msg = "{$teacher_name_req} has submitted a request to reschedule the classroom observation.\n"
                     . "Proposed Schedule: {$formatted_sched}\n"
                     . "Reason: {$reason_text}";
                if ($req_room !== '') $msg .= "\nRoom: {$req_room}";
                if ($req_subject !== '') $msg .= "\nSubject: {$req_subject}";
                if ($req_subject_area !== '') $msg .= "\nGrade/Section or Subject Area: {$req_subject_area}";

                $notifLink = 'observation_plan.php?' . http_build_query([
                    'open_reschedule' => 1,
                    'teacher_id' => (int)$my_teacher_id,
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
                            $subject,
                            $msg
                        );
                    }
                    try {
                        $notif = $db->prepare("INSERT INTO notifications (user_id, teacher_id, type, title, message, link, request_eval_id, request_schedule_key, is_read)
                                               VALUES (:uid, :tid, 'reschedule_request', :title, :msg, :link, :request_eval_id, :request_schedule_key, 0)");
                        $notif->execute([
                            ':uid' => (int)$rcp['id'],
                            ':tid' => (int)$my_teacher_id,
                            ':title' => $subject,
                            ':msg' => $msg,
                            ':link' => $notifLink,
                            ':request_eval_id' => (int)($req_eval_id ?? 0),
                            ':request_schedule_key' => $req_schedule_key
                        ]);
                    } catch (Exception $e) {
                        // Backward-compatibility fallback if migration has not run yet.
                        try {
                            $notifLegacy = $db->prepare("INSERT INTO notifications (user_id, teacher_id, type, title, message, link, is_read)
                                                         VALUES (:uid, :tid, 'reschedule_request', :title, :msg, :link, 0)");
                            $notifLegacy->execute([
                                ':uid' => (int)$rcp['id'],
                                ':tid' => (int)$my_teacher_id,
                                ':title' => $subject,
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
    }

    // Handle signature POST — per-schedule signing
        // PRG safeguard: prevent browser Back/Refresh from re-submitting reschedule request POST.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_reschedule_my') {
        $redirect = 'observation_plan.php?view=my_observation'
            . '&semester=' . urlencode($_GET['semester'] ?? '1st')
            . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
        if (!empty($success_message)) $_SESSION['success'] = $success_message;
        if (!empty($error_message)) $_SESSION['error'] = $error_message;
        header("Location: $redirect");
        exit();
    }
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
            $updated_count = 0;
            $signed_eval_ids = [];
            $has_upcoming = false;
            $seen_targets = [];
            foreach ($signed_items as $item) {
                $target_eval_ids = [];
                if ($item === 'upcoming') {
                    // Strict mode bridge: map upcoming/current signing to concrete
                    // evaluation rows on the teacher's scheduled date when available.
                    $schedDate = null;
                    $schedStmt = $db->prepare("SELECT evaluation_schedule FROM teachers WHERE id = :tid LIMIT 1");
                    $schedStmt->execute([':tid' => $my_teacher_id]);
                    $schedRaw = trim((string)$schedStmt->fetchColumn());
                    if ($schedRaw !== '' && strtotime($schedRaw) !== false) {
                        $schedDate = date('Y-m-d', strtotime($schedRaw));
                    }
                    if ($schedDate !== null) {
                        $semAlt = $ack_semester . ' Semester';
                        $evStmt = $db->prepare("SELECT id
                                                FROM evaluations
                                                WHERE teacher_id = :tid
                                                  AND academic_year = :ay
                                                  AND semester IN (:sem1, :sem2)
                                                  AND observation_date IS NOT NULL
                                                  AND DATE(observation_date) = :sdate");
                        $evStmt->execute([
                            ':tid' => $my_teacher_id,
                            ':ay' => $ack_academic_year,
                            ':sem1' => $ack_semester,
                            ':sem2' => $semAlt,
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
                $targetKey = ($eval_id === null) ? 'upcoming' : ('eval_' . (int)$eval_id);
                if (isset($seen_targets[$targetKey])) {
                    continue;
                }
                $seen_targets[$targetKey] = true;
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
                $existingAckId = (int)($check->fetchColumn() ?: 0);
                if ($existingAckId <= 0) {
                    $ins = $db->prepare("INSERT INTO observation_plan_acknowledgments (teacher_id, academic_year, semester, department, evaluation_id, acknowledged_at, signature) VALUES (:tid, :ay, :sem, :dept, :eid, NOW(), :sig)");
                    $ins->execute([':tid' => $my_teacher_id, ':ay' => $ack_academic_year, ':sem' => $ack_semester, ':dept' => $sign_dept, ':eid' => $eval_id, ':sig' => $sig_data]);
                    $signed_count++;
                    if ($eval_id !== null) {
                        $signed_eval_ids[] = $eval_id;
                    } else {
                        $has_upcoming = true;
                    }
                } else {
                    // Permanent fix for multi-select signing:
                    // keep existing row but refresh signature + timestamp so print/reports
                    // always have a per-row signature for the selected schedule.
                    $upd = $db->prepare("UPDATE observation_plan_acknowledgments
                                         SET signature = :sig,
                                             department = COALESCE(:dept, department),
                                             acknowledged_at = NOW()
                                         WHERE id = :id");
                    $upd->execute([
                        ':sig' => $sig_data,
                        ':dept' => $sign_dept,
                        ':id' => $existingAckId
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
                $total_processed = $signed_count + $updated_count;
                $success_message = "Successfully signed {$total_processed} observation schedule(s).";
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
    // PRG safeguard: prevent browser Back/Refresh from re-submitting signature POST.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_plan') {
        $redirect = 'observation_plan.php?view=my_observation'
            . '&semester=' . urlencode($_GET['semester'] ?? '1st')
            . '&academic_year=' . urlencode($_GET['academic_year'] ?? '');
        if (!empty($success_message)) $_SESSION['success'] = $success_message;
        if (!empty($error_message)) $_SESSION['error'] = $error_message;
        header("Location: $redirect");
        exit();
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
$is_observer_only = (($_SESSION['role'] ?? '') === 'president');
$is_coordinator = in_array($_SESSION['role'], ['chairperson', 'subject_coordinator', 'grade_level_coordinator']);
$is_observer_role = in_array($_SESSION['role'], ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator', 'president', 'vice_president']);

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
    // Hard guard: block manual URL edits to departments outside assigned programs.
    $raw_department = guardCoordinatorDepartment($requested_department, $available_filter_departments, $session_department);
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
                            OR LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('president','vice_president')
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

        // Build lookup: evaluation_id => acknowledgment row
        $my_signed_map = [];
        foreach ($my_acknowledgments_raw as $ack) {
            $key = $ack['evaluation_id'] === null ? 'upcoming' : (int)$ack['evaluation_id'];
            $my_signed_map[$key] = $ack;
        }
        $my_acknowledgment = null;
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
                         t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type,
                         t.scheduled_by, t.scheduled_department,
                         e.id as eval_id, e.evaluator_id as eval_evaluator_id, e.observation_date, e.observation_time, e.status as eval_status, e.faculty_signature,
                         e.subject_observed, e.observation_room as eval_room,
                         e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                         e.semester as eval_semester, e.department as eval_department
                  FROM teachers t
                  JOIN evaluations e ON e.teacher_id = t.id
                  LEFT JOIN users eu ON eu.id = e.evaluator_id
                  LEFT JOIN teacher_departments td ON td.teacher_id = t.id
                  WHERE (
                        (
                            e.department = :dept1
                        )
                        OR
                        (
                            (e.department IS NULL OR e.department = '')
                            AND
                            t.scheduled_department IS NOT NULL
                            AND t.scheduled_department <> ''
                            AND t.scheduled_department = :dept3
                        )
                        OR
                        (
                            (e.department IS NULL OR e.department = '')
                            AND
                            (t.scheduled_department IS NULL OR t.scheduled_department = '')
                            AND eu.department = :dept1
                        )
                  )
                  AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                  AND e.academic_year = :academic_year
                  AND e.semester = :semester
                  AND (e.status IS NULL OR e.status <> 'completed' OR e.evaluator_id = :current_user_id_completed)
                  ORDER BY t.name ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':dept1', $raw_department);
        $stmt->bindParam(':dept3', $raw_department);
        $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
        $stmt->bindParam(':current_user_id_completed', $_SESSION['user_id']);
        $stmt->bindParam(':academic_year', $academic_year);
        $stmt->bindParam(':semester', $semester);
    } else {
        $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                         t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                         t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type,
                         t.scheduled_by, t.scheduled_department,
                         e.id as eval_id, e.evaluator_id as eval_evaluator_id, e.observation_date, e.observation_time, e.status as eval_status, e.faculty_signature,
                         e.subject_observed, e.observation_room as eval_room,
                         e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                         e.semester as eval_semester, e.department as eval_department
                  FROM teachers t
                  JOIN evaluations e ON e.teacher_id = t.id
                  WHERE (t.user_id IS NULL OR t.user_id != :current_user_id)
                  AND e.academic_year = :academic_year
                  AND e.semester = :semester
                  AND (e.status IS NULL OR e.status <> 'completed' OR e.evaluator_id = :current_user_id_completed)
                  ORDER BY t.name ASC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
        $stmt->bindParam(':current_user_id_completed', $_SESSION['user_id']);
        $stmt->bindParam(':academic_year', $academic_year);
        $stmt->bindParam(':semester', $semester);
    }
} elseif ($is_coordinator) {
    // 1) Coordinators: show schedules owned by the selected department.
    // This allows department coordinators to see schedules set by that
    // department's dean/principal even when not explicitly assigned yet.
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type,
                     t.scheduled_by, t.scheduled_department,
                     e.id as eval_id, e.evaluator_id as eval_evaluator_id, e.observation_date, e.observation_time, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester, e.department as eval_department
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
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
              AND (tu.id IS NULL OR LOWER(REPLACE(TRIM(tu.role), ' ', '_')) NOT IN ('dean','principal','president','vice_president'))
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
// Dean/principal query — show evaluations only when the row belongs to this
// department via scheduled_department, or evaluator_id (they did the evaluation themselves).
// Do not include rows just because teacher's primary department matches.
    $query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                     t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                     t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type,
                     t.scheduled_by, t.scheduled_department,
                     e.id as eval_id, e.evaluator_id as eval_evaluator_id, e.observation_date, e.observation_time, e.status as eval_status, e.faculty_signature,
                     e.subject_observed, e.observation_room as eval_room,
                     e.subject_area as eval_subject_area, e.evaluation_focus as eval_focus,
                     e.semester as eval_semester, e.department as eval_department
              FROM teachers t
              JOIN evaluations e ON e.teacher_id = t.id
              WHERE (
                    e.department = :dept2
                    OR
                    (
                        (e.department IS NULL OR e.department = '')
                        AND t.scheduled_department IS NOT NULL
                        AND t.scheduled_department <> ''
                        AND t.scheduled_department = :dept2
                    )
                    OR
                    (
                        e.evaluator_id = :self_eval_id
                        AND e.department = :self_eval_dept
                    )
              )
              AND (t.user_id IS NULL OR t.user_id != :current_user_id)
              AND e.academic_year = :academic_year
              AND e.semester = :semester
              ORDER BY t.name ASC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':dept2', $raw_department);
    $stmt->bindParam(':self_eval_id', $_SESSION['user_id']);
    $stmt->bindParam(':self_eval_dept', $raw_department);
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
                               t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type,
                               t.scheduled_by, t.scheduled_department
                        FROM teachers t
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
                                    AND t.department = :filter_dept
                                )
                              )
                          AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                          AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                        ORDER BY t.name ASC";
        $sched_stmt = $db->prepare($sched_query);
        $sched_stmt->bindParam(':filter_dept', $raw_department);
        $sched_stmt->bindParam(':filter_dept3', $raw_department);
        $sched_stmt->bindParam(':filter_semester', $semester);
        $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    } else {
        $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                               t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                               t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type,
                               t.scheduled_by, t.scheduled_department
                        FROM teachers t
                        WHERE t.status = 'active'
                          AND t.evaluation_schedule IS NOT NULL
                          AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                          AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                        ORDER BY t.name ASC";
        $sched_stmt = $db->prepare($sched_query);
        $sched_stmt->bindParam(':filter_semester', $semester);
        $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    }
} elseif ($is_coordinator) {
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type,
                           t.scheduled_by, t.scheduled_department
                    FROM teachers t
                    LEFT JOIN users tu ON tu.id = t.user_id
                    WHERE t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND t.scheduled_department IS NOT NULL
                      AND t.scheduled_department <> ''
                      AND t.scheduled_department = :department_match_sched
                      AND (t.evaluation_semester = :filter_semester OR t.evaluation_semester IS NULL OR t.evaluation_semester = '')
                      AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                      AND (tu.id IS NULL OR LOWER(REPLACE(TRIM(tu.role), ' ', '_')) NOT IN ('dean','principal','president','vice_president'))
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
    $sched_stmt->bindParam(':department_match_sched', $raw_department);
    $sched_stmt->bindParam(':filter_semester', $semester);
    $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $sched_stmt->bindParam(':academic_year', $academic_year);
    $sched_stmt->bindParam(':semester', $semester);
} else {
    // Dean/principal: show scheduled teachers only when schedule is set for this
    // department explicitly via scheduled_department field.
    $sched_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                           t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                           t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type,
                           t.scheduled_by, t.scheduled_department
                    FROM teachers t
                    WHERE t.status = 'active'
                      AND t.evaluation_schedule IS NOT NULL
                      AND t.scheduled_department IS NOT NULL
                      AND t.scheduled_department <> ''
                      AND t.scheduled_department = :department_sched
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
    $sched_stmt->bindParam(':department_sched', $raw_department);
    $sched_stmt->bindParam(':filter_semester', $semester);
    $sched_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $sched_stmt->bindParam(':academic_year', $academic_year);
    $sched_stmt->bindParam(':semester', $semester);
}
$sched_stmt->execute();
$scheduled_teachers = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);

// Use teacher_schedules as the authoritative source for scheduled-only rows.
// teachers.evaluation_schedule stores only the latest schedule and has no
// academic year, so it can leak rows into the wrong Academic Year filter.
try {
    $scheduled_params = [
        ':ay' => (string)$academic_year,
        ':sem' => (string)$semester,
        ':current_user_id' => (int)($_SESSION['user_id'] ?? 0),
    ];
    $scheduled_where = [
        "t.status = 'active'",
        "ts.academic_year = :ay",
        "ts.semester = :sem",
        "ts.status <> 'cancelled'",
        "(t.user_id IS NULL OR t.user_id != :current_user_id)",
    ];
    $scheduled_joins = "";

    if ($is_leader) {
        if ($raw_department !== '') {
            $scheduled_where[] = "(
                (ts.scheduled_department IS NOT NULL AND ts.scheduled_department <> '' AND ts.scheduled_department = :schedule_dept)
                OR
                ((ts.scheduled_department IS NULL OR ts.scheduled_department = '') AND t.department = :teacher_dept)
            )";
            $scheduled_params[':schedule_dept'] = $raw_department;
            $scheduled_params[':teacher_dept'] = $raw_department;
        }
    } elseif ($is_coordinator) {
        $scheduled_joins = "LEFT JOIN users tu ON tu.id = t.user_id";
        $scheduled_where[] = "ts.scheduled_department = :schedule_dept";
        $scheduled_where[] = "(tu.id IS NULL OR LOWER(REPLACE(TRIM(tu.role), ' ', '_')) NOT IN ('dean','principal','president','vice_president'))";
        $scheduled_params[':schedule_dept'] = $raw_department;
    } else {
        $scheduled_where[] = "ts.scheduled_department = :schedule_dept";
        $scheduled_params[':schedule_dept'] = $raw_department;
    }

    $scheduled_query = "SELECT DISTINCT
                           t.id,
                           t.name,
                           t.department as teacher_department,
                           ts.schedule_start as evaluation_schedule,
                           ts.schedule_end as evaluation_schedule_end,
                           ts.room as evaluation_room,
                           ts.focus_json as evaluation_focus,
                           ts.subject_area as evaluation_subject_area,
                           ts.subject as evaluation_subject,
                           ts.semester as evaluation_semester,
                           ts.form_type as evaluation_form_type,
                           ts.scheduled_by,
                           ts.scheduled_department,
                           ts.evaluation_id as schedule_eval_id,
                           ts.status as schedule_status
                        FROM teacher_schedules ts
                        JOIN teachers t ON t.id = ts.teacher_id
                        $scheduled_joins
                        WHERE " . implode("\n                          AND ", $scheduled_where) . "
                        ORDER BY t.name ASC, ts.schedule_start ASC";
    $scheduled_stmt = $db->prepare($scheduled_query);
    $scheduled_stmt->execute($scheduled_params);
    $scheduled_teachers = $scheduled_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Keep the legacy result if the schedule table is unavailable.
}

// Build completion map for CURRENT evaluator per schedule slot.
// This is used for remarks logic:
// - ISO schedule: conducted when ISO is completed by this evaluator.
// - PEAC schedule: conducted when PEAC is completed by this evaluator.
// - BOTH schedule: conducted only when BOTH ISO and PEAC are completed by this evaluator.
$completed_forms_by_slot = [];
try {
    $slotEvalStmt = $db->prepare(
        "SELECT teacher_id, evaluation_form_type, observation_date, observation_time, observation_room, subject_area, subject_observed
         FROM evaluations
         WHERE evaluator_id = :eid
           AND academic_year = :ay
           AND semester = :sem
           AND status = 'completed'"
    );
    $slotEvalStmt->execute([
        ':eid' => (int)($_SESSION['user_id'] ?? 0),
        ':ay' => $academic_year,
        ':sem' => $semester,
    ]);
    while ($er = $slotEvalStmt->fetch(PDO::FETCH_ASSOC)) {
        $teacherId = (int)($er['teacher_id'] ?? 0);
        if ($teacherId <= 0 || empty($er['observation_date'])) continue;
        $slotKey = implode('|', [
            (string)$teacherId,
            date('Y-m-d', strtotime((string)$er['observation_date'])),
            strtolower(trim((string)($er['observation_room'] ?? ''))),
            strtolower(trim((string)($er['subject_area'] ?? ''))),
            strtolower(trim((string)normalizeSubjectDisplay((string)($er['subject_observed'] ?? '')))),
        ]);
        $ft = strtolower(trim((string)($er['evaluation_form_type'] ?? 'iso')));
        if (!in_array($ft, ['iso', 'peac'], true)) $ft = 'iso';
        if (!isset($completed_forms_by_slot[$slotKey])) $completed_forms_by_slot[$slotKey] = [];
        $completed_forms_by_slot[$slotKey][$ft] = true;
    }
} catch (Exception $e) {}

// Build combined teachers list and data maps
$eval_data = [];
$observer_map = [];
$schedule_data = [];
// Keep a map of evaluation datetimes per teacher so we can detect
// when a teacher-level schedule matches any existing evaluation.
$eval_dates_by_teacher = [];
// Keep completed-evaluation dates per teacher so schedule rows can be
// suppressed when the teacher already has a completed row on that date.
$completed_dates_by_teacher = [];

// For leaders: build a set of evaluation IDs they have opted into as observer
$leader_opted_evals = [];
if ($is_leader) {
    $opt_stmt = $db->prepare("SELECT eval_id FROM teacher_assignments WHERE evaluator_id = :eid AND eval_id IS NOT NULL");
    $opt_stmt->execute([':eid' => $_SESSION['user_id']]);
    while ($opt_row = $opt_stmt->fetch(PDO::FETCH_ASSOC)) {
        $oe = (int)($opt_row['eval_id'] ?? 0);
        if ($oe > 0) $leader_opted_evals[$oe] = true;
    }
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
$get_required_observers = function(int $teacher_id, int $eval_id, string $dept, int $scheduled_by_id, string $teacher_name, string $current_user_name, bool $exclude_current_user) use ($db): array {
    $required = [];
    $dept = trim((string)$dept);
    $coordinator_id = $scheduled_by_id;
    $hasCoordinatorObserver = false;

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
            $dean_stmt = $db->prepare("SELECT DISTINCT name FROM users WHERE department = :dept AND role IN ('dean','principal') AND status = 'active' ORDER BY name");
            $dean_stmt->execute([':dept' => $dept]);
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
                   AND department = :dept
                   AND status = 'active'
                   AND role IN ('chairperson','subject_coordinator','grade_level_coordinator')
                 LIMIT 1"
            );
            $coord_stmt->execute([':id' => $coordinator_id, ':dept' => $dept]);
            $coord_name = trim((string)$coord_stmt->fetchColumn());
            if ($coord_name !== '' && !in_array($coord_name, $required, true)) {
                $required[] = $coord_name;
                $hasCoordinatorObserver = true;
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
                   AND u.department = :dept
                   AND u.status = 'active'
                   AND u.role IN ('chairperson','subject_coordinator','grade_level_coordinator')
                 ORDER BY u.name"
            );
            $assigned_coord_stmt->execute([
                ':teacher_id' => $teacher_id,
                ':dept' => $dept
            ]);
            while ($cn = $assigned_coord_stmt->fetchColumn()) {
                $cn = trim((string)$cn);
                if ($cn !== '' && !in_array($cn, $required, true)) {
                    $required[] = $cn;
                    $hasCoordinatorObserver = true;
                }
            }
        } catch (Exception $e) {}
    }

    // Include President/VP only when they explicitly accepted this schedule row.
    if ($teacher_id > 0 && $eval_id > 0) {
        try {
            $pvp_stmt = $db->prepare(
                "SELECT DISTINCT u.name
                 FROM teacher_assignments ta
                 JOIN users u ON u.id = ta.evaluator_id
                 WHERE ta.teacher_id = :teacher_id
                   AND ta.eval_id = :eval_id
                   AND u.status = 'active'
                   AND LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('president','vice_president')
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

    return array_values(array_filter($required, function($n) use ($teacher_name, $current_user_name, $exclude_current_user) {
        $name = trim((string)$n);
        if ($name === '') return false;
        if (strcasecmp($name, trim((string)$teacher_name)) === 0) return false;
        if ($exclude_current_user && $current_user_name !== '' && strcasecmp($name, trim((string)$current_user_name)) === 0) return false;
        return true;
    }));
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
$exclude_current_user_from_observers = false;
$slot_end_by_eval_id = [];
$slot_end_by_teacher_start = [];

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

// Row-specific schedule end lookup (prevents losing end time when teacher-level
// latest schedule fields point to a different row).
try {
    $slotStmt = $db->prepare(
        "SELECT evaluation_id, teacher_id, schedule_start, schedule_end
         FROM teacher_schedules
         WHERE academic_year = :ay
           AND semester = :sem"
    );
    $slotStmt->execute([
        ':ay' => (string)$academic_year,
        ':sem' => (string)$semester
    ]);
    while ($sr = $slotStmt->fetch(PDO::FETCH_ASSOC)) {
        $eid = (int)($sr['evaluation_id'] ?? 0);
        $tid = (int)($sr['teacher_id'] ?? 0);
        $sstart = trim((string)($sr['schedule_start'] ?? ''));
        $send = trim((string)($sr['schedule_end'] ?? ''));
        if ($eid > 0 && $send !== '' && !isset($slot_end_by_eval_id[$eid])) {
            $slot_end_by_eval_id[$eid] = $send;
        }
        if ($tid > 0 && $sstart !== '' && $send !== '') {
            $key = $tid . '|' . date('Y-m-d H:i', strtotime($sstart));
            if (!isset($slot_end_by_teacher_start[$key])) {
                $slot_end_by_teacher_start[$key] = $send;
            }
        }
    }
} catch (Exception $e) {}

// Process teachers with evaluations
foreach ($eval_teachers as $t) {
    $tid = $t['id'];
    $eval_id = isset($t['eval_id']) && $t['eval_id'] ? (int)$t['eval_id'] : 0;
    $row_eval_dept = trim((string)($t['eval_department'] ?? ''));
    $row_sched_dept = trim((string)($t['scheduled_department'] ?? ''));
    $row_teacher_dept = trim((string)($t['teacher_department'] ?? ''));
    $row_owning_dept_filter = $row_eval_dept !== '' ? $row_eval_dept : ($row_sched_dept !== '' ? $row_sched_dept : $row_teacher_dept);
    // Final safety gate:
    // - Dean/Principal: enforce department ownership
    // - Coordinators: rely on explicit teacher assignment scope
    if (!$is_leader && !$is_coordinator && $raw_department !== '' && $row_owning_dept_filter !== '' && strcasecmp($row_owning_dept_filter, $raw_department) !== 0) {
        continue;
    }

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
    $is_eval_row = $eval_id > 0;
    // Main Observation Plan status should reflect schedule progress itself.
    // If a row's evaluation record is completed, mark as done/conducted.
    $is_done = ($eval_status === 'completed');
    $faculty_sig = $t['faculty_signature'] ?? '';

    // Store per-row eval data (used during rendering)
    $eval_data[$row_key] = ['date' => $obs_date, 'done' => $is_done, 'faculty_signature' => $faculty_sig, 'eval_id' => $eval_id ?: null, 'status' => $eval_status, 'cutoff' => ''];

    // Track all evaluation datetimes for this teacher to compare against
    // teacher-level schedules later (to avoid duplicate rows when identical).
    if (!empty($obs_date)) {
        $obs_time = trim((string)($t['observation_time'] ?? ''));
        $obs_dt_raw = $obs_time !== '' ? ($obs_date . ' ' . $obs_time) : $obs_date;
        $norm = date('Y-m-d H:i', strtotime($obs_dt_raw));
        if (!isset($eval_dates_by_teacher[$tid]) || !in_array($norm, $eval_dates_by_teacher[$tid], true)) {
            $eval_dates_by_teacher[$tid][] = $norm;
        }
        if ($eval_status === 'completed') {
            $done_date = date('Y-m-d', strtotime($obs_date));
            if (!isset($completed_dates_by_teacher[$tid]) || !in_array($done_date, $completed_dates_by_teacher[$tid], true)) {
                $completed_dates_by_teacher[$tid][] = $done_date;
            }
        }
    }

    // Any row tied to an evaluation record must use row-level evaluation data,
    // not the teacher's latest schedule fields (which can be overwritten by newer schedules).
    $focus_raw = $is_eval_row ? ($t['eval_focus'] ?? $t['evaluation_focus'] ?? '') : ($t['evaluation_focus'] ?? $t['eval_focus'] ?? '');

    $schedule_data[$row_key] = [
        'semester' => $is_eval_row ? ($t['eval_semester'] ?? $t['evaluation_semester'] ?? '') : ($t['evaluation_semester'] ?? $t['eval_semester'] ?? ''),
        'focus' => formatFocusDisplay($focus_raw, $focus_labels),
        'day_time' => '',
        'subject_area' => $is_eval_row ? ($t['eval_subject_area'] ?? $t['evaluation_subject_area'] ?? '') : ($t['evaluation_subject_area'] ?? $t['eval_subject_area'] ?? ''),
        'subject' => normalizeSubjectDisplay($is_eval_row ? ($t['subject_observed'] ?? $t['evaluation_subject'] ?? '') : ($t['evaluation_subject'] ?? $t['subject_observed'] ?? '')),
        'room' => $is_eval_row ? ($t['eval_room'] ?? $t['evaluation_room'] ?? '') : ($t['evaluation_room'] ?? $t['eval_room'] ?? ''),
    ];
    // Day & Time:
    // - evaluation rows: use observation_date + observation_time from that row
    // - schedule-only rows: use current teacher schedule fields
    $sched_dt = $t['evaluation_schedule'] ?? '';
    if ($is_eval_row && !empty($obs_date)) {
        $obs_time_for_cutoff = trim((string)($t['observation_time'] ?? ''));
        $sched_dt = $obs_time_for_cutoff !== '' ? ($obs_date . ' ' . $obs_time_for_cutoff) : $obs_date;
    }
    $sched_dt_end = $is_eval_row ? '' : ($t['evaluation_schedule_end'] ?? '');
    if ($eval_id > 0 && !empty($slot_end_by_eval_id[$eval_id])) {
        $sched_dt_end = $slot_end_by_eval_id[$eval_id];
    } elseif (!empty($sched_dt)) {
        $slot_key_lookup = ((int)$tid) . '|' . date('Y-m-d H:i', strtotime((string)$sched_dt));
        if (!empty($slot_end_by_teacher_start[$slot_key_lookup])) {
            $sched_dt_end = $slot_end_by_teacher_start[$slot_key_lookup];
        }
    }
    $eval_data[$row_key]['cutoff'] = trim((string)$sched_dt_end) !== '' ? $sched_dt_end : $sched_dt;
    if ($is_eval_row && !empty($obs_date)) {
        $schedule_data[$row_key]['day_time'] = date('l', strtotime($obs_date));
        $obs_time_fmt = trim((string)($t['observation_time'] ?? ''));
        // If observation_time is missing, fallback to current schedule start time.
        if (($obs_time_fmt === '' || $obs_time_fmt === '00:00:00' || $obs_time_fmt === '00:00') && !empty($sched_dt)) {
            $obs_time_fmt = date('H:i:s', strtotime($sched_dt));
        }
        if ($obs_time_fmt !== '' && $obs_time_fmt !== '00:00:00' && $obs_time_fmt !== '00:00') {
            $start_fmt = date('g:i A', strtotime($obs_time_fmt));
            $end_fmt = '';
            if (!empty($sched_dt_end)) {
                $end_fmt = date('g:i A', strtotime($sched_dt_end));
            }
            $schedule_data[$row_key]['day_time'] .= "\n" . $start_fmt . ($end_fmt !== '' ? (' - ' . $end_fmt) : '');
        }
    } elseif (!empty($sched_dt)) {
        $ts = strtotime($sched_dt);
        $day_str = date('l', $ts);
        $start_time = date('g:i A', $ts);
        if (!empty($sched_dt_end)) {
            $ts_end = strtotime($sched_dt_end);
            $end_time = date('g:i A', $ts_end);
            $schedule_data[$row_key]['day_time'] = $day_str . "\n" . $start_time . ' - ' . $end_time;
        } else {
            $schedule_data[$row_key]['day_time'] = $day_str . "\n" . $start_time;
        }
    } elseif (!empty($obs_date)) {
        $schedule_data[$row_key]['day_time'] = date('l', strtotime($obs_date));
        $obs_time_fmt = trim((string)($t['observation_time'] ?? ''));
        if ($obs_time_fmt !== '' && $obs_time_fmt !== '00:00:00' && $obs_time_fmt !== '00:00') {
            $start_fmt = date('g:i A', strtotime($obs_time_fmt));
            $end_fmt = '';
            if (!empty($sched_dt_end)) {
                $end_fmt = date('g:i A', strtotime($sched_dt_end));
            }
            $schedule_data[$row_key]['day_time'] .= "\n" . $start_fmt . ($end_fmt !== '' ? (' - ' . $end_fmt) : '');
        }
    }

    // Get observers — based on the department that owns this schedule/evaluation
    // Determine the "owning" department per row:
    // eval.department > scheduled_department > teacher primary department.
    $eval_dept_val = trim((string)($t['eval_department'] ?? ''));
    $sched_dept_val = $t['scheduled_department'] ?? '';
    $teacher_primary_dept = $t['teacher_department'] ?? '';
    // Evaluation rows are historical/per-slot records, so their own department
    // must win over the teacher-level latest schedule fields. Otherwise a newer
    // schedule in another department can make completed rows show wrong observers.
    $row_owning_dept = $eval_dept_val !== '' ? $eval_dept_val : (!empty($sched_dept_val) ? $sched_dept_val : $teacher_primary_dept);
    $owning_dept = $row_owning_dept;
    $is_secondary_dept = !empty($raw_department) && $teacher_primary_dept !== $raw_department;

    // For leaders: find which department this evaluation belongs to
    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    $row_eval_id = (int)($t['eval_id'] ?? 0);

    $dept_for_observers = $row_owning_dept;
    if ($dept_for_observers === '') $dept_for_observers = $raw_department;
    $scheduled_by_id = (int)($t['scheduled_by'] ?? 0);
    $teacher_name = $t['name'] ?? '';
    $current_user_name = trim((string)($_SESSION['name'] ?? ''));
    $all_observers = $get_required_observers($tid, $row_eval_id, $dept_for_observers, $scheduled_by_id, $teacher_name, $current_user_name, $exclude_current_user_from_observers);
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
        $ts = strtotime($sched_dt);
        $day_str = date('l', $ts);
        $start_time = date('g:i A', $ts);
        $sched_dt_end = $t['evaluation_schedule_end'] ?? '';
        $day_time_str = '';
        if (!empty($sched_dt_end)) {
            $ts_end = strtotime($sched_dt_end);
            $end_time = date('g:i A', $ts_end);
            $day_time_str = $day_str . "\n" . $start_time . ' - ' . $end_time;
        } else {
            $day_time_str = $day_str . "\n" . $start_time;
        }
        $schedule_data[$tid] = [
            'semester' => $t['evaluation_semester'] ?? '',
            'focus' => formatFocusDisplay($focus_raw, $focus_labels),
            'day_time' => $day_time_str,
            'subject_area' => $t['evaluation_subject_area'] ?? '',
            'subject' => normalizeSubjectDisplay($t['evaluation_subject'] ?? ''),
            'room' => $t['evaluation_room'] ?? '',
        ];
    }
}

// Process scheduled-only teachers (no evaluation yet)
foreach ($scheduled_teachers as $t) {
    $tid = $t['id'];
    $sched_dt = $t['evaluation_schedule'] ?? '';
    $sched_date = !empty($sched_dt) ? date('Y-m-d H:i', strtotime($sched_dt)) : '';
    $sched_date_only = !empty($sched_dt) ? date('Y-m-d', strtotime($sched_dt)) : '';

    // If this teacher already has a completed evaluation on the same date,
    // do not add a second "Scheduled" row for that same day.
    $completed_dates = $completed_dates_by_teacher[$tid] ?? [];
    if ($sched_date_only !== '' && in_array($sched_date_only, $completed_dates, true)) {
        continue;
    }

    // Compare scheduled datetime against any evaluation datetimes we collected
    $existing_dates = $eval_dates_by_teacher[$tid] ?? [];
    if (isset($seen_ids[$tid]) && $sched_date !== '' && in_array($sched_date, $existing_dates, true)) continue;

    $row_key = isset($seen_ids[$tid]) ? ('schedule_' . $tid) : $tid;
    $seen_ids[$tid] = true;
    $t['_row_key'] = $row_key;
    $teachers_list[] = $t;

    $eval_data[$row_key] = [
        'date' => $sched_date,
        'done' => false,
        'faculty_signature' => '',
        'eval_id' => !empty($t['schedule_eval_id']) ? (int)$t['schedule_eval_id'] : null,
        'status' => strtolower(trim((string)($t['schedule_status'] ?? 'scheduled'))),
        'cutoff' => trim((string)($t['evaluation_schedule_end'] ?? '')) !== '' ? $t['evaluation_schedule_end'] : $sched_dt,
    ];

    $focus_raw = $t['evaluation_focus'] ?? '';

    $schedule_data[$row_key] = [
        'semester' => $t['evaluation_semester'] ?? '',
        'focus' => formatFocusDisplay($focus_raw, $focus_labels),
        'day_time' => '',
        'subject_area' => $t['evaluation_subject_area'] ?? '',
        'subject' => normalizeSubjectDisplay($t['evaluation_subject'] ?? ''),
        'room' => $t['evaluation_room'] ?? '',
    ];
    if (!empty($sched_dt)) {
        $ts = strtotime($sched_dt);
        $day_str = date('l', $ts);
        $start_time = date('g:i A', $ts);
        $sched_dt_end = $t['evaluation_schedule_end'] ?? '';
        if (!empty($sched_dt_end)) {
            $ts_end = strtotime($sched_dt_end);
            $end_time = date('g:i A', $ts_end);
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
    // Resolve active evaluation row for this exact scheduled slot so
    // observer assignments are slot-specific (prevents stale observers).
    $active_eval_id_for_schedule = 0;
    try {
        if (!empty($sched_dt)) {
            $active_eval_id_for_schedule = (int)($t['schedule_eval_id'] ?? 0);
        }
        if (!empty($sched_dt) && $active_eval_id_for_schedule <= 0) {
            $activeEvalStmt = $db->prepare(
                "SELECT id
                 FROM evaluations
                 WHERE teacher_id = :tid
                   AND academic_year = :ay
                   AND semester = :sem
                   AND (:owning_dept = '' OR department = :owning_dept_match)
                   AND status <> 'completed'
                   AND DATE_FORMAT(CONCAT(observation_date, ' ', COALESCE(observation_time, '00:00:00')), '%Y-%m-%d %H:%i') = DATE_FORMAT(:sched_dt, '%Y-%m-%d %H:%i')
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $activeEvalStmt->execute([
                ':tid' => $tid,
                ':ay' => $academic_year,
                ':sem' => $semester,
                ':owning_dept' => (string)$owning_dept,
                ':owning_dept_match' => (string)$owning_dept,
                ':sched_dt' => $sched_dt
            ]);
            $active_eval_id_for_schedule = (int)($activeEvalStmt->fetchColumn() ?: 0);

            if ($active_eval_id_for_schedule <= 0) {
                $activeScheduleStmt = $db->prepare(
                    "SELECT evaluation_id
                     FROM teacher_schedules
                     WHERE teacher_id = :tid
                       AND academic_year = :ay
                       AND semester = :sem
                       AND schedule_start = :sched_dt
                       AND (:owning_dept = '' OR scheduled_department = :owning_dept_match)
                       AND evaluation_id IS NOT NULL
                     ORDER BY id DESC
                     LIMIT 1"
                );
                $activeScheduleStmt->execute([
                    ':tid' => $tid,
                    ':ay' => $academic_year,
                    ':sem' => $semester,
                    ':sched_dt' => $sched_dt,
                    ':owning_dept' => (string)$owning_dept,
                    ':owning_dept_match' => (string)$owning_dept
                ]);
                $active_eval_id_for_schedule = (int)($activeScheduleStmt->fetchColumn() ?: 0);
            }

            if ($active_eval_id_for_schedule <= 0) {
                $activeEvalLooseStmt = $db->prepare(
                    "SELECT id
                     FROM evaluations
                     WHERE teacher_id = :tid
                       AND academic_year = :ay
                       AND semester = :sem
                       AND (:owning_dept = '' OR department = :owning_dept_match)
                       AND status <> 'completed'
                       AND DATE_FORMAT(CONCAT(observation_date, ' ', COALESCE(observation_time, '00:00:00')), '%Y-%m-%d %H:%i') = DATE_FORMAT(:sched_dt, '%Y-%m-%d %H:%i')
                     ORDER BY id DESC
                     LIMIT 1"
                );
                $activeEvalLooseStmt->execute([
                    ':tid' => $tid,
                    ':ay' => $academic_year,
                    ':sem' => $semester,
                    ':owning_dept' => (string)$owning_dept,
                    ':owning_dept_match' => (string)$owning_dept,
                    ':sched_dt' => $sched_dt
                ]);
                $active_eval_id_for_schedule = (int)($activeEvalLooseStmt->fetchColumn() ?: 0);
            }
        }
    } catch (Exception $e) {}

    if ($active_eval_id_for_schedule > 0) {
        $eval_data[$row_key]['eval_id'] = $active_eval_id_for_schedule;
    }

    // Observers are department dean/principal + coordinator who set the schedule.
    $scheduled_by_id = (int)($t['scheduled_by'] ?? 0);
    $teacher_name = $t['name'] ?? '';
    $current_user_name = trim((string)($_SESSION['name'] ?? ''));
    $dept_for_observers = !empty($owning_dept) ? $owning_dept : $raw_department;
    $all_observers = $get_required_observers($tid, $active_eval_id_for_schedule, $dept_for_observers, $scheduled_by_id, $teacher_name, $current_user_name, $exclude_current_user_from_observers);
    $observer_map[$row_key] = $all_observers;
}

// No fallback by evaluator department: ownership must follow scheduled_department
// (or legacy teacher primary department when scheduled_department is empty).

// Consolidate duplicate rendered rows in main Observation Plan table.
// Duplicate key is based on teacher + slot fields shown in the table.
if (!empty($teachers_list)) {
    $normalize_subject_slot = static function(string $subject): string {
        $s = strtolower(trim($subject));
        if ($s === '') return '';
        // Remove trailing time text appended to subject (e.g. "GEC 9 8:00 PM").
        $s = preg_replace('/\s+\d{1,2}:\d{2}\s*(am|pm)\s*$/i', '', $s);
        return trim((string)$s);
    };
    $merged_rows = [];
    $merged_index = [];
    foreach ($teachers_list as $t) {
        $tid = (int)($t['id'] ?? 0);
        $row_key = $t['_row_key'] ?? $tid;
        $ed = $eval_data[$row_key] ?? [];
        $sd = $schedule_data[$row_key] ?? [];
        // Keep rows from different owning departments separate so observer
        // lists are not merged across primary/secondary department contexts.
        $row_owning_dept_key = trim((string)($t['eval_department'] ?? ''));
        if ($row_owning_dept_key === '') {
            $row_owning_dept_key = trim((string)($t['scheduled_department'] ?? ''));
        }
        if ($row_owning_dept_key === '') {
            $row_owning_dept_key = trim((string)($t['teacher_department'] ?? ''));
        }
        $key = implode('|', [
            (string)$tid,
            strtolower($row_owning_dept_key),
            trim((string)($ed['date'] ?? '')),
            trim((string)($sd['subject_area'] ?? '')),
            $normalize_subject_slot((string)($sd['subject'] ?? '')),
            trim((string)($sd['room'] ?? '')),
        ]);

        if (!isset($merged_index[$key])) {
            $merged_index[$key] = count($merged_rows);
            $merged_rows[] = $t;
            continue;
        }

        // Merge duplicate into existing kept row.
        $keep_idx = $merged_index[$key];
        $keep = $merged_rows[$keep_idx];
        $keep_row_key = $keep['_row_key'] ?? ($keep['id'] ?? $tid);
        $new_row_key = $row_key;

        // Merge observers (unique names).
        $keep_obs = $observer_map[$keep_row_key] ?? [];
        $new_obs = $observer_map[$new_row_key] ?? [];
        $merged_obs = array_values(array_unique(array_merge($keep_obs, $new_obs)));
        $observer_map[$keep_row_key] = $merged_obs;

        // Prefer row data with eval_id and/or signature if existing kept row has none.
        $keep_eval = $eval_data[$keep_row_key] ?? [];
        $new_eval = $eval_data[$new_row_key] ?? [];
        $keep_eval_id = (int)($keep_eval['eval_id'] ?? 0);
        $new_eval_id = (int)($new_eval['eval_id'] ?? 0);
        $keep_has_sig = trim((string)($keep_eval['faculty_signature'] ?? '')) !== '';
        $new_has_sig = trim((string)($new_eval['faculty_signature'] ?? '')) !== '';
        if (($new_eval_id > 0 && $keep_eval_id <= 0) || (!$keep_has_sig && $new_has_sig)) {
            $merged_rows[$keep_idx] = $t;
            // If representative row changes, carry merged observers to the new key
            // so accepted President/VP observers are not lost.
            $observer_map[$new_row_key] = $merged_obs;
        }
    }
    $teachers_list = array_values($merged_rows);
}

// Final ownership gate for dean/principal views:
// render only rows owned by the selected/current department.
// Ownership precedence: eval.department > scheduled_department > teacher primary department.
if (!$is_leader && !$is_coordinator && !empty($raw_department)) {
    $teachers_list = array_values(array_filter($teachers_list, function($t) use ($raw_department) {
        $eval_dept = trim((string)($t['eval_department'] ?? ''));
        $sched_dept = trim((string)($t['scheduled_department'] ?? ''));
        $teacher_dept = trim((string)($t['teacher_department'] ?? ''));
        $owning_dept = $eval_dept !== '' ? $eval_dept : ($sched_dept !== '' ? $sched_dept : $teacher_dept);
        if ($owning_dept === '') return false;
        return strcasecmp($owning_dept, $raw_department) === 0;
    }));
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
        $is_done = !empty($eval_data[$row_key]['done']);
        $row_status = strtolower(trim((string)($eval_data[$row_key]['status'] ?? '')));
        $has_sched = !empty($t['evaluation_schedule']);

        $is_overdue_not_evaluated = false;
        if (!$is_done) {
            $row_sched_end_raw = trim((string)($eval_data[$row_key]['cutoff'] ?? ''));
            $row_sched_start_raw = trim((string)($t['evaluation_schedule'] ?? ''));
            $cutoff_raw = $row_sched_end_raw !== '' ? $row_sched_end_raw : $row_sched_start_raw;
            if ($cutoff_raw !== '') {
                try {
                    $tz = new DateTimeZone('Asia/Manila');
                    $cutoff_at = new DateTime($cutoff_raw, $tz);
                    $now_at = new DateTime('now', $tz);
                    if ($now_at > $cutoff_at) {
                        $is_overdue_not_evaluated = true;
                    }
                } catch (Exception $e) {}
            }
        }

        if ($filter_status === 'done') return $is_done || $row_status === 'completed';
        if ($filter_status === 'rescheduled') return ($row_status === 'rescheduled');
        if ($filter_status === 'observer_unbalanced') return ($row_status === 'observer_unbalanced');
        if ($filter_status === 'did_not_evaluate') return $is_overdue_not_evaluated;
        if ($filter_status === 'scheduled') return $has_sched && !$is_done && !in_array($row_status, ['rescheduled', 'observer_unbalanced'], true) && !$is_overdue_not_evaluated;
        return true;
    });
    $teachers_list = array_values($teachers_list);
}

$dean_role_display = ucfirst(str_replace('_', ' ', $_SESSION['role']));

// Pending reschedule requests (shared across recipients) for Observation Plan UI.
$pending_reschedule_requests = [];
$pending_reschedule_by_teacher = [];
$pending_reschedule_by_slot = [];
try {
    $pendingStmt = $db->prepare("SELECT id, teacher_id, link, created_at, request_eval_id, request_schedule_key
                                 FROM notifications
                                 WHERE type = 'reschedule_request'
                                   AND is_read = 0
                                 ORDER BY id DESC");
    $pendingStmt->execute();
    while ($pr = $pendingStmt->fetch(PDO::FETCH_ASSOC)) {
        $ptid = (int)($pr['teacher_id'] ?? 0);
        if ($ptid <= 0) continue;
        $peval = (int)($pr['request_eval_id'] ?? 0);
        $pslot = strtolower(trim((string)($pr['request_schedule_key'] ?? '')));
        $plink = trim((string)($pr['link'] ?? ''));
        if ($peval <= 0 && $plink !== '') {
            $parts = parse_url($plink);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $qs);
                $peval = (int)($qs['eval_id'] ?? 0);
            }
        }
        $pkey = $ptid . '|' . $peval;
        if (!isset($pending_reschedule_requests[$pkey])) {
            $pending_reschedule_requests[$pkey] = [
                'notification_id' => (int)($pr['id'] ?? 0),
                'created_at' => $pr['created_at'] ?? null,
                'request_schedule_key' => $pslot
            ];
        }
        if ($pslot !== '') {
            $slotKey = $ptid . '|' . $pslot;
            if (!isset($pending_reschedule_by_slot[$slotKey])) {
                $pending_reschedule_by_slot[$slotKey] = [
                    'notification_id' => (int)($pr['id'] ?? 0),
                    'created_at' => $pr['created_at'] ?? null
                ];
            }
        }
        if (!isset($pending_reschedule_by_teacher[$ptid])) {
            $pending_reschedule_by_teacher[$ptid] = [
                'notification_id' => (int)($pr['id'] ?? 0),
                'eval_id' => $peval,
                'created_at' => $pr['created_at'] ?? null
            ];
        }
    }
} catch (Exception $e) {
    // Backward compatibility when request_eval_id column does not exist yet.
    try {
        $pendingStmtLegacy = $db->prepare("SELECT id, teacher_id, link, created_at
                                           FROM notifications
                                           WHERE type = 'reschedule_request'
                                             AND is_read = 0
                                           ORDER BY id DESC");
        $pendingStmtLegacy->execute();
        while ($pr = $pendingStmtLegacy->fetch(PDO::FETCH_ASSOC)) {
            $ptid = (int)($pr['teacher_id'] ?? 0);
            if ($ptid <= 0) continue;
            $peval = 0;
            $plink = trim((string)($pr['link'] ?? ''));
            if ($plink !== '') {
                $parts = parse_url($plink);
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $qs);
                    $peval = (int)($qs['eval_id'] ?? 0);
                }
            }
            if ($peval <= 0) continue;
            $pkey = $ptid . '|' . $peval;
            if (!isset($pending_reschedule_requests[$pkey])) {
                $pending_reschedule_requests[$pkey] = [
                    'notification_id' => (int)($pr['id'] ?? 0),
                    'created_at' => $pr['created_at'] ?? null
                ];
            }
        }
    } catch (Exception $e2) {}
}

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
                        t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type, t.scheduled_department
                 FROM teachers t
                 WHERE t.status = 'active'
                   AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                 ORDER BY t.department, t.name ASC";
    $st_stmt = $db->prepare($st_query);
    $st_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
} elseif ($is_coordinator) {
    $st_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                        t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                        t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type, t.scheduled_department
                 FROM teachers t
                 JOIN teacher_assignments ta ON ta.teacher_id = t.id AND ta.evaluator_id = :evaluator_id
                 LEFT JOIN users tu ON tu.id = t.user_id
                 WHERE t.status = 'active'
                   AND (t.user_id IS NULL OR t.user_id != :current_user_id)
                   AND (tu.id IS NULL OR LOWER(REPLACE(TRIM(tu.role), ' ', '_')) NOT IN ('dean','principal','president','vice_president'))
                 ORDER BY t.name ASC";
    $st_stmt = $db->prepare($st_query);
    $st_stmt->bindParam(':evaluator_id', $_SESSION['user_id']);
    $st_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
} else {
    $st_query = "SELECT DISTINCT t.id, t.name, t.department as teacher_department,
                        t.evaluation_schedule, t.evaluation_schedule_end, t.evaluation_room, t.evaluation_focus,
                        t.evaluation_subject_area, t.evaluation_subject, t.evaluation_semester, t.evaluation_form_type, t.scheduled_department
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
    // Keep modal department scope aligned with page/filter scope so
    // reschedule flows (including post-accept redirect) never render
    // an empty department dropdown when session department is blank.
    if (!empty($available_filter_departments)) {
        $schedule_available_departments = array_values(array_unique(array_filter($available_filter_departments)));
    } else {
        $self_dept = trim((string)($_SESSION['department'] ?? ''));
        if ($self_dept === '') {
            $self_dept = trim((string)$raw_department);
        }
        if ($self_dept !== '') {
            $schedule_available_departments[] = $self_dept;
        }
    }
}

// Final hard-sanitize: keep only valid department codes and guarantee at least one fallback.
$schedule_available_departments = array_values(array_unique(array_filter(
    array_map(function($d) { return trim((string)$d); }, $schedule_available_departments),
    function($d) use ($all_departments) { return $d !== '' && in_array($d, $all_departments, true); }
)));
if (empty($schedule_available_departments)) {
    $fallback_dept = trim((string)$raw_department);
    if ($fallback_dept === '') $fallback_dept = trim((string)($_SESSION['department'] ?? ''));
    if ($fallback_dept !== '' && in_array($fallback_dept, $all_departments, true)) {
        $schedule_available_departments[] = $fallback_dept;
    }
}

// Unscheduled teachers are only shown in the modal dropdown, not in the table

// Load acknowledgment data for current semester/year
$ack_eval_map = [];
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
        $sem_alt = $semester . ' Semester';
        $ack_query = "SELECT teacher_id, department, acknowledged_at, signature, evaluation_id, id
                      FROM observation_plan_acknowledgments
                      WHERE academic_year = ?
                        AND semester IN (?, ?)
                        AND teacher_id IN ($ph)
                      ORDER BY acknowledged_at DESC, id DESC";
        $ack_stmt = $db->prepare($ack_query);
        $ack_stmt->execute(array_merge([$academic_year, $semester, $sem_alt], $ack_teacher_ids));

        while ($ack_row = $ack_stmt->fetch(PDO::FETCH_ASSOC)) {
            $tid = (int)$ack_row['teacher_id'];
            $aeid = (int)($ack_row['evaluation_id'] ?? 0);
            if ($aeid > 0) {
                if (!isset($ack_eval_map[$aeid])) {
                    $ack_eval_map[$aeid] = $ack_row;
                } else {
                    $curEvalHasSig = !empty($ack_eval_map[$aeid]['signature']);
                    $newEvalHasSig = !empty($ack_row['signature']);
                    if (!$curEvalHasSig && $newEvalHasSig) {
                        $ack_eval_map[$aeid] = $ack_row;
                    }
                }
            }
            // Keep schedule-only ("upcoming") signature separately for rows
            // that do not yet have a concrete evaluation_id.
            if (($ack_row['evaluation_id'] === null || $ack_row['evaluation_id'] === '') && !isset($ack_upcoming_map[$tid])) {
                $ack_upcoming_map[$tid] = $ack_row;
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
        html {
            font-size: clamp(14px, 0.95vw, 16px);
        }
        .dashboard-topbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            align-items: center;
        }
        .dashboard-topbar h2 {
            font-size: clamp(1.45rem, 2.3vw, 2.05rem);
            line-height: 1.18;
            margin: 0;
            word-break: break-word;
        }
        .dashboard-topbar .ms-auto {
            margin-left: auto !important;
        }
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
        .prepared-by .prepared-by-inner {
            width: 220px;
            text-align: left;
        }
        .prepared-by .prepared-by-inner p:first-child {
            text-align: left;
            margin-bottom: 2px;
        }
        .prepared-by .prepared-by-signature-stack {
            display: inline-block;
            text-align: center;
        }
        .prepared-by .sig-img {
            display: block;
            max-height: 50px;
            max-width: 200px;
            margin: 0 auto -8px;
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

        /* Global modal scrolling: apply to every modal on this page */
        .modal .modal-dialog {
            margin-top: 0.6rem;
            margin-bottom: 0.6rem;
        }
        .modal .modal-content {
            max-height: calc(100dvh - 1.2rem);
            max-height: calc(100vh - 1.2rem);
            overflow: hidden;
        }
        .modal .modal-body {
            overflow-y: auto;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
        }

        /* Fast, zoom-safe modal sizing (applies immediately at any browser zoom) */
        #scheduleModal .modal-dialog {
            width: min(680px, calc(100vw - 1.25rem));
            max-width: min(680px, calc(100vw - 1.25rem));
            margin: 0.6rem auto;
        }
        #scheduleModal .modal-content {
            max-height: min(88dvh, 820px);
            max-height: min(88vh, 820px);
            height: auto;
        }
        /* Keep footer visible: only body scrolls inside dialog */
        #scheduleModal .modal-dialog.modal-dialog-scrollable .modal-content {
            max-height: calc(100dvh - 1.6rem);
            max-height: calc(100vh - 1.6rem);
        }
        #scheduleModal .modal-dialog.modal-dialog-scrollable .modal-body {
            overflow-y: auto;
            max-height: calc(100dvh - 11.5rem);
            max-height: calc(100vh - 11.5rem);
        }
        #scheduleModal .modal-body {
            position: relative;
            overflow-y: auto;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            padding: 0.85rem 1rem 1rem;
        }
        #scheduleModal .modal-scroll-controls {
            position: sticky;
            bottom: 0.5rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.4rem;
            pointer-events: none;
            z-index: 4;
            margin-top: 0.35rem;
        }
        #scheduleModal .modal-scroll-btn {
            pointer-events: auto;
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 999px;
            background: rgba(13, 110, 253, 0.95);
            color: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.22);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        #scheduleModal .modal-scroll-btn[hidden] {
            display: none !important;
        }
        #scheduleModal .modal-scroll-btn:hover {
            background: #0b5ed7;
        }
        #scheduleModal .modal-header {
            padding: 0.75rem 1rem;
        }
        #scheduleModal .modal-footer {
            padding: 0.75rem 1rem;
            position: sticky;
            bottom: 0;
            z-index: 3;
            background: #fff;
            border-top: 1px solid #dee2e6;
        }
        #scheduleModal .form-control,
        #scheduleModal .form-select {
            min-height: 42px;
        }
        #scheduleModal .row.g-2 > [class*="col-"] {
            min-width: 0;
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
        #unableObserveModal .modal-dialog {
            max-width: 560px;
            width: calc(100% - 1.25rem);
            margin: 0.75rem auto;
        }
        #unableObserveCommentsWrap {
            display: block;
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
                background: #E3A15A !important;
                color: #000 !important;
                border: 1.5px solid #000 !important;
                -webkit-print-color-adjust: exact;
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
                width: calc(100vw - 0.75rem);
                margin: 0.5rem auto;
            }
            #scheduleModal .modal-content {
                max-height: calc(100dvh - 0.75rem);
            }
            #scheduleModal .modal-body {
                padding: 0.8rem 0.85rem 0.95rem;
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
            /* Stack paired fields like Start/End and Subject/Room on mobile */
            #scheduleModal .row.g-2 > [class*="col-6"],
            #scheduleModal .row.g-2 > [class*="col-md-6"] {
                width: 100%;
                flex: 0 0 100%;
                max-width: 100%;
            }
            #scheduleModal .form-check-label {
                font-size: 0.95rem;
            }
        }
        @media (max-height: 820px) {
            #scheduleModal .modal-dialog {
                margin: 0.35rem auto;
            }
            #scheduleModal .modal-content {
                max-height: calc(100vh - 0.7rem);
                max-height: calc(100dvh - 0.7rem);
            }
        }
        @media (max-width: 992px) {
            .dashboard-topbar h2 {
                width: 100%;
            }
            .dashboard-topbar .ms-auto {
                width: 100%;
                display: flex;
                justify-content: flex-end;
            }
            .observation-plan-container .card .row.g-2 > [class*="col-md-"] {
                flex: 0 0 50%;
                max-width: 50%;
            }
            .observation-plan-container .card .row.g-2 > [class*="col-md-"] button {
                width: 100%;
            }
            #scheduleModal .modal-body {
                padding: 0.95rem;
            }
        }
        @media (max-width: 1200px) {
            .observation-plan-container .card .row.g-2 > [class*="col-md-"] {
                flex: 0 0 33.3333%;
                max-width: 33.3333%;
            }
        }
        @media (max-width: 640px) {
            .dashboard-topbar .ms-auto {
                justify-content: stretch;
            }
            .dashboard-topbar .ms-auto .no-print,
            .dashboard-topbar .ms-auto .dropdown {
                width: 100%;
            }
            .dashboard-topbar .ms-auto .btn {
                width: 100%;
            }
            .observation-plan-container .card .row.g-2 > [class*="col-md-"] {
                flex: 0 0 100%;
                max-width: 100%;
            }
            .plan-table {
                min-width: 820px;
            }
            #scheduleModal .modal-dialog {
                width: calc(100vw - 0.5rem);
                max-width: calc(100vw - 0.5rem);
                margin: 0.25rem auto;
            }
            #scheduleModal .modal-content {
                max-height: calc(100dvh - 0.5rem);
            }
            #scheduleModal .modal-header,
            #scheduleModal .modal-footer {
                padding: 0.65rem 0.75rem;
            }
            #scheduleModal .modal-body {
                padding: 0.72rem 0.75rem 0.9rem;
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
                        <?php if ($view_mode !== 'my_observation'): ?>
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
                                <option value="observer_unbalanced" <?php echo $filter_status === 'observer_unbalanced' ? 'selected' : ''; ?>>Observer Imbalance</option>
                                <option value="done" <?php echo $filter_status === 'done' ? 'selected' : ''; ?>>Conducted</option>
                                <option value="did_not_evaluate" <?php echo $filter_status === 'did_not_evaluate' ? 'selected' : ''; ?>>Did Not Evaluate</option>
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
            <?php unset($_SESSION['schedule_debug_line']); ?>
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

                // Group evaluations by exact datetime and by schedule slot key
                // so upcoming row does not duplicate an existing evaluation row.
                $my_eval_datetime_keys = [];
                $my_eval_slot_keys = [];
                foreach ($my_evaluations as $ev) {
                    if (!empty($ev['observation_date'])) {
                        $obs_dt = trim((string)($ev['observation_date'] ?? ''));
                        $obs_tm = trim((string)($ev['observation_time'] ?? ''));
                        if ($obs_tm !== '') {
                            $my_eval_datetime_keys[date('Y-m-d H:i', strtotime($obs_dt . ' ' . $obs_tm))] = true;
                        } else {
                            $my_eval_datetime_keys[date('Y-m-d 00:00', strtotime($obs_dt))] = true;
                        }

                        $ev_slot_key = implode('|', [
                            date('Y-m-d', strtotime($obs_dt)),
                            strtolower(trim((string)($ev['subject_area'] ?? ''))),
                            strtolower(trim((string)normalizeSubjectDisplay((string)($ev['subject_observed'] ?? '')))),
                            strtolower(trim((string)($ev['observation_room'] ?? ''))),
                        ]);
                        $my_eval_slot_keys[$ev_slot_key] = true;
                    }
                }
                $my_sched_datetime_key = $my_has_matching ? date('Y-m-d H:i', strtotime($my_teacher_data['evaluation_schedule'])) : null;
                $my_sched_slot_key = null;
                $my_has_loose_slot_overlap = false;
                if ($my_has_matching && !empty($my_teacher_data['evaluation_schedule'])) {
                    $my_sched_slot_key = implode('|', [
                        date('Y-m-d', strtotime((string)$my_teacher_data['evaluation_schedule'])),
                        strtolower(trim((string)($my_teacher_data['evaluation_subject_area'] ?? ''))),
                        strtolower(trim((string)normalizeSubjectDisplay((string)($my_teacher_data['evaluation_subject'] ?? '')))),
                        strtolower(trim((string)($my_teacher_data['evaluation_room'] ?? ''))),
                    ]);

                    // Fallback overlap rule: treat as same slot when date+room(+subject area)
                    // match, even if subject text/time formatting differs.
                    $sched_date_only = date('Y-m-d', strtotime((string)$my_teacher_data['evaluation_schedule']));
                    $sched_room_norm = strtolower(trim((string)($my_teacher_data['evaluation_room'] ?? '')));
                    $sched_area_norm = strtolower(trim((string)($my_teacher_data['evaluation_subject_area'] ?? '')));
                    foreach ($my_evaluations as $ev2) {
                        $ev_date_only = trim((string)($ev2['observation_date'] ?? ''));
                        if ($ev_date_only === '') continue;
                        $ev_date_only = date('Y-m-d', strtotime($ev_date_only));
                        if ($ev_date_only !== $sched_date_only) continue;

                        $ev_room_norm = strtolower(trim((string)($ev2['observation_room'] ?? '')));
                        $ev_area_norm = strtolower(trim((string)($ev2['subject_area'] ?? '')));
                        $room_match = ($sched_room_norm !== '' && $ev_room_norm !== '' && $sched_room_norm === $ev_room_norm);
                        $area_match = ($sched_area_norm !== '' && $ev_area_norm !== '' && $sched_area_norm === $ev_area_norm);
                        if ($room_match || $area_match) {
                            $my_has_loose_slot_overlap = true;
                            break;
                        }
                    }
                }

                // Show upcoming schedule row unless an evaluation already exists
                // at the same datetime OR same schedule slot fields.
                $has_matching_eval_slot = (
                    ($my_sched_datetime_key !== null && isset($my_eval_datetime_keys[$my_sched_datetime_key])) ||
                    ($my_sched_slot_key !== null && isset($my_eval_slot_keys[$my_sched_slot_key])) ||
                    $my_has_loose_slot_overlap
                );
                $my_show_upcoming = $my_has_matching && !$has_matching_eval_slot;

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

                // Consolidate duplicate evaluator rows into one row per schedule slot.
                // Slot key: date+time+subject_area+subject+room.
                $my_slot_groups = [];
                foreach ($my_evaluations as $ev) {
                    $slot_date = trim((string)($ev['observation_date'] ?? ''));
                    $slot_time = trim((string)($ev['observation_time'] ?? ''));
                    $slot_dt_key = $slot_date !== ''
                        ? date('Y-m-d H:i', strtotime($slot_date . ' ' . ($slot_time !== '' ? $slot_time : '00:00')))
                        : ('ev_' . (int)($ev['id'] ?? 0));
                    $slot_key = implode('|', [
                        $slot_dt_key,
                        trim((string)($ev['subject_area'] ?? '')),
                        trim((string)normalizeSubjectDisplay((string)($ev['subject_observed'] ?? ''))),
                        trim((string)($ev['observation_room'] ?? '')),
                    ]);
                    if (!isset($my_slot_groups[$slot_key])) {
                        $my_slot_groups[$slot_key] = [
                            'rep' => $ev,
                            'ids' => [],
                            'times' => [],
                            'evaluator_names' => [],
                            'completed_by' => [],
                            'has_rescheduled' => false,
                            'has_scheduled' => false,
                        ];
                    }
                    // Use newest evaluation id as representative for signing/checklist actions.
                    if ((int)($ev['id'] ?? 0) > (int)($my_slot_groups[$slot_key]['rep']['id'] ?? 0)) {
                        $my_slot_groups[$slot_key]['rep'] = $ev;
                    }
                    $my_slot_groups[$slot_key]['ids'][] = (int)($ev['id'] ?? 0);
                    $ev_time_slot = trim((string)($ev['observation_time'] ?? ''));
                    if ($ev_time_slot !== '' && $ev_time_slot !== '00:00:00' && $ev_time_slot !== '00:00') {
                        $my_slot_groups[$slot_key]['times'][$ev_time_slot] = true;
                    }
                    $ev_name_slot_all = trim((string)($ev['evaluator_name'] ?? ''));
                    if ($ev_name_slot_all !== '') {
                        $my_slot_groups[$slot_key]['evaluator_names'][$ev_name_slot_all] = true;
                    }
                    $ev_status_slot = strtolower(trim((string)($ev['status'] ?? '')));
                    if ($ev_status_slot === 'completed') {
                        $ev_name_slot = trim((string)($ev['evaluator_name'] ?? ''));
                        if ($ev_name_slot !== '') $my_slot_groups[$slot_key]['completed_by'][$ev_name_slot] = true;
                    } elseif ($ev_status_slot === 'rescheduled') {
                        $my_slot_groups[$slot_key]['has_rescheduled'] = true;
                    } else {
                        $my_slot_groups[$slot_key]['has_scheduled'] = true;
                    }
                }
                $my_unique_evaluations = [];
                foreach ($my_slot_groups as $g) $my_unique_evaluations[] = $g['rep'];

                // Count unsigned items
                $unsigned_count = 0;
                if ($my_show_upcoming && !isset($my_signed_map['upcoming'])) $unsigned_count++;
                foreach ($my_unique_evaluations as $ev) {
                    if (!isset($my_signed_map[(int)$ev['id']])) $unsigned_count++;
                }
                $all_signed = ($unsigned_count === 0) && ($my_show_upcoming || count($my_unique_evaluations) > 0);
                ?>

                <?php if ($my_show_upcoming || count($my_unique_evaluations) > 0): ?>

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
                                $focus_display = formatFocusDisplay($focus_raw, $focus_labels_my);
                                $ts = strtotime($my_teacher_data['evaluation_schedule']);
                                $my_day_time = date('l', $ts) . '<br>' . date('g:i A', $ts);
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
                                <td class="myobs-focus-cell" style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars($focus_display); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo date('M d, Y', $ts); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo $my_day_time; ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($my_teacher_data['evaluation_subject_area'] ?? ''); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($my_teacher_data['evaluation_subject'] ?? ''); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($my_teacher_data['evaluation_room'] ?? ''); ?></td>
                                <td class="myobs-observers-cell" style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $my_observer_names)); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <?php if (!empty($upcoming_signature)): ?>
                                        <img src="<?php echo htmlspecialchars($upcoming_signature); ?>" alt="Teacher Signature" style="max-height:30px;max-width:90px;">
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <span class="badge bg-info">Upcoming</span>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <?php foreach ($my_unique_evaluations as $ev): ?>
                            <?php
                                $ev_focus_raw = $ev['evaluation_focus'] ?? '';
                                $ev_focus_display = formatFocusDisplay($ev_focus_raw, $focus_labels_my);
                                $ev_signed = isset($my_signed_map[(int)$ev['id']]);
                                $ev_signature = $my_signed_map[(int)$ev['id']]['signature'] ?? '';
                                $ev_status = strtolower(trim((string)($ev['status'] ?? '')));
                                $slot_date = trim((string)($ev['observation_date'] ?? ''));
                                $slot_time = trim((string)($ev['observation_time'] ?? ''));
                                $slot_dt_key = $slot_date !== ''
                                    ? date('Y-m-d H:i', strtotime($slot_date . ' ' . ($slot_time !== '' ? $slot_time : '00:00')))
                                    : ('ev_' . (int)($ev['id'] ?? 0));
                                $slot_key = implode('|', [
                                    $slot_dt_key,
                                    trim((string)($ev['subject_area'] ?? '')),
                                    trim((string)normalizeSubjectDisplay((string)($ev['subject_observed'] ?? ''))),
                                    trim((string)($ev['observation_room'] ?? '')),
                                ]);
                                $slot_group = $my_slot_groups[$slot_key] ?? null;
                                $ev_day_time = '';
                                if (!empty($ev['observation_date'])) {
                                    $ev_day_time = date('l', strtotime($ev['observation_date']));
                                    $ev_time = trim((string)($ev['observation_time'] ?? ''));
                                    if (($ev_time === '' || $ev_time === '00:00:00' || $ev_time === '00:00') && !empty($slot_group['times'])) {
                                        $slot_times = array_keys($slot_group['times']);
                                        sort($slot_times);
                                        $ev_time = (string)($slot_times[0] ?? '');
                                    }
                                    if ($ev_time !== '' && $ev_time !== '00:00:00' && $ev_time !== '00:00') {
                                        $ev_start = date('g:i A', strtotime($ev_time));
                                        $ev_end = '';
                                        $ev_sched_end = trim((string)($my_teacher_data['evaluation_schedule_end'] ?? ''));
                                        if ($ev_sched_end !== '') {
                                            $ev_end = date('g:i A', strtotime($ev_sched_end));
                                        }
                                        $ev_day_time .= '<br>' . $ev_start . ($ev_end !== '' ? (' - ' . $ev_end) : '');
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
                                                                              OR LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('dean','principal','president','vice_president')
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
                                if (!empty($slot_group['evaluator_names'])) {
                                    foreach (array_keys($slot_group['evaluator_names']) as $slot_eval_name) {
                                        $slot_eval_name = trim((string)$slot_eval_name);
                                        if ($slot_eval_name !== '' && !in_array($slot_eval_name, $ev_observers, true)) {
                                            $ev_observers[] = $slot_eval_name;
                                        }
                                    }
                                }
                                $ev_own_name = trim((string)($_SESSION['name'] ?? ''));
                                $ev_observers = array_values(array_filter(array_unique($ev_observers), function($n) use ($ev_own_name) {
                                    return trim((string)$n) !== '' && trim((string)$n) !== $ev_own_name;
                                }));

                                // Slot-level completion rule: Conducted only when all observers
                                // have completed for this schedule slot.
                                $slot_has_rescheduled = (bool)($slot_group['has_rescheduled'] ?? false);
                                $slot_has_scheduled = (bool)($slot_group['has_scheduled'] ?? false);
                                $slot_ids = $slot_group['ids'] ?? [];
                                // Conducted only when every evaluator record for this slot is completed.
                                $all_evaluators_done = (!empty($slot_ids) && !$slot_has_rescheduled && !$slot_has_scheduled);
                            ?>
                            <tr>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <input type="checkbox" class="form-check-input sign-item-check" value="<?php echo (int)$ev['id']; ?>" data-schedule-label="Schedule: <?php echo htmlspecialchars(!empty($ev['observation_date']) ? date('M d, Y', strtotime($ev['observation_date'])) : ''); ?>" style="width:20px;height:20px;" title="<?php echo $ev_signed ? 'Signed schedule (can still be rescheduled)' : 'Select schedule'; ?>">
                                </td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars(($ev['semester'] ?? '') . ' Semester'); ?></td>
                                <td class="myobs-focus-cell" style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars($ev_focus_display); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo !empty($ev['observation_date']) ? date('M d, Y', strtotime($ev['observation_date'])) : ''; ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo $ev_day_time; ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($ev['subject_area'] ?? ''); ?></td>
                                <td style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars(normalizeSubjectDisplay($ev['subject_observed'] ?? '')); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;"><?php echo htmlspecialchars($ev['observation_room'] ?? ''); ?></td>
                                <td class="myobs-observers-cell" style="padding:10px;border:1px solid #dee2e6;font-size:0.85rem;"><?php echo htmlspecialchars(implode(', ', $ev_observers)); ?></td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <?php if (!empty($ev_signature)): ?>
                                        <img src="<?php echo htmlspecialchars($ev_signature); ?>" alt="Teacher Signature" style="max-height:30px;max-width:90px;">
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" style="padding:10px;border:1px solid #dee2e6;">
                                    <?php if ($all_evaluators_done): ?>
                                        <span class="badge bg-success">Conducted</span>
                                    <?php elseif ($slot_has_rescheduled): ?>
                                        <span class="badge bg-warning text-dark">Rescheduled</span>
                                    <?php elseif ($slot_has_scheduled || $ev_status === 'in_progress' || $ev_status === 'draft' || $ev_status === 'completed'): ?>
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
                    <button type="button" class="btn btn-primary" onclick="openScheduleModal()">
                        <i class="fas fa-calendar-plus me-1"></i>Set Schedule
                    </button>
                    <button type="button" class="btn btn-primary" onclick="openRescheduleModal()">
                        <i class="fas fa-redo me-1"></i>Reschedule
                    </button>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['role'] ?? '', ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'], true)): ?>
                    <button type="button" class="btn btn-warning" id="acceptRescheduleBtn" disabled onclick="acceptRescheduleRequest()">
                        <i class="fas fa-check-circle me-1"></i>Accept Reschedule Request
                    </button>
                    <?php endif; ?>
                    <?php if ($is_observer_role): ?>
                    <button class="btn btn-success" id="joinObserverBtn" disabled onclick="joinAsObserver()">
                        <i class="fas fa-user-plus me-1"></i>Observe
                    </button>
                    <button class="btn btn-danger" id="unableObserveBtn" disabled onclick="openUnableObserveModal()">
                        <i class="fas fa-envelope-open-text me-1"></i>Unable to Observe/Evaluate
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Observation Plan Table -->
                <div class="table-responsive">
                    <table class="plan-table">
                        <thead>
                            <tr>
                                <th style="width: 12%;">Teacher</th>
                                <th style="width: 6%;">Semester</th>
                                <th style="width: 12%;">Focus of Observation</th>
                                <th style="width: 8%;">Date</th>
                                <th style="width: 7%;">Day &amp; Time</th>
                                <th style="width: 8%;" id="th_subject_area"><?php echo in_array($raw_department, ['JHS', 'ELEM']) ? 'Grade Level/Section' : 'Subject Area'; ?></th>
                                <th style="width: 8%;" id="th_subject"><?php echo in_array($raw_department, ['JHS', 'ELEM']) ? 'Subject of Instruction' : 'Subject'; ?></th>
                                <th style="width: 5%;">Room</th>
                                <th style="width: 12%;">Name of Observers</th>
                                <th style="width: 10%; min-width: 90px;">Teacher's Signature</th>
                                <th style="width: 12%; min-width: 70px;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($teachers_list) > 0): ?>
                                <?php $counter = 1; foreach ($teachers_list as $t): ?>
                                <?php
                                    $tid = $t['id'];
                                    $row_key = $t['_row_key'] ?? $tid;
                                    $eval_id = (int)($eval_data[$row_key]['eval_id'] ?? 0);
                                    $sd = $schedule_data[$row_key] ?? [];
                                    $row_required_form_type_for_actions = strtolower(trim((string)($t['evaluation_form_type'] ?? 'iso')));
                                    if (!in_array($row_required_form_type_for_actions, ['iso', 'peac', 'both'], true)) {
                                        $row_required_form_type_for_actions = 'iso';
                                    }
                                    $slot_date_for_actions = $eval_data[$row_key]['date'] ?? '';
                                    if (!empty($slot_date_for_actions)) {
                                        $slot_date_for_actions = date('Y-m-d', strtotime((string)$slot_date_for_actions));
                                    }
                                    $slot_key_for_actions = implode('|', [
                                        (string)$tid,
                                        (string)$slot_date_for_actions,
                                        strtolower(trim((string)($sd['room'] ?? ''))),
                                        strtolower(trim((string)($sd['subject_area'] ?? ''))),
                                        strtolower(trim((string)normalizeSubjectDisplay((string)($sd['subject'] ?? '')))),
                                    ]);
                                    $slot_done_forms_for_actions = $completed_forms_by_slot[$slot_key_for_actions] ?? [];
                                    $is_done_by_requirement_for_actions = false;
                                    if ($row_required_form_type_for_actions === 'both') {
                                        $is_done_by_requirement_for_actions = !empty($slot_done_forms_for_actions['iso']) && !empty($slot_done_forms_for_actions['peac']);
                                    } elseif ($row_required_form_type_for_actions === 'peac') {
                                        $is_done_by_requirement_for_actions = !empty($slot_done_forms_for_actions['peac']);
                                    } else {
                                        $is_done_by_requirement_for_actions = !empty($slot_done_forms_for_actions['iso']);
                                    }
                                    $is_done = $is_done_by_requirement_for_actions;
                                    $row_has_schedule_data =
                                        !empty($t['evaluation_schedule']) ||
                                        !empty($sd['day_time']) ||
                                        !empty($eval_data[$row_key]['date'] ?? '');
                                    $has_schedule = !$is_done && $row_has_schedule_data;
                                    $row_eval_id_for_sig = (int)($eval_data[$row_key]['eval_id'] ?? 0);
                                    // Strict per-row signature ownership: use only the row's evaluation_id.
                                    // This prevents old/general signatures from auto-signing new rows.
                                    $ack_eval = ($row_eval_id_for_sig > 0) ? ($ack_eval_map[$row_eval_id_for_sig] ?? null) : ($ack_upcoming_map[$tid] ?? null);
                                    // Only use per-schedule signatures (per eval_id or upcoming).
                                    // Do not fall back to teacher-level acknowledgments from previous schedules.
                                    $is_schedule_signed = $has_schedule && !empty($ack_eval);
                                    // Signature is acknowledgment only; it must not block rescheduling.
                                    $can_reschedule = $has_schedule;
                                    $row_eval_id_for_req = (int)($eval_data[$row_key]['eval_id'] ?? 0);
                                    $pending_req_key = $tid . '|' . $row_eval_id_for_req;
                                    // Strict row-level request matching:
                                    // only the exact requested schedule row should carry the pending state.
                                    // (No teacher-level fallback, which caused all rows to be flagged.)
                                    if ($row_eval_id_for_req > 0) {
                                        $pending_req_info = $pending_reschedule_requests[$pending_req_key] ?? null;
                                    } else {
                                        // Upcoming rows are keyed as eval_id=0.
                                        $pending_req_info = $pending_reschedule_requests[$tid . '|0'] ?? null;
                                    }
                                    // Fallback: match by schedule slot key when request_eval_id is 0
                                    // but request_schedule_key is available.
                                    if (empty($pending_req_info)) {
                                        $row_date_for_req = trim((string)($eval_data[$row_key]['date'] ?? ''));
                                        $row_time_for_req = trim((string)($t['observation_time'] ?? ''));
                                        if (($row_time_for_req === '' || $row_time_for_req === '00:00:00' || $row_time_for_req === '00:00') && !empty($t['evaluation_schedule'])) {
                                            $row_time_for_req = date('H:i', strtotime((string)$t['evaluation_schedule']));
                                        }
                                        $row_sched_key = '';
                                        if ($row_date_for_req !== '') {
                                            $dtRaw = $row_date_for_req . ($row_time_for_req !== '' ? (' ' . $row_time_for_req) : ' 00:00');
                                            $row_sched_key = implode('|', [
                                                date('Y-m-d H:i', strtotime($dtRaw)),
                                                strtolower(trim((string)($sd['semester'] ?? $semester))),
                                                strtolower(trim((string)$academic_year)),
                                                strtolower(trim((string)($sd['room'] ?? ''))),
                                                strtolower(trim((string)($sd['subject_area'] ?? ''))),
                                                strtolower(trim((string)normalizeSubjectDisplay((string)($sd['subject'] ?? '')))),
                                            ]);
                                        }
                                        if ($row_sched_key !== '') {
                                            $pending_req_info = $pending_reschedule_by_slot[$tid . '|' . $row_sched_key] ?? null;
                                        }
                                    }
                                    $has_pending_req = !empty($pending_req_info);
                                    $pending_req_id = (int)($pending_req_info['notification_id'] ?? 0);
                                    $has_pending_req_strict = $has_pending_req;
                                    $is_schedule_past_for_observer = false;
                                    $row_sched_end_raw = trim((string)($eval_data[$row_key]['cutoff'] ?? ''));
                                    $row_sched_start_raw = trim((string)($t['evaluation_schedule'] ?? ''));
                                    $observer_cutoff_raw = $row_sched_end_raw !== '' ? $row_sched_end_raw : $row_sched_start_raw;
                                    if ($observer_cutoff_raw !== '') {
                                        try {
                                            $tz = new DateTimeZone('Asia/Manila');
                                            $cutoff_at = new DateTime($observer_cutoff_raw, $tz);
                                            $now_at = new DateTime('now', $tz);
                                            if ($now_at > $cutoff_at) {
                                                $is_schedule_past_for_observer = true;
                                            }
                                        } catch (Exception $e) {}
                                    }

                                ?>
                                <tr>
                                    <td>
                                        <?php if ($can_reschedule): ?>
                                            <?php if ($is_leader): ?>
                                                <?php
                                                    $is_opted = ($eval_id > 0) ? isset($leader_opted_evals[$eval_id]) : false;
                                                    $scheduled_by_me = ((int)($t['scheduled_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0));
                                                ?>
                                                <?php if (!$is_observer_only && $scheduled_by_me): ?>
                                                    <input type="checkbox" class="form-check-input reschedule-check no-print" value="<?php echo (int)$tid; ?>" data-eval-id="<?php echo (int)$eval_id; ?>" data-owning-department="<?php echo htmlspecialchars($row_owning_dept_filter, ENT_QUOTES); ?>" data-has-pending-req="<?php echo $has_pending_req ? '1' : '0'; ?>" data-has-pending-req-strict="<?php echo $has_pending_req_strict ? '1' : '0'; ?>" data-pending-notif-id="<?php echo $pending_req_id; ?>" style="width:16px;height:16px;cursor:pointer;margin-right:6px;vertical-align:middle;" title="Scheduled by you">
                                                <?php else: ?>
                                                    <?php $disable_observer_opt = $is_schedule_past_for_observer && !$is_opted; ?>
                                                    <input type="checkbox" class="form-check-input reschedule-check observer-opt-check no-print" value="<?php echo (int)$tid; ?>" data-eval-id="<?php echo $eval_id; ?>" data-owning-department="<?php echo htmlspecialchars($row_owning_dept_filter, ENT_QUOTES); ?>" <?php echo $is_opted ? 'checked' : ''; ?> data-opted="<?php echo $is_opted ? '1' : '0'; ?>" data-has-pending-req="<?php echo $has_pending_req ? '1' : '0'; ?>" data-has-pending-req-strict="<?php echo $has_pending_req_strict ? '1' : '0'; ?>" data-pending-notif-id="<?php echo $pending_req_id; ?>" style="width:16px;height:16px;cursor:pointer;margin-right:6px;vertical-align:middle;accent-color:green;" title="<?php echo $disable_observer_opt ? 'Schedule already passed; cannot accept as observer.' : ($is_opted ? 'You are an observer' : 'Check to join as observer'); ?>" <?php echo $disable_observer_opt ? 'disabled' : ''; ?>>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <input type="checkbox" class="form-check-input reschedule-check no-print" value="<?php echo (int)$tid; ?>" data-eval-id="<?php echo (int)$eval_id; ?>" data-owning-department="<?php echo htmlspecialchars($row_owning_dept_filter, ENT_QUOTES); ?>" data-has-pending-req="<?php echo $has_pending_req ? '1' : '0'; ?>" data-has-pending-req-strict="<?php echo $has_pending_req_strict ? '1' : '0'; ?>" data-pending-notif-id="<?php echo $pending_req_id; ?>" style="width:16px;height:16px;cursor:pointer;margin-right:6px;vertical-align:middle;">
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php echo $counter++ . '. ' . htmlspecialchars($t['name']); ?>
                                        <?php if ($has_pending_req && !$is_done): ?>
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
                                        $teacher_signature_display = trim((string)($ack_eval['signature'] ?? ''));
                                        ?>
                                        <?php if ($teacher_signature_display !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($teacher_signature_display); ?>" alt="Teacher Signature" style="max-height:34px;max-width:96px;">
                                        <?php else: ?>
                                            <div style="height:34px;display:flex;align-items:flex-end;justify-content:center;">
                                                <span style="display:inline-block;width:86px;border-bottom:1px solid #777;"></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" style="font-size:0.8rem;">
                                        <?php 
                                        $row_required_form_type = strtolower(trim((string)($t['evaluation_form_type'] ?? 'iso')));
                                        if (!in_array($row_required_form_type, ['iso', 'peac', 'both'], true)) {
                                            $row_required_form_type = 'iso';
                                        }
                                        $slot_date_for_remark = $eval_data[$row_key]['date'] ?? '';
                                        if (!empty($slot_date_for_remark)) {
                                            $slot_date_for_remark = date('Y-m-d', strtotime((string)$slot_date_for_remark));
                                        }
                                        $slot_key_for_remark = implode('|', [
                                            (string)$tid,
                                            (string)$slot_date_for_remark,
                                            strtolower(trim((string)($sd['room'] ?? ''))),
                                            strtolower(trim((string)($sd['subject_area'] ?? ''))),
                                            strtolower(trim((string)normalizeSubjectDisplay((string)($sd['subject'] ?? '')))),
                                        ]);
                                        $slot_done_forms = $completed_forms_by_slot[$slot_key_for_remark] ?? [];
                                        $is_done_by_requirement = false;
                                        if ($row_required_form_type === 'both') {
                                            $is_done_by_requirement = !empty($slot_done_forms['iso']) && !empty($slot_done_forms['peac']);
                                        } elseif ($row_required_form_type === 'peac') {
                                            $is_done_by_requirement = !empty($slot_done_forms['peac']);
                                        } else {
                                            $is_done_by_requirement = !empty($slot_done_forms['iso']);
                                        }
                                        // After schedule end time, incomplete required forms become "Did Not Evaluate".
                                        $is_overdue_not_evaluated = false;
                                        if (!$is_done_by_requirement) {
                                            $row_sched_end_raw = trim((string)($eval_data[$row_key]['cutoff'] ?? ''));
                                            $row_sched_start_raw = trim((string)($t['evaluation_schedule'] ?? ''));
                                            $cutoff_raw = $row_sched_end_raw !== '' ? $row_sched_end_raw : $row_sched_start_raw;
                                            if ($cutoff_raw !== '') {
                                                try {
                                                    $tz = new DateTimeZone('Asia/Manila');
                                                    $cutoff_at = new DateTime($cutoff_raw, $tz);
                                                    $cutoff_at->setTimezone($tz);
                                                    $now_at = new DateTime('now', $tz);
                                                    if ($now_at > $cutoff_at) {
                                                        $is_overdue_not_evaluated = true;
                                                    }
                                                } catch (Exception $e) {}
                                            }
                                        }
                                        $row_eval_status = strtolower(trim((string)($eval_data[$row_key]['status'] ?? '')));
                                        if ($is_done_by_requirement) {
                                            echo '<span class="badge bg-success">Conducted</span>';
                                        } elseif (count($observers) < 2) {
                                            echo '<span class="badge bg-danger">Observer Imbalance</span>';
                                        } elseif ($is_overdue_not_evaluated) {
                                            echo '<span class="badge bg-danger">Closed</span>';
                                        } elseif ($row_eval_status === 'rescheduled') {
                                            echo '<span class="badge bg-warning text-dark">Rescheduled</span>';
                                        } elseif ($row_eval_status === 'observer_unbalanced') {
                                            echo '<span class="badge bg-danger">Observer Imbalance</span>';
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
                                    <td colspan="10" class="text-center text-muted">No teachers found for this semester.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Prepared By (print only) -->
                <div class="prepared-by print-only">
                    <div class="prepared-by-inner">
                        <p><em>Prepared by:</em></p>
                        <div class="prepared-by-signature-stack">
                            <?php if (!empty($dean_signature)): ?>
                            <img class="sig-img" src="<?php echo $dean_signature; ?>" alt="Signature">
                            <?php endif; ?>
                            <p class="name-line"><?php echo htmlspecialchars(strtoupper($dean_name)); ?></p>
                            <p class="role-dept"><?php echo htmlspecialchars($dean_role_display); ?>, <?php echo htmlspecialchars($raw_department); ?></p>
                        </div>
                    </div>
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
                        <input type="hidden" name="reschedule_eval_id" id="reschedule_eval_id" value="">
                        <!-- Hidden mirror inputs for disabled fields in reschedule mode -->
                        <input type="hidden" id="mirror_semester" name="" value="">
                        <input type="hidden" id="mirror_form_type" name="" value="">
                        <div id="mirror_focus_container"></div>
                        <input type="hidden" name="filter_semester" value="<?php echo htmlspecialchars($semester); ?>">
                        <input type="hidden" name="filter_academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">

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

                        <!-- Teacher Selection -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Teacher <span class="text-danger">*</span></label>
                            <select class="form-select" name="teacher_id" id="schedule_teacher_id" required disabled>
                                <option value="">-- Select teacher --</option>
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
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

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

                        <div class="modal-scroll-controls" id="scheduleModalScrollControls" aria-label="Modal scroll controls">
                            <button type="button" class="modal-scroll-btn" id="scheduleModalScrollUp" title="Scroll up" aria-label="Scroll up" hidden>
                                <i class="fas fa-chevron-up"></i>
                            </button>
                            <button type="button" class="modal-scroll-btn" id="scheduleModalScrollDown" title="Scroll down" aria-label="Scroll down" hidden>
                                <i class="fas fa-chevron-down"></i>
                            </button>
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

    <?php if ($is_leader): ?>
    <div class="modal fade" id="unableObserveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-envelope-open-text me-2"></i>Unable to Observe/Evaluate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="unableObserveReasonSelect" class="form-label fw-bold">Reason (required)</label>
                        <select id="unableObserveReasonSelect" class="form-select" onchange="toggleUnableObserveComments(this.value)">
                            <option value="">-- Select reason --</option>
                            <option value="Schedule conflict">Schedule conflict</option>
                            <option value="Official meeting/administrative duty">Official meeting/administrative duty</option>
                            <option value="Emergency situation">Emergency situation</option>
                            <option value="Health concern">Health concern</option>
                            <option value="Travel/field assignment">Travel/field assignment</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-2" id="unableObserveCommentsWrap">
                        <label for="unableObserveComments" class="form-label fw-bold">Comments <span id="unableObserveCommentsRequiredText">(required for Other)</span></label>
                        <textarea id="unableObserveComments" class="form-control" rows="3" placeholder="Enter details/comments..."></textarea>
                    </div>
                    <small class="text-muted d-block mt-2">Reason will be sent automatically to the teacher via notification and email.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="submitUnableObserveReason()">
                        <i class="fas fa-paper-plane me-1"></i>Send Reason & Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

<script>
// Available departments for current user based on their role
const userRole = '<?php echo htmlspecialchars($_SESSION['role']); ?>';
const userDept = '<?php echo htmlspecialchars($_SESSION['department'] ?? ''); ?>';
const allDepartments = ['ELEM', 'JHS', 'SHS', 'CCIS', 'CAS', 'CTEAS', 'CBM', 'CTHM', 'CCJE'];

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

// Determine which departments the current user can set schedules for.
// IMPORTANT: sanitize only after departmentMap is defined.
let availableDepartments = <?php echo json_encode(array_values($schedule_available_departments)); ?>;
availableDepartments = Array.from(new Set((availableDepartments || [])
    .map(function(d){ return String(d || '').trim(); })
    .filter(function(d){ return !!departmentMap[d]; })));
if (availableDepartments.length === 0) {
    const pageDept = '<?php echo addslashes($raw_department); ?>';
    const sessionDept = userDept || '';
    const fallbackDept = (pageDept && departmentMap[pageDept]) ? pageDept : ((sessionDept && departmentMap[sessionDept]) ? sessionDept : '');
    if (fallbackDept) availableDepartments = [fallbackDept];
}

function populateDepartmentDropdown() {
    const deptSelect = document.getElementById('modal_scheduled_department');
    if (!deptSelect) return;

    availableDepartments = Array.from(new Set((availableDepartments || []).map(function(d){ return String(d || '').trim(); }).filter(function(d){ return !!departmentMap[d]; })));
    if (availableDepartments.length === 0) {
        const pageDept = '<?php echo addslashes($raw_department); ?>';
        const sessionDept = userDept || '';
        const fallbackDept = (pageDept && departmentMap[pageDept]) ? pageDept : ((sessionDept && departmentMap[sessionDept]) ? sessionDept : '');
        if (fallbackDept) availableDepartments = [fallbackDept];
    }
    // Clear and populate with available departments
    deptSelect.innerHTML = '<option value="">-- Select department --</option>';
    availableDepartments.forEach(dept => {
        const option = document.createElement('option');
        option.value = dept;
        option.textContent = departmentMap[dept] || dept;
        deptSelect.appendChild(option);
    });
}

function teacherBelongsToDepartment(opt, dept) {
    if (!opt || !dept) return false;
    try {
        const teacherDepts = JSON.parse(opt.dataset.departments || '[]');
        return Array.isArray(teacherDepts) && teacherDepts.includes(dept);
    } catch (e) {
        return false;
    }
}

function applyTeacherFilterByDepartment() {
    const deptSelect = document.getElementById('modal_scheduled_department');
    const teacherSelect = document.getElementById('schedule_teacher_id');
    if (!deptSelect || !teacherSelect) return;

    const selectedDept = (deptSelect.value || '').trim();
    const options = Array.from(teacherSelect.options);
    const current = teacherSelect.value;

    if (!selectedDept) {
        teacherSelect.disabled = true;
        teacherSelect.value = '';
        options.forEach((opt, idx) => {
            if (idx === 0) {
                opt.hidden = false;
                opt.textContent = '-- Select teacher --';
            } else {
                opt.hidden = true;
            }
        });
        return;
    }

    teacherSelect.disabled = false;
    let hasAny = false;
    options.forEach((opt, idx) => {
        if (idx === 0) {
            opt.hidden = false;
            opt.textContent = '-- Choose a teacher --';
            return;
        }
        const belongs = teacherBelongsToDepartment(opt, selectedDept);
        opt.hidden = !belongs;
        if (belongs) hasAny = true;
    });

    const stillValid = options.some((opt, idx) => idx > 0 && !opt.hidden && opt.value === current);
    if (!stillValid) teacherSelect.value = '';
    if (!hasAny && options[0]) options[0].textContent = '-- No teachers in selected department --';
}

function openPrintPlan() {
    const modalId = 'preparedBySignaturePrintModal';
    let modalEl = document.getElementById(modalId);
    const defaultPreparedByName = <?php echo json_encode((string)($_SESSION['name'] ?? '')); ?>;
    if (!modalEl) {
        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = modalId;
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.innerHTML = ''
            + '<div class="modal-dialog modal-dialog-centered" style="max-width:500px;">'
            + '  <div class="modal-content">'
            + '    <div class="modal-header">'
            + '      <h5 class="modal-title"><i class="fas fa-signature me-2"></i>Sign Before Printing</h5>'
            + '      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
            + '    </div>'
            + '    <div class="modal-body">'
            + '      <p class="text-muted small mb-2">Prepared by</p>'
            + '      <label for="preparedByPrintedName" class="form-label fw-bold">Printed Name</label>'
            + '      <input type="text" id="preparedByPrintedName" class="form-control mb-3" value="">'
            + '      <label class="form-label fw-bold">Signature</label>'
            + '      <div style="border:1px solid #ced4da;border-radius:8px;background:#fff;overflow:hidden;">'
            + '        <canvas id="preparedBySigCanvas" width="440" height="140" style="display:block;width:100%;height:140px;touch-action:none;cursor:crosshair;"></canvas>'
            + '      </div>'
            + '      <div class="mt-2 text-end">'
            + '        <button type="button" class="btn btn-sm btn-outline-secondary" id="preparedBySigClearBtn"><i class="fas fa-eraser me-1"></i>Clear</button>'
            + '      </div>'
            + '    </div>'
            + '    <div class="modal-footer">'
            + '      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>'
            + '      <button type="button" class="btn btn-primary" id="preparedBySigPrintBtn"><i class="fas fa-print me-1"></i>Use Signature & Print</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(modalEl);
    }

    const canvas = modalEl.querySelector('#preparedBySigCanvas');
    const clearBtn = modalEl.querySelector('#preparedBySigClearBtn');
    const printBtn = modalEl.querySelector('#preparedBySigPrintBtn');
    const printedNameInput = modalEl.querySelector('#preparedByPrintedName');
    const ctx = canvas.getContext('2d');
    let drawing = false;
    canvas.dataset.hasStroke = "0";
    if (printedNameInput && !printedNameInput.value.trim()) {
        printedNameInput.value = defaultPreparedByName;
    }

    function getPoint(evt) {
        const rect = canvas.getBoundingClientRect();
        const touch = evt.touches && evt.touches[0] ? evt.touches[0] : null;
        const clientX = touch ? touch.clientX : evt.clientX;
        const clientY = touch ? touch.clientY : evt.clientY;
        return { x: clientX - rect.left, y: clientY - rect.top };
    }

    function startDraw(evt) {
        evt.preventDefault();
        const p = getPoint(evt);
        drawing = true;
        canvas.dataset.hasStroke = "1";
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }

    function draw(evt) {
        if (!drawing) return;
        evt.preventDefault();
        const p = getPoint(evt);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    }

    function endDraw(evt) {
        if (evt) evt.preventDefault();
        drawing = false;
    }

    if (!canvas.dataset.initialized) {
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#111';

        canvas.addEventListener('mousedown', startDraw);
        canvas.addEventListener('mousemove', draw);
        window.addEventListener('mouseup', endDraw);

        canvas.addEventListener('touchstart', startDraw, { passive: false });
        canvas.addEventListener('touchmove', draw, { passive: false });
        canvas.addEventListener('touchend', endDraw, { passive: false });

        clearBtn.addEventListener('click', function() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            canvas.dataset.hasStroke = "0";
        });

        printBtn.addEventListener('click', function() {
            const printedName = printedNameInput ? printedNameInput.value.trim() : '';
            if (canvas.dataset.hasStroke !== "1") {
                alert('Please draw your signature first.');
                return;
            }
            if (!printedName) {
                alert('Please enter printed name.');
                return;
            }
            sessionStorage.setItem('prepared_by_signature_data', canvas.toDataURL('image/png'));
            sessionStorage.setItem('prepared_by_printed_name', printedName);
            const params = new URLSearchParams(window.location.search);
            params.set('auto_print', '1');
            params.set('prepared_sig', '1');
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            window.open('observation_plan_print.php?' + params.toString(), '_blank');
        });

        modalEl.addEventListener('shown.bs.modal', function() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            canvas.dataset.hasStroke = "0";
        });

        canvas.dataset.initialized = '1';
    }

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function setModalRescheduleMode(enabled) {
    // Hidden flag
    document.getElementById('modal_is_reschedule').value = enabled ? '1' : '';
    document.getElementById('reschedule_teacher_id').value = enabled ? (document.getElementById('schedule_teacher_id').value || '') : '';
    var evalInput = document.getElementById('reschedule_eval_id');
    if (!enabled && evalInput) evalInput.value = '';

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
    const deptSelect = document.getElementById('modal_scheduled_department');
    if (deptSelect) {
        // Auto-select current page department when allowed.
        const currentDept = '<?php echo addslashes($raw_department); ?>';
        if (currentDept && Array.from(deptSelect.options).some(o => o.value === currentDept)) {
            deptSelect.value = currentDept;
        } else {
            // If only one valid department is available, auto-select it.
            const validOptions = Array.from(deptSelect.options).filter(o => o.value);
            if (validOptions.length === 1) {
                deptSelect.value = validOptions[0].value;
            }
        }
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

    // Keep Set Schedule blank by default
    if (select) select.selectedIndex = 0;
    applyTeacherFilterByDepartment();

    // Open only after content is fully prepared to avoid visual "big then compress" jump.
    var modalEl = document.getElementById('scheduleModal');
    if (modalEl) {
        // Remove fade animation for this modal to reduce reflow jitter during zoom/resize.
        modalEl.classList.remove('fade');
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
        requestAnimationFrame(function() {
            modal.handleUpdate();
        });
    }
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

// Update Subject Area / Subject labels based on department
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
    if (labelArea) labelArea.innerHTML = isBasicEd ? 'Grade Level/Section <span class="text-danger">*</span>' : 'Subject Area';
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

    // Cancel selected rows:
    // - with eval_id => delete evaluation row
    // - without eval_id => clear teacher schedule-only row
    var evalIds = [];
    var scheduleOnlyTeacherIds = [];
    checked.forEach(function(cb) {
        var eid = parseInt(cb.dataset.evalId || '0', 10);
        if (eid > 0) {
            evalIds.push(eid);
        } else {
            var tid = parseInt(cb.value || '0', 10);
            if (tid > 0) scheduleOnlyTeacherIds.push(tid);
        }
    });
    if (evalIds.length === 0 && scheduleOnlyTeacherIds.length === 0) {
        alert('No valid selected schedule rows to cancel.');
        return;
    }

    // Create a hidden form and submit once
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    var actionInput = document.createElement('input');
    actionInput.name = 'action';
    actionInput.value = 'cancel_schedule';
    form.appendChild(actionInput);
    var evalInput = document.createElement('input');
    evalInput.name = 'eval_ids';
    evalInput.value = JSON.stringify(evalIds);
    form.appendChild(evalInput);
    var schedOnlyInput = document.createElement('input');
    schedOnlyInput.name = 'schedule_only_teacher_ids';
    schedOnlyInput.value = JSON.stringify(scheduleOnlyTeacherIds);
    form.appendChild(schedOnlyInput);
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
    var evalId = parseInt(checked[0].dataset.evalId || '0', 10);
    if (!(evalId > 0)) {
        alert('This row has no evaluation ID. Please set schedule again before rescheduling.');
        return;
    }
    var rowOwningDept = (checked[0].dataset.owningDepartment || '').trim();
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
    // Always rebuild department options in reschedule flow.
    populateDepartmentDropdown();

    // Department-first: preselect the schedule's department, filter teachers, then select teacher.
    const selectedOpt = Array.from(select.options).find(o => o.value === teacherId);
    const deptSelect = document.getElementById('modal_scheduled_department');
    if (deptSelect) {
        let targetDept = '';
        // 1) Prefer the owning department from selected schedule row.
        if (rowOwningDept) {
            targetDept = rowOwningDept;
        }
        // 2) Fallback to teacher option's scheduled department.
        if (!targetDept && selectedOpt) {
            const scheduledDept = (selectedOpt.dataset.scheduledDepartment || '').trim();
            if (scheduledDept) targetDept = scheduledDept;
        }
        // 3) Fallback to one of teacher's known departments.
        if (!targetDept && selectedOpt) {
            try {
                const tDepts = JSON.parse(selectedOpt.dataset.departments || '[]');
                if (Array.isArray(tDepts) && tDepts.length > 0) {
                    targetDept = (tDepts.find(d => availableDepartments.includes(d)) || tDepts[0] || '').trim();
                }
            } catch (e) {}
        }

        // If allowed list is empty/missing this dept, inject it so dropdown is never blank.
        if (targetDept && departmentMap[targetDept] && !availableDepartments.includes(targetDept)) {
            availableDepartments.push(targetDept);
            availableDepartments = Array.from(new Set(availableDepartments.filter(Boolean)));
            populateDepartmentDropdown();
        } else if (availableDepartments.length === 0) {
            // Ensure dropdown has at least one department for this flow.
            const fallbackDept = targetDept || '<?php echo addslashes($raw_department); ?>' || '<?php echo addslashes(trim((string)($_SESSION['department'] ?? ''))); ?>';
            if (fallbackDept && departmentMap[fallbackDept]) {
                availableDepartments.push(fallbackDept);
                availableDepartments = Array.from(new Set(availableDepartments.filter(Boolean)));
                populateDepartmentDropdown();
            }
        }
        if (targetDept) deptSelect.value = targetDept;
    }
    applyTeacherFilterByDepartment();
    // Select the teacher in dropdown and preload existing data
    select.value = teacherId;
    var evalInput = document.getElementById('reschedule_eval_id');
    if (evalInput) evalInput.value = (evalId > 0 ? String(evalId) : '');
    select.dispatchEvent(new Event('change'));

    // Hide/show form variants after department has been selected
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
    // Match Set Schedule open flow to avoid "big then compress" on first paint.
    scheduleModalElement.classList.remove('fade');
    var modal = bootstrap.Modal.getOrCreateInstance(scheduleModalElement);
    modal.show();
    requestAnimationFrame(function() {
        modal.handleUpdate();
    });
}

function acceptRescheduleRequest() {
    var checked = document.querySelectorAll('.reschedule-check:checked');
    if (checked.length === 0) {
        alert('Please select a teacher.');
        return;
    }
    if (checked.length > 1) {
        alert('Please select only one teacher at a time.');
        return;
    }

    var target = checked[0];
    var teacherId = parseInt(target.value || '0', 10);
    var evalId = parseInt(target.dataset.evalId || '0', 10);
    var notifId = parseInt(target.dataset.pendingNotifId || '0', 10);
    var hasPendingReq = (target.dataset.hasPendingReqStrict || '0') === '1';

    if (!(evalId > 0)) {
        alert('Invalid evaluation selection.');
        return;
    }
    if (!teacherId) {
        alert('Invalid teacher selection.');
        return;
    }

    if (!hasPendingReq) {
        alert('No pending reschedule request for the selected evaluation row.');
        return;
    }

    if (!confirm('Accept this teacher reschedule request and notify the teacher?')) return;

    // Optimistically clear current pending markers in UI to avoid stale blocking
    // if the user re-opens the modal before page reload.
    target.dataset.hasPendingReq = '0';
    target.dataset.hasPendingReqStrict = '0';
    target.dataset.pendingNotifId = '0';
    var acceptBtn = document.getElementById('acceptRescheduleBtn');
    if (acceptBtn) acceptBtn.disabled = true;

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;
    form.style.display = 'none';

    var a = document.createElement('input');
    a.type = 'hidden'; a.name = 'action'; a.value = 'accept_reschedule_request';
    form.appendChild(a);

    var e = document.createElement('input');
    e.type = 'hidden'; e.name = 'eval_id'; e.value = String(evalId);
    form.appendChild(e);

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
    // Make every Bootstrap modal scrollable (body scroll inside modal).
    document.querySelectorAll('.modal .modal-dialog').forEach(function(dialog) {
        dialog.classList.add('modal-dialog-scrollable');
    });

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

            // Department-first flow: selecting teacher should not rewrite department choices.
            updateFormTypeVisibility();
            updateSubjectLabels();
        });
    }

    // Department change handler for leaders: toggle PEAC/Both visibility
    const deptSelect = document.getElementById('modal_scheduled_department');
    if (deptSelect) {
        deptSelect.addEventListener('change', function() {
            applyTeacherFilterByDepartment();
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
        var allWithEvalId = true;
        checked.forEach(function(cb) {
            var eid = parseInt(cb.dataset.evalId || '0', 10);
            if (!(eid > 0)) allWithEvalId = false;
        });
        var canReschedule = (count === 1 && allWithEvalId);
        var canAcceptReq = false;
        if (count === 1) {
            var only = checked[0];
            var hasPendingReq = (only.dataset.hasPendingReqStrict || '0') === '1';
            var onlyEvalId = parseInt(only.dataset.evalId || '0', 10);
            canAcceptReq = hasPendingReq && (onlyEvalId > 0);
        }
        if (rescheduleBtn) rescheduleBtn.disabled = !canReschedule;
        // Cancel supports mixed rows now (eval rows + schedule-only rows).
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

    // Delegated fallback for browsers/UI states where per-node listeners
    // don't fire consistently (e.g. restored DOM state).
    document.addEventListener('change', function(e) {
        if (!e.target || !e.target.classList || !e.target.classList.contains('reschedule-check')) return;
        updateRescheduleState();
    });

    if (checkAll) {
        checkAll.addEventListener('change', function() {
            document.querySelectorAll('.reschedule-check').forEach(function(cb) {
                cb.checked = checkAll.checked;
            });
            updateRescheduleState();
        });
    }

    // Sync button states on initial load too (important when browser restores checkbox state).
    updateRescheduleState();
})();

<?php if ($is_observer_role): ?>
// Observer opt-in checkboxes for all observer roles (Dean, Principal, Coordinators, President, VP)
(function() {
    var joinBtn = document.getElementById('joinObserverBtn');
    var leaveBtn = document.getElementById('leaveObserverBtn');
    var unableBtn = document.getElementById('unableObserveBtn');

    function updateObserverBtnState() {
        var checks = document.querySelectorAll('.observer-opt-check');
        var toJoin = 0;
        var toLeave = 0;
        var selectedSched = 0;
        checks.forEach(function(cb) {
            var wasOpted = cb.dataset.opted === '1';
            if (cb.checked && !wasOpted) toJoin++;
            if (cb.checked && wasOpted) toLeave++;
        });
        document.querySelectorAll('.reschedule-check').forEach(function(cb) {
            var eid = parseInt(cb.dataset.evalId || '0', 10);
            if (cb.checked && eid > 0) selectedSched++;
        });
        if (joinBtn) joinBtn.disabled = (toJoin === 0);
        if (leaveBtn) leaveBtn.disabled = (toLeave === 0);
        if (unableBtn) unableBtn.disabled = (selectedSched === 0);
    }

    document.querySelectorAll('.observer-opt-check').forEach(function(cb) {
        cb.addEventListener('change', updateObserverBtnState);
    });
    document.querySelectorAll('.reschedule-check').forEach(function(cb) {
        cb.addEventListener('change', updateObserverBtnState);
    });
    updateObserverBtnState();
})();

function joinAsObserver() {
    var evalIds = [];
    var teacherIds = [];
    document.querySelectorAll('.observer-opt-check').forEach(function(cb) {
        var eid = parseInt(cb.dataset.evalId || '0', 10);
        if (cb.checked && cb.dataset.opted !== '1') {
            teacherIds.push(parseInt(cb.value, 10));
            if (eid > 0) evalIds.push(eid);
        }
    });
    if (evalIds.length === 0 && teacherIds.length === 0) return;
    var selectedCount = (evalIds.length > 0) ? evalIds.length : teacherIds.length;
    if (!confirm('Observe ' + selectedCount + ' schedule(s)? This will notify the teacher, dean/principal, and coordinators.')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;
    var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'join_observer'; form.appendChild(a);
    var t = document.createElement('input'); t.type = 'hidden'; t.name = 'eval_ids'; t.value = JSON.stringify(evalIds); form.appendChild(t);
    var tt = document.createElement('input'); tt.type = 'hidden'; tt.name = 'teacher_ids'; tt.value = JSON.stringify(teacherIds); form.appendChild(tt);
    var semEl = document.querySelector('select[name=\"semester\"]');
    var ayEl = document.querySelector('select[name=\"academic_year\"]');
    var semIn = document.createElement('input'); semIn.type = 'hidden'; semIn.name = 'semester'; semIn.value = semEl ? semEl.value : ''; form.appendChild(semIn);
    var ayIn = document.createElement('input'); ayIn.type = 'hidden'; ayIn.name = 'academic_year'; ayIn.value = ayEl ? ayEl.value : ''; form.appendChild(ayIn);
    document.body.appendChild(form);
    form.submit();
}

function leaveAsObserver() {
    openUnableObserveModal();
}

function openUnableObserveModal() {
    var evalIds = [];
    document.querySelectorAll('.reschedule-check').forEach(function(cb) {
        var eid = parseInt(cb.dataset.evalId || '0', 10);
        if (cb.checked && eid > 0) evalIds.push(eid);
    });
    if (evalIds.length === 0) {
        alert('Please select at least one schedule.');
        return;
    }
    var reasonSelect = document.getElementById('unableObserveReasonSelect');
    var commentsBox = document.getElementById('unableObserveComments');
    var commentsWrap = document.getElementById('unableObserveCommentsWrap');
    if (reasonSelect) reasonSelect.value = '';
    if (commentsBox) commentsBox.value = '';
    toggleUnableObserveComments(reasonSelect ? reasonSelect.value : '');
    var el = document.getElementById('unableObserveModal');
    if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        var reasonFallback = (window.prompt('Reason (e.g., Schedule conflict / Emergency / Other):', '') || '').trim();
        if (!reasonFallback) return;
        var commentsFallback = (window.prompt('Comments:', '') || '').trim();
        if (!commentsFallback) return;
        submitUnableObserveReason(reasonFallback + ' | Comments: ' + commentsFallback);
        return;
    }
    var modal = bootstrap.Modal.getOrCreateInstance(el);
    modal.show();
}

function toggleUnableObserveComments(reasonValue) {
    var wrap = document.getElementById('unableObserveCommentsWrap');
    var comments = document.getElementById('unableObserveComments');
    var requiredText = document.getElementById('unableObserveCommentsRequiredText');
    var isOther = String(reasonValue || '').trim() === 'Other';
    if (wrap) wrap.style.display = '';
    if (comments) comments.required = isOther;
    if (requiredText) requiredText.style.display = isOther ? '' : 'none';
}

function submitUnableObserveReason(reasonArg) {
    var reason = '';
    if (typeof reasonArg === 'string') {
        reason = reasonArg.trim();
    } else {
        var selectedReason = (document.getElementById('unableObserveReasonSelect')?.value || '').trim();
        var comments = (document.getElementById('unableObserveComments')?.value || '').trim();
        if (!selectedReason) {
            alert('Please select a reason.');
            return;
        }
        if (selectedReason === 'Other') {
            if (!comments) {
                alert('Please enter comments for Other reason.');
                var commentsBox = document.getElementById('unableObserveComments');
                if (commentsBox) commentsBox.focus();
                return;
            }
            reason = 'Other | Comments: ' + comments;
        } else {
            reason = comments ? selectedReason + ' | Comments: ' + comments : selectedReason;
        }
    }

    var evalIds = [];
    document.querySelectorAll('.reschedule-check').forEach(function(cb) {
        var eid = parseInt(cb.dataset.evalId || '0', 10);
        if (cb.checked && eid > 0) {
            evalIds.push(eid);
        }
    });
    if (evalIds.length === 0) return;
    if (!confirm('Cancel as observer for ' + evalIds.length + ' schedule(s) and notify the teacher?')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;
    var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'leave_observer'; form.appendChild(a);
    var t = document.createElement('input'); t.type = 'hidden'; t.name = 'eval_ids'; t.value = JSON.stringify(evalIds); form.appendChild(t);
    var r = document.createElement('input'); r.type = 'hidden'; r.name = 'observer_reason'; r.value = reason; form.appendChild(r);
    var semEl = document.querySelector('select[name=\"semester\"]');
    var ayEl = document.querySelector('select[name=\"academic_year\"]');
    var semIn = document.createElement('input'); semIn.type = 'hidden'; semIn.name = 'semester'; semIn.value = semEl ? semEl.value : ''; form.appendChild(semIn);
    var ayIn = document.createElement('input'); ayIn.type = 'hidden'; ayIn.name = 'academic_year'; ayIn.value = ayEl ? ayEl.value : ''; form.appendChild(ayIn);
    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('change', function(e) {
    if (!e.target || e.target.id !== 'unableObserveReasonSelect') return;
    toggleUnableObserveComments(e.target.value || '');
});

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
        // Resolve container directly so Sign button can enable even before canvas init.
        var hiddenItemsContainer = document.getElementById('myObsSignedItemsContainer');
        var canSign = !!panelEl && !!hiddenItemsContainer;
        if (countEl) countEl.textContent = count + ' schedule(s) selected';
        if (hiddenItemsContainer) {
            hiddenItemsContainer.innerHTML = '';
            checks.forEach(function(cb) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'signed_items[]'; input.value = cb.value;
                hiddenItemsContainer.appendChild(input);
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
    var targetEvalId = '<?php echo isset($_GET['eval_id']) ? (int)$_GET['eval_id'] : 0; ?>';
    if (!autoOpenReschedule || !targetTeacherId) return;

    var targetCheckbox = null;
    if (targetEvalId && parseInt(targetEvalId, 10) > 0) {
        targetCheckbox = document.querySelector('.reschedule-check[value="' + targetTeacherId + '"][data-eval-id="' + targetEvalId + '"]');
    }
    if (!targetCheckbox) {
        // Prefer teacher row that still has the pending request marker;
        // fallback to the first row for that teacher.
        targetCheckbox = document.querySelector('.reschedule-check[value="' + targetTeacherId + '"][data-has-pending-req-strict="1"]')
            || document.querySelector('.reschedule-check[value="' + targetTeacherId + '"]');
    }
    if (targetCheckbox) {
        document.querySelectorAll('.reschedule-check').forEach(function(cb) { cb.checked = false; });
        targetCheckbox.checked = true;
        targetCheckbox.dispatchEvent(new Event('change'));
        openRescheduleModal();
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

document.addEventListener('DOMContentLoaded', function() {
    var modalEl = document.getElementById('scheduleModal');
    if (!modalEl) return;

    var bodyEl = modalEl.querySelector('.modal-body');
    var upBtn = document.getElementById('scheduleModalScrollUp');
    var downBtn = document.getElementById('scheduleModalScrollDown');
    if (!bodyEl || !upBtn || !downBtn) return;

    function refreshScrollButtons() {
        var canScroll = bodyEl.scrollHeight > bodyEl.clientHeight + 2;
        var atTop = bodyEl.scrollTop <= 4;
        var atBottom = (bodyEl.scrollTop + bodyEl.clientHeight) >= (bodyEl.scrollHeight - 4);

        upBtn.hidden = !canScroll || atTop;
        downBtn.hidden = !canScroll || atBottom;
    }

    upBtn.addEventListener('click', function() {
        bodyEl.scrollBy({ top: -260, behavior: 'smooth' });
    });
    downBtn.addEventListener('click', function() {
        bodyEl.scrollBy({ top: 260, behavior: 'smooth' });
    });

    bodyEl.addEventListener('scroll', refreshScrollButtons);
    modalEl.addEventListener('shown.bs.modal', function() {
        setTimeout(refreshScrollButtons, 0);
    });
    window.addEventListener('resize', refreshScrollButtons);
    refreshScrollButtons();
});
</script>
</body>
</html>







