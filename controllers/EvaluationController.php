<?php
// Handle AJAX save draft requests (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_draft') {
    require_once '../config/database.php';
    require_once '../models/Evaluation.php';
    require_once '../models/Teacher.php';
    require_once '../controllers/AIController.php';

    $db = (new Database())->getConnection();
    $evalController = new EvaluationController($db);

    session_start();
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

    session_start();
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

    public function __construct($database) {
        $this->db = $database;
        $this->evaluationModel = new Evaluation($database);
        $this->aiController = new AIController($database);
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
            $evaluatorInfoStmt = $this->db->prepare("SELECT role, department FROM users WHERE id = :id LIMIT 1");
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

            // Consider it "scheduled" if either schedule datetime is set OR room is set.
            // (Room is usually set together with schedule from the UI; this avoids false negatives in prod data.)
            if (empty($scheduleVal) && empty($roomVal)) {
                throw new Exception('Cannot submit evaluation: no evaluation schedule is set for this teacher.');
            }

            // Log submission for debugging
            error_log("Submission: evaluatorId=$evaluatorId, teacher_id=" . ($postData['teacher_id'] ?? 'MISSING'));

            // Start transaction
            $this->db->beginTransaction();

            // 1. Create evaluation record
            $evaluationId = $this->createEvaluationRecord($postData, $evaluatorId);

            if (!$evaluationId) {
                throw new Exception("Failed to create evaluation record");
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

    // IMPORTANT: Use a consistent status that dashboards can filter on.
    // We'll use 'submitted' for final submissions.
    $query = "INSERT INTO evaluations 
                  (teacher_id, evaluator_id, academic_year, semester, 
                   subject_observed, observation_time, observation_date, 
                   observation_type, seat_plan, course_syllabi, 
                   others_requirements, others_specify, status) 
                  VALUES (:teacher_id, :evaluator_id, :academic_year, :semester, 
                          :subject_observed, :observation_time, :observation_date, 
                          :observation_type, :seat_plan, :course_syllabi, 
              :others_requirements, :others_specify, 'submitted')";

        $stmt = $this->db->prepare($query);

        $stmt->bindValue(':teacher_id', $teacher_id);
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

        if ($stmt->execute()) {
            return $this->db->lastInsertId();
        }
        $err = $stmt->errorInfo();
        throw new Exception('DB Error creating evaluation record: ' . ($err[2] ?? json_encode($err)));
    }

    private function saveEvaluationDetails($evaluationId, $data) {
        // Support BOTH payload styles:
        // 1) flat fields: communications0=5, communications_comment0=...
        // 2) nested fields: ratings[communications][0][rating]=5, ratings[communications][0][comment]=...
        // Normalize nested => flat so the loops below always work.
        if (isset($data['ratings']) && is_array($data['ratings'])) {
            foreach (['communications' => 5, 'management' => 12, 'assessment' => 6] as $cat => $count) {
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
        throw new Exception('DB Error saving criterion: ' . ($err[2] ?? json_encode($err)));
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
                      (teacher_id, evaluator_id, academic_year, semester, 
                       subject_observed, observation_time, observation_date, 
                       observation_type, seat_plan, course_syllabi, 
                       others_requirements, others_specify, status, created_at) 
                      VALUES (:teacher_id, :evaluator_id, :academic_year, :semester, 
                              :subject_observed, :observation_time, :observation_date, 
                              :observation_type, :seat_plan, :course_syllabi, 
                              :others_requirements, :others_specify, 'draft', NOW())";

            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':teacher_id', $teacher_id);
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
}
?>