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

    public function __construct($database) {
        $this->db = $database;
        $this->evaluationModel = new Evaluation($database);
        $this->aiController = new AIController($database);
    }

    private function buildScheduleGate(?string $scheduleVal, ?string $roomVal): array {
        $scheduleVal = is_string($scheduleVal) ? trim($scheduleVal) : '';
        $roomVal = is_string($roomVal) ? trim($roomVal) : '';

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

        try {
            $timezone = new DateTimeZone(self::EVALUATION_TIMEZONE);
            $scheduledAt = new DateTime($scheduleVal, $timezone);
            $scheduledAt->setTimezone($timezone);
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

        if ($now < $scheduledAt) {
            return [
                'scheduled' => true,
                'allowed' => false,
                'reason' => 'too_early',
                'message' => 'Cannot submit evaluation yet. Evaluation opens on ' . $scheduledAt->format('F d, Y \a\t h:i A') . '.',
            ];
        }

        return [
            'scheduled' => true,
            'allowed' => true,
            'reason' => 'ready',
            'message' => '',
        ];
    }

    public function submitEvaluation($postData, $evaluatorId) {
        try {
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

            // Accept schedule from either: evaluation_schedule (legacy DATETIME column)
            // or evaluation_room/evaluation_schedule text fields (newer UI patterns may store schedule info differently).
            // If your DB only has evaluation_schedule, the COALESCE simply returns that.
            $scheduleStmt = $this->db->prepare(
                "SELECT evaluation_schedule, evaluation_room FROM teachers WHERE id = :id LIMIT 1"
            );
            $scheduleStmt->bindValue(':id', $teacherId);
            $scheduleStmt->execute();
            $t = $scheduleStmt->fetch(PDO::FETCH_ASSOC);
            $scheduleVal = $t['evaluation_schedule'] ?? null;
            $roomVal = $t['evaluation_room'] ?? null;

            $scheduleGate = $this->buildScheduleGate(
                isset($scheduleVal) ? (string)$scheduleVal : null,
                isset($roomVal) ? (string)$roomVal : null
            );
            if (!$scheduleGate['allowed'] && !in_array($evaluatorRole, ['president', 'vice_president'])) {
                throw new Exception($scheduleGate['message']);
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

            // Commit transaction
            $this->db->commit();

            // 6. Export completed evaluation to the AI reference corpus (best effort only)
            try {
                $this->syncEvaluationToAIReferences((int)$evaluationId);
            } catch (Throwable $syncErr) {
                error_log("AI reference sync failed for evaluation {$evaluationId}: " . $syncErr->getMessage());
            }

            // 7. Notify teacher via email (best effort)
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

        // Prevent duplicate: check if this evaluator already completed this form type for this teacher
        if (!empty($teacher_id)) {
            $dupeStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM evaluations WHERE evaluator_id = :eid AND teacher_id = :tid AND evaluation_form_type = :ft AND status = 'completed'"
            );
            $dupeStmt->bindValue(':eid', $evaluatorId);
            $dupeStmt->bindValue(':tid', $teacher_id);
            $dupeStmt->bindValue(':ft', $evaluation_form_type);
            $dupeStmt->execute();
            if ((int)$dupeStmt->fetchColumn() > 0) {
                return null; // Already evaluated — block duplicate
            }
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
        $stmt->bindValue(':fs_form_code_no', $_fsSnap['form_code_no'] ?? 'FM-DPM-SMCC-RTH-04');
        $stmt->bindValue(':fs_issue_status', $_fsSnap['issue_status'] ?? '02');
        $stmt->bindValue(':fs_revision_no', $_fsSnap['revision_no'] ?? '02');
        $stmt->bindValue(':fs_date_effective', $_fsSnap['date_effective'] ?? '13 September 2023');
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

    private function saveEvaluationDetails($evaluationId, $data) {
        // Support BOTH payload styles:
        // 1) flat fields: communications0=5, communications_comment0=...
        // 2) nested fields: ratings[communications][0][rating]=5, ratings[communications][0][comment]=...
        // Normalize nested => flat so the loops below always work.
        // ISO categories + PEAC categories
        $allCategories = [
            'communications' => 5, 'management' => 12, 'assessment' => 6,
            'teacher_actions' => 6, 'student_learning_actions' => 9
        ];
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

        // Save ISO criteria
        // Save communications criteria
        for ($i = 0; $i < 5; $i++) {
            if (isset($data["communications{$i}"])) {
                $this->saveCriterion($evaluationId, 'communications', $i, $data["communications{$i}"], $data["communications_comment{$i}"] ?? '');
                $savedCount++;
            }
        }

        // Save management criteria
        for ($i = 0; $i < 12; $i++) {
            if (isset($data["management{$i}"])) {
                $this->saveCriterion($evaluationId, 'management', $i, $data["management{$i}"], $data["management_comment{$i}"] ?? '');
                $savedCount++;
            }
        }

        // Save assessment criteria
        for ($i = 0; $i < 6; $i++) {
            if (isset($data["assessment{$i}"])) {
                $this->saveCriterion($evaluationId, 'assessment', $i, $data["assessment{$i}"], $data["assessment_comment{$i}"] ?? '');
                $savedCount++;
            }
        }

        // Save PEAC criteria
        // Save teacher_actions criteria (6 items)
        for ($i = 0; $i < 6; $i++) {
            if (isset($data["teacher_actions{$i}"])) {
                $this->saveCriterion($evaluationId, 'teacher_actions', $i, $data["teacher_actions{$i}"], $data["teacher_actions_comment{$i}"] ?? '');
                $savedCount++;
            }
        }

        // Save student_learning_actions criteria (9 items)
        for ($i = 0; $i < 9; $i++) {
            if (isset($data["student_learning_actions{$i}"])) {
                $this->saveCriterion($evaluationId, 'student_learning_actions', $i, $data["student_learning_actions{$i}"], $data["student_learning_actions_comment{$i}"] ?? '');
                $savedCount++;
            }
        }

        error_log("Saved evaluation_details rows={$savedCount} for evaluation_id={$evaluationId}");
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