<?php
// Handle AJAX save draft requests (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_draft') {
    require_once '../config/database.php';
    require_once '../models/Evaluation.php';
    require_once '../models/Teacher.php';
    require_once '../controllers/AIController.php';

    $db = (new Database())->getConnection();
    $evalController = new EvaluationController($db);

    if (session_status() === PHP_SESSION_NONE) session_start();
    $evaluatorId = $_SESSION['user_id'] ?? null;

    $postData = $_POST;
    $result = $evalController->saveDraft($postData, $evaluatorId);

    header('Content-Type: application/json');
    echo json_encode($result);
    exit();
}

// ✅ ADD: Handle AJAX FINAL submit requests (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'submit_evaluation') {
    require_once '../config/database.php';
    require_once '../models/Evaluation.php';
    require_once '../models/Teacher.php';
    require_once '../controllers/AIController.php';

    $db = (new Database())->getConnection();
    $evalController = new EvaluationController($db);

    if (session_status() === PHP_SESSION_NONE) session_start();
    $evaluatorId = $_SESSION['user_id'] ?? null;

    $postData = $_POST;
    $result = $evalController->submitEvaluation($postData, $evaluatorId);

    header('Content-Type: application/json');
    echo json_encode($result);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_teacher' && isset($_GET['id'])) {
    require_once '../config/database.php';
    require_once '../models/Teacher.php';
    $db = (new Database())->getConnection();
    $teacherModel = new Teacher($db);
    $teacher = $teacherModel->getById($_GET['id']);
    header('Content-Type: application/json');
    if ($teacher) {
        echo json_encode(['success' => true, 'teacher' => [
            'name' => $teacher['name'],
            'department' => $teacher['department']
        ]]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Teacher not found']);
    }
    exit();
}

// Program assignment helpers
$programHelperPath = __DIR__ . '/../includes/program_assignments.php';
if (file_exists($programHelperPath)) {
    require_once $programHelperPath;
}

// Ensure AIController is available when this controller is used directly
if (!class_exists('AIController')) {
    $aiPath = __DIR__ . '/AIController.php';
    if (file_exists($aiPath)) {
        require_once $aiPath;
    }
}

class EvaluationController {
    private $db;
    private $evaluationModel;
    private $aiController;

    private const EVALUATION_TIMEZONE = 'Asia/Manila';
    private const CLOSED_SCHEDULE_RESET_GRACE_MINUTES = 30;
    private const SIGNATURE_DATAURL_PATTERN = '/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/';
    private $reusedEvaluationId = 0;
    private $criterionCountCache = null;

    public function __construct($database) {
        $this->db = $database;
        $this->evaluationModel = new Evaluation($database);
        $this->aiController = new AIController($database);
    }

    private function hasAcceptedObserverAssignment(int $teacherId, int $evaluatorId): bool {
        if ($teacherId <= 0 || $evaluatorId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT 1
             FROM teacher_assignments
             WHERE teacher_id = :teacher_id
               AND evaluator_id = :evaluator_id
               AND eval_id IS NOT NULL
             LIMIT 1"
        );
        $stmt->execute([
            ':teacher_id' => $teacherId,
            ':evaluator_id' => $evaluatorId
        ]);

        return (bool)$stmt->fetchColumn();
    }

    private function canUseScheduleDepartment(int $teacherId, int $evaluatorId, string $evaluatorRole, string $evaluatorDept, string $scheduleDept): bool {
        $scheduleDept = trim($scheduleDept);
        if ($scheduleDept === '' || in_array($evaluatorRole, ['president', 'vice_president'], true)) {
            return true;
        }

        if (strcasecmp(trim($evaluatorDept), $scheduleDept) === 0) {
            return true;
        }

        return $this->hasAcceptedObserverAssignment($teacherId, $evaluatorId);
    }

    private function isSchedulePastRequiredGrace(?string $scheduleEndVal, DateTime $now, DateTimeZone $timezone): bool {
        $scheduleEndVal = is_string($scheduleEndVal) ? trim($scheduleEndVal) : '';
        if ($scheduleEndVal === '') {
            return false;
        }

        try {
            $scheduleEnd = new DateTime($scheduleEndVal, $timezone);
            $scheduleEnd->setTimezone($timezone);
        } catch (Exception $e) {
            return false;
        }

        $resetAt = clone $scheduleEnd;
        $resetAt->modify('+' . self::CLOSED_SCHEDULE_RESET_GRACE_MINUTES . ' minutes');

        return $now >= $resetAt;
    }

    private function buildScheduleGate(?string $scheduleVal, ?string $roomVal, ?string $scheduleEndVal = null): array {
        $scheduleVal = is_string($scheduleVal) ? trim($scheduleVal) : '';
        $roomVal = is_string($roomVal) ? trim($roomVal) : '';
        $scheduleEndVal = is_string($scheduleEndVal) ? trim($scheduleEndVal) : '';

        if ($scheduleVal === '' && $roomVal === '') {
            return [
                'scheduled' => false,
                'allowed' => false,
                'reason' => 'missing_schedule',
                'message' => 'Cannot submit evaluation: no evaluation schedule is set for this teacher.',
            ];
        }

        if ($scheduleVal === '') {
            return [
                'scheduled' => true,
                'allowed' => false,
                'reason' => 'missing_datetime',
                'message' => 'Cannot submit evaluation yet: the teacher has a room assigned but no scheduled date and time.',
            ];
        }

        if ($scheduleEndVal === '') {
            return [
                'scheduled' => true,
                'allowed' => false,
                'reason' => 'missing_end_time',
                'message' => 'Cannot submit evaluation yet: a complete schedule with start and end time is required.',
            ];
        }

        try {
            $timezone = new DateTimeZone(self::EVALUATION_TIMEZONE);
            $scheduledAt = new DateTime($scheduleVal, $timezone);
            $scheduledAt->setTimezone($timezone);
            $scheduleEnd = new DateTime($scheduleEndVal, $timezone);
            $scheduleEnd->setTimezone($timezone);
            $now = new DateTime('now', $timezone);
        } catch (Exception $e) {
            error_log('Invalid evaluation schedule for teacher gating: ' . $e->getMessage());
            return [
                'scheduled' => true,
                'allowed' => false,
                'reason' => 'invalid_datetime',
                'message' => 'Cannot submit evaluation: the evaluation schedule is invalid. Please ask the dean/principal to reschedule it.',
            ];
        }

        if ($scheduleEnd < $scheduledAt) {
            return [
                'scheduled' => true,
                'allowed' => false,
                'reason' => 'invalid_datetime',
                'message' => 'Cannot submit evaluation: the evaluation schedule end time is earlier than the start time. Please ask the dean/principal to reschedule it.',
            ];
        }

        if ($now < $scheduledAt) {
            return [
                'scheduled' => true,
                'allowed' => false,
                'reason' => 'too_early',
                'message' => 'Cannot submit evaluation yet. Evaluation opens on ' . $scheduledAt->format('F d, Y \a\t h:i A') . '.',
            ];
        }

        if ($now > $scheduleEnd) {
            if ($this->isSchedulePastRequiredGrace($scheduleEndVal, $now, $timezone)) {
                return [
                    'scheduled' => false,
                    'allowed' => false,
                    'reason' => 'missing_schedule',
                    'message' => 'Cannot submit evaluation: no evaluation schedule is set for this teacher.',
                ];
            }

            return [
                'scheduled' => true,
                'allowed' => false,
                'reason' => 'deadline_passed',
                'message' => 'Cannot submit evaluation: the evaluation deadline has passed. No further changes are allowed.',
            ];
        }

        return [
            'scheduled' => true,
            'allowed' => true,
            'reason' => 'ready',
            'message' => '',
        ];
    }

    private function resolveEffectiveScheduleForEvaluator(int $teacherId, int $evaluatorId, string $evaluatorRole = '', string $evaluatorDept = ''): array {
        $fallback = [
            'schedule' => null,
            'schedule_end' => null,
            'room' => null,
            'semester' => null,
            'academic_year' => null,
        ];

        try {
            $stmt = $this->db->prepare(
                "SELECT e.observation_date, e.observation_time, e.observation_room, e.semester, e.academic_year,
                        e.subject_observed, e.subject_area, e.evaluation_focus, e.department,
                        COALESCE(ts.schedule_end, ts_slot.schedule_end) AS schedule_end
                 FROM evaluations e
                 LEFT JOIN teacher_schedules ts ON ts.evaluation_id = e.id
                 LEFT JOIN teacher_schedules ts_slot ON ts_slot.teacher_id = e.teacher_id
                    AND DATE(ts_slot.schedule_start) = e.observation_date
                    AND DATE_FORMAT(ts_slot.schedule_start, '%H:%i') = (
                        CASE
                            WHEN e.observation_time IS NULL OR TRIM(e.observation_time) = '' THEN '00:00'
                            ELSE LEFT(TRIM(e.observation_time), 5)
                        END
                    )
                 WHERE e.teacher_id = :tid
                   AND e.evaluator_id = :eid
                   AND e.observation_date IS NOT NULL
                   AND (
                        e.status IN ('draft','pending','observer_unbalanced')
                        OR e.status IS NULL
                        OR e.status = ''
                    )
                  ORDER BY e.observation_date ASC, COALESCE(e.observation_time, '00:00:00') ASC, e.id ASC"
            );
            $stmt->execute([':tid' => $teacherId, ':eid' => $evaluatorId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $tz = new DateTimeZone(self::EVALUATION_TIMEZONE);
            $now = new DateTime('now', $tz);
            $row = null;
            $dt = null;
            $bestDiff = null;
            foreach ($rows as $r) {
                $d = trim((string)($r['observation_date'] ?? ''));
                if ($d === '') continue;
                if ($this->isSchedulePastRequiredGrace($r['schedule_end'] ?? null, $now, $tz)) continue;
                $t = trim((string)($r['observation_time'] ?? ''));
                if ($t === '' || $t === '00:00') $t = '00:00:00';
                $dtCandidate = new DateTime($d . ' ' . $t, $tz);
                $diff = abs($dtCandidate->getTimestamp() - $now->getTimestamp());
                if ($bestDiff === null || $diff < $bestDiff) {
                    $bestDiff = $diff;
                    $row = $r;
                    $dt = $dtCandidate;
                }
            }

            // Dean/Principal/Coordinator fallback:
            // if no evaluator-specific pending slot exists, use the closest
            // pending slot owned by their department, or a slot they explicitly
            // accepted as observer.
            if ($row === null && in_array($evaluatorRole, ['dean', 'principal', 'chairperson', 'subject_coordinator', 'grade_level_coordinator'], true) && trim($evaluatorDept) !== '') {
                $stmtAny = $this->db->prepare(
                    "SELECT e.observation_date, e.observation_time, e.observation_room, e.semester, e.academic_year,
                            e.subject_observed, e.subject_area, e.evaluation_focus, e.department,
                            COALESCE(ts.schedule_end, ts_slot.schedule_end) AS schedule_end
                     FROM evaluations e
                     INNER JOIN teachers t ON t.id = e.teacher_id
                     LEFT JOIN teacher_schedules ts ON ts.evaluation_id = e.id
                     LEFT JOIN teacher_schedules ts_slot ON ts_slot.teacher_id = e.teacher_id
                        AND DATE(ts_slot.schedule_start) = e.observation_date
                        AND DATE_FORMAT(ts_slot.schedule_start, '%H:%i') = (
                            CASE
                                WHEN e.observation_time IS NULL OR TRIM(e.observation_time) = '' THEN '00:00'
                                ELSE LEFT(TRIM(e.observation_time), 5)
                            END
                        )
                      WHERE e.teacher_id = :tid
                        AND e.observation_date IS NOT NULL
                       AND (
                            e.status IN ('draft','pending','observer_unbalanced')
                            OR e.status IS NULL
                            OR e.status = ''
                       )
                        AND (
                             e.department = :dept_eval
                             OR (
                                 COALESCE(NULLIF(e.department, ''), '') = ''
                                 AND t.scheduled_department = :dept_sched
                             )
                             OR (
                                 COALESCE(NULLIF(e.department, ''), NULLIF(t.scheduled_department, '')) IS NULL
                                 AND t.department = :dept_legacy
                             )
                             OR EXISTS (
                                 SELECT 1
                                 FROM teacher_assignments ta
                                 WHERE ta.teacher_id = e.teacher_id
                                   AND ta.evaluator_id = :assigned_evaluator_id
                                   AND ta.eval_id IS NOT NULL
                             )
                        )
                      ORDER BY e.observation_date ASC, COALESCE(e.observation_time, '00:00:00') ASC, e.id ASC"
                );
                $stmtAny->execute([
                    ':tid' => $teacherId,
                    ':dept_eval' => $evaluatorDept,
                    ':dept_sched' => $evaluatorDept,
                    ':dept_legacy' => $evaluatorDept,
                    ':assigned_evaluator_id' => $evaluatorId
                ]);
                $rowsAny = $stmtAny->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $bestAnyDiff = null;
                foreach ($rowsAny as $rAny) {
                    $dAny = trim((string)($rAny['observation_date'] ?? ''));
                    if ($dAny === '') continue;
                    if ($this->isSchedulePastRequiredGrace($rAny['schedule_end'] ?? null, $now, $tz)) continue;
                    $tAny = trim((string)($rAny['observation_time'] ?? ''));
                    if ($tAny === '' || $tAny === '00:00') $tAny = '00:00:00';
                    $dtAny = new DateTime($dAny . ' ' . $tAny, $tz);
                    $diffAny = abs($dtAny->getTimestamp() - $now->getTimestamp());
                    if ($bestAnyDiff === null || $diffAny < $bestAnyDiff) {
                        $bestAnyDiff = $diffAny;
                        $row = $rAny;
                        $dt = $dtAny;
                    }
                }
            }

            if ($row === null || $dt === null) return $fallback;
            return [
                'schedule' => $dt->format('Y-m-d H:i:s'),
                'schedule_end' => trim((string)($row['schedule_end'] ?? '')),
                'room' => trim((string)($row['observation_room'] ?? '')),
                'semester' => trim((string)($row['semester'] ?? '')),
                'academic_year' => trim((string)($row['academic_year'] ?? '')),
                'subject_observed' => trim((string)($row['subject_observed'] ?? '')),
                'subject_area' => trim((string)($row['subject_area'] ?? '')),
                'evaluation_focus' => trim((string)($row['evaluation_focus'] ?? '')),
                'department' => trim((string)($row['department'] ?? '')),
            ];
        } catch (Exception $e) {
            return $fallback;
        }
    }

    private function assertObserverBalance(int $teacherId, string $observationDate, string $observationTime, string $department = '', bool $allowOverride = false): void {
        $observationDate = trim($observationDate);
        $observationTime = trim($observationTime);
        if ($observationDate === '') return;
        if ($observationTime === '') $observationTime = '00:00';
        $observationTime = substr($observationTime, 0, 5);
        $department = trim($department);

        $balanceStmt = $this->db->prepare(
            "SELECT
                COUNT(DISTINCT e.evaluator_id) AS observer_count
             FROM evaluations e
             WHERE e.teacher_id = :teacher_id
               AND e.observation_date = :observation_date
               AND (
                    CASE
                        WHEN e.observation_time IS NULL OR TRIM(e.observation_time) = '' THEN '00:00'
                        ELSE LEFT(TRIM(e.observation_time), 5)
                    END
               ) = :observation_time
               AND (:department = '' OR e.department = :department_match)
               AND (e.status IS NULL OR e.status NOT IN ('cancelled','canceled','rescheduled'))"
        );
        $balanceStmt->execute([
            ':teacher_id' => $teacherId,
            ':observation_date' => $observationDate,
            ':observation_time' => $observationTime,
            ':department' => $department,
            ':department_match' => $department
        ]);
        $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $observerCount = (int)($balance['observer_count'] ?? 0);

        try {
            $activeScheduleStmt = $this->db->prepare(
                "SELECT 1
                 FROM teacher_schedules ts
                 WHERE ts.teacher_id = :teacher_id
                   AND DATE(ts.schedule_start) = :observation_date
                   AND DATE_FORMAT(ts.schedule_start, '%H:%i') = :observation_time
                   AND (:department = '' OR ts.scheduled_department = :department_match)
                   AND ts.status = 'scheduled'
                 LIMIT 1"
            );
            $activeScheduleStmt->execute([
                ':teacher_id' => $teacherId,
                ':observation_date' => $observationDate,
                ':observation_time' => $observationTime,
                ':department' => $department,
                ':department_match' => $department
            ]);

            if ($activeScheduleStmt->fetchColumn()) {
                $requiredObserverStmt = $this->db->prepare(
                    "SELECT COUNT(DISTINCT observer_id)
                     FROM (
                         SELECT u.id AS observer_id
                         FROM users u
                         WHERE u.status = 'active'
                           AND u.role IN ('dean','principal')
                           AND (:department_dean = '' OR u.department = :department_match_dean)
                         UNION
                         SELECT u.id AS observer_id
                         FROM teacher_assignments ta
                         JOIN users u ON u.id = ta.evaluator_id
                         WHERE ta.teacher_id = :teacher_id_coord
                           AND u.status = 'active'
                           AND u.role IN ('chairperson','subject_coordinator','grade_level_coordinator')
                           AND (:department_coord = '' OR u.department = :department_match_coord)
                         UNION
                         SELECT u.id AS observer_id
                         FROM teacher_assignments ta
                         JOIN users u ON u.id = ta.evaluator_id
                         WHERE ta.teacher_id = :teacher_id_pvp
                           AND ta.eval_id IS NOT NULL
                           AND u.status = 'active'
                           AND u.role IN ('president','vice_president')
                         UNION
                         SELECT u.id AS observer_id
                         FROM teacher_assignments ta
                         JOIN users u ON u.id = ta.evaluator_id
                         WHERE ta.teacher_id = :teacher_id_requested
                           AND ta.eval_id IS NOT NULL
                           AND u.status = 'active'
                           AND LOWER(REPLACE(TRIM(u.role), ' ', '_')) IN ('teacher','dean','principal','chairperson','subject_coordinator','grade_level_coordinator','president','vice_president')
                     ) required_observers"
                );
                $requiredObserverStmt->execute([
                    ':department_dean' => $department,
                    ':department_match_dean' => $department,
                    ':teacher_id_coord' => $teacherId,
                    ':department_coord' => $department,
                    ':department_match_coord' => $department,
                    ':teacher_id_pvp' => $teacherId,
                    ':teacher_id_requested' => $teacherId
                ]);
                $observerCount = max($observerCount, (int)$requiredObserverStmt->fetchColumn());
            }
        } catch (Exception $e) {
            // Keep evaluation-row count as fallback.
        }

        if ($observerCount < 2 && !$allowOverride) {
            throw new Exception('Observer imbalance: evaluation cannot proceed until at least 2 observers/evaluators are assigned.');
        }
    }

    public function submitEvaluation($postData, $evaluatorId) {
        try {
            $this->reusedEvaluationId = 0;
            if (empty($evaluatorId)) {
                throw new Exception('Unauthorized');
            }

            // Enforce schedule requirement: you can't evaluate a teacher without a schedule.
            $teacherId = $postData['teacher_id'] ?? null;
            if (empty($teacherId)) {
                throw new Exception('Teacher is required');
            }

            // Enforce coordinator program assignment + explicit teacher assignment
            $evaluatorInfoStmt = $this->db->prepare("SELECT name, role, department FROM users WHERE id = :id LIMIT 1");
            $evaluatorInfoStmt->bindValue(':id', $evaluatorId);
            $evaluatorInfoStmt->execute();
            $evaluatorInfo = $evaluatorInfoStmt->fetch(PDO::FETCH_ASSOC);
            $evaluatorRole = $evaluatorInfo['role'] ?? '';
            $evaluatorDept = $evaluatorInfo['department'] ?? null;

            if (in_array($evaluatorRole, ['subject_coordinator', 'chairperson', 'grade_level_coordinator'])) {
                $teacherDeptStmt = $this->db->prepare("SELECT department FROM teachers WHERE id = :id LIMIT 1");
                $teacherDeptStmt->bindValue(':id', $teacherId);
                $teacherDeptStmt->execute();
                $teacherDept = $teacherDeptStmt->fetchColumn();

                if (function_exists('resolveEvaluatorPrograms')) {
                    $allowedPrograms = resolveEvaluatorPrograms($this->db, $evaluatorId, $evaluatorDept);
                    if (!empty($allowedPrograms) && !in_array($teacherDept, $allowedPrograms, true)) {
                        throw new Exception('You are not allowed to evaluate teachers outside your assigned program.');
                    }
                }

                $assignmentCheck = $this->db->prepare(
                    "SELECT id FROM teacher_assignments WHERE evaluator_id = :evaluator_id AND teacher_id = :teacher_id LIMIT 1"
                );
                $assignmentCheck->bindValue(':evaluator_id', $evaluatorId);
                $assignmentCheck->bindValue(':teacher_id', $teacherId);
                $assignmentCheck->execute();
                if ($assignmentCheck->rowCount() === 0) {
                    throw new Exception('You are not assigned to evaluate this teacher.');
                }
            }

            // Resolve schedule gate using evaluator-specific pending schedule slots first.
            // This supports advance scheduling (multiple rows) and avoids blocking on a
            // later "latest teacher snapshot" schedule.
            $effectiveSchedule = $this->resolveEffectiveScheduleForEvaluator(
                (int)$teacherId,
                (int)$evaluatorId,
                (string)$evaluatorRole,
                (string)($evaluatorDept ?? '')
            );
            $scheduleVal = $effectiveSchedule['schedule'] ?? null;
            $scheduleEndVal = $effectiveSchedule['schedule_end'] ?? null;
            $roomVal = $effectiveSchedule['room'] ?? null;
            $effectiveDept = trim((string)($effectiveSchedule['department'] ?? ''));
            if (($scheduleVal || $roomVal) && empty($scheduleEndVal)) {
                try {
                    $endFallbackStmt = $this->db->prepare("SELECT evaluation_schedule_end FROM teachers WHERE id = :id LIMIT 1");
                    $endFallbackStmt->execute([':id' => (int)$teacherId]);
                    $scheduleEndVal = $endFallbackStmt->fetchColumn() ?: null;
                } catch (Exception $e) {}
            }
            if (($scheduleVal || $roomVal) && $effectiveDept === '') {
                try {
                    $deptOwnerStmt = $this->db->prepare("SELECT COALESCE(NULLIF(scheduled_department, ''), department) FROM teachers WHERE id = :id LIMIT 1");
                    $deptOwnerStmt->execute([':id' => (int)$teacherId]);
                    $effectiveDept = trim((string)$deptOwnerStmt->fetchColumn());
                } catch (Exception $e) {}
            }
            if (
                ($scheduleVal || $roomVal)
                && !$this->canUseScheduleDepartment((int)$teacherId, (int)$evaluatorId, (string)$evaluatorRole, (string)($evaluatorDept ?? ''), $effectiveDept)
            ) {
                throw new Exception('This schedule belongs to another department. Only that department or accepted observers can evaluate it.');
            }

            if (empty($scheduleVal) && empty($roomVal)) {
                // Fallback to legacy teacher snapshot when no pending evaluator slot exists.
                $scheduleStmt = $this->db->prepare(
                    "SELECT evaluation_schedule, evaluation_schedule_end, evaluation_room, scheduled_department, department FROM teachers WHERE id = :id LIMIT 1"
                );
                $scheduleStmt->bindValue(':id', $teacherId);
                $scheduleStmt->execute();
                $t = $scheduleStmt->fetch(PDO::FETCH_ASSOC);
                $fallbackDept = trim((string)($t['scheduled_department'] ?? ''));
                if ($fallbackDept === '') {
                    $fallbackDept = trim((string)($t['department'] ?? ''));
                }
                if ($this->canUseScheduleDepartment((int)$teacherId, (int)$evaluatorId, (string)$evaluatorRole, (string)($evaluatorDept ?? ''), $fallbackDept)) {
                    $scheduleVal = $t['evaluation_schedule'] ?? null;
                    $scheduleEndVal = $t['evaluation_schedule_end'] ?? null;
                    $roomVal = $t['evaluation_room'] ?? null;
                    $effectiveDept = $fallbackDept;
                }
            }

            $scheduleGate = $this->buildScheduleGate(
                isset($scheduleVal) ? (string)$scheduleVal : null,
                isset($roomVal) ? (string)$roomVal : null,
                isset($scheduleEndVal) ? (string)$scheduleEndVal : null
            );
            if (!$scheduleGate['allowed'] && !in_array($evaluatorRole, ['president', 'vice_president'])) {
                throw new Exception($scheduleGate['message']);
            }

            // Force-bind submission to the resolved effective schedule slot so
            // saved evaluations always map to the correct row (not a later snapshot).
            if (!empty($effectiveSchedule['schedule'])) {
                try {
                    $tzBind = new DateTimeZone(self::EVALUATION_TIMEZONE);
                    $dtBind = new DateTime((string)$effectiveSchedule['schedule'], $tzBind);
                    $postData['observation_date'] = $dtBind->format('Y-m-d');
                    $postData['observation_time'] = $dtBind->format('H:i:s');
                } catch (Exception $e) {
                    // keep user-provided values if schedule parse unexpectedly fails
                }
            }
            if (empty($postData['observation_room']) && !empty($effectiveSchedule['room'])) {
                $postData['observation_room'] = (string)$effectiveSchedule['room'];
            }
            if (empty($postData['subject_observed']) && !empty($effectiveSchedule['subject_observed'])) {
                $postData['subject_observed'] = (string)$effectiveSchedule['subject_observed'];
            }
            if (empty($postData['subject_area']) && !empty($effectiveSchedule['subject_area'])) {
                $postData['subject_area'] = (string)$effectiveSchedule['subject_area'];
            }
            if (empty($postData['evaluation_focus']) && !empty($effectiveSchedule['evaluation_focus'])) {
                $postData['evaluation_focus'] = (string)$effectiveSchedule['evaluation_focus'];
            }
            if ((empty($postData['semester']) || !in_array((string)$postData['semester'], ['1st', '2nd'], true)) && !empty($effectiveSchedule['semester'])) {
                $postData['semester'] = (string)$effectiveSchedule['semester'];
            }
            if (empty($postData['academic_year']) && !empty($effectiveSchedule['academic_year'])) {
                $postData['academic_year'] = (string)$effectiveSchedule['academic_year'];
            }
            if ($effectiveDept !== '') {
                $postData['department'] = $effectiveDept;
            }

            $balanceDate = trim((string)($postData['observation_date'] ?? ''));
            $balanceTime = trim((string)($postData['observation_time'] ?? ''));
            if ($balanceDate === '' && !empty($scheduleVal)) {
                try {
                    $balanceDt = new DateTime((string)$scheduleVal, new DateTimeZone(self::EVALUATION_TIMEZONE));
                    $balanceDate = $balanceDt->format('Y-m-d');
                    $balanceTime = $balanceDt->format('H:i:s');
                } catch (Exception $e) {}
            }
            $balanceDepartment = trim((string)($effectiveSchedule['department'] ?? ''));
            if ($balanceDepartment === '') {
                try {
                    $deptStmt = $this->db->prepare("SELECT COALESCE(NULLIF(scheduled_department, ''), department) FROM teachers WHERE id = :id LIMIT 1");
                    $deptStmt->execute([':id' => (int)$teacherId]);
                    $balanceDepartment = trim((string)$deptStmt->fetchColumn());
                } catch (Exception $e) {}
            }
            $allowObserverImbalance = !empty($postData['allow_observer_imbalance']);
            $this->assertObserverBalance((int)$teacherId, $balanceDate, $balanceTime, $balanceDepartment, $allowObserverImbalance);

            // Enforce signatures for both ISO and PEAC submissions (server-side).
            $raterSig = trim((string)($postData['rater_signature'] ?? ''));
            $facultySig = trim((string)($postData['faculty_signature'] ?? ''));
            if ($raterSig === '' || !preg_match(self::SIGNATURE_DATAURL_PATTERN, $raterSig)) {
                throw new Exception('Rater/Observer signature is required.');
            }
            if ($facultySig === '' || !preg_match(self::SIGNATURE_DATAURL_PATTERN, $facultySig)) {
                throw new Exception('Faculty signature is required.');
            }

            // Log submission for debugging
            error_log("Submission: evaluatorId=$evaluatorId, teacher_id=" . ($postData['teacher_id'] ?? 'MISSING'));

            // Start transaction
            $this->db->beginTransaction();

            // 1. Create evaluation record
            $evaluationId = $this->createEvaluationRecord($postData, $evaluatorId);

            if ($evaluationId === null) {
                 throw new Exception("You have already completed this evaluation for this teacher.");
            }
            if (!$evaluationId) {
                 throw new Exception("Failed to create evaluation record (lastInsertId=" . var_export($evaluationId, true) . ")");
            }
            error_log("Created evaluation record: $evaluationId for teacher_id=" . ($postData['teacher_id'] ?? 'MISSING'));

            // 2. Save evaluation details (ratings and comments)
            // Ensure details are replaced cleanly when reusing an existing draft slot row.
            $delDetailsStmt = $this->db->prepare("DELETE FROM evaluation_details WHERE evaluation_id = :evaluation_id");
            $delDetailsStmt->execute([':evaluation_id' => $evaluationId]);
            $this->saveEvaluationDetails($evaluationId, $postData);

            // 3. Calculate averages (use model method)
            $this->evaluationModel->calculateAverages($evaluationId);

            // 4. Generate AI recommendations (if service down, don't fail submit)
            try {
                $this->aiController->generateRecommendations($evaluationId);
            } catch (Throwable $aiErr) {
                error_log("AI generateRecommendations failed: " . $aiErr->getMessage());
            }

            // 5. Update evaluation with qualitative data
            $this->updateQualitativeData($evaluationId, $postData);

            // 6. Once a slot is fully completed, return the teacher list to
            // "Schedule required". Observer-imbalanced slots are intentionally
            // left visible until they are resolved.
            try {
                $this->cleanupCompletedScheduleSlot((int)$evaluationId);
            } catch (Throwable $cleanupErr) {
                error_log("Schedule cleanup failed for evaluation {$evaluationId}: " . $cleanupErr->getMessage());
            }

            // Commit transaction
            $this->db->commit();

            // 7. Export completed evaluation to the AI reference corpus (best effort only)
            try {
                $this->syncEvaluationToAIReferences((int)$evaluationId);
            } catch (Throwable $syncErr) {
                error_log("AI reference sync failed for evaluation {$evaluationId}: " . $syncErr->getMessage());
            }

            // 8. Notify teacher via email (best effort)
            try {
                $teacherEmailStmt = $this->db->prepare(
                    "SELECT t.name AS teacher_name, COALESCE(t.email, u.email) AS email, COALESCE(t.email_verified, u.is_email_verified) AS verified
                     FROM teachers t LEFT JOIN users u ON t.user_id = u.id WHERE t.id = :id LIMIT 1"
                );
                $teacherEmailStmt->bindValue(':id', $teacherId);
                $teacherEmailStmt->execute();
                $teacherInfo = $teacherEmailStmt->fetch(PDO::FETCH_ASSOC);

                if ($teacherInfo && !empty($teacherInfo['email']) && filter_var($teacherInfo['email'], FILTER_VALIDATE_EMAIL) && (int)($teacherInfo['verified'] ?? 0) === 1) {
                    $mailerPath = __DIR__ . '/../includes/mailer.php';
                    if (file_exists($mailerPath)) {
                        require_once $mailerPath;
                        if (function_exists('sendEvaluationCompletedEmail')) {
                            sendEvaluationCompletedEmail(
                                $teacherInfo['email'],
                                $teacherInfo['teacher_name'],
                                $evaluatorInfo['name'] ?? ($postData['faculty_name'] ?? 'Evaluator'),
                                $evaluatorRole,
                                $postData['observation_date'] ?? null
                            );
                        }
                    }
                }
            } catch (Throwable $mailErr) {
                error_log("Evaluation notification email failed for evaluation {$evaluationId}: " . $mailErr->getMessage());
            }

            // Mark schedule notifications for this teacher as read for the evaluator
            try {
                $markNotifStmt = $this->db->prepare(
                    "UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND teacher_id = :tid AND type = 'schedule' AND is_read = 0"
                );
                $markNotifStmt->execute([':uid' => $evaluatorId, ':tid' => $teacherId]);
            } catch (Throwable $e) {
                // Non-critical — don't fail the submission
            }

            return [
                'success' => true,
                'evaluation_id' => $evaluationId,
                'message' => 'Evaluation submitted successfully!'
            ];

        } catch (Exception $e) {
            // Rollback transaction on error
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function createEvaluationRecord($data, $evaluatorId) {
        // Ensure optional fields have sensible defaults to avoid SQL errors
        $teacher_id = $data['teacher_id'] ?? null;
        $faculty_name = $data['faculty_name'] ?? null;
        $department = $data['department'] ?? null;
        $academic_year = $data['academic_year'] ?? null;
        $semester = $data['semester'] ?? null;
        $subject_observed = $data['subject_observed'] ?? null;
        $observation_time = $data['observation_time'] ?? null;
        $observation_date = $data['observation_date'] ?? null;
        $observation_type = $data['observation_type'] ?? null;
        $seat_plan = isset($data['seat_plan']) ? $data['seat_plan'] : 0;
        $course_syllabi = isset($data['course_syllabi']) ? $data['course_syllabi'] : 0;
        $others_requirements = isset($data['others_requirements']) ? $data['others_requirements'] : 0;
        $others_specify = $data['others_specify'] ?? '';
        $evaluation_focus = $data['evaluation_focus'] ?? null;
        $evaluation_form_type = $data['evaluation_form_type'] ?? 'iso';
        // Validate form type value
        if (!in_array($evaluation_form_type, ['iso', 'peac'], true)) {
            $evaluation_form_type = 'iso';
        }

        // Idempotency guard:
        // If submit is triggered again for the same schedule slot, reuse
        // the existing completed record instead of inserting a duplicate row.
        if (!empty($teacher_id) && !empty($observation_date)) {
            $dupeStmt = $this->db->prepare(
                "SELECT id
                 FROM evaluations
                 WHERE evaluator_id = :eid
                   AND teacher_id = :tid
                   AND evaluation_form_type = :ft
                    AND academic_year = :ay
                    AND semester = :sem
                    AND observation_date = :obs_date
                    AND COALESCE(observation_time, '') = COALESCE(:obs_time, '')
                    AND COALESCE(department, '') = COALESCE(:dept, '')
                    AND status = 'completed'
                  ORDER BY id DESC
                  LIMIT 1"
            );
            $dupeStmt->bindValue(':eid', $evaluatorId);
            $dupeStmt->bindValue(':tid', $teacher_id);
            $dupeStmt->bindValue(':ft', $evaluation_form_type);
            $dupeStmt->bindValue(':ay', $academic_year);
            $dupeStmt->bindValue(':sem', $semester);
            $dupeStmt->bindValue(':obs_date', $observation_date);
            $dupeStmt->bindValue(':obs_time', $observation_time);
            $dupeStmt->bindValue(':dept', $department);
            $dupeStmt->execute();
            $existingEvalId = (int)($dupeStmt->fetchColumn() ?: 0);
            if ($existingEvalId > 0) {
                return $existingEvalId;
            }
        }

        // Permanent fix:
        // Reuse existing draft/rescheduled row for the same evaluator+teacher+slot
        // instead of inserting a new completed row (prevents duplicate IDs per slot).
        $slotDraftId = 0;
        if (!empty($teacher_id) && !empty($observation_date)) {
            $slotStmt = $this->db->prepare(
                "SELECT id
                 FROM evaluations
                 WHERE evaluator_id = :eid
                   AND teacher_id = :tid
                   AND evaluation_form_type = :ft
                    AND academic_year = :ay
                    AND semester = :sem
                    AND observation_date = :obs_date
                    AND COALESCE(observation_time, '') = COALESCE(:obs_time, '')
                    AND COALESCE(department, '') = COALESCE(:dept, '')
                    AND (status IN ('draft','pending','observer_unbalanced') OR status IS NULL OR status = '')
                  ORDER BY id DESC
                  LIMIT 1"
            );
            $slotStmt->bindValue(':eid', $evaluatorId);
            $slotStmt->bindValue(':tid', $teacher_id);
            $slotStmt->bindValue(':ft', $evaluation_form_type);
            $slotStmt->bindValue(':ay', $academic_year);
            $slotStmt->bindValue(':sem', $semester);
            $slotStmt->bindValue(':obs_date', $observation_date);
            $slotStmt->bindValue(':obs_time', $observation_time);
            $slotStmt->bindValue(':dept', $department);
            $slotStmt->execute();
            $slotDraftId = (int)($slotStmt->fetchColumn() ?: 0);
        }

        // Fetch teacher's schedule details (room, subject_area, focus) before they are cleared
        $observation_room = $data['observation_room'] ?? null;
        $subject_area = $data['subject_area'] ?? null;
        if (!empty($teacher_id)) {
            $schedInfoStmt = $this->db->prepare("SELECT evaluation_room, evaluation_subject_area, evaluation_focus FROM teachers WHERE id = :id LIMIT 1");
            $schedInfoStmt->bindValue(':id', $teacher_id);
            $schedInfoStmt->execute();
            $schedInfo = $schedInfoStmt->fetch(PDO::FETCH_ASSOC);
            if ($schedInfo) {
                if (empty($observation_room)) $observation_room = $schedInfo['evaluation_room'];
                if (empty($subject_area)) $subject_area = $schedInfo['evaluation_subject_area'];
                if (empty($evaluation_focus)) $evaluation_focus = $schedInfo['evaluation_focus'];
            }
        }

    // IMPORTANT: Finalized submissions should be marked completed so they appear in reports
    // and can be imported into the AI reference corpus.

    // Snapshot current form settings so past evaluations are not affected by future changes
    $_fsSnap = [];
    try {
        $fsStmt = $this->db->query("SELECT setting_key, setting_value FROM form_settings");
        while ($r = $fsStmt->fetch(PDO::FETCH_ASSOC)) { $_fsSnap[$r['setting_key']] = $r['setting_value']; }
    } catch (PDOException $e) {}

    if ($slotDraftId > 0) {
        $updateQuery = "UPDATE evaluations
                        SET faculty_name = :faculty_name,
                            department = :department,
                            academic_year = :academic_year,
                            semester = :semester,
                            subject_observed = :subject_observed,
                            observation_time = :observation_time,
                            observation_date = :observation_date,
                            observation_type = :observation_type,
                            observation_room = :observation_room,
                            subject_area = :subject_area,
                            evaluation_focus = :evaluation_focus,
                            evaluation_form_type = :evaluation_form_type,
                            seat_plan = :seat_plan,
                            course_syllabi = :course_syllabi,
                            others_requirements = :others_requirements,
                            others_specify = :others_specify,
                            status = 'completed',
                            fs_form_code_no = :fs_form_code_no,
                            fs_issue_status = :fs_issue_status,
                            fs_revision_no = :fs_revision_no,
                            fs_date_effective = :fs_date_effective,
                            fs_approved_by = :fs_approved_by,
                            updated_at = NOW()
                        WHERE id = :id";
        $u = $this->db->prepare($updateQuery);
        $u->bindValue(':faculty_name', $faculty_name);
        $u->bindValue(':department', $department);
        $u->bindValue(':academic_year', $academic_year);
        $u->bindValue(':semester', $semester);
        $u->bindValue(':subject_observed', $subject_observed);
        $u->bindValue(':observation_time', $observation_time);
        $u->bindValue(':observation_date', $observation_date);
        $u->bindValue(':observation_type', $observation_type);
        $u->bindValue(':observation_room', $observation_room);
        $u->bindValue(':subject_area', $subject_area);
        $u->bindValue(':evaluation_focus', $evaluation_focus);
        $u->bindValue(':evaluation_form_type', $evaluation_form_type);
        $u->bindValue(':seat_plan', $seat_plan);
        $u->bindValue(':course_syllabi', $course_syllabi);
        $u->bindValue(':others_requirements', $others_requirements);
        $u->bindValue(':others_specify', $others_specify);
        $u->bindValue(':fs_form_code_no', $_fsSnap['form_code_no'] ?? 'FM-DPM-SMCC-CMI-02');
        $u->bindValue(':fs_issue_status', $_fsSnap['issue_status'] ?? '03');
        $u->bindValue(':fs_revision_no', $_fsSnap['revision_no'] ?? '00');
        $u->bindValue(':fs_date_effective', $_fsSnap['date_effective'] ?? '5 June 2026');
        $u->bindValue(':fs_approved_by', $_fsSnap['approved_by'] ?? 'President');
        $u->bindValue(':id', $slotDraftId, PDO::PARAM_INT);
        if ($u->execute()) {
            $this->reusedEvaluationId = $slotDraftId;
            return $slotDraftId;
        }
        $err = $u->errorInfo();
        error_log('DB Error updating draft evaluation record: ' . ($err[2] ?? json_encode($err)));
        throw new Exception('Failed to finalize existing draft evaluation. Please try again.');
    }

    $query = "INSERT INTO evaluations 
          (teacher_id, faculty_name, department, evaluator_id, academic_year, semester, 
                   subject_observed, observation_time, observation_date, 
                   observation_type, observation_room, subject_area, evaluation_focus, evaluation_form_type, seat_plan, course_syllabi, 
                   others_requirements, others_specify, status,
                   fs_form_code_no, fs_issue_status, fs_revision_no, fs_date_effective, fs_approved_by) 
          VALUES (:teacher_id, :faculty_name, :department, :evaluator_id, :academic_year, :semester, 
                          :subject_observed, :observation_time, :observation_date, 
                          :observation_type, :observation_room, :subject_area, :evaluation_focus, :evaluation_form_type, :seat_plan, :course_syllabi, 
              :others_requirements, :others_specify, 'completed',
              :fs_form_code_no, :fs_issue_status, :fs_revision_no, :fs_date_effective, :fs_approved_by)";

        $stmt = $this->db->prepare($query);

        $stmt->bindValue(':teacher_id', $teacher_id);
    $stmt->bindValue(':faculty_name', $faculty_name);
    $stmt->bindValue(':department', $department);
        $stmt->bindValue(':evaluator_id', $evaluatorId);
        $stmt->bindValue(':academic_year', $academic_year);
        $stmt->bindValue(':semester', $semester);
        $stmt->bindValue(':subject_observed', $subject_observed);
        $stmt->bindValue(':observation_time', $observation_time);
        $stmt->bindValue(':observation_date', $observation_date);
        $stmt->bindValue(':observation_type', $observation_type);
        $stmt->bindValue(':observation_room', $observation_room);
        $stmt->bindValue(':subject_area', $subject_area);
        $stmt->bindValue(':evaluation_focus', $evaluation_focus);
        $stmt->bindValue(':evaluation_form_type', $evaluation_form_type);
        $stmt->bindValue(':seat_plan', $seat_plan);
        $stmt->bindValue(':course_syllabi', $course_syllabi);
        $stmt->bindValue(':others_requirements', $others_requirements);
        $stmt->bindValue(':others_specify', $others_specify);
        $stmt->bindValue(':fs_form_code_no', $_fsSnap['form_code_no'] ?? 'FM-DPM-SMCC-CMI-02');
        $stmt->bindValue(':fs_issue_status', $_fsSnap['issue_status'] ?? '03');
        $stmt->bindValue(':fs_revision_no', $_fsSnap['revision_no'] ?? '00');
        $stmt->bindValue(':fs_date_effective', $_fsSnap['date_effective'] ?? '5 June 2026');
        $stmt->bindValue(':fs_approved_by', $_fsSnap['approved_by'] ?? 'President');

        if ($stmt->execute()) {
            // Do NOT clear the teacher's schedule here. Multiple evaluators (chairperson, dean)
            // may need to evaluate the same teacher using the same schedule. The schedule will be
            // cleared automatically once ALL assigned evaluators have completed, or after the
            // 24-hour expiration window.
            $lastId = $this->resolveInsertedEvaluationId($teacher_id, $evaluatorId);
            error_log("Evaluation create stmt executed. lastInsertId=" . var_export($lastId, true));
            return $lastId;
        }
        $err = $stmt->errorInfo();
        error_log('DB Error creating evaluation record: ' . ($err[2] ?? json_encode($err)));
        throw new Exception('Failed to create evaluation record. Please try again.');
    }

    private function resolveInsertedEvaluationId($teacherId, $evaluatorId) {
        $lastId = (int)$this->db->lastInsertId();
        if ($lastId > 0) {
            return $lastId;
        }

        if (empty($teacherId) || empty($evaluatorId)) {
            return 0;
        }

        $fallbackStmt = $this->db->prepare(
            "SELECT id
             FROM evaluations
             WHERE teacher_id = :teacher_id
               AND evaluator_id = :evaluator_id
             ORDER BY id DESC
             LIMIT 1"
        );
        $fallbackStmt->bindValue(':teacher_id', $teacherId, PDO::PARAM_INT);
        $fallbackStmt->bindValue(':evaluator_id', $evaluatorId, PDO::PARAM_INT);
        $fallbackStmt->execute();

        return (int)($fallbackStmt->fetchColumn() ?: 0);
    }

    private function cleanupCompletedScheduleSlot(int $evaluationId): void {
        if ($evaluationId <= 0) {
            return;
        }

        $slotStmt = $this->db->prepare(
            "SELECT teacher_id, academic_year, semester, observation_date, observation_time, department
             FROM evaluations
             WHERE id = :id
               AND status = 'completed'
             LIMIT 1"
        );
        $slotStmt->execute([':id' => $evaluationId]);
        $slot = $slotStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$slot) {
            return;
        }

        $teacherId = (int)($slot['teacher_id'] ?? 0);
        $academicYear = trim((string)($slot['academic_year'] ?? ''));
        $semester = trim((string)($slot['semester'] ?? ''));
        $observationDate = trim((string)($slot['observation_date'] ?? ''));
        $observationTime = trim((string)($slot['observation_time'] ?? ''));
        $department = trim((string)($slot['department'] ?? ''));
        if ($teacherId <= 0 || $observationDate === '') {
            return;
        }
        $observationTimeMin = $observationTime !== '' ? substr($observationTime, 0, 5) : '00:00';
        if ($observationTimeMin === '') {
            $observationTimeMin = '00:00';
        }

        $common = [
            ':teacher_id' => $teacherId,
            ':academic_year' => $academicYear,
            ':semester' => $semester,
            ':observation_date' => $observationDate,
            ':observation_time' => $observationTimeMin,
            ':department' => $department,
            ':department_match' => $department
        ];

        $remainingStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM evaluations
             WHERE teacher_id = :teacher_id
               AND COALESCE(academic_year, '') = :academic_year
               AND COALESCE(semester, '') = :semester
               AND observation_date = :observation_date
               AND (
                    CASE
                        WHEN observation_time IS NULL OR TRIM(observation_time) = '' THEN '00:00'
                        ELSE LEFT(TRIM(observation_time), 5)
                    END
               ) = :observation_time
               AND (:department = '' OR department = :department_match)
               AND (
                    status IN ('draft','pending')
                    OR status IS NULL
                    OR status = ''
               )"
        );
        $remainingStmt->execute($common);
        if ((int)$remainingStmt->fetchColumn() > 0) {
            return;
        }

        $imbalanceStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM evaluations
             WHERE teacher_id = :teacher_id
               AND COALESCE(academic_year, '') = :academic_year
               AND COALESCE(semester, '') = :semester
               AND observation_date = :observation_date
               AND (
                    CASE
                        WHEN observation_time IS NULL OR TRIM(observation_time) = '' THEN '00:00'
                        ELSE LEFT(TRIM(observation_time), 5)
                    END
               ) = :observation_time
               AND (:department = '' OR department = :department_match)
               AND status = 'observer_unbalanced'"
        );
        $imbalanceStmt->execute($common);
        if ((int)$imbalanceStmt->fetchColumn() > 0) {
            return;
        }

        $scheduleImbalanceStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM teacher_schedules
             WHERE teacher_id = :teacher_id
               AND COALESCE(academic_year, '') = :academic_year
               AND COALESCE(semester, '') = :semester
               AND DATE(schedule_start) = :observation_date
               AND COALESCE(DATE_FORMAT(schedule_start, '%H:%i'), '00:00') = :observation_time
               AND (:department = '' OR scheduled_department = :department_match)
               AND status = 'observer_unbalanced'"
        );
        $scheduleImbalanceStmt->execute($common);
        if ((int)$scheduleImbalanceStmt->fetchColumn() > 0) {
            return;
        }

        $scheduleStmt = $this->db->prepare(
            "UPDATE teacher_schedules
             SET status = 'completed', updated_at = NOW()
             WHERE teacher_id = :teacher_id
               AND COALESCE(academic_year, '') = :academic_year
               AND COALESCE(semester, '') = :semester
               AND DATE(schedule_start) = :observation_date
               AND COALESCE(DATE_FORMAT(schedule_start, '%H:%i'), '00:00') = :observation_time
               AND (:department = '' OR scheduled_department = :department_match)
               AND status = 'scheduled'"
        );
        $scheduleStmt->execute($common);

        $teacherStmt = $this->db->prepare(
            "UPDATE teachers
             SET evaluation_schedule = NULL,
                 evaluation_schedule_end = NULL,
                 evaluation_room = NULL,
                 evaluation_focus = NULL,
                 evaluation_subject_area = NULL,
                 evaluation_subject = NULL,
                 evaluation_semester = NULL,
                 evaluation_form_type = 'iso',
                 scheduled_by = NULL,
                 scheduled_department = NULL,
                 updated_at = NOW()
             WHERE id = :teacher_id
               AND evaluation_schedule IS NOT NULL
               AND DATE(evaluation_schedule) = :observation_date
               AND COALESCE(DATE_FORMAT(evaluation_schedule, '%H:%i'), '00:00') = :observation_time
               AND (:department = '' OR scheduled_department = :department_match)"
        );
        $teacherStmt->execute([
            ':teacher_id' => $teacherId,
            ':observation_date' => $observationDate,
            ':observation_time' => $observationTimeMin,
            ':department' => $department,
            ':department_match' => $department
        ]);
    }

    private function saveEvaluationDetails($evaluationId, $data) {
        // Support BOTH payload styles:
        // 1) flat fields: communications0=5, communications_comment0=...
        // 2) nested fields: ratings[communications][0][rating]=5, ratings[communications][0][comment]=...
        // Normalize nested => flat so the loops below always work.
        // Dynamic category sizes from evaluation_criteria table (with safe defaults).
        $allCategories = $this->getCriterionCountsByCategory();
        if (isset($data['ratings']) && is_array($data['ratings'])) {
            foreach ($allCategories as $cat => $count) {
                if (!isset($data['ratings'][$cat]) || !is_array($data['ratings'][$cat])) continue;
                for ($i = 0; $i < $count; $i++) {
                    if (!isset($data["{$cat}{$i}"]) && isset($data['ratings'][$cat][$i]['rating'])) {
                        $data["{$cat}{$i}"] = $data['ratings'][$cat][$i]['rating'];
                    }
                    if (!isset($data["{$cat}_comment{$i}"]) && isset($data['ratings'][$cat][$i]['comment'])) {
                        $data["{$cat}_comment{$i}"] = $data['ratings'][$cat][$i]['comment'];
                    }
                }
            }
        }

        $savedCount = 0;

        // Persist every category dynamically.
        foreach ($allCategories as $category => $count) {
            for ($i = 0; $i < $count; $i++) {
                if (isset($data["{$category}{$i}"])) {
                    $this->saveCriterion($evaluationId, $category, $i, $data["{$category}{$i}"], $data["{$category}_comment{$i}"] ?? '');
                    $savedCount++;
                }
            }
        }

        error_log("Saved evaluation_details rows={$savedCount} for evaluation_id={$evaluationId}");
    }

    private function getCriterionCountsByCategory(): array {
        if (is_array($this->criterionCountCache)) {
            return $this->criterionCountCache;
        }

        $defaults = [
            'communications' => 5,
            'management' => 12,
            'assessment' => 6,
            'teacher_actions' => 6,
            'student_learning_actions' => 9
        ];

        $counts = $defaults;
        try {
            $stmt = $this->db->query("SELECT category, COUNT(*) AS cnt FROM evaluation_criteria GROUP BY category");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cat = trim((string)($row['category'] ?? ''));
                $cnt = (int)($row['cnt'] ?? 0);
                if ($cat !== '' && $cnt > 0) {
                    $counts[$cat] = $cnt;
                }
            }
        } catch (Exception $e) {
            // Keep defaults if table/query is unavailable.
        }

        $this->criterionCountCache = $counts;
        return $counts;
    }

    private function saveCriterion($evaluationId, $category, $index, $rating, $comment) {
        // Get criterion text from evaluation_criteria table
        $criterionQuery = "SELECT criterion_text FROM evaluation_criteria 
                          WHERE category = :category AND criterion_index = :index";
        $criterionStmt = $this->db->prepare($criterionQuery);
        $criterionStmt->bindParam(':category', $category);
        $criterionStmt->bindParam(':index', $index);
        $criterionStmt->execute();
        $criterion = $criterionStmt->fetch(PDO::FETCH_ASSOC);
        $criterion_text = $criterion['criterion_text'] ?? '';

        $query = "INSERT INTO evaluation_details 
                  (evaluation_id, category, criterion_index, criterion_text, rating, comments) 
                  VALUES (:evaluation_id, :category, :criterion_index, :criterion_text, :rating, :comments)";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':evaluation_id', $evaluationId);
        $stmt->bindParam(':category', $category);
        $stmt->bindParam(':criterion_index', $index);
        $stmt->bindParam(':criterion_text', $criterion_text);
        $stmt->bindParam(':rating', $rating);
        $stmt->bindParam(':comments', $comment);

        if ($stmt->execute()) {
            return true;
        }
        $err = $stmt->errorInfo();
        error_log('DB Error saving criterion: ' . ($err[2] ?? json_encode($err)));
        throw new Exception('Failed to save evaluation criterion. Please try again.');
    }

    private function calculateAndUpdateAverages($evaluationId) {
        // Legacy: kept for backward compatibility. Prefer model->calculateAverages()
        $stmt = $this->db->prepare("CALL CalculateAverages(?)");
        try {
            $stmt->execute([$evaluationId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // If stored procedure doesn't exist, fallback to model method
            return $this->evaluationModel->calculateAverages($evaluationId);
        }
    }

    // Save draft submission (used via AJAX)
    public function saveDraft($postData, $evaluatorId) {
        try {
            $this->db->beginTransaction();

            // Create evaluation with status 'draft'
                // Prepare default values for optional draft fields
                $teacher_id = $postData['teacher_id'] ?? null;
                $faculty_name = $postData['faculty_name'] ?? null;
                $department = $postData['department'] ?? null;
                $academic_year = $postData['academic_year'] ?? null;
                $semester = $postData['semester'] ?? null;
                $subject_observed = $postData['subject_observed'] ?? null;
                $observation_time = $postData['observation_time'] ?? null;
                $observation_date = $postData['observation_date'] ?? null;
                $observation_type = $postData['observation_type'] ?? null;
                $seat_plan = isset($postData['seat_plan']) ? $postData['seat_plan'] : 0;
                $course_syllabi = isset($postData['course_syllabi']) ? $postData['course_syllabi'] : 0;
                $others_requirements = isset($postData['others_requirements']) ? $postData['others_requirements'] : 0;
                $others_specify = $postData['others_specify'] ?? '';

                $query = "INSERT INTO evaluations 
                      (teacher_id, faculty_name, department, evaluator_id, academic_year, semester, 
                       subject_observed, observation_time, observation_date, 
                       observation_type, seat_plan, course_syllabi, 
                       others_requirements, others_specify, status, created_at) 
                      VALUES (:teacher_id, :faculty_name, :department, :evaluator_id, :academic_year, :semester, 
                              :subject_observed, :observation_time, :observation_date, 
                              :observation_type, :seat_plan, :course_syllabi, 
                              :others_requirements, :others_specify, 'draft', NOW())";

            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':teacher_id', $teacher_id);
            $stmt->bindValue(':faculty_name', $faculty_name);
            $stmt->bindValue(':department', $department);
            $stmt->bindValue(':evaluator_id', $evaluatorId);
            $stmt->bindValue(':academic_year', $academic_year);
            $stmt->bindValue(':semester', $semester);
            $stmt->bindValue(':subject_observed', $subject_observed);
            $stmt->bindValue(':observation_time', $observation_time);
            $stmt->bindValue(':observation_date', $observation_date);
            $stmt->bindValue(':observation_type', $observation_type);
            $stmt->bindValue(':seat_plan', $seat_plan);
            $stmt->bindValue(':course_syllabi', $course_syllabi);
            $stmt->bindValue(':others_requirements', $others_requirements);
            $stmt->bindValue(':others_specify', $others_specify);

            if (!$stmt->execute()) {
                throw new Exception('Failed to create draft evaluation');
            }

            $evaluationId = $this->db->lastInsertId();

            // Save details (ratings/comments) if provided
            $this->saveEvaluationDetails($evaluationId, $postData);

            // Update qualitative fields if present
            $this->updateQualitativeData($evaluationId, $postData);

            $this->db->commit();

            return ['success' => true, 'evaluation_id' => $evaluationId];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function updateQualitativeData($evaluationId, $data) {
        $query = "UPDATE evaluations 
                  SET strengths = :strengths, 
                      improvement_areas = :improvement_areas,
                      recommendations = :recommendations,
                      agreement = :agreement,
                      rater_printed_name = :rater_printed_name,
                      rater_signature = :rater_signature,
                      rater_date = :rater_date,
                      faculty_printed_name = :faculty_printed_name,
                      faculty_signature = :faculty_signature,
                      faculty_date = :faculty_date
                  WHERE id = :evaluation_id";

        $stmt = $this->db->prepare($query);

        // ✅ prevent "Undefined index" notices
        $strengths = $data['strengths'] ?? '';
        $improvement = $data['improvement_areas'] ?? '';
        $recommendations = $data['recommendations'] ?? '';
        $agreement = $data['agreement'] ?? '';
    $raterPrintedName = $data['rater_printed_name'] ?? '';
        $raterSig = $data['rater_signature'] ?? '';
        $raterDate = $data['rater_date'] ?? null;
    $facultyPrintedName = $data['faculty_printed_name'] ?? '';
        $facultySig = $data['faculty_signature'] ?? '';
        $facultyDate = $data['faculty_date'] ?? null;

        $stmt->bindParam(':strengths', $strengths);
        $stmt->bindParam(':improvement_areas', $improvement);
        $stmt->bindParam(':recommendations', $recommendations);
        $stmt->bindParam(':agreement', $agreement);
    $stmt->bindParam(':rater_printed_name', $raterPrintedName);
        $stmt->bindParam(':rater_signature', $raterSig);
        $stmt->bindParam(':rater_date', $raterDate);
    $stmt->bindParam(':faculty_printed_name', $facultyPrintedName);
        $stmt->bindParam(':faculty_signature', $facultySig);
        $stmt->bindParam(':faculty_date', $facultyDate);
        $stmt->bindParam(':evaluation_id', $evaluationId);

        return $stmt->execute();
    }

    private function syncEvaluationToAIReferences(int $evaluationId): void {
        $query = "
            SELECT
                e.id,
                e.subject_observed,
                e.observation_type,
                e.communications_avg,
                e.management_avg,
                e.assessment_avg,
                e.overall_avg,
                e.strengths,
                e.improvement_areas,
                e.recommendations,
                e.created_at,
                COALESCE(t.name, e.faculty_printed_name, '') AS faculty_name,
                COALESCE(t.department, u.department, '') AS department
            FROM evaluations e
            LEFT JOIN teachers t ON e.teacher_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE e.id = :evaluation_id
              AND e.status = 'completed'
            LIMIT 1
        ";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':evaluation_id', $evaluationId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return;
        }

        $detailStmt = $this->db->prepare(
            "SELECT category, criterion_index, rating, comments
             FROM evaluation_details
             WHERE evaluation_id = :evaluation_id
             ORDER BY category, criterion_index"
        );
        $detailStmt->bindValue(':evaluation_id', $evaluationId, PDO::PARAM_INT);
        $detailStmt->execute();
        $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ratings = [];
        foreach ($details as $detail) {
            $category = $detail['category'] ?? 'other';
            if (!isset($ratings[$category])) {
                $ratings[$category] = [];
            }
            $ratings[$category][] = [
                'rating' => (float)($detail['rating'] ?? 0),
                'comment' => trim((string)($detail['comments'] ?? '')),
            ];
        }

        $entry = [
            'faculty_name' => trim((string)($row['faculty_name'] ?? '')),
            'department' => trim((string)($row['department'] ?? '')),
            'subject_observed' => trim((string)($row['subject_observed'] ?? '')),
            'observation_type' => trim((string)($row['observation_type'] ?? '')),
            'averages' => [
                'communications' => (float)($row['communications_avg'] ?? 0),
                'management' => (float)($row['management_avg'] ?? 0),
                'assessment' => (float)($row['assessment_avg'] ?? 0),
                'overall' => (float)($row['overall_avg'] ?? 0),
            ],
            'ratings' => $ratings,
            'strengths' => trim((string)($row['strengths'] ?? '')),
            'improvement_areas' => trim((string)($row['improvement_areas'] ?? '')),
            'recommendations' => trim((string)($row['recommendations'] ?? '')),
            'source' => 'live-submit',
            'source_evaluation_id' => $evaluationId,
            'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : date('c'),
        ];

        if ($entry['strengths'] === '' || $entry['improvement_areas'] === '' || $entry['recommendations'] === '') {
            return;
        }

        $ratingsJson = json_encode($entry['ratings'], JSON_UNESCAPED_UNICODE);
        if ($ratingsJson === false) {
            $ratingsJson = '{}';
        }

        $dbSync = $this->db->prepare(
            "INSERT INTO ai_reference_evaluations (
                evaluation_id,
                faculty_name,
                department,
                subject_observed,
                observation_type,
                communications_avg,
                management_avg,
                assessment_avg,
                overall_avg,
                ratings_json,
                strengths,
                improvement_areas,
                recommendations,
                source,
                source_evaluation_id,
                reference_created_at
            ) VALUES (
                :evaluation_id,
                :faculty_name,
                :department,
                :subject_observed,
                :observation_type,
                :communications_avg,
                :management_avg,
                :assessment_avg,
                :overall_avg,
                :ratings_json,
                :strengths,
                :improvement_areas,
                :recommendations,
                :source,
                :source_evaluation_id,
                :reference_created_at
            )
            ON DUPLICATE KEY UPDATE
                faculty_name = VALUES(faculty_name),
                department = VALUES(department),
                subject_observed = VALUES(subject_observed),
                observation_type = VALUES(observation_type),
                communications_avg = VALUES(communications_avg),
                management_avg = VALUES(management_avg),
                assessment_avg = VALUES(assessment_avg),
                overall_avg = VALUES(overall_avg),
                ratings_json = VALUES(ratings_json),
                strengths = VALUES(strengths),
                improvement_areas = VALUES(improvement_areas),
                recommendations = VALUES(recommendations),
                source = VALUES(source),
                source_evaluation_id = VALUES(source_evaluation_id),
                reference_created_at = VALUES(reference_created_at)"
        );

        $dbSync->execute([
            ':evaluation_id' => (int)$evaluationId,
            ':faculty_name' => $entry['faculty_name'],
            ':department' => $entry['department'],
            ':subject_observed' => $entry['subject_observed'],
            ':observation_type' => $entry['observation_type'],
            ':communications_avg' => (float)$entry['averages']['communications'],
            ':management_avg' => (float)$entry['averages']['management'],
            ':assessment_avg' => (float)$entry['averages']['assessment'],
            ':overall_avg' => (float)$entry['averages']['overall'],
            ':ratings_json' => $ratingsJson,
            ':strengths' => $entry['strengths'],
            ':improvement_areas' => $entry['improvement_areas'],
            ':recommendations' => $entry['recommendations'],
            ':source' => $entry['source'],
            ':source_evaluation_id' => (int)$entry['source_evaluation_id'],
            ':reference_created_at' => $entry['created_at'],
        ]);

        $outPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ai_service' . DIRECTORY_SEPARATOR . 'reference_evaluations.imported.jsonl';
        file_put_contents($outPath, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
?>

